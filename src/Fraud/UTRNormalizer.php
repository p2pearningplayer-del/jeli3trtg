<?php
/**
 * UTR Normalizer & Duplicate Detection Engine
 * Normalizes Indian UPI references, IMPS/NEFT numbers, detects dummy sequences,
 * and securely computes fingerprints for cross-order collision checks.
 */

declare(strict_types=1);

namespace Fraud;

use PDO;

class UTRNormalizer {

    /**
     * Common prefixes prepended by banking SMS, UPI apps, and user copy-paste
     */
    private const PREFIX_PATTERNS = [
        '/^(PAYTM|GPAY|GOOGLEPAY|PHONEPE|BHIM|AMAZONPAY)[\/:\s\-_]*(REF|TXN|UTR|TRANS|NO|ID)?[\/:\s\-_]*/i',
        '/^UTR[\/:\s\-_]*(NO|NUM|NUMBER)?[\/:\s\-_]*/i',
        '/^UPI[\/:\s\-_]*(REF|TXN|NO|NUM|NUMBER)?[\/:\s\-_]*/i',
        '/^REF[\/:\s\-_]*(NO|NUM|NUMBER)?[\/:\s\-_]*/i',
        '/^TXN[\/:\s\-_]*(ID|NO|NUM|NUMBER)?[\/:\s\-_]*/i',
        '/^TRANS[\/:\s\-_]*(ID|NO|NUM|NUMBER)?[\/:\s\-_]*/i',
        '/^IMPS[\/:\s\-_]+/i',
        '/^RRN[\/:\s\-_]+/i',
        '/^ORDER[\/:\s\-_]*(ID)?[\/:\s\-_]*/i',
        '/^(NO|NUM|NUMBER)[\/:\s\-_]+/i'
    ];

    /**
     * Common suffixes appended by payment app share sheets
     */
    private const SUFFIX_PATTERNS = [
        '/[\/:\s\-_]+(REF|TXN|ID|NO|UTR|UPI)$/i',
        '/[\/:\s\-_]+(SUCCESS|COMPLETED|PAID)$/i'
    ];

    /**
     * Known test and bogus sequence patterns
     */
    private const DUMMY_SEQUENCES = [
        '123456789012',
        '000000000000',
        '111111111111',
        '222222222222',
        '333333333333',
        '444444444444',
        '555555555555',
        '666666666666',
        '777777777777',
        '888888888888',
        '999999999999',
        '123456781234',
        '987654321098',
        '012345678901'
    ];

    /**
     * Normalize a user-submitted UTR / reference string.
     *
     * @param string $utr
     * @return array
     */
    public static function normalize(string $utr): array {
        $raw = trim($utr);
        $clean = $raw;

        // 1. Strip common prefixes iteratively
        $changed = true;
        $maxLoops = 5;
        while ($changed && $maxLoops-- > 0) {
            $changed = false;
            foreach (self::PREFIX_PATTERNS as $pattern) {
                if (preg_match($pattern, $clean)) {
                    $clean = (string)preg_replace($pattern, '', $clean);
                    $changed = true;
                }
            }
        }

        // 1b. Strip common suffixes iteratively
        foreach (self::SUFFIX_PATTERNS as $pattern) {
            $clean = (string)preg_replace($pattern, '', $clean);
        }

        // 2. Remove all spaces, dashes, dots, slashes, and special characters
        $clean = (string)preg_replace('/[^A-Za-z0-9]/', '', $clean);
        $normalized = strtoupper($clean);

        // 2b. If a 12-digit numeric RRN is followed by known suffixes that lacked delimiters
        if (strlen($normalized) > 12 && preg_match('/^(\d{12})(?:REF|TXN|ID|NO|UTR|UPI)$/i', $normalized, $m)) {
            $normalized = $m[1];
        }

        // 3. Validation checks
        $length = strlen($normalized);
        $isValid = ($length >= 8 && $length <= 35 && ctype_alnum($normalized));

        // 4. Dummy pattern detection
        $isDummy = false;
        $dummyReason = null;
        if (in_array($normalized, self::DUMMY_SEQUENCES, true)) {
            $isDummy = true;
            $dummyReason = 'Matched known sequence pattern';
        } elseif (preg_match('/^(.)\1{7,}$/', $normalized)) {
            // 8 or more repeated identical characters e.g. 00000000
            $isDummy = true;
            $dummyReason = 'Repetitive identical character sequence';
        } elseif (preg_match('/^(01234567|12345678|23456789|98765432)/', $normalized)) {
            $isDummy = true;
            $dummyReason = 'Ascending or descending sequential pattern';
        }

        // 5. Provider heuristic detection
        $providerHint = 'GENERIC';
        if (preg_match('/^\d{12}$/', $normalized)) {
            $providerHint = 'UPI_RRN_12DIGIT';
        } elseif (str_starts_with($normalized, 'CICB') || str_starts_with($normalized, 'GOOG')) {
            $providerHint = 'GOOGLE_PAY';
        } elseif (str_starts_with($normalized, 'T') && strlen($normalized) >= 18) {
            $providerHint = 'PHONEPE';
        } elseif (str_starts_with($normalized, 'PTM') || str_starts_with($normalized, 'PYTM')) {
            $providerHint = 'PAYTM';
        } elseif (preg_match('/^[A-Z]{4}\d{6,14}$/', $normalized)) {
            $providerHint = 'BANK_IMPS';
        }

        // 6. Cryptographic fingerprint (SHA-256)
        $fingerprint = hash('sha256', $normalized);

        return [
            'raw' => $raw,
            'normalized' => $normalized,
            'fingerprint' => $fingerprint,
            'length' => $length,
            'is_valid' => $isValid,
            'is_dummy' => $isDummy,
            'dummy_reason' => $dummyReason,
            'provider_hint' => $providerHint,
            'error' => $isValid ? null : 'UTR must be between 8 and 35 alphanumeric characters after normalization.'
        ];
    }

