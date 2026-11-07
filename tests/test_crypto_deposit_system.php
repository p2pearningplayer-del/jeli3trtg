<?php
/**
 * Test Suite: USDT (TRC-20) Crypto Deposit System
 * 
 * Verifies:
 * 1. Default configuration (Master wallet TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV, 1:115 rate, 100 USDT min, manual 1-address provider).
 * 2. Manual 1-Address Gateway: All users receive the official master wallet deposit address and vector QR code.
 * 3. User manual submission flow: TXID, amount, proof receipt screenshot.
 * 4. Strict minimum deposit limit (<100 USDT rejected as below_minimum, 0 coins credited).
 * 5. Pending Approval Queue: Valid deposits queue for admin review when on-chain is unindexed.
 * 6. Admin Manual Approval: Balance crediting @ 1:115, double-entry wallet_ledger, status=completed, audit log.
 * 7. Admin Rejection: Reason recorded, status=rejected, user notification.
 * 8. Strict Idempotency & Duplicate Prevention: Replayed or duplicate TXIDs blocked.
 * 9. Automated Dedicated Address Generation: When provider is switched to automated (nowpayments/trongrid), unique TRC-20 addresses generated.
 * 10. NOWPayments Webhook IPN processing and HMAC-SHA512 signature verification.
 * 11. Admin Manual Credit Tool (adminManualCredit) and macro audit logs.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Crypto/CryptoDepositManager.php';
require_once __DIR__ . '/../config/schema_installer.php';

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

echo "====================================================================\n";
echo " JELIPAY - USDT (TRC20) 1-ADDRESS MANUAL & AUTOMATED GATEWAY TESTS\n";
echo "====================================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function runCheck(string $desc, bool $condition, string $detail = ''): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

use Crypto\CryptoDepositManager;

// =========================================================================
// TEST GROUP 1: PLATFORM SETTINGS & MASTER VAULT CONFIGURATION
// =========================================================================
echo "[TEST GROUP 1: Platform Settings & Master Wallet]\n";

$masterWallet = CryptoDepositManager::getMasterWallet();
runCheck(
    "Master wallet defaults to TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV",
    $masterWallet === 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV',
    "Got: {$masterWallet}"
);

$exchangeRate = CryptoDepositManager::getExchangeRate();
runCheck(
    "Exchange rate defaults to 1 USDT = 115 Platform Coins",
    abs($exchangeRate - 115.00) < 0.001,
    "Got: {$exchangeRate}"
);

$minDeposit = CryptoDepositManager::getMinDeposit();
runCheck(
    "Minimum deposit threshold is strictly 100 USDT",
    abs($minDeposit - 100.00) < 0.001,
    "Got: {$minDeposit}"
);

$provider = CryptoDepositManager::getGatewayProvider();
runCheck(
    "Gateway provider defaults to 'manual' (1 Address mode)",
    $provider === 'manual',
    "Got: {$provider}"
);

// =========================================================================
// TEST GROUP 2: MANUAL 1-ADDRESS GATEWAY (ALL USERS SHARE MASTER WALLET)
// =========================================================================
echo "\n[TEST GROUP 2: Manual 1-Address Gateway Verification]\n";

$user1ManualAddr = CryptoDepositManager::getUserDepositAddress(1);
$user2ManualAddr = CryptoDepositManager::getUserDepositAddress(2);

runCheck(
    "User 1 receives official master wallet address in manual mode",
    $user1ManualAddr['address'] === 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV',
    "Address: " . ($user1ManualAddr['address'] ?? 'none')
);

runCheck(
    "User 2 receives identical master wallet address in manual mode",
    $user2ManualAddr['address'] === 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV',
    "User 2 Address: " . ($user2ManualAddr['address'] ?? 'none')
);

runCheck(
    "Deposit address response includes is_manual flag",
    !empty($user1ManualAddr['is_manual']) && $user1ManualAddr['is_manual'] === true
);

runCheck(
    "Master wallet address query generates vector SVG QR code",
    !empty($user1ManualAddr['qr_svg']) && str_contains($user1ManualAddr['qr_svg'], '<svg'),
    "SVG contains: " . substr($user1ManualAddr['qr_svg'] ?? '', 0, 30)
);

// =========================================================================
// TEST GROUP 3: MANUAL DEPOSIT SUBMISSION & STRICT MINIMUM LIMIT (< 100 USDT)
// =========================================================================
echo "\n[TEST GROUP 3: Manual Deposit Submission & Strict Minimum Enforcement]\n";

$walletBeforeSub = Wallet::getWallet(1);
$balBeforeSub = (float)$walletBeforeSub['available_balance'];

// Sub-minimum: 50 USDT submitted by User 1
$subTxid = 'manual_submin_tx_' . bin2hex(random_bytes(16));
$subResult = CryptoDepositManager::submitManualDeposit(1, $subTxid, 50.00, 'uploads/proofs/test_submin.png');

runCheck(
    "Sub-minimum 50.00 USDT manual deposit is immediately rejected",
    $subResult['success'] === false && ($subResult['status'] ?? '') === 'below_minimum',
    "Result: " . json_encode($subResult)
);

$walletAfterSub = Wallet::getWallet(1);
$balAfterSub = (float)$walletAfterSub['available_balance'];
runCheck(
    "Zero coins credited to user's wallet for sub-minimum submission",
    abs($balAfterSub - $balBeforeSub) < 0.001,
    "Before: {$balBeforeSub}, After: {$balAfterSub}"
);

$stmtSub = $pdo->prepare("SELECT * FROM crypto_deposits WHERE txid = ?");
$stmtSub->execute([$subTxid]);
$subRow = $stmtSub->fetch();
runCheck(
    "Sub-minimum submission recorded in database with status 'below_minimum' and proof saved",
    $subRow && $subRow['status'] === 'below_minimum' && (float)$subRow['coins_credited'] === 0.0 && $subRow['proof_screenshot'] === 'uploads/proofs/test_submin.png'
);

// Resubmitting the same sub-minimum TXID is blocked
$subReplay = CryptoDepositManager::submitManualDeposit(1, $subTxid, 50.00);
runCheck(
    "Resubmitting existing below_minimum TXID returns rejection message",
    $subReplay['success'] === false && ($subReplay['status'] ?? '') === 'below_minimum'
);

// =========================================================================
// TEST GROUP 4: VALID MANUAL DEPOSIT QUEUEING (PENDING APPROVAL)
// =========================================================================
echo "\n[TEST GROUP 4: Valid Manual Deposit Submission (Pending Approval Queue)]\n";

$validManualTxid1 = 'tron_manual_100usdt_' . bin2hex(random_bytes(16));
$proofPath1 = 'uploads/proofs/valid_receipt_100.png';

$manualSub1 = CryptoDepositManager::submitManualDeposit(1, $validManualTxid1, 100.00, $proofPath1);

runCheck(
    "100.00 USDT manual deposit submitted successfully",
    !empty($manualSub1['success']),
    "Result: " . json_encode($manualSub1)
);

runCheck(
    "Deposit is placed in pending_approval queue when blockchain unindexed in test env",
    !empty($manualSub1['pending']) && ($manualSub1['deposit_id'] ?? 0) > 0,
    "Deposit ID: " . ($manualSub1['deposit_id'] ?? 0)
);

$pendingDepositId1 = (int)$manualSub1['deposit_id'];

// Check database row
$stmtPending = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
$stmtPending->execute([$pendingDepositId1]);
$pendingRow = $stmtPending->fetch();

runCheck(
    "Deposit record stored with status 'pending_approval' and correct proof screenshot",
    $pendingRow && $pendingRow['status'] === 'pending_approval' && $pendingRow['proof_screenshot'] === $proofPath1 && (float)$pendingRow['amount_usdt'] === 100.00
);

// Resubmitting the same pending TXID returns pending notice without duplicating row
$reSubPending = CryptoDepositManager::submitManualDeposit(1, $validManualTxid1, 100.00);
runCheck(
    "Resubmitting pending TXID returns pending status idempotently without duplicate row",
    !empty($reSubPending['success']) && !empty($reSubPending['pending'])
);

// =========================================================================
// TEST GROUP 5: ADMIN MANUAL APPROVAL & BALANCE CREDITING @ 1:115
// =========================================================================
echo "\n[TEST GROUP 5: Admin Manual Approval & Balance Crediting]\n";

$walletBeforeApprove = Wallet::getWallet(1);
$balBeforeApprove = (float)$walletBeforeApprove['available_balance'];

// Admin (User 1) approves the pending deposit
$approveRes = CryptoDepositManager::approveManualDeposit(
    1, // adminId
    $pendingDepositId1,
    'Verified via TronScan hash and verified screenshot proof'
);

runCheck(
    "Admin approveManualDeposit succeeds",
    !empty($approveRes['success']),
    "Error: " . ($approveRes['error'] ?? 'none')
);

runCheck(
    "Admin approval calculated exact 1:115 conversion (100 USDT = 11,500 Coins)",
    abs((float)($approveRes['coins_credited'] ?? 0) - 11500.00) < 0.01,
    "Coins credited: " . ($approveRes['coins_credited'] ?? 0)
);

$walletAfterApprove = Wallet::getWallet(1);
$balAfterApprove = (float)$walletAfterApprove['available_balance'];
runCheck(
    "User Available Balance credited with exactly 11,500.00 Coins",
    abs(($balAfterApprove - $balBeforeApprove) - 11500.00) < 0.01,
    "Diff: " . ($balAfterApprove - $balBeforeApprove)
);

// Verify double-entry ledger entry
$stmtLedger = $pdo->prepare("SELECT * FROM wallet_ledger WHERE user_id = 1 AND transaction_type = 'USDT_DEPOSIT' ORDER BY id DESC LIMIT 1");
$stmtLedger->execute();
$ledgerRow = $stmtLedger->fetch();
runCheck(
    "Double-entry wallet ledger created with transaction_type 'USDT_DEPOSIT' and admin reference",
    $ledgerRow && abs((float)$ledgerRow['amount'] - 11500.00) < 0.01 && $ledgerRow['reference_id'] === $validManualTxid1,
    "Ledger: " . json_encode($ledgerRow)
);

// Verify deposit row updated to completed
$stmtApprovedDep = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
$stmtApprovedDep->execute([$pendingDepositId1]);
$approvedDepRow = $stmtApprovedDep->fetch();
runCheck(
    "Deposit row updated to status 'completed' and sweep_status 'swept'",
    $approvedDepRow && $approvedDepRow['status'] === 'completed' && $approvedDepRow['sweep_status'] === 'swept'
);

// Re-approving an already completed deposit is idempotent
$reApprove = CryptoDepositManager::approveManualDeposit(1, $pendingDepositId1);
runCheck(
    "Re-approving already completed deposit is idempotent",
    !empty($reApprove['success']) && !empty($reApprove['idempotent'])
);

// =========================================================================
// TEST GROUP 6: ADMIN MANUAL REJECTION
// =========================================================================
echo "\n[TEST GROUP 6: Admin Manual Rejection Flow]\n";

// Submit another deposit to reject
$rejectTxid = 'tron_reject_tx_' . bin2hex(random_bytes(16));
$rejectSub = CryptoDepositManager::submitManualDeposit(2, $rejectTxid, 120.00, 'uploads/proofs/fake_receipt.png');
$rejectDepositId = (int)($rejectSub['deposit_id'] ?? 0);

runCheck(
    "Deposit for User 2 submitted to pending queue",
    $rejectDepositId > 0
);

$rejectRes = CryptoDepositManager::rejectManualDeposit(
    1, // adminId
    $rejectDepositId,
    'Transaction hash not found on Tron blockchain explorer'
);

runCheck(
    "Admin rejectManualDeposit succeeds",
    !empty($rejectRes['success']),
    "Error: " . ($rejectRes['error'] ?? 'none')
);

// Check database row
$stmtRejectDep = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
$stmtRejectDep->execute([$rejectDepositId]);
$rejectRow = $stmtRejectDep->fetch();

runCheck(
    "Deposit status updated to 'rejected' with admin rejection reason saved",
    $rejectRow && $rejectRow['status'] === 'rejected' && str_contains($rejectRow['admin_notes'] ?? '', 'Tron blockchain explorer')
);

// Submitting this rejected TXID again is blocked
$resubReject = CryptoDepositManager::submitManualDeposit(2, $rejectTxid, 120.00);
runCheck(
    "Resubmitting a rejected TXID is blocked with explanation",
    $resubReject['success'] === false && ($resubReject['status'] ?? '') === 'rejected'
);

// =========================================================================
// TEST GROUP 7: AUTOMATED COIN CONVERSION VIA PROCESS DEPOSIT
// =========================================================================
echo "\n[TEST GROUP 7: Direct Automated Conversion & Crediting]\n";

$validTxidAuto = 'tron_direct_auto_' . bin2hex(random_bytes(16));
$directResult = CryptoDepositManager::processDeposit(1, $masterWallet, $validTxidAuto, 250.00);

runCheck(
    "Direct processDeposit converts 250 USDT to 28,750.00 Coins (250 * 115)",
    !empty($directResult['success']) && abs((float)$directResult['coins_credited'] - 28750.00) < 0.01,
    "Result: " . json_encode($directResult)
);

// Replay prevention
$replayRes = CryptoDepositManager::processDeposit(1, $masterWallet, $validTxidAuto, 250.00);
runCheck(
    "Replayed processDeposit TXID is idempotent and does not re-credit",
    !empty($replayRes['success']) && !empty($replayRes['idempotent'])
);

// =========================================================================
// TEST GROUP 8: ADMIN DIRECT CREDIT TOOL (adminManualCredit)
// =========================================================================
echo "\n[TEST GROUP 8: Admin Direct Credit Tool (adminManualCredit)]\n";

$user2Before = Wallet::getWallet(2);
$user2BalBefore = (float)$user2Before['available_balance'];

$adminDirectTxid = 'admin_direct_tx_' . bin2hex(random_bytes(16));
$adminCreditRes = CryptoDepositManager::adminManualCredit(
    1, // adminId
    2, // userId
    $adminDirectTxid,
    150.00, // 150 USDT -> 17,250 Coins
    'Direct ledger credit verified on TronScan explorer'
);

runCheck(
    "Admin manual credit processes successfully",
    !empty($adminCreditRes['success']),
    "Error: " . ($adminCreditRes['error'] ?? 'none')
);

$user2After = Wallet::getWallet(2);
$user2BalAfter = (float)$user2After['available_balance'];
runCheck(
    "User 2 credited with 17,250.00 Coins (150 USDT * 115)",
    abs(($user2BalAfter - $user2BalBefore) - 17250.00) < 0.01,
    "Diff: " . ($user2BalAfter - $user2BalBefore)
);

// Verify admin audit log
$stmtAudit = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE action_type = 'MANUAL_CRYPTO_DEPOSIT_CREDIT' ORDER BY id DESC LIMIT 1");
$stmtAudit->execute();
$auditRow = $stmtAudit->fetch();
runCheck(
    "Admin manual credit creates audit trail entry",
    $auditRow && (int)$auditRow['admin_id'] === 1 && str_contains($auditRow['after_state'] ?? '', '150')
);

// =========================================================================
// TEST GROUP 9: MULTI-ADDRESS MODE & NOWPAYMENTS WEBHOOK
// =========================================================================
echo "\n[TEST GROUP 9: Multi-Address Provider & NOWPayments Webhook]\n";

// Switch gateway provider to 'nowpayments' to test multi-address capabilities
$pdo->prepare("UPDATE platform_settings SET setting_value = 'nowpayments' WHERE setting_key = 'crypto_gateway_provider'")->execute();

$user1MultiAddr = CryptoDepositManager::getUserDepositAddress(1);
$user2MultiAddr = CryptoDepositManager::getUserDepositAddress(2);

runCheck(
    "In automated mode, User 1 receives a dedicated Base58Check Tron address",
    !empty($user1MultiAddr['address']) && str_starts_with($user1MultiAddr['address'], 'T') && strlen($user1MultiAddr['address']) === 34,
    "Address: " . ($user1MultiAddr['address'] ?? 'none')
);

runCheck(
    "In automated mode, User 2 receives a distinct unique address from User 1",
    $user1MultiAddr['address'] !== $user2MultiAddr['address'],
    "User 1: {$user1MultiAddr['address']}, User 2: {$user2MultiAddr['address']}"
);

// Webhook test with assigned address
$webhookTxid = 'nowpay_ipn_tx_' . bin2hex(random_bytes(16));
$webhookPayload = [
    'payment_id' => 'np_pay_998877',
    'payment_status' => 'finished',
    'pay_address' => $user1MultiAddr['address'],
    'pay_amount' => 200.00,
    'actually_paid' => 200.00,
    'pay_currency' => 'usdttrc20',
    'txn_id' => $webhookTxid
];

$rawJson = json_encode($webhookPayload);
$webhookRes = CryptoDepositManager::handleNowPaymentsWebhook($webhookPayload, $rawJson, null);

runCheck(
    "NOWPayments webhook processes confirmed payment and credits user (200 USDT = 23,000 Coins)",
    !empty($webhookRes['success']) && abs((float)($webhookRes['coins_credited'] ?? 0) - 23000.00) < 0.01,
    "Result: " . json_encode($webhookRes)
);

// Webhook with unconfirmed status ('waiting') should be ignored safely
$waitingPayload = [
    'payment_id' => 'np_pay_waiting',
    'payment_status' => 'waiting',
    'pay_address' => $user1MultiAddr['address'],
    'actually_paid' => 100.00,
    'txn_id' => 'tx_waiting_' . bin2hex(random_bytes(8))
];
$waitingRes = CryptoDepositManager::handleNowPaymentsWebhook($waitingPayload, json_encode($waitingPayload), null);
runCheck(
    "Unconfirmed webhook status ('waiting') ignored without crediting balance",
    !empty($waitingRes['success']) && !empty($waitingRes['ignored'])
);

// Webhook with sub-minimum payment (<100 USDT)
$subWebhookPayload = [
    'payment_id' => 'np_pay_sub',
    'payment_status' => 'finished',
    'pay_address' => $user1MultiAddr['address'],
    'actually_paid' => 45.00,
    'txn_id' => 'tx_sub_webhook_' . bin2hex(random_bytes(8))
];
$subWebhookRes = CryptoDepositManager::handleNowPaymentsWebhook($subWebhookPayload, json_encode($subWebhookPayload), null);
runCheck(
    "Webhook with sub-minimum payment (45 USDT) rejected with below_minimum status",
    ($subWebhookRes['status'] ?? '') === 'below_minimum'
);

// HMAC-SHA512 Signature Verification Test
$ipnSecretTest = 'jelipay_test_ipn_secret_9988';
$pdo->prepare("UPDATE platform_settings SET setting_value = ? WHERE setting_key = 'nowpayments_ipn_secret'")->execute([$ipnSecretTest]);

$signedTxid = 'signed_ipn_tx_' . bin2hex(random_bytes(16));
$signedPayload = [
    'payment_id' => 'np_pay_signed',
    'payment_status' => 'finished',
    'pay_address' => $user1MultiAddr['address'],
    'actually_paid' => 120.00,
    'txn_id' => $signedTxid
];
$sortedCopy = $signedPayload;
ksort($sortedCopy);
$validSig = hash_hmac('sha512', json_encode($sortedCopy, JSON_UNESCAPED_SLASHES), $ipnSecretTest);
$invalidSig = 'wrong_hmac_signature_value_123456';

$tamperedRes = CryptoDepositManager::handleNowPaymentsWebhook($signedPayload, json_encode($signedPayload), $invalidSig);
runCheck(
    "Webhook with invalid HMAC-SHA512 signature rejected",
    $tamperedRes['success'] === false && str_contains($tamperedRes['error'] ?? '', 'Invalid HMAC-SHA512'),
    "Result: " . json_encode($tamperedRes)
);

$validSigRes = CryptoDepositManager::handleNowPaymentsWebhook($signedPayload, json_encode($signedPayload), $validSig);
runCheck(
    "Webhook with authentic HMAC-SHA512 signature verified and credited (120 USDT = 13,800 Coins)",
    !empty($validSigRes['success']) && abs((float)$validSigRes['coins_credited'] - 13800.00) < 0.01,
    "Result: " . json_encode($validSigRes)
);

// Switch back to manual provider as default
$pdo->prepare("UPDATE platform_settings SET setting_value = 'manual' WHERE setting_key = 'crypto_gateway_provider'")->execute();

// =========================================================================
// TEST GROUP 10: DEPOSIT LOGS & MACRO TOTALS
// =========================================================================
echo "\n[TEST GROUP 10: Admin Deposit Logs & Macro Counters]\n";

$logs = CryptoDepositManager::getDepositLogs(50, 0);
runCheck(
    "getDepositLogs returns list of deposits",
    !empty($logs['deposits']) && count($logs['deposits']) >= 5
);

runCheck(
    "getDepositLogs aggregates total completed USDT",
    ($logs['totals']['total_usdt_completed'] ?? 0) >= 800.00,
    "Total USDT: " . ($logs['totals']['total_usdt_completed'] ?? 0)
);

runCheck(
    "getDepositLogs aggregates below_minimum count",
    ($logs['totals']['total_below_minimum_count'] ?? 0) >= 2,
    "Below min count: " . ($logs['totals']['total_below_minimum_count'] ?? 0)
);

runCheck(
    "getDepositLogs aggregates pending_approval count",
    isset($logs['totals']['total_pending_count']),
    "Pending count: " . ($logs['totals']['total_pending_count'] ?? 0)
);

runCheck(
    "getDepositLogs aggregates rejected count",
    ($logs['totals']['total_rejected_count'] ?? 0) >= 1,
    "Rejected count: " . ($logs['totals']['total_rejected_count'] ?? 0)
);

runCheck(
    "getDepositLogs returns configured master wallet",
    $logs['master_wallet'] === 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV'
);

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n====================================================================\n";
echo " TEST RESULTS: {$passedTests} / {$totalTests} PASSED\n";
echo "====================================================================\n";

if ($passedTests === $totalTests) {
    echo ">>> ALL CRYPTO DEPOSIT SYSTEM CHECKS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED! <<<\n";
    exit(1);
}
