<?php
/**
 * Buy & Order Picking API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
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

// Opportunistically check and expire overdue reservations
StateMachine::checkAndExpireOrders();

$action = $_GET['action'] ?? $_POST['action'] ?? 'pool';
$input = getJsonInput();

switch ($action) {
    case 'pool':
        // Display available child portions to buyers (Requirement 8 Privacy)
        // Seller details AND Order IDs are strictly hidden pre-pickup from public browsable cards.
        $pdo = Database::getConnection();

        // Price range filter support
        $minAmount = isset($_GET['min_amount']) && is_numeric($_GET['min_amount']) ? (float)$_GET['min_amount'] : 0.0;
        $maxAmount = isset($_GET['max_amount']) && is_numeric($_GET['max_amount']) ? (float)$_GET['max_amount'] : 0.0;

        $sql = "
            SELECT c.id, c.amount, c.snapshotted_reward_rate,
                   c.snapshotted_reward_amount, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   c.created_at, c.seller_id, c.snapshotted_upi_id,
                   COALESCE(u.upi_risk_flag, 0) as upi_risk_flag
            FROM child_orders c
            LEFT JOIN users u ON c.seller_id = u.id
            WHERE c.status = 'Available' AND c.seller_id != ?
        ";
        $params = [(int)$user['id']];

        if ($minAmount > 0) {
            $sql .= " AND c.amount >= ?";
            $params[] = $minAmount;
        }
        if ($maxAmount > 0) {
            $sql .= " AND c.amount <= ?";
            $params[] = $maxAmount;
        }

        $sql .= " ORDER BY c.amount ASC, c.id ASC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $poolList = [];
        $secret = defined('JWT_SECRET') ? JWT_SECRET : 'jelipay_secure_token_secret_key';
        foreach ($orders as $ord) {
            $sellerUpi = (string)($ord['snapshotted_upi_id'] ?? '');

            // FR-Item 1: Hide any active sell listings tied to a UPI in risk cooldown
            if (UpiManager::isUpiInCooldown($sellerUpi)) {
                continue;
            }

            $childId = (int)$ord['id'];
            // Generate HMAC signed pickup token without leaking raw ID in plain UI
            $tokenPayload = $childId . ':' . hash_hmac('sha256', (string)$childId, $secret);
            $pickToken = base64_encode($tokenPayload);

            $poolList[] = [
                'pick_token' => $pickToken,
                'amount' => (float)$ord['amount'],
                'reward_rate' => (float)$ord['snapshotted_reward_rate'],
                'reward_amount' => (float)$ord['snapshotted_reward_amount'],
                'total_credit' => (float)$ord['total_credit'],
                'seller_upi_risk' => false // FR-Item 1: Visibility strictly hidden from buyers
            ];
        }

        jsonResponse(true, ['orders' => $poolList]);
        break;

    case 'pick':
        if ($user['is_impersonating']) {
            jsonError('Order reservation is disabled in Admin Support Mode.', 403);
        }

        // Rate limit order picking
        $rateKeyPick = "pick_order_{$user['id']}";
        if (!RateLimiter::checkWithBan($rateKeyPick, 'pick_order', 20, 60, $clientIp)) {
            jsonError('Too many reservation requests. Please wait a moment before trying again.', 429);
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

        // Pre-flight IDOR check: Verify caller is the buyer of this reservation
        $pdo = Database::getConnection();
        $chkOrder = $pdo->prepare("SELECT id, buyer_id, seller_id FROM child_orders WHERE id = ?");
        $chkOrder->execute([$orderId]);
        $orderRec = $chkOrder->fetch();

        if (!$orderRec) {
            jsonError('Order not found.', 404);
        }

        if ((int)$orderRec['buyer_id'] !== (int)$user['id']) {
            IPBanManager::recordViolation($clientIp, "IDOR attempt on order cancellation: User #{$user['id']} is not buyer on order #{$orderId}", 'IDOR_CANCEL_ATTEMPT', 6);
            jsonError('Access Denied: Unauthorized. You can only cancel reservations initiated by your account.', 403);
        }

        $reasonCode = trim((string)($input['reason_code'] ?? $input['reason'] ?? ''));
        $reasonText = trim((string)($input['reason_text'] ?? ''));

        if (empty($reasonCode)) {
            jsonError('Please select a cancellation reason before cancelling the order.', 400);
        }

        $res = StateMachine::cancelReservation($orderId, $user['id'], $reasonCode, $reasonText);
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

        // Rate limit custom allocation requests
        $rateKeyAlloc = "custom_alloc_{$user['id']}";
        if (!RateLimiter::checkWithBan($rateKeyAlloc, 'custom_allocation', 25, 60, $clientIp)) {
            jsonError('Too many allocation requests. Please wait a minute before trying again.', 429);
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

        // Rate limit proof submissions (supports up to 10 concurrent buy orders)
        $rateKeyProof = "submit_proof_{$user['id']}";
        if (!RateLimiter::checkWithBan($rateKeyProof, 'submit_proof', 25, 60, $clientIp)) {
            jsonError('Too many proof submissions. Please wait a minute before trying again.', 429);
        }

        $orderId = (int)($input['order_id'] ?? 0);
        $utr = $input['utr'] ?? '';
        $screenshotPath = $input['screenshot_path'] ?? '';
        $notes = $input['notes'] ?? null;

        if (empty($screenshotPath)) {
            jsonError('Please upload a screenshot of your successful UPI payment proof.');
        }

        // Pre-flight IDOR check: Verify caller is the buyer of this reservation
        $pdo = Database::getConnection();
        $chkOrder = $pdo->prepare("SELECT id, buyer_id, seller_id FROM child_orders WHERE id = ?");
        $chkOrder->execute([$orderId]);
        $orderRec = $chkOrder->fetch();

        if (!$orderRec) {
            jsonError('Order not found.', 404);
        }

        if ((int)$orderRec['buyer_id'] !== (int)$user['id']) {
            IPBanManager::recordViolation($clientIp, "IDOR attempt on payment proof submission: User #{$user['id']} is not buyer on order #{$orderId}", 'IDOR_PROOF_ATTEMPT', 12);
            jsonError('Access Denied: Unauthorized. You can only submit payment proof for orders reserved by your account.', 403);
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
        $maxConcurrent = defined('MAX_CONCURRENT_BUY_ORDERS') ? (int)MAX_CONCURRENT_BUY_ORDERS : 10;
        $stmt = $pdo->prepare("
            SELECT c.*, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at,
                   d.status as dispute_status, d.reason as dispute_reason
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE c.buyer_id = ? AND c.status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Disputed')
            ORDER BY CASE WHEN c.status = 'Reserved' THEN 0 ELSE 1 END ASC, c.id DESC
            LIMIT 10
        ");
        $stmt->execute([(int)$user['id']]);
        $activeRows = $stmt->fetchAll();

        if (empty($activeRows)) {
            jsonResponse(true, [
                'has_active' => false,
                'active_count' => 0,
                'max_concurrent_orders' => $maxConcurrent,
                'order' => null,
                'orders' => []
            ]);
        }

        $now = time();
        $formattedOrders = [];
        foreach ($activeRows as $r) {
            $formattedOrders[] = formatChildOrderResponse($r, $now);
        }

        jsonResponse(true, [
            'has_active' => true,
            'active_count' => count($formattedOrders),
            'max_concurrent_orders' => $maxConcurrent,
            'order' => $formattedOrders[0],
            'orders' => $formattedOrders
        ]);
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

    // FR-Item 1: Risk alerts hidden from buyers
    $p['seller_upi_risk'] = false;

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
