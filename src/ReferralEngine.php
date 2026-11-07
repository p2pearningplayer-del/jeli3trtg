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
            Wallet::creditReferral($l1Id, $commissions['level_1_commission'], 1, $orderReferenceId, $buyerId);
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
                Wallet::creditReferral($l2Id, $commissions['level_2_commission'], 2, $orderReferenceId, $buyerId);
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
     * Granular commission metrics: today's earnings, specific day performance, and active team size.
     */
    public static function getReferralTree(int $userId): array {
        $pdo = Database::getConnection();

        // Level 1 members
        $l1Stmt = $pdo->prepare("
            SELECT u.id, u.phone, u.created_at, u.account_status,
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
                'account_status' => $m['account_status'],
                'earned' => (float)$m['earned_from_user'],
                'is_active' => false // Will be determined below
            ];
        }

        // Level 2 members
        $level2 = [];
        $l2Ids = [];
        if (!empty($l1Ids)) {
            $placeholders = implode(',', array_fill(0, count($l1Ids), '?'));
            $l2Stmt = $pdo->prepare("
                SELECT u.id, u.phone, u.referred_by_id, u.created_at, u.account_status
                FROM users u
                WHERE u.referred_by_id IN ($placeholders)
                ORDER BY u.id DESC
            ");
            $l2Stmt->execute($l1Ids);
            $l2Members = $l2Stmt->fetchAll();

            foreach ($l2Members as $m) {
                $l2Ids[] = (int)$m['id'];
                $level2[] = [
                    'user_id' => (int)$m['id'],
                    'phone_masked' => Validator::maskPhone($m['phone']),
                    'referred_by_l1' => (int)$m['referred_by_id'],
                    'joined_at' => $m['created_at'],
                    'account_status' => $m['account_status'],
                    'is_active' => false // Will be determined below
                ];
            }
        }

        // Identify Active Team Members (Placed orders or generated commissions)
        $allReferredIds = array_unique(array_merge($l1Ids, $l2Ids));
        $activeIdMap = [];
        if (!empty($allReferredIds)) {
            $ph = implode(',', array_fill(0, count($allReferredIds), '?'));
            try {
                $actStmt = $pdo->prepare("
                    SELECT DISTINCT user_id FROM wallets WHERE user_id IN ($ph) AND (available_balance > 0 OR total_rewards_earned > 0 OR total_commissions_earned > 0)
                    UNION
                    SELECT DISTINCT user_id FROM wallet_ledger WHERE user_id IN ($ph)
                    UNION
                    SELECT DISTINCT buyer_id FROM child_orders WHERE buyer_id IN ($ph)
                    UNION
                    SELECT DISTINCT seller_id FROM parent_sell_orders WHERE seller_id IN ($ph)
                ");
                $params = array_merge($allReferredIds, $allReferredIds, $allReferredIds, $allReferredIds);
                $actStmt->execute($params);
                $tradeIds = $actStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tradeIds as $tid) {
                    $activeIdMap[(int)$tid] = true;
                }
            } catch (Exception $e) {}
        }

        // Update is_active for Level 1 and Level 2 members
        foreach ($level1 as &$m1) {
            if ($m1['account_status'] === 'active' && (!empty($activeIdMap[$m1['user_id']]) || $m1['earned'] > 0)) {
                $m1['is_active'] = true;
            }
        }
        unset($m1);

        foreach ($level2 as &$m2) {
            if ($m2['account_status'] === 'active' && !empty($activeIdMap[$m2['user_id']])) {
                $m2['is_active'] = true;
            }
        }
        unset($m2);

        $l1ActiveCount = count(array_filter($level1, fn($x) => !empty($x['is_active'])));
        $l2ActiveCount = count(array_filter($level2, fn($x) => !empty($x['is_active'])));
        $activeTeamSize = $l1ActiveCount + $l2ActiveCount;
        $totalTeamSize = count($level1) + count($level2);

        // Fetch granular referral commissions from wallet_ledger
        $ledgerStmt = $pdo->prepare("
            SELECT amount, created_at, transaction_type
            FROM wallet_ledger
            WHERE user_id = ? AND transaction_type IN ('REFERRAL_L1', 'REFERRAL_L2')
            ORDER BY created_at ASC
        ");
        $ledgerStmt->execute([$userId]);
        $commissionLedger = $ledgerStmt->fetchAll();

        $todayUtc = gmdate('Y-m-d');
        $todayLocal = date('Y-m-d');
        $todayEarnings = 0.0;

        $daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $dayOfWeekEarnings = array_fill_keys($daysOfWeek, 0.0);

        // Recent 7 days timeline
        $dailyTimeline = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $dayName = date('l', strtotime($d));
            $dailyTimeline[$d] = [
                'date' => $d,
                'day_name' => $dayName,
                'short_day' => date('D', strtotime($d)),
                'earnings' => 0.0,
                'is_today' => ($i === 0)
            ];
        }

        foreach ($commissionLedger as $row) {
            $amt = (float)$row['amount'];
            $ts = strtotime($row['created_at']);
            $dateStr = date('Y-m-d', $ts);
            $dayName = date('l', $ts);

            if ($dateStr === $todayUtc || $dateStr === $todayLocal) {
                $todayEarnings += $amt;
            }

            if (isset($dayOfWeekEarnings[$dayName])) {
                $dayOfWeekEarnings[$dayName] = round($dayOfWeekEarnings[$dayName] + $amt, 2);
            }

            if (isset($dailyTimeline[$dateStr])) {
                $dailyTimeline[$dateStr]['earnings'] = round($dailyTimeline[$dateStr]['earnings'] + $amt, 2);
            }
        }
        $todayEarnings = round($todayEarnings, 2);

        // Total commissions earned
        $earnedStmt = $pdo->prepare("SELECT total_commissions_earned FROM wallets WHERE user_id = ?");
        $earnedStmt->execute([$userId]);
        $totalCommission = (float)($earnedStmt->fetchColumn() ?: 0.0);

        return [
            'level_1_count' => count($level1),
            'level_2_count' => count($level2),
            'total_team_count' => $totalTeamSize,
            'active_team_size' => $activeTeamSize,
            'level_1_active_count' => $l1ActiveCount,
            'level_2_active_count' => $l2ActiveCount,
            'total_commissions_earned' => $totalCommission,
            'today_earnings' => $todayEarnings,
            'day_of_week_earnings' => $dayOfWeekEarnings,
            'daily_timeline' => array_values($dailyTimeline),
            'level_1_network' => $level1,
            'level_2_network' => $level2
        ];
    }
}
