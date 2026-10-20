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
require_once __DIR__ . '/../src/AgentManager.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/response.php';

SchemaInstaller::ensureSchema();

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

    case 'impersonate_agent':
        $targetAgentId = (int)($input['agent_id'] ?? 0);
        if ($targetAgentId <= 0) {
            jsonError('Target Agent ID required.');
        }

        $res = AgentManager::startAgentImpersonation((int)$adminId, $targetAgentId);
        if ($res['success']) {
            jsonResponse(true, [
                'message' => 'Agent support mode started.',
                'agent' => $res['agent'],
                'redirect' => $res['redirect']
            ]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'exit_agent_impersonate':
        $res = AgentManager::exitAgentImpersonation();
        jsonResponse(true, [
            'message' => 'Agent support mode exited.',
            'redirect' => $res['redirect']
        ]);
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
        if (!AdminManager::checkPermission($user, 'disputes_view')) {
            jsonError('Access denied. Insufficient permissions to view dispute details.', 403);
        }

        $pdo = Database::getConnection();
        $statusFilter = $_GET['status'] ?? 'all';
        $query = trim($_GET['query'] ?? '');

        $sql = "
            SELECT d.*, c.amount, c.snapshotted_reward_amount, c.seller_id, c.buyer_id, c.status as order_status,
                   c.parent_order_id, c.created_at as order_created_at, c.proof_submitted_at,
                   c.snapshotted_upi_id, c.snapshotted_account_holder,
                   ps.snapshotted_upi_id as parent_snapshotted_upi_id, ps.snapshotted_account_holder as parent_snapshotted_account_holder,
                   u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name,
                   u_buyer.phone as buyer_phone, u_buyer.account_holder_name as buyer_name,
                   p.utr_number, p.screenshot_path as buyer_screenshot, p.notes as buyer_notes,
                   p.created_at as proof_created_at
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            LEFT JOIN parent_sell_orders ps ON c.parent_order_id = ps.id
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
                OR c.snapshotted_upi_id LIKE :qlike
                OR ps.snapshotted_upi_id LIKE :qlike
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

        foreach ($disputes as &$d) {
            // Strictly resolve the exact snapshotted UPI ID used for this specific order
            $childUpi = trim((string)($d['snapshotted_upi_id'] ?? ''));
            $parentUpi = trim((string)($d['parent_snapshotted_upi_id'] ?? ''));
            $resolvedUpi = ($childUpi !== '') ? $childUpi : $parentUpi;

            if ($resolvedUpi !== '') {
                $d['seller_order_upi_id'] = $resolvedUpi;
                $d['has_historical_upi'] = true;
            } else {
                // Strictly do NOT invent or dynamically display current user UPI ID if not in order snapshot
                $d['seller_order_upi_id'] = 'Historical UPI Details Unavailable';
                $d['has_historical_upi'] = false;
            }

            $childHolder = trim((string)($d['snapshotted_account_holder'] ?? ''));
            $parentHolder = trim((string)($d['parent_snapshotted_account_holder'] ?? ''));
            $resolvedHolder = ($childHolder !== '') ? $childHolder : $parentHolder;
            $d['seller_order_account_holder'] = ($resolvedHolder !== '') ? $resolvedHolder : ($d['seller_name'] ?: 'Unavailable');

            $d['payment_method'] = 'UPI';
            $d['proof_submission_timestamp'] = $d['proof_submitted_at'] ?? $d['proof_created_at'] ?? null;
        }
        unset($d);

        jsonResponse(true, ['disputes' => $disputes]);
        break;

    case 'get_dispute':
        if (!AdminManager::checkPermission($user, 'disputes_view')) {
            jsonError('Access denied. Insufficient permissions to view dispute details.', 403);
        }

        $disputeId = (int)($_GET['dispute_id'] ?? $input['dispute_id'] ?? 0);
        $childOrderId = (int)($_GET['child_order_id'] ?? $input['child_order_id'] ?? 0);

        if ($disputeId <= 0 && $childOrderId <= 0) {
            jsonError('Dispute ID or Order ID required.');
        }

        $pdo = Database::getConnection();
        $sql = "
            SELECT d.*, c.amount, c.snapshotted_reward_amount, c.seller_id, c.buyer_id, c.status as order_status,
                   c.parent_order_id, c.created_at as order_created_at, c.proof_submitted_at,
                   c.snapshotted_upi_id, c.snapshotted_account_holder,
                   ps.snapshotted_upi_id as parent_snapshotted_upi_id, ps.snapshotted_account_holder as parent_snapshotted_account_holder,
                   u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name,
                   u_buyer.phone as buyer_phone, u_buyer.account_holder_name as buyer_name,
                   p.utr_number, p.screenshot_path as buyer_screenshot, p.notes as buyer_notes,
                   p.created_at as proof_created_at
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            LEFT JOIN parent_sell_orders ps ON c.parent_order_id = ps.id
            LEFT JOIN users u_seller ON c.seller_id = u_seller.id
            LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            WHERE " . ($disputeId > 0 ? "d.id = ?" : "c.id = ?") . "
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$disputeId > 0 ? $disputeId : $childOrderId]);
        $disp = $stmt->fetch();

        if (!$disp) {
            jsonError('Dispute / Appeal order record not found.', 404);
        }

        $childUpi = trim((string)($disp['snapshotted_upi_id'] ?? ''));
        $parentUpi = trim((string)($disp['parent_snapshotted_upi_id'] ?? ''));
        $resolvedUpi = ($childUpi !== '') ? $childUpi : $parentUpi;

        if ($resolvedUpi !== '') {
            $disp['seller_order_upi_id'] = $resolvedUpi;
            $disp['has_historical_upi'] = true;
        } else {
            $disp['seller_order_upi_id'] = 'Historical UPI Details Unavailable';
            $disp['has_historical_upi'] = false;
        }

        $childHolder = trim((string)($disp['snapshotted_account_holder'] ?? ''));
        $parentHolder = trim((string)($disp['parent_snapshotted_account_holder'] ?? ''));
        $resolvedHolder = ($childHolder !== '') ? $childHolder : $parentHolder;
        $disp['seller_order_account_holder'] = ($resolvedHolder !== '') ? $resolvedHolder : ($disp['seller_name'] ?: 'Unavailable');

        $disp['payment_method'] = 'UPI';
        $disp['proof_submission_timestamp'] = $disp['proof_submitted_at'] ?? $disp['proof_created_at'] ?? null;

        AdminManager::logAudit(
            (int)$adminId,
            'VIEW_DISPUTE_PAYMENT_DETAILS',
            'disputes',
            (string)$disp['id'],
            null,
            ['child_order_id' => $disp['child_order_id'], 'seller_upi_id' => $disp['seller_order_upi_id']],
            'Admin viewed seller payment details for dispute'
        );

        jsonResponse(true, ['dispute' => $disp]);
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

    case 'fraud_assessments':
    case 'fraud_list':
        // Requirement 9: Fraud Dashboard assessment listing
        if (!AdminManager::checkPermission($user, 'fraud_view')) {
            jsonError('Permission denied: fraud_view permission required.', 403);
        }

        $query = $_GET['query'] ?? '';
        $decision = $_GET['decision'] ?? 'all';
        $riskLevel = $_GET['risk_level'] ?? 'all';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $res = AdminManager::getFraudAssessments($query, $decision, $riskLevel, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'fraud_detail':
        // Requirement 9: Fraud assessment detail
        if (!AdminManager::checkPermission($user, 'fraud_view')) {
            jsonError('Permission denied: fraud_view permission required.', 403);
        }

        $assessmentId = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($assessmentId <= 0) {
            jsonError('Assessment ID is required.');
        }

        $detail = AdminManager::getFraudAssessmentDetail($assessmentId);
        if (!$detail) {
            jsonError('Fraud assessment record not found.', 404);
        }
        jsonResponse(true, ['assessment' => $detail]);
        break;

    case 'fraud_review':
        // Requirement 9: Admin fraud assessment review/override action
        if (!AdminManager::checkPermission($user, 'fraud_review')) {
            jsonError('Permission denied: fraud_review permission required.', 403);
        }

        $assessmentId = (int)($input['assessment_id'] ?? 0);
        $reviewAction = trim($input['review_decision'] ?? $input['action'] ?? '');
        $notes = trim($input['notes'] ?? $input['reason'] ?? '');

        if ($assessmentId <= 0 || empty($reviewAction)) {
            jsonError('Assessment ID and review action are required.');
        }

        if (empty($notes)) {
            jsonError('Mandatory review notes are required to document decision reasons.');
        }

        $res = AdminManager::reviewFraudAssessment($assessmentId, $adminId, $reviewAction, $notes);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'list_agents':
        try {
            $agents = AgentManager::listAgents($_GET);
            jsonResponse(true, ['agents' => $agents]);
        } catch (Throwable $e) {
            error_log("api/admin.php list_agents error: " . $e->getMessage());
            jsonResponse(true, ['agents' => []]);
        }
        break;

    case 'get_agent':
        $agentId = (int)($_GET['agent_id'] ?? $input['agent_id'] ?? 0);
        if ($agentId <= 0) {
            jsonError('Agent ID is required.');
        }
        $detail = AgentManager::getAgentDetail($agentId);
        if (!$detail) {
            jsonError('Agent not found.', 404);
        }
        jsonResponse(true, $detail);
        break;

    case 'create_agent':
        $res = AgentManager::createAgent($input, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'update_agent':
        $agentId = (int)($input['agent_id'] ?? 0);
        if ($agentId <= 0) {
            jsonError('Agent ID is required.');
        }
        $res = AgentManager::updateAgent($agentId, $input, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'reset_agent_password':
        $agentId = (int)($input['agent_id'] ?? 0);
        if ($agentId <= 0) {
            jsonError('Agent ID is required.');
        }
        $res = AgentManager::resetPassword($agentId, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'reset_agent_totp':
        $agentId = (int)($input['agent_id'] ?? 0);
        if ($agentId <= 0) {
            jsonError('Agent ID is required.');
        }
        $res = AgentManager::resetTotp($agentId, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'toggle_agent_status':
        $agentId = (int)($input['agent_id'] ?? 0);
        $reason = $input['reason'] ?? 'Admin status toggle';
        if ($agentId <= 0) {
            jsonError('Agent ID is required.');
        }
        $res = AgentManager::toggleStatus($agentId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'list_agent_statements':
        try {
            $statements = AgentManager::listAdminStatements($_GET);
            jsonResponse(true, ['statements' => $statements]);
        } catch (Throwable $e) {
            error_log("api/admin.php list_agent_statements error: " . $e->getMessage());
            jsonResponse(true, ['statements' => []]);
        }
        break;

    case 'generate_agent_statements':
        $year = !empty($input['year']) ? (int)$input['year'] : null;
        $week = !empty($input['week']) ? (int)$input['week'] : null;
        $targetAgentId = !empty($input['agent_id']) ? (int)$input['agent_id'] : null;
        $res = AgentManager::generateWeeklyStatements($year, $week, $targetAgentId);
        jsonResponse(true, $res);
        break;

    case 'adjust_agent_statement':
        $statementId = (int)($input['statement_id'] ?? 0);
        $adjAmount = (float)($input['adjustment_amount'] ?? 0);
        $reason = trim((string)($input['reason'] ?? ''));
        if ($statementId <= 0 || empty($reason)) {
            jsonError('Statement ID and mandatory adjustment reason are required.');
        }
        $res = AgentManager::adjustStatement($statementId, $adjAmount, $reason, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'approve_agent_statement':
        $statementId = (int)($input['statement_id'] ?? 0);
        if ($statementId <= 0) {
            jsonError('Statement ID is required.');
        }
        $res = AgentManager::approveStatement($statementId, $adminId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'payout_agent_statement':
        $statementId = (int)($input['statement_id'] ?? 0);
        $payoutRef = trim((string)($input['payout_reference'] ?? ''));
        $paidDate = trim((string)($input['paid_date'] ?? ''));
        $notes = $input['notes'] ?? null;
        if ($statementId <= 0 || empty($payoutRef)) {
            jsonError('Statement ID and payout payment reference (UTR) are required.');
        }
        $res = AgentManager::recordPayout($statementId, $payoutRef, $paidDate, $adminId, $notes);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'agent_audit_logs':
        $targetAgentId = !empty($_GET['agent_id']) ? (int)$_GET['agent_id'] : null;
        $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $logs = AgentManager::getAuditLogs($targetAgentId, $limit);
        jsonResponse(true, ['logs' => $logs]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
