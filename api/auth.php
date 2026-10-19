<?php
/**
 * Authentication API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/response.php';

// Ensure schema exists
SchemaInstaller::ensureSchema();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'register':
        $phone = $input['phone'] ?? '';
        $password = $input['password'] ?? '';
        $refCode = $input['referral_code'] ?? null;
        $upiId = $input['upi_id'] ?? null;
        $name = $input['name'] ?? null;

        $res = Auth::register($phone, $password, $refCode, $upiId, $name);
        if ($res['success']) {
            // Auto login upon registration
            Auth::login($phone, $password);
            jsonResponse(true, ['message' => 'Registration successful.', 'user' => Auth::getCurrentUser()]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'login':
        $identifier = $input['identifier'] ?? $input['phone'] ?? '';
        $password = $input['password'] ?? '';

        $res = Auth::login($identifier, $password);
        if ($res['success']) {
            jsonResponse(true, ['message' => 'Login successful.', 'user' => $res['user']]);
        } else {
            jsonError($res['error'], 401);
        }
        break;

    case 'admin_login':
        $identifier = $input['identifier'] ?? $input['email'] ?? $input['phone'] ?? '';
        $password = $input['password'] ?? '';

        $res = Auth::adminLogin($identifier, $password);
        if ($res['success']) {
            jsonResponse(true, ['message' => 'Admin authentication successful.', 'admin' => $res['admin']]);
        } else {
            jsonError($res['error'], 401);
        }
        break;

    case 'logout':
        Auth::logout();
        jsonResponse(true, ['message' => 'Logged out successfully.']);
        break;

    case 'me':
        $user = Auth::getCurrentUser();
        if (!$user) {
            jsonError('Not authenticated.', 401);
        }
        $wallet = Wallet::getDashboardSummaries($user['id']);
        jsonResponse(true, [
            'user' => $user,
            'wallet' => $wallet
        ]);
        break;

    case 'set_tpin':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Financial modifications are disabled in Admin Support Mode.', 403);
        }

        $tpin = $input['tpin'] ?? '';
        $res = Auth::setTPIN($user['id'], $tpin);
        if ($res['success']) {
            jsonResponse(true, ['message' => $res['message']]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'update_profile':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Profile updates are disabled in Admin Support Mode.', 403);
        }

        $upiId = $input['upi_id'] ?? '';
        $holderName = $input['account_holder_name'] ?? '';
        $email = array_key_exists('email', $input) ? (string)$input['email'] : null;

        $res = Auth::updateProfile($user['id'], $upiId, $holderName, $email);
        if ($res['success']) {
            jsonResponse(true, ['message' => $res['message']]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'change_password':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Password changes are disabled in Admin Support Mode.', 403);
        }

        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $totpCode = isset($input['totp_code']) ? (string)$input['totp_code'] : null;

        if (empty($currentPassword) || empty($newPassword)) {
            jsonError('Current password and new password are both required.');
        }

        $res = Auth::changePassword($user['id'], $currentPassword, $newPassword, $totpCode);
        if ($res['success']) {
            jsonResponse(true, ['message' => $res['message']]);
        } else {
            jsonResponse(false, [
                'error' => $res['error'],
                'requires_totp' => !empty($res['requires_totp'])
            ], 400);
        }
        break;

    case 'totp_status':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);

        $res = Auth::getTotpStatus($user['id']);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'totp_setup':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Two-factor setup is disabled in Admin Support Mode.', 403);
        }

        $res = Auth::initiateTotpSetup($user['id']);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'totp_verify_enrollment':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Two-factor verification is disabled in Admin Support Mode.', 403);
        }

        $code = (string)($input['code'] ?? '');
        if (empty($code)) {
            jsonError('A 6-digit authenticator verification code is required.');
        }

        $res = Auth::verifyTotpEnrollment($user['id'], $code);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'totp_disable':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Two-factor modifications are disabled in Admin Support Mode.', 403);
        }

        $password = (string)($input['password'] ?? '');
        $code = (string)($input['code'] ?? '');

        if (empty($password) || empty($code)) {
            jsonError('Current password and verification code are both required to disable 2FA.');
        }

        $res = Auth::disableTotp($user['id'], $password, $code);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'totp_regenerate_recovery_codes':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);
        if ($user['is_impersonating']) {
            jsonError('Modifications are disabled in Admin Support Mode.', 403);
        }

        $password = (string)($input['password'] ?? '');
        $code = (string)($input['code'] ?? '');

        if (empty($password) || empty($code)) {
            jsonError('Password and current 6-digit authenticator code are required.');
        }

        $res = Auth::regenerateRecoveryCodes($user['id'], $password, $code);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'notifications':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, title, message, type, is_read, reference_id, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 25
        ");
        $stmt->execute([$user['id']]);
        $list = $stmt->fetchAll();

        $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $unreadStmt->execute([$user['id']]);
        $unreadCount = (int)$unreadStmt->fetchColumn();

        jsonResponse(true, [
            'notifications' => $list,
            'unread_count' => $unreadCount
        ]);
        break;

    case 'mark_notifications_read':
        $user = Auth::getCurrentUser();
        if (!$user) jsonError('Not authenticated.', 401);

        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
        jsonResponse(true, ['message' => 'Notifications marked as read.']);
        break;

    case 'exit_impersonation':
        require_once __DIR__ . '/../src/AdminManager.php';
        AdminManager::exitImpersonation();
        jsonResponse(true, ['message' => 'Exited impersonation support mode.']);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