    /**
     * Check database for duplicate submissions of this UTR.
     * Enforces strict cross-order and cross-account concurrency detection.
     *
     * @param PDO $pdo
     * @param string $utrNormalized
     * @param string $utrFingerprint
     * @param int $currentOrderId
     * @param int $currentBuyerId
     * @return array
     */
    public static function checkDuplicate(PDO $pdo, string $utrNormalized, string $utrFingerprint, int $currentOrderId, int $currentBuyerId): array {
        // 1. Check in payment_proofs table
        $stmtProof = $pdo->prepare("
            SELECT p.id, p.child_order_id, p.buyer_id, p.created_at, c.status as order_status
            FROM payment_proofs p
            JOIN child_orders c ON p.child_order_id = c.id
            WHERE (p.utr_number = ? OR p.utr_number = ?) AND p.child_order_id != ?
            LIMIT 1
        ");
        $stmtProof->execute([$utrNormalized, $utrNormalized, $currentOrderId]);
        $existingProof = $stmtProof->fetch(PDO::FETCH_ASSOC);

        if ($existingProof) {
            $conflictBuyerId = (int)$existingProof['buyer_id'];
            $isCrossUser = ($conflictBuyerId !== $currentBuyerId);
            return [
                'is_duplicate' => true,
                'source' => 'payment_proofs',
                'conflict_order_id' => (int)$existingProof['child_order_id'],
                'conflict_buyer_id' => $conflictBuyerId,
                'conflict_order_status' => $existingProof['order_status'],
                'is_cross_user' => $isCrossUser,
                'reason_code' => $isCrossUser ? 'DUPLICATE_UTR_CROSS_USER' : 'DUPLICATE_UTR_REUSE',
                'description' => sprintf(
                    'UTR %s was already submitted for Order #%04d by %s (Status: %s).',
                    $utrNormalized,
                    (int)$existingProof['child_order_id'],
                    $isCrossUser ? "User #{$conflictBuyerId}" : "your account",
                    $existingProof['order_status']
                )
            ];
        }

        // 2. Check in payment_fingerprints table
        $stmtFp = $pdo->prepare("
            SELECT fp.id, fp.child_order_id, fp.buyer_id, fp.created_at
            FROM payment_fingerprints fp
            WHERE fp.utr_hash = ? AND fp.child_order_id != ?
            LIMIT 1
        ");
        $stmtFp->execute([$utrFingerprint, $currentOrderId]);
        $existingFp = $stmtFp->fetch(PDO::FETCH_ASSOC);

        if ($existingFp) {
            $conflictBuyerId = (int)$existingFp['buyer_id'];
            $isCrossUser = ($conflictBuyerId !== $currentBuyerId);
            return [
                'is_duplicate' => true,
                'source' => 'payment_fingerprints',
                'conflict_order_id' => (int)$existingFp['child_order_id'],
                'conflict_buyer_id' => $conflictBuyerId,
                'conflict_order_status' => 'Logged Fingerprint',
                'is_cross_user' => $isCrossUser,
                'reason_code' => $isCrossUser ? 'DUPLICATE_UTR_CROSS_USER' : 'DUPLICATE_UTR_REUSE',
                'description' => sprintf(
                    'UTR fingerprint matches previously recorded submission on Order #%04d.',
                    (int)$existingFp['child_order_id']
                )
            ];
        }

        return [
            'is_duplicate' => false,
            'source' => null,
            'conflict_order_id' => null,
            'conflict_buyer_id' => null,
            'is_cross_user' => false,
            'reason_code' => null,
            'description' => null
        ];
    }
}
