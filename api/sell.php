<?php
/**
 * Sell Orders & Seller Tracking API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/Security/IPBanManager.php';
require_once __DIR__ . '/response.php';

$clientIp = IPBanManager::getClientIp();

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

if ($user['account_status'] === 'suspended') {
    jsonError('Access Denied: Your account is suspended.', 403);
}

// Opportunistically check and expire overdue reservations
StateMachine::checkAndExpireOrders();

$action = $_GET['action'] ?? $_POST['action'] ?? 'tracking';
$input = getJsonInput();

switch ($action) {
    case 'create_order':
        if ($user['is_impersonating']) {
            jsonError('Order creation is disabled in Admin Support Mode.', 403);
        }

        // Rate limit order creation: 15 per minute
        $rateKeyCreate = "create_sell_{$user['id']}";
        if (!RateLimiter::checkWithBan($rateKeyCreate, 'create_sell_order', 15, 60, $clientIp)) {
            jsonError('Too many sell order requests. Please wait a minute before creating new orders.', 429);
        }

        $amount = (float)($input['amount'] ?? 0);
        $tpin = (string)($input['tpin'] ?? '');
        $accountHolderName = isset($input['account_holder_name']) ? trim((string)$input['account_holder_name']) : null;
        $upiId = isset($input['upi_id']) ? trim((string)$input['upi_id']) : null;
        $upiAccountId = isset($input['upi_account_id']) ? (int)$input['upi_account_id'] : null;

        if ($upiAccountId && empty($upiId)) {
            $pdo = Database::getConnection();
            $accStmt = $pdo->prepare("SELECT * FROM user_upi_accounts WHERE id = ? AND user_id = ?");
            $accStmt->execute([$upiAccountId, (int)$user['id']]);
            $acc = $accStmt->fetch(PDO::FETCH_ASSOC);
            if ($acc) {
                $upiId = $acc['upi_id'];
                if (empty($accountHolderName)) {
                    $accountHolderName = $acc['account_holder_name'];
                }
            }
        }

        if (empty($tpin)) {
            jsonError('Selling TPIN is required to authorize token listings.');
        }

        $res = OrderEngine::createSellOrder($user['id'], $amount, $tpin, $accountHolderName, $upiId);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'tracking':
        // Visibility into Parent-Child order lifecycle (FR-014)
        $trackingData = OrderEngine::getSellerTrackingData($user['id']);
        $now = time();

        // Calculate active timers for seller tracking
        foreach ($trackingData as &$parent) {
            foreach ($parent['children'] as &$child) {
                if ($child['status'] === 'Reserved' && !empty($child['payment_deadline'])) {
                    $deadline = strtotime($child['payment_deadline'] . ' UTC');
                    $child['seconds_remaining'] = max(0, $deadline - $now);
                } elseif ($child['status'] === 'Seller Confirmation Pending' && !empty($child['verification_deadline'])) {
                    $deadline = strtotime($child['verification_deadline'] . ' UTC');
                    $child['seconds_remaining'] = max(0, $deadline - $now);
                } else {
                    $child['seconds_remaining'] = 0;
                }
            }
        }

        jsonResponse(true, ['parent_orders' => $trackingData]);
        break;

    case 'confirm_payment':
        if ($user['is_impersonating']) {
            jsonError('Settlement execution is disabled in Admin Support Mode.', 403);
        }

        // Strict rate limiting: Max 5 attempts per 5 minutes per user & per IP
        $rateKeyUser = "confirm_pay_u_{$user['id']}";
        $rateKeyIp = "confirm_pay_ip_{$clientIp}";
        if (!RateLimiter::checkWithBan($rateKeyUser, 'confirm_payment', 5, 300, $clientIp) ||
            !RateLimiter::checkWithBan($rateKeyIp, 'confirm_payment', 5, 300, $clientIp)) {
            jsonError('Too many confirmation attempts. Please wait 5 minutes before trying again.', 429);
        }

        $childOrderId = (int)($input['order_id'] ?? $_POST['order_id'] ?? 0);
        $tpin = trim((string)($input['tpin'] ?? $_POST['tpin'] ?? ''));
        $idempotencyKey = $input['idempotency_key'] ?? $_POST['idempotency_key'] ?? null;

        if ($childOrderId <= 0) {
            jsonError('Invalid order ID.');
        }

        if (empty($tpin)) {
            jsonError('Selling T-PIN is strictly required to authorize payment confirmation and token release.', 400);
        }

        // Pre-flight IDOR check: Verify caller is the authentic seller
        $pdo = Database::getConnection();
        $chkOrderStmt = $pdo->prepare("SELECT id, seller_id, buyer_id, status FROM child_orders WHERE id = ?");
        $chkOrderStmt->execute([$childOrderId]);
        $orderRecord = $chkOrderStmt->fetch();

        if (!$orderRecord) {
            jsonError('Order not found.', 404);
        }

        // IDOR Check 1: Buyer attempting to confirm their own buy order
        if ((int)$orderRecord['buyer_id'] === (int)$user['id']) {
            require_once __DIR__ . '/../src/AdminManager.php';
            AdminManager::logAudit(
                0,
                'SECURITY_ALERT_IDOR',
                'child_orders',
                (string)$childOrderId,
                ['status' => $orderRecord['status']],
                ['user_id' => $user['id'], 'ip' => $clientIp, 'action' => 'confirm_payment'],
                'Critical IDOR violation: Buyer attempted to confirm/settle order'
            );
            jsonError('Access Denied: Insecure Direct Object Reference (IDOR) detected. Buyers cannot confirm order settlement.', 403);
        }

        // IDOR Check 2: Unauthorized third party who is not the seller
        if ((int)$orderRecord['seller_id'] !== (int)$user['id']) {
            jsonError('Access Denied: Unauthorized. This sell order does not belong to your account.', 403);
        }

        $res = StateMachine::confirmPayment($childOrderId, (int)$user['id'], $tpin, $idempotencyKey);
        if ($res['success']) {
            RateLimiter::reset($rateKeyUser, 'confirm_payment');
            RateLimiter::reset($rateKeyIp, 'confirm_payment');
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'raise_appeal':
        if ($user['is_impersonating']) {
            jsonError('Dispute filing is disabled in Admin Support Mode.', 403);
        }

        $childOrderId = (int)($input['order_id'] ?? 0);
        $reason = $input['reason'] ?? '';
        $evidencePath = $input['evidence_path'] ?? null;

        if ($childOrderId <= 0) {
            jsonError('Invalid order ID.');
        }

        // Pre-flight IDOR check: Verify caller owns this order
        $pdo = Database::getConnection();
        $chkOrderStmt = $pdo->prepare("SELECT id, seller_id, buyer_id FROM child_orders WHERE id = ?");
        $chkOrderStmt->execute([$childOrderId]);
        $orderRecord = $chkOrderStmt->fetch();

        if ($orderRecord && (int)$orderRecord['seller_id'] !== (int)$user['id']) {
            jsonError('Access Denied: Unauthorized. You can only appeal sell orders belonging to your account.', 403);
        }

        $res = StateMachine::raiseAppeal($childOrderId, $user['id'], $reason, $evidencePath);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    case 'cancel_order':
    case 'cancel_listing':
        if ($user['is_impersonating']) {
            jsonError('Order cancellation is disabled in Admin Support Mode.', 403);
        }

        $parentOrderId = (int)($input['parent_order_id'] ?? $input['order_id'] ?? 0);
        $childOrderId = (int)($input['child_order_id'] ?? 0);

        if ($childOrderId > 0) {
            $res = OrderEngine::cancelChildSellPortion($user['id'], $childOrderId);
        } elseif ($parentOrderId > 0) {
            $res = OrderEngine::cancelParentSellOrder($user['id'], $parentOrderId);
        } else {
            jsonError('Order ID required for cancellation.');
        }

        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
