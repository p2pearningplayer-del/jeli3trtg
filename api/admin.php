<?php
/**
 * Administrator Governance & Arbitration API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/response.php';

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

$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';
$input = getJsonInput();

switch ($action) {
    case 'stats':
        // FR-020 Macro metrics for platform health
        $stats = AdminManager::getPlatformStats();
        jsonResponse(true, ['stats' => $stats]);
        break;

    case 'disputes':
        $pdo = Database::getConnection();
        $statusFilter = $_GET['status'] ?? 'all';

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
        if ($statusFilter !== 'all') {
            $sql .= " WHERE d.status = :status";
        }
        $sql .= " ORDER BY d.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        if ($statusFilter !== 'all') {
            $stmt->bindValue(':status', $statusFilter);
        }
        $stmt->execute();
        $disputes = $stmt->fetchAll();

        jsonResponse(true, ['disputes' => $disputes]);
        break;

    case 'arbitrate':
        // FR-018 Dispute Resolution
        $disputeId = (int)($input['dispute_id'] ?? 0);
        $decision = $input['decision'] ?? ''; // 'force_settle' or 'cancel_reverse'
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

    case 'manual_adjustment':
        // FR-021 Manual Adjustments
        $targetUserId = (int)($input['user_id'] ?? 0);
        $type = $input['type'] ?? 'credit'; // 'credit' or 'debit'
        $amount = (float)($input['amount'] ?? 0);
        $reason = $input['reason'] ?? '';

        if ($targetUserId <= 0 || $amount <= 0) {
            jsonError('Valid Target User ID and positive amount are required.');
        }

        $res = AdminManager::manualAdjustment($targetUserId, $type, $amount, $reason, $adminId);
        if ($res['success']) {
            jsonResponse(true, ['message' => 'Manual adjustment executed successfully.', 'new_available' => $res['new_available']]);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'impersonate':
        // FR-022 Admin Impersonation
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

    case 'users':
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT u.id, u.phone, u.role, u.account_status, u.referral_code, u.referred_by_id,
                   u.upi_id, u.account_holder_name, u.created_at,
                   w.available_balance, w.reserved_balance, w.total_rewards_earned, w.total_commissions_earned
            FROM users u
            LEFT JOIN wallets w ON u.id = w.user_id
            ORDER BY u.id DESC
            LIMIT 100
        ");
        $users = $stmt->fetchAll();
        jsonResponse(true, ['users' => $users]);
        break;

    case 'toggle_suspension':
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

    case 'settings':
        $pdo = Database::getConnection();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // FR-023 Global Configurations
            $key = $input['setting_key'] ?? '';
            $value = $input['setting_value'] ?? '';
            $reason = $input['reason'] ?? 'Admin config update';

            $res = AdminManager::updateSetting($key, $value, $adminId, $reason);
            if ($res['success']) {
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

    case 'audit_logs':
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT a.*, u.phone as admin_phone
            FROM admin_audit_logs a
            LEFT JOIN users u ON a.admin_id = u.id
            ORDER BY a.id DESC
            LIMIT 100
        ");
        $logs = $stmt->fetchAll();
        jsonResponse(true, ['audit_logs' => $logs]);
        break;

    case 'all_ledger':
        $ledger = Ledger::getAllLedger(100, 0);
        jsonResponse(true, ['ledger' => $ledger]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
