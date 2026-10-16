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

        $res = Auth::updateProfile($user['id'], $upiId, $holderName);
        if ($res['success']) {
            jsonResponse(true, ['message' => $res['message']]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'exit_impersonation':
        require_once __DIR__ . '/../src/AdminManager.php';
        AdminManager::exitImpersonation();
        jsonResponse(true, ['message' => 'Exited impersonation support mode.']);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
