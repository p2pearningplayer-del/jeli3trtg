<?php
/**
 * Sell Orders & Seller Tracking API Controller
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

$action = $_GET['action'] ?? $_POST['action'] ?? 'tracking';
$input = getJsonInput();

switch ($action) {
    case 'create_order':
        if ($user['is_impersonating']) {
            jsonError('Order creation is disabled in Admin Support Mode.', 403);
        }

        $amount = (float)($input['amount'] ?? 0);
        $tpin = (string)($input['tpin'] ?? '');

        if (empty($tpin)) {
            jsonError('Selling TPIN is required to authorize token listings.');
        }

        $res = OrderEngine::createSellOrder($user['id'], $amount, $tpin);
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

        $childOrderId = (int)($input['order_id'] ?? 0);
        $idempotencyKey = $input['idempotency_key'] ?? null;

        if ($childOrderId <= 0) {
            jsonError('Invalid order ID.');
        }

        $res = StateMachine::confirmPayment($childOrderId, $user['id'], $idempotencyKey);
        if ($res['success']) {
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

        $res = StateMachine::raiseAppeal($childOrderId, $user['id'], $reason, $evidencePath);
        if ($res['success']) {
            jsonResponse(true, $res);
        } else {
            jsonError($res['error']);
        }
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
