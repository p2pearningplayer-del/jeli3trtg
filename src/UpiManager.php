<?php
/**
 * JeliPay - UPI Management & Risk Engine (UpiManager)
 * 
 * Handles:
 * 1. Multi-UPI account management per user (FR-Item 2)
 * 2. Strict 1-to-1 UPI ID & Phone Number binding (Anti-Fraud Validation - FR-Item 3)
 * 3. Automated UPI Risk Hiding & Cooldown System (FR-Item 1)
 *    - 4-hour initial penalty on multiple buyer cancellations
 *    - 8-hour penalty if re-flagged after reactivation
 *    - Automated hiding from public Buy pool and custom buy allocation
 *    - Seller-only dashboard alert visibility with cancellation capability
 */

class UpiManager {

    /**
     * Validate strict 1-to-1 binding for UPI ID and Phone Number across entire platform
     */
    public static function validateUpiBinding(PDO $pdo, int $userId, string $upiId, string $phoneNumber): array {
        $cleanUpi = strtolower(trim($upiId));
        $cleanPhone = Validator::cleanPhone($phoneNumber);

        // Format validations
        if (!Validator::validateUpiId($cleanUpi)) {
            return [
                'valid' => false,
                'error' => 'Invalid UPI ID format. Example: username@okhdfcbank or 9876543210@paytm.'
            ];
        }

        if (!Validator::validatePhone($cleanPhone)) {
            return [
                'valid' => false,
                'error' => 'Please provide a valid 10-digit Indian mobile number.'
            ];
        }

        // 1. Strict 1-to-1 UPI Binding Check: UPI ID cannot be bound to any other user
        // Check user_upi_accounts
        $chkUpiAcc = $pdo->prepare("SELECT user_id FROM user_upi_accounts WHERE LOWER(upi_id) = ? AND user_id != ? LIMIT 1");
        $chkUpiAcc->execute([$cleanUpi, $userId]);
        $existingUpiUser = $chkUpiAcc->fetchColumn();
        if ($existingUpiUser) {
            return [
                'valid' => false,
                'error' => 'This UPI is already bound to another account.'
            ];
        }

        // Check users table
        $chkUpiUser = $pdo->prepare("SELECT id FROM users WHERE LOWER(upi_id) = ? AND id != ? LIMIT 1");
        $chkUpiUser->execute([$cleanUpi, $userId]);
        $existingUserUpi = $chkUpiUser->fetchColumn();
        if ($existingUserUpi) {
            return [
                'valid' => false,
                'error' => 'This UPI is already bound to another account.'
            ];
        }

        // 2. Strict 1-to-1 Phone Binding Check: Phone number cannot be bound to any other user
        // Check user_upi_accounts
        $chkPhoneAcc = $pdo->prepare("SELECT user_id FROM user_upi_accounts WHERE phone_number = ? AND user_id != ? LIMIT 1");
        $chkPhoneAcc->execute([$cleanPhone, $userId]);
        $existingPhoneUser = $chkPhoneAcc->fetchColumn();
        if ($existingPhoneUser) {
            return [
                'valid' => false,
                'error' => 'This Phone Number is already linked to another user account.'
            ];
        }

        // Check users table
        $chkUserPhone = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1");
        $chkUserPhone->execute([$cleanPhone, $userId]);
        $existingPhoneId = $chkUserPhone->fetchColumn();
        if ($existingPhoneId) {
            return [
                'valid' => false,
                'error' => 'This Phone Number is already linked to another user account.'
            ];
        }

        // Check if user already added this exact UPI ID to their own saved accounts
        $chkSelf = $pdo->prepare("SELECT id FROM user_upi_accounts WHERE user_id = ? AND LOWER(upi_id) = ? LIMIT 1");
        $chkSelf->execute([$userId, $cleanUpi]);
        if ($chkSelf->fetchColumn()) {
            return [
                'valid' => false,
                'error' => 'This UPI ID is already added to your account.'
            ];
        }

        return [
            'valid' => true,
            'upi_id' => $cleanUpi,
            'phone_number' => $cleanPhone
        ];
    }

