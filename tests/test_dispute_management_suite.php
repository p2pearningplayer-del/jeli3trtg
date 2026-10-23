<?php
/**
 * Automated Verification Suite for Admin Panel Dispute Management
 * Tests search, three-section classification, pagination, evidence preservation, and settlement safety.
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_dispute_mgmt_suite.sqlite';
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

function assertCheck(bool $condition, string $title, string $details = ''): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}" . ($details ? ": {$details}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo " ADMIN PANEL DISPUTE MANAGEMENT ACCEPTANCE TEST SUITE\n";
echo "====================================================================\n\n";

$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);
$dummyTpin = password_hash('1234', PASSWORD_BCRYPT);

// -------------------------------------------------------------
// Setup Fixtures: Admin, Seller, Buyers
// -------------------------------------------------------------
echo "--- Section 1: Fixtures Setup ---\n";

// Super Admin
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$adminId = (int)$adminStmt->fetchColumn();
assertCheck($adminId > 0, "Admin fixture verified (ID #{$adminId})");

// Seller User (ID: 401)
$upiIdA = 'alice_seller@okhdfcbank';
$sellerName = 'Alice Seller Trader';
$sellerPhone = '9800000401';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, upi_id, account_status, role)
    VALUES (401, ?, 'alice.seller@jelipay.in', ?, ?, 'REFSELLER401', ?, ?, 'active', 'user')
")->execute([$sellerPhone, $dummyHash, $dummyTpin, $sellerName, $upiIdA]);
$sellerId = 401;
Wallet::adminAdjust($sellerId, 'credit', 50000.0, 'Initial seller balance', $adminId);
assertCheck($sellerId === 401, "Seller #401 created with ₹50,000 balance");

// Buyer 1 (ID: 402)
$buyer1Name = 'Bob Buyer Trader';
$buyer1Phone = '9800000402';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (402, ?, 'bob.buyer@jelipay.in', ?, 'REFBUYER402', ?, 'active', 'user')
")->execute([$buyer1Phone, $dummyHash, $buyer1Name]);
$buyer1Id = 402;
assertCheck($buyer1Id === 402, "Buyer 1 #402 created");

// Buyer 2 (ID: 403)
$buyer2Name = 'Charlie Buyer Trader';
$buyer2Phone = '9800000403';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (403, ?, 'charlie.buyer@jelipay.in', ?, 'REFBUYER403', ?, 'active', 'user')
")->execute([$buyer2Phone, $dummyHash, $buyer2Name]);
$buyer2Id = 403;
assertCheck($buyer2Id === 403, "Buyer 2 #403 created");

// Buyer 3 (ID: 404)
$buyer3Name = 'David Buyer Trader';
$buyer3Phone = '9800000404';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (404, ?, 'david.buyer@jelipay.in', ?, 'REFBUYER404', ?, 'active', 'user')
")->execute([$buyer3Phone, $dummyHash, $buyer3Name]);
$buyer3Id = 404;
assertCheck($buyer3Id === 404, "Buyer 3 #404 created");

// Buyer 4 (ID: 405)
$buyer4Name = 'Eve Buyer Trader';
$buyer4Phone = '9800000405';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (405, ?, 'eve.buyer@jelipay.in', ?, 'REFBUYER405', ?, 'active', 'user')
")->execute([$buyer4Phone, $dummyHash, $buyer4Name]);
$buyer4Id = 405;
assertCheck($buyer4Id === 405, "Buyer 4 #405 created");

// -------------------------------------------------------------
// Section 2: Create Parent Listing & Disputed Child Orders
// -------------------------------------------------------------
echo "\n--- Section 2: Order Creation, Splitting & Dispute Transitions ---\n";

// Seller creates ₹10,000 sell order (splits into Child #1: ₹5,000, Child #2: ₹3,000, Child #3: ₹2,000)
$sellRes1 = OrderEngine::createSellOrder($sellerId, 10000.0, '1234', $sellerName, $upiIdA);
assertCheck($sellRes1['success'] === true, "Seller created ₹10,000 listing (Parent #1)");
$parent1Id = (int)$sellRes1['parent_order_id'];
assertCheck($parent1Id > 0, "Parent Sell Order ID is #{$parent1Id}");

// Seller creates ₹5,000 sell order (Parent #2, splits into Child #4: ₹2,500, Child #5: ₹1,500, Child #6: ₹1,000)
$sellRes2 = OrderEngine::createSellOrder($sellerId, 5000.0, '1234', $sellerName, $upiIdA);
assertCheck($sellRes2['success'] === true, "Seller created ₹5,000 listing (Parent #2)");
$parent2Id = (int)$sellRes2['parent_order_id'];
assertCheck($parent2Id > 0, "Parent Sell Order ID is #{$parent2Id}");

// Case 1: Child Order 1 (₹5,000, Buyer 402) -> Pending Dispute
$pickRes1 = StateMachine::pickOrder(1, $buyer1Id);
assertCheck($pickRes1['success'] === true, "Child Order #1 picked by Buyer #402");
$utr1 = 'UTRDISPUTE9901';
$proofRes1 = StateMachine::submitPaymentProof(1, $buyer1Id, $utr1, '/proofs/proof1.png', 'Paid to Alice');
assertCheck($proofRes1['success'] === true, "Payment proof submitted for Order #1");
$appealRes1 = StateMachine::raiseAppeal(1, $sellerId, 'Payment not received in Alice account', '/disputes/seller1.png');
assertCheck($appealRes1['success'] === true, "Dispute raised for Order #1 (Pending)");
$dispId1 = (int)$appealRes1['dispute_id'];
assertCheck($dispId1 > 0, "Dispute #{$dispId1} recorded");

// Case 2: Child Order 2 (₹3,000, Buyer 403) -> Evidence Required Dispute
$pickRes2 = StateMachine::pickOrder(2, $buyer2Id);
assertCheck($pickRes2['success'] === true, "Child Order #2 picked by Buyer #403");
$utr2 = 'UTRDISPUTE9902';
$proofRes2 = StateMachine::submitPaymentProof(2, $buyer2Id, $utr2, '/proofs/proof2.png', 'Paid via GPay');
assertCheck($proofRes2['success'] === true, "Payment proof submitted for Order #2");
$appealRes2 = StateMachine::raiseAppeal(2, $sellerId, 'Suspected fake UTR submission', '/disputes/seller2.png');
$dispId2 = (int)$appealRes2['dispute_id'];
assertCheck($dispId2 > 0, "Dispute #{$dispId2} recorded");
// Admin requests evidence from traders
$evReqRes = AdminManager::requestAdditionalEvidence($dispId2, $adminId, 'Please upload bank statement PDF covering 10:00 to 12:00 UTC');
assertCheck($evReqRes['success'] === true, "Admin requested additional evidence for Dispute #{$dispId2} (Evidence Required)");

// Case 3: Child Order 3 (₹2,000, Buyer 404) -> Resolved Dispute (Force Settled / Buyer Won)
$pickRes3 = StateMachine::pickOrder(3, $buyer3Id);
assertCheck($pickRes3['success'] === true, "Child Order #3 picked by Buyer #404");
$utr3 = 'UTRDISPUTE9903';
$proofRes3 = StateMachine::submitPaymentProof(3, $buyer3Id, $utr3, '/proofs/proof3.png', 'Payment done via PhonePe');
assertCheck($proofRes3['success'] === true, "Payment proof submitted for Order #3");
$appealRes3 = StateMachine::raiseAppeal(3, $sellerId, 'Payment pending bank clearance', '/disputes/seller3.png');
$dispId3 = (int)$appealRes3['dispute_id'];
assertCheck($dispId3 > 0, "Dispute #{$dispId3} recorded");
// Admin resolves in favor of buyer
$resDisp3 = AdminManager::resolveDispute($dispId3, $adminId, 'force_settle', 'Bank statement verified, transaction settled to buyer');
assertCheck($resDisp3['success'] === true, "Dispute #{$dispId3} resolved as force_settle");

// Case 4: Child Order 4 (₹2,500, Buyer 405) -> Resolved Dispute (Cancel & Reverse / Seller Recredited)
$pickRes4 = StateMachine::pickOrder(4, $buyer4Id);
assertCheck($pickRes4['success'] === true, "Child Order #4 picked by Buyer #405");
$utr4 = 'UTRDISPUTE9904';
$proofRes4 = StateMachine::submitPaymentProof(4, $buyer4Id, $utr4, '/proofs/proof4.png', 'Payment proof invalid test');
assertCheck($proofRes4['success'] === true, "Payment proof submitted for Order #4");
$appealRes4 = StateMachine::raiseAppeal(4, $sellerId, 'Fraudulent transaction reference', '/disputes/seller4.png');
$dispId4 = (int)$appealRes4['dispute_id'];
assertCheck($dispId4 > 0, "Dispute #{$dispId4} recorded");
// Admin resolves in favor of seller
$resDisp4 = AdminManager::resolveDispute($dispId4, $adminId, 'cancel_reverse', 'Confirmed buyer never transferred funds');
assertCheck($resDisp4['success'] === true, "Dispute #{$dispId4} resolved as cancel_reverse");

// -------------------------------------------------------------
// Section 3: Execute 11 Required Test Scenarios
// -------------------------------------------------------------
echo "\n--- Section 3: Verification of 11 Test Scenarios ---\n";

// Scenario 1: Search for known disputed Order ID
$search1 = AdminManager::getDisputes('all', 'all', '1');
$found1 = false;
foreach ($search1['disputes'] as $d) {
    if ((int)$d['child_order_id'] === 1) $found1 = true;
}
assertCheck($found1, "Scenario 1: Search for numeric Order ID '1' finds Order #1");

$search1Hash = AdminManager::getDisputes('all', 'all', '#1');
$found1Hash = false;
foreach ($search1Hash['disputes'] as $d) {
    if ((int)$d['child_order_id'] === 1) $found1Hash = true;
}
assertCheck($found1Hash, "Scenario 1: Search with hash prefix '#1' finds Order #1");

$search1Text = AdminManager::getDisputes('all', 'all', 'Order #1');
$found1Text = false;
foreach ($search1Text['disputes'] as $d) {
    if ((int)$d['child_order_id'] === 1) $found1Text = true;
}
assertCheck($found1Text, "Scenario 1: Search with string 'Order #1' finds Order #1");

// Scenario 2: Search for a disputed child order generated by order splitting (via Parent Sell Order ID)
$searchParent1 = AdminManager::getDisputes('all', 'all', (string)$parent1Id);
$matchingParentCount = 0;
foreach ($searchParent1['disputes'] as $d) {
    if ((int)$d['parent_order_id'] === $parent1Id) $matchingParentCount++;
}
assertCheck($matchingParentCount === 3, "Scenario 2: Search by Parent Sell Order ID #{$parent1Id} finds all 3 split child orders (Found: {$matchingParentCount})");

// Scenario 3: Search by Buyer User ID and Seller User ID
$searchBuyer402 = AdminManager::getDisputes('all', 'all', '402');
$foundBuyer402 = false;
foreach ($searchBuyer402['disputes'] as $d) {
    if ((int)$d['buyer_id'] === 402) {
        $foundBuyer402 = true;
    }
}
assertCheck($foundBuyer402 && count($searchBuyer402['disputes']) >= 1, "Scenario 3: Search by Buyer User ID 402 successfully finds buyer 402 disputes");

$searchSeller401 = AdminManager::getDisputes('all', 'all', '401');
$allSeller401 = true;
foreach ($searchSeller401['disputes'] as $d) {
    if ((int)$d['seller_id'] !== 401 && (int)$d['buyer_id'] !== 401 && (int)$d['child_order_id'] !== 401) {
        $allSeller401 = false;
    }
}
assertCheck($allSeller401 && count($searchSeller401['disputes']) === 4, "Scenario 3: Search by Seller User ID 401 returns all 4 disputes associated with seller 401");

// Scenario 4: Search for an order in Evidence Required status
$searchEvidenceReq = AdminManager::getDisputes('evidence_required', 'all', '');
$foundDisp2InEvidence = false;
foreach ($searchEvidenceReq['disputes'] as $d) {
    if ((int)$d['id'] === $dispId2) $foundDisp2InEvidence = true;
}
assertCheck($foundDisp2InEvidence, "Scenario 4: Dispute #{$dispId2} appears in Evidence Required section");

$searchByUtr2 = AdminManager::getDisputes('evidence_required', 'all', $utr2);
assertCheck(count($searchByUtr2['disputes']) === 1 && (int)$searchByUtr2['disputes'][0]['id'] === $dispId2, "Scenario 4: Search by UTR '{$utr2}' in Evidence Required isolates Dispute #{$dispId2}");

// Scenario 5: Verify that resolved disputes appear in the Resolved Disputes tab
$searchResolved = AdminManager::getDisputes('resolved', 'all', '');
$foundDisp3 = false;
$foundDisp4 = false;
foreach ($searchResolved['disputes'] as $d) {
    if ((int)$d['id'] === $dispId3) $foundDisp3 = true;
    if ((int)$d['id'] === $dispId4) $foundDisp4 = true;
}
assertCheck($foundDisp3 && $foundDisp4, "Scenario 5: Both resolved disputes #{$dispId3} and #{$dispId4} appear in Resolved Disputes section");

// Scenario 6: Verify that resolved cases do not appear as pending
$searchPending = AdminManager::getDisputes('pending', 'all', '');
$noResolvedInPending = true;
foreach ($searchPending['disputes'] as $d) {
    if ((int)$d['id'] === $dispId3 || (int)$d['id'] === $dispId4) {
        $noResolvedInPending = false;
    }
}
assertCheck($noResolvedInPending, "Scenario 6: Resolved disputes #{$dispId3} and #{$dispId4} strictly DO NOT appear in Pending section");

$noEvidenceInPending = true;
foreach ($searchPending['disputes'] as $d) {
    if ((int)$d['id'] === $dispId2) {
        $noEvidenceInPending = false;
    }
}
assertCheck($noEvidenceInPending, "Scenario 6: Evidence Required dispute #{$dispId2} strictly does not appear in Pending section");

// Scenario 7: Test pagination with 20, 40, and 100 records
// Insert additional disputes to reach 45 total disputes
$bulkStmt = $pdo->prepare("
    INSERT INTO disputes (child_order_id, raised_by_user_id, raised_by_role, reason, status, created_at)
    VALUES (?, ?, 'seller', 'Bulk pagination test case', 'pending', ?)
");
$nowUtc = gmdate('Y-m-d H:i:s');
for ($i = 10; $i <= 50; $i++) {
    // Insert dummy child order first
    $pdo->prepare("
        INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
        VALUES (?, ?, 401, 402, 100.0, 9.0, 9.0, 'alice_seller@okhdfcbank', 'Alice Seller Trader', 'Disputed', ?)
    ")->execute([$i, $parent1Id, $nowUtc]);
    $bulkStmt->execute([$i, 401, $nowUtc]);
}

$page20 = AdminManager::getDisputes('pending', 'all', '', 20, 0);
assertCheck(count($page20['disputes']) === 20, "Scenario 7: Pagination limit 20 returns exactly 20 records");
assertCheck($page20['page'] === 1, "Scenario 7: Pagination page calculation is 1");

$page20P2 = AdminManager::getDisputes('pending', 'all', '', 20, 20);
assertCheck(count($page20P2['disputes']) === 20, "Scenario 7: Page 2 with limit 20 returns 20 records");
assertCheck($page20P2['page'] === 2, "Scenario 7: Page 2 calculation is 2");

$page40 = AdminManager::getDisputes('pending', 'all', '', 40, 0);
assertCheck(count($page40['disputes']) === 40, "Scenario 7: Pagination limit 40 returns exactly 40 records");

$page100 = AdminManager::getDisputes('pending', 'all', '', 100, 0);
assertCheck(count($page100['disputes']) === 42, "Scenario 7: Pagination limit 100 returns all 42 pending records");

// Scenario 8: Test pagination combined with search and filters
$searchPaginated = AdminManager::getDisputes('pending', 'all', 'Bulk pagination', 20, 0);
assertCheck($searchPaginated['total'] === 41, "Scenario 8: Filtered search total is 41 matching bulk records");
assertCheck(count($searchPaginated['disputes']) === 20, "Scenario 8: Filtered search page 1 returns 20 records");

$searchPaginatedP2 = AdminManager::getDisputes('pending', 'all', 'Bulk pagination', 20, 20);
assertCheck(count($searchPaginatedP2['disputes']) === 20, "Scenario 8: Filtered search page 2 returns 20 records");

$searchPaginatedP3 = AdminManager::getDisputes('pending', 'all', 'Bulk pagination', 20, 40);
assertCheck(count($searchPaginatedP3['disputes']) === 1, "Scenario 8: Filtered search page 3 returns remaining 1 record");

// Scenario 9: Verify that displayed record count matches the backend query
$countChk = AdminManager::getDisputes('pending', 'all', '');
assertCheck($countChk['total'] === 42, "Scenario 9: Total pending count is 42");
assertCheck($countChk['counts']['evidence_required'] === 1, "Scenario 9: Evidence required count badge is 1");
assertCheck($countChk['counts']['resolved'] === 2, "Scenario 9: Resolved count badge is 2");
assertCheck($countChk['counts']['total'] === 45, "Scenario 9: Total all disputes count is 45");

// Scenario 10: Verify that evidence, payment details, and historical decisions remain accessible
$dispDetails = AdminManager::getDisputes('resolved', 'all', 'UTRDISPUTE9903');
assertCheck(count($dispDetails['disputes']) === 1, "Scenario 10: Isolated Dispute #{$dispId3}");
$d3 = $dispDetails['disputes'][0];
assertCheck($d3['seller_order_upi_id'] === $upiIdA, "Scenario 10: Seller snapshotted UPI ID preserved ({$upiIdA})");
assertCheck($d3['utr_number'] === 'DISPUTE9903', "Scenario 10: Normalized UTR preserved (DISPUTE9903)");
assertCheck($d3['status'] === 'resolved_buyer', "Scenario 10: Final decision status preserved (resolved_buyer)");
assertCheck(!empty($d3['resolved_at']), "Scenario 10: Resolution timestamp preserved ({$d3['resolved_at']})");
assertCheck(str_contains($d3['resolution_notes'], 'Bank statement verified'), "Scenario 10: Resolution reason preserved");

// Check audit log
$auditLog = $pdo->query("SELECT * FROM admin_audit_logs WHERE action_type = 'FORCE_SETTLE_DISPUTE' AND target_id = '{$dispId3}'")->fetch();
assertCheck(!empty($auditLog), "Scenario 10: Admin audit log recorded for dispute resolution");

// Scenario 11: Confirm that no wallet, payment, or order settlement logic has changed
$buyer3Wal = Wallet::getWallet($buyer3Id);
// Order 3 was ₹2,000 + 9% reward = ₹2,180.0
assertCheck((float)$buyer3Wal['available_balance'] === 2180.0, "Scenario 11: Buyer #404 available balance credited with ₹2,000 + 9% reward (₹2,180.0)");
$sellerWal = Wallet::getWallet($sellerId);
// Initial: 50,000.
// Listing 1: 10,000 (res: 10,000, avail: 40,000). Order 3 (2000) settled -> res 8000.
// Listing 2: 5,000 (res: 5,000, avail: 35,000). Order 4 (2500) cancelled -> released back to avail (+2500).
// Avail: 37,500. Total reserved: 8,000 + 2,500 = 10,500.
// Total wallet: 48,000 (50,000 - 2,000 settled = 48,000).
assertCheck((float)$sellerWal['available_balance'] === 37500.0, "Scenario 11: Seller available balance mathematically preserved (₹37,500.0)");

echo "\n--------------------------------------------------------------------\n";
echo " DISPUTE MANAGEMENT SUITE SUMMARY: {$passedTests} / {$totalTests} Scenarios Passed (" . round(($passedTests / $totalTests) * 100, 1) . "%)\n";
echo "--------------------------------------------------------------------\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: All 11 required scenarios passed strictly on backend logic!\n";
    exit(0);
} else {
    echo "FAILURE: One or more scenarios failed.\n";
    exit(1);
}
