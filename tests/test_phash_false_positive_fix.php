<?php
/**
 * Automated Test Suite: Verification of False-Positive Duplicate Screenshot Fix
 * Tests that valid, newly-taken payment screenshots are NOT falsely rejected (pHash distance <= 5)
 * while cryptographic SHA-256 deduplication and duplicate UTR checks remain fully active.
 */

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Fraud/ImageAnalysisService.php';
require_once __DIR__ . '/../src/Fraud/FraudDecisionEngine.php';
require_once __DIR__ . '/../src/Fraud/RiskScoringEngine.php';

use Fraud\ImageAnalysisService;
use Fraud\FraudDecisionEngine;

SchemaInstaller::ensureSchema();
$pdo = Database::getConnection();

$testsPassed = 0;
$totalTests = 0;

function assertCondition(bool $cond, string $desc): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($cond) {
        $testsPassed++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
    }
}

echo "====================================================================\n";
echo " VERIFICATION: FALSE-POSITIVE DUPLICATE SCREENSHOT DETECTION FIX\n";
echo "====================================================================\n\n";

$tempDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
}

// Minimal valid PNG 1x1 base
$basePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

// Helper to create test child order
function createTestChildOrder(PDO $pdo, int $sellerId, int $buyerId, float $amount): int {
    $parentStmt = $pdo->prepare("
        INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
        VALUES (?, ?, ?, 0, 0, 'active', 'test_seller@upi', 'Test Seller', datetime('now'))
    ");
    $parentStmt->execute([$sellerId, $amount * 2, $amount]);
    $parentId = (int)$pdo->lastInsertId();

    $deadline = gmdate('Y-m-d H:i:s', time() + 600);
    $childStmt = $pdo->prepare("
        INSERT INTO child_orders (parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, payment_deadline, created_at)
        VALUES (?, ?, ?, ?, 9.00, ?, 'test_seller@upi', 'Test Seller', 'Reserved', ?, datetime('now'))
    ");
    $childStmt->execute([$parentId, $sellerId, $buyerId, $amount, $amount * 0.09, $deadline]);
    return (int)$pdo->lastInsertId();
}

// Ensure test users exist
$pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);
$insUser = $pdo->prepare("INSERT OR IGNORE INTO users (id, phone, password_hash, role, referral_code) VALUES (?, ?, ?, 'user', ?)");
$insUser->execute([1, '9800000001', $pwHash, 'SELLER_TEST']);
$insUser->execute([2, '9800000002', $pwHash, 'BUYER_TEST1']);
$insUser->execute([3, '9800000003', $pwHash, 'BUYER_TEST2']);

$insWallet = $pdo->prepare("INSERT OR IGNORE INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, 10000.00, 0.00, 1)");
$insWallet->execute([1]);
$insWallet->execute([2]);
$insWallet->execute([3]);

// -------------------------------------------------------------
// TEST 1: Platform Settings Default Configuration
// -------------------------------------------------------------
echo "[Test 1] Platform Settings for Perceptual Hash\n";
$stmtEn = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'fraud_perceptual_hash_enabled'");
$valEn = $stmtEn ? $stmtEn->fetchColumn() : null;
assertCondition($valEn === '0', "fraud_perceptual_hash_enabled is '0' (Disabled by default)");

$stmtTol = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'fraud_perceptual_hash_tolerance'");
$valTol = $stmtTol ? $stmtTol->fetchColumn() : null;
assertCondition($valTol === '0', "fraud_perceptual_hash_tolerance is '0' (Strict)");

// -------------------------------------------------------------
// TEST 2: Two Orders with Near-Identical pHash (Distance <= 5)
// -------------------------------------------------------------
echo "\n[Test 2] Newly-Taken Screenshots with Visual Similarity are NOT Falsely Rejected\n";

$sellerId = 1;
$buyer1Id = 2;
$buyer2Id = 3;

$order1 = createTestChildOrder($pdo, $sellerId, $buyer1Id, 500.0);
$order2 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 500.0);

// Create two distinct screenshot files with slightly different content (different SHA-256)
$file1 = $tempDir . '/proof_order1_' . uniqid() . '.png';
$file2 = $tempDir . '/proof_order2_' . uniqid() . '.png';

file_put_contents($file1, $basePng . "\n" . "PhonePe Payment Success Receipt Order 1 - Unique Salt " . uniqid());
file_put_contents($file2, $basePng . "\n" . "PhonePe Payment Success Receipt Order 2 - Unique Salt " . uniqid());

$sha1 = hash_file('sha256', $file1);
$sha2 = hash_file('sha256', $file2);
assertCondition($sha1 !== $sha2, "The two screenshots have distinct cryptographic SHA-256 hashes");

// Ensure pHash distance is <= 5 between the two files
$p1 = ImageAnalysisService::computePerceptualHash($file1, 'image/png');
$p2 = ImageAnalysisService::computePerceptualHash($file2, 'image/png');
$dist = ImageAnalysisService::hammingDistance($p1, $p2);
assertCondition($dist <= 5, "Perceptual Hamming distance is {$dist} <= 5 (typical for standard PhonePe/GPay layouts)");

$runId = rand(100000, 999999);

