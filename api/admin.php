<?php
/**
 * Administrator Governance & Operations API Controller
 * Provides authenticated control for users, orders, disputes, settings, adjustments, and reports.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/response.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';

// Handle CSV export direct download early
if ($action === 'export_csv') {
    $user = Auth::getCurrentUser();
    $adminId = $user['real_admin_id'] ?? ($user && $user['role'] === 'admin' ? $user['id'] : null);
    if (!$adminId) {
        http_response_code(403);
        die('Access denied. Administrator privileges required.');
    }

    $type = $_GET['type'] ?? 'users';
    $csv = AdminManager::exportReportCsv($type);
    $filename = "jelipay_report_{$type}_" . gmdate('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $csv;
    exit;
}

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

// Check admin role or real admin in impersonation mode
$adminId = $user['real_admin_id'] ?? ($user['role'] === 'admin' ? $user['id'] : null);
if (!$adminId) {
    jsonError('Access denied. Administrator privileges required.', 403);
}

// Opportunistically check and expire overdue reservations
StateMachine::checkAndExpireOrders();

$input = getJsonInput();

switch ($action) {
    case 'stats':
        // Requirement 1: Comprehensive real-time platform statistics
        $stats = AdminManager::getPlatformStats();
        jsonResponse(true, ['stats' => $stats]);
        break;

    case 'users':
        // Requirement 2: Search, filter, and list users
        $query = $_GET['query'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $role = $_GET['role'] ?? 'all';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $res = AdminManager::searchUsers($query, $status, $role, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'user_detail':
        // Requirement 2: Complete user profile, dossier & financial history
        $targetUserId = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            jsonError('Target User ID required.');
        }

        $details = AdminManager::getUserDetails($targetUserId);
        if (!$details) {
            jsonError('User not found.', 404);
        }
        jsonResponse(true, $details);
        break;

    case 'toggle_suspension':
        // Requirement 2: Suspend or reactivate user accounts
        $targetUserId = (int)($input['user_id'] ?? 0);
        $reason = $input['reason'] ?? 'Admin action';
        if ($targetUserId <= 0) jsonError('Target User ID required.');

        $res = AdminManager::toggleUserSuspension($targetUserId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'toggle_flag':
        // Requirement 2: Flag or unflag suspicious user
        $targetUserId = (int)($input['user_id'] ?? 0);
        $reason = $input['reason'] ?? 'Flagged by compliance review';
        if ($targetUserId <= 0) jsonError('Target User ID required.');

        $res = AdminManager::toggleUserFlag($targetUserId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'manual_adjustment':
        // Requirement 2: Adjust user token balances with mandatory reason
        $targetUserId = (int)($input['user_id'] ?? 0);
        $type = $input['type'] ?? 'credit'; // 'credit' or 'debit'
        $amount = (float)($input['amount'] ?? 0);
        $reason = $input['reason'] ?? '';

        if ($targetUserId <= 0 || $amount <= 0) {
            jsonError('Valid Target User ID and positive amount are required.');
        }

        if (empty(trim($reason))) {
            jsonError('A mandatory reason is required for balance adjustment.');
        }

        $res = AdminManager::manualAdjustment($targetUserId, $type, $amount, $reason, $adminId);
        if ($res['success']) {
            jsonResponse(true, [
                'message' => 'Manual adjustment executed successfully.',
                'new_available' => $res['new_available']
            ]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'impersonate':
        // Requirement 2: Start read-only user dashboard support inspection
        $targetUserId = (int)($input['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            jsonError('Target User ID required.');
        }

        $res = AdminManager::startImpersonation($adminId, $targetUserId);
        if ($res['success']) {
            jsonResponse(true, [
                'message' => 'Admin support mode started.',
                'target_user' => $res['target_user']
            ]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'exit_impersonate':
        AdminManager::exitImpersonation();
        jsonResponse(true, ['message' => 'Support mode exited.']);
        break;

    case 'orders':
        // Requirement 3: Control all buy and sell orders
        $status = $_GET['status'] ?? 'all';
        $type = $_GET['type'] ?? 'child';
        $query = $_GET['query'] ?? '';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $res = AdminManager::getOrders($status, $type, $query, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'cancel_order':
        // Requirement 3: Admin order cancellation & token reserve release
        $childId = (int)($input['order_id'] ?? 0);
        $reason = $input['reason'] ?? '';

        if ($childId <= 0) jsonError('Valid Order ID required.');
        if (empty(trim($reason))) jsonError('A mandatory reason must be provided for order cancellation.');

        $res = AdminManager::cancelChildOrder($childId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'disputes':
        // Requirement 4 & Part 10 Item 2: List disputes and evidence with live search
        $pdo = Database::getConnection();
        $statusFilter = $_GET['status'] ?? 'all';
        $query = trim($_GET['query'] ?? '');

        $sql = "
            SELECT d.*, c.amount, c.snapshotted_reward_amount, c.seller_id, c.buyer_id, c.status as order_status,
                   u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name,
                   u_buyer.phone as buyer_phone, u_buyer.account_holder_name as buyer_name,
                   p.utr_number, p.screenshot_path as buyer_screenshot, p.notes as buyer_notes
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            LEFT JOIN users u_seller ON c.seller_id = u_seller.id
            LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
        ";
        $whereClauses = [];
        $params = [];

        if ($statusFilter !== 'all') {
            $whereClauses[] = "d.status = :status";
            $params[':status'] = $statusFilter;
        }

        if (!empty($query)) {
            $whereClauses[] = "(
                d.id = :qid
                OR d.child_order_id = :qchild
                OR p.utr_number LIKE :qlike
                OR u_seller.phone LIKE :qlike
                OR u_seller.account_holder_name LIKE :qlike
                OR u_buyer.phone LIKE :qlike
                OR u_buyer.account_holder_name LIKE :qlike
                OR d.reason LIKE :qlike
            )";
            $params[':qid'] = is_numeric($query) ? (int)$query : -1;
            $params[':qchild'] = is_numeric($query) ? (int)$query : -1;
            $params[':qlike'] = "%{$query}%";
        }

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " ORDER BY d.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $paramKey => $paramVal) {
            $stmt->bindValue($paramKey, $paramVal);
        }
        $stmt->execute();
        $disputes = $stmt->fetchAll();

        jsonResponse(true, ['disputes' => $disputes]);
        break;

    case 'arbitrate':
        // Requirement 4: Dispute Resolution (Force Settle / Cancel & Reverse)
        $disputeId = (int)($input['dispute_id'] ?? 0);
        $decision = $input['decision'] ?? '';
        $resolutionNotes = $input['resolution_notes'] ?? '';

        if ($disputeId <= 0 || empty($decision)) {
            jsonError('Dispute ID and decision are required.');
        }

        $res = AdminManager::resolveDispute($disputeId, $adminId, $decision, $resolutionNotes);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'request_evidence':
        // Requirement 4: Request additional evidence from buyer/seller
        $disputeId = (int)($input['dispute_id'] ?? 0);
        $instructions = $input['instructions'] ?? '';

        if ($disputeId <= 0) jsonError('Dispute ID required.');
        if (empty(trim($instructions))) jsonError('Instructions for evidence request are required.');

        $res = AdminManager::requestAdditionalEvidence($disputeId, $adminId, $instructions);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'update_refund':
        // Requirement 4: Track external refund status
        $disputeId = (int)($input['dispute_id'] ?? 0);
        $status = $input['status'] ?? 'pending';
        $refundRef = $input['refund_reference'] ?? null;
        $notes = $input['notes'] ?? 'Refund status updated';

        if ($disputeId <= 0) jsonError('Dispute ID required.');

        $res = AdminManager::updateRefundStatus($disputeId, $adminId, $status, $refundRef, $notes);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'settings':
        // Requirements 5 & 6 & Part 11: Platform variables, content, and branding
        $pdo = Database::getConnection();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $key = trim((string)($input['setting_key'] ?? $_POST['setting_key'] ?? ''));
            $value = (string)($input['setting_value'] ?? $_POST['setting_value'] ?? '');
            $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? 'Admin settings configuration update'));
            if (empty($reason)) {
                $reason = 'Admin settings configuration update';
            }

            if (empty($key)) {
                jsonError('Setting key is required.');
            }

            $res = AdminManager::updateSetting($key, (string)$value, $adminId, $reason);
            if ($res['success']) {
                $freshStmt = $pdo->prepare("SELECT setting_key, setting_value, updated_at FROM platform_settings WHERE setting_key = ?");
                $freshStmt->execute([$key]);
                $fresh = $freshStmt->fetch();
                $res['setting'] = $fresh;
                jsonResponse(true, $res);
            } else {
                jsonError($res['error']);
            }
        } else {
            $stmt = $pdo->query("SELECT * FROM platform_settings ORDER BY setting_key ASC");
            $settings = $stmt->fetchAll();
            jsonResponse(true, ['settings' => $settings]);
        }
        break;

    case 'purge_demo_data':
        // Part 9, Item 4: Push to Live/Production Mode (purge demo accounts and test data)
        $res = AdminManager::purgeDemoData($adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'audit_logs':
        // Requirement 7: Complete administrative audit trail
        $pdo = Database::getConnection();
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $stmt = $pdo->prepare("
            SELECT a.*, u.phone as admin_phone, u.account_holder_name as admin_name
            FROM admin_audit_logs a
            LEFT JOIN users u ON a.admin_id = u.id
            ORDER BY a.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();
        jsonResponse(true, ['audit_logs' => $logs]);
        break;

    case 'all_ledger':
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $ledger = Ledger::getAllLedger($limit, 0);
        jsonResponse(true, ['ledger' => $ledger]);
        break;

    case 'admin_users':
    case 'admins_list':
        // Part 10, Item 4: List administrators & assigned team roles
        $admins = AdminManager::listAdmins();
        jsonResponse(true, ['admins' => $admins]);
        break;

    case 'admin_create':
    case 'create_admin':
    case 'add_admin':
        // Part 10 & 11: Create new administrator account (Super Admin only)
        $caller = Auth::getCurrentUser();
        $isSuperAdmin = ($caller && $caller['role'] === 'admin' && (empty($caller['admin_role']) || $caller['admin_role'] === 'super_admin' || $caller['admin_role'] === 'admin'));
        if (!$isSuperAdmin) {
            jsonError('Super Admin privileges required to create administrators.', 403);
        }

        $phone = $input['phone'] ?? '';
        $password = $input['password'] ?? '';
        $adminRole = $input['admin_role'] ?? 'support_agent';
        $email = $input['email'] ?? null;
        $name = $input['name'] ?? null;

        $res = AdminManager::createAdmin([
            'phone' => $phone,
            'password' => $password,
            'admin_role' => $adminRole,
            'email' => $email,
            'name' => $name
        ], $adminId);

        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'admin_update_role':
        // Part 10, Item 4: Update administrator team role (Super Admin only)
        $caller = Auth::getCurrentUser();
        if (($caller['admin_role'] ?? 'super_admin') !== 'super_admin') {
            jsonError('Super Admin privileges required to modify administrator roles.', 403);
        }

        $targetId = (int)($input['admin_id'] ?? 0);
        $newRole = $input['admin_role'] ?? '';
        if ($targetId <= 0 || empty($newRole)) {
            jsonError('Target Administrator ID and new team role are required.');
        }

        $res = AdminManager::updateAdminRole($targetId, $newRole, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'admin_delete':
        // Part 10, Item 4: Remove administrator (Super Admin only)
        $caller = Auth::getCurrentUser();
        if (($caller['admin_role'] ?? 'super_admin') !== 'super_admin') {
            jsonError('Super Admin privileges required to remove administrators.', 403);
        }

        $targetId = (int)($input['admin_id'] ?? 0);
        if ($targetId <= 0) {
            jsonError('Target Administrator ID is required.');
        }

        $res = AdminManager::deleteAdmin($targetId, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'admin_change_password':
        // Part 10, Item 4: Centralized password change for Super Admins / Admins
        $currentPass = $input['current_password'] ?? '';
        $newPass = $input['new_password'] ?? '';

        if (empty($currentPass) || empty($newPass)) {
            jsonError('Current password and new password are required.');
        }

        $res = AdminManager::changeAdminPassword($adminId, $currentPass, $newPass);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
