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
