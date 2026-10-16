<?php
/**
 * Buy & Order Picking API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/response.php';

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

// Opportunistically check and expire overdue reservations
StateMachine::checkAndExpireOrders();

$action = $_GET['action'] ?? $_POST['action'] ?? 'pool';
$input = getJsonInput();

switch ($action) {
    case 'pool':
        // Display available child portions to buyers (FR-006)
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.id, c.parent_order_id, c.amount, c.snapshotted_reward_rate,
                   c.snapshotted_reward_amount, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   c.snapshotted_upi_id, c.snapshotted_account_holder, c.created_at,
                   c.seller_id
            FROM child_orders c
            WHERE c.status = 'Available' AND c.seller_id != ?
            ORDER BY c.amount ASC, c.id ASC
            LIMIT 50
        ");
        $stmt->execute([$user['id']]);
        $orders = $stmt->fetchAll();

        foreach ($orders as &$ord) {
            $ord['amount'] = (float)$ord['amount'];
            $ord['reward_amount'] = (float)$ord['snapshotted_reward_amount'];
            $ord['total_credit'] = (float)$ord['total_credit'];
            $ord['reward_rate'] = (float)$ord['snapshotted_reward_rate'];
            $ord['seller_name_masked'] = Validator::maskName($ord['snapshotted_account_holder']);
        }

        jsonResponse(true, ['orders' => $orders]);
        break;

    case 'pick':
        if ($user['is_impersonating']) {
            jsonError('Order reservation is disabled in Admin Support Mode.', 403);
        }

        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) {
            jsonError('Invalid order ID.');
        }

        $res = StateMachine::pickOrder($orderId, $user['id']);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error'], 409);
        }
        break;

    case 'custom_allocation':
        if ($user['is_impersonating']) {
            jsonError('Order allocation is disabled in Admin Support Mode.', 403);
        }

        $amount = (float)($input['amount'] ?? 0);
        $res = OrderEngine::allocateCustomBuy($user['id'], $amount);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'submit_proof':
        if ($user['is_impersonating']) {
            jsonError('Proof submission is disabled in Admin Support Mode.', 403);
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $utr = $input['utr'] ?? '';
        $screenshotPath = $input['screenshot_path'] ?? '';
        $notes = $input['notes'] ?? null;

        if (empty($screenshotPath)) {
            jsonError('Please upload a screenshot of your successful UPI payment proof.');
        }

        $res = StateMachine::submitPaymentProof($orderId, $user['id'], $utr, $screenshotPath, $notes);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'my_purchases':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.*, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at,
                   d.status as dispute_status, d.reason as dispute_reason
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE c.buyer_id = ?
            ORDER BY c.id DESC
            LIMIT 50
        ");
        $stmt->execute([$user['id']]);
        $purchases = $stmt->fetchAll();

        $now = time();
        foreach ($purchases as &$p) {
            $p['amount'] = (float)$p['amount'];
            $p['reward_amount'] = (float)$p['snapshotted_reward_amount'];
            $p['total_credit'] = (float)$p['total_credit'];

            if ($p['status'] === 'Reserved' && !empty($p['payment_deadline'])) {
                $deadline = strtotime($p['payment_deadline'] . ' UTC');
                $p['seconds_remaining'] = max(0, $deadline - $now);
            } elseif ($p['status'] === 'Seller Confirmation Pending' && !empty($p['verification_deadline'])) {
                $deadline = strtotime($p['verification_deadline'] . ' UTC');
                $p['seconds_remaining'] = max(0, $deadline - $now);
            } else {
                $p['seconds_remaining'] = 0;
            }
        }

        jsonResponse(true, ['purchases' => $purchases]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
