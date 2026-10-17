<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Validator.php';

echo "Running Part 8 Feature Verification Tests...\n";

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

// -------------------------------------------------------------
// Test 1: Telegram support URL setting seeded (Part 8.3)
// -------------------------------------------------------------
$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
$tgUrl = $stmt->fetchColumn();
assert($tgUrl === 'https://t.me/jelipay_support', "Telegram support URL should be configured");
echo "[PASS] Part 8.3: Telegram support URL setting seeded\n";

// -------------------------------------------------------------
// Test 2: Support Request submission (Live Chat API backend) (Part 8.3)
// -------------------------------------------------------------
$testPhone = '9876543210';
$stmt = $pdo->prepare("INSERT INTO users (account_holder_name, phone, password_hash, role, health_score, account_status, referral_code) VALUES (?, ?, ?, 'user', 100, 'active', ?)");
$stmt->execute(['Support Test User', $testPhone, password_hash('Secret123!', PASSWORD_BCRYPT), 'REF_SUP01']);
$supportUserId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO support_requests (user_id, phone, username, message, status) VALUES (?, ?, 'Support Test User', ?, 'pending')");
$stmt->execute([$supportUserId, $testPhone, 'Live agent assistance requested']);
$supportReqId = (int)$pdo->lastInsertId();
assert($supportReqId > 0, "Support request record created");

$checkStmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
$checkStmt->execute([$supportReqId]);
$reqRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert($reqRow['phone'] === $testPhone, "Phone number stored correctly in support_requests");
assert($reqRow['status'] === 'pending', "Support request status is pending");
echo "[PASS] Part 8.3: Live Chat request records phone and logs pending request\n";

// -------------------------------------------------------------
// Test 3: Purchase History API logic & Validator masking (Part 8.1)
// -------------------------------------------------------------
$sellerPhone = '9123456789';
$stmt = $pdo->prepare("INSERT INTO users (account_holder_name, phone, password_hash, role, health_score, account_status, referral_code) VALUES (?, ?, ?, 'user', 100, 'active', ?)");
$stmt->execute(['Ramesh Kumar', $sellerPhone, password_hash('Secret123!', PASSWORD_BCRYPT), 'REF_SEL01']);
$sellerId = (int)$pdo->lastInsertId();

$buyerPhone = '9988776655';
$stmt = $pdo->prepare("INSERT INTO users (account_holder_name, phone, password_hash, role, health_score, account_status, referral_code) VALUES (?, ?, ?, 'user', 100, 'active', ?)");
$stmt->execute(['Suresh Patel', $buyerPhone, password_hash('Secret123!', PASSWORD_BCRYPT), 'REF_BUY01']);
$buyerId = (int)$pdo->lastInsertId();

