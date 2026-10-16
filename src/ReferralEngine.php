<?php
/**
 * Multi-Tier Referral & Commission Engine
 * Manages 2-tier referral tree (L1 1%, L2 0.5%), loop prevention, PII masking, and commission payouts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Wallet.php';

class ReferralEngine {
    /**
     * Distribute multi-tier referral commissions on completed buy orders (FR-019)
     * Level 1: 1% to immediate referrer.
     * Level 2: 0.5% to parent of immediate referrer.
     */
    public static function distributeCommissions(int $buyerId, float $orderAmount, string $orderReferenceId): array {
        $pdo = Database::getConnection();

        // Find Level 1 referrer
        $l1Stmt = $pdo->prepare("SELECT referred_by_id FROM users WHERE id = ?");
        $l1Stmt->execute([$buyerId]);
        $buyer = $l1Stmt->fetch();

        if (!$buyer || empty($buyer['referred_by_id'])) {
            return ['level_1_paid' => 0.0, 'level_2_paid' => 0.0];
        }

        $l1Id = (int)$buyer['referred_by_id'];
        if ($l1Id === $buyerId) {
            // Prevent self-referral
            return ['level_1_paid' => 0.0, 'level_2_paid' => 0.0];
        }

        // Fetch commission rates from settings
        $l1RateStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'referral_l1_percent'");
        $l1Rate = (float)($l1RateStmt->fetchColumn() ?: 1.0);

        $l2RateStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'referral_l2_percent'");
        $l2Rate = (float)($l2RateStmt->fetchColumn() ?: 0.5);

        $commissions = Validator::calculateReferralCommissions($orderAmount, $l1Rate, $l2Rate);

        // Credit Level 1
        $l1Paid = 0.0;
        if ($commissions['level_1_commission'] > 0) {
            Wallet::creditReferral($l1Id, $commissions['level_1_commission'], 1, $orderReferenceId);
            $l1Paid = $commissions['level_1_commission'];
        }

        // Find Level 2 referrer
        $l2Stmt = $pdo->prepare("SELECT referred_by_id FROM users WHERE id = ?");
        $l2Stmt->execute([$l1Id]);
        $l1User = $l2Stmt->fetch();

        $l2Paid = 0.0;
        if ($l1User && !empty($l1User['referred_by_id'])) {
            $l2Id = (int)$l1User['referred_by_id'];
            // Prevent circular referral loops
            if ($l2Id !== $buyerId && $l2Id !== $l1Id && $commissions['level_2_commission'] > 0) {
                Wallet::creditReferral($l2Id, $commissions['level_2_commission'], 2, $orderReferenceId);
                $l2Paid = $commissions['level_2_commission'];
            }
        }

        return [
            'level_1_referrer_id' => $l1Id,
            'level_1_paid' => $l1Paid,
            'level_2_referrer_id' => $l2Id ?? null,
            'level_2_paid' => $l2Paid
        ];
    }

    /**
     * Get user's referral tree with masked phone numbers for privacy (FR-019)
     */
    public static function getReferralTree(int $userId): array {
        $pdo = Database::getConnection();

        // Level 1 members
        $l1Stmt = $pdo->prepare("
            SELECT u.id, u.phone, u.created_at,
                   COALESCE((SELECT SUM(amount) FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'REFERRAL_L1' AND actor_id = u.id), 0) as earned_from_user
            FROM users u
            WHERE u.referred_by_id = ?
            ORDER BY u.id DESC
        ");
        $l1Stmt->execute([$userId, $userId]);
        $l1Members = $l1Stmt->fetchAll();

        $level1 = [];
        $l1Ids = [];
        foreach ($l1Members as $m) {
            $l1Ids[] = (int)$m['id'];
            $level1[] = [
                'user_id' => (int)$m['id'],
                'phone_masked' => Validator::maskPhone($m['phone']),
                'joined_at' => $m['created_at'],
                'earned' => (float)$m['earned_from_user']
            ];
        }

        // Level 2 members
        $level2 = [];
        if (!empty($l1Ids)) {
            $placeholders = implode(',', array_fill(0, count($l1Ids), '?'));
            $l2Stmt = $pdo->prepare("
                SELECT u.id, u.phone, u.referred_by_id, u.created_at
                FROM users u
                WHERE u.referred_by_id IN ($placeholders)
                ORDER BY u.id DESC
            ");
            $l2Stmt->execute($l1Ids);
            $l2Members = $l2Stmt->fetchAll();

            foreach ($l2Members as $m) {
                $level2[] = [
                    'user_id' => (int)$m['id'],
                    'phone_masked' => Validator::maskPhone($m['phone']),
                    'referred_by_l1' => (int)$m['referred_by_id'],
                    'joined_at' => $m['created_at']
                ];
            }
        }

        // Total commissions earned
        $earnedStmt = $pdo->prepare("SELECT total_commissions_earned FROM wallets WHERE user_id = ?");
        $earnedStmt->execute([$userId]);
        $totalCommission = (float)($earnedStmt->fetchColumn() ?: 0.0);

        return [
            'level_1_count' => count($level1),
            'level_2_count' => count($level2),
            'total_commissions_earned' => $totalCommission,
            'level_1_network' => $level1,
            'level_2_network' => $level2
        ];
    }
}
