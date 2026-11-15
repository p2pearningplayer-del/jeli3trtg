<?php
/**
 * Test Suite: Fix Sell Order Portions - Keep Unsold Portions LIVE in Buy Pool
 * Verifies that all unsold portions remain active and live in the Buy Pool,
 * auto-healing restores missing or expired portions,
 * and partially completed orders keep their unsold portions live in the marketplace.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/UpiManager.php';

SchemaInstaller::ensureSchema();

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $description, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail ? " ($detail)" : "") . "\n";
        $failed++;
    }
}

echo "====================================================================\n";
echo " JELIPAY - KEEP UNSOLD PORTIONS LIVE IN BUY POOL TEST SUITE\n";
echo "====================================================================\n\n";

$pdo = Database::getConnection();

// Create test admin if missing
$chkAdmin = $pdo->query("SELECT id FROM users WHERE id = 1")->fetch();
if (!$chkAdmin) {
    SchemaInstaller::ensureDefaultAdmin($pdo);
}
$adminId = 1;

// Create unique test users using Auth::register
$suffix = time() . '_' . mt_rand(100, 999);
$sellerPhone = '98' . mt_rand(10000000, 99999999);
$buyerPhone = '97' . mt_rand(10000000, 99999999);
$sellerUpi = "seller_{$suffix}@okhdfcbank";
$buyerUpi = "buyer_{$suffix}@paytm";

$sellerReg = Auth::register($sellerPhone, 'Pass1234!', null, $sellerUpi, 'Test Seller', "seller{$suffix}@gmail.com", true);
$sellerId = (int)$sellerReg['user_id'];
Auth::setTPIN($sellerId, '1234');

$buyerReg = Auth::register($buyerPhone, 'Pass1234!', null, $buyerUpi, 'Test Buyer', "buyer{$suffix}@gmail.com", true);
$buyerId = (int)$buyerReg['user_id'];

// Fund seller & buyer wallets
Wallet::adminAdjust($sellerId, 'credit', 10000.00, 'Seed seller balance', $adminId);
Wallet::adminAdjust($buyerId, 'credit', 10000.00, 'Seed buyer balance', $adminId);

// -------------------------------------------------------------
// SECTION 1: Standard Active Sell Order Portions Are LIVE in Buy Pool
// -------------------------------------------------------------
echo "--- SECTION 1: Active Sell Order Portions Are LIVE in Buy Pool ---\n";

// Create sell order with approval requirement bypassed for live testing
$orderRes = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', 'Test Seller', $sellerUpi, false);
assertCondition($orderRes['success'] === true, "Sell order created successfully");
$parentOrderId = (int)$orderRes['parent_order_id'];
assertCondition($orderRes['status'] === 'active', "Sell order status is 'active'");

// Verify children were created as Available
$cStmt = $pdo->prepare("SELECT id, status, amount FROM child_orders WHERE parent_order_id = ?");
$cStmt->execute([$parentOrderId]);
$children = $cStmt->fetchAll(PDO::FETCH_ASSOC);
assertCondition(count($children) === 3, "Parent order has 3 micro-portions (500, 300, 200)");
$allAvailable = true;
foreach ($children as $c) {
    if ($c['status'] !== 'Available') $allAvailable = false;
}
assertCondition($allAvailable, "All created micro-portions have status 'Available'");

// Check buy pool query (matches api/buy.php logic)
$poolStmt = $pdo->prepare("
    SELECT c.id, c.amount, c.status
    FROM child_orders c
    LEFT JOIN parent_sell_orders pso ON c.parent_order_id = pso.id
    WHERE LOWER(c.status) = 'available'
      AND (
          pso.id IS NULL
          OR LOWER(pso.status) IN ('active', 'partially_completed')
          OR (pso.remaining_amount > 0 AND LOWER(pso.status) NOT IN ('cancelled', 'rejected', 'completed', 'partially_cancelled', 'pending_approval'))
      )
      AND c.seller_id != ?
");
$poolStmt->execute([$buyerId]); // buyer queries pool
$poolOrders = $poolStmt->fetchAll(PDO::FETCH_ASSOC);
$foundPortions = array_filter($poolOrders, fn($o) => in_array($o['id'], array_column($children, 'id')));
assertCondition(count($foundPortions) === 3, "All 3 portions appear live in the public Buy Pool for buyers");

// -------------------------------------------------------------
// SECTION 2: Buyer Pickup and Expiry Keeps Portions LIVE
// -------------------------------------------------------------
echo "\n--- SECTION 2: Expiry Keeps Unsold Portions LIVE in Buy Pool ---\n";

$firstPortionId = (int)$children[0]['id'];
$pickRes = StateMachine::pickOrder($firstPortionId, $buyerId);
assertCondition($pickRes['success'] === true, "Buyer picked portion #{$firstPortionId} (status -> Reserved)");

// Check parent reserved / remaining amounts
$pStmt = $pdo->prepare("SELECT remaining_amount, reserved_amount, completed_amount FROM parent_sell_orders WHERE id = ?");
$pStmt->execute([$parentOrderId]);
$pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition((float)$pRow['remaining_amount'] === 500.00, "Parent remaining_amount reduced to 500.00");
assertCondition((float)$pRow['reserved_amount'] === 500.00, "Parent reserved_amount increased to 500.00");

// Now simulate buyer timeout: expire the reserved order
$expireRes = StateMachine::expireSingleOrder($firstPortionId);
assertCondition($expireRes === true, "Reservation expired cleanly");

// Check child order status & relisting
$pStmt->execute([$parentOrderId]);
$pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition((float)$pRow['remaining_amount'] === 1000.00, "Parent remaining_amount restored to 1000.00 on expiry");
assertCondition((float)$pRow['reserved_amount'] === 0.00, "Parent reserved_amount restored to 0.00 on expiry");

// Verify that a live portion is available in the pool
$availStmt = $pdo->prepare("SELECT id, status, amount FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$availStmt->execute([$parentOrderId]);
$availPortions = $availStmt->fetchAll(PDO::FETCH_ASSOC);
$totalAvailAmt = (float)array_sum(array_column($availPortions, 'amount'));
assertCondition(abs($totalAvailAmt - 1000.00) < 0.01, "Total available portions equals 1000.00 full remaining amount");

// Verify seller tracking data reflects active micro-portions > 0
$tracking = OrderEngine::getSellerTrackingData($sellerId);
$parentTracking = null;
foreach ($tracking as $p) {
    if ((int)$p['id'] === $parentOrderId) {
        $parentTracking = $p;
        break;
    }
}
assertCondition($parentTracking !== null, "Parent order found in seller tracking data");
$activeRunningPortions = array_filter($parentTracking['children'], fn($c) => in_array($c['status'], ['Available', 'Reserved', 'Payment Proof Submitted']));
assertCondition(count($activeRunningPortions) >= 3, "Seller dashboard tracking displays active micro-portions count >= 3 (not 0)");

// -------------------------------------------------------------
// SECTION 3: Auto-Healing Scenario (Fix Parent #2597 Case)
// -------------------------------------------------------------
echo "\n--- SECTION 3: Auto-Healing Missing / Prematurely Expired Portions ---\n";

// Intentionally create the exact bug scenario:
// A parent order has remaining_amount = 1000.00, status = 'active', but its child portions are marked 'Expired'
$bugOrderRes = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', 'Test Seller', $sellerUpi, false);
$bugParentId = (int)$bugOrderRes['parent_order_id'];

// Simulate bug: set all child orders to 'Expired'
$pdo->prepare("UPDATE child_orders SET status = 'Expired' WHERE parent_order_id = ?")->execute([$bugParentId]);

// Verify the bug state before healing
$beforeStmt = $pdo->prepare("SELECT COUNT(*) FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$beforeStmt->execute([$bugParentId]);
$availBefore = (int)$beforeStmt->fetchColumn();
assertCondition($availBefore === 0, "Simulated bug state: Active Micro-Portions is 0");

// Trigger auto-healing via OrderEngine::synchronizeParentOrderPortions
$healRes = OrderEngine::synchronizeParentOrderPortions($bugParentId);
assertCondition($healRes['success'] === true, "Auto-healing executed successfully");
assertCondition($healRes['healed_count'] > 0, "Auto-healing restored {$healRes['healed_count']} portion(s)");

// Verify all portions are now Available and LIVE
$afterStmt = $pdo->prepare("SELECT id, status, amount FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$afterStmt->execute([$bugParentId]);
$healedPortions = $afterStmt->fetchAll(PDO::FETCH_ASSOC);
$healedTotal = (float)array_sum(array_column($healedPortions, 'amount'));
assertCondition(abs($healedTotal - 1000.00) < 0.01, "Auto-healed active micro-portions total ₹1,000.00");

// Verify seller tracking data automatically heals on dashboard load
// Break portions again
$pdo->prepare("UPDATE child_orders SET status = 'Expired' WHERE parent_order_id = ?")->execute([$bugParentId]);
$tracking2 = OrderEngine::getSellerTrackingData($sellerId);
$bugParentTracking = null;
foreach ($tracking2 as $p) {
    if ((int)$p['id'] === $bugParentId) {
        $bugParentTracking = $p;
        break;
    }
}
$trackingLivePortions = array_filter($bugParentTracking['children'] ?? [], fn($c) => $c['status'] === 'Available');
assertCondition(count($trackingLivePortions) > 0, "Seller dashboard load automatically auto-healed portions (Active Micro-Portions > 0)");

// -------------------------------------------------------------
// SECTION 4: Partial Completion Keeps Unsold Portions LIVE
// -------------------------------------------------------------
echo "\n--- SECTION 4: Partially Completed Order Keeps Remaining Portions LIVE ---\n";

// Order has remaining 1000. Let buyer pick 500 portion
$stmt = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' AND amount = 500.00 LIMIT 1");
$stmt->execute([$bugParentId]);
$target500Id = (int)$stmt->fetchColumn();

$pick500 = StateMachine::pickOrder($target500Id, $buyerId);
assertCondition($pick500['success'] === true, "Buyer picked ₹500 portion", $pick500['error'] ?? '');

// Create a valid dummy PNG file
$proofDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($proofDir)) {
    @mkdir($proofDir, 0777, true);
}
$dummyProofPath = $proofDir . '/proof_test_' . uniqid() . '.png';
$basePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($dummyProofPath, $basePng . str_repeat('A', 256));

// Submit proof with unique UTR
$testUtr = '98' . mt_rand(1000000000, 9999999999);
$proofRes = StateMachine::submitPaymentProof($target500Id, $buyerId, $testUtr, $dummyProofPath, 'Paid via UPI');
assertCondition($proofRes['success'] === true, "Buyer submitted payment proof", $proofRes['error'] ?? '');

// Seller confirms payment with T-PIN
$confirmRes = StateMachine::confirmPayment($target500Id, $sellerId, '1234');
assertCondition($confirmRes['success'] === true, "Seller confirmed payment for ₹500 portion", $confirmRes['error'] ?? '');

// Check parent status: should have completed=500, remaining=500
$pStmt->execute([$bugParentId]);
$pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition((float)$pRow['remaining_amount'] === 500.00, "Parent remaining_amount is 500.00");
assertCondition((float)$pRow['completed_amount'] === 500.00, "Parent completed_amount is 500.00");

// Synchronize parent order
OrderEngine::synchronizeParentOrderPortions($bugParentId);

// Verify that remaining unsold portions (₹500 total) appear in public Buy Pool query
$poolStmt->execute([$buyerId]);
$poolOrders = $poolStmt->fetchAll(PDO::FETCH_ASSOC);
$bugPortionsInPool = array_filter($poolOrders, function($o) use ($bugParentId, $pdo) {
    $parent = $pdo->query("SELECT parent_order_id FROM child_orders WHERE id = {$o['id']}")->fetchColumn();
    return (int)$parent === $bugParentId;
});
$poolUnsoldTotal = (float)array_sum(array_column($bugPortionsInPool, 'amount'));
assertCondition(abs($poolUnsoldTotal - 500.00) < 0.01, "Unsold portions (₹500.00) of partially completed order remain LIVE in the public Buy Pool");

// Verify allocateCustomBuy can match and allocate from this partially completed order
$customAlloc = OrderEngine::allocateCustomBuy($buyerId, 300.00);
assertCondition($customAlloc['success'] === true, "allocateCustomBuy successfully picked ₹300 from partially completed order", $customAlloc['error'] ?? '');
assertCondition((float)$customAlloc['amount'] === 300.00, "Allocated amount matches ₹300.00");

// Clean up temp proof file
if (file_exists($dummyProofPath)) {
    @unlink($dummyProofPath);
}

echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
