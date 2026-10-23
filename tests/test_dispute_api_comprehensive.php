<?php
/**
 * Comprehensive API Verification Suite for all Dispute & Appeal APIs:
 * - api/disputes.php (User Appeals Center: list, detail, upload_evidence)
 * - api/sell.php (Seller: raise_appeal)
 * - api/admin.php (Admin: disputes, get_dispute, arbitrate, request_evidence, update_refund)
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_dispute_api_comprehensive.sqlite';
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

function assertAPI(bool $condition, string $title, string $details = ''): void {
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
echo " COMPREHENSIVE DISPUTE APIS VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);
$dummyTpin = password_hash('1234', PASSWORD_BCRYPT);

// -------------------------------------------------------------
// Setup Fixtures: Admin, Dispute Manager, Finance Manager, Seller, Buyer, Third Party
// -------------------------------------------------------------
echo "--- Section 1: Fixtures & Users Setup ---\n";

// Super Admin (ID 1)
$adminRow = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$adminId = (int)$adminRow['id'];
assertAPI($adminId > 0, "Super admin fixture verified (ID #{$adminId})");

// Dispute Manager Admin (ID 201)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, role, admin_role, referral_code, account_status)
    VALUES (201, '9800000201', 'disp_manager@jelipay.in', ?, 'admin', 'dispute_manager', 'REFADM201', 'active')
")->execute([$dummyHash]);
$dispManagerUser = $pdo->query("SELECT * FROM users WHERE id = 201")->fetch();
assertAPI(!empty($dispManagerUser), "Dispute Manager fixture created (ID #201)");

// Finance Manager Admin (ID 202 - lacks disputes_arbitrate)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, role, admin_role, referral_code, account_status)
    VALUES (202, '9800000202', 'fin_manager@jelipay.in', ?, 'admin', 'finance_manager', 'REFADM202', 'active')
")->execute([$dummyHash]);
$finManagerUser = $pdo->query("SELECT * FROM users WHERE id = 202")->fetch();
assertAPI(!empty($finManagerUser), "Finance Manager fixture created (ID #202)");

// Seller (ID 501)
$sellerUpi = 'seller_sam@okhdfcbank';
$sellerName = 'Sam Seller';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, upi_id, account_status, role)
    VALUES (501, '9800000501', 'sam.seller@jelipay.in', ?, ?, 'REFSELLER501', ?, ?, 'active', 'user')
")->execute([$dummyHash, $dummyTpin, $sellerName, $sellerUpi]);
$sellerId = 501;
$sellerUser = $pdo->query("SELECT * FROM users WHERE id = 501")->fetch();
Wallet::adminAdjust($sellerId, 'credit', 20000.0, 'Initial seller balance', $adminId);
assertAPI($sellerId === 501, "Seller #501 fixture created");

// Buyer (ID 502)
$buyerName = 'Ben Buyer';
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (502, '9800000502', 'ben.buyer@jelipay.in', ?, 'REFBUYER502', ?, 'active', 'user')
")->execute([$dummyHash, $buyerName]);
$buyerId = 502;
$buyerUser = $pdo->query("SELECT * FROM users WHERE id = 502")->fetch();
assertAPI($buyerId === 502, "Buyer #502 fixture created");

// Third Party (ID 503 - Not a party to seller 501 / buyer 502 orders)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (503, '9800000503', 'third.party@jelipay.in', ?, 'REFTHIRD503', 'Third Party', 'active', 'user')
")->execute([$dummyHash]);
$thirdPartyId = 503;
$thirdPartyUser = $pdo->query("SELECT * FROM users WHERE id = 503")->fetch();
assertAPI($thirdPartyId === 503, "Third party user #503 fixture created");

// -------------------------------------------------------------
// Section 2: Order Creation & Appeal Lifecycle
// -------------------------------------------------------------
echo "\n--- Section 2: Order & Appeal Setup ---\n";

// Seller creates ₹2,000 listing (Order #1: ₹1,000, Order #2: ₹1,000)
$sellRes = OrderEngine::createSellOrder($sellerId, 2000.0, '1234', $sellerName, $sellerUpi);
assertAPI($sellRes['success'] === true, "Seller created ₹2,000 listing");
$parentId = (int)$sellRes['parent_order_id'];

// Buyer picks Order #1
$pickRes = StateMachine::pickOrder(1, $buyerId);
assertAPI($pickRes['success'] === true, "Buyer picked Order #1");

// Buyer submits payment proof
$utr = 'UTRDISPTEST8801';
$proofRes = StateMachine::submitPaymentProof(1, $buyerId, $utr, '/proofs/proof8801.png', 'Transferred to Sam');
assertAPI($proofRes['success'] === true, "Buyer submitted payment proof with UTR {$utr}");

// Seller raises appeal
$appealRes = StateMachine::raiseAppeal(1, $sellerId, 'Payment not received in Sam account', '/proofs/seller_statement.png');
assertAPI($appealRes['success'] === true, "Seller successfully raised appeal");
$disputeId = (int)$appealRes['dispute_id'];
assertAPI($disputeId > 0, "Dispute #{$disputeId} created");

// -------------------------------------------------------------
// Section 3: Test User Disputes API (api/disputes.php)
// -------------------------------------------------------------
echo "\n--- Section 3: User Disputes API (api/disputes.php) ---\n";

// Helper function to simulate api/disputes.php in an isolated process
function callDisputesApi(array $user, string $action, array $params = [], array $input = []): array {
    global $testDbFile;
    $queryParams = array_merge(['action' => $action], $params);
    $encodedQuery = base64_encode(http_build_query($queryParams));
    $encodedInput = base64_encode(json_encode($input));
    $userId = (int)$user['id'];

    $code = "
        putenv('DB_DRIVER=sqlite');
        \$_ENV['DB_DRIVER'] = 'sqlite';
        putenv('SQLITE_PATH=' . '{$testDbFile}');
        \$_ENV['SQLITE_PATH'] = '{$testDbFile}';
        session_start();
        \$_SESSION['user_id'] = {$userId};
        parse_str(base64_decode('{$encodedQuery}'), \$_GET);
        \$_POST = json_decode(base64_decode('{$encodedInput}'), true) ?: [];
        include __DIR__ . '/api/disputes.php';
    ";
    $codeEncoded = base64_encode($code);
    $out = shell_exec("php -r \"eval(base64_decode('{$codeEncoded}'));\"");
    $decoded = json_decode((string)$out, true);
    return is_array($decoded) ? $decoded : ['raw' => $out];
}

// Helper function to simulate api/admin.php in an isolated process
function callAdminApi(array $admin, string $action, array $params = [], array $input = []): array {
    global $testDbFile;
    $queryParams = array_merge(['action' => $action], $params);
    $encodedQuery = base64_encode(http_build_query($queryParams));
    $encodedInput = base64_encode(json_encode($input));
    $adminId = (int)$admin['id'];

    $code = "
        putenv('DB_DRIVER=sqlite');
        \$_ENV['DB_DRIVER'] = 'sqlite';
        putenv('SQLITE_PATH=' . '{$testDbFile}');
        \$_ENV['SQLITE_PATH'] = '{$testDbFile}';
        session_start();
        \$_SESSION['user_id'] = {$adminId};
        parse_str(base64_decode('{$encodedQuery}'), \$_GET);
        \$_POST = json_decode(base64_decode('{$encodedInput}'), true) ?: [];
        include __DIR__ . '/api/admin.php';
    ";
    $codeEncoded = base64_encode($code);
    $out = shell_exec("php -r \"eval(base64_decode('{$codeEncoded}'));\"");
    $decoded = json_decode((string)$out, true);
    return is_array($decoded) ? $decoded : ['raw' => $out];
}

// 3.1 Seller lists disputes -> Buyer privacy enforced
$sellerList = callDisputesApi($sellerUser, 'list');
assertAPI($sellerList['success'] === true, "api/disputes.php?action=list succeeds for Seller");
assertAPI(count($sellerList['disputes']) >= 1, "Seller sees at least 1 dispute");
$sellerViewDisp = $sellerList['disputes'][0];
assertAPI(!isset($sellerViewDisp['buyer_name']), "CRITICAL: Buyer name is strictly omitted from seller dispute view");
assertAPI(!isset($sellerViewDisp['buyer_phone']), "CRITICAL: Buyer phone is strictly omitted from seller dispute view");
assertAPI((int)$sellerViewDisp['child_order_id'] === 1, "Dispute order ID #1 verified");

// 3.2 Buyer lists disputes -> Seller name masked
$buyerList = callDisputesApi($buyerUser, 'list');
assertAPI($buyerList['success'] === true, "api/disputes.php?action=list succeeds for Buyer");
assertAPI(count($buyerList['disputes']) >= 1, "Buyer sees at least 1 dispute");
$buyerViewDisp = $buyerList['disputes'][0];
assertAPI(!empty($buyerViewDisp['seller_name_masked']), "Seller name masked for buyer ({$buyerViewDisp['seller_name_masked']})");

// 3.3 Third party lists disputes -> Should see 0 disputes
$thirdPartyList = callDisputesApi($thirdPartyUser, 'list');
assertAPI($thirdPartyList['success'] === true && count($thirdPartyList['disputes']) === 0, "Third party user sees 0 disputes (Security check passed)");

// 3.4 Dispute Detail endpoint: Authorized party vs Unauthorized
$detailBuyer = callDisputesApi($buyerUser, 'detail', ['dispute_id' => $disputeId]);
assertAPI($detailBuyer['success'] === true && (int)$detailBuyer['dispute']['id'] === $disputeId, "Buyer can view dispute detail #{$disputeId}");

$detailThird = callDisputesApi($thirdPartyUser, 'detail', ['dispute_id' => $disputeId]);
assertAPI($detailThird['success'] === false && str_contains($detailThird['error'], 'Unauthorized'), "Third party detail request rejected with 403 Unauthorized");

// 3.5 Third party attempt to upload evidence -> Rejected
$unauthUpload = callDisputesApi($thirdPartyUser, 'upload_evidence', [], [
    'dispute_id' => $disputeId,
    'statement' => 'Attempting to interfere'
]);
assertAPI($unauthUpload['success'] === false && str_contains($unauthUpload['error'], 'Unauthorized'), "Unauthorized evidence upload rejected");

// 3.6 Buyer uploads additional evidence statement & bank proof
$buyerUpload = callDisputesApi($buyerUser, 'upload_evidence', [], [
    'dispute_id' => $disputeId,
    'evidence_path' => '/proofs/buyer_bank_statement.pdf',
    'statement' => 'Attached official bank statement showing debit timestamp matching UTR'
]);
assertAPI($buyerUpload['success'] === true, "Buyer successfully uploaded additional evidence");

// Verify dispute status in DB transitioned to 'under_review' and additional_evidence_requested reset
$dRow = $pdo->query("SELECT * FROM disputes WHERE id = {$disputeId}")->fetch();
assertAPI($dRow['status'] === 'under_review', "Dispute status transitioned to 'under_review'");
assertAPI((int)$dRow['additional_evidence_requested'] === 0, "additional_evidence_requested reset to 0");
assertAPI(str_contains($dRow['evidence_screenshot_path'], 'buyer_bank_statement.pdf'), "Evidence path recorded in database");
assertAPI(str_contains($dRow['resolution_notes'], 'Buyer Additional Note'), "Resolution log updated with timestamped buyer note");

// -------------------------------------------------------------
// Section 4: Test Admin Dispute APIs (api/admin.php)
// -------------------------------------------------------------
echo "\n--- Section 4: Admin Dispute APIs (api/admin.php) ---\n";

// 4.1 RBAC: Disputes list permissions
$financeList = callAdminApi($finManagerUser, 'disputes');
assertAPI($financeList['success'] === false && str_contains($financeList['error'], 'Access denied'), "Finance Manager (lacking disputes_view) cannot view disputes");

$dispManagerList = callAdminApi($dispManagerUser, 'disputes', ['section' => 'pending']);
assertAPI($dispManagerList['success'] === true, "Dispute Manager (with disputes_view) can list disputes");
assertAPI($dispManagerList['counts']['pending'] >= 1, "Pending count badge accurately reflects open dispute");

// 4.2 Multi-field Search via Admin API
$searchById = callAdminApi($adminRow, 'disputes', ['section' => 'all', 'query' => (string)$disputeId]);
assertAPI(count($searchById['disputes']) === 1 && (int)$searchById['disputes'][0]['id'] === $disputeId, "Admin search by Dispute ID #{$disputeId} isolates record");

$searchByHash = callAdminApi($adminRow, 'disputes', ['section' => 'all', 'query' => '#' . $disputeId]);
assertAPI(count($searchByHash['disputes']) === 1 && (int)$searchByHash['disputes'][0]['id'] === $disputeId, "Admin search by '#{$disputeId}' isolates record");

$searchByUtr = callAdminApi($adminRow, 'disputes', ['section' => 'all', 'query' => $utr]);
assertAPI(count($searchByUtr['disputes']) === 1, "Admin search by UTR '{$utr}' isolates record");

$searchByBuyer = callAdminApi($adminRow, 'disputes', ['section' => 'all', 'query' => (string)$buyerId]);
assertAPI(count($searchByBuyer['disputes']) >= 1, "Admin search by Buyer User ID #{$buyerId} finds record");

$searchBySeller = callAdminApi($adminRow, 'disputes', ['section' => 'all', 'query' => (string)$sellerId]);
assertAPI(count($searchBySeller['disputes']) >= 1, "Admin search by Seller User ID #{$sellerId} finds record");

// 4.3 Get Dispute Detail via Admin API
$adminDispDetail = callAdminApi($dispManagerUser, 'get_dispute', ['dispute_id' => $disputeId]);
assertAPI($adminDispDetail['success'] === true, "api/admin.php?action=get_dispute returns dispute detail");
assertAPI($adminDispDetail['dispute']['seller_order_upi_id'] === $sellerUpi, "Immutable snapshotted UPI ID preserved ({$sellerUpi})");

// Verify audit log for viewing payment details
$viewAudit = $pdo->query("SELECT * FROM admin_audit_logs WHERE action_type = 'VIEW_DISPUTE_PAYMENT_DETAILS' AND target_id = '{$disputeId}'")->fetch();
assertAPI(!empty($viewAudit), "VIEW_DISPUTE_PAYMENT_DETAILS audit logged");

// 4.4 Request Additional Evidence via Admin API
$reqEvRes = callAdminApi($dispManagerUser, 'request_evidence', [], [
    'dispute_id' => $disputeId,
    'instructions' => 'Please provide full bank passbook or statement with transaction time'
]);
assertAPI($reqEvRes['success'] === true, "Admin requested additional evidence via api/admin.php");

// Verify dispute is now in Evidence Required section
$evReqList = callAdminApi($dispManagerUser, 'disputes', ['section' => 'evidence_required']);
assertAPI(count($evReqList['disputes']) >= 1, "Dispute moved to Evidence Required section");

// 4.5 RBAC: Arbitrate permissions
$finArbitrate = callAdminApi($finManagerUser, 'arbitrate', [], [
    'dispute_id' => $disputeId,
    'decision' => 'force_settle',
    'resolution_notes' => 'Attempt by finance manager'
]);
assertAPI($finArbitrate['success'] === false && str_contains($finArbitrate['error'], 'Access denied'), "Finance Manager without disputes_arbitrate cannot arbitrate");

// 4.6 Arbitrate Dispute via Admin API (Force Settle)
$arbitrateRes = callAdminApi($dispManagerUser, 'arbitrate', [], [
    'dispute_id' => $disputeId,
    'decision' => 'force_settle',
    'resolution_notes' => 'Verified official bank statement provided by buyer. Funds received by seller.'
]);
assertAPI($arbitrateRes['success'] === true, "Dispute Manager arbitrated dispute with decision 'force_settle'");

// Verify dispute is now in Resolved section and excluded from Pending
$resolvedList = callAdminApi($dispManagerUser, 'disputes', ['section' => 'resolved']);
assertAPI(count($resolvedList['disputes']) >= 1, "Dispute moved to Resolved section");

$pendingAfter = callAdminApi($dispManagerUser, 'disputes', ['section' => 'pending']);
$inPending = false;
foreach ($pendingAfter['disputes'] as $d) {
    if ((int)$d['id'] === $disputeId) $inPending = true;
}
assertAPI(!$inPending, "Resolved dispute strictly excluded from Pending section");

// Verify resolution audit log
$settleAudit = $pdo->query("SELECT * FROM admin_audit_logs WHERE action_type = 'FORCE_SETTLE_DISPUTE' AND target_id = '{$disputeId}'")->fetch();
assertAPI(!empty($settleAudit), "FORCE_SETTLE_DISPUTE audit recorded in admin_audit_logs");

// 4.7 Update External Refund Tracking
$refundRes = callAdminApi($dispManagerUser, 'update_refund', [], [
    'dispute_id' => $disputeId,
    'status' => 'issued',
    'refund_reference' => 'REFUND-HDFC-99182',
    'notes' => 'Processed via bank gateway'
]);
assertAPI($refundRes['success'] === true, "Updated refund tracking via api/admin.php");

// 4.8 User upload attempt on finalized dispute -> Rejected
$uploadFinalized = callDisputesApi($buyerUser, 'upload_evidence', [], [
    'dispute_id' => $disputeId,
    'statement' => 'Attempt upload on finalized dispute'
]);
assertAPI($uploadFinalized['success'] === false && str_contains($uploadFinalized['error'], 'finalized'), "Evidence upload on finalized dispute strictly rejected");

echo "\n--------------------------------------------------------------------\n";
echo " DISPUTE APIS SUMMARY: {$passedTests} / {$totalTests} Scenarios Passed (" . round(($passedTests / $totalTests) * 100, 1) . "%)\n";
echo "--------------------------------------------------------------------\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: All Dispute & Appeal API endpoints verified with zero errors!\n";
    exit(0);
} else {
    echo "FAILURE: One or more dispute API scenarios failed.\n";
    exit(1);
}
