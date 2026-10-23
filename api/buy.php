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
        // Display available child portions to buyers (Requirement 8 Privacy)
        // Seller details AND Order IDs are strictly hidden pre-pickup from public browsable cards.
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.id, c.amount, c.snapshotted_reward_rate,
                   c.snapshotted_reward_amount, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   c.created_at, c.seller_id
            FROM child_orders c
            WHERE c.status = 'Available' AND c.seller_id != ?
            ORDER BY c.amount ASC, c.id ASC
            LIMIT 50
        ");
        $stmt->execute([(int)$user['id']]);
        $orders = $stmt->fetchAll();

        $poolList = [];
        $secret = defined('JWT_SECRET') ? JWT_SECRET : 'jelipay_secure_token_secret_key';
        foreach ($orders as $ord) {
            $childId = (int)$ord['id'];
            // Generate HMAC signed pickup token without leaking raw ID in plain UI
            $tokenPayload = $childId . ':' . hash_hmac('sha256', (string)$childId, $secret);
            $pickToken = base64_encode($tokenPayload);

            $poolList[] = [
                'pick_token' => $pickToken,
                'amount' => (float)$ord['amount'],
                'reward_rate' => (float)$ord['snapshotted_reward_rate'],
                'reward_amount' => (float)$ord['snapshotted_reward_amount'],
                'total_credit' => (float)$ord['total_credit']
            ];
        }

        jsonResponse(true, ['orders' => $poolList]);
        break;

    case 'pick':
        if ($user['is_impersonating']) {
            jsonError('Order reservation is disabled in Admin Support Mode.', 403);
        }

        $orderId = 0;
        $secret = defined('JWT_SECRET') ? JWT_SECRET : 'jelipay_secure_token_secret_key';

        // 1. Resolve pick_token if provided
        $pickToken = trim((string)($input['pick_token'] ?? ''));
        if (!empty($pickToken)) {
            $decoded = base64_decode($pickToken);
            if ($decoded && strpos($decoded, ':') !== false) {
                [$rawId, $sig] = explode(':', $decoded, 2);
                if (hash_equals(hash_hmac('sha256', $rawId, $secret), $sig)) {
                    $orderId = (int)$rawId;
                }
            }
        }

        // 2. Direct order_id fallback (for existing test suite & authorized direct picking)
        if ($orderId <= 0 && isset($input['order_id'])) {
            $orderId = (int)$input['order_id'];
        }

        if ($orderId <= 0) {
            jsonError('Invalid order reservation request.');
        }

        $res = StateMachine::pickOrder($orderId, $user['id']);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            $statusCode = ($res['error'] === 'You cannot purchase your own sell order.') ? 403 : 409;
            jsonError($res['error'], $statusCode, $res);
        }
        break;

    case 'cancel':
        if ($user['is_impersonating']) {
            jsonError('Order cancellation is disabled in Admin Support Mode.', 403);
        }

        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) {
            jsonError('Invalid order ID.');
        }

        $res = StateMachine::cancelReservation($orderId, $user['id']);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
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
            jsonError($res['error'], 400, $res);
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

    case 'order_details':
        $orderId = (int)($_GET['order_id'] ?? $input['order_id'] ?? 0);
        if ($orderId <= 0) {
            jsonError('Invalid order ID.');
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.*, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at,
                   d.status as dispute_status, d.reason as dispute_reason
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE c.id = ? AND (c.buyer_id = ? OR c.seller_id = ? OR ? = 1)
            LIMIT 1
        ");
        $stmt->execute([$orderId, (int)$user['id'], (int)$user['id'], $user['role'] === 'admin' ? 1 : 0]);
        $order = $stmt->fetch();

        if (!$order) {
            jsonError('Order not found or unauthorized access.', 404);
        }

        $order = formatChildOrderResponse($order, time());
        jsonResponse(true, ['order' => $order]);
        break;

    case 'active_order':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.*, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at,
                   d.status as dispute_status, d.reason as dispute_reason
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE c.buyer_id = ? AND c.status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Disputed')
            ORDER BY c.id DESC
            LIMIT 1
        ");
        $stmt->execute([(int)$user['id']]);
        $activeOrder = $stmt->fetch();

        if (!$activeOrder) {
            jsonResponse(true, ['has_active' => false, 'order' => null]);
        }

        $activeOrder = formatChildOrderResponse($activeOrder, time());
        jsonResponse(true, ['has_active' => true, 'order' => $activeOrder]);
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
        $formattedPurchases = [];
        foreach ($purchases as $p) {
            $formattedPurchases[] = formatChildOrderResponse($p, $now);
        }

        jsonResponse(true, ['purchases' => $formattedPurchases]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}

function formatChildOrderResponse(array $p, int $now): array {
    $p['id'] = (int)$p['id'];
    $p['parent_order_id'] = (int)$p['parent_order_id'];
    $p['seller_id'] = (int)$p['seller_id'];
    $p['buyer_id'] = !empty($p['buyer_id']) ? (int)$p['buyer_id'] : null;
    $p['amount'] = (float)$p['amount'];
    $p['reward_amount'] = (float)($p['snapshotted_reward_amount'] ?? ($p['amount'] * 0.09));
    $p['snapshotted_reward_amount'] = (float)($p['snapshotted_reward_amount'] ?? ($p['amount'] * 0.09));
    $p['total_credit'] = (float)($p['total_credit'] ?? ($p['amount'] + $p['reward_amount']));

    $p['seller_upi_id'] = $p['snapshotted_upi_id'] ?? '';
    $p['seller_account_holder'] = $p['snapshotted_account_holder'] ?? '';
    $p['seller_upi'] = $p['snapshotted_upi_id'] ?? '';
    $p['seller_name'] = $p['snapshotted_account_holder'] ?? '';
    $p['seller_name_masked'] = Validator::maskName($p['seller_account_holder']);

    if ($p['status'] === 'Reserved' && !empty($p['payment_deadline'])) {
        $deadline = strtotime($p['payment_deadline'] . ' UTC');
        $p['seconds_remaining'] = max(0, $deadline - $now);
    } elseif ($p['status'] === 'Seller Confirmation Pending' && !empty($p['verification_deadline'])) {
        $deadline = strtotime($p['verification_deadline'] . ' UTC');
        $p['seconds_remaining'] = max(0, $deadline - $now);
    } else {
        $p['seconds_remaining'] = 0;
    }

    // Part 12, Item 3: Screenshot Privacy & History Security
    $isFinal = in_array(strtolower($p['status'] ?? ''), ['completed', 'cancelled', 'expired', 'cancelled / reversed']);
    $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') || !empty($_SESSION['real_admin_id']);
    if ($isFinal && !$isAdmin) {
        $p['screenshot_path'] = null;
    }

    return $p;
}
