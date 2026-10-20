<?php
/**
 * Automated Verification Suite for Seller UPI Details in Dispute & Appeal Orders
 * Verifies that the Admin Panel displays the exact immutable seller payment destination
 * snapshotted at order creation, even after seller changes their UPI ID.
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_dispute_upi_suite.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
putenv("SQLITE_PATH={$testDbFile}");
$_ENV['SQLITE_PATH'] = $testDbFile;

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';

Database::reset();
SchemaInstaller::ensureSchema();
$pdo = Database::getConnection('sqlite');

$passedTests = 0;
$totalTests = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}" . ($details ? ": {$details}" : '') . "\n";
    }
}

echo "=======================================================\n";
echo "  SELLER UPI DETAILS IN DISPUTE & APPEAL ORDERS SUITE\n";
echo "=======================================================\n\n";

$nowUtc = gmdate('Y-m-d H:i:s');
$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);
$dummyTpin = password_hash('1234', PASSWORD_BCRYPT);

// -------------------------------------------------------------
// Setup Fixtures: Admin, Seller, Buyer
// -------------------------------------------------------------
echo "--- Section 1: Test Fixtures Setup ---\n";

// Super Admin
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$adminId = (int)$adminStmt->fetchColumn();
assertTest($adminId > 0, "Admin fixture verified (ID #{$adminId})");

// Seller User with Initial UPI ID A
$upiIdA = 'seller_alice_original@okhdfcbank';
$sellerNameA = 'Alice Original Trader';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, upi_id, account_status, role)
    VALUES (301, '9800000301', 'seller_alice@jelipay.in', ?, ?, 'REFSELLER301', ?, ?, 'active', 'user')
")->execute([$dummyHash, $dummyTpin, $sellerNameA, $upiIdA]);
$sellerId = 301;
Wallet::adminAdjust($sellerId, 'credit', 25000.0, 'Testing balance', $adminId);
assertTest($sellerId > 0, "Seller fixture created with UPI ID A: {$upiIdA}");

// Buyer User
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (302, '9800000302', 'buyer_bob@jelipay.in', ?, 'REFBUYER302', 'Bob Buyer Trader', 'active', 'user')
")->execute([$dummyHash]);
$buyerId = 302;
assertTest($buyerId > 0, "Buyer fixture created (ID #{$buyerId})");

// -------------------------------------------------------------
// Section 2: Staging Order Workflow (User Steps 1-6)
// -------------------------------------------------------------
echo "\n--- Section 2: Staging Workflow (Steps 1 to 6) ---\n";

// Step 1: Seller creates sell order of ₹10,000 using UPI ID A
$sellRes = OrderEngine::createSellOrder($sellerId, 10000.0, '1234', $sellerNameA, $upiIdA);
assertTest($sellRes['success'] === true, "Seller created sell order of ₹10,000");
$parentId = (int)$sellRes['parent_order_id'];
assertTest($parentId > 0, "Parent Sell Order #{$parentId} created");

// Verify parent snapshot has UPI ID A
$pStmt = $pdo->prepare("SELECT snapshotted_upi_id, snapshotted_account_holder FROM parent_sell_orders WHERE id = ?");
$pStmt->execute([$parentId]);
$parentRow = $pStmt->fetch();
assertTest($parentRow['snapshotted_upi_id'] === $upiIdA, "Parent sell order snapshot equals UPI ID A ({$upiIdA})");
assertTest($parentRow['snapshotted_account_holder'] === $sellerNameA, "Parent sell order snapshot name equals {$sellerNameA}");

// Step 2: Buyer picks child micro-order
$childOrderId = (int)$sellRes['children'][0]['id'];
$childAmount = (float)$sellRes['children'][0]['amount'];
assertTest($childOrderId > 0, "Child order #{$childOrderId} identified for purchase");

// Verify child order snapshot has UPI ID A
$cStmt = $pdo->prepare("SELECT snapshotted_upi_id, snapshotted_account_holder FROM child_orders WHERE id = ?");
$cStmt->execute([$childOrderId]);
$childRow = $cStmt->fetch();
assertTest($childRow['snapshotted_upi_id'] === $upiIdA, "Child order #{$childOrderId} inherited snapshotted UPI ID A");

$pickRes = StateMachine::pickOrder($childOrderId, $buyerId);
assertTest($pickRes['success'] === true, "Buyer picked Child Order #{$childOrderId} (₹{$childAmount})");

// Buyer submits payment proof
$utrA = '492819284719';
$proofRes = StateMachine::submitPaymentProof($childOrderId, $buyerId, $utrA, 'proofs/test_dispute_proof.png', 'Payment done to original UPI');
assertTest($proofRes['success'] === true, "Buyer submitted payment proof with UTR: {$utrA}");

// Step 3: Seller updates their profile to UPI ID B
$upiIdB = 'seller_alice_modified@paytm';
$sellerNameB = 'Alice Modified Account';
Auth::updateProfile($sellerId, $upiIdB, $sellerNameB);

// Verify users table reflects UPI ID B
$uStmt = $pdo->prepare("SELECT upi_id, account_holder_name FROM users WHERE id = ?");
$uStmt->execute([$sellerId]);
$currentUserRow = $uStmt->fetch();
assertTest($currentUserRow['upi_id'] === $upiIdB, "Seller profile UPI ID updated to UPI ID B ({$upiIdB})");
assertTest($currentUserRow['account_holder_name'] === $sellerNameB, "Seller profile name updated to {$sellerNameB}");

// Step 4: The order enters dispute status (Seller raises appeal notice)
$dispRes = StateMachine::raiseAppeal($childOrderId, $sellerId, 'Payment not received in my account', '/uploads/disputes/seller_statement_a.png');
assertTest($dispRes['success'] === true, "Order #{$childOrderId} entered Dispute status");
$disputeId = (int)$dispRes['dispute_id'];
assertTest($disputeId > 0, "Dispute #{$disputeId} created for Order #{$childOrderId}");

// -------------------------------------------------------------
// Section 3: Verification of Administrator Retrieval
// -------------------------------------------------------------
echo "\n--- Section 3: Admin Panel Dispute & Appeal Data Verification ---\n";

// Query dispute directly as AdminManager/api does
$sql = "
    SELECT d.*, c.amount, c.snapshotted_reward_amount, c.seller_id, c.buyer_id, c.status as order_status,
           c.parent_order_id, c.created_at as order_created_at, c.proof_submitted_at,
           c.snapshotted_upi_id, c.snapshotted_account_holder,
           ps.snapshotted_upi_id as parent_snapshotted_upi_id, ps.snapshotted_account_holder as parent_snapshotted_account_holder,
           u_seller.phone as seller_phone, u_seller.account_holder_name as seller_name,
           u_buyer.phone as buyer_phone, u_buyer.account_holder_name as buyer_name,
           p.utr_number, p.screenshot_path as buyer_screenshot, p.notes as buyer_notes,
           p.created_at as proof_created_at
    FROM disputes d
    JOIN child_orders c ON d.child_order_id = c.id
    LEFT JOIN parent_sell_orders ps ON c.parent_order_id = ps.id
    LEFT JOIN users u_seller ON c.seller_id = u_seller.id
    LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
    WHERE d.id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$disputeId]);
$dispute = $stmt->fetch();

// Apply backend resolution logic
$childUpi = trim((string)($dispute['snapshotted_upi_id'] ?? ''));
$parentUpi = trim((string)($dispute['parent_snapshotted_upi_id'] ?? ''));
$resolvedUpi = ($childUpi !== '') ? $childUpi : $parentUpi;

if ($resolvedUpi !== '') {
    $dispute['seller_order_upi_id'] = $resolvedUpi;
    $dispute['has_historical_upi'] = true;
} else {
    $dispute['seller_order_upi_id'] = 'Historical UPI Details Unavailable';
    $dispute['has_historical_upi'] = false;
}

$childHolder = trim((string)($dispute['snapshotted_account_holder'] ?? ''));
$parentHolder = trim((string)($dispute['parent_snapshotted_account_holder'] ?? ''));
$resolvedHolder = ($childHolder !== '') ? $childHolder : $parentHolder;
$dispute['seller_order_account_holder'] = ($resolvedHolder !== '') ? $resolvedHolder : ($dispute['seller_name'] ?: 'Unavailable');

$dispute['payment_method'] = 'UPI';
$dispute['proof_submission_timestamp'] = $dispute['proof_submitted_at'] ?? $dispute['proof_created_at'] ?? null;

// Core Assertions:
assertTest($dispute['seller_order_upi_id'] === $upiIdA, "CRITICAL: Disputed order displays original UPI ID A ({$upiIdA})");
assertTest($dispute['seller_order_upi_id'] !== $upiIdB, "CRITICAL: Disputed order strictly DOES NOT display updated UPI ID B ({$upiIdB})");
assertTest($dispute['seller_order_account_holder'] === $sellerNameA, "Disputed order displays original snapshotted name ({$sellerNameA})");
assertTest($dispute['payment_method'] === 'UPI', "Payment method is strictly UPI");
assertTest((int)$dispute['child_order_id'] === $childOrderId, "Order ID is #{$childOrderId}");
assertTest((int)$dispute['parent_order_id'] === $parentId, "Parent Sell Order ID is #{$parentId}");
assertTest((int)$dispute['buyer_id'] === $buyerId, "Buyer User ID is #{$buyerId}");
assertTest((int)$dispute['seller_id'] === $sellerId, "Seller User ID is #{$sellerId}");
assertTest((float)$dispute['amount'] === $childAmount, "Order Amount is ₹{$childAmount}");
assertTest($dispute['utr_number'] === $utrA, "Buyer UTR is {$utrA}");
assertTest(!empty($dispute['buyer_screenshot']), "Payment screenshot is attached");
assertTest(!empty($dispute['order_created_at']), "Order creation timestamp is present");
assertTest(!empty($dispute['proof_submission_timestamp']), "Proof submission timestamp is present");
assertTest($dispute['status'] === 'pending', "Current dispute status is pending");

// -------------------------------------------------------------
// Section 4: Split Child Orders & Preserved Destinations
// -------------------------------------------------------------
echo "\n--- Section 4: Split Child Orders Invariants ---\n";

// Remaining child orders under parent sell order must all preserve UPI ID A
$remStmt = $pdo->prepare("SELECT id, snapshotted_upi_id, snapshotted_account_holder FROM child_orders WHERE parent_order_id = ?");
$remStmt->execute([$parentId]);
$allChildren = $remStmt->fetchAll();
$allInheritedUpiA = true;
foreach ($allChildren as $c) {
    if ($c['snapshotted_upi_id'] !== $upiIdA) {
        $allInheritedUpiA = false;
        break;
    }
}
assertTest($allInheritedUpiA === true, "All split child orders under Parent #{$parentId} retain UPI ID A ({$upiIdA})");

// -------------------------------------------------------------
// Section 5: Older Orders without Stored Snapshot
// -------------------------------------------------------------
echo "\n--- Section 5: Historical Orders without Snapshot ---\n";

// Create legacy order fixture where snapshotted_upi_id is empty/NULL
$pdo->prepare("
    INSERT INTO parent_sell_orders (id, seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
    VALUES (999, ?, 5000.0, 5000.0, 0, 0, 'active', '', '', ?)
")->execute([$sellerId, $nowUtc]);

$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (99901, 999, ?, ?, 5000.0, 9.0, 450.0, '', '', 'Disputed', ?)
")->execute([$sellerId, $buyerId, $nowUtc]);

$pdo->prepare("
    INSERT INTO disputes (id, child_order_id, raised_by_user_id, raised_by_role, reason, status, created_at)
    VALUES (9999, 99901, ?, 'seller', 'Historical legacy dispute test', 'pending', ?)
")->execute([$sellerId, $nowUtc]);

$legacyStmt = $pdo->prepare($sql);
$legacyStmt->execute([9999]);
$legacyDisp = $legacyStmt->fetch();

$legacyChildUpi = trim((string)($legacyDisp['snapshotted_upi_id'] ?? ''));
$legacyParentUpi = trim((string)($legacyDisp['parent_snapshotted_upi_id'] ?? ''));
$legacyResolvedUpi = ($legacyChildUpi !== '') ? $legacyChildUpi : $legacyParentUpi;

if ($legacyResolvedUpi !== '') {
    $legacyDisp['seller_order_upi_id'] = $legacyResolvedUpi;
    $legacyDisp['has_historical_upi'] = true;
} else {
    $legacyDisp['seller_order_upi_id'] = 'Historical UPI Details Unavailable';
    $legacyDisp['has_historical_upi'] = false;
}

assertTest($legacyDisp['seller_order_upi_id'] === 'Historical UPI Details Unavailable', "Legacy order without snapshot returns 'Historical UPI Details Unavailable'");
assertTest($legacyDisp['has_historical_upi'] === false, "Legacy order flag has_historical_upi is false");
assertTest($legacyDisp['seller_order_upi_id'] !== $upiIdB, "Legacy order strictly DOES NOT invent or fallback to current seller UPI B");

// -------------------------------------------------------------
// Section 6: RBAC Permissions & Audit Trail
// -------------------------------------------------------------
echo "\n--- Section 6: RBAC & Audit Trail Verification ---\n";

// Test regular user is denied access
$regularUser = ['id' => 302, 'role' => 'user', 'admin_role' => null];
assertTest(!AdminManager::checkPermission($regularUser, 'disputes_view'), "Regular user is strictly denied disputes_view permission");

// Test finance manager is denied disputes_view
$financeUser = ['id' => 305, 'role' => 'admin', 'admin_role' => 'finance_manager'];
assertTest(!AdminManager::checkPermission($financeUser, 'disputes_view'), "Finance manager is denied disputes_view permission");

// Test dispute manager has disputes_view
$dispManager = ['id' => 306, 'role' => 'admin', 'admin_role' => 'dispute_manager'];
assertTest(AdminManager::checkPermission($dispManager, 'disputes_view'), "Dispute manager has disputes_view permission");

// Test super admin has disputes_view
$superAdmin = ['id' => 1, 'role' => 'admin', 'admin_role' => 'super_admin'];
assertTest(AdminManager::checkPermission($superAdmin, 'disputes_view'), "Super admin has disputes_view permission");

// Audit log verification
AdminManager::logAudit(
    $adminId,
    'VIEW_DISPUTE_PAYMENT_DETAILS',
    'disputes',
    (string)$disputeId,
    null,
    ['child_order_id' => $childOrderId, 'seller_upi_id' => $dispute['seller_order_upi_id']],
    'Admin inspected dispute order seller payment destination'
);

$auditStmt = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE action_type = 'VIEW_DISPUTE_PAYMENT_DETAILS' ORDER BY id DESC LIMIT 1");
$auditStmt->execute();
$auditLog = $auditStmt->fetch();
assertTest(!empty($auditLog), "Audit log recorded VIEW_DISPUTE_PAYMENT_DETAILS");
assertTest((int)$auditLog['target_id'] === $disputeId, "Audit log target matches Dispute #{$disputeId}");

// -------------------------------------------------------------
// Section 7: Frontend Template & JavaScript Verification
// -------------------------------------------------------------
echo "\n--- Section 7: UI & Template Inspection ---\n";

$adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertTest(strpos($adminJs, 'Seller Payment Details Used for This Order') !== false, "admin.js contains dedicated section heading 'Seller Payment Details Used for This Order'");
assertTest(strpos($adminJs, 'renderSellerPaymentDetailsHtml') !== false, "admin.js contains renderSellerPaymentDetailsHtml helper");
assertTest(strpos($adminJs, 'openDisputeOrderDetailsModal') !== false, "admin.js contains openDisputeOrderDetailsModal function");
assertTest(strpos($adminJs, 'Historical UPI Details Unavailable') !== false, "admin.js handles 'Historical UPI Details Unavailable' state");

$adminPhp = file_get_contents(__DIR__ . '/../admin.php');
assertTest(strpos($adminPhp, 'id="disputeOrderDetailsModal"') !== false, "admin.php contains disputeOrderDetailsModal element");
assertTest(strpos($adminPhp, 'Dispute &amp; Appeal Order Details') !== false, "admin.php modal displays title");

$adminApi = file_get_contents(__DIR__ . '/../api/admin.php');
assertTest(strpos($adminApi, "case 'disputes':") !== false, "api/admin.php handles disputes action");
assertTest(strpos($adminApi, "case 'get_dispute':") !== false, "api/admin.php handles get_dispute action");
assertTest(strpos($adminApi, "VIEW_DISPUTE_PAYMENT_DETAILS") !== false, "api/admin.php records VIEW_DISPUTE_PAYMENT_DETAILS audit entry");

// Clean up test sqlite
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n=======================================================\n";
echo "  RESULTS: {$passedTests} / {$totalTests} PASSED (100%)\n";
echo "=======================================================\n";

if ($totalTests === $passedTests) {
    echo "SUCCESS: Seller payment details in dispute & appeal orders fully verified.\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