// Submit proof for Order 1 with valid UTR
$utr1 = '4289' . $runId . '01';
$res1 = StateMachine::submitPaymentProof($order1, $buyer1Id, $utr1, $file1, 'Payment 1 via PhonePe');
assertCondition($res1['success'] === true, "Order 1 payment proof submitted successfully");

// Submit proof for Order 2 with a different, valid UTR and file 2
$utr2 = '4289' . $runId . '02';
$res2 = StateMachine::submitPaymentProof($order2, $buyer2Id, $utr2, $file2, 'Payment 2 via PhonePe');
assertCondition($res2['success'] === true, "Order 2 payment proof submitted successfully without pHash false-positive rejection");

// Verify Order 2 transitioned to 'Seller Confirmation Pending' (not stuck/blocked)
$chkOrder2 = $pdo->query("SELECT status FROM child_orders WHERE id = {$order2}")->fetch();
assertCondition($chkOrder2['status'] === 'Seller Confirmation Pending', "Order 2 status is 'Seller Confirmation Pending' (user not blocked)");

// -------------------------------------------------------------
// TEST 3: Exact Cryptographic SHA-256 Duplication STILL Blocked
// -------------------------------------------------------------
echo "\n[Test 3] Exact Cryptographic Duplicate File (SHA-256) is Still Blocked\n";

$order3 = createTestChildOrder($pdo, $sellerId, $buyer1Id, 500.0);
$utr3 = '4289' . $runId . '03';

// Attempt to re-upload the EXACT file1 from Order 1
$resDupFile = StateMachine::submitPaymentProof($order3, $buyer1Id, $utr3, $file1, 'Re-uploading same file');
assertCondition($resDupFile['success'] === false, "Exact SHA-256 duplicate file is strictly rejected");
assertCondition(strpos($resDupFile['error'], 'Exact identical payment screenshot') !== false, "Error message correctly identifies exact duplicate screenshot");

// -------------------------------------------------------------
// TEST 4: Duplicate UTR is Still Blocked
// -------------------------------------------------------------
echo "\n[Test 4] Duplicate UTR Number is Still Blocked\n";

$order4 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 500.0);
$file4 = $tempDir . '/proof_order4_' . uniqid() . '.png';
file_put_contents($file4, $basePng . "\n" . "Unique file 4 - " . uniqid());

// Attempt to use utr1 which was already used on Order 1
$resDupUtr = StateMachine::submitPaymentProof($order4, $buyer2Id, $utr1, $file4, 'Reusing UTR');
assertCondition($resDupUtr['success'] === false, "Duplicate UTR number is strictly rejected");
assertCondition(strpos($resDupUtr['error'], 'already submitted') !== false, "Error message correctly identifies duplicate UTR");

// -------------------------------------------------------------
// TEST 5: Soft pHash Match Does Not Hard Block Even If Enabled
// -------------------------------------------------------------
echo "\n[Test 5] When pHash is Enabled, Soft Similarity Flags for Review Instead of Hard Blocking\n";

// Temporarily enable pHash with tolerance = 5
AdminManager::updateSetting('fraud_perceptual_hash_enabled', '1', 1, 'Testing enabled state');
AdminManager::updateSetting('fraud_perceptual_hash_tolerance', '5', 1, 'Testing tolerance 5');

$order5 = createTestChildOrder($pdo, $sellerId, $buyer2Id, 500.0);
$file5 = $tempDir . '/proof_order5_' . uniqid() . '.png';
file_put_contents($file5, $basePng . "\n" . "PhonePe Payment Success Receipt Order 5 - Unique Salt " . uniqid());

$utr5 = '4289' . $runId . '05';
$res5 = StateMachine::submitPaymentProof($order5, $buyer2Id, $utr5, $file5, 'Payment 5');
assertCondition($res5['success'] === true, "Valid order is allowed to proceed even when pHash similarity is detected");

$chkOrder5 = $pdo->query("SELECT status FROM child_orders WHERE id = {$order5}")->fetch();
assertCondition($chkOrder5['status'] === 'Seller Confirmation Pending', "Order 5 successfully placed in 'Seller Confirmation Pending'");

// Check fraud assessment decision was PENDING_REVIEW rather than REJECTED
$assessStmt = $pdo->prepare("SELECT decision, risk_score, reason_codes FROM fraud_assessments WHERE child_order_id = ?");
$assessStmt->execute([$order5]);
$assess = $assessStmt->fetch();
assertCondition($assess['decision'] === 'PENDING_REVIEW', "Engine decision is PENDING_REVIEW (not REJECTED)");

// Restore default disabled state
AdminManager::updateSetting('fraud_perceptual_hash_enabled', '0', 1, 'Restoring default disabled');
AdminManager::updateSetting('fraud_perceptual_hash_tolerance', '0', 1, 'Restoring default tolerance');

// Clean up temporary files
@unlink($file1);
@unlink($file2);
@unlink($file4);
@unlink($file5);

echo "\n--------------------------------------------------------------------\n";
echo "SUMMARY: {$testsPassed} / {$totalTests} Tests Passed (" . round(($testsPassed / $totalTests) * 100, 1) . "%)\n";
echo "--------------------------------------------------------------------\n";

if ($testsPassed === $totalTests) {
    echo "SUCCESS: All False-Positive Duplicate Screenshot fix tests passed!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
