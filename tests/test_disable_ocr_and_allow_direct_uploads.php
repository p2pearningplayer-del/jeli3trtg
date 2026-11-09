<?php
/**
 * Test Suite: Disable OCR / AI Validation on Payment Screenshots & Allow Direct Uploads
 *
 * Verifies:
 * 1. Default OCR provider is DisabledOcrProvider (zero text/amount extraction).
 * 2. Upload of screenshots with mismatched amounts (e.g., Paytm reading ₹2.00 on a ₹100.00 order)
 *    is 100% permitted with can_proceed = true and no AMOUNT_MISMATCH_OCR rejection.
 * 3. Direct upload transitions order to 'Seller Confirmation Pending' with proof saved.
 * 4. Seller receives manual verification prompt notification.
 * 5. Seller can manually verify screenshot and settle order with T-PIN.
 * 6. Hard security rules (duplicate UTR, duplicate image SHA-256, dummy UTRs) remain intact.
 * 7. Past OCR false-positive rejections are excluded from user behavioral risk history.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/Fraud/UTRNormalizer.php';
require_once __DIR__ . '/../src/Fraud/ImageAnalysisService.php';
require_once __DIR__ . '/../src/Fraud/RiskScoringEngine.php';
require_once __DIR__ . '/../src/Fraud/FraudDecisionEngine.php';
require_once __DIR__ . '/../src/UpiManager.php';

use Fraud\DisabledOcrProvider;
use Fraud\ImageAnalysisService;
use Fraud\RiskScoringEngine;
use Fraud\FraudDecisionEngine;
use Fraud\UTRNormalizer;

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

$testDbFile = __DIR__ . '/test_ocr_disabled_' . time() . '.sqlite';
putenv("SQLITE_DATABASE_PATH={$testDbFile}");

$pdo = new PDO("sqlite:{$testDbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

Database::setConnection($pdo, 'sqlite');

SchemaInstaller::ensureSchema();

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testName, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$testName}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$testName} - {$details}\n";
        $failCount++;
    }
}

echo "====================================================================\n";
echo " TEST SUITE: DISABLE OCR VALIDATION & ALLOW DIRECT UPLOADS\n";
echo "====================================================================\n\n";

// Helper to create users
function createTestUser(PDO $pdo, string $phone, string $email, string $role = 'user'): int {
    $upi = 'user_' . substr($phone, -4) . '@upi';
    $res = Auth::register($phone, 'Password@123', null, $upi, 'Test User', $email, true);
    if (!$res['success']) {
        throw new Exception("Failed to register test user: " . ($res['error'] ?? ''));
    }
    $uid = (int)$res['user_id'];
    Auth::setTPIN($uid, '1234');
    $pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([50000.0, $uid]);
    return $uid;
}

// Create Seller and Buyer
$sellerId = createTestUser($pdo, '9100000001', 'seller1@gmail.com');
$buyerId = createTestUser($pdo, '9100000002', 'buyer1@gmail.com');
$buyer2Id = createTestUser($pdo, '9100000003', 'buyer2@gmail.com');

// Create test image files
$tmpDir = sys_get_temp_dir() . '/ocr_test_' . uniqid();
@mkdir($tmpDir, 0755, true);

$dummyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

// 1. Paytm screenshot simulation (file has text reading Rs. 2.00, but order is for Rs. 100.00)
$paytmScreenshot = $tmpDir . '/paytm_screenshot.png';
file_put_contents($paytmScreenshot, $dummyPng . "\nPaytm Payment Successful Rs. 2.00 to Seller UTR 512345678901");

// 2. Different screenshot
$validScreenshot = $tmpDir . '/valid_screenshot.png';
file_put_contents($validScreenshot, $dummyPng . "\nGooglePay Payment Received Rs. 100.00 UTR 512345678902");

// -------------------------------------------------------------
// TEST 1: Verify Default OCR Provider is DisabledOcrProvider
// -------------------------------------------------------------
$refMethod = new ReflectionMethod(ImageAnalysisService::class, 'resolveDefaultOcrProvider');
$refMethod->setAccessible(true);
$defaultOcr = $refMethod->invoke(null);

assertTest(
    $defaultOcr instanceof DisabledOcrProvider,
    "Test 1: Default OCR provider resolves to DisabledOcrProvider",
    "Expected DisabledOcrProvider, got " . get_class($defaultOcr)
);

$ocrExtract = $defaultOcr->extractTransactionData($paytmScreenshot);
assertTest(
    $ocrExtract['extracted_amount'] === null && $ocrExtract['extracted_utr'] === null && $ocrExtract['provider'] === 'disabled',
    "Test 1b: DisabledOcrProvider extracts null amount and null UTR",
    "Extracted amount: " . var_export($ocrExtract['extracted_amount'], true)
);

// -------------------------------------------------------------
// TEST 2: Schema setting fraud_ocr_enabled is 0
// -------------------------------------------------------------
$ocrSettingStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'fraud_ocr_enabled'");
$ocrSetting = $ocrSettingStmt->fetchColumn();

assertTest(
    $ocrSetting === '0',
    "Test 2: Platform setting fraud_ocr_enabled is '0' (Disabled)",
    "Expected '0', got: " . var_export($ocrSetting, true)
);

// Helper to create test child order
function createTestChildOrder(PDO $pdo, int $sellerId, int $buyerId, float $amount): int {
    $parentStmt = $pdo->prepare("
        INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
        VALUES (?, ?, ?, 0, 0, 'active', 'seller@upi', 'Merchant Seller', datetime('now'))
    ");
    $parentStmt->execute([$sellerId, $amount * 2, $amount]);
    $parentId = (int)$pdo->lastInsertId();

    $childStmt = $pdo->prepare("
        INSERT INTO child_orders (parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, payment_deadline, created_at)
        VALUES (?, ?, ?, ?, 9.00, ?, 'seller@upi', 'Merchant Seller', 'Reserved', datetime('now', '+10 minutes'), datetime('now'))
    ");
    $childStmt->execute([$parentId, $sellerId, $buyerId, $amount, $amount * 0.09]);
    return (int)$pdo->lastInsertId();
}

// -------------------------------------------------------------
// TEST 3: Create Order for ₹100.00 and evaluate Paytm screenshot (reading ₹2.00)
// -------------------------------------------------------------
$orderId1 = createTestChildOrder($pdo, $sellerId, $buyerId, 100.00);

// Evaluate payment proof with Paytm screenshot and UTR
$eval1 = FraudDecisionEngine::evaluatePaymentProof(
    $pdo,
    $orderId1,
    $buyerId,
    '512345678901',
    $paytmScreenshot
);

assertTest(
    $eval1['can_proceed'] === true,
    "Test 3a: Proof submission can_proceed is TRUE for Paytm screenshot reading ₹2.00",
    "can_proceed was false, error: " . ($eval1['error'] ?? 'none')
);

assertTest(
    $eval1['decision'] !== 'REJECTED',
    "Test 3b: Decision is NOT REJECTED for Paytm screenshot with different text",
    "Decision: " . $eval1['decision']
);

assertTest(
    !in_array('AMOUNT_MISMATCH_OCR', $eval1['reason_codes'], true),
    "Test 3c: AMOUNT_MISMATCH_OCR reason code is completely absent",
    "Reasons: " . json_encode($eval1['reason_codes'])
);

assertTest(
    !in_array('UTR_MISMATCH_OCR', $eval1['reason_codes'], true),
    "Test 3d: UTR_MISMATCH_OCR reason code is completely absent",
    "Reasons: " . json_encode($eval1['reason_codes'])
);

// -------------------------------------------------------------
// TEST 4: Direct Upload via StateMachine::submitPaymentProof
// -------------------------------------------------------------
$submitRes = StateMachine::submitPaymentProof(
    $orderId1,
    $buyerId,
    '512345678901',
    $paytmScreenshot,
    'Paid via Paytm'
);

assertTest(
    $submitRes['success'] === true,
    "Test 4a: StateMachine::submitPaymentProof succeeds without OCR blockage",
    "Error: " . ($submitRes['error'] ?? '')
);

// Verify order status in DB
$orderChkStmt = $pdo->prepare("SELECT status, proof_submitted_at, verification_deadline FROM child_orders WHERE id = ?");
$orderChkStmt->execute([$orderId1]);
$orderRow1 = $orderChkStmt->fetch();

assertTest(
    $orderRow1['status'] === 'Seller Confirmation Pending',
    "Test 4b: Child order transitioned directly to 'Seller Confirmation Pending'",
    "Status: " . ($orderRow1['status'] ?? 'null')
);

// Verify payment_proofs record
$proofChk = $pdo->prepare("SELECT * FROM payment_proofs WHERE child_order_id = ?");
$proofChk->execute([$orderId1]);
$proofRow = $proofChk->fetch();

assertTest(
    $proofRow !== false && $proofRow['utr_number'] === '512345678901',
    "Test 4c: Payment proof record stored with normalized UTR and screenshot path",
    "Proof: " . json_encode($proofRow)
);

// Verify notification delivered to seller for manual verification
$notifChk = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND reference_id = ? ORDER BY id DESC LIMIT 1");
$notifChk->execute([$sellerId, $orderId1]);
$notifRow = $notifChk->fetch();

assertTest(
    $notifRow !== false && (str_contains($notifRow['message'], 'manually verify') || str_contains($notifRow['message'], 'check your bank account')),
    "Test 4d: Notification sent to seller prompting manual verification of screenshot",
    "Message: " . ($notifRow['message'] ?? 'null')
);

// -------------------------------------------------------------
// TEST 5: Manual Verification - Seller confirms payment with T-PIN
// -------------------------------------------------------------
$confirmRes = StateMachine::confirmPayment($orderId1, $sellerId, '1234');

assertTest(
    $confirmRes['success'] === true,
    "Test 5a: Seller manually confirms payment and settlement succeeds",
    "Error: " . ($confirmRes['error'] ?? '')
);

$orderChkStmt->execute([$orderId1]);
$orderRowSettled = $orderChkStmt->fetch();
assertTest(
    $orderRowSettled['status'] === 'Completed',
    "Test 5b: Order status is 'Completed' after seller manual verification",
    "Status: " . ($orderRowSettled['status'] ?? 'null')
);

// -------------------------------------------------------------
// TEST 6: Hard Rules Still Protected (Duplicate confirmed UTR)
// -------------------------------------------------------------
// Another order trying to use the same UTR '512345678901'
$orderId2 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 100.00);

$uniqueScreen2 = $tmpDir . '/screen_buyer2.png';
file_put_contents($uniqueScreen2, $dummyPng . "\nUnique Content " . uniqid());

$evalDup = FraudDecisionEngine::evaluatePaymentProof(
    $pdo,
    $orderId2,
    $buyer2Id,
    '512345678901', // Same UTR already confirmed on orderId1
    $uniqueScreen2
);

assertTest(
    $evalDup['can_proceed'] === false && $evalDup['decision'] === 'REJECTED',
    "Test 6a: Duplicate confirmed UTR is strictly REJECTED (can_proceed = false)",
    "can_proceed: " . var_export($evalDup['can_proceed'], true) . ", decision: " . $evalDup['decision']
);

assertTest(
    in_array('DUPLICATE_UTR_CROSS_USER', $evalDup['reason_codes'], true) || in_array('DUPLICATE_UTR_SAME_USER', $evalDup['reason_codes'], true),
    "Test 6b: Reason code indicates duplicate UTR",
    "Reasons: " . json_encode($evalDup['reason_codes'])
);

// -------------------------------------------------------------
// TEST 7: Hard Rules Still Protected (Exact Duplicate Image Hash)
// -------------------------------------------------------------
$orderId3 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 100.00);

// Submit exact same paytmScreenshot file with different UTR
$evalDupImg = FraudDecisionEngine::evaluatePaymentProof(
    $pdo,
    $orderId3,
    $buyer2Id,
    '599988887777', // New UTR
    $paytmScreenshot // Exact duplicate image hash
);

assertTest(
    $evalDupImg['can_proceed'] === false && $evalDupImg['decision'] === 'REJECTED',
    "Test 7a: Exact duplicate image hash is strictly REJECTED",
    "can_proceed: " . var_export($evalDupImg['can_proceed'], true) . ", decision: " . $evalDupImg['decision']
);

// -------------------------------------------------------------
// TEST 8: Hard Rules Still Protected (Dummy UTR Pattern)
// -------------------------------------------------------------
$orderId4 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 100.00);

$uniqueScreen3 = $tmpDir . '/screen_buyer3.png';
file_put_contents($uniqueScreen3, $dummyPng . "\nUnique Content 3 " . uniqid());

$evalDummy = FraudDecisionEngine::evaluatePaymentProof(
    $pdo,
    $orderId4,
    $buyer2Id,
    '000000000000', // Obvious dummy UTR
    $uniqueScreen3
);

assertTest(
    $evalDummy['can_proceed'] === false && $evalDummy['decision'] === 'REJECTED',
    "Test 8: Dummy sequential UTR is strictly REJECTED",
    "can_proceed: " . var_export($evalDummy['can_proceed'], true) . ", decision: " . $evalDummy['decision']
);

// -------------------------------------------------------------
// TEST 9: Historical Behavioral Signal Decontamination
// -------------------------------------------------------------
// Insert a legacy false-positive OCR rejection for buyer1
$legacyFa = $pdo->prepare("
    INSERT INTO fraud_assessments (
        child_order_id, buyer_id, decision, risk_score, risk_level, reason_codes, ocr_data, image_analysis, claimed_amount, claimed_utr, created_at, updated_at
    ) VALUES (
        999, ?, 'REJECTED', 75, 'critical', '[\"AMOUNT_MISMATCH_OCR\"]', '{}', '{}', 100.0, '123456789012', datetime('now'), datetime('now')
    )
");
$legacyFa->execute([$buyerId]);

$signals = RiskScoringEngine::getUserBehavioralSignals($pdo, $buyerId);

assertTest(
    $signals['prior_rejected_assessments'] === 0,
    "Test 9: Historical false-positive OCR rejections are excluded from buyer risk history",
    "prior_rejected_assessments: " . $signals['prior_rejected_assessments']
);

// Clean up temporary files
@unlink($paytmScreenshot);
@unlink($validScreenshot);
@unlink($uniqueScreen2);
@unlink($uniqueScreen3);
@rmdir($tmpDir);
@unlink($testDbFile);

echo "\n====================================================================\n";
echo " TEST SUITE COMPLETE: {$passCount} PASSED, {$failCount} FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
