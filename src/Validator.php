<?php
/**
 * Input Validator & Business Mathematics Helper
 * Enforces denomination limits, modulo checks, phone validation, and reward calculations.
 */

require_once __DIR__ . '/../config/config.php';

class Validator {
    /**
     * Validate sell amount against platform rules (FR-011, QA-002)
     * Must be >= MIN_SELL_AMOUNT (100) and an exact multiple of 100 (mod 100 == 0).
     */
    public static function validateSellAmount($amount, float $minAmount = MIN_SELL_AMOUNT, int $step = SELL_DENOMINATION_STEP): array {
        if (!is_numeric($amount)) {
            return ['valid' => false, 'error' => 'Sell amount must be a valid number.'];
        }

        $num = (float)$amount;

        if ($num < $minAmount) {
            return ['valid' => false, 'error' => sprintf('Minimum sell amount is ₹%d.', (int)$minAmount)];
        }

        // Modulo 100 check (e.g. 100, 500, 3000 are valid; 99, 123, 150, 454 are rejected)
        if (fmod($num, $step) != 0.0) {
            return ['valid' => false, 'error' => sprintf('Sell amount must be an exact multiple of ₹%d (e.g. ₹100, ₹500, ₹1000).', $step)];
        }

        return ['valid' => true, 'amount' => $num];
    }

    /**
     * Calculate 9% Purchase Reward and Total Wallet Credit (FR-006, QA-001)
     */
    public static function calculatePurchaseReward(float $purchaseAmount, float $rewardRatePercent = 9.0): array {
        $rewardAmount = round($purchaseAmount * ($rewardRatePercent / 100.0), 2);
        $totalCredit = round($purchaseAmount + $rewardAmount, 2);

        return [
            'purchase_amount' => $purchaseAmount,
            'reward_rate_percent' => $rewardRatePercent,
            'reward_amount' => $rewardAmount,
            'total_credit' => $totalCredit
        ];
    }

    /**
     * Calculate 2-tier Referral Commissions (FR-019)
     * Level 1: 1.0%, Level 2: 0.5%
     */
    public static function calculateReferralCommissions(float $orderAmount, float $l1Rate = 1.0, float $l2Rate = 0.5): array {
        $l1Commission = round($orderAmount * ($l1Rate / 100.0), 2);
        $l2Commission = round($orderAmount * ($l2Rate / 100.0), 2);

        return [
            'level_1_commission' => $l1Commission,
            'level_2_commission' => $l2Commission
        ];
    }

    /**
     * Validate Indian phone number (10 digits)
     */
    public static function validatePhone(?string $phone): bool {
        if (!$phone) return false;
        $clean = preg_replace('/\D/', '', $phone);
        return (bool)preg_match('/^[6-9]\d{9}$/', $clean);
    }

    /**
     * Sanitize phone number to standard 10 digits
     */
    public static function cleanPhone(string $phone): string {
        $clean = preg_replace('/\D/', '', $phone);
        if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
            $clean = substr($clean, 2);
        }
        return $clean;
    }

    /**
     * Validate UPI Transaction Reference / UTR
     * Must be 8-30 alphanumeric characters
     */
    public static function validateUTR(?string $utr): bool {
        if (!$utr) return false;
        $trimmed = trim($utr);
        return (bool)preg_match('/^[a-zA-Z0-9_\-\.]{8,35}$/', $trimmed);
    }

    /**
     * Validate Selling TPIN (4 to 6 numeric digits)
     */
    public static function validateTPIN(?string $tpin): bool {
        if (!$tpin) return false;
        return (bool)preg_match('/^\d{4,6}$/', trim($tpin));
    }

    /**
     * Validate UPI ID format (e.g. user@okhdfcbank, 9876543210@paytm)
     */
    public static function validateUpiId(?string $upiId): bool {
        if (!$upiId) return false;
        $trimmed = trim($upiId);
        return (bool)preg_match('/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z0-9.\-_]{2,64}$/', $trimmed);
    }

    /**
     * Mask Phone number for privacy (FR-019)
     */
    public static function maskPhone(string $phone): string {
        $len = strlen($phone);
        if ($len < 6) return '****';
        return substr($phone, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($phone, -2);
    }

    /**
     * Mask Name for active transaction privacy
     */
    public static function maskName(string $name): string {
        $parts = explode(' ', trim($name));
        $maskedParts = [];
        foreach ($parts as $part) {
            $len = strlen($part);
            if ($len <= 2) {
                $maskedParts[] = $part;
            } else {
                $maskedParts[] = substr($part, 0, 1) . str_repeat('*', $len - 2) . substr($part, -1);
            }
        }
        return implode(' ', $maskedParts);
    }
}