    /**
     * Get all saved UPI accounts for a user, including cooldown status
     */
    public static function getUserUpiAccounts(int $userId): array {
        $pdo = Database::getConnection();

        // Check if table exists
        try {
            $stmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE user_id = ? ORDER BY is_primary DESC, id ASC");
            $stmt->execute([$userId]);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $accounts = [];
        }

        // If user has no saved accounts in user_upi_accounts yet, check users table and auto-sync
        if (empty($accounts)) {
            $uStmt = $pdo->prepare("SELECT upi_id, phone, account_holder_name, upi_risk_flag, upi_risk_cooldown_until, upi_risk_flag_count, upi_risk_reason FROM users WHERE id = ?");
            $uStmt->execute([$userId]);
            $u = $uStmt->fetch(PDO::FETCH_ASSOC);

            if ($u && !empty($u['upi_id'])) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO user_upi_accounts (
                            user_id, upi_id, phone_number, account_holder_name,
                            is_primary, is_risk_flagged, risk_flag_count,
                            risk_cooldown_until, risk_reason
                        ) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $userId,
                        strtolower(trim($u['upi_id'])),
                        Validator::cleanPhone($u['phone'] ?? ''),
                        $u['account_holder_name'] ?? null,
                        (int)($u['upi_risk_flag'] ?? 0),
                        (int)($u['upi_risk_flag_count'] ?? 0),
                        $u['upi_risk_cooldown_until'] ?? null,
                        $u['upi_risk_reason'] ?? null
                    ]);

