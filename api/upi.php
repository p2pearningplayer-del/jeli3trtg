<?php
/**
 * User UPI Accounts API Controller
 * Provides endpoints for Multi-UPI management, strict 1-to-1 binding validation, and risk cooldown inspection.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/UpiManager.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/Security/IPBanManager.php';
require_once __DIR__ . '/response.php';

// IP Ban and Rate Limit Enforcement
$clientIp = IPBanManager::getClientIp();
if (IPBanManager::isBanned($clientIp)) {
    jsonError('Access Denied: Your IP address is temporarily banned due to security policy violations.', 403);
}

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

if ($user['account_status'] === 'suspended') {
    jsonError('Access Denied: Your account is suspended.', 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$input = getJsonInput();

switch ($action) {
    case 'list':
        $accounts = UpiManager::getUserUpiAccounts((int)$user['id']);
        jsonResponse(true, ['accounts' => $accounts]);
        break;

    case 'add':
        if ($user['is_impersonating']) {
            jsonError('Adding UPI accounts is disabled in Admin Support Mode.', 403);
        }

        // Rate limit: 10 additions per 10 minutes
        $rateKey = "add_upi_{$user['id']}";
        if (!RateLimiter::checkWithBan($rateKey, 'add_upi_account', 10, 600, $clientIp)) {
            jsonError('Too many requests. Please wait a few minutes before adding another UPI ID.', 429);
        }

        $upiId = trim((string)($input['upi_id'] ?? ''));
        $phone = trim((string)($input['phone_number'] ?? $input['phone'] ?? ''));
        $holderName = trim((string)($input['account_holder_name'] ?? ''));
        $setAsPrimary = !empty($input['is_primary']);

        if (empty($upiId)) {
            jsonError('Please provide a valid UPI ID (e.g. username@okhdfcbank).');
        }

        if (empty($phone)) {
            jsonError('Please provide the 10-digit mobile number associated with this UPI account.');
        }

        $res = UpiManager::addUpiAccount((int)$user['id'], $upiId, $phone, $holderName, $setAsPrimary);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'] ?? 'Failed to add UPI account.');
        }
        break;

    case 'set_primary':
        if ($user['is_impersonating']) {
            jsonError('Modifying UPI settings is disabled in Admin Support Mode.', 403);
        }

        $accountId = (int)($input['upi_account_id'] ?? $input['id'] ?? 0);
        if ($accountId <= 0) {
            jsonError('Invalid UPI account ID.');
        }

        $res = UpiManager::setPrimaryUpi((int)$user['id'], $accountId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'] ?? 'Failed to set primary UPI.');
        }
        break;

    case 'delete':
        if ($user['is_impersonating']) {
            jsonError('Removing UPI accounts is disabled in Admin Support Mode.', 403);
        }

        $accountId = (int)($input['upi_account_id'] ?? $input['id'] ?? 0);
        if ($accountId <= 0) {
            jsonError('Invalid UPI account ID.');
        }

        $res = UpiManager::deleteUpiAccount((int)$user['id'], $accountId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'] ?? 'Failed to remove UPI account.');
        }
        break;

    case 'cooldown_status':
        $upiId = trim((string)($input['upi_id'] ?? $_GET['upi_id'] ?? ''));
        $info = UpiManager::getUpiCooldownInfo($upiId);
        jsonResponse(true, $info);
        break;

    default:
        jsonError("Unknown action: {$action}", 400);
        break;
}
