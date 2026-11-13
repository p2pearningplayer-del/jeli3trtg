<?php
/**
 * Test Suite: Admin Approval Workflow for Sell Orders
 * Tests sell order creation in Pending Approval state, marketplace isolation,
 * Admin Pending Sell Orders queue, Approve workflow, and Reject/Refund workflow.
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

function assertCondition(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "====================================================================\n";
echo " JELIPAY - ADMIN APPROVAL WORKFLOW FOR SELL ORDERS TEST SUITE\n";
echo "====================================================================\n\n";

$pdo = Database::getConnection();

// Create test admin
$adminId = 1;
$chkAdmin = $pdo->query("SELECT id FROM users WHERE id = 1")->fetch();
if (!$chkAdmin) {
    SchemaInstaller::ensureDefaultAdmin($pdo);
}

// Generate unique test users
$suffix = time() . '_' . mt_rand(100, 999);
$sellerPhone = '97' . mt_rand(10000000, 99999999);
$buyerPhone = '96' . mt_rand(10000000, 99999999);
$sellerUpi = "seller_{$suffix}@okhdfcbank";
$buyerUpi = "buyer_{$suffix}@paytm";

$sellerReg = Auth::register($sellerPhone, 'Pass1234!', null, $sellerUpi, 'Approval Seller', "seller{$suffix}@gmail.com", true);
$sellerId = (int)$sellerReg['user_id'];
Auth::setTPIN($sellerId, '1234');

$buyerReg = Auth::register($buyerPhone, 'Pass1234!', null, $buyerUpi, 'Approval Buyer', "buyer{$suffix}@gmail.com", true);
$buyerId = (int)$buyerReg['user_id'];

// Fund seller with ₹5,000 available balance
Wallet::adminAdjust($sellerId, 'credit', 5000.00, 'Seed test balance for approval workflow', $adminId);
$sellerWallet = Wallet::getWallet($sellerId);
assertCondition((float)$sellerWallet['available_balance'] >= 5000.00, "Seller funded with ₹5,000 available balance");

// ====================================================================
// SECTION 1: Sell Order Creation, Locking & Marketplace Isolation
// ====================================================================
echo "\n--- SECTION 1: Sell Order Creation, Balance Locking & Isolation ---\n";

$initialAvail = (float)$sellerWallet['available_balance'];
$initialRes = (float)$sellerWallet['reserved_balance'];
$sellAmount = 1000.00;

$sellOrderRes = OrderEngine::createSellOrder($sellerId, $sellAmount, '1234', 'Approval Seller', $sellerUpi);
assertCondition($sellOrderRes['success'] === true, "Seller created sell order for ₹1,000");

$parentOrderId = (int)$sellOrderRes['parent_order_id'];
assertCondition($parentOrderId > 0, "Parent sell order created with ID #{$parentOrderId}");

// Verify Parent status is pending_approval
$pStmt = $pdo->prepare("SELECT * FROM parent_sell_orders WHERE id = ?");
$pStmt->execute([$parentOrderId]);
$parentOrder = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($parentOrder && $parentOrder['status'] === 'pending_approval', "Parent order status is 'pending_approval'");

// Verify Child orders status is 'Pending Approval'
$cStmt = $pdo->prepare("SELECT * FROM child_orders WHERE parent_order_id = ?");
$cStmt->execute([$parentOrderId]);
$childOrders = $cStmt->fetchAll(PDO::FETCH_ASSOC);
assertCondition(count($childOrders) > 0, "Parent order divided into " . count($childOrders) . " child portions");

$allPending = true;
foreach ($childOrders as $c) {
    if ($c['status'] !== 'Pending Approval') {
        $allPending = false;
        break;
    }
}
assertCondition($allPending, "All child order portions have status 'Pending Approval'");

// Verify Wallet Balance locking
$updatedSellerWallet = Wallet::getWallet($sellerId);
$expectedAvail = round($initialAvail - $sellAmount, 2);
$expectedRes = round($initialRes + $sellAmount, 2);
assertCondition((float)$updatedSellerWallet['available_balance'] === $expectedAvail, "Seller available balance deducted by ₹{$sellAmount} (now ₹{$updatedSellerWallet['available_balance']})");
assertCondition((float)$updatedSellerWallet['reserved_balance'] === $expectedRes, "Seller reserved balance increased by ₹{$sellAmount} (now ₹{$updatedSellerWallet['reserved_balance']})");

// Verify Order does NOT appear in Buy Pool
$poolStmt = $pdo->prepare("
    SELECT c.id FROM child_orders c
    LEFT JOIN parent_sell_orders pso ON c.parent_order_id = pso.id
    WHERE c.status = 'Available' AND (pso.status = 'active' OR pso.id IS NULL) AND c.parent_order_id = ?
");
$poolStmt->execute([$parentOrderId]);
$visiblePortions = $poolStmt->fetchAll();
assertCondition(count($visiblePortions) === 0, "Pending sell order does NOT appear in public Buy marketplace pool");

// Verify Buyer cannot pick an unapproved child order
$testChildId = (int)$childOrders[0]['id'];
$pickRes = StateMachine::pickOrder($testChildId, $buyerId);
assertCondition($pickRes['success'] === false && str_contains($pickRes['error'], 'Order is no longer available'), "Buyer cannot pick pending approval order ('Order is no longer available')");

// Verify Seller Tracking dashboard shows status
$tracking = OrderEngine::getSellerTrackingData($sellerId);
$foundParentInTracking = null;
foreach ($tracking as $tp) {
    if ((int)$tp['id'] === $parentOrderId) {
        $foundParentInTracking = $tp;
        break;
    }
}
assertCondition($foundParentInTracking !== null, "Parent order appears in seller tracking dashboard data");
assertCondition($foundParentInTracking && $foundParentInTracking['status'] === 'pending_approval', "Dashboard reflects listing status as 'pending_approval'");

// ====================================================================
// SECTION 2: Admin Panel 'Pending Sell Orders' Tab & Query
// ====================================================================
echo "\n--- SECTION 2: Admin Panel 'Pending Sell Orders' Queue & Search ---\n";

$pendingList = AdminManager::getPendingSellOrders();
assertCondition($pendingList['total'] >= 1, "AdminManager::getPendingSellOrders finds at least 1 pending order");
assertCondition($pendingList['summary']['pending_count'] >= 1, "Summary metric pending_count >= 1");
assertCondition($pendingList['summary']['pending_volume'] >= $sellAmount, "Summary metric pending_volume reflects reserved volume");

// Check if our specific order is present with correct fields
$foundPending = null;
foreach ($pendingList['orders'] as $po) {
    if ((int)$po['id'] === $parentOrderId) {
        $foundPending = $po;
        break;
    }
}
assertCondition($foundPending !== null, "Newly created sell order #{$parentOrderId} is listed in Admin Pending Sell Orders queue");
assertCondition((int)$foundPending['seller_id'] === $sellerId, "Pending order displays correct Seller User ID (#{$sellerId})");
assertCondition((float)$foundPending['original_amount'] === $sellAmount, "Pending order displays correct Token Amount (₹{$sellAmount})");
assertCondition($foundPending['snapshotted_upi_id'] === $sellerUpi, "Pending order displays correct selected UPI ID ({$sellerUpi})");

// Test Search filtering in Admin Queue
$searchByOrderId = AdminManager::getPendingSellOrders((string)$parentOrderId);
$foundInOrderSearch = false;
foreach ($searchByOrderId['orders'] as $so) {
    if ((int)$so['id'] === $parentOrderId) {
        $foundInOrderSearch = true;
        break;
    }
}
assertCondition($searchByOrderId['total'] >= 1 && $foundInOrderSearch, "Search by Order ID accurately finds target pending sell order");

$searchByUserId = AdminManager::getPendingSellOrders((string)$sellerId);
$foundInUserSearch = false;
foreach ($searchByUserId['orders'] as $su) {
    if ((int)$su['seller_id'] === $sellerId) {
        $foundInUserSearch = true;
        break;
    }
}
assertCondition($searchByUserId['total'] >= 1 && $foundInUserSearch, "Search by User ID accurately finds target pending sell order");

$searchByUpi = AdminManager::getPendingSellOrders($sellerUpi);
$foundInUpiSearch = false;
foreach ($searchByUpi['orders'] as $supi) {
    if ($supi['snapshotted_upi_id'] === $sellerUpi) {
        $foundInUpiSearch = true;
        break;
    }
}
assertCondition($searchByUpi['total'] >= 1 && $foundInUpiSearch, "Search by UPI ID accurately finds target pending sell order");

// ====================================================================
// SECTION 3: Admin Action - APPROVE Sell Order
// ====================================================================
echo "\n--- SECTION 3: Admin Action - APPROVE Sell Order ---\n";

$approveRes = AdminManager::approveSellOrder($parentOrderId, $adminId);
assertCondition($approveRes['success'] === true, "AdminManager::approveSellOrder returns success");

// Verify Parent order status is now 'active'
$pStmt->execute([$parentOrderId]);
$updatedParent = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($updatedParent && $updatedParent['status'] === 'active', "Parent sell order status transitioned to 'active'");

// Verify Child orders status transitioned to 'Available'
$cStmt->execute([$parentOrderId]);
$updatedChildren = $cStmt->fetchAll(PDO::FETCH_ASSOC);
$allAvailable = true;
foreach ($updatedChildren as $uc) {
    if ($uc['status'] !== 'Available') {
        $allAvailable = false;
        break;
    }
}
assertCondition($allAvailable, "All child order micro-portions transitioned to 'Available'");

// Verify Order now appears in public Buy marketplace pool
$poolStmt->execute([$parentOrderId]);
$activePortionsInPool = $poolStmt->fetchAll();
assertCondition(count($activePortionsInPool) === count($childOrders), "All approved child portions now appear live in public Buy marketplace pool");

// Verify Buyer can now successfully reserve/pick the approved order
$approvedChildId = (int)$updatedChildren[0]['id'];
$pickApprovedRes = StateMachine::pickOrder($approvedChildId, $buyerId);
assertCondition($pickApprovedRes['success'] === true, "Buyer successfully picked approved order #{$approvedChildId}");

// Verify Admin Audit Log was created
$auditStmt = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE target_id = ? AND action_type = 'APPROVE_SELL_ORDER'");
$auditStmt->execute([(string)$parentOrderId]);
$auditEntry = $auditStmt->fetch();
assertCondition($auditEntry !== false, "Admin audit log entry recorded for APPROVE_SELL_ORDER");

// Verify Notification created for seller
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'order_approved' AND reference_id = ?");
$notifStmt->execute([$sellerId, (string)$parentOrderId]);
$notifEntry = $notifStmt->fetch();
assertCondition($notifEntry !== false, "Notification created for seller informing them order was approved");

// Verify Idempotency: Approving an already active order fails cleanly
$repeatApprove = AdminManager::approveSellOrder($parentOrderId, $adminId);
assertCondition($repeatApprove['success'] === false && str_contains($repeatApprove['error'], 'already active'), "Repeat approve on active order is guarded");

// ====================================================================
// SECTION 4: Admin Action - REJECT Sell Order & Instant Refund
// ====================================================================
echo "\n--- SECTION 4: Admin Action - REJECT Sell Order & Instant Refund ---\n";

// Seller creates a second order for ₹500
$sellAmount2 = 500.00;
$sellerWalletBefore2 = Wallet::getWallet($sellerId);
$availBefore2 = (float)$sellerWalletBefore2['available_balance'];
$resBefore2 = (float)$sellerWalletBefore2['reserved_balance'];

$sellOrderRes2 = OrderEngine::createSellOrder($sellerId, $sellAmount2, '1234', 'Approval Seller', $sellerUpi);
assertCondition($sellOrderRes2['success'] === true, "Seller created second sell order for ₹500");
$parentOrderId2 = (int)$sellOrderRes2['parent_order_id'];

// Check balances locked
$sellerWalletLocked2 = Wallet::getWallet($sellerId);
assertCondition((float)$sellerWalletLocked2['available_balance'] === round($availBefore2 - $sellAmount2, 2), "₹500 deducted from available balance");
assertCondition((float)$sellerWalletLocked2['reserved_balance'] === round($resBefore2 + $sellAmount2, 2), "₹500 added to reserved balance");

// Admin rejects the second order
$rejectReason = "Unverified bank account details";
$rejectRes = AdminManager::rejectSellOrder($parentOrderId2, $adminId, $rejectReason);
assertCondition($rejectRes['success'] === true, "AdminManager::rejectSellOrder returns success");

// Verify Parent order status is 'rejected'
$pStmt->execute([$parentOrderId2]);
$rejectedParent = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($rejectedParent && $rejectedParent['status'] === 'rejected', "Parent order status transitioned to 'rejected'");
assertCondition((float)$rejectedParent['remaining_amount'] === 0.00, "Parent remaining_amount set to 0.00");

// Verify Child orders are 'Cancelled'
$cStmt->execute([$parentOrderId2]);
$rejectedChildren = $cStmt->fetchAll(PDO::FETCH_ASSOC);
$allCancelled = true;
foreach ($rejectedChildren as $rc) {
    if ($rc['status'] !== 'Cancelled') {
        $allCancelled = false;
        break;
    }
}
assertCondition($allCancelled, "All child order micro-portions transitioned to 'Cancelled'");

// Verify INSTANT REFUND of locked tokens back to user's available wallet balance
$sellerWalletAfterReject = Wallet::getWallet($sellerId);
assertCondition((float)$sellerWalletAfterReject['available_balance'] === $availBefore2, "Locked tokens (₹{$sellAmount2}) instantly refunded to Available Balance (now ₹{$sellerWalletAfterReject['available_balance']})");
assertCondition((float)$sellerWalletAfterReject['reserved_balance'] === $resBefore2, "Reserved balance restored to previous value (now ₹{$sellerWalletAfterReject['reserved_balance']})");

// Verify Ledger recorded the refund
$ledgerStmt = $pdo->prepare("SELECT * FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'SELL_RELEASE' AND reference_id = ?");
$ledgerStmt->execute([$sellerId, (string)$parentOrderId2]);
$ledgerRow = $ledgerStmt->fetch();
assertCondition($ledgerRow !== false && (float)$ledgerRow['amount'] === $sellAmount2, "Wallet ledger recorded SELL_RELEASE refund of ₹{$sellAmount2}");

// Verify Admin Audit Log was created
$auditStmt2 = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE target_id = ? AND action_type = 'REJECT_SELL_ORDER'");
$auditStmt2->execute([(string)$parentOrderId2]);
$auditEntry2 = $auditStmt2->fetch();
assertCondition($auditEntry2 !== false, "Admin audit log entry recorded for REJECT_SELL_ORDER");

// Verify Notification created for seller
$notifStmt2 = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND type = 'order_rejected' AND reference_id = ?");
$notifStmt2->execute([$sellerId, (string)$parentOrderId2]);
$notifEntry2 = $notifStmt2->fetch();
assertCondition($notifEntry2 !== false, "Notification created for seller informing them order was rejected and refunded");

// Verify Idempotency: Rejecting an already rejected order fails cleanly
$repeatReject = AdminManager::rejectSellOrder($parentOrderId2, $adminId);
assertCondition($repeatReject['success'] === false, "Repeat reject on rejected order is guarded");

// ====================================================================
// SECTION 5: Seller Self-Cancellation While Pending Approval
// ====================================================================
echo "\n--- SECTION 5: Seller Self-Cancellation While Pending Approval ---\n";

// Seller creates a third order for ₹300
$sellAmount3 = 300.00;
$availBefore3 = (float)Wallet::getWallet($sellerId)['available_balance'];
$sellOrderRes3 = OrderEngine::createSellOrder($sellerId, $sellAmount3, '1234', 'Approval Seller', $sellerUpi);
assertCondition($sellOrderRes3['success'] === true, "Seller created third sell order for ₹300");
$parentOrderId3 = (int)$sellOrderRes3['parent_order_id'];

// Seller cancels before admin approval
$cancelRes = OrderEngine::cancelParentSellOrder($sellerId, $parentOrderId3);
assertCondition($cancelRes['success'] === true, "Seller manually cancelled pending approval listing #{$parentOrderId3}");

$availAfterCancel = (float)Wallet::getWallet($sellerId)['available_balance'];
assertCondition($availAfterCancel === $availBefore3, "Tokens (₹{$sellAmount3}) immediately refunded back to seller available balance on self-cancellation");

$pStmt->execute([$parentOrderId3]);
$cancelledParent = $pStmt->fetch(PDO::FETCH_ASSOC);
assertCondition($cancelledParent && $cancelledParent['status'] === 'cancelled', "Parent order status is 'cancelled'");

// ====================================================================
// SUMMARY
// ====================================================================
echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED (" . round(($passed / ($passed + $failed)) * 100, 1) . "%)\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
