<?php
putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/Validator.php';

// Initialize in-memory SQLite database
$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec("PRAGMA foreign_keys = ON;");
Database::setConnection($pdo, 'sqlite');
SchemaInstaller::initSqlite($pdo);
SchemaInstaller::migrateColumns($pdo, 'sqlite');
SchemaInstaller::seedPlatformSettings($pdo);

// Create 2 test users
$sellerPhone = '9812345678';
$buyerPhone = '9712345678';
Auth::register($sellerPhone, 'password123');
$seller = $pdo->query("SELECT * FROM users WHERE phone = '{$sellerPhone}'")->fetch();
$sellerId = (int)$seller['id'];
Auth::setTpin($sellerId, '1234');
$pdo->prepare("UPDATE wallets SET available_balance = 5000 WHERE user_id = ?")->execute([$sellerId]);

Auth::register($buyerPhone, 'password123');
$buyer = $pdo->query("SELECT * FROM users WHERE phone = '{$buyerPhone}'")->fetch();
$buyerId = (int)$buyer['id'];

// Create parent sell order & split
$parentRes = OrderEngine::createSellOrder($sellerId, 1000, '1234', 'Seller Name', 'seller@upi');
if (!$parentRes['success']) {
    die("Failed to create sell order: " . $parentRes['error']);
}

// Get one available child order
$stmt = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' LIMIT 1");
$stmt->execute([$parentRes['parent_order_id']]);
$child = $stmt->fetch();
$childOrderId = (int)$child['id'];

// Buyer picks order
$pickRes = StateMachine::pickOrder($childOrderId, $buyerId);
if (!$pickRes['success']) {
    die("Failed to pick order: " . $pickRes['error']);
}

echo "[PASS] Child order #{$childOrderId} reserved by buyer #{$buyerId}\n";

// Helper function from buy.php
function testFormatChildOrderResponse(array $p, int $now): array {
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
    return $p;
}

// Test active_order query logic
$activeStmt = $pdo->prepare("
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
$activeStmt->execute([$buyerId]);
$activeOrder = $activeStmt->fetch();

assert(!empty($activeOrder), "Active order should be found");
assert((int)$activeOrder['id'] === $childOrderId, "Active order ID should match child order");
$formatted = testFormatChildOrderResponse($activeOrder, time());
assert($formatted['seller_upi_id'] === 'seller@upi', "UPI should match seller UPI");
assert($formatted['seller_account_holder'] === 'Seller Name', "Account holder should match");
assert($formatted['status'] === 'Reserved', "Status should be Reserved");

echo "[PASS] Active order query and formatting succeeded\n";

// Test order_details query logic
$detailStmt = $pdo->prepare("
    SELECT c.*, (c.amount + c.snapshotted_reward_amount) as total_credit,
           p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at,
           d.status as dispute_status, d.reason as dispute_reason
    FROM child_orders c
    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
    LEFT JOIN disputes d ON c.id = d.child_order_id
    WHERE c.id = ? AND (c.buyer_id = ? OR c.seller_id = ? OR 0 = 1)
    LIMIT 1
");
$detailStmt->execute([$childOrderId, $buyerId, $buyerId]);
$detailOrder = $detailStmt->fetch();

assert(!empty($detailOrder), "Detail order should be found for buyer");
assert((int)$detailOrder['id'] === $childOrderId, "Detail order ID should match");
$formattedDetail = testFormatChildOrderResponse($detailOrder, time());
assert($formattedDetail['seller_upi_id'] === 'seller@upi', "UPI must match");
assert($formattedDetail['amount'] > 0, "Amount must be positive");

echo "[PASS] Order details query and formatting succeeded\n";

echo "ALL ACTIVE ORDER ENDPOINT TESTS PASSED SUCCESSFULLY!\n";
