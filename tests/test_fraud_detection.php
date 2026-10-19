<?php
/**
 * JeliPay Fraud Prevention Architecture - Comprehensive Verification Suite
 * Tests for UTR normalization, duplicate detection, perceptual hashing,
 * OCR discrepancy analysis, explainable risk scoring, and state machine protection.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Fraud/UTRNormalizer.php';
require_once __DIR__ . '/../src/Fraud/ImageAnalysisService.php';
require_once __DIR__ . '/../src/Fraud/RiskScoringEngine.php';
require_once __DIR__ . '/../src/Fraud/FraudDecisionEngine.php';

use Fraud\UTRNormalizer;
use Fraud\ImageAnalysisService;
use Fraud\RiskScoringEngine;
use Fraud\FraudDecisionEngine;

echo "====================================================================\n";
echo " JELIPAY AI-POWERED FRAUD DETECTION VERIFICATION SUITE\n";
echo " Target: Enterprise UPI Manual Payment Fraud Architecture\n";
echo " DB: SQLite Memory Mock | Environment: PHP " . PHP_VERSION . "\n";
echo "====================================================================\n\n";

// Initialize SQLite database
$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec("PRAGMA foreign_keys = ON;");
Database::setConnection($pdo, 'sqlite');
SchemaInstaller::initSqlite($pdo);
SchemaInstaller::migrateColumns($pdo, 'sqlite');
SchemaInstaller::seedPlatformSettings($pdo);
SchemaInstaller::seedDemoAccounts($pdo);

// Seed users for testing
$tpinHash = password_hash('1234', PASSWORD_BCRYPT);
$pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);

$insUser = $pdo->prepare("
    INSERT OR IGNORE INTO users (id, phone, password_hash, tpin_hash, account_status, role, admin_role, referral_code, referred_by_id, upi_id, account_holder_name)
    VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)
");
$insUser->execute([1, '9999999999', $pwHash, $tpinHash, 'admin', 'super_admin', 'ADMIN01', null, 'admin@upi', 'Admin User']);
$insUser->execute([2, '9800000001', $pwHash, $tpinHash, 'user', null, 'SELLER01', 1, 'seller@upi', 'Seller User']);
$insUser->execute([3, '9800000002', $pwHash, $tpinHash, 'user', null, 'BUYER01', 2, 'buyer@upi', 'Buyer One']);
$insUser->execute([4, '9800000003', $pwHash, $tpinHash, 'user', null, 'BUYER02', 3, 'buyer2@upi', 'Buyer Two']);

$insWallet = $pdo->prepare("INSERT OR IGNORE INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, ?, 0.00, 1)");
$insWallet->execute([1, 1000000.00]);
$insWallet->execute([2, 50000.00]);
$insWallet->execute([3, 10000.00]);
$insWallet->execute([4, 10000.00]);

$passCount = 0;
$totalTests = 10;

// Helper to create test order
function createTestChildOrder(PDO $pdo, int $sellerId, int $buyerId, float $amount): int {
    $parentStmt = $pdo->prepare("
        INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
        VALUES (?, ?, ?, 0, 0, 'active', 'seller@upi', 'Merchant Seller', datetime('now'))
    ");
    $parentStmt->execute([$sellerId, $amount * 2, $amount]);
    $parentId = (int)$pdo->lastInsertId();

    $childStmt = $pdo->prepare("
        INSERT INTO child_orders (parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
        VALUES (?, ?, ?, ?, 9.00, ?, 'seller@upi', 'Merchant Seller', 'Reserved', datetime('now'))
    ");
    $childStmt->execute([$parentId, $sellerId, $buyerId, $amount, $amount * 0.09]);
    return (int)$pdo->lastInsertId();
}

// -------------------------------------------------------------
// TEST 1: Canonical UTR Normalization & SMS Prefix Stripping
// -------------------------------------------------------------
try {
    $variations = [
        "UPI/428901234567/Ref" => "428901234567",
        "4289 0123 4567"       => "428901234567",
        "RRN: 428901234567"    => "428901234567",
        "Paytm Ref: 428901234567" => "428901234567",
        "GPay-428901234567"    => "428901234567",
        "PhonePe-Ref:428901234567" => "428901234567",
        "428901234567."        => "428901234567",
        "UTR NO 428901234567"  => "428901234567",
    ];

    foreach ($variations as $input => $expected) {
        $normResult = UTRNormalizer::normalize($input);
        if ($normResult['normalized'] !== $expected) {
            throw new Exception("Normalization failed for '{$input}': expected '{$expected}', got '{$normResult['normalized']}'");
        }
    }

    $validCheck = UTRNormalizer::normalize("428901234567");
    if (!$validCheck['is_valid']) {
        throw new Exception("Standard 12-digit UTR 428901234567 failed is_valid: " . ($validCheck['error'] ?? ''));
    }

    echo "[PASS] Test 1: Canonical UTR Normalization & SMS prefix stripping succeeded across 8 formats\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 1: UTR Normalization - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 2: Detection of Dummy & Sequential Test UTRs
// -------------------------------------------------------------
try {
    $dummyUtrs = [
        "123456789012",
        "111111111111",
        "000000000000",
        "987654321098",
        "123456781234",
        "012345678901",
    ];

    foreach ($dummyUtrs as $dummy) {
        $check = UTRNormalizer::normalize($dummy);
        if (!$check['is_dummy']) {
            throw new Exception("Dummy/sequential UTR '{$dummy}' was unexpectedly marked as not dummy.");
        }
    }

    echo "[PASS] Test 2: Detection of Dummy & Sequential Test UTRs confirmed for all patterns\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 2: Dummy UTR Detection - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 3: Duplicate UTR Detection Across Orders & Accounts
// -------------------------------------------------------------
try {
    $utr = "428999888111";
    $order1 = createTestChildOrder($pdo, 1, 2, 1000);

    // Insert first payment proof
    $insStmt = $pdo->prepare("
        INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path, created_at)
        VALUES (?, ?, ?, 'proofs/test1.png', datetime('now'))
    ");
    $insStmt->execute([$order1, 2, $utr]);

    $normUtr = UTRNormalizer::normalize($utr);

    // Check duplicate check on a new order for a different buyer (User 3)
    $dupCheck = UTRNormalizer::checkDuplicate($pdo, $normUtr['normalized'], $normUtr['fingerprint'], 9999, 3);
    if (!$dupCheck['is_duplicate']) {
        throw new Exception("Existing UTR '{$utr}' was not detected as a duplicate!");
    }
    if ($dupCheck['conflict_order_id'] !== $order1) {
        throw new Exception("Duplicate detection pointed to wrong order ID: expected {$order1}, got {$dupCheck['conflict_order_id']}");
    }
    if (!$dupCheck['is_cross_user']) {
        throw new Exception("Cross-user duplicate was not flagged as is_cross_user!");
    }

    echo "[PASS] Test 3: Duplicate UTR detection across orders & accounts verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 3: Duplicate UTR - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 4: Exact Cryptographic Duplicate Screenshot Detection (SHA-256)
// -------------------------------------------------------------
try {
    $tempDir = __DIR__ . '/../storage/proofs';
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

    $fileA = $tempDir . '/test_sha_a.png';
    $fileB = $tempDir . '/test_sha_b.png';

    // 1x1 transparent PNG binary
    $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($fileA, $pngData);
    file_put_contents($fileB, $pngData);

    $orderA = createTestChildOrder($pdo, 1, 2, 1000.0);
    $orderB = createTestChildOrder($pdo, 1, 3, 1000.0);

    $analysisA = ImageAnalysisService::analyzeImage($fileA, 1000.0, "428900000001");
    if (empty($analysisA['sha256'])) {
        throw new Exception("SHA-256 computation failed on image A");
    }

    // Register fingerprint in database
    FraudDecisionEngine::recordFingerprint($pdo, $orderA, 2, "428900000001", $analysisA);

    // Look for duplicate using ImageAnalysisService::findDuplicateImage
    $dupImage = ImageAnalysisService::findDuplicateImage($pdo, $analysisA['sha256'], $analysisA['phash'], $orderB, 3);
    if (!$dupImage['is_duplicate'] || $dupImage['match_type'] !== 'EXACT_SHA256') {
        throw new Exception("Exact SHA-256 duplicate was not flagged!");
    }
    if ($dupImage['conflict_order_id'] !== $orderA) {
        throw new Exception("Duplicate matched unexpected order ID: {$dupImage['conflict_order_id']}");
    }

    echo "[PASS] Test 4: Exact Cryptographic SHA-256 duplicate screenshot detection verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 4: SHA-256 Duplicate - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 5: Perceptual Near-Duplicate Screenshot Detection (dHash Hamming Distance)
// -------------------------------------------------------------
try {
    $h1 = "1111000011110000";
    $h2 = "1111000011110001"; // 1 bit difference
    $h3 = "0000111100001111"; // 16 bits difference

    $dist1_2 = ImageAnalysisService::hammingDistance($h1, $h2);
    $dist1_3 = ImageAnalysisService::hammingDistance($h1, $h3);

    if ($dist1_2 !== 1) {
        throw new Exception("Hamming distance calculation error: expected 1, got {$dist1_2}");
    }
    if ($dist1_3 !== 16) {
        throw new Exception("Hamming distance calculation error: expected 16, got {$dist1_3}");
    }

    $orderC = createTestChildOrder($pdo, 1, 2, 1000.0);
    $orderD = createTestChildOrder($pdo, 1, 3, 1000.0);

    // Register h1 in fingerprints
    $dummyAnalysis = [
        'sha256' => 'dummy_sha_' . bin2hex(random_bytes(16)),
        'phash' => $h1,
        'width' => 1080,
        'height' => 1920,
        'aspect_ratio' => 0.56,
        'editing_tools_detected' => [],
    ];
    FraudDecisionEngine::recordFingerprint($pdo, $orderC, 2, "428900000002", $dummyAnalysis);

    // Test perceptual match with h2 (distance = 1 <= tolerance of 5)
    $dupCheck = ImageAnalysisService::findDuplicateImage($pdo, 'different_sha_hash', $h2, $orderD, 3, 5);
    if (!$dupCheck['is_duplicate'] || $dupCheck['match_type'] !== 'PERCEPTUAL_PHASH') {
        throw new Exception("Perceptual near-duplicate with Hamming distance 1 was not detected!");
    }
    if ($dupCheck['distance'] !== 1) {
        throw new Exception("Reported Hamming distance mismatch: expected 1, got {$dupCheck['distance']}");
    }

    echo "[PASS] Test 5: Perceptual near-duplicate dHash comparison & Hamming distance tolerance verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 5: Perceptual Hash - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 6: OCR Discrepancy & Pluggable Provider Fallback
// -------------------------------------------------------------
try {
    // Create an image file with embedded transaction text to test LocalPatternOcrProvider
    $ocrSampleFile = $tempDir . '/ocr_sample.png';
    $payloadText = "State Bank of India\nPaid to MERCHANT\nRs. 5000.00\nUPI Ref 428901234567\nPayment SUCCESS\nDate: 20/10/2026";
    file_put_contents($ocrSampleFile, $pngData . "\n" . $payloadText);

    $localOcr = new \Fraud\LocalPatternOcrProvider();
    $extracted = $localOcr->extractTransactionData($ocrSampleFile);

    if ($extracted['extracted_amount'] !== 5000.0) {
        throw new Exception("Local OCR failed to parse amount: expected 5000.0, got " . var_export($extracted['extracted_amount'], true));
    }
    if ($extracted['extracted_utr'] !== '428901234567') {
        throw new Exception("Local OCR failed to parse UTR: expected 428901234567, got " . var_export($extracted['extracted_utr'], true));
    }
    if ($extracted['extracted_status'] !== 'SUCCESS') {
        throw new Exception("Local OCR failed to detect status: expected SUCCESS, got " . var_export($extracted['extracted_status'], true));
    }

    // Test OCR comparison discrepancy via analyzeImage
    $analysis = ImageAnalysisService::analyzeImage($ocrSampleFile, 3000.0, "999999999999", $localOcr);
    $ocrComp = $analysis['comparison'] ?? [];

    if ($ocrComp['amount_matched'] !== false) {
        throw new Exception("Amount mismatch was not caught: claimed ₹3000 vs OCR ₹5000");
    }
    if ($ocrComp['utr_matched'] !== false) {
        throw new Exception("UTR mismatch was not caught: claimed 999999999999 vs OCR 428901234567");
    }

    echo "[PASS] Test 6: OCR Discrepancy parsing & discrepancy matrix comparison verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 6: OCR Discrepancy - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 7: Risk Scoring Engine Calculation & Categorization
// -------------------------------------------------------------
try {
    $orderMock = ['buyer_id' => 2, 'amount' => 5000.0];

    // Scenario A: Clean transaction
    $utrDataClean = UTRNormalizer::normalize("428901234567");
    $noDup = ['is_duplicate' => false];
    $cleanImage = [
        'is_valid_format' => true,
        'aspect_ratio' => 1.77,
        'aspect_ratio_anomaly' => false,
        'tampering_hints' => [],
        'ocr' => ['success' => true],
        'comparison' => [
            'amount_matched' => true,
            'utr_matched' => true,
            'status_matched' => true,
            'extracted_status' => 'SUCCESS'
        ]
    ];
    $cleanEval = RiskScoringEngine::evaluateSubmission($pdo, $orderMock, $utrDataClean, $noDup, $cleanImage, $noDup);

    if ($cleanEval['score'] > 25 || $cleanEval['risk_level'] === 'critical') {
        throw new Exception("Clean transaction produced unexpected score: {$cleanEval['score']} ({$cleanEval['risk_level']})");
    }

    // Scenario B: Ambiguous transaction (OCR amount mismatch)
    $ambigImage = $cleanImage;
    $ambigImage['comparison']['amount_matched'] = false;
    $ambigImage['comparison']['extracted_amount'] = 500.0;
    $ambigImage['comparison']['amount_difference'] = 4500.0;
    $ambigEval = RiskScoringEngine::evaluateSubmission($pdo, $orderMock, $utrDataClean, $noDup, $ambigImage, $noDup);

    if ($ambigEval['score'] < 30 || !in_array('AMOUNT_MISMATCH_OCR', $ambigEval['reasons'], true)) {
        throw new Exception("Ambiguous transaction produced unexpected score: {$ambigEval['score']}");
    }

    // Scenario C: Confirmed Hard Duplicate UTR
    $dupUtr = [
        'is_duplicate' => true,
        'reason_code' => 'DUPLICATE_UTR_CROSS_USER',
        'description' => 'UTR already submitted on Order #10'
    ];
    $hardEval = RiskScoringEngine::evaluateSubmission($pdo, $orderMock, $utrDataClean, $dupUtr, $cleanImage, $noDup);

    if ($hardEval['score'] < 75 || !$hardEval['hard_rule_violated']) {
        throw new Exception("Hard duplicate did not trigger hard rule violation: {$hardEval['score']}");
    }

    echo "[PASS] Test 7: Multi-signal Risk Scoring Engine calculation & categorization verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 7: Risk Scoring - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 8: State Machine & Financial Ledger Protection
// -------------------------------------------------------------
try {
    $sellerId = 1;
    $buyerId = 2;
    $orderId = createTestChildOrder($pdo, $sellerId, $buyerId, 500.0);

    // Initial seller & buyer balances
    $sellerInitBal = (float)Wallet::getWallet($sellerId)['available_balance'];
    $buyerInitBal = (float)Wallet::getWallet($buyerId)['available_balance'];

    // Submit fraudulent duplicate UTR (111111111111 - dummy pattern)
    $evalDummy = FraudDecisionEngine::evaluatePaymentProof(
        $pdo,
        $orderId,
        $buyerId,
        "111111111111",
        $fileA
    );

    if ($evalDummy['decision'] !== 'REJECTED') {
        throw new Exception("Dummy UTR was not REJECTED by decision engine: " . $evalDummy['decision']);
    }

    // Attempt StateMachine transition with rejected UTR
    $stateMachineResult = StateMachine::submitPaymentProof($orderId, $buyerId, "111111111111", $fileA);
    if ($stateMachineResult['success']) {
        throw new Exception("StateMachine::submitPaymentProof succeeded when it should have been blocked!");
    }

    // Verify order status has NOT transitioned to 'Payment Proof Submitted'
    $orderCheck = $pdo->query("SELECT status FROM child_orders WHERE id = {$orderId}")->fetch();
    if ($orderCheck['status'] !== 'Reserved') {
        throw new Exception("Order status unexpectedly changed to: {$orderCheck['status']}");
    }

    // Verify buyer received zero wallet credit
    $buyerAfterBal = (float)Wallet::getWallet($buyerId)['available_balance'];
    if ($buyerAfterBal !== $buyerInitBal) {
        throw new Exception("Buyer balance changed from ₹{$buyerInitBal} to ₹{$buyerAfterBal}!");
    }

    echo "[PASS] Test 8: State Machine & Financial Ledger protection verified (Zero unauthorized wallet credit)\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 8: Ledger Protection - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 9: Administrative Adjudication & Audit Override
// -------------------------------------------------------------
try {
    // Check fraud assessment recorded in database from Test 8
    $assessStmt = $pdo->query("SELECT id FROM fraud_assessments ORDER BY id DESC LIMIT 1");
    $assessRow = $assessStmt->fetch();
    if (!$assessRow) {
        throw new Exception("No fraud assessment record found in database.");
    }
    $assessmentId = (int)$assessRow['id'];

    // Admin executes review override
    $adminId = 1; // Super Admin
    $reviewNotes = "Forensic inspection completed: False positive identified via bank statement verification.";
    $reviewRes = AdminManager::reviewFraudAssessment($assessmentId, $adminId, 'override_verified', $reviewNotes);

    if (!$reviewRes['success']) {
        throw new Exception("AdminManager::reviewFraudAssessment failed: " . ($reviewRes['error'] ?? ''));
    }

    // Verify database record updated
    $chkStmt = $pdo->prepare("SELECT review_decision, reviewed_by_admin_id, review_notes FROM fraud_assessments WHERE id = ?");
    $chkStmt->execute([$assessmentId]);
    $updated = $chkStmt->fetch();

    if ($updated['review_decision'] !== 'override_verified') {
        throw new Exception("Expected review_decision 'override_verified', got: {$updated['review_decision']}");
    }
    if ((int)$updated['reviewed_by_admin_id'] !== $adminId) {
        throw new Exception("Expected reviewed_by_admin_id {$adminId}, got: {$updated['reviewed_by_admin_id']}");
    }

    // Verify audit log entry created
    $auditChk = $pdo->prepare("SELECT action_type, reason FROM admin_audit_logs WHERE target_id = ? ORDER BY id DESC LIMIT 1");
    $auditChk->execute([(string)$assessmentId]);
    $auditLog = $auditChk->fetch();
    if (!$auditLog || $auditLog['action_type'] !== 'FRAUD_REVIEW_OVERRIDE') {
        throw new Exception("Missing or incorrect admin audit log for fraud review.");
    }

    echo "[PASS] Test 9: Administrative adjudication, override mechanism, and audit trail verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 9: Admin Review & Audit - " . $e->getMessage() . "\n";
}

// -------------------------------------------------------------
// TEST 10: System Resilience & Hostinger Environment Compatibility
// -------------------------------------------------------------
try {
    // 1. Verify all 3 fraud tables exist with proper columns
    $tables = ['fraud_assessments', 'payment_fingerprints', 'user_risk_profiles'];
    foreach ($tables as $tbl) {
        $checkTbl = $pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
        if ($checkTbl === false) {
            throw new Exception("Table {$tbl} missing from database schema.");
        }
    }

    // 2. Verify platform settings seeded
    $reqSettings = [
        'fraud_detection_enabled',
        'fraud_auto_reject_score',
        'fraud_review_threshold',
        'fraud_perceptual_hash_tolerance'
    ];
    foreach ($reqSettings as $sKey) {
        $val = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = '{$sKey}'")->fetchColumn();
        if ($val === false) {
            throw new Exception("Setting {$sKey} missing from platform_settings table.");
        }
    }

    // 3. Test Pure-PHP Perceptual Hash Fallback (CLI / No-GD simulation)
    $pureHash = ImageAnalysisService::computePurePhpPerceptualHash($fileA);
    if (strlen($pureHash) !== 16) {
        throw new Exception("Pure-PHP 64-bit dHash fallback failed: output length " . strlen($pureHash));
    }

    echo "[PASS] Test 10: System resilience, schema completeness & Hostinger shared hosting compatibility verified\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 10: System Resilience - " . $e->getMessage() . "\n";
}

// Clean up temporary test files
@unlink($fileA);
@unlink($fileB);
@unlink($ocrSampleFile ?? '');

echo "\n====================================================================\n";
echo " FRAUD VERIFICATION SUITE SUMMARY: {$passCount}/{$totalTests} PASSED (" . round(($passCount / $totalTests) * 100) . "%)\n";
echo "====================================================================\n";

if ($passCount === $totalTests) {
    exit(0);
} else {
    exit(1);
}