                    $stmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE user_id = ? ORDER BY is_primary DESC, id ASC");
                    $stmt->execute([$userId]);
                    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $eSync) {}
            }
        }

        $nowTs = time();
        $result = [];

        foreach ($accounts as $acc) {
            $cooldownUntil = $acc['risk_cooldown_until'] ?? null;
            $isInCooldown = false;
            $remainingSecs = 0;
            $remainingFormatted = null;

            if (!empty($cooldownUntil)) {
                $cooldownTs = strtotime($cooldownUntil . ' UTC');
                if ($cooldownTs > $nowTs) {
                    $isInCooldown = true;
                    $remainingSecs = $cooldownTs - $nowTs;
                    $hours = floor($remainingSecs / 3600);
                    $mins = floor(($remainingSecs % 3600) / 60);
                    $remainingFormatted = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";
                }
            }

            $result[] = [
                'id' => (int)$acc['id'],
                'user_id' => (int)$acc['user_id'],
                'upi_id' => $acc['upi_id'],
                'phone_number' => $acc['phone_number'],
                'account_holder_name' => $acc['account_holder_name'],
                'is_primary' => (bool)$acc['is_primary'],
                'is_in_cooldown' => $isInCooldown,
                'risk_cooldown_until' => $cooldownUntil,
                'cooldown_remaining_seconds' => $remainingSecs,
                'cooldown_remaining_formatted' => $remainingFormatted,
                'risk_flag_count' => (int)($acc['risk_flag_count'] ?? 0),
                'risk_reason' => $acc['risk_reason'] ?? null,
                'created_at' => $acc['created_at'] ?? null
            ];
        }

        return $result;
    }

    /**
     * Add a new UPI account for a seller with strict 1-to-1 binding validation
     */
    public static function addUpiAccount(int $userId, string $upiId, string $phoneNumber, ?string $accountHolderName = null, bool $setAsPrimary = false): array {
        $pdo = Database::getConnection();

        // Step 1: Validate 1-to-1 binding
        $val = self::validateUpiBinding($pdo, $userId, $upiId, $phoneNumber);
        if (!$val['valid']) {
            return ['success' => false, 'error' => $val['error']];
        }

        $cleanUpi = $val['upi_id'];
        $cleanPhone = $val['phone_number'];

        // Step 2: Validate Account Holder Name
        $name = trim((string)$accountHolderName);
        if (empty($name)) {
            $userStmt = $pdo->prepare("SELECT account_holder_name FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $name = (string)($userStmt->fetchColumn() ?: '');
        }

        if (mb_strlen($name) < 2) {
            return ['success' => false, 'error' => 'Please enter a valid Account Holder Name (minimum 2 characters).'];
        }

        // Step 3: Check if user has existing saved UPIs
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM user_upi_accounts WHERE user_id = ?");
        $cntStmt->execute([$userId]);
        $existingCount = (int)$cntStmt->fetchColumn();

        // If this is the user's first saved UPI account, force it to be primary
        if ($existingCount === 0) {
            $setAsPrimary = true;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            if ($setAsPrimary) {
                $resetStmt = $pdo->prepare("UPDATE user_upi_accounts SET is_primary = 0 WHERE user_id = ?");
                $resetStmt->execute([$userId]);
            }

            $ins = $pdo->prepare("
                INSERT INTO user_upi_accounts (
                    user_id, upi_id, phone_number, account_holder_name,
                    is_primary, is_risk_flagged, risk_flag_count,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)
            ");
            $ins->execute([
                $userId,
                $cleanUpi,
                $cleanPhone,
                $name,
                $setAsPrimary ? 1 : 0,
                $nowUtc,
                $nowUtc
            ]);
            $newId = (int)$pdo->lastInsertId();

            if ($setAsPrimary) {
                $updUser = $pdo->prepare("UPDATE users SET upi_id = ?, account_holder_name = ? WHERE id = ?");
                $updUser->execute([$cleanUpi, $name, $userId]);
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'UPI account successfully added.',
                'account' => [
                    'id' => $newId,
                    'upi_id' => $cleanUpi,
                    'phone_number' => $cleanPhone,
                    'account_holder_name' => $name,
                    'is_primary' => $setAsPrimary,
                    'is_in_cooldown' => false
                ]
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to save UPI account: ' . $e->getMessage()];
        }
    }

    /**
     * Set a saved UPI account as primary
     */
    public static function setPrimaryUpi(int $userId, int $upiAccountId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$upiAccountId, $userId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc) {
            return ['success' => false, 'error' => 'Saved UPI account not found.'];
        }

        $pdo->beginTransaction();
        try {
            $reset = $pdo->prepare("UPDATE user_upi_accounts SET is_primary = 0 WHERE user_id = ?");
            $reset->execute([$userId]);

            $set = $pdo->prepare("UPDATE user_upi_accounts SET is_primary = 1 WHERE id = ?");
            $set->execute([$upiAccountId]);

            $cooldownInfo = self::getUpiCooldownInfo($acc['upi_id']);
            $updUser = $pdo->prepare("
                UPDATE users
                SET upi_id = ?,
                    account_holder_name = COALESCE(?, account_holder_name),
                    upi_risk_flag = ?,
                    upi_risk_cooldown_until = ?,
                    upi_risk_flag_count = ?,
                    upi_risk_reason = ?
                WHERE id = ?
            ");
            $updUser->execute([
                $acc['upi_id'],
                $acc['account_holder_name'],
                $cooldownInfo['is_in_cooldown'] ? 1 : 0,
                $cooldownInfo['cooldown_until'],
                $cooldownInfo['risk_flag_count'],
                $cooldownInfo['risk_reason'],
                $userId
            ]);

            $pdo->commit();
            return ['success' => true, 'message' => 'Primary settlement UPI updated.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to update primary UPI: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a saved UPI account
     */
    public static function deleteUpiAccount(int $userId, int $upiAccountId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$upiAccountId, $userId]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc) {
            return ['success' => false, 'error' => 'Saved UPI account not found.'];
        }

        // Safety check: ensure no active pending buyer orders on this UPI ID
        $actStmt = $pdo->prepare("
            SELECT COUNT(*) FROM child_orders
            WHERE LOWER(snapshotted_upi_id) = ?
              AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Disputed')
        ");
        $actStmt->execute([strtolower($acc['upi_id'])]);
        if ((int)$actStmt->fetchColumn() > 0) {
            return [
                'success' => false,
                'error' => 'Cannot remove this UPI ID while active buyer payments are in progress.'
            ];
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM user_upi_accounts WHERE id = ?");
            $del->execute([$upiAccountId]);

            // If deleted account was primary, nominate the next available account as primary
            if ((int)$acc['is_primary'] === 1) {
                $nextStmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                $nextStmt->execute([$userId]);
                $next = $nextStmt->fetch(PDO::FETCH_ASSOC);

                if ($next) {
                    $setPrimary = $pdo->prepare("UPDATE user_upi_accounts SET is_primary = 1 WHERE id = ?");
                    $setPrimary->execute([$next['id']]);

                    $updUser = $pdo->prepare("UPDATE users SET upi_id = ?, account_holder_name = COALESCE(?, account_holder_name) WHERE id = ?");
                    $updUser->execute([$next['upi_id'], $next['account_holder_name'], $userId]);
                } else {
                    $clearUser = $pdo->prepare("UPDATE users SET upi_id = NULL WHERE id = ?");
                    $clearUser->execute([$userId]);
                }
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'UPI account removed successfully.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to remove UPI account: ' . $e->getMessage()];
        }
    }

    /**
     * Check if a UPI ID is currently restricted by risk cooldown
     */
    public static function isUpiInCooldown(?string $upiId): bool {
        if (empty($upiId)) {
            return false;
        }

        $cleanUpi = strtolower(trim($upiId));
        $nowTs = time();
        $pdo = Database::getConnection();

        // Check user_upi_accounts
        try {
            $stmt = $pdo->prepare("
                SELECT risk_cooldown_until
                FROM user_upi_accounts
                WHERE LOWER(upi_id) = ? AND risk_cooldown_until IS NOT NULL
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$cleanUpi]);
            $cooldown = $stmt->fetchColumn();

            if ($cooldown) {
                $cooldownTs = strtotime($cooldown . ' UTC');
                if ($cooldownTs > $nowTs) {
                    return true;
                }
            }
        } catch (Exception $e) {}

        // Check users table
        try {
            $uStmt = $pdo->prepare("
                SELECT upi_risk_cooldown_until
                FROM users
                WHERE LOWER(upi_id) = ? AND upi_risk_cooldown_until IS NOT NULL
                LIMIT 1
            ");
            $uStmt->execute([$cleanUpi]);
            $uCooldown = $uStmt->fetchColumn();

            if ($uCooldown) {
                $uCooldownTs = strtotime($uCooldown . ' UTC');
                if ($uCooldownTs > $nowTs) {
                    return true;
                }
            }
        } catch (Exception $e) {}

        return false;
    }

    /**
     * Get detailed cooldown info for a UPI ID
     */
    public static function getUpiCooldownInfo(?string $upiId): array {
        if (empty($upiId)) {
            return [
                'is_in_cooldown' => false,
                'cooldown_until' => null,
                'cooldown_remaining_seconds' => 0,
                'cooldown_remaining_formatted' => null,
                'risk_reason' => null,
                'risk_flag_count' => 0
            ];
        }

        $cleanUpi = strtolower(trim($upiId));
        $nowTs = time();
        $pdo = Database::getConnection();

        $cooldownUntil = null;
        $reason = null;
        $flagCount = 0;

        try {
            $stmt = $pdo->prepare("
                SELECT risk_cooldown_until, risk_reason, risk_flag_count
                FROM user_upi_accounts
                WHERE LOWER(upi_id) = ? AND risk_cooldown_until IS NOT NULL
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$cleanUpi]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acc) {
                $cooldownUntil = $acc['risk_cooldown_until'];
                $reason = $acc['risk_reason'];
                $flagCount = (int)($acc['risk_flag_count'] ?? 0);
            }
        } catch (Exception $e) {}

        if (!$cooldownUntil) {
            try {
                $uStmt = $pdo->prepare("
                    SELECT upi_risk_cooldown_until, upi_risk_reason, upi_risk_flag_count
                    FROM users
                    WHERE LOWER(upi_id) = ? AND upi_risk_cooldown_until IS NOT NULL
                    LIMIT 1
                ");
                $uStmt->execute([$cleanUpi]);
                $u = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($u) {
                    $cooldownUntil = $u['upi_risk_cooldown_until'];
                    $reason = $u['upi_risk_reason'];
                    $flagCount = (int)($u['upi_risk_flag_count'] ?? 0);
                }
            } catch (Exception $e) {}
        }

        $isInCooldown = false;
        $remainingSecs = 0;
        $remainingFormatted = null;

        if (!empty($cooldownUntil)) {
            $cooldownTs = strtotime($cooldownUntil . ' UTC');
            if ($cooldownTs > $nowTs) {
                $isInCooldown = true;
                $remainingSecs = $cooldownTs - $nowTs;
                $hours = floor($remainingSecs / 3600);
                $mins = floor(($remainingSecs % 3600) / 60);
                $remainingFormatted = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";
            }
        }

        return [
            'is_in_cooldown' => $isInCooldown,
            'cooldown_until' => $cooldownUntil,
            'cooldown_remaining_seconds' => $remainingSecs,
            'cooldown_remaining_formatted' => $remainingFormatted,
            'risk_reason' => $reason,
            'risk_flag_count' => $flagCount
        ];
    }

    /**
     * Automated UPI Risk Flagging & Cooldown Trigger (FR-Item 1)
     * Penalty logic:
     * - Initial flag (flag_count == 0): 4 hours cooldown
     * - Re-flagged after reactivation (flag_count >= 1): 8 hours cooldown
     */
    public static function flagUpiForRisk(PDO $pdo, string $upiId, int $sellerId, string $reason = 'Multiple buyer cancellations reported on this UPI ID'): array {
        $cleanUpi = strtolower(trim($upiId));
        if (empty($cleanUpi)) {
            return ['flagged' => false];
        }

        // If currently in active cooldown, do not prematurely stack/multiply
        if (self::isUpiInCooldown($cleanUpi)) {
            return [
                'flagged' => true,
                'already_active' => true,
                'info' => self::getUpiCooldownInfo($cleanUpi)
            ];
        }

        // Determine existing flag count
        $prevCount = 0;
        try {
            $cntStmt = $pdo->prepare("SELECT MAX(risk_flag_count) FROM user_upi_accounts WHERE LOWER(upi_id) = ?");
            $cntStmt->execute([$cleanUpi]);
            $prevCount = (int)$cntStmt->fetchColumn();
        } catch (Exception $e) {}

        if ($prevCount === 0) {
            try {
                $uCntStmt = $pdo->prepare("SELECT upi_risk_flag_count FROM users WHERE LOWER(upi_id) = ? OR id = ?");
                $uCntStmt->execute([$cleanUpi, $sellerId]);
                $prevCount = (int)$uCntStmt->fetchColumn();
            } catch (Exception $e) {}
        }

        // Cooldown calculation:
        // 1st flag: 4 hours
        // 2nd+ flag: 8 hours
        $penaltyHours = ($prevCount === 0) ? 4 : 8;
        $newCount = $prevCount + 1;
        $nowUtc = gmdate('Y-m-d H:i:s');
        $cooldownUntil = gmdate('Y-m-d H:i:s', time() + ($penaltyHours * 3600));

        // Update user_upi_accounts
        try {
            $updAcc = $pdo->prepare("
                UPDATE user_upi_accounts
                SET is_risk_flagged = 1,
                    risk_flag_count = ?,
                    risk_cooldown_until = ?,
                    risk_reason = ?,
                    updated_at = ?
                WHERE LOWER(upi_id) = ?
            ");
            $updAcc->execute([$newCount, $cooldownUntil, $reason, $nowUtc, $cleanUpi]);
        } catch (Exception $eAcc) {}

        // Update users table where active upi_id matches the flagged UPI
        try {
            $updUser = $pdo->prepare("
                UPDATE users
                SET upi_risk_flag = 1,
                    upi_risk_flag_count = ?,
                    upi_risk_cooldown_until = ?,
                    upi_risk_reason = ?,
                    upi_risk_flagged_at = ?
                WHERE LOWER(upi_id) = ?
            ");
            $updUser->execute([$newCount, $cooldownUntil, $reason, $nowUtc, $cleanUpi]);
        } catch (Exception $eUser) {}

        return [
            'flagged' => true,
            'penalty_hours' => $penaltyHours,
            'cooldown_until' => $cooldownUntil,
            'flag_count' => $newCount,
            'reason' => $reason
        ];
    }

    /**
     * Admin Manual Override: Clear UPI risk flag & cooldown timer ("Remove Restriction" / Make Live)
     * Immediately restores the UPI ID and pushes all active 'Available' sell orders tied to it
     * back to the public Buy section.
     */
    public static function clearUpiRestriction(
        PDO $pdo,
        string $upiId,
        ?int $userId = null,
        bool $resetStrikeCount = false
    ): array {
        $cleanUpi = strtolower(trim($upiId));
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Resolve owner user_id if not provided
        if (empty($userId) && $cleanUpi !== '') {
            try {
                $stmtOwner = $pdo->prepare("SELECT user_id FROM user_upi_accounts WHERE LOWER(upi_id) = ? LIMIT 1");
                $stmtOwner->execute([$cleanUpi]);
                $userId = (int)$stmtOwner->fetchColumn();
            } catch (Exception $e) {}

            if (empty($userId)) {
                try {
                    $stmtUser = $pdo->prepare("SELECT id FROM users WHERE LOWER(upi_id) = ? LIMIT 1");
                    $stmtUser->execute([$cleanUpi]);
                    $userId = (int)$stmtUser->fetchColumn();
                } catch (Exception $e) {}
            }
        }

        // 1. Clear risk flags and cooldown on user_upi_accounts
        if ($cleanUpi !== '') {
            try {
                if ($resetStrikeCount) {
                    $updAcc = $pdo->prepare("
                        UPDATE user_upi_accounts
                        SET is_risk_flagged = 0,
                            risk_cooldown_until = NULL,
                            risk_reason = NULL,
                            risk_flag_count = 0,
                            updated_at = ?
                        WHERE LOWER(upi_id) = ?
                    ");
                    $updAcc->execute([$nowUtc, $cleanUpi]);
                } else {
                    $updAcc = $pdo->prepare("
                        UPDATE user_upi_accounts
                        SET is_risk_flagged = 0,
                            risk_cooldown_until = NULL,
                            risk_reason = NULL,
                            updated_at = ?
                        WHERE LOWER(upi_id) = ?
                    ");
                    $updAcc->execute([$nowUtc, $cleanUpi]);
                }
            } catch (Exception $eAcc) {}

            // 2. Clear risk flags and cooldown on users table where upi_id matches
            try {
                if ($resetStrikeCount) {
                    $updUserUpi = $pdo->prepare("
                        UPDATE users
                        SET upi_risk_flag = 0,
                            upi_risk_cooldown_until = NULL,
                            upi_risk_reason = NULL,
                            upi_risk_flag_count = 0
                        WHERE LOWER(upi_id) = ?
                    ");
                    $updUserUpi->execute([$cleanUpi]);
                } else {
                    $updUserUpi = $pdo->prepare("
                        UPDATE users
                        SET upi_risk_flag = 0,
                            upi_risk_cooldown_until = NULL,
                            upi_risk_reason = NULL
                        WHERE LOWER(upi_id) = ?
                    ");
                    $updUserUpi->execute([$cleanUpi]);
                }
            } catch (Exception $eUserUpi) {}

            // 3. Clear historical buyer cancellations for this UPI so it doesn't immediately re-trigger on 1 cancel
            try {
                $delCancels = $pdo->prepare("DELETE FROM order_cancellations WHERE LOWER(seller_upi_id) = ?");
                $delCancels->execute([$cleanUpi]);
            } catch (Exception $eCanc) {}
        }

        // 4. If userId is known, also clear user-level risk / sell restriction if no other UPIs remain in cooldown
        if (!empty($userId) && $userId > 0) {
            try {
                $otherActiveStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM user_upi_accounts
                    WHERE user_id = ? AND is_risk_flagged = 1 AND risk_cooldown_until > ?
                ");
                $otherActiveStmt->execute([$userId, $nowUtc]);
                $remainingFlagged = (int)$otherActiveStmt->fetchColumn();

                if ($remainingFlagged === 0) {
                    $updUser = $pdo->prepare("
                        UPDATE users
                        SET upi_risk_flag = 0,
                            upi_risk_cooldown_until = NULL,
                            upi_risk_reason = NULL,
                            sell_restricted = 0,
                            sell_restriction_reason = NULL
                        WHERE id = ?
                    ");
                    $updUser->execute([$userId]);
                }
            } catch (Exception $eUserClean) {}
        }

        // 5. Count active sell orders (child_orders with status = 'Available') now live in the public Buy section
        $liveCount = 0;
        $liveAmount = 0.0;
        if ($cleanUpi !== '') {
            try {
                $liveStmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amt
                    FROM child_orders
                    WHERE status = 'Available' AND LOWER(snapshotted_upi_id) = ?
                ");
                $liveStmt->execute([$cleanUpi]);
                $liveRow = $liveStmt->fetch(PDO::FETCH_ASSOC);
                $liveCount = (int)($liveRow['cnt'] ?? 0);
                $liveAmount = round((float)($liveRow['total_amt'] ?? 0.0), 2);
            } catch (Exception $eLive) {}
        } elseif (!empty($userId)) {
            try {
                $liveStmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total_amt
                    FROM child_orders
                    WHERE status = 'Available' AND seller_id = ?
                ");
                $liveStmt->execute([$userId]);
                $liveRow = $liveStmt->fetch(PDO::FETCH_ASSOC);
                $liveCount = (int)($liveRow['cnt'] ?? 0);
                $liveAmount = round((float)($liveRow['total_amt'] ?? 0.0), 2);
            } catch (Exception $eLive) {}
        }

        return [
            'success' => true,
            'upi_id' => $cleanUpi,
            'user_id' => $userId,
            'live_orders_count' => $liveCount,
            'live_orders_amount' => $liveAmount
        ];
    }
}
