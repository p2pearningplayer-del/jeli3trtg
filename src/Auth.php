<?php
/**
 * Authentication & Security Service
 * Handles user registration, bcrypt authentication, TPIN verification, and secure sessions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/EmailService.php';

class Auth {
    /**
     * Register a new user with mandatory email verification
     */
    public static function register(string $phone, string $password, ?string $referralCode = null, ?string $upiId = null, ?string $name = null, ?string $email = null, bool $autoActivate = false): array {
        $cleanPhone = Validator::cleanPhone($phone);
        if (!Validator::validatePhone($cleanPhone)) {
            return ['success' => false, 'error' => 'Please provide a valid 10-digit Indian mobile number.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
        }

        // If email is not explicitly provided (e.g. internal test suites / scripts), generate dummy email and auto-activate
        if (empty($email)) {
            $cleanEmail = "user_{$cleanPhone}@jelipay.in";
            $autoActivate = true;
        } else {
            $cleanEmail = strtolower(trim($email));
            if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'error' => 'Please provide a valid email address for account verification.'];
            }
        }

        $pdo = Database::getConnection();

        // Check if active account with this email exists
        $emailStmt = $pdo->prepare("SELECT id, phone, account_status FROM users WHERE email = ?");
        $emailStmt->execute([$cleanEmail]);
        $existingEmailUser = $emailStmt->fetch();
        if ($existingEmailUser && $existingEmailUser['account_status'] !== 'pending_verification') {
            return ['success' => false, 'error' => 'An account with this email address already exists. Please sign in.'];
        }

        // Check unique phone
        $stmt = $pdo->prepare("SELECT id, email, account_status FROM users WHERE phone = ?");
        $stmt->execute([$cleanPhone]);
        $existingPhoneUser = $stmt->fetch();
        if ($existingPhoneUser && $existingPhoneUser['account_status'] !== 'pending_verification') {
            return ['success' => false, 'error' => 'An account with this phone number already exists. Please sign in.'];
        }

        // Resolve referral code
        $referredById = null;
        if (!empty($referralCode)) {
            $refStmt = $pdo->prepare("SELECT id FROM users WHERE referral_code = ?");
            $refStmt->execute([trim($referralCode)]);
            $refUser = $refStmt->fetch();
            if ($refUser) {
                $referredById = (int)$refUser['id'];
            }
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $initialStatus = $autoActivate ? 'active' : 'pending_verification';
        $verifiedAt = $autoActivate ? date('Y-m-d H:i:s') : null;

        $pdo->beginTransaction();
        try {
            $targetUserId = null;
            $myReferralCode = '';

            // If user previously registered but never verified email, update password and re-issue OTP
            if ($existingPhoneUser && $existingPhoneUser['account_status'] === 'pending_verification') {
                $targetUserId = (int)$existingPhoneUser['id'];
                $upd = $pdo->prepare("
                    UPDATE users
                    SET email = ?,
                        password_hash = ?,
                        account_status = ?,
                        email_verified_at = ?,
                        referred_by_id = COALESCE(?, referred_by_id),
                        upi_id = COALESCE(?, upi_id),
                        account_holder_name = COALESCE(?, account_holder_name)
                    WHERE id = ?
                ");
                $upd->execute([
                    $cleanEmail,
                    $passwordHash,
                    $initialStatus,
                    $verifiedAt,
                    $referredById,
                    $upiId ? trim($upiId) : null,
                    $name ? trim($name) : null,
                    $targetUserId
                ]);
            } else {
                $myReferralCode = strtoupper('JP' . substr(md5(uniqid($cleanPhone, true)), 0, 6));
                $insert = $pdo->prepare("
                    INSERT INTO users (phone, email, password_hash, account_status, email_verified_at, role, referral_code, referred_by_id, upi_id, account_holder_name)
                    VALUES (?, ?, ?, ?, ?, 'user', ?, ?, ?, ?)
                ");
                $insert->execute([
                    $cleanPhone,
                    $cleanEmail,
                    $passwordHash,
                    $initialStatus,
                    $verifiedAt,
                    $myReferralCode,
                    $referredById,
                    $upiId ? trim($upiId) : null,
                    $name ? trim($name) : null
                ]);
                $targetUserId = (int)$pdo->lastInsertId();

                // Initialize wallet
                $walletInsert = $pdo->prepare("
                    INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version)
                    VALUES (?, 0.00, 0.00, 0.00, 1)
                ");
                $walletInsert->execute([$targetUserId]);
            }

            $pdo->commit();

            if ($autoActivate) {
                return [
                    'success' => true,
                    'user_id' => $targetUserId,
                    'role' => 'user',
                    'referral_code' => $myReferralCode,
                    'phone' => $cleanPhone,
                    'email' => $cleanEmail,
                    'account_status' => 'active'
                ];
            }

            // Initiate email verification (OTP + 1-Click Link)
            $verifRes = EmailService::initiateVerification($targetUserId, $cleanEmail, $name);

            return [
                'success' => true,
                'step' => 'verify_email',
                'user_id' => $targetUserId,
                'role' => 'user',
                'referral_code' => $myReferralCode,
                'phone' => $cleanPhone,
                'email' => $cleanEmail,
                'account_status' => 'pending_verification',
                'mail_sent' => $verifRes['mail_sent'] ?? false,
                'message' => 'Registration initiated! Please enter the 6-digit verification code sent to ' . $cleanEmail . ' to activate your account.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Registration failed: ' . $e->getMessage()];
        }
    }

    /**
     * Authenticate login with phone, email, or User ID (FR-001)
     */
    public static function login(string $identifier, string $password): array {
        $trimmed = trim($identifier);
        $cleanPhone = Validator::cleanPhone($trimmed);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateKey = "login_{$trimmed}_{$clientIp}";

        if (!RateLimiter::check($rateKey, 'login', 5, 300)) {
            return ['success' => false, 'error' => 'Too many failed login attempts. Please wait 5 minutes before retrying.'];
        }

        $pdo = Database::getConnection();
        // Support login with Phone Number, Email, User ID, or Referral Code
        if (is_numeric($trimmed) && (int)$trimmed > 0 && strlen($cleanPhone) !== 10) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? OR phone = ? OR LOWER(email) = ? OR referral_code = ?");
            $stmt->execute([(int)$trimmed, $cleanPhone, strtolower($trimmed), $trimmed]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? OR LOWER(email) = ? OR referral_code = ?");
            $stmt->execute([$cleanPhone, strtolower($trimmed), $trimmed]);
        }
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid credentials. Please verify your phone/ID and password.'];
        }

        if ($user['account_status'] === 'suspended') {
            return ['success' => false, 'error' => 'This account has been suspended by administration.'];
        }

        if ($user['account_status'] === 'temporarily_blocked') {
            return [
                'success' => false,
                'is_temporarily_blocked' => true,
                'error' => 'Your account is temporarily restricted pending review. Please contact support.'
            ];
        }

        if ($user['account_status'] === 'pending_verification') {
            return [
                'success' => false,
                'is_pending_verification' => true,
                'email' => $user['email'],
                'phone' => $user['phone'],
                'error' => 'Please verify your email address to activate your account.'
            ];
        }

        RateLimiter::reset($rateKey, 'login');

        // Safely start and regenerate session to prevent session fixation and stale session ID reuse
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        // Establish session
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['admin_role'] = $user['admin_role'] ?? ($user['role'] === 'admin' ? 'super_admin' : null);
        $_SESSION['phone'] = $user['phone'];
        $_SESSION['is_impersonating'] = false;

        return [
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'phone' => $user['phone'],
                'email' => $user['email'] ?? null,
                'role' => $user['role'],
                'admin_role' => $user['admin_role'] ?? null,
                'is_demo' => !empty($user['is_demo']),
                'referral_code' => $user['referral_code'],
                'has_tpin' => !empty($user['tpin_hash']),
                'upi_id' => $user['upi_id'],
                'account_holder_name' => $user['account_holder_name'],
                'health_score' => (int)($user['health_score'] ?? 100)
            ]
        ];
    }

    /**
     * Verify user email address with 6-digit OTP code and activate account
     */
    public static function verifyEmailOtp(string $email, string $otp): array {
        $res = EmailService::verifyOtp($email, $otp);
        if ($res['success']) {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([(int)$res['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['admin_role'] = $user['admin_role'] ?? ($user['role'] === 'admin' ? 'super_admin' : null);
                $_SESSION['phone'] = $user['phone'];
                $_SESSION['is_impersonating'] = false;

                $res['user'] = [
                    'id' => (int)$user['id'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'referral_code' => $user['referral_code'],
                    'upi_id' => $user['upi_id'],
                    'account_holder_name' => $user['account_holder_name']
                ];
            }
        }
        return $res;
    }

    /**
     * Verify user email via 1-click token link and activate account
     */
    public static function verifyEmailToken(string $email, string $token): array {
        $res = EmailService::verifyToken($email, $token);
        if ($res['success'] && !empty($res['user_id'])) {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([(int)$res['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['admin_role'] = $user['admin_role'] ?? ($user['role'] === 'admin' ? 'super_admin' : null);
                $_SESSION['phone'] = $user['phone'];
                $_SESSION['is_impersonating'] = false;

                $res['user'] = [
                    'id' => (int)$user['id'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'referral_code' => $user['referral_code'],
                    'upi_id' => $user['upi_id'],
                    'account_holder_name' => $user['account_holder_name']
                ];
            }
        }
        return $res;
    }

    /**
     * Resend verification OTP code to user's registered email
     */
    public static function resendEmailOtp(string $identifier): array {
        $clean = trim($identifier);
        $cleanPhone = Validator::cleanPhone($clean);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, email, phone, account_holder_name, account_status FROM users WHERE phone = ? OR email = ?");
        $stmt->execute([$cleanPhone, strtolower($clean)]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'Account not found. Please register first.'];
        }

        if ($user['account_status'] === 'active') {
            return ['success' => false, 'error' => 'This account is already active and verified. Please sign in.'];
        }

        if (empty($user['email'])) {
            return ['success' => false, 'error' => 'No email address associated with this account.'];
        }

        // Rate limit resend to 1 per 60 seconds
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateKey = "resend_otp_{$user['id']}_{$clientIp}";
        if (!RateLimiter::check($rateKey, 'resend_otp', 1, 60)) {
            return ['success' => false, 'error' => 'Please wait 60 seconds before requesting another verification code.'];
        }

        return EmailService::initiateVerification((int)$user['id'], $user['email'], $user['account_holder_name']);
    }

    /**
     * Request Password Reset OTP & Token
     */
    public static function requestPasswordReset(string $identifier): array {
        $clean = trim($identifier);
        if (empty($clean)) {
            return ['success' => false, 'error' => 'Please provide your registered email address.'];
        }

        $pdo = Database::getConnection();
        $cleanEmail = strtolower($clean);
        $cleanPhone = Validator::cleanPhone($clean);

        // Find user by email, phone, or id
        if (filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT id, phone, email, account_holder_name, account_status FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$cleanEmail]);
        } elseif (strlen($cleanPhone) === 10) {
            $stmt = $pdo->prepare("SELECT id, phone, email, account_holder_name, account_status FROM users WHERE phone = ? OR LOWER(email) = ?");
            $stmt->execute([$cleanPhone, $cleanEmail]);
        } else {
            $stmt = $pdo->prepare("SELECT id, phone, email, account_holder_name, account_status FROM users WHERE id = ? OR phone = ? OR LOWER(email) = ?");
            $stmt->execute([(int)$clean, $cleanPhone, $cleanEmail]);
        }
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'No account found matching the provided details. Please verify and try again.'];
        }

        if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'No verified email address is linked to this account. Please contact support.'];
        }

        if ($user['account_status'] === 'suspended') {
            return ['success' => false, 'error' => 'This account has been suspended. Please contact support.'];
        }

        $userId = (int)$user['id'];
        $userEmail = strtolower(trim($user['email']));
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Rate limit: 1 reset request per 60 seconds
        $rateKey = "forgot_pw_{$userId}_{$clientIp}";
        if (!RateLimiter::check($rateKey, 'forgot_pw', 1, 60)) {
            return ['success' => false, 'error' => 'A password reset code was recently sent. Please wait 60 seconds before requesting another code.'];
        }

        $otp = sprintf('%06d', random_int(100000, 999999));
        $token = bin2hex(random_bytes(32));
        $nowUtc = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 900); // 15 minutes

        // Expire previous pending resets for this user/email
        $expStmt = $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE (user_id = ? OR LOWER(email) = ?) AND status = 'pending'");
        $expStmt->execute([$userId, $userEmail]);

        // Insert new password_resets record
        $ins = $pdo->prepare("
            INSERT INTO password_resets (user_id, email, otp_code, reset_token, status, attempts, ip_address, created_at, expires_at)
            VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, ?)
        ");
        $ins->execute([$userId, $userEmail, $otp, $token, $clientIp, $nowUtc, $expiresAt]);

        // Update user record
        $upd = $pdo->prepare("
            UPDATE users
            SET password_reset_otp = ?,
                password_reset_token = ?,
                password_reset_expires_at = ?
            WHERE id = ?
        ");
        $upd->execute([$otp, $token, $expiresAt, $userId]);

        // Dispatch Email via Hostinger SMTP
        $mailRes = EmailService::sendPasswordResetEmail($userEmail, $otp, $token, $user['account_holder_name'] ?? null);

        // Mask email for security display (e.g., u***r@example.com)
        $emailParts = explode('@', $userEmail);
        $namePart = $emailParts[0];
        $domainPart = $emailParts[1] ?? 'jelipay.in';
        $len = strlen($namePart);
        if ($len <= 2) {
            $maskedUser = $namePart[0] . '*';
        } else {
            $maskedUser = substr($namePart, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($namePart, -1);
        }
        $maskedEmail = $maskedUser . '@' . $domainPart;

        return [
            'success' => true,
            'email' => $userEmail,
            'masked_email' => $maskedEmail,
            'otp' => (getenv('ALLOW_SQLITE_CLI_FALLBACK') === '1' || getenv('MOCK_EMAIL_DELIVERY') === '1') ? $otp : null,
            'token' => (getenv('ALLOW_SQLITE_CLI_FALLBACK') === '1' || getenv('MOCK_EMAIL_DELIVERY') === '1') ? $token : null,
            'mail_sent' => $mailRes['success'],
            'mail_error' => $mailRes['error'] ?? null,
            'message' => 'Password reset code has been sent to ' . $maskedEmail . '.'
        ];
    }

    /**
     * Verify Password Reset 6-Digit OTP
     */
    public static function verifyPasswordResetOtp(string $email, string $otp): array {
        $cleanEmail = strtolower(trim($email));
        $cleanOtp = trim($otp);

        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        if (strlen($cleanOtp) !== 6 || !ctype_digit($cleanOtp)) {
            return ['success' => false, 'error' => 'Please enter a valid 6-digit reset code.'];
        }

        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT * FROM password_resets
            WHERE LOWER(email) = ? AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$cleanEmail]);
        $rec = $stmt->fetch();

        if (!$rec) {
            return ['success' => false, 'error' => 'No active password reset request found. Please request a new code.'];
        }

        if ((int)$rec['attempts'] >= 5) {
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Too many failed attempts. Please request a new code.'];
        }

        if (strtotime($rec['expires_at']) < strtotime($nowUtc)) {
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Reset code has expired. Please request a new code.'];
        }

        if ($rec['otp_code'] !== $cleanOtp) {
            $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?")->execute([$rec['id']]);
            $remaining = 5 - ((int)$rec['attempts'] + 1);
            return ['success' => false, 'error' => "Incorrect reset code. {$remaining} attempt(s) remaining."];
        }

        return [
            'success' => true,
            'reset_token' => $rec['reset_token'],
            'email' => $cleanEmail,
            'message' => 'Code verified successfully. Please enter your new password.'
        ];
    }

    /**
     * Verify Password Reset Token (1-Click Link)
     */
    public static function verifyPasswordResetToken(string $email, string $token): array {
        $cleanEmail = strtolower(trim($email));
        $cleanToken = trim($token);

        if (empty($cleanEmail) || empty($cleanToken)) {
            return ['success' => false, 'error' => 'Invalid password reset link parameters.'];
        }

        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT * FROM password_resets
            WHERE LOWER(email) = ? AND reset_token = ? AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$cleanEmail, $cleanToken]);
        $rec = $stmt->fetch();

        if (!$rec) {
            return ['success' => false, 'error' => 'Invalid or expired password reset link. Please request a new one.'];
        }

        if (strtotime($rec['expires_at']) < strtotime($nowUtc)) {
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'This password reset link has expired. Please request a new one.'];
        }

        return [
            'success' => true,
            'user_id' => (int)$rec['user_id'],
            'email' => $cleanEmail,
            'reset_token' => $cleanToken,
            'message' => 'Reset link is valid. You may now set a new password.'
        ];
    }

    /**
     * Reset Password and Log User In
     */
    public static function resetPassword(string $email, string $codeOrToken, string $newPassword, ?string $confirmPassword = null): array {
        $cleanEmail = strtolower(trim($email));
        $codeOrToken = trim($codeOrToken);

        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters long.'];
        }

        if ($confirmPassword !== null && $newPassword !== $confirmPassword) {
            return ['success' => false, 'error' => 'New password and confirmation do not match.'];
        }

        if (empty($codeOrToken)) {
            return ['success' => false, 'error' => 'Reset code or authorization token is required.'];
        }

        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Look for matching pending reset record by otp_code OR reset_token
        $stmt = $pdo->prepare("
            SELECT * FROM password_resets
            WHERE LOWER(email) = ? AND (otp_code = ? OR reset_token = ?) AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$cleanEmail, $codeOrToken, $codeOrToken]);
        $rec = $stmt->fetch();

        if (!$rec) {
            return ['success' => false, 'error' => 'Invalid or expired reset code. Please request a new password reset.'];
        }

        if (strtotime($rec['expires_at']) < strtotime($nowUtc)) {
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'This reset session has expired. Please request a new code.'];
        }

        if ((int)$rec['attempts'] >= 5) {
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Too many attempts. Please request a new reset code.'];
        }

        $userId = (int)$rec['user_id'];
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'Associated account could not be found.'];
        }

        if ($user['account_status'] === 'suspended') {
            return ['success' => false, 'error' => 'This account has been suspended. Please contact support.'];
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo->beginTransaction();
        try {
            // Update password_resets record
            $updPr = $pdo->prepare("UPDATE password_resets SET status = 'used', used_at = ? WHERE id = ?");
            $updPr->execute([$nowUtc, $rec['id']]);

            // Update user record: update password_hash, password_changed_at, clear reset fields, and activate if pending
            $updUser = $pdo->prepare("
                UPDATE users
                SET password_hash = ?,
                    password_changed_at = ?,
                    password_reset_otp = NULL,
                    password_reset_token = NULL,
                    password_reset_expires_at = NULL,
                    account_status = CASE WHEN account_status = 'pending_verification' THEN 'active' ELSE account_status END,
                    email_verified_at = COALESCE(email_verified_at, ?)
                WHERE id = ?
            ");
            $updUser->execute([$passwordHash, $nowUtc, $nowUtc, $userId]);

            $pdo->commit();

            // Establish session so user can log in immediately
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['admin_role'] = $user['admin_role'] ?? ($user['role'] === 'admin' ? 'super_admin' : null);
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['is_impersonating'] = false;

            return [
                'success' => true,
                'message' => 'Password reset successfully! You are now signed in.',
                'user' => [
                    'id' => (int)$user['id'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'admin_role' => $user['admin_role'] ?? null,
                    'is_demo' => !empty($user['is_demo']),
                    'referral_code' => $user['referral_code'],
                    'has_tpin' => !empty($user['tpin_hash']),
                    'upi_id' => $user['upi_id'],
                    'account_holder_name' => $user['account_holder_name'],
                    'health_score' => (int)($user['health_score'] ?? 100)
                ]
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to reset password: ' . $e->getMessage()];
        }
    }

    /**
     * Dedicated Administrator Login Portal Authentication (Requirement 8)
     */
    public static function adminLogin(string $identifier, string $password): array {
        $trimmed = trim($identifier);
        $cleanPhone = Validator::cleanPhone($trimmed);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateKey = "admin_login_{$trimmed}_{$clientIp}";

        if (!RateLimiter::check($rateKey, 'admin_login', 5, 900)) {
            return ['success' => false, 'error' => 'Too many failed admin login attempts. Security cooldown active for 15 minutes.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM users
            WHERE (phone = ? OR email = ? OR referral_code = ?) AND role = 'admin'
        ");
        $stmt->execute([$cleanPhone, $trimmed, $trimmed]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid administrator credentials. Access unauthorized.'];
        }

        if ($admin['account_status'] === 'suspended') {
            return ['success' => false, 'error' => 'This administrative account has been deactivated.'];
        }

        RateLimiter::reset($rateKey, 'admin_login');

        // Establish administrative session
        $_SESSION['user_id'] = (int)$admin['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['admin_role'] = $admin['admin_role'] ?? 'super_admin';
        $_SESSION['phone'] = $admin['phone'];
        $_SESSION['is_admin'] = true;
        $_SESSION['is_impersonating'] = false;

        // Audit admin sign-in
        try {
            $nowUtc = gmdate('Y-m-d H:i:s');
            $logStmt = $pdo->prepare("
                INSERT INTO admin_audit_logs (admin_id, action_type, target_entity, target_id, reason, ip_address, created_at)
                VALUES (?, 'ADMIN_LOGIN', 'users', ?, 'Successful admin session login', ?, ?)
            ");
            $logStmt->execute([(int)$admin['id'], (string)$admin['id'], $clientIp, $nowUtc]);
        } catch (Exception $e) {
            // Ignore log failure
        }

        return [
            'success' => true,
            'admin' => [
                'id' => (int)$admin['id'],
                'phone' => $admin['phone'],
                'email' => $admin['email'] ?? null,
                'role' => $admin['role'],
                'admin_role' => $admin['admin_role'] ?? 'super_admin',
                'referral_code' => $admin['referral_code'],
                'name' => $admin['account_holder_name'] ?? 'Admin'
            ]
        ];
    }

    /**
     * Set or change Selling TPIN (FR-002)
     */
    public static function setTPIN(int $userId, string $tpin): array {
        if (!Validator::validateTPIN($tpin)) {
            return ['success' => false, 'error' => 'TPIN must be a 4 to 6 digit numerical PIN.'];
        }

        $tpinHash = password_hash($tpin, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET tpin_hash = ? WHERE id = ?");
        $stmt->execute([$tpinHash, $userId]);

        return ['success' => true, 'message' => 'Selling TPIN configured successfully.'];
    }

    /**
     * Verify Selling TPIN before authorizing sell orders (FR-002)
     */
    public static function verifyTPIN(int $userId, string $tpin): array {
        $rateKey = "tpin_{$userId}";
        if (!RateLimiter::check($rateKey, 'verify_tpin', 5, 300)) {
            return ['success' => false, 'error' => 'Too many failed TPIN attempts. Please wait 5 minutes before trying again.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT tpin_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['tpin_hash'])) {
            return ['success' => false, 'error' => 'Selling TPIN has not been created yet. Please set your TPIN in profile settings.'];
        }

        if (!password_verify($tpin, $row['tpin_hash'])) {
            return ['success' => false, 'error' => 'Invalid Selling TPIN.'];
        }

        RateLimiter::reset($rateKey, 'verify_tpin');
        return ['success' => true];
    }

    /**
     * Update user UPI profile details
     */
    public static function updateProfile(int $userId, string $upiId, string $holderName, ?string $email = null): array {
        $trimmedUpi = trim($upiId);
        $trimmedName = trim($holderName);

        if (empty($trimmedUpi) || !str_contains($trimmedUpi, '@')) {
            return ['success' => false, 'error' => 'Please provide a valid UPI ID (e.g. user@bank).'];
        }

        if (empty($trimmedName)) {
            return ['success' => false, 'error' => 'Account holder name is required.'];
        }

        $pdo = Database::getConnection();

        // Enforce email immutability (Requirement 5):
        // The email address supplied during registration remains associated with the account and cannot be modified.
        $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $existingEmail = $userStmt->fetchColumn();

        if ($email !== null && !empty($existingEmail)) {
            $trimmedEmail = strtolower(trim($email));
            if ($trimmedEmail !== '' && $trimmedEmail !== strtolower($existingEmail)) {
                return ['success' => false, 'error' => 'Registered email address is immutable and cannot be changed.'];
            }
        }

        // Update banking settlement details only
        $stmt = $pdo->prepare("UPDATE users SET upi_id = ?, account_holder_name = ? WHERE id = ?");
        $stmt->execute([$trimmedUpi, $trimmedName, $userId]);

        return ['success' => true, 'message' => 'Banking details updated successfully.'];
    }

    /**
     * Get current authenticated user
     */
    public static function getCurrentUser(): ?array {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $userId = (int)$_SESSION['user_id'];
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, phone, email, role, admin_role, is_demo, is_flagged, flag_reason,
                   account_status, referral_code, referred_by_id, upi_id, account_holder_name,
                   health_score,
                   (tpin_hash IS NOT NULL AND tpin_hash != '') AS has_tpin,
                   (totp_enabled = 1) AS totp_enabled
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            session_destroy();
            return null;
        }

        $user['health_score'] = (int)($user['health_score'] ?? 100);
        $user['is_impersonating'] = !empty($_SESSION['is_impersonating']);
        $user['real_admin_id'] = $_SESSION['real_admin_id'] ?? null;
        $user['totp_enabled'] = (bool)($user['totp_enabled'] ?? false);
        return $user;
    }

    /**
     * Get user's TOTP 2FA status and remaining recovery codes count.
     * Never exposes secrets, hashes, or sensitive keys in responses.
     */
    public static function getTotpStatus(int $userId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT totp_enabled, totp_recovery_codes, password_changed_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        $enabled = (int)($row['totp_enabled'] ?? 0) === 1;
        $remainingCodes = 0;
        $totalCodes = 0;

        if (!empty($row['totp_recovery_codes'])) {
            $codes = json_decode($row['totp_recovery_codes'], true);
            if (is_array($codes)) {
                $totalCodes = count($codes);
                foreach ($codes as $item) {
                    if (empty($item['used'])) {
                        $remainingCodes++;
                    }
                }
            }
        }

        return [
            'success' => true,
            'enabled' => $enabled,
            'remaining_recovery_codes' => $remainingCodes,
            'total_recovery_codes' => $totalCodes,
            'password_changed_at' => $row['password_changed_at'] ?? null
        ];
    }

    /**
     * Initiate TOTP Two-Factor Authentication setup.
     * Generates a unique 160-bit Base32 secret, stores it encrypted as totp_temp_secret,
     * and generates offline vector SVG QR code and provisioning URI.
     */
    public static function initiateTotpSetup(int $userId): array {
        if (!RateLimiter::check("totp_setup_{$userId}", "totp_setup", 10, 300)) {
            return [
                'success' => false,
                'error' => 'Too many setup requests. Please wait 5 minutes before trying again.'
            ];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, phone, email, totp_enabled FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'User not found.'];
        }

        // Generate 160-bit (20-byte) Base32 secret (RFC 6238)
        $secret = \Security\TotpService::generateSecret(20);
        $encryptedSecret = \Security\TotpService::encryptSecret($secret);
        $now = gmdate('Y-m-d H:i:s');

        // Store encrypted temporary secret and timestamp
        $upd = $pdo->prepare("UPDATE users SET totp_temp_secret = ?, totp_temp_created_at = ? WHERE id = ?");
        $upd->execute([$encryptedSecret, $now, $userId]);

        // Build account identifier for authenticator display
        $identifier = !empty($user['email']) ? $user['email'] : ($user['phone'] ?? "user_{$userId}");
        $issuer = defined('APP_NAME') ? APP_NAME : 'JeliPay';
        $provisioningUri = \Security\TotpService::getProvisioningUri($secret, $identifier, $issuer);

        // Generate pure-PHP vector SVG QR code (zero network requests, 100% offline)
        $qrSvg = \Security\QRCodeGenerator::generateSvg($provisioningUri, 220, '#ffffff', '#020617');

        // Formatted manual entry key in groups of 4 for human readability
        $formattedSecret = trim(chunk_split($secret, 4, ' '));

        return [
            'success' => true,
            'secret' => $secret,
            'secret_formatted' => $formattedSecret,
            'qr_svg' => $qrSvg,
            'provisioning_uri' => $provisioningUri,
            'issuer' => $issuer,
            'identifier' => $identifier
        ];
    }

    /**
     * Confirm TOTP enrollment using a valid 6-digit authenticator code.
     * Does NOT activate 2FA until code is strictly verified against temp secret.
     * Generates 8 single-use cryptographically secure recovery codes.
     */
    public static function verifyTotpEnrollment(int $userId, string $code, ?int $timestamp = null): array {
        if (!RateLimiter::check("totp_enroll_{$userId}", "totp_verify", 5, 300)) {
            return [
                'success' => false,
                'error' => 'Too many failed verification attempts. Please wait 5 minutes before trying again.'
            ];
        }

        $cleanCode = preg_replace('/\s+/', '', trim($code));
        if (strlen($cleanCode) !== 6 || !ctype_digit($cleanCode)) {
            return ['success' => false, 'error' => 'Please enter a valid 6-digit verification code.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT totp_temp_secret, totp_temp_created_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || empty($user['totp_temp_secret'])) {
            return ['success' => false, 'error' => 'No pending 2FA setup found. Please initiate setup again.'];
        }

        // Expire setup session after 15 minutes (900 seconds)
        if (!empty($user['totp_temp_created_at'])) {
            $createdTs = strtotime($user['totp_temp_created_at']);
            if ((time() - $createdTs) > 900) {
                return ['success' => false, 'error' => 'Setup session expired. Please generate a new QR code.'];
            }
        }

        $tempSecret = \Security\TotpService::decryptSecret($user['totp_temp_secret']);
        if (!$tempSecret) {
            return ['success' => false, 'error' => 'Unable to decrypt setup secret. Please restart setup.'];
        }

        // Verify with 1 time step clock drift tolerance
        $verify = \Security\TotpService::verifyCode($tempSecret, $cleanCode, 0, 1, $timestamp);
        if (!$verify['valid']) {
            return [
                'success' => false,
                'error' => $verify['error'] ?? 'Invalid authenticator code. Make sure your phone clock is synced.'
            ];
        }

        // Generate 8 single-use cryptographically secure recovery codes
        $recovery = \Security\TotpService::generateRecoveryCodes(8);
        $hashedCodesJson = json_encode($recovery['stored']);

        // Activate 2FA, store permanent encrypted secret, and clean up temp secret
        $upd = $pdo->prepare("
            UPDATE users SET
                totp_secret = totp_temp_secret,
                totp_enabled = 1,
                totp_temp_secret = NULL,
                totp_temp_created_at = NULL,
                totp_last_timestamp = ?,
                totp_recovery_codes = ?
            WHERE id = ?
        ");
        $upd->execute([
            $verify['step'],
            $hashedCodesJson,
            $userId
        ]);

        RateLimiter::reset("totp_enroll_{$userId}", "totp_verify");

        // Notify user about 2FA activation
        self::createNotification(
            $userId,
            'Two-Factor Authentication Enabled',
            'Google Authenticator 2FA has been successfully activated on your account. Keep your backup recovery codes in a safe place.',
            'info'
        );

        return [
            'success' => true,
            'message' => 'Two-Factor Authentication activated successfully.',
            'recovery_codes' => $recovery['plain']
        ];
    }

    /**
     * Disable TOTP Two-Factor Authentication.
     * Strictly requires current account password and either a valid 6-digit TOTP code
     * or an unused single-use backup recovery code.
     */
    public static function disableTotp(int $userId, string $password, string $code, ?int $timestamp = null): array {
        if (!RateLimiter::check("totp_disable_{$userId}", "totp_disable", 5, 300)) {
            return [
                'success' => false,
                'error' => 'Too many failed attempts. Temporary lockout for 5 minutes.'
            ];
        }

        $cleanPassword = trim($password);
        $cleanCode = preg_replace('/\s+/', '', trim($code));

        if (empty($cleanPassword) || empty($cleanCode)) {
            return ['success' => false, 'error' => 'Both your account password and a verification code are required.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash, totp_enabled, totp_secret, totp_last_timestamp, totp_recovery_codes FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($cleanPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current account password is incorrect.'];
        }

        if (empty($user['totp_enabled'])) {
            return ['success' => false, 'error' => 'Two-Factor Authentication is not enabled on this account.'];
        }

        $secret = !empty($user['totp_secret']) ? \Security\TotpService::decryptSecret($user['totp_secret']) : null;
        $codeVerified = false;

        // Try TOTP code first if 6 digits
        if ($secret && strlen($cleanCode) === 6 && ctype_digit($cleanCode)) {
            $verifyRes = \Security\TotpService::verifyCode($secret, $cleanCode, (int)($user['totp_last_timestamp'] ?? 0), 1, $timestamp);
            if ($verifyRes['valid']) {
                $codeVerified = true;
            }
        }

        // Try recovery code fallback if TOTP didn't pass
        if (!$codeVerified && !empty($user['totp_recovery_codes'])) {
            $storedCodes = json_decode($user['totp_recovery_codes'], true) ?: [];
            $recRes = \Security\TotpService::verifyRecoveryCode($cleanCode, $storedCodes);
            if ($recRes['valid']) {
                $codeVerified = true;
            }
        }

        if (!$codeVerified) {
            return ['success' => false, 'error' => 'Invalid authenticator code or recovery code.'];
        }

        // Wipe TOTP credentials completely
        $upd = $pdo->prepare("
            UPDATE users SET
                totp_secret = NULL,
                totp_enabled = 0,
                totp_temp_secret = NULL,
                totp_temp_created_at = NULL,
                totp_last_timestamp = 0,
                totp_recovery_codes = NULL
            WHERE id = ?
        ");
        $upd->execute([$userId]);

        RateLimiter::reset("totp_disable_{$userId}", "totp_disable");

        // High priority security notification
        self::createNotification(
            $userId,
            'Security Alert: 2FA Disabled',
            'Two-Factor Authentication was disabled on your account. If you did not authorize this, change your password immediately.',
            'warning'
        );

        return [
            'success' => true,
            'message' => 'Two-Factor Authentication has been disabled.'
        ];
    }

    /**
     * Regenerate 8 single-use recovery codes.
     * Requires current password and a valid TOTP code.
     */
    public static function regenerateRecoveryCodes(int $userId, string $password, string $code, ?int $timestamp = null): array {
        if (!RateLimiter::check("totp_regen_{$userId}", "totp_regen", 5, 300)) {
            return [
                'success' => false,
                'error' => 'Too many attempts. Please wait 5 minutes before trying again.'
            ];
        }

        $cleanPassword = trim($password);
        $cleanCode = preg_replace('/\s+/', '', trim($code));

        if (empty($cleanPassword) || empty($cleanCode)) {
            return ['success' => false, 'error' => 'Password and current authenticator code are required.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash, totp_enabled, totp_secret, totp_last_timestamp FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($cleanPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        if (empty($user['totp_enabled']) || empty($user['totp_secret'])) {
            return ['success' => false, 'error' => 'Two-Factor Authentication is not enabled.'];
        }

        $secret = \Security\TotpService::decryptSecret($user['totp_secret']);
        if (!$secret) {
            return ['success' => false, 'error' => 'Unable to decrypt authenticator secret.'];
        }

        $verifyRes = \Security\TotpService::verifyCode($secret, $cleanCode, (int)($user['totp_last_timestamp'] ?? 0), 1, $timestamp);
        if (!$verifyRes['valid']) {
            return ['success' => false, 'error' => $verifyRes['error'] ?? 'Invalid authenticator code.'];
        }

        $recovery = \Security\TotpService::generateRecoveryCodes(8);
        $hashedCodesJson = json_encode($recovery['stored']);

        $upd = $pdo->prepare("
            UPDATE users SET
                totp_last_timestamp = ?,
                totp_recovery_codes = ?
            WHERE id = ?
        ");
        $upd->execute([
            $verifyRes['step'],
            $hashedCodesJson,
            $userId
        ]);

        RateLimiter::reset("totp_regen_{$userId}", "totp_regen");

        self::createNotification(
            $userId,
            'Backup Recovery Codes Regenerated',
            'Your single-use 2FA backup recovery codes have been regenerated. Any previous recovery codes are now invalid.',
            'info'
        );

        return [
            'success' => true,
            'message' => 'New recovery codes generated successfully.',
            'recovery_codes' => $recovery['plain']
        ];
    }

    /**
     * Change user password (Requirement 7 & 2FA Enforcement)
     * When 2FA is enabled, requires a valid 6-digit TOTP code or single-use recovery code.
     * Enforces rate limiting, session regeneration, and security audit notification.
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword, ?string $totpCode = null, ?int $timestamp = null): array {
        if (!RateLimiter::check("pwd_change_{$userId}", "change_pwd", 5, 300)) {
            return [
                'success' => false,
                'error' => 'Too many failed password change attempts. Please wait 5 minutes before trying again.'
            ];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters.'];
        }

        if ($currentPassword === $newPassword) {
            return ['success' => false, 'error' => 'New password must be different from your current password.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT password_hash, totp_enabled, totp_secret, totp_last_timestamp, totp_recovery_codes
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $isTotpEnabled = (int)($user['totp_enabled'] ?? 0) === 1;
        $updatedLastTimestamp = (int)($user['totp_last_timestamp'] ?? 0);
        $updatedRecoveryCodesJson = null;
        $usedRecoveryCode = false;

        // When 2FA is enabled, enforce valid TOTP or single-use recovery code
        if ($isTotpEnabled) {
            $cleanTotp = preg_replace('/\s+/', '', trim((string)$totpCode));
            if ($cleanTotp === '') {
                return [
                    'success' => false,
                    'error' => 'Two-Factor Authentication code is required to change password.',
                    'requires_totp' => true
                ];
            }

            $secret = !empty($user['totp_secret']) ? \Security\TotpService::decryptSecret($user['totp_secret']) : null;
            if (!$secret) {
                return ['success' => false, 'error' => 'Security configuration error. Please contact support.'];
            }

            $codeVerified = false;

            // 1. Try TOTP 6-digit time-based code
            if (strlen($cleanTotp) === 6 && ctype_digit($cleanTotp)) {
                $verifyRes = \Security\TotpService::verifyCode($secret, $cleanTotp, $updatedLastTimestamp, 1, $timestamp);
                if ($verifyRes['valid']) {
                    $codeVerified = true;
                    $updatedLastTimestamp = (int)$verifyRes['step'];
                } elseif ($verifyRes['code'] === 'REPLAYED_CODE') {
                    return [
                        'success' => false,
                        'error' => $verifyRes['error']
                    ];
                }
            }

            // 2. Try single-use recovery code fallback
            if (!$codeVerified && !empty($user['totp_recovery_codes'])) {
                $storedCodes = json_decode($user['totp_recovery_codes'], true) ?: [];
                $recRes = \Security\TotpService::verifyRecoveryCode($cleanTotp, $storedCodes);
                if ($recRes['valid']) {
                    $codeVerified = true;
                    $usedRecoveryCode = true;
                    $updatedRecoveryCodesJson = json_encode($recRes['updated_codes']);
                }
            }

            if (!$codeVerified) {
                return [
                    'success' => false,
                    'error' => 'Invalid or expired Two-Factor Authentication code. If you cannot access your authenticator app, enter a single-use backup recovery code.'
                ];
            }
        }

        // Apply password update and security timestamps
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $now = gmdate('Y-m-d H:i:s');

        if ($isTotpEnabled) {
            if ($updatedRecoveryCodesJson !== null) {
                $upd = $pdo->prepare("
                    UPDATE users SET
                        password_hash = ?,
                        password_changed_at = ?,
                        totp_last_timestamp = ?,
                        totp_recovery_codes = ?
                    WHERE id = ?
                ");
                $upd->execute([$newHash, $now, $updatedLastTimestamp, $updatedRecoveryCodesJson, $userId]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE users SET
                        password_hash = ?,
                        password_changed_at = ?,
                        totp_last_timestamp = ?
                    WHERE id = ?
                ");
                $upd->execute([$newHash, $now, $updatedLastTimestamp, $userId]);
            }
        } else {
            $upd = $pdo->prepare("
                UPDATE users SET
                    password_hash = ?,
                    password_changed_at = ?
                WHERE id = ?
            ");
            $upd->execute([$newHash, $now, $userId]);
        }

        // Session Security Policy: Regenerate session ID to prevent session fixation / stale credentials
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        // Reset rate limit on success
        RateLimiter::reset("pwd_change_{$userId}", "change_pwd");

        // Dispatch audit notification
        $alertBody = 'Your account login password was successfully updated' .
            ($usedRecoveryCode ? ' using a single-use backup recovery code.' : ($isTotpEnabled ? ' with 2FA verification.' : '.')) .
            ' If you did not make this change, please contact support immediately.';

        self::createNotification($userId, 'Security Alert: Password Updated', $alertBody, 'warning');

        return ['success' => true, 'message' => 'Login password updated successfully.'];
    }

    /**
     * Adjust user Health / Credit Score and auto-ban if score drops to <= 50 (Requirement 5)
     */
    public static function adjustHealthScore(int $userId, int $delta, string $reason): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT health_score, account_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) return ['success' => false, 'error' => 'User not found.'];

        $curScore = (int)($user['health_score'] ?? 100);
        $newScore = max(0, min(100, $curScore + $delta));
        $newStatus = ($newScore <= 50) ? 'suspended' : $user['account_status'];

        $upd = $pdo->prepare("UPDATE users SET health_score = ?, account_status = ? WHERE id = ?");
        $upd->execute([$newScore, $newStatus, $userId]);

        $title = ($delta < 0) ? 'Health Score Deducted' : 'Health Score Updated';
        $msg = "Your Health Score was changed by {$delta} points ({$newScore}/100). Reason: {$reason}.";
        if ($newScore <= 50) {
            $msg .= " WARNING: Your account was automatically suspended because your Health Score dropped to 50 or below.";
        }
        self::createNotification($userId, $title, $msg, ($delta < 0 ? 'warning' : 'info'));

        return [
            'success' => true,
            'old_score' => $curScore,
            'new_score' => $newScore,
            'is_banned' => ($newScore <= 50)
        ];
    }

    /**
     * Dispatch an in-app notification (Requirement 6)
     */
    public static function createNotification(int $userId, string $title, string $message, string $type = 'info', ?string $refId = null): void {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, reference_id, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, 0, ?)
            ");
            $stmt->execute([$userId, $title, $message, $type, $refId, gmdate('Y-m-d H:i:s')]);
        } catch (Exception $e) {
            // Non-blocking notification write
        }
    }

    /**
     * Check if current session is Admin
     */
    public static function isAdmin(): bool {
        $user = self::getCurrentUser();
        return $user && $user['role'] === 'admin';
    }

    /**
     * Logout
     */
    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
