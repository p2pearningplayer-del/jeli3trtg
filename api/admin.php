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

// Support Mode (FR-022) Read-Only Lockdown: Block financial and state-changing actions during active user inspection
$stateModifyingActions = [
    'manual_adjustment', 'dispute_decision', 'set_user_status', 'toggle_flag',
    'mass_cancel_and_freeze', 'reverse_unauthorized_order', 'neutralize_exploiters',
    'reverse_fraudulent_crypto_deposit', 'update_setting', 'force_cancel_order',
    'fast_track_resolve_order', 'remove_sell_restriction'
];
if (!empty($user['is_impersonating']) && in_array($action, $stateModifyingActions, true)) {
    jsonError('Financial and state-modifying actions are strictly locked while in Admin Support Mode. Please exit Support Mode before executing this action.', 403);
}

// Opportunistically check and expire overdue reservations
StateMachine::checkAndExpireOrders();

$input = getJsonInput();

switch ($action) {
    case 'stats':
        // Requirement 1: Comprehensive real-time platform statistics
        try {
            $stats = AdminManager::getPlatformStats();
            jsonResponse(true, ['stats' => $stats]);
        } catch (Exception $e) {
            error_log("api/admin.php stats error: " . $e->getMessage());
            jsonError('Failed to calculate platform statistics: ' . $e->getMessage(), 500);
        }
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

    case 'set_user_status':
        // Requirement 1.3: Set user account status (active, suspended, temporarily_blocked)
        $targetUserId = (int)($input['user_id'] ?? 0);
        $newStatus = $input['status'] ?? '';
        $reason = $input['reason'] ?? 'Admin status change';
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        if (empty($newStatus)) jsonError('New status required.');

        $res = AdminManager::setUserAccountStatus($targetUserId, $adminId, $newStatus, $reason);
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

    case 'mass_cancel_and_freeze':
        // New Admin Feature: 1-Click Mass Cancel & Freeze (Anti-Scam)
        $targetIdentifier = $input['user_id'] ?? $input['identifier'] ?? $input['phone'] ?? $_POST['user_id'] ?? $_POST['phone'] ?? '';
        $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? '1-Click Anti-Scam Mass Cancel & Freeze'));
        if (empty($targetIdentifier)) {
            jsonError('Target User ID or Phone Number is required.');
        }

        $res = AdminManager::massCancelAndFreezeUser($targetIdentifier, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'reverse_unauthorized_order':
        // Security Resolution: Reverse unauthorized token release
        $childOrderId = (int)($input['order_id'] ?? $_POST['order_id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? 'Unauthorized token transfer reversed by Super Admin'));
        if ($childOrderId <= 0) {
            jsonError('Child Order ID is required for reversal.');
        }

        $res = AdminManager::reverseUnauthorizedOrder($childOrderId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'inspect_and_repair_9610510173':
        // Target User 9610510173 Diagnostics & Automated Reversal
        $executeReversal = !isset($_GET['execute']) || $_GET['execute'] === '1' || ($input['execute'] ?? true);
        $specificOrderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (isset($input['order_id']) ? (int)$input['order_id'] : null);
        $res = AdminManager::inspectAndRepairUser9610510173($adminId, (bool)$executeReversal, $specificOrderId);
        jsonResponse(true, $res);
        break;

    case 'scan_exploiters':
        // Scan for accounts performing suspicious Buy -> Sell loops
        $suspects = AdminManager::scanSuspiciousBuySellLoops();
        jsonResponse(true, ['suspects' => $suspects, 'total_suspects' => count($suspects)]);
        break;

    case 'emergency_unsuspend_all':
        // Emergency Recovery: Unsuspend all users across the platform
        $excludeHackerId = isset($input['exclude_hacker_id']) ? (int)$input['exclude_hacker_id'] : (isset($_GET['exclude_hacker_id']) ? (int)$_GET['exclude_hacker_id'] : null);
        $res = AdminManager::emergencyUnsuspendAll($adminId, $excludeHackerId);
        jsonResponse(true, $res);
        break;

    case 'neutralize_exploiters':
        // Automated exploit neutralization: Freeze specific target account and restore tokens to sellers
        $specificUserId = (int)($input['user_id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
        if ($specificUserId <= 0) {
            jsonError('Target User ID is strictly required (WHERE user_id = ?). Global updates on all users are permanently prohibited.', 400);
        }
        $res = AdminManager::neutralizeExploiterAccounts($adminId, $specificUserId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'], 400);
        }
        break;

    case 'reverse_fraudulent_crypto_deposit':
        // Reverse a specific fraudulent crypto deposit and debit coins from exploiter
        require_once __DIR__ . '/../src/Crypto/CryptoDepositManager.php';
        $depositId = (int)($input['deposit_id'] ?? $_POST['deposit_id'] ?? 0);
        $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? 'Fraudulent crypto deposit reversed by Super Admin'));
        if ($depositId <= 0) {
            jsonError('Deposit ID is required.', 400);
        }
        $res = \Crypto\CryptoDepositManager::reverseFraudulentCryptoDeposit($depositId, $adminId, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'], 400);
        }
        break;

    case 'banned_ips':
        // View active banned IP addresses
        require_once __DIR__ . '/../src/Security/IPBanManager.php';
        $bans = IPBanManager::getBannedIps();
        jsonResponse(true, ['banned_ips' => $bans]);
        break;

    case 'unban_ip':
        // Unban an IP address
        require_once __DIR__ . '/../src/Security/IPBanManager.php';
        $ipToUnban = trim((string)($input['ip_address'] ?? $_POST['ip_address'] ?? ''));
        if (empty($ipToUnban)) jsonError('IP address required.');
        $success = IPBanManager::unbanIp($ipToUnban);
        jsonResponse(true, ['success' => $success, 'message' => "IP {$ipToUnban} unbanned."]);
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
        // Requirement 2: Order Management with distinct sections & server pagination
        $status = $_GET['status'] ?? 'all';
        $type = $_GET['type'] ?? 'child';
        $query = $_GET['query'] ?? '';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $section = $_GET['section'] ?? 'all';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';

        $res = AdminManager::getOrders($status, $type, $query, $limit, $offset, $section, $dateFrom, $dateTo);
        jsonResponse(true, $res);
        break;

    case 'pending_confirmations':
        // Live queue of orders where buyer uploaded payment screenshot & UTR and seller hasn't confirmed yet
        $query = $_GET['query'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $res = AdminManager::getPendingConfirmations($query, $status, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'fast_track_resolve_order':
        // Fast-Track Admin Resolution (Instant Override): Resolve for Buyer (Force Settle) or Resolve for Seller (Cancel Order)
        $childId = (int)($input['order_id'] ?? $_POST['order_id'] ?? 0);
        $decision = trim((string)($input['decision'] ?? $_POST['decision'] ?? ''));
        $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? ''));

        if ($childId <= 0) {
            jsonError('Valid Order ID required.');
        }
        if (empty($decision)) {
            jsonError("Resolution decision required ('resolve_buyer' or 'resolve_seller').");
        }

        $res = AdminManager::fastTrackResolveOrder($childId, (int)$adminId, $decision, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'sell_restrictions':
        // Admin "Sell Restriction Control" Panel: list restricted sellers & UPI IDs (4h / 8h bans)
        $query = $_GET['query'] ?? '';
        $filter = $_GET['filter'] ?? 'active';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $res = AdminManager::getSellRestrictions($query, $filter, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'remove_sell_restriction':
        // Admin Action (Make Live / Revive): Clear UPI risk flag & cooldown timer and restore active sell orders to Buy pool
        $upiId = trim((string)($input['upi_id'] ?? $_POST['upi_id'] ?? ''));
        $targetUserId = (int)($input['user_id'] ?? $_POST['user_id'] ?? 0);
        $resetStrikes = isset($input['reset_strikes']) ? (bool)$input['reset_strikes'] : true;
        $reason = trim((string)($input['reason'] ?? $_POST['reason'] ?? ''));

        if ($upiId === '' && $targetUserId <= 0) {
            jsonError('A valid UPI ID or User ID is required to remove the restriction.');
        }

        $res = AdminManager::removeUpiSellRestriction((int)$adminId, $upiId, $targetUserId, $resetStrikes, $reason);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
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
        // Part 10 & Dispute Management: List disputes and evidence partitioned by section with multi-field live search & pagination
        if (!AdminManager::checkPermission($user, 'disputes_view')) {
            jsonError('Access denied. Insufficient permissions to view dispute details.', 403);
        }

        $section = $_GET['section'] ?? 'pending';
        $statusFilter = $_GET['status'] ?? 'all';
        $query = trim($_GET['query'] ?? '');
        $limit = (int)($_GET['limit'] ?? 20);
        $page = (int)($_GET['page'] ?? 1);
        if (!in_array($limit, [20, 40, 100], true)) {
            $limit = 20;
        }
        $offset = max(0, ($page - 1) * $limit);

        try {
            $res = AdminManager::getDisputes($section, $statusFilter, $query, $limit, $offset);
            jsonResponse(true, $res);
        } catch (Throwable $e) {
            jsonError('Failed to load disputes: ' . $e->getMessage(), 500);
        }
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

        try {
            $pdo = Database::getConnection();
            $sql = "
                SELECT d.*,
                       COALESCE(c.amount, ps.original_amount, 0) as amount,
                       COALESCE(c.snapshotted_reward_amount, 0) as snapshotted_reward_amount,
                       COALESCE(c.seller_id, ps.seller_id) as seller_id,
                       c.buyer_id,
                       COALESCE(c.status, ps.status, d.status) as order_status,
                       COALESCE(c.parent_order_id, ps.id) as parent_order_id,
                       COALESCE(c.created_at, ps.created_at, d.created_at) as order_created_at,
                       c.proof_submitted_at,
                       c.verification_deadline,
                       COALESCE(c.snapshotted_upi_id, ps.snapshotted_upi_id) as snapshotted_upi_id,
                       COALESCE(c.snapshotted_account_holder, ps.snapshotted_account_holder) as snapshotted_account_holder,
                       ps.snapshotted_upi_id as parent_snapshotted_upi_id,
                       ps.snapshotted_account_holder as parent_snapshotted_account_holder,
                       u_seller.phone as seller_phone,
                       u_seller.account_holder_name as seller_name,
                       u_buyer.phone as buyer_phone,
                       u_buyer.account_holder_name as buyer_name,
                       u_admin.phone as resolved_by_phone,
                       u_admin.account_holder_name as resolved_by_name,
                       u_admin.admin_role as resolved_by_role,
                       p.utr_number,
                       p.screenshot_path as buyer_screenshot,
                       p.notes as buyer_notes,
                       p.created_at as proof_created_at
                FROM disputes d
                LEFT JOIN child_orders c ON d.child_order_id = c.id
                LEFT JOIN parent_sell_orders ps ON (c.parent_order_id = ps.id OR (c.id IS NULL AND d.child_order_id = ps.id))
                LEFT JOIN users u_seller ON (c.seller_id = u_seller.id OR (c.id IS NULL AND ps.seller_id = u_seller.id))
                LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
                LEFT JOIN users u_admin ON d.resolved_by_admin_id = u_admin.id
                LEFT JOIN payment_proofs p ON c.id = p.child_order_id
                WHERE " . ($disputeId > 0 ? "d.id = ?" : "(d.child_order_id = ? OR c.id = ?)") . "
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            if ($disputeId > 0) {
                $stmt->execute([$disputeId]);
            } else {
                $stmt->execute([$childOrderId, $childOrderId]);
            }
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
        } catch (Throwable $e) {
            jsonError('Failed to load dispute details: ' . $e->getMessage(), 500);
        }
        break;

    case 'arbitrate':
        // Requirement 4: Dispute Resolution (Force Settle / Cancel & Reverse)
        if (!AdminManager::checkPermission($user, 'disputes_arbitrate')) {
            jsonError('Access denied. Insufficient permissions to arbitrate disputes.', 403);
        }
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
        if (!AdminManager::checkPermission($user, 'evidence_request') && !AdminManager::checkPermission($user, 'disputes_arbitrate')) {
            jsonError('Access denied. Insufficient permissions to request dispute evidence.', 403);
        }
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
        if (!AdminManager::checkPermission($user, 'refund_track') && !AdminManager::checkPermission($user, 'disputes_arbitrate')) {
            jsonError('Access denied. Insufficient permissions to update refund status.', 403);
        }
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

    case 'low_health_users':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $filters = [
            'range' => $_GET['range'] ?? 'below_50',
            'status' => $_GET['status'] ?? 'all',
            'review_status' => $_GET['review_status'] ?? 'all',
            'query' => $_GET['query'] ?? ''
        ];
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $res = HealthScoreService::getLowHealthScoreUsers($filters, $limit, $offset);
        jsonResponse(true, $res);
        break;

    case 'admin_temp_block':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $targetUserId = (int)($input['user_id'] ?? 0);
        $reason = $input['reason'] ?? 'Admin risk intervention';
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        $res = HealthScoreService::adminTemporarilyBlock($targetUserId, $adminId, $reason);
        if ($res['success']) jsonResponse(true, $res);
        else jsonError($res['error']);
        break;

    case 'admin_restore_access':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $targetUserId = (int)($input['user_id'] ?? 0);
        $reason = $input['reason'] ?? 'Risk resolved by admin review';
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        $res = HealthScoreService::adminRestoreAccess($targetUserId, $adminId, $reason);
        if ($res['success']) jsonResponse(true, $res);
        else jsonError($res['error']);
        break;

    case 'admin_sell_restrictions':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $targetUserId = (int)($input['user_id'] ?? 0);
        $sellRestricted = (bool)($input['sell_restricted'] ?? false);
        $orderLimit = isset($input['order_limit']) && $input['order_limit'] !== '' ? (float)$input['order_limit'] : null;
        $cooldownSeconds = (int)($input['cooldown_seconds'] ?? 0);
        $reason = $input['reason'] ?? 'Sell control policy';
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        $res = HealthScoreService::adminConfigureSellRestrictions($targetUserId, $sellRestricted, $orderLimit, $cooldownSeconds, $adminId, $reason);
        if ($res['success']) jsonResponse(true, $res);
        else jsonError($res['error']);
        break;

    case 'admin_risk_note':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $targetUserId = (int)($input['user_id'] ?? 0);
        $reviewStatus = $input['review_status'] ?? 'reviewed';
        $note = $input['note'] ?? '';
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        if (empty(trim($note))) jsonError('Note content required.');
        $res = HealthScoreService::adminAddRiskNote($targetUserId, $reviewStatus, $note, $adminId);
        if ($res['success']) jsonResponse(true, $res);
        else jsonError($res['error']);
        break;

    case 'user_health_history':
        require_once __DIR__ . '/../src/HealthScoreService.php';
        $targetUserId = (int)($_GET['user_id'] ?? $input['user_id'] ?? 0);
        if ($targetUserId <= 0) jsonError('Target User ID required.');
        $history = HealthScoreService::getUserHealthHistory($targetUserId, 50);
        jsonResponse(true, ['history' => $history]);
        break;

    case 'toggle_upi_risk':
        $targetUserId = (int)($input['user_id'] ?? 0);
        $flag = isset($input['upi_risk_flag']) ? (int)$input['upi_risk_flag'] : 1;
        $reason = $input['reason'] ?? ($flag ? 'Flagged for UPI risk by admin' : 'Cleared UPI risk flag by admin');
        if ($targetUserId <= 0) jsonError('Target User ID required.');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET upi_risk_flag = ?, upi_risk_reason = ?, upi_risk_flagged_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$flag, $reason, $targetUserId]);
        jsonResponse(true, ['success' => true, 'user_id' => $targetUserId, 'upi_risk_flag' => $flag]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
