<?php
/**
 * Explainable Risk Scoring Engine
 * Evaluates behavioral signals, UTR anomalies, image indicators, and historical account patterns.
 * Computes an explainable risk score (0-100) with detailed reason codes.
 */

declare(strict_types=1);

namespace Fraud;

use PDO;

class RiskScoringEngine {

    /**
     * Compute risk score and reason codes for a payment proof submission.
     *
     * @param PDO $pdo
     * @param array $order
     * @param array $utrData
     * @param array $utrDupCheck
     * @param array $imageData
     * @param array $imageDupCheck
     * @return array
     */
    public static function evaluateSubmission(
        PDO $pdo,
        array $order,
        array $utrData,
        array $utrDupCheck,
        array $imageData,
        array $imageDupCheck
    ): array {
        $score = 0;
        $reasons = [];
        $explanations = [];
        $hardRuleViolated = false;

        $buyerId = (int)$order['buyer_id'];
        $claimedAmount = (float)$order['amount'];

        // 1. HARD RULE: Duplicate UTR Detection
        if (!empty($utrDupCheck['is_duplicate'])) {
            $score += 100;
            $hardRuleViolated = true;
            $reasons[] = $utrDupCheck['reason_code'];
            $explanations[] = $utrDupCheck['description'];
        }

        // 2. HARD RULE: Exact Duplicate Image Hash (SHA-256)
        if (!empty($imageDupCheck['is_duplicate']) && $imageDupCheck['match_type'] === 'EXACT_SHA256') {
            $score += 100;
            $hardRuleViolated = true;
            $reasons[] = 'DUPLICATE_IMAGE_HASH';
            $explanations[] = $imageDupCheck['description'];
        }

        // 3. DUMMY / TEST UTR Pattern Detection
        if (!empty($utrData['is_dummy'])) {
            $score += 75;
            $hardRuleViolated = true;
            $reasons[] = 'DUMMY_UTR_PATTERN';
            $explanations[] = "UTR matches obvious fake or sequential test pattern: {$utrData['dummy_reason']}";
        }

        // 4. INVALID UTR Format
        if (empty($utrData['is_valid'])) {
            $score += 50;
            $reasons[] = 'INVALID_UTR_FORMAT';
            $explanations[] = $utrData['error'] ?: 'UTR format is invalid';
        }

        // 5. Visually Similar Screenshot (pHash Hamming distance <= 5)
        if (!empty($imageDupCheck['is_duplicate']) && $imageDupCheck['match_type'] === 'PERCEPTUAL_PHASH') {
            $score += 45;
            $reasons[] = 'PERCEPTUAL_SIMILAR_IMAGE';
            $explanations[] = $imageDupCheck['description'];
        }

        // 6. OCR Discrepancies
        if (!empty($imageData['ocr']['success'])) {
            $comparison = $imageData['comparison'] ?? [];

            // Amount Mismatch (e.g. ₹500 paid for ₹5,000 order)
            if ($comparison['amount_matched'] === false && $comparison['extracted_amount'] !== null) {
                $score += 45;
                $reasons[] = 'AMOUNT_MISMATCH_OCR';
                $explanations[] = sprintf(
                    'OCR extracted ₹%s which does not match claimed order amount ₹%s (difference ₹%s).',
                    number_format((float)$comparison['extracted_amount'], 2),
                    number_format($claimedAmount, 2),
                    number_format((float)$comparison['amount_difference'], 2)
                );
            }

            // UTR Reference Mismatch
            if ($comparison['utr_matched'] === false && !empty($comparison['extracted_utr'])) {
                $score += 35;
                $reasons[] = 'UTR_MISMATCH_OCR';
                $explanations[] = sprintf(
                    'OCR extracted UTR [%s] which differs from submitted UTR [%s].',
                    $comparison['extracted_utr'],
                    $comparison['claimed_utr']
                );
            }

            // Explicit Transaction Failure in Screenshot
            if ($comparison['status_matched'] === false && in_array($comparison['extracted_status'], ['FAILED', 'DECLINED'], true)) {
                $score += 65;
                $reasons[] = 'PAYMENT_FAILED_SCREENSHOT';
                $explanations[] = "Screenshot explicitly indicates a '{$comparison['extracted_status']}' banking transaction status.";
            }
        }

        // 7. Image Editing Artifacts (Photoshop, Canva, PicsArt)
        if (!empty($imageData['tampering_hints'])) {
            $score += 25;
            $reasons[] = 'IMAGE_EDITING_SIGNATURE';
            $explanations[] = 'Image metadata contains signatures of graphic editing software: ' . implode(', ', $imageData['tampering_hints']);
        }

        // 8. Screenshot Aspect Ratio Anomaly (Horizontal desktop/banner)
        if (!empty($imageData['aspect_ratio_anomaly'])) {
            $score += 15;
            $reasons[] = 'ASPECT_RATIO_ANOMALY';
            $explanations[] = sprintf('Non-mobile aspect ratio (%.2f:1) detected. Legitimate UPI screenshots are typically portrait.', $imageData['aspect_ratio']);
        }

        // 9. Historical User Behavioral Signals
        $userSignals = self::getUserBehavioralSignals($pdo, $buyerId);

        // Prior rejected fraud assessments
        if ($userSignals['prior_rejected_assessments'] > 0) {
            $score += min(40, $userSignals['prior_rejected_assessments'] * 20);
            $reasons[] = 'USER_PRIOR_FRAUD_HISTORY';
            $explanations[] = "Account has {$userSignals['prior_rejected_assessments']} prior rejected fraud assessment(s).";
        }

        // Excessive cancellation rate (> 60% on 3+ orders)
        if ($userSignals['total_orders'] >= 3 && $userSignals['cancellation_rate'] >= 60.0) {
            $score += 20;
            $reasons[] = 'USER_HIGH_CANCELLATION_RATE';
            $explanations[] = sprintf('High historical order cancellation/expiry rate (%.1f%% of %d orders).', $userSignals['cancellation_rate'], $userSignals['total_orders']);
        }

        // Rapid retry spamming (> 3 attempts in last 10 minutes)
        if ($userSignals['recent_attempts_10m'] >= 3) {
            $score += 20;
            $reasons[] = 'RAPID_SUBMISSION_RETRY';
            $explanations[] = "Account submitted {$userSignals['recent_attempts_10m']} payment attempts within the last 10 minutes.";
        }

        // Normalize total score
        $finalScore = max(0, min(100, $score));

        // Determine categorical risk level
        $riskLevel = 'low';
        if ($finalScore >= 75) {
            $riskLevel = 'critical';
        } elseif ($finalScore >= 50) {
            $riskLevel = 'high';
        } elseif ($finalScore >= 25) {
            $riskLevel = 'medium';
        }

        return [
            'score' => $finalScore,
            'risk_level' => $riskLevel,
            'reasons' => $reasons,
            'explanations' => $explanations,
            'hard_rule_violated' => $hardRuleViolated,
            'user_signals' => $userSignals
        ];
    }