// Create parent sell order & completed child order
$stmt = $pdo->prepare("
    INSERT INTO parent_sell_orders (
        seller_id, original_amount, remaining_amount, reserved_amount, completed_amount,
        status, snapshotted_upi_id, snapshotted_account_holder, created_at
    ) VALUES (?, 1000.00, 0.00, 0.00, 1000.00, 'completed', 'ramesh@upi', 'Ramesh Kumar', datetime('now'))
");
$stmt->execute([$sellerId]);
$parentOrderId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("
    INSERT INTO child_orders (
        parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
        snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
        status, completed_at, created_at
    ) VALUES (?, ?, ?, 1000.00, 9.00, 90.00, 'ramesh@upi', 'Ramesh Kumar', 'Completed', datetime('now'), datetime('now'))
");
$stmt->execute([$parentOrderId, $sellerId, $buyerId]);
$childOrderId = (int)$pdo->lastInsertId();

$stmt = $pdo->prepare("
    INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path)
    VALUES (?, ?, 'UTR12345678', 'uploads/proofs/test.jpg')
");
$stmt->execute([$childOrderId, $buyerId]);

// Execute buy_history query logic from api/wallet.php
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
$stmt->execute([$buyerId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
assert(count($orders) === 1, "Buy history returns 1 record");

$ord = $orders[0];
$ord['formatted_id'] = sprintf('#%04d', (int)$ord['id']);
$ord['amount'] = (float)$ord['amount'];
$ord['reward_amount'] = (float)($ord['snapshotted_reward_amount'] ?? ($ord['amount'] * 0.09));
$ord['total_credit'] = round($ord['amount'] + $ord['reward_amount'], 2);
$sellerRawName = !empty($ord['seller_name']) ? $ord['seller_name'] : (!empty($ord['snapshotted_account_holder']) ? $ord['snapshotted_account_holder'] : 'Platform Peer');
$ord['seller_name_masked'] = Validator::maskName($sellerRawName);
$ord['seller_upi_id'] = $ord['snapshotted_upi_id'] ?? '';
if (!empty($ord['seller_phone'])) {
    $ord['seller_phone_masked'] = Validator::maskPhone($ord['seller_phone']);
}

assert($ord['formatted_id'] === sprintf('#%04d', $childOrderId), "Formatted ID is 4 digits");
assert($ord['total_credit'] === 1090.00, "Total credit matches 1000 + 90");
assert(str_contains($ord['seller_name_masked'], '*'), "Seller name is masked");
assert($ord['seller_name_masked'] === 'R****h K***r', "Seller name masking pattern matches R****h K***r");
assert(str_contains($ord['seller_phone_masked'], '*****'), "Seller phone is masked");
echo "[PASS] Part 8.1: Buy History API query and Validator masking works without errors\n";

// -------------------------------------------------------------
// Test 4: Order Expiry and Automatic Relisting Logic (Part 8.2)
// -------------------------------------------------------------
// Create seller wallet
$stmt = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance) VALUES (?, 0, 2000)");
$stmt->execute([$sellerId]);

// Parent sell order with remaining = 1000, reserved = 1000
$stmt = $pdo->prepare("
    INSERT INTO parent_sell_orders (
        seller_id, original_amount, remaining_amount, reserved_amount, completed_amount,
        status, snapshotted_upi_id, snapshotted_account_holder, created_at
    ) VALUES (?, 2000.00, 1000.00, 1000.00, 0.00, 'active', 'ramesh@upi', 'Ramesh Kumar', datetime('now'))
");
$stmt->execute([$sellerId]);
$parentSell2Id = (int)$pdo->lastInsertId();

// Create child order in 'Reserved' state with expired deadline
$stmt = $pdo->prepare("
    INSERT INTO child_orders (
        parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
        snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
        status, payment_deadline, created_at
    ) VALUES (?, ?, ?, 1000.00, 9.00, 90.00, 'ramesh@upi', 'Ramesh Kumar', 'Reserved', datetime('now', '-5 minutes'), datetime('now', '-15 minutes'))
");
$stmt->execute([$parentSell2Id, $sellerId, $buyerId]);
$expiredChildId = (int)$pdo->lastInsertId();

// Execute expireSingleOrder
$stateMachine = new StateMachine($pdo);
$expireRes = $stateMachine->expireSingleOrder($expiredChildId, 'Payment time expired - automated cancellation');
assert($expireRes === true, "Order expiry should succeed");

// Verify child order status is 'Expired'
$stmt = $pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
$stmt->execute([$expiredChildId]);
$expiredStatus = $stmt->fetchColumn();
assert($expiredStatus === 'Expired', "Expired child order status should be Expired");

// Verify parent sell order remaining_amount increased by 1000, reserved_amount decreased by 1000
$stmt = $pdo->prepare("SELECT remaining_amount, reserved_amount FROM parent_sell_orders WHERE id = ?");
$stmt->execute([$parentSell2Id]);
$parentRow = $stmt->fetch(PDO::FETCH_ASSOC);
assert((float)$parentRow['remaining_amount'] === 2000.00, "Parent remaining_amount restored to 2000.00");
assert((float)$parentRow['reserved_amount'] === 0.00, "Parent reserved_amount restored to 0.00");

// Verify that a new Available child order was automatically relisted into the pool
$stmt = $pdo->prepare("SELECT id, status, amount FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$stmt->execute([$parentSell2Id]);
$relistedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
assert($relistedOrder !== false, "A new Available order should be relisted in the pool");
assert((float)$relistedOrder['amount'] === 1000.00, "Relisted order amount should be 1000.00");

// Verify notifications were generated for both buyer and seller
$stmt = $pdo->prepare("SELECT count(*) FROM notifications WHERE user_id IN (?, ?)");
$stmt->execute([$buyerId, $sellerId]);
$notifCount = (int)$stmt->fetchColumn();
assert($notifCount >= 2, "Notifications dispatched to both buyer and seller upon order expiry");
echo "[PASS] Part 8.2: Order expiry automatically cancels, restores parent balance, relists Available child order, and notifies parties\n";

// -------------------------------------------------------------
// Test 5: Expiry when Parent Sell Order was Cancelled (Part 8.2)
// -------------------------------------------------------------
$stmt = $pdo->prepare("UPDATE parent_sell_orders SET status = 'cancelled' WHERE id = ?");
$stmt->execute([$parentSell2Id]);

// Create another reserved child
$stmt = $pdo->prepare("
    INSERT INTO child_orders (
        parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
        snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
        status, payment_deadline, created_at
    ) VALUES (?, ?, ?, 500.00, 9.00, 45.00, 'ramesh@upi', 'Ramesh Kumar', 'Reserved', datetime('now', '-5 minutes'), datetime('now', '-15 minutes'))
");
$stmt->execute([$parentSell2Id, $sellerId, $buyerId]);
$child2Id = (int)$pdo->lastInsertId();

$expireRes2 = $stateMachine->expireSingleOrder($child2Id, 'Payment time expired on cancelled parent');
assert($expireRes2 === true, "Order expiry on cancelled parent should succeed");

// Seller available balance should receive the 500 tokens refunded directly
$stmt = $pdo->prepare("SELECT available_balance FROM wallets WHERE user_id = ?");
$stmt->execute([$sellerId]);
$sellerBal = (float)$stmt->fetchColumn();
assert($sellerBal === 500.00, "Seller available balance received refunded tokens from cancelled parent order");
echo "[PASS] Part 8.2: Order expiry on cancelled parent refunds tokens directly to seller available balance\n";

// -------------------------------------------------------------
// Test 6: Home Active Order lookup logic (Part 8.4)
// -------------------------------------------------------------
$stmt = $pdo->prepare("
    INSERT INTO child_orders (
        parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
        snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
        status, payment_deadline, created_at
    ) VALUES (?, ?, ?, 700.00, 9.00, 63.00, 'ramesh@upi', 'Ramesh Kumar', 'Reserved', datetime('now', '+10 minutes'), datetime('now'))
");
$stmt->execute([$parentSell2Id, $sellerId, $buyerId]);
$activeBuyId = (int)$pdo->lastInsertId();

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
$stmt->execute([$buyerId]);
$activeOrder = $stmt->fetch(PDO::FETCH_ASSOC);
assert($activeOrder !== false, "Active buy order found for buyer");
assert((int)$activeOrder['id'] === $activeBuyId, "Active order ID matches");
assert((float)$activeOrder['total_credit'] === 763.00, "Total credit matches 700 + 63");
echo "[PASS] Part 8.4: Active order lookup accurately provides data for Home Dashboard active banner\n";

echo "\nALL PART 8 FEATURE TESTS PASSED SUCCESSFULLY!\n";
