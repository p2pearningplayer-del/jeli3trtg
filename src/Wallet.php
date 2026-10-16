<?php
/**
 * User Wallet Management Service
 * Manages balance segregation (Available, Reserved, Pending), concurrency locks, and ledger integrity.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Ledger.php';

class Wallet {
    /**
     * Get or initialize a user's wallet record
     */
    public static function getWallet(int $userId, bool $forUpdate = false): array {
        $pdo = Database::getConnection();
        $sql = "SELECT * FROM wallets WHERE user_id = ?";
        if ($forUpdate && Database::getDriver() === 'mysql') {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch();

        if (!$wallet) {
            $insert = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0.00, 0.00, 0.00, 1)");
            $insert->execute([$userId]);
            return self::getWallet($userId, $forUpdate);
        }

        return $wallet;
    }

    /**
     * Reserve tokens for a sell listing (FR-004, FR-011)
     */
    public static function reserveForSell(int $userId, float $amount, string $referenceId): array {
        $pdo = Database::getConnection();
        $wallet = self::getWallet($userId, true);

        $available = (float)$wallet['available_balance'];
        if ($available < $amount) {
            return [
                'success' => false,
                'error' => sprintf('Insufficient available balance. You have ₹%.2f available, cannot reserve ₹%.2f.', $available, $amount)
            ];
        }

        $newAvailable = round($available - $amount, 2);
        $newReserved = round((float)$wallet['reserved_balance'] + $amount, 2);
        $newVersion = (int)$wallet['version'] + 1;

        $update = $pdo->prepare("
            UPDATE wallets
            SET available_balance = ?, reserved_balance = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$newAvailable, $newReserved, $newVersion, $userId, $wallet['version']]);

        if ($update->rowCount() === 0) {
            return ['success' => false, 'error' => 'Wallet concurrency conflict. Please retry.'];
        }

        // Record in ledger
        Ledger::record(
            $userId,
            'SELL_RESERVE',
            $amount,
            $available,
            $newAvailable,
            'available',
            $referenceId,
            $userId,
            'user',
            "Tokens reserved for sell order #{$referenceId}"
        );

        return ['success' => true, 'new_available' => $newAvailable, 'new_reserved' => $newReserved];
    }

    /**
     * Release reserved tokens back to available balance (FR-010, Timeout / Cancellation)
     */
    public static function releaseReserved(int $userId, float $amount, string $referenceId, string $reason = 'Order expired or cancelled'): array {
        $pdo = Database::getConnection();
        $wallet = self::getWallet($userId, true);

        $reserved = (float)$wallet['reserved_balance'];
        $releaseAmount = min($amount, $reserved);
        if ($releaseAmount <= 0) {
            return ['success' => true, 'message' => 'No reserved balance to release.'];
        }

        $newReserved = round($reserved - $releaseAmount, 2);
        $newAvailable = round((float)$wallet['available_balance'] + $releaseAmount, 2);
        $newVersion = (int)$wallet['version'] + 1;

        $update = $pdo->prepare("
            UPDATE wallets
            SET available_balance = ?, reserved_balance = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$newAvailable, $newReserved, $newVersion, $userId, $wallet['version']]);

        Ledger::record(
            $userId,
            'SELL_RELEASE',
            $releaseAmount,
            $reserved,
            $newReserved,
            'reserved',
            $referenceId,
            $userId,
            'system',
            $reason
        );

        return ['success' => true, 'new_available' => $newAvailable, 'new_reserved' => $newReserved];
    }

    /**
     * Settle completed sell order (deduct from reserved balance)
     */
    public static function settleSellOrder(int $sellerId, float $amount, string $referenceId): array {
        $pdo = Database::getConnection();
        $wallet = self::getWallet($sellerId, true);

        $reserved = (float)$wallet['reserved_balance'];
        $deductAmount = min($amount, $reserved);
        $newReserved = round($reserved - $deductAmount, 2);
        $newVersion = (int)$wallet['version'] + 1;

        $update = $pdo->prepare("
            UPDATE wallets
            SET reserved_balance = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$newReserved, $newVersion, $sellerId, $wallet['version']]);

        Ledger::record(
            $sellerId,
            'SELL_SETTLE',
            $deductAmount,
            $reserved,
            $newReserved,
            'reserved',
            $referenceId,
            $sellerId,
            'user',
            "Payment received - tokens transferred for order #{$referenceId}"
        );

        return ['success' => true, 'new_reserved' => $newReserved];
    }

    /**
     * Credit buyer with purchased tokens + 9% promotional reward (FR-006, QA-001)
     */
    public static function creditBuyer(int $buyerId, float $baseAmount, float $rewardAmount, string $referenceId): array {
        $pdo = Database::getConnection();
        $wallet = self::getWallet($buyerId, true);

        $prevAvailable = (float)$wallet['available_balance'];
        $prevRewards = (float)$wallet['total_rewards_earned'];

        $interimAvailable = round($prevAvailable + $baseAmount, 2);
        $finalAvailable = round($interimAvailable + $rewardAmount, 2);
        $newRewards = round($prevRewards + $rewardAmount, 2);
        $newVersion = (int)$wallet['version'] + 1;

        $update = $pdo->prepare("
            UPDATE wallets
            SET available_balance = ?, total_rewards_earned = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$finalAvailable, $newRewards, $newVersion, $buyerId, $wallet['version']]);

        // Ledger entry for Base Purchased Tokens
        Ledger::record(
            $buyerId,
            'BUY_CREDIT',
            $baseAmount,
            $prevAvailable,
            $interimAvailable,
            'available',
            $referenceId,
            $buyerId,
            'system',
            "Tokens purchased via UPI order #{$referenceId}"
        );

        // Ledger entry for 9% Promotional Purchase Reward
        if ($rewardAmount > 0) {
            Ledger::record(
                $buyerId,
                'BUY_REWARD',
                $rewardAmount,
                $interimAvailable,
                $finalAvailable,
                'available',
                $referenceId,
                $buyerId,
                'system',
                "9% promotional purchase reward for order #{$referenceId}"
            );
        }

        return [
            'success' => true,
            'total_credited' => $baseAmount + $rewardAmount,
            'new_available' => $finalAvailable
        ];
    }

    /**
     * Credit referral commission (FR-019)
     */
    public static function creditReferral(int $userId, float $amount, int $level, string $referenceId): array {
        if ($amount <= 0) return ['success' => true];

        $pdo = Database::getConnection();
        $wallet = self::getWallet($userId, true);

        $prevAvailable = (float)$wallet['available_balance'];
        $prevCommissions = (float)$wallet['total_commissions_earned'];

        $newAvailable = round($prevAvailable + $amount, 2);
        $newCommissions = round($prevCommissions + $amount, 2);
        $newVersion = (int)$wallet['version'] + 1;

        $update = $pdo->prepare("
            UPDATE wallets
            SET available_balance = ?, total_commissions_earned = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$newAvailable, $newCommissions, $newVersion, $userId, $wallet['version']]);

        $type = ($level === 1) ? 'REFERRAL_L1' : 'REFERRAL_L2';
        Ledger::record(
            $userId,
            $type,
            $amount,
            $prevAvailable,
            $newAvailable,
            'available',
            $referenceId,
            $userId,
            'system',
            "Level {$level} referral commission from order #{$referenceId}"
        );

        return ['success' => true, 'new_available' => $newAvailable];
    }

    /**
     * Admin manual balance adjustment with mandatory audit reason (FR-021)
     */
    public static function adminAdjust(int $userId, string $type, float $amount, string $reason, int $adminId): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Adjustment amount must be greater than zero.'];
        }

        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'A mandatory text reason must be provided for audit tracking.'];
        }

        $pdo = Database::getConnection();
        $wallet = self::getWallet($userId, true);

        $available = (float)$wallet['available_balance'];

        if ($type === 'debit') {
            if ($available < $amount) {
                return [
                    'success' => false,
                    'error' => sprintf('Insufficient Available Balance for debit. User only has ₹%.2f available. Reserved balance cannot be debited.', $available)
                ];
            }
            $newAvailable = round($available - $amount, 2);
            $txType = 'ADMIN_DEBIT';
        } else {
            $newAvailable = round($available + $amount, 2);
            $txType = 'ADMIN_CREDIT';
        }

        $newVersion = (int)$wallet['version'] + 1;
        $update = $pdo->prepare("
            UPDATE wallets
            SET available_balance = ?, version = ?
            WHERE user_id = ? AND version = ?
        ");
        $update->execute([$newAvailable, $newVersion, $userId, $wallet['version']]);

        Ledger::record(
            $userId,
            $txType,
            $amount,
            $available,
            $newAvailable,
            'available',
            "ADJ_" . time(),
            $adminId,
            'admin',
            "Admin manual adjustment: " . trim($reason)
        );

        return ['success' => true, 'new_available' => $newAvailable];
    }

    /**
     * Authoritative dashboard summary statistics (FR-003, Section 6)
     * Pulls exclusively from backend database sum queries.
     */
    public static function getDashboardSummaries(int $userId): array {
        $pdo = Database::getConnection();
        $wallet = self::getWallet($userId);

        // Pending Buy volume (orders picked by user that are Reserved or Payment Proof Submitted)
        $pendingBuyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM child_orders
            WHERE buyer_id = ? AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending')
        ");
        $pendingBuyStmt->execute([$userId]);
        $pendingBuy = (float)$pendingBuyStmt->fetchColumn();

        // Pending Sell volume (child orders in active state)
        $pendingSellStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM child_orders
            WHERE seller_id = ? AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending')
        ");
        $pendingSellStmt->execute([$userId]);
        $pendingSell = (float)$pendingSellStmt->fetchColumn();

        // Total Completed Buy Volume
        $buyVolStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM child_orders
            WHERE buyer_id = ? AND status = 'Completed'
        ");
        $buyVolStmt->execute([$userId]);
        $completedBuyVolume = (float)$buyVolStmt->fetchColumn();

        // Total Completed Sell Volume
        $sellVolStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM child_orders
            WHERE seller_id = ? AND status = 'Completed'
        ");
        $sellVolStmt->execute([$userId]);
        $completedSellVolume = (float)$sellVolStmt->fetchColumn();

        // Weekly Selling Progress % (Target: ₹4,00,000)
        // Formula: Progress % = (Eligible Completed Vol / 400000) * 100, Capped at 100%
        $weeklyTarget = WEEKLY_SELLING_TARGET;
        $progressPercent = min(100.0, round(($completedSellVolume / $weeklyTarget) * 100.0, 2));

        return [
            'available_balance' => (float)$wallet['available_balance'],
            'reserved_balance' => (float)$wallet['reserved_balance'],
            'pending_buy_volume' => $pendingBuy,
            'pending_sell_volume' => $pendingSell,
            'total_rewards' => (float)$wallet['total_rewards_earned'],
            'total_commissions' => (float)$wallet['total_commissions_earned'],
            'completed_buy_volume' => $completedBuyVolume,
            'completed_sell_volume' => $completedSellVolume,
            'weekly_target' => $weeklyTarget,
            'weekly_progress_percent' => $progressPercent
        ];
    }
}
