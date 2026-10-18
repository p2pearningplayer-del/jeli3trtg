<?php
/**
 * Administrator Operations & Governance Manager
 * Provides complete control over users, orders, disputes, finances, settings,
 * audit trails, and data export reporting.
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
     * Check role-based permission
     */
    public static function checkPermission(array $adminUser, string $permission): bool {
        if ($adminUser['role'] !== 'admin') {
            return false;
        }

        $adminRole = $adminUser['admin_role'] ?? 'super_admin';
        if ($adminRole === 'super_admin') {
            return true;
        }

        $rolePermissions = [
            'order_manager' => [
                'dashboard_view', 'users_view', 'orders_view', 'orders_manage',
                'reports_view', 'disputes_view', 'settings_view'
            ],
            'dispute_manager' => [
                'dashboard_view', 'users_view', 'disputes_view', 'disputes_arbitrate',
                'evidence_request', 'refund_track', 'reports_view'
            ],
            'support_agent' => [
                'dashboard_view', 'users_view', 'disputes_view', 'disputes_arbitrate',
                'evidence_request', 'refund_track', 'reports_view'
            ],
            'support_team' => [
                'dashboard_view', 'users_view', 'disputes_view', 'disputes_arbitrate',
                'evidence_request', 'refund_track', 'reports_view'
            ],
            'finance_manager' => [
                'dashboard_view', 'users_view', 'wallets_manage', 'adjust_balance',
                'reports_view', 'ledger_view'
            ],
            'finance_team' => [
                'dashboard_view', 'users_view', 'wallets_manage', 'adjust_balance',
                'reports_view', 'ledger_view'
            ]
        ];

        return in_array($permission, $rolePermissions[$adminRole] ?? [], true);
    }

    /**
     * Comprehensive real-time platform statistics (Requirement 1)
     */
    public static function getPlatformStats(): array {
        $pdo = Database::getConnection();

        // 1. Users
        $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'active'")->fetchColumn();
        $suspendedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();
        $demoUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_demo = 1")->fetchColumn();

        // 2. Balances
        $balanceStmt = $pdo->query("
            SELECT COALESCE(SUM(available_balance), 0) as total_available,
                   COALESCE(SUM(reserved_balance), 0) as total_reserved,
                   COALESCE(SUM(pending_credit_balance), 0) as total_pending
            FROM wallets
        ");
        $balances = $balanceStmt->fetch();

        // 3. Volumes
        $buyVolume = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM child_orders WHERE status = 'Completed'")->fetchColumn();
        $sellVolume = (float)$pdo->query("SELECT COALESCE(SUM(completed_amount), 0) FROM parent_sell_orders")->fetchColumn();

        // 4. Order state counts
        $orderCounts = [
            'total' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders")->fetchColumn(),
            'available' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Available'")->fetchColumn(),
            'reserved' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Reserved'")->fetchColumn(),
            'pending_confirmation' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status IN ('Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout')")->fetchColumn(),
            'disputed' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Disputed'")->fetchColumn(),
            'under_review' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Under Admin Review'")->fetchColumn(),
            'completed' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Completed'")->fetchColumn(),
            'cancelled' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Cancelled / Reversed'")->fetchColumn(),
            'expired' => (int)$pdo->query("SELECT COUNT(*) FROM child_orders WHERE status = 'Expired'")->fetchColumn(),
        ];

        // 5. Appeals & Disputes
        $pendingAppeals = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('pending', 'under_review')")->fetchColumn();
        $resolvedAppeals = (int)$pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('resolved_buyer', 'resolved_seller')")->fetchColumn();

        // 6. Rewards & Commissions
        $totalRewards = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE transaction_type = 'BUY_REWARD'")->fetchColumn();
        $totalL1 = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE transaction_type = 'REFERRAL_L1'")->fetchColumn();
        $totalL2 = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM wallet_ledger WHERE transaction_type = 'REFERRAL_L2'")->fetchColumn();

        // 7. Weekly Target
        $targetStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'weekly_selling_target'");
        $weeklyTarget = (float)($targetStmt ? $targetStmt->fetchColumn() : 400000.00);
        if ($weeklyTarget <= 0) $weeklyTarget = 400000.00;

        $sevenDaysAgo = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $weeklyVolStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM child_orders WHERE status = 'Completed' AND completed_at >= ?");
        $weeklyVolStmt->execute([$sevenDaysAgo]);
        $weeklyVolume = (float)$weeklyVolStmt->fetchColumn();
        $weeklyPercent = min(100.0, round(($weeklyVolume / $weeklyTarget) * 100, 1));

        // 8. Recent Activities
        $recentTrans = $pdo->query("
            SELECT l.*, u.phone as user_phone, u.account_holder_name
            FROM wallet_ledger l
            JOIN users u ON l.user_id = u.id
            ORDER BY l.id DESC LIMIT 8
        ")->fetchAll();

        $recentAudits = $pdo->query("
            SELECT a.*, u.phone as admin_phone, u.account_holder_name as admin_name
            FROM admin_audit_logs a
            JOIN users u ON a.admin_id = u.id
            ORDER BY a.id DESC LIMIT 8
        ")->fetchAll();

        return [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'suspended_users' => $suspendedUsers,
            'demo_users' => $demoUsers,
            'total_available_tokens' => (float)$balances['total_available'],
            'total_reserved_tokens' => (float)$balances['total_reserved'],
            'total_pending_tokens' => (float)$balances['total_pending'],
            'total_tokens_in_circulation' => (float)$balances['total_available'] + (float)$balances['total_reserved'],
            'total_buy_volume' => $buyVolume,
            'total_sell_volume' => $sellVolume,
            'total_completed_trading_volume' => $buyVolume,
            'orders' => $orderCounts,
            'pending_confirmations_count' => $orderCounts['pending_confirmation'],
            'pending_appeals_count' => $pendingAppeals,
            'resolved_appeals_count' => $resolvedAppeals,
            'total_rewards' => $totalRewards,
            'total_l1_commissions' => $totalL1,
            'total_l2_commissions' => $totalL2,
            'total_commissions' => $totalL1 + $totalL2,
            'weekly_selling_target' => $weeklyTarget,
            'weekly_completed_volume' => $weeklyVolume,
            'weekly_progress_percent' => $weeklyPercent,
            'recent_transactions' => $recentTrans,
            'recent_admin_activities' => $recentAudits
        ];
    }

    /**
     * Search and filter users (Requirement 2)
     */
    public static function searchUsers(string $query = '', string $status = 'all', string $role = 'all', int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
        $params = [];
        $where = ["1=1"];

        if (!empty(trim($query))) {
            $q = '%' . trim($query) . '%';
            $where[] = "(u.phone LIKE ? OR u.email LIKE ? OR u.referral_code LIKE ? OR u.account_holder_name LIKE ? OR CAST(u.id AS CHAR) = ?)";
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = trim($query);
        }

        if ($status !== 'all') {
            $where[] = "u.account_status = ?";
            $params[] = $status;
        }

        if ($role !== 'all') {
            $where[] = "u.role = ?";
            $params[] = $role;
        }

        $whereClause = implode(" AND ", $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT u.id, u.phone, u.email, u.role, u.admin_role, u.account_status, u.is_demo, u.is_flagged, u.flag_reason,
                   u.referral_code, u.referred_by_id, u.upi_id, u.account_holder_name, u.created_at,
                   w.available_balance, w.reserved_balance, w.total_rewards_earned, w.total_commissions_earned
            FROM users u
            LEFT JOIN wallets w ON u.id = w.user_id
            WHERE {$whereClause}
            ORDER BY u.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        return [
            'total' => $totalCount,
            'users' => $users
        ];
    }

    /**
     * Get complete user dossier without exposing passwords or TPINs (Requirement 2)
     */
    public static function getUserDetails(int $userId): ?array {
        $pdo = Database::getConnection();

        // 1. User profile
        $userStmt = $pdo->prepare("
            SELECT u.id, u.phone, u.email, u.role, u.admin_role, u.account_status, u.is_demo, u.is_flagged, u.flag_reason,
                   u.referral_code, u.referred_by_id, u.upi_id, u.account_holder_name, u.created_at,
                   u_ref.phone as referred_by_phone, u_ref.account_holder_name as referred_by_name
            FROM users u
            LEFT JOIN users u_ref ON u.referred_by_id = u_ref.id
            WHERE u.id = ?
        ");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();

        if (!$user) return null;

        // 2. Wallet
        $wallet = Wallet::getWallet($userId);

        // 3. Buy orders (purchases)
        $buyStmt = $pdo->prepare("
            SELECT c.*, p.utr_number, p.screenshot_path,
                   u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            JOIN users u_seller ON c.seller_id = u_seller.id
            WHERE c.buyer_id = ?
            ORDER BY c.id DESC LIMIT 50
        ");
        $buyStmt->execute([$userId]);
        $buyOrders = $buyStmt->fetchAll();

        // 4. Sell orders (listings)
        $sellStmt = $pdo->prepare("
            SELECT p.*,
                   (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id) as child_count
            FROM parent_sell_orders p
            WHERE p.seller_id = ?
            ORDER BY p.id DESC LIMIT 50
        ");
        $sellStmt->execute([$userId]);
        $sellOrders = $sellStmt->fetchAll();

        // 5. Complete wallet ledger history
        $ledger = Ledger::getUserHistory($userId, 100);

        // 6. Referral Tree
        $tree = ReferralEngine::getReferralTree($userId);

        // 7. Audit logs targeting this user
        $auditStmt = $pdo->prepare("
            SELECT a.*, u_admin.phone as admin_phone, u_admin.account_holder_name as admin_name
            FROM admin_audit_logs a
            JOIN users u_admin ON a.admin_id = u_admin.id
            WHERE a.target_entity = 'users' AND a.target_id = ?
            ORDER BY a.id DESC LIMIT 20
        ");
        $auditStmt->execute([(string)$userId]);
        $auditLogs = $auditStmt->fetchAll();

        return [
            'user' => $user,
            'wallet' => $wallet,
            'buy_orders' => $buyOrders,
            'sell_orders' => $sellOrders,
            'ledger' => $ledger,
            'referral_tree' => $tree,
            'audit_logs' => $auditLogs
        ];
    }

    /**
     * Flag or unflag suspicious user account (Requirement 2)
     */
    public static function toggleUserFlag(int $userId, int $adminId, string $reason): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, phone, is_flagged, flag_reason FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $newFlag = $user['is_flagged'] ? 0 : 1;
        $flagReason = $newFlag ? trim($reason) : null;

        $upd = $pdo->prepare("UPDATE users SET is_flagged = ?, flag_reason = ? WHERE id = ?");
        $upd->execute([$newFlag, $flagReason, $userId]);

        self::logAudit(
            $adminId,
            $newFlag ? 'FLAG_USER_SUSPICIOUS' : 'UNFLAG_USER',
            'users',
            (string)$userId,
            ['is_flagged' => $user['is_flagged']],
            ['is_flagged' => $newFlag, 'reason' => $flagReason],
            $reason
        );

        return ['success' => true, 'is_flagged' => $newFlag, 'flag_reason' => $flagReason];
    }

    /**
     * Query all orders (Parent and Child) with complete filtering (Requirement 3)
     */
    public static function getOrders(string $status = 'all', string $type = 'child', string $query = '', int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();

        if ($type === 'parent') {
            $where = ["1=1"];
            $params = [];
            if ($status !== 'all') {
                $where[] = "p.status = ?";
                $params[] = $status;
            }
            if (!empty(trim($query))) {
                $q = '%' . trim($query) . '%';
                $where[] = "(p.id = ? OR u.phone LIKE ? OR u.account_holder_name LIKE ?)";
                $params[] = trim($query);
                $params[] = $q;
                $params[] = $q;
            }
            $whereClause = implode(" AND ", $where);

            $stmt = $pdo->prepare("
                SELECT p.*, u.phone as seller_phone, u.account_holder_name as seller_name,
                       (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id) as child_count
                FROM parent_sell_orders p
                JOIN users u ON p.seller_id = u.id
                WHERE {$whereClause}
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?
            ");
            $bindIdx = 1;
            foreach ($params as $p) {
                $stmt->bindValue($bindIdx++, $p);
            }
            $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
            return ['orders' => $stmt->fetchAll()];
        }

        // Child orders
        $where = ["1=1"];
        $params = [];
        if ($status !== 'all') {
            $where[] = "c.status = ?";
            $params[] = $status;
        }
        if (!empty(trim($query))) {
            $q = '%' . trim($query) . '%';
            $where[] = "(c.id = ? OR c.parent_order_id = ? OR p.utr_number LIKE ? OR u_seller.phone LIKE ? OR u_buyer.phone LIKE ?)";
            $params[] = trim($query);
            $params[] = trim($query);
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }
        $whereClause = implode(" AND ", $where);

        $stmt = $pdo->prepare("
            SELECT c.*, p.utr_number, p.screenshot_path,
                   u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name,
                   u_buyer.phone as buyer_phone, u_buyer.account_holder_name as buyer_name,
                   d.id as dispute_id, d.status as dispute_status
            FROM child_orders c
            JOIN users u_seller ON c.seller_id = u_seller.id
            LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE {$whereClause}
            ORDER BY c.id DESC
            LIMIT ? OFFSET ?
        ");
        $bindIdx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($bindIdx++, $p);
        }
        $stmt->bindValue($bindIdx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['orders' => $stmt->fetchAll()];
    }

    /**
     * Admin cancel micro-order & return reserved tokens to seller (Requirement 3)
     */
    public static function cancelChildOrder(int $childId, int $adminId, string $reason): array {
        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'A mandatory reason is required for administrative order cancellation.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ?");
        $stmt->execute([$childId]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order portion not found.'];
        }

        if (in_array($order['status'], ['Completed', 'Cancelled / Reversed'], true)) {
            return ['success' => false, 'error' => "Cannot cancel an order in '{$order['status']}' state."];
        }

        $amount = (float)$order['amount'];
        $sellerId = (int)$order['seller_id'];

        $pdo->beginTransaction();
        try {
            // Update order status
            $upd = $pdo->prepare("UPDATE child_orders SET status = 'Cancelled / Reversed' WHERE id = ?");
            $upd->execute([$childId]);

            // Release seller reserved tokens back to available balance
            Wallet::releaseReserved($sellerId, $amount, (string)$childId, "Admin cancelled order #{$childId}: " . trim($reason));

            // Update parent order reserved amount
            $pUpd = $pdo->prepare("UPDATE parent_sell_orders SET reserved_amount = reserved_amount - ? WHERE id = ?");
            $pUpd->execute([$amount, $order['parent_order_id']]);

            self::logAudit(
                $adminId,
                'ADMIN_CANCEL_ORDER',
                'child_orders',
                (string)$childId,
                ['status' => $order['status'], 'amount' => $amount],
                ['status' => 'Cancelled / Reversed'],
                $reason
            );

            $pdo->commit();
            return ['success' => true, 'message' => "Order #{$childId} cancelled and ₹{$amount} returned to seller available balance."];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to cancel order: ' . $e->getMessage()];
        }
    }

    /**
     * Arbitrate Dispute / Appeal (Requirement 4)
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
                // Payment verified -> Force settlement to buyer
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
     * Request additional evidence for a dispute (Requirement 4)
     */
    public static function requestAdditionalEvidence(int $disputeId, int $adminId, string $instructions): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM disputes WHERE id = ?");
        $stmt->execute([$disputeId]);
        $disp = $stmt->fetch();

        if (!$disp) {
            return ['success' => false, 'error' => 'Dispute not found.'];
        }

        $childStmt = $pdo->prepare("SELECT id, buyer_id, seller_id, amount FROM child_orders WHERE id = ?");
        $childStmt->execute([$disp['child_order_id']]);
        $child = $childStmt->fetch();

        $upd = $pdo->prepare("
            UPDATE disputes
            SET additional_evidence_requested = 1, status = 'under_review', resolution_notes = ?
            WHERE id = ?
        ");
        $cleanInstructions = trim($instructions);
        $note = "Admin requested additional evidence: " . $cleanInstructions;
        $upd->execute([$note, $disputeId]);

        // Trigger automatic notifications to both parties (Part 7, Item 3)
        $formattedOrderId = sprintf('#%04d', (int)$disp['child_order_id']);
        $notifTitle = "Evidence Requested: Order {$formattedOrderId}";
        $notifBody = "Admin has requested specific evidence for Dispute #{$disputeId}: \"{$cleanInstructions}\". Please open the Appeals Center to upload your photo, video, or bank statement proof.";

        if ($child) {
            if (!empty($child['buyer_id'])) {
                Auth::createNotification((int)$child['buyer_id'], $notifTitle, $notifBody, 'dispute', (string)$disputeId);
            }
            if (!empty($child['seller_id'])) {
                Auth::createNotification((int)$child['seller_id'], $notifTitle, $notifBody, 'dispute', (string)$disputeId);
            }
        }

        self::logAudit(
            $adminId,
            'REQUEST_ADDITIONAL_EVIDENCE',
            'disputes',
            (string)$disputeId,
            ['status' => $disp['status']],
            ['status' => 'under_review', 'additional_evidence_requested' => 1],
            $instructions
        );

        return ['success' => true, 'message' => 'Evidence request dispatched to buyer and seller with instant notifications.'];
    }

    /**
     * Update dispute refund tracking status (Requirement 4)
     */
    public static function updateRefundStatus(int $disputeId, int $adminId, string $status, ?string $refundRef, string $notes): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM disputes WHERE id = ?");
        $stmt->execute([$disputeId]);
        $disp = $stmt->fetch();

        if (!$disp) {
            return ['success' => false, 'error' => 'Dispute not found.'];
        }

        $upd = $pdo->prepare("
            UPDATE disputes
            SET refund_status = ?, refund_reference = ?
            WHERE id = ?
        ");
        $upd->execute([$status, $refundRef ? trim($refundRef) : null, $disputeId]);

        self::logAudit(
            $adminId,
            'UPDATE_DISPUTE_REFUND_STATUS',
            'disputes',
            (string)$disputeId,
            ['refund_status' => $disp['refund_status']],
            ['refund_status' => $status, 'refund_reference' => $refundRef],
            $notes
        );

        return ['success' => true, 'message' => 'Refund status updated successfully.'];
    }

    /**
     * Manual Balance Adjustment (Requirement 2, FR-021)
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
     * Start Admin Impersonation Support Session (Requirement 2, FR-022)
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
     * Update Global Configurations & Content (Requirements 5 & 6)
     */
    public static function updateSetting(string $key, string $value, int $adminId, string $reason = 'Admin configuration update'): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $curStmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
        $curStmt->execute([$key]);
        $curr = $curStmt->fetch();

        $beforeVal = $curr ? $curr['setting_value'] : null;

        if ($curr) {
            $upd = $pdo->prepare("
                UPDATE platform_settings
                SET setting_value = ?, updated_by = ?, updated_at = ?
                WHERE setting_key = ?
            ");
            $upd->execute([$value, $adminId, $nowUtc, $key]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at)
                VALUES (?, ?, ?, ?)
            ");
            $ins->execute([$key, $value, $adminId, $nowUtc]);
        }

        self::logAudit(
            $adminId,
            'UPDATE_SETTING',
            'platform_settings',
            $key,
            ['value' => $beforeVal],
            ['value' => $value],
            !empty(trim($reason)) ? $reason : 'Admin configuration update'
        );

        return ['success' => true, 'message' => "Setting '{$key}' updated successfully."];
    }

    /**
     * Toggle user suspension (Requirement 2)
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

    /**
     * Export downloadable CSV reports (Requirement 7)
     */
    public static function exportReportCsv(string $type): string {
        $pdo = Database::getConnection();
        $output = fopen('php://temp', 'r+');

        switch ($type) {
            case 'users':
                fputcsv($output, ['User ID', 'Phone', 'Email', 'Role', 'Admin Role', 'Status', 'Is Demo', 'Flagged', 'Available Balance', 'Reserved Balance', 'Total Rewards', 'Total Commissions', 'Referral Code', 'Registered At (UTC)']);
                $stmt = $pdo->query("
                    SELECT u.id, u.phone, u.email, u.role, u.admin_role, u.account_status, u.is_demo, u.is_flagged,
                           w.available_balance, w.reserved_balance, w.total_rewards_earned, w.total_commissions_earned,
                           u.referral_code, u.created_at
                    FROM users u
                    LEFT JOIN wallets w ON u.id = w.user_id
                    ORDER BY u.id ASC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'orders':
                fputcsv($output, ['Child Order ID', 'Parent Order ID', 'Seller Phone', 'Buyer Phone', 'Amount', 'Reward Rate (%)', 'Reward Amount', 'Status', 'UTR Number', 'Created At (UTC)', 'Completed At (UTC)']);
                $stmt = $pdo->query("
                    SELECT c.id, c.parent_order_id, u_seller.phone as seller_phone, u_buyer.phone as buyer_phone,
                           c.amount, c.snapshotted_reward_rate, c.snapshotted_reward_amount, c.status,
                           p.utr_number, c.created_at, c.completed_at
                    FROM child_orders c
                    JOIN users u_seller ON c.seller_id = u_seller.id
                    LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
                    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
                    ORDER BY c.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'ledger':
                fputcsv($output, ['Ledger ID', 'User ID', 'User Phone', 'Type', 'Amount', 'Previous Balance', 'New Balance', 'Pool', 'Reference', 'Actor Role', 'Notes', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT l.id, l.user_id, u.phone as user_phone, l.transaction_type, l.amount,
                           l.previous_balance, l.new_balance, l.balance_type, l.reference_id, l.actor_role,
                           l.notes, l.created_at
                    FROM wallet_ledger l
                    JOIN users u ON l.user_id = u.id
                    ORDER BY l.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'rewards':
                fputcsv($output, ['Ledger ID', 'User ID', 'Phone', 'Reward Amount', 'Order Reference', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT l.id, l.user_id, u.phone, l.amount, l.reference_id, l.created_at
                    FROM wallet_ledger l
                    JOIN users u ON l.user_id = u.id
                    WHERE l.transaction_type = 'BUY_REWARD'
                    ORDER BY l.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'commissions':
                fputcsv($output, ['Ledger ID', 'User ID', 'Phone', 'Tier', 'Commission Amount', 'Reference', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT l.id, l.user_id, u.phone, l.transaction_type, l.amount, l.reference_id, l.created_at
                    FROM wallet_ledger l
                    JOIN users u ON l.user_id = u.id
                    WHERE l.transaction_type IN ('REFERRAL_L1', 'REFERRAL_L2')
                    ORDER BY l.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'disputes':
                fputcsv($output, ['Dispute ID', 'Child Order ID', 'Raised By Role', 'Reason', 'Status', 'Refund Status', 'Refund Ref', 'Resolved By Admin', 'Resolution Notes', 'Created At (UTC)', 'Resolved At (UTC)']);
                $stmt = $pdo->query("
                    SELECT d.id, d.child_order_id, d.raised_by_role, d.reason, d.status,
                           d.refund_status, d.refund_reference, u_admin.phone as resolved_by,
                           d.resolution_notes, d.created_at, d.resolved_at
                    FROM disputes d
                    LEFT JOIN users u_admin ON d.resolved_by_admin_id = u_admin.id
                    ORDER BY d.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'reversals':
                fputcsv($output, ['Ledger ID', 'User ID', 'Phone', 'Type', 'Amount', 'Reference', 'Notes', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT l.id, l.user_id, u.phone, l.transaction_type, l.amount, l.reference_id, l.notes, l.created_at
                    FROM wallet_ledger l
                    JOIN users u ON l.user_id = u.id
                    WHERE l.transaction_type IN ('SELL_RELEASE', 'DISPUTE_REVERSAL')
                    ORDER BY l.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'weekly_volume':
                fputcsv($output, ['Order ID', 'Seller Phone', 'Buyer Phone', 'Amount', 'Completed At (UTC)']);
                $stmt = $pdo->query("
                    SELECT c.id, u_seller.phone as seller_phone, u_buyer.phone as buyer_phone, c.amount, c.completed_at
                    FROM child_orders c
                    JOIN users u_seller ON c.seller_id = u_seller.id
                    JOIN users u_buyer ON c.buyer_id = u_buyer.id
                    WHERE c.status = 'Completed'
                    ORDER BY c.completed_at DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'adjustments':
                fputcsv($output, ['Audit ID', 'Admin Phone', 'User ID', 'Action', 'Before Balance', 'After Balance', 'Reason', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT a.id, u.phone as admin_phone, a.target_id as user_id, a.action_type,
                           a.before_state, a.after_state, a.reason, a.created_at
                    FROM admin_audit_logs a
                    JOIN users u ON a.admin_id = u.id
                    WHERE a.action_type = 'MANUAL_BALANCE_ADJUSTMENT'
                    ORDER BY a.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;

            case 'audit_logs':
            default:
                fputcsv($output, ['Audit ID', 'Admin ID', 'Admin Phone', 'Action Type', 'Target Entity', 'Target ID', 'Before State', 'After State', 'Reason', 'IP Address', 'Timestamp (UTC)']);
                $stmt = $pdo->query("
                    SELECT a.id, a.admin_id, u.phone as admin_phone, a.action_type, a.target_entity, a.target_id,
                           a.before_state, a.after_state, a.reason, a.ip_address, a.created_at
                    FROM admin_audit_logs a
                    JOIN users u ON a.admin_id = u.id
                    ORDER BY a.id DESC
                ");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                break;
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /**
     * Requirement 4: Permanently purge all demo test accounts & artifacts,
     * switching the platform completely into 100% Live / Real Mode.
     */
    public static function purgeDemoData(int $adminId): array {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // Find demo accounts
            $demoStmt = $pdo->query("SELECT id FROM users WHERE is_demo = 1 OR phone IN ('9888800000', '9888800001')");
            $demoUserIds = $demoStmt->fetchAll(PDO::FETCH_COLUMN);

            $purgedCount = count($demoUserIds);

            if (!empty($demoUserIds)) {
                $inClause = implode(',', array_map('intval', $demoUserIds));

                // 1. Delete associated payment proofs
                $pdo->exec("DELETE FROM payment_proofs WHERE child_order_id IN (SELECT id FROM child_orders WHERE buyer_id IN ($inClause) OR seller_id IN ($inClause))");

                // 2. Delete disputes
                $pdo->exec("DELETE FROM disputes WHERE child_order_id IN (SELECT id FROM child_orders WHERE buyer_id IN ($inClause) OR seller_id IN ($inClause))");

                // 4. Delete child orders
                $pdo->exec("DELETE FROM child_orders WHERE buyer_id IN ($inClause) OR seller_id IN ($inClause)");

                // 5. Delete parent sell orders
                $pdo->exec("DELETE FROM parent_sell_orders WHERE seller_id IN ($inClause)");

                // 6. Delete ledger entries
                $pdo->exec("DELETE FROM wallet_ledger WHERE user_id IN ($inClause)");

                // 7. Delete wallets
                $pdo->exec("DELETE FROM wallets WHERE user_id IN ($inClause)");

                // 8. Delete notifications
                $pdo->exec("DELETE FROM notifications WHERE user_id IN ($inClause)");

                // 9. Delete support requests
                $pdo->exec("DELETE FROM support_requests WHERE user_id IN ($inClause)");

                // 10. Delete the users themselves
                $pdo->exec("DELETE FROM users WHERE id IN ($inClause)");
            }

            // Set platform_mode to 'live' in platform_settings
            $updateMode = $pdo->prepare("UPDATE platform_settings SET setting_value = 'live', updated_at = CURRENT_TIMESTAMP WHERE setting_key = 'platform_mode'");
            $updateMode->execute();

            // Log audit
            self::logAudit(
                $adminId,
                'PURGE_DEMO_DATA',
                'system',
                '0',
                null,
                ['purged_users' => $purgedCount, 'mode' => 'live'],
                'Switched platform to 100% Live Mode. Permanently purged demo users and mock orders.'
            );

            $pdo->commit();

            return [
                'success' => true,
                'purged_users' => $purgedCount,
                'platform_mode' => 'live',
                'message' => "Successfully purged $purgedCount demo accounts and associated records. Platform is now operating in 100% Live Mode."
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Failed to purge demo data: ' . $e->getMessage()
            ];
        }
    }

    /**
     * List all administrator accounts and their roles (Part 10, Item 4)
     */
    public static function listAdmins(): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT id, phone, email, role, admin_role, account_status, referral_code, account_holder_name, created_at
            FROM users
            WHERE role = 'admin' OR admin_role IS NOT NULL
            ORDER BY id ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Create a new administrator account with role-based access (Part 10, Item 4)
     */
    public static function createAdmin(array $data, int $byAdminId): array {
        $cleanPhone = Validator::cleanPhone($data['phone'] ?? '');
        if (!Validator::validatePhone($cleanPhone)) {
            return ['success' => false, 'error' => 'Please provide a valid 10-digit Indian mobile number for administrator login.'];
        }

        $password = $data['password'] ?? '';
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
        }

        $adminRole = $data['admin_role'] ?? 'support_agent';
        $allowedRoles = ['super_admin', 'support_agent', 'support_team', 'finance_manager', 'finance_team', 'order_manager', 'dispute_manager'];
        if (!in_array($adminRole, $allowedRoles, true)) {
            $adminRole = 'support_agent';
        }

        $pdo = Database::getConnection();

        // Check if phone already registered
        $stmt = $pdo->prepare("SELECT id, role, admin_role FROM users WHERE phone = ?");
        $stmt->execute([$cleanPhone]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ($existing['role'] === 'admin') {
                return ['success' => false, 'error' => 'An administrator account with this phone number already exists.'];
            }
            // Elevate existing user to admin
            $passHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $upd = $pdo->prepare("
                UPDATE users
                SET role = 'admin', admin_role = ?, password_hash = ?, account_status = 'active',
                    email = COALESCE(?, email), account_holder_name = COALESCE(?, account_holder_name)
                WHERE id = ?
            ");
            $upd->execute([
                $adminRole,
                $passHash,
                !empty($data['email']) ? trim($data['email']) : null,
                !empty($data['name']) ? trim($data['name']) : null,
                $existing['id']
            ]);

            self::logAudit(
                $byAdminId,
                'UPGRADE_USER_TO_ADMIN',
                'users',
                (string)$existing['id'],
                ['role' => $existing['role'], 'admin_role' => $existing['admin_role']],
                ['role' => 'admin', 'admin_role' => $adminRole],
                'Existing account elevated to administrator role'
            );

            return [
                'success' => true,
                'admin_id' => (int)$existing['id'],
                'phone' => $cleanPhone,
                'admin_role' => $adminRole,
                'message' => 'Existing account elevated to administrator successfully.'
            ];
        }

        $referralCode = strtoupper('ADM' . substr(md5(uniqid($cleanPhone, true)), 0, 6));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO users (phone, email, password_hash, account_status, role, admin_role, referral_code, account_holder_name)
                VALUES (?, ?, ?, 'active', 'admin', ?, ?, ?)
            ");
            $ins->execute([
                $cleanPhone,
                !empty($data['email']) ? trim($data['email']) : null,
                $passwordHash,
                $adminRole,
                $referralCode,
                !empty($data['name']) ? trim($data['name']) : null
            ]);
            $newAdminId = (int)$pdo->lastInsertId();

            // Create zeroed wallet
            $wIns = $pdo->prepare("
                INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version)
                VALUES (?, 0.00, 0.00, 0.00, 1)
            ");
            $wIns->execute([$newAdminId]);

            self::logAudit(
                $byAdminId,
                'CREATE_ADMIN',
                'users',
                (string)$newAdminId,
                null,
                ['phone' => $cleanPhone, 'admin_role' => $adminRole, 'role' => 'admin'],
                'Administrator created via Admin Management Console'
            );

            $pdo->commit();

            return [
                'success' => true,
                'admin_id' => $newAdminId,
                'phone' => $cleanPhone,
                'admin_role' => $adminRole,
                'message' => 'New administrator created successfully.'
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Failed to create administrator: ' . $e->getMessage()];
        }
    }

    /**
     * Update an administrator's assigned team / role (Part 10, Item 4)
     */
    public static function updateAdminRole(int $targetAdminId, string $newRole, int $byAdminId): array {
        $allowedRoles = ['super_admin', 'support_agent', 'support_team', 'finance_manager', 'finance_team', 'order_manager', 'dispute_manager'];
        if (!in_array($newRole, $allowedRoles, true)) {
            return ['success' => false, 'error' => "Invalid admin role '{$newRole}'."];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, role, admin_role, phone FROM users WHERE id = ?");
        $stmt->execute([$targetAdminId]);
        $target = $stmt->fetch();

        if (!$target || $target['role'] !== 'admin') {
            return ['success' => false, 'error' => 'Target administrator not found.'];
        }

        // Prevent demoting the last super_admin
        if ($target['admin_role'] === 'super_admin' && $newRole !== 'super_admin') {
            $superCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND admin_role = 'super_admin' AND account_status = 'active'")->fetchColumn();
            if ($superCount <= 1) {
                return ['success' => false, 'error' => 'Cannot reassign role: platform must retain at least one active Super Admin.'];
            }
        }

        $oldRole = $target['admin_role'];
        $upd = $pdo->prepare("UPDATE users SET admin_role = ? WHERE id = ?");
        $upd->execute([$newRole, $targetAdminId]);

        self::logAudit(
            $byAdminId,
            'UPDATE_ADMIN_ROLE',
            'users',
            (string)$targetAdminId,
            ['admin_role' => $oldRole],
            ['admin_role' => $newRole],
            "Reassigned administrator role from '{$oldRole}' to '{$newRole}'"
        );

        return ['success' => true, 'message' => "Administrator role updated to {$newRole}."];
    }

    /**
     * Remove / Delete an administrator (Part 10, Item 4)
     */
    public static function deleteAdmin(int $targetAdminId, int $byAdminId): array {
        if ($targetAdminId === $byAdminId) {
            return ['success' => false, 'error' => 'You cannot remove your own active administrator account.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, role, admin_role, phone FROM users WHERE id = ?");
        $stmt->execute([$targetAdminId]);
        $target = $stmt->fetch();

        if (!$target || $target['role'] !== 'admin') {
            return ['success' => false, 'error' => 'Target administrator not found.'];
        }

        // Check if target is the last super_admin
        if ($target['admin_role'] === 'super_admin') {
            $superCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND admin_role = 'super_admin' AND account_status = 'active'")->fetchColumn();
            if ($superCount <= 1) {
                return ['success' => false, 'error' => 'Cannot remove the last active Super Admin.'];
            }
        }

        try {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetAdminId]);
        } catch (Exception $e) {
            // Fallback: revoke admin permissions and suspend
            $upd = $pdo->prepare("UPDATE users SET role = 'user', admin_role = NULL, account_status = 'suspended' WHERE id = ?");
            $upd->execute([$targetAdminId]);
        }

        self::logAudit(
            $byAdminId,
            'DELETE_ADMIN',
            'users',
            (string)$targetAdminId,
            ['phone' => $target['phone'], 'admin_role' => $target['admin_role']],
            null,
            'Administrator removed via Admin Management Console'
        );

        return ['success' => true, 'message' => 'Administrator removed successfully.'];
    }

    /**
     * Change admin password with current password verification (Part 10, Item 4)
     */
    public static function changeAdminPassword(int $adminId, string $currentPassword, string $newPassword): array {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters long.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, role, password_hash, phone FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $user = $stmt->fetch();

        if (!$user || $user['role'] !== 'admin') {
            return ['success' => false, 'error' => 'Administrator account not found.'];
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password verification failed. Please check your current password.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $adminId]);

        self::logAudit(
            $adminId,
            'CHANGE_ADMIN_PASSWORD',
            'users',
            (string)$adminId,
            null,
            ['phone' => $user['phone']],
            'Administrator login password updated securely'
        );

        return ['success' => true, 'message' => 'Administrator login password changed successfully.'];
    }
}
