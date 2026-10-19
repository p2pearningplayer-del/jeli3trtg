<?php
/**
 * Agent Portal API Controller
 * Provides secure endpoints for Agent authentication (User ID + Password + 6-digit TOTP),
 * profile verification, dashboard metrics, filterable purchase logs, and weekly statements.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/AgentManager.php';
require_once __DIR__ . '/response.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

if ($action === 'login') {
    $agentCode = trim($input['agent_code'] ?? '');
    $password = (string)($input['password'] ?? '');
    $totpCode = trim((string)($input['totp_code'] ?? ''));

    if (empty($agentCode) || empty($password) || empty($totpCode)) {
        jsonError('Agent User ID, Password, and 6-digit Authenticator Code are required.');
    }

    $res = AgentManager::authenticate($agentCode, $password, $totpCode);
    if ($res['success']) {
        jsonResponse(true, [
            'message' => 'Agent login successful.',
            'agent' => $res['agent'],
            'redirect' => '/agent/dashboard'
        ]);
    } else {
        jsonError($res['error'], 401);
    }
}

if ($action === 'logout') {
    AgentManager::agentLogout();
    jsonResponse(true, ['message' => 'Agent session ended.', 'redirect' => '/agent/login']);
}

// For all other actions, require an authenticated agent session
$agent = AgentManager::getCurrentAgent();
if (!$agent) {
    jsonError('Authentication required. Please log in as an Agent.', 401);
}

$agentId = (int)$agent['id'];

switch ($action) {
    case 'me':
        jsonResponse(true, [
            'agent' => [
                'id' => (int)$agent['id'],
                'agent_code' => $agent['agent_code'],
                'name' => $agent['name'],
                'phone' => $agent['phone'],
                'email' => $agent['email'],
                'commission_rate' => (float)$agent['commission_rate'],
                'eligible_levels' => $agent['eligible_levels'],
                'must_change_password' => (int)$agent['must_change_password'],
                'last_login_at' => $agent['last_login_at']
            ]
        ]);
        break;

    case 'dashboard':
        $stats = AgentManager::getAgentDashboardStats($agentId);
        jsonResponse(true, ['stats' => $stats]);
        break;

    case 'purchases':
        $filters = [
            'level' => $_GET['level'] ?? '',
            'status' => $_GET['status'] ?? 'earned',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'order_id' => $_GET['order_id'] ?? '',
            'limit' => $_GET['limit'] ?? 50,
            'offset' => $_GET['offset'] ?? 0
        ];
        $purchases = AgentManager::getAgentPurchases($agentId, $filters);
        jsonResponse(true, $purchases);
        break;

    case 'statements':
        $statements = AgentManager::getAgentStatements($agentId);
        jsonResponse(true, ['statements' => $statements]);
        break;

    case 'change_password':
        $currentPassword = (string)($input['current_password'] ?? '');
        $newPassword = (string)($input['new_password'] ?? '');
        $totpCode = trim((string)($input['totp_code'] ?? ''));

        if (empty($currentPassword) || empty($newPassword) || empty($totpCode)) {
            jsonError('Current password, new password, and 6-digit Authenticator code are required.');
        }

        $res = AgentManager::agentChangePassword($agentId, $currentPassword, $newPassword, $totpCode);
        if ($res['success']) {
            jsonResponse(true, ['message' => $res['message']]);
        } else {
            jsonError($res['error']);
        }
        break;

    default:
        jsonError("Unknown or unsupported action '{$action}'.", 400);
}
