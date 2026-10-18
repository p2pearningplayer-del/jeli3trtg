<?php
/**
 * Authentication & Security Service
 * Handles user registration, bcrypt authentication, TPIN verification, and secure sessions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/RateLimiter.php';

class Auth {
    /**
     * Register a new user
     */
    public static function register(string $phone, string $password, ?string $referralCode = null, ?string $upiId = null, ?string $name = null): array {
        $cleanPhone = Validator::cleanPhone($phone);
        if (!Validator::validatePhone($cleanPhone)) {
            return ['success' => false, 'error' => 'Please provide a valid 10-digit Indian mobile number.'];
        }

        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'Password must be at least 6 characters.'];
        }

        $pdo = Database::getConnection();

        // Check unique phone
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$cleanPhone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'An account with this phone number already exists.'];
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

        // Generate unique referral code for this user
        $myReferralCode = strtoupper('JP' . substr(md5(uniqid($cleanPhone, true)), 0, 6));

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare("
                INSERT INTO users (phone, password_hash, account_status, role, referral_code, referred_by_id, upi_id, account_holder_name)
                VALUES (?, ?, 'active', 'user', ?, ?, ?, ?)
            ");
            $insert->execute([
                $cleanPhone,
                $passwordHash,
                $myReferralCode,
                $referredById,
                $upiId ? trim($upiId) : null,
                $name ? trim($name) : null
            ]);
            $userId = (int)$pdo->lastInsertId();

            // Initialize wallet
            $walletInsert = $pdo->prepare("
                INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version)
                VALUES (?, 0.00, 0.00, 0.00, 1)
            ");
            $walletInsert->execute([$userId]);

            $pdo->commit();

            return [
                'success' => true,
                'user_id' => $userId,
                'phone' => $cleanPhone,
                'referral_code' => $myReferralCode
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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? OR email = ? OR referral_code = ?");
        $stmt->execute([$cleanPhone, $trimmed, $trimmed]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid credentials. Please verify your phone/ID and password.'];
        }

        if ($user['account_status'] === 'suspended') {
            return ['success' => false, 'error' => 'This account has been suspended by administration.'];
        }

        RateLimiter::reset($rateKey, 'login');

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

        if ($email !== null) {
            $trimmedEmail = trim($email);
            if ($trimmedEmail !== '' && !filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'error' => 'Please provide a valid email address.'];
            }
            $stmt = $pdo->prepare("UPDATE users SET upi_id = ?, account_holder_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$trimmedUpi, $trimmedName, $trimmedEmail ?: null, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET upi_id = ?, account_holder_name = ? WHERE id = ?");
            $stmt->execute([$trimmedUpi, $trimmedName, $userId]);
        }

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
                   (tpin_hash IS NOT NULL AND tpin_hash != '') AS has_tpin
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
        return $user;
    }

    /**
     * Change user password (Requirement 7)
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): array {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'New password must be at least 6 characters.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $userId]);

        self::createNotification($userId, 'Security Alert', 'Your account login password was successfully updated.', 'info');

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
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
