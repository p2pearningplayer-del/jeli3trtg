<?php
/**
 * Health Score & Admin Risk Management Service
 * Calculates explainable, auditable user health scores (0-100 scale)
 * and enforces backend risk controls, temporary blocking, and sell speed limits.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Auth.php';

class HealthScoreService {

    public const THRESHOLD_RISK = 50;

    /**
     * Adjust a user's health score with an explainable reason, audit record, and threshold check.
     */
    public static function adjustScore(
        int $userId,
        int $delta,
        string $reason,
        string $eventType = 'SYSTEM_EVENT',
        ?int $adminId = null
    ): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, health_score, previous_health_score, account_status, sell_restricted, risk_review_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $oldScore = (int)($user['health_score'] ?? 100);
        $newScore = max(0, min(100, $oldScore + $delta));
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Determine if account status or risk review status changes due to falling to <= 50
        $newStatus = $user['account_status'];
        $newSellRestricted = (int)($user['sell_restricted'] ?? 0);
        $newReviewStatus = $user['risk_review_status'] ?: 'none';
        $triggeredRisk = false;

        if ($newScore <= self::THRESHOLD_RISK) {
            $triggeredRisk = true;
            $newReviewStatus = 'pending_review';
            // Apply reversible temporary restriction pending admin review
            if ($newStatus === 'active') {
                $newStatus = 'temporarily_blocked';
            }
            $newSellRestricted = 1;
        } elseif ($newScore > self::THRESHOLD_RISK && $user['account_status'] === 'temporarily_blocked' && $oldScore <= self::THRESHOLD_RISK && $delta > 0 && $adminId !== null) {
            // Admin restorative adjustment can lift temporary block
            $newStatus = 'active';
            $newReviewStatus = 'reviewed';
        }

        $pdo->beginTransaction();
        try {
            // Update user record
            $upd = $pdo->prepare("
                UPDATE users
                SET previous_health_score = ?,
                    health_score = ?,
                    health_score_updated_at = ?,
                    account_status = ?,
                    sell_restricted = ?,
                    risk_review_status = ?
                WHERE id = ?
            ");
            $upd->execute([$oldScore, $newScore, $nowUtc, $newStatus, $newSellRestricted, $newReviewStatus, $userId]);

            // Insert audit record
            $audit = $pdo->prepare("
                INSERT INTO health_score_audit_logs (user_id, old_score, new_score, delta, reason, event_type, admin_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $audit->execute([$userId, $oldScore, $newScore, $delta, $reason, $eventType, $adminId, $nowUtc]);

            $pdo->commit();

            // In-app user notification
            $notifType = ($delta < 0) ? 'warning' : 'info';
            $notifTitle = ($delta < 0) ? 'Health Score Deducted' : 'Health Score Updated';
            $notifMsg = "Your platform Health Score was adjusted by {$delta} points ({$newScore}/100). Reason: {$reason}.";
            if ($triggeredRisk) {
                $notifMsg .= " Notice: Because your Health Score is at or below 50, your account has been temporarily restricted pending review.";
            }
            Auth::createNotification($userId, $notifTitle, $notifMsg, $notifType);

            return [
                'success' => true,
                'user_id' => $userId,
                'old_score' => $oldScore,
                'new_score' => $newScore,
                'delta' => $delta,
                'account_status' => $newStatus,
                'is_restricted' => ($newScore <= self::THRESHOLD_RISK),
                'risk_review_status' => $newReviewStatus
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to adjust health score: ' . $e->getMessage()];
        }
    }

    /**
     * Get user audit history of all health score adjustments.
     */
    public static function getUserHealthHistory(int $userId, int $limit = 30): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT h.*, a.phone as admin_phone, a.account_holder_name as admin_name
            FROM health_score_audit_logs h
            LEFT JOIN users a ON h.admin_id = a.id
            WHERE h.user_id = ?
            ORDER BY h.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get low health score users for Admin Panel -> Risk Management -> Low Health Score Users.
     */
    public static function getLowHealthScoreUsers(array $filters = [], int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
        $where = ["1=1"];
        $params = [];

        // Score range filter
        $range = $filters['range'] ?? 'below_50';
        if ($range === 'below_50') {
            $where[] = "u.health_score <= 50";
        } elseif ($range === 'below_70') {
            $where[] = "u.health_score <= 70";
        } elseif ($range === 'all_low') {
            $where[] = "u.health_score < 100";
        }

        // Account status filter
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "u.account_status = ?";
            $params[] = $filters['status'];
        }

        // Review status filter
        if (!empty($filters['review_status']) && $filters['review_status'] !== 'all') {
            $where[] = "u.risk_review_status = ?";
            $params[] = $filters['review_status'];
        }

        // Text query (User ID or phone)
        if (!empty($filters['query'])) {
            $q = '%' . trim($filters['query']) . '%';
            $where[] = "(u.phone LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) = ?)";
            $params[] = $q;
            $params[] = $q;
            $params[] = trim($filters['query']);
        }

        $whereClause = implode(" AND ", $where);

        // Count total matching
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Fetch users with related dispute counts and order stats
        $sql = "
            SELECT u.id, u.phone, u.email, u.account_holder_name, u.role, u.account_status,
                   u.health_score, u.previous_health_score, u.health_score_updated_at,
                   u.sell_restricted, u.sell_restriction_reason, u.sell_order_limit, u.sell_cooldown_seconds,
                   u.risk_review_status, u.risk_notes, u.created_at as registered_at,
                   (SELECT COUNT(*) FROM child_orders WHERE (buyer_id = u.id OR seller_id = u.id) AND status = 'Completed') as completed_order_count,
                   (SELECT COUNT(*) FROM child_orders WHERE (buyer_id = u.id OR seller_id = u.id) AND status LIKE '%Cancel%') as cancelled_order_count,
                   (SELECT COUNT(*) FROM disputes d JOIN child_orders co ON d.child_order_id = co.id WHERE (co.buyer_id = u.id OR co.seller_id = u.id) AND d.status NOT IN ('resolved', 'rejected', 'resolved_buyer', 'resolved_seller')) as open_dispute_count,
                   (SELECT COUNT(*) FROM disputes d JOIN child_orders co ON d.child_order_id = co.id WHERE (co.buyer_id = u.id OR co.seller_id = u.id)) as total_dispute_count,
                   (SELECT reason FROM health_score_audit_logs WHERE user_id = u.id ORDER BY id DESC LIMIT 1) as last_score_reason,
                   (SELECT created_at FROM health_score_audit_logs WHERE user_id = u.id ORDER BY id DESC LIMIT 1) as last_score_date
            FROM users u
            WHERE {$whereClause}
            ORDER BY u.health_score ASC, u.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll();

        return [
            'users' => $users,
            'total_count' => $totalCount,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Admin Action: Temporarily block user account (Reversible risk action with audit log).
     */
    public static function adminTemporarilyBlock(int $userId, int $adminId, string $reason): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE users
                SET account_status = 'temporarily_blocked',
                    risk_review_status = 'pending_review',
                    risk_notes = COALESCE(risk_notes || '\n', '') || ?
                WHERE id = ?
            ");
            $upd->execute(["[{$nowUtc}] Temporarily blocked by Admin #{$adminId}: {$reason}", $userId]);

            // Audit log
            $log = $pdo->prepare("
                INSERT INTO admin_audit_logs (admin_id, action_type, target_entity, target_id, reason, ip_address, created_at)
                VALUES (?, 'TEMPORARY_BLOCK', 'users', ?, ?, ?, ?)
            ");
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log->execute([$adminId, (string)$userId, $reason, $clientIp, $nowUtc]);

            $pdo->commit();

            Auth::createNotification(
                $userId,
                'Account Temporarily Restricted',
                "Your account has been temporarily restricted pending review. Reason: {$reason}. Tokens and wallet balances remain secure.",
                'warning'
            );

            return ['success' => true, 'message' => "User #{$userId} temporarily blocked."];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to block user: ' . $e->getMessage()];
        }
    }

    /**
     * Admin Action: Restore user account access.
     */
    public static function adminRestoreAccess(int $userId, int $adminId, string $reason): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE users
                SET account_status = 'active',
                    risk_review_status = 'reviewed',
                    sell_restricted = 0,
                    risk_notes = COALESCE(risk_notes || '\n', '') || ?
                WHERE id = ?
            ");
            $upd->execute(["[{$nowUtc}] Access restored by Admin #{$adminId}: {$reason}", $userId]);

            // Audit log
            $log = $pdo->prepare("
                INSERT INTO admin_audit_logs (admin_id, action_type, target_entity, target_id, reason, ip_address, created_at)
                VALUES (?, 'RESTORE_ACCESS', 'users', ?, ?, ?, ?)
            ");
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log->execute([$adminId, (string)$userId, $reason, $clientIp, $nowUtc]);

            $pdo->commit();

            Auth::createNotification(
                $userId,
                'Account Access Restored',
                "Your account access has been fully restored by administration. Reason: {$reason}.",
                'info'
            );

            return ['success' => true, 'message' => "User #{$userId} access restored."];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to restore user access: ' . $e->getMessage()];
        }
    }

    /**
     * Admin Action: Configure sell restrictions (sell frequency cooldown, max order limit, or block).
     */
    public static function adminConfigureSellRestrictions(
        int $userId,
        bool $sellRestricted,
        ?float $orderLimit,
        int $cooldownSeconds,
        int $adminId,
        ?string $reason = null
    ): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $cleanReason = trim((string)$reason) ?: 'Admin risk policy';

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE users
                SET sell_restricted = ?,
                    sell_restriction_reason = ?,
                    sell_order_limit = ?,
                    sell_cooldown_seconds = ?
                WHERE id = ?
            ");
            $upd->execute([
                $sellRestricted ? 1 : 0,
                $cleanReason,
                ($orderLimit !== null && $orderLimit > 0) ? $orderLimit : null,
                max(0, $cooldownSeconds),
                $userId
            ]);

            // Audit log
            $log = $pdo->prepare("
                INSERT INTO admin_audit_logs (admin_id, action_type, target_entity, target_id, reason, ip_address, created_at)
                VALUES (?, 'SET_SELL_RESTRICTIONS', 'users', ?, ?, ?, ?)
            ");
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $details = "SellRestricted=" . ($sellRestricted ? '1' : '0') . ", Limit=" . ($orderLimit ?? 'none') . ", Cooldown=" . $cooldownSeconds . "s. Reason: {$cleanReason}";
            $log->execute([$adminId, (string)$userId, $details, $clientIp, $nowUtc]);

            $pdo->commit();

            return ['success' => true, 'message' => "Sell controls configured for User #{$userId}."];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to configure sell controls: ' . $e->getMessage()];
        }
    }

    /**
     * Admin Action: Add internal review note or mark reviewed.
     */
    public static function adminAddRiskNote(int $userId, string $reviewStatus, string $note, int $adminId): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');
        $formattedNote = "[{$nowUtc} - Admin #{$adminId}]: {$note}";

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE users
                SET risk_review_status = ?,
                    risk_notes = COALESCE(risk_notes || '\n', '') || ?
                WHERE id = ?
            ");
            $upd->execute([$reviewStatus, $formattedNote, $userId]);

            $log = $pdo->prepare("
                INSERT INTO admin_audit_logs (admin_id, action_type, target_entity, target_id, reason, ip_address, created_at)
                VALUES (?, 'RISK_REVIEW_NOTE', 'users', ?, ?, ?, ?)
            ");
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $log->execute([$adminId, (string)$userId, "Status: {$reviewStatus}. Note: {$note}", $clientIp, $nowUtc]);

            $pdo->commit();
            return ['success' => true, 'message' => "Risk note recorded for User #{$userId}."];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to save risk note: ' . $e->getMessage()];
        }
    }
}