    /**
     * Fetch user behavioral risk signals from database.
     */
    public static function getUserBehavioralSignals(PDO $pdo, int $userId): array {
        if ($userId <= 0) {
            return [
                'total_orders' => 0,
                'completed_orders' => 0,
                'cancelled_orders' => 0,
                'cancellation_rate' => 0.0,
                'prior_rejected_assessments' => 0,
                'recent_attempts_10m' => 0
            ];
        }

        // 1. Order stats
        $stmtOrders = $pdo->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status IN ('Completed', 'completed') THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status IN ('Cancelled', 'Expired', 'cancelled', 'expired') THEN 1 ELSE 0 END) as cancelled_orders
            FROM child_orders
            WHERE buyer_id = ?
        ");
        $stmtOrders->execute([$userId]);
        $ordRow = $stmtOrders->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalOrders = (int)($ordRow['total_orders'] ?? 0);
        $completedOrders = (int)($ordRow['completed_orders'] ?? 0);
        $cancelledOrders = (int)($ordRow['cancelled_orders'] ?? 0);
        $cancellationRate = ($totalOrders > 0) ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0.0;

        // 2. Prior rejected fraud assessments
        $stmtFraud = $pdo->prepare("
            SELECT COUNT(*) FROM fraud_assessments
            WHERE buyer_id = ? AND decision = 'REJECTED'
        ");
        $stmtFraud->execute([$userId]);
        $priorRejects = (int)$stmtFraud->fetchColumn();

        // 3. Recent submission attempts (last 10 minutes)
        $tenMinsAgoUtc = gmdate('Y-m-d H:i:s', time() - 600);
        $stmtRecent = $pdo->prepare("
            SELECT COUNT(*) FROM payment_fingerprints
            WHERE buyer_id = ? AND created_at >= ?
        ");
        $stmtRecent->execute([$userId, $tenMinsAgoUtc]);
        $recentAttempts = (int)$stmtRecent->fetchColumn();

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'cancellation_rate' => $cancellationRate,
            'prior_rejected_assessments' => $priorRejects,
            'recent_attempts_10m' => $recentAttempts
        ];
    }
}
