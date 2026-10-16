<?php
/**
 * Administrator Operations & Governance Manager
 * Provides dispute arbitration, manual ledger adjustments, global configuration controls,
 * read-only support impersonation, and immutable audit logging.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/ReferralEngine.php';

class AdminManager {
    /**
     * Record immutable administrator audit trail (PRD Section 14)
     */
    public static function logAudit(
        int $adminId,
        string $actionType,
        string $targetEntity,
        string $targetId,
        ?array $beforeState,
        ?array $afterState,
        string $reason
    ): int {
        $pdo = Database::getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO admin_audit_logs (
                admin_id, action_type, target_entity, target_id, before_state, after_state, reason, ip_address, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $adminId,
            $actionType,
            $targetEntity,
            $targetId,
            $beforeState ? json_encode($beforeState) : null,
            $afterState ? json_encode($afterState) : null,
            $reason,
            $ip,
            $nowUtc
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Platform macro health statistics (FR-020)
     */
    public static function getPlatformStats(): array {
        $pdo = Database::getConnection();

        // Total Users
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
        $suspendedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();

        // Total Token Balances
        $balanceStmt = $pdo->query("SELECT COALESCE(SUM(available_balance), 0) as total_available, COALESCE(SUM(reserved_balance), 0) as total_reserved FROM wallets");
        $balances = $balanceStmt->fetch();

        // Total Buy / Sell Completed Volumes
        $buyVolume = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM child_orders WHERE status = 'Completed'")->fetchColumn();

        // Pending Disputes / Appeals
        $pendingAppeals = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('pending', 'under_review')")->fetchColumn();

        // Open child orders in market pool
        $openOrders = (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Available'")->fetchColumn();

        return [
            'total_users' => $totalUsers,
            'suspended_users' => $suspendedUsers,
            'total_available_tokens' => (float)$balances['total_available'],
            'total_reserved_tokens' => (float)$balances['total_reserved'],
            'total_tokens_in_circulation' => (float)$balances['total_available'] + (float)$balances['total_reserved'],
            'total_completed_trading_volume' => $buyVolume,
            'pending_appeals_count' => $pendingAppeals,
            'active_market_listings' => $openOrders
        ];
    }

    /**
     * Arbitrate Dispute / Appeal (FR-018)
     * Decisions: 'force_settle' (favor buyer) or 'cancel_reverse' (favor seller).
     */
    public static function resolveDispute(int $disputeId, int $adminId, string $decision, string $resolutionNotes): array {
        if (empty(trim($resolutionNotes))) {
            return ['success' => false, 'error' => 'A mandatory resolution note must be provided for arbitration auditing.'];
        }

        $pdo = Database::getConnection();

        $dispStmt = $pdo->prepare("
            SELECT d.*, c.amount, c.snapshotted_reward_amount, c.seller_id, c.buyer_id, c.parent_order_id, c.status as order_status
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            WHERE d.id = ?
        ");
        $dispStmt->execute([$disputeId]);
        $dispute = $dispStmt->fetch();

        if (!$dispute) {
            return ['success' => false, 'error' => 'Dispute record not found.'];
        }

        if (in_array($dispute['status'], ['resolved_buyer', 'resolved_seller'], true)) {
            return ['success' => false, 'error' => 'This dispute has already been resolved.'];
        }

        $childId = (int)$dispute['child_order_id'];
        $sellerId = (int)$dispute['seller_id'];
        $buyerId = (int)$dispute['buyer_id'];
        $amount = (float)$dispute['amount'];
        $rewardAmount = (float)$dispute['snapshotted_reward_amount'];
        $resolvedAt = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            if ($decision === 'force_settle') {
                // Payment verified -> Force settlement
                $updOrder = $pdo->prepare("UPDATE child_orders SET status = 'Completed', completed_at = ? WHERE id = ?");
                $updOrder->execute([$resolvedAt, $childId]);

                // Settle seller reserved tokens
                Wallet::settleSellOrder($sellerId, $amount, (string)$childId);

                // Credit buyer tokens + 9% reward
                Wallet::creditBuyer($buyerId, $amount, $rewardAmount, (string)$childId);

                // Distribute referral commissions
                ReferralEngine::distributeCommissions($buyerId, $amount, (string)$childId);

                // Update parent order
                $updParent = $pdo->prepare("UPDATE parent_sell_orders SET completed_amount = completed_amount + ?, reserved_amount = reserved_amount - ? WHERE id = ?");
                $updParent->execute([$amount, $amount, $dispute['parent_order_id']]);

                // Update dispute status
                $updDisp = $pdo->prepare("
                    UPDATE disputes
                    SET status = 'resolved_buyer', resolution_notes = ?, resolved_by_admin_id = ?, resolved_at = ?
                    WHERE id = ?
                ");
                $updDisp->execute([trim($resolutionNotes), $adminId, $resolvedAt, $disputeId]);

                self::logAudit(
                    $adminId,
                    'FORCE_SETTLE_DISPUTE',
                    'disputes',
                    (string)$disputeId,
                    ['status' => $dispute['status']],
                    ['status' => 'resolved_buyer', 'order_status' => 'Completed'],
                    $resolutionNotes
                );
            } elseif ($decision === 'cancel_reverse') {
                // Payment unverified -> Cancel order and return tokens to seller
                $updOrder = $pdo->prepare("UPDATE child_orders SET status = 'Cancelled / Reversed' WHERE id = ?");
                $updOrder->execute([$childId]);

                // Release seller reserved tokens back to available balance
                Wallet::releaseReserved($sellerId, $amount, (string)$childId, "Dispute #{$disputeId} resolved in favor of seller: " . trim($resolutionNotes));

                // Update parent order
                $updParent = $pdo->prepare("UPDATE parent_sell_orders SET reserved_amount = reserved_amount - ? WHERE id = ?");
                $updParent->execute([$amount, $dispute['parent_order_id']]);

                // Update dispute status
                $updDisp = $pdo->prepare("
                    UPDATE disputes
                    SET status = 'resolved_seller', resolution_notes = ?, resolved_by_admin_id = ?, resolved_at = ?
                    WHERE id = ?
                ");
                $updDisp->execute([trim($resolutionNotes), $adminId, $resolvedAt, $disputeId]);

                self::logAudit(
                    $adminId,
                    'CANCEL_REVERSE_DISPUTE',
                    'disputes',
                    (string)$disputeId,
                    ['status' => $dispute['status']],
                    ['status' => 'resolved_seller', 'order_status' => 'Cancelled / Reversed'],
                    $resolutionNotes
                );
            } else {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Invalid decision '{$decision}'. Choose 'force_settle' or 'cancel_reverse'."];
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'Dispute resolved and audited successfully.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Arbitration failed: ' . $e->getMessage()];
        }
    }

    /**
     * Manual Balance Adjustment (FR-021)
     */
    public static function manualAdjustment(int $targetUserId, string $type, float $amount, string $reason, int $adminId): array {
        $pdo = Database::getConnection();

        // Fetch user before state
        $walletBefore = Wallet::getWallet($targetUserId);

        $pdo->beginTransaction();
        try {
            $res = Wallet::adminAdjust($targetUserId, $type, $amount, $reason, $adminId);
            if (!$res['success']) {
                $pdo->rollBack();
                return $res;
            }

            $walletAfter = Wallet::getWallet($targetUserId);

            self::logAudit(
                $adminId,
                'MANUAL_BALANCE_ADJUSTMENT',
                'wallets',
                (string)$targetUserId,
                ['available_balance' => (float)$walletBefore['available_balance']],
                ['available_balance' => (float)$walletAfter['available_balance'], 'adjustment' => "{$type} {$amount}"],
                $reason
            );

            $pdo->commit();
            return $res;
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Manual adjustment failed: ' . $e->getMessage()];
        }
    }

    /**
     * Start Admin Impersonation Support Session (FR-022)
     */
    public static function startImpersonation(int $adminId, int $targetUserId): array {
        $pdo = Database::getConnection();
        $targetStmt = $pdo->prepare("SELECT id, phone, role, account_status FROM users WHERE id = ?");
        $targetStmt->execute([$targetUserId]);
        $targetUser = $targetStmt->fetch();

        if (!$targetUser) {
            return ['success' => false, 'error' => 'Target user not found.'];
        }

        // Set impersonation session flags
        $_SESSION['real_admin_id'] = $adminId;
        $_SESSION['user_id'] = (int)$targetUser['id'];
        $_SESSION['phone'] = $targetUser['phone'];
        $_SESSION['is_impersonating'] = true;

        self::logAudit(
            $adminId,
            'START_IMPERSONATION',
            'users',
            (string)$targetUserId,
            null,
            ['impersonating_user_id' => $targetUserId],
            'Support inspection mode activated'
        );

        return ['success' => true, 'target_user' => $targetUser];
    }

    /**
     * Exit Admin Impersonation Session
     */
    public static function exitImpersonation(): void {
        if (!empty($_SESSION['real_admin_id'])) {
            $adminId = (int)$_SESSION['real_admin_id'];
            $_SESSION['user_id'] = $adminId;
            $_SESSION['role'] = 'admin';
            $_SESSION['is_impersonating'] = false;
            unset($_SESSION['real_admin_id']);
        }
    }

    /**
     * Update Global Configurations (FR-023)
     */
    public static function updateSetting(string $key, string $value, int $adminId, string $reason): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $curStmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
        $curStmt->execute([$key]);
        $curr = $curStmt->fetch();

        if (!$curr) {
            return ['success' => false, 'error' => "Configuration key '{$key}' does not exist."];
        }

        $beforeVal = $curr['setting_value'];

        $upd = $pdo->prepare("
            UPDATE platform_settings
            SET setting_value = ?, updated_by = ?, updated_at = ?
            WHERE setting_key = ?
        ");
        $upd->execute([$value, $adminId, $nowUtc, $key]);

        self::logAudit(
            $adminId,
            'UPDATE_SETTING',
            'platform_settings',
            $key,
            ['value' => $beforeVal],
            ['value' => $value],
            $reason
        );

        return ['success' => true, 'message' => "Setting '{$key}' updated successfully."];
    }

    /**
     * Toggle user suspension
     */
    public static function toggleUserSuspension(int $userId, int $adminId, string $reason): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT account_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $newStatus = ($user['account_status'] === 'active') ? 'suspended' : 'active';
        $upd = $pdo->prepare("UPDATE users SET account_status = ? WHERE id = ?");
        $upd->execute([$newStatus, $userId]);

        self::logAudit(
            $adminId,
            'TOGGLE_USER_STATUS',
            'users',
            (string)$userId,
            ['status' => $user['account_status']],
            ['status' => $newStatus],
            $reason
        );

        return ['success' => true, 'new_status' => $newStatus];
    }
}
