<?php
/**
 * Test Suite: Concurrent Buy Order Limit (Up to 10) & Admin Dashboard Order Enhancements
 *
 * Verifies:
 * 1. Concurrent Buy Order Limit Increase:
 *    - Buyer is no longer restricted to 1 active buy order at a time.
 *    - Buyer can pick, pay for, and hold up to 10 active/pending buy orders simultaneously.
 *    - Buyer can initiate new buy orders (via both direct pick and custom buy allocation)
 *      even while previous orders are in 'Reserved' or 'Seller Confirmation Pending'.
 *    - 11th concurrent order is blocked at the 10-order ceiling.
 *    - Completing or cancelling one of the 10 orders frees a slot to pick another order.
 * 2. Admin Panel 'Orders' Section Enhancements:
 *    - Daily Metric 1: Today's Available Order Balance (total value of active, unsold orders listed today).
 *    - Daily Metric 2: Today's Completed Order Balance (total value of orders completed today).
 *    - Dedicated 'Completed Orders' section filter in AdminManager::getOrders('all', 'child', '', 50, 0, 'completed').
 *    - Frontend HTML/JS verification in admin.php and public/js/admin.js.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/UpiManager.php';

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

$testDbFile = __DIR__ . '/test_concurrent_admin_' . time() . '.sqlite';
putenv("SQLITE_DATABASE_PATH={$testDbFile}");

$pdo = new PDO("sqlite:{$testDbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

Database::setConnection($pdo, 'sqlite');
SchemaInstaller::ensureSchema();

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$label}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$label}" . ($detail ? " ({$detail})" : "") . "\n";
        $failCount++;
    }
}

echo "====================================================================\n";
echo " TEST SUITE: CONCURRENT BUY ORDERS (10) & ADMIN ORDERS DASHBOARD\n";
echo "====================================================================\n\n";

// Helper to create test users
function createTestUser(PDO $pdo, string $phone, string $email, string $upi): int {
    $res = Auth::register($phone, 'Password@123', null, $upi, 'Trader ' . substr($phone, -4), $email, true);
    if (!$res['success']) {
        throw new Exception("User registration failed: " . ($res['error'] ?? ''));
    }
    $uid = (int)$res['user_id'];
    Auth::setTPIN($uid, '1234');
    $pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([100000.00, $uid]);
    return $uid;
}

$sellerId = createTestUser($pdo, '9200000001', 'seller_conc@gmail.com', 'sellerconc@upi');
$buyerId  = createTestUser($pdo, '9200000002', 'buyer_conc@gmail.com', 'buyerconc@upi');

// Create a parent sell order with 15 available child orders of ₹100 each (Total ₹1,500 listed today)
$nowUtc = gmdate('Y-m-d H:i:s');
$parentStmt = $pdo->prepare("
    INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
    VALUES (?, 1500.00, 1500.00, 0.00, 0.00, 'active', 'sellerconc@upi', 'Seller Conc', ?)
");
$parentStmt->execute([$sellerId, $nowUtc]);
$parentId = (int)$pdo->lastInsertId();

// Lock seller balance for the parent order
$pdo->prepare("UPDATE wallets SET available_balance = available_balance - 1500.00, reserved_balance = reserved_balance + 1500.00 WHERE user_id = ?")->execute([$sellerId]);

$childIds = [];
$childStmt = $pdo->prepare("
    INSERT INTO child_orders (parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (?, ?, NULL, 100.00, 9.00, 9.00, 'sellerconc@upi', 'Seller Conc', 'Available', ?)
");
for ($i = 1; $i <= 15; $i++) {
    $childStmt->execute([$parentId, $sellerId, $nowUtc]);
    $childIds[] = (int)$pdo->lastInsertId();
}

// Create temp screenshots for proof submissions
$tmpDir = sys_get_temp_dir() . '/conc_test_' . uniqid();
@mkdir($tmpDir, 0755, true);
$dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

// -------------------------------------------------------------
// SECTION 1: CONCURRENT BUY ORDER LIMIT (UP TO 10 SIMULTANEOUS)
// -------------------------------------------------------------
echo "--- Section 1: Concurrent Buy Order Limit (Up to 10) ---\n";

assertCheck(
    defined('MAX_CONCURRENT_BUY_ORDERS') && MAX_CONCURRENT_BUY_ORDERS === 10,
    "MAX_CONCURRENT_BUY_ORDERS is configured to 10"
);

// 1. Pick Order #1 and submit payment proof -> moves to 'Seller Confirmation Pending'
$pick1 = StateMachine::pickOrder($childIds[0], $buyerId);
assertCheck($pick1['success'] === true, "Buyer picks Order #1 successfully");

$screen1 = $tmpDir . '/proof_1.png';
file_put_contents($screen1, $dummyPng . "\nOrder 1 Proof " . uniqid());
$proof1 = StateMachine::submitPaymentProof($childIds[0], $buyerId, '610000000001', $screen1);
assertCheck($proof1['success'] === true, "Buyer submits payment proof for Order #1 (now waiting for seller confirmation)");

// 2. While Order #1 is waiting for seller confirmation, pick Orders #2 through #9 via pickOrder
for ($idx = 1; $idx <= 8; $idx++) {
    $orderNum = $idx + 1;
    $pickRes = StateMachine::pickOrder($childIds[$idx], $buyerId);
    assertCheck(
        $pickRes['success'] === true,
        "Buyer picks concurrent Order #{$orderNum} while previous orders are pending/active",
        $pickRes['error'] ?? ''
    );

    // Submit payment proof on half of them so we have a mix of 'Seller Confirmation Pending' and 'Reserved'
    if ($idx % 2 === 0) {
        $scFile = $tmpDir . "/proof_{$orderNum}.png";
        file_put_contents($scFile, $dummyPng . "\nOrder {$orderNum} Proof " . uniqid());
        $utrStr = sprintf('6100000000%02d', $orderNum);
        $prRes = StateMachine::submitPaymentProof($childIds[$idx], $buyerId, $utrStr, $scFile);
        assertCheck($prRes['success'] === true, "Buyer submits payment proof for concurrent Order #{$orderNum}");
    }
}

// 3. Pick 10th concurrent order via OrderEngine::allocateCustomBuy
$alloc10 = OrderEngine::allocateCustomBuy($buyerId, 100.00);
assertCheck(
    $alloc10['success'] === true,
    "Buyer allocates 10th concurrent order via Custom Buy while 9 orders are active/pending",
    $alloc10['error'] ?? ''
);

// Verify buyer currently has exactly 10 active/pending buy orders
$activeCountStmt = $pdo->prepare("
    SELECT COUNT(*) FROM child_orders
    WHERE buyer_id = ? AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Disputed')
");
$activeCountStmt->execute([$buyerId]);
$currentActiveCount = (int)$activeCountStmt->fetchColumn();
assertCheck($currentActiveCount === 10, "Buyer holds exactly 10 active/pending buy orders simultaneously", "Count: {$currentActiveCount}");

// 4. Attempt 11th concurrent buy order -> must be blocked at the 10-order limit
$pick11 = StateMachine::pickOrder($childIds[10], $buyerId);
assertCheck(
    $pick11['success'] === false && ($pick11['max_concurrent_orders'] ?? 0) === 10,
    "11th concurrent pickOrder is blocked when buyer already holds 10 active/pending orders",
    json_encode($pick11)
);

$alloc11 = OrderEngine::allocateCustomBuy($buyerId, 100.00);
assertCheck(
    $alloc11['success'] === false && ($alloc11['max_concurrent_orders'] ?? 0) === 10,
    "11th concurrent allocateCustomBuy is blocked when buyer already holds 10 active/pending orders",
    json_encode($alloc11)
);

// 5. Seller confirms Order #1 and Order #3 -> frees 2 slots, buyer can pick again!
$confirm1 = StateMachine::confirmPayment($childIds[0], $sellerId, '1234');
$confirm3 = StateMachine::confirmPayment($childIds[2], $sellerId, '1234');
assertCheck($confirm1['success'] === true && $confirm3['success'] === true, "Seller confirms 2 pending orders (#1 and #3)");

$pickAfterSettle = StateMachine::pickOrder($childIds[10], $buyerId);
assertCheck(
    $pickAfterSettle['success'] === true,
    "Buyer can immediately pick a new order once active count drops below 10"
);

// -------------------------------------------------------------
// SECTION 2: ADMIN PANEL 'ORDERS' SECTION ENHANCEMENTS
// -------------------------------------------------------------
echo "\n--- Section 2: Admin Panel 'Orders' Section Enhancements ---\n";

// At this point:
// Total child orders created today = 15 (each ₹100 = ₹1,500 total)
// - 2 orders are 'Completed' today (#1 and #3 -> ₹200.00)
// - 9 orders are 'Reserved' or 'Seller Confirmation Pending' (₹900.00)
// - 4 orders are 'Available' (unsold, listed today -> ₹400.00)

$adminActiveView = AdminManager::getOrders('all', 'child', '', 50, 0, 'active');

assertCheck(
    isset($adminActiveView['daily_metrics']),
    "AdminManager::getOrders returns daily_metrics object"
);

$dm = $adminActiveView['daily_metrics'];
assertCheck(
    (float)$dm['today_available_balance'] === 400.00 && (int)$dm['today_available_count'] === 4,
    "Today's Available Order Balance accurately reflects active, unsold orders listed today (₹400.00 across 4 orders)",
    json_encode($dm)
);

assertCheck(
    (float)$dm['today_completed_balance'] === 200.00 && (int)$dm['today_completed_count'] === 2,
    "Today's Completed Order Balance accurately reflects orders completed today (₹200.00 across 2 orders)",
    json_encode($dm)
);

assertCheck(
    (int)($adminActiveView['counts']['completed'] ?? -1) === 2,
    "Section counts include 'completed' count = 2",
    json_encode($adminActiveView['counts'])
);

// Query the new dedicated 'completed' section in AdminManager::getOrders
$adminCompletedView = AdminManager::getOrders('all', 'child', '', 50, 0, 'completed');
assertCheck(
    (int)$adminCompletedView['total'] === 2 && count($adminCompletedView['orders']) === 2,
    "Dedicated 'Completed Orders' section returns all 2 settled transactions",
    "Total: " . $adminCompletedView['total']
);

$allCompletedStatuses = true;
foreach ($adminCompletedView['orders'] as $co) {
    if ($co['status'] !== 'Completed') {
        $allCompletedStatuses = false;
    }
}
assertCheck($allCompletedStatuses, "All orders returned in 'completed' section have status 'Completed'");

// Verify frontend markup in admin.php and public/js/admin.js
$adminHtml = (string)file_get_contents(__DIR__ . '/../admin.php');
$adminJs   = (string)file_get_contents(__DIR__ . '/../public/js/admin.js');

assertCheck(
    str_contains($adminHtml, 'id="orderMetricTodayAvailableBalance"') &&
    str_contains($adminHtml, "Today's Available Order Balance"),
    "admin.php includes Today's Available Order Balance metric card"
);

assertCheck(
    str_contains($adminHtml, 'id="orderMetricTodayCompletedBalance"') &&
    str_contains($adminHtml, "Today's Completed Order Balance"),
    "admin.php includes Today's Completed Order Balance metric card"
);

assertCheck(
    str_contains($adminHtml, 'id="orderSecTab-completed"') &&
    str_contains($adminHtml, "switchOrderSection('completed')"),
    "admin.php includes dedicated 'Completed Orders' tab button"
);

assertCheck(
    str_contains($adminJs, "sec === 'completed'") &&
    str_contains($adminJs, 'orderMetricTodayAvailableBalance') &&
    str_contains($adminJs, 'orderMetricTodayCompletedBalance'),
    "public/js/admin.js handles 'completed' section tab and binds daily volume metrics"
);

// Clean up temp files
foreach (glob($tmpDir . '/*.png') as $f) {
    @unlink($f);
}
@rmdir($tmpDir);
@unlink($testDbFile);

echo "\n====================================================================\n";
echo " TEST SUITE SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
