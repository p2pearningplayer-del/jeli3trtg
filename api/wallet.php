<?php
/**
 * Wallet & Ledger API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/response.php';

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

// Opportunistically sync expired orders
StateMachine::checkAndExpireOrders();

$action = $_GET['action'] ?? $_POST['action'] ?? 'summary';

switch ($action) {
    case 'summary':
        $stats = Wallet::getDashboardSummaries($user['id']);
        jsonResponse(true, ['wallet' => $stats]);
        break;

    case 'ledger':
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $history = Ledger::getUserHistory($user['id'], $limit, $offset);
        jsonResponse(true, ['ledger' => $history]);
        break;

    case 'buy_history':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.id, c.parent_order_id, c.amount, c.snapshotted_reward_rate,
                   c.snapshotted_reward_amount, c.snapshotted_upi_id, c.snapshotted_account_holder,
                   c.status, c.created_at, c.picked_at,
                   p.utr_number, p.screenshot_path, p.created_at as payment_submitted_at,
                   u.account_holder_name as seller_name, u.phone as seller_phone
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN users u ON c.seller_id = u.id
            WHERE c.buyer_id = ?
            ORDER BY c.id DESC
            LIMIT 100
        ");
        $stmt->execute([$user['id']]);
        $orders = $stmt->fetchAll();
        foreach ($orders as &$ord) {
            $ord['formatted_id'] = sprintf('#%04d', (int)$ord['id']);
            $ord['amount'] = (float)$ord['amount'];
            $ord['reward_amount'] = (float)$ord['snapshotted_reward_amount'];
            $ord['total_credit'] = round($ord['amount'] + $ord['reward_amount'], 2);
            $ord['seller_name_masked'] = Validator::maskName($ord['seller_name'] ?: $ord['snapshotted_account_holder']);
            if (!empty($ord['seller_phone'])) {
                $ord['seller_phone_masked'] = Validator::maskPhone($ord['seller_phone']);
            }
        }
        jsonResponse(true, ['buy_history' => $orders]);
        break;

    case 'sell_history':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT c.id, c.parent_order_id, c.amount, c.status, c.created_at, c.picked_at,
                   c.snapshotted_upi_id,
                   p.utr_number, p.screenshot_path, p.created_at as payment_submitted_at,
                   u.account_holder_name as buyer_name, u.phone as buyer_phone
            FROM child_orders c
            LEFT JOIN payment_proofs p ON c.id = p.child_order_id
            LEFT JOIN users u ON c.buyer_id = u.id
            WHERE c.seller_id = ?
            ORDER BY c.id DESC
            LIMIT 100
        ");
        $stmt->execute([$user['id']]);
        $portions = $stmt->fetchAll();
        foreach ($portions as &$p) {
            $p['formatted_id'] = sprintf('#%04d', (int)$p['id']);
            $p['parent_formatted_id'] = sprintf('#%04d', (int)$p['parent_order_id']);
            $p['amount'] = (float)$p['amount'];
            if (!empty($p['buyer_name'])) {
                $p['buyer_name_masked'] = Validator::maskName($p['buyer_name']);
            }
            if (!empty($p['buyer_phone'])) {
                $p['buyer_phone_masked'] = Validator::maskPhone($p['buyer_phone']);
            }
        }

        $pStmt = $pdo->prepare("
            SELECT id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, created_at
            FROM parent_sell_orders
            WHERE seller_id = ?
            ORDER BY id DESC
            LIMIT 50
        ");
        $pStmt->execute([$user['id']]);
        $parents = $pStmt->fetchAll();
        foreach ($parents as &$par) {
            $par['formatted_id'] = sprintf('#%04d', (int)$par['id']);
            $par['original_amount'] = (float)$par['original_amount'];
            $par['remaining_amount'] = (float)$par['remaining_amount'];
            $par['completed_amount'] = (float)$par['completed_amount'];
        }

        jsonResponse(true, [
            'sell_history' => $portions,
            'parent_listings' => $parents
        ]);
        break;

    case 'notifications':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30");
        $stmt->execute([$user['id']]);
        $notifs = $stmt->fetchAll();
        jsonResponse(true, ['notifications' => $notifs]);
        break;

    case 'mark_notifications_read':
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        jsonResponse(true, ['message' => 'Notifications marked as read.']);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
