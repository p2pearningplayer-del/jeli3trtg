<?php
/**
 * Test Suite: USDT (TRC-20) Automated Crypto Deposit & Conversion System
 * 
 * Verifies:
 * 1. Default configuration (Master wallet TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV, 1:115 rate, 100 USDT min).
 * 2. Dedicated unique TRC20 address generation per user with Base58Check and SVG QR.
 * 3. Strict minimum deposit limit (<100 USDT rejected as below_minimum, 0 coins credited).
 * 4. Automatic coin conversion @ 1:115 ratio (e.g. 100 USDT = 11,500 Coins credited to Available Balance).
 * 5. Double-entry immutable ledger tracking (wallet_ledger with USDT_DEPOSIT).
 * 6. Strict idempotency (same TXID cannot be credited multiple times).
 * 7. Admin manual verification & audit logging.
 * 8. NOWPayments Webhook IPN processing and HMAC signature verification.
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
echo " JELIPAY - USDT (TRC20) CRYPTO DEPOSIT SYSTEM TEST SUITE\n";
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

// =========================================================================
// TEST GROUP 2: DEDICATED UNIQUE TRC20 ADDRESS GENERATION
// =========================================================================
echo "\n[TEST GROUP 2: Unique Dedicated TRC20 Address Generation]\n";

$user1AddrInfo = CryptoDepositManager::getUserDepositAddress(1);
$user2AddrInfo = CryptoDepositManager::getUserDepositAddress(2);

runCheck(
    "User 1 deposit address generated successfully",
    !empty($user1AddrInfo['address']) && str_starts_with($user1AddrInfo['address'], 'T'),
    "Address: " . ($user1AddrInfo['address'] ?? 'none')
);

runCheck(
    "User 1 deposit address is valid Tron length (34 chars)",
    strlen($user1AddrInfo['address']) === 34,
    "Length: " . strlen($user1AddrInfo['address'])
);

runCheck(
    "User 2 receives a distinct unique address from User 1",
    $user1AddrInfo['address'] !== $user2AddrInfo['address'],
    "User 1: {$user1AddrInfo['address']}, User 2: {$user2AddrInfo['address']}"
);

runCheck(
    "Deposit address query generates vector SVG QR code",
    !empty($user1AddrInfo['qr_svg']) && str_contains($user1AddrInfo['qr_svg'], '<svg'),
    "SVG contains: " . substr($user1AddrInfo['qr_svg'] ?? '', 0, 30)
);

$user1SecondQuery = CryptoDepositManager::getUserDepositAddress(1);
runCheck(
    "User address is persistent across repeat calls (same user retains same address)",
    $user1AddrInfo['address'] === $user1SecondQuery['address']
);

// =========================================================================
// TEST GROUP 3: STRICT MINIMUM DEPOSIT LIMIT ENFORCEMENT (< 100 USDT)
// =========================================================================
echo "\n[TEST GROUP 3: Strict Minimum Deposit Limit Enforcement (< 100 USDT)]\n";

$walletBeforeSub = Wallet::getWallet(1);
$balBeforeSub = (float)$walletBeforeSub['available_balance'];

$subTxid = 'submin_tx_' . bin2hex(random_bytes(8));
$subResult = CryptoDepositManager::processDeposit(1, $user1AddrInfo['address'], $subTxid, 50.00);

runCheck(
    "50.00 USDT deposit is rejected by system",
    $subResult['success'] === false && ($subResult['status'] ?? '') === 'below_minimum',
    "Result: " . json_encode($subResult)
);

$walletAfterSub = Wallet::getWallet(1);
$balAfterSub = (float)$walletAfterSub['available_balance'];
runCheck(
    "Zero coins credited to user's wallet for sub-minimum deposit",
    abs($balAfterSub - $balBeforeSub) < 0.001,
    "Before: {$balBeforeSub}, After: {$balAfterSub}"
);

$stmtSub = $pdo->prepare("SELECT * FROM crypto_deposits WHERE txid = ?");
$stmtSub->execute([$subTxid]);
$subRow = $stmtSub->fetch();
runCheck(
    "Sub-minimum transaction recorded in database with status 'below_minimum'",
    $subRow && $subRow['status'] === 'below_minimum' && (float)$subRow['coins_credited'] === 0.0
);

// Test edge case: 99.99 USDT (strictly below 100.00)
$edgeTxid = 'submin_edge_' . bin2hex(random_bytes(8));
$edgeResult = CryptoDepositManager::processDeposit(1, $user1AddrInfo['address'], $edgeTxid, 99.99);
runCheck(
    "99.99 USDT deposit is rejected (strict 100.00 minimum enforced)",
    $edgeResult['success'] === false && ($edgeResult['status'] ?? '') === 'below_minimum'
);

// =========================================================================
// TEST GROUP 4: AUTOMATED COIN CONVERSION & AVAILABLE BALANCE CREDITING
// =========================================================================
echo "\n[TEST GROUP 4: Automated Coin Conversion & Balance Crediting]\n";

$walletBeforeValid = Wallet::getWallet(1);
$balBeforeValid = (float)$walletBeforeValid['available_balance'];

// User 1 deposits 100 USDT -> Expect 100 * 115 = 11,500 Coins
$validTxid1 = 'tron_valid_tx_100usdt_' . bin2hex(random_bytes(16));
$validResult1 = CryptoDepositManager::processDeposit(1, $user1AddrInfo['address'], $validTxid1, 100.00);

runCheck(
    "100.00 USDT deposit processes successfully",
    !empty($validResult1['success']),
    "Error: " . ($validResult1['error'] ?? 'none')
);

runCheck(
    "100 USDT converts exactly to 11,500.00 Coins",
    abs((float)($validResult1['coins_credited'] ?? 0) - 11500.00) < 0.01,
    "Coins credited: " . ($validResult1['coins_credited'] ?? 0)
);

$walletAfterValid = Wallet::getWallet(1);
$balAfterValid = (float)$walletAfterValid['available_balance'];
runCheck(
    "User 1 Available Balance incremented by exactly 11,500.00 Coins",
    abs(($balAfterValid - $balBeforeValid) - 11500.00) < 0.01,
    "Expected diff 11500, got: " . ($balAfterValid - $balBeforeValid)
);

// Verify double-entry ledger record
$stmtLedger = $pdo->prepare("SELECT * FROM wallet_ledger WHERE user_id = 1 AND transaction_type = 'USDT_DEPOSIT' ORDER BY id DESC LIMIT 1");
$stmtLedger->execute();
$ledgerRow = $stmtLedger->fetch();
runCheck(
    "Double-entry wallet ledger created with transaction_type 'USDT_DEPOSIT'",
    $ledgerRow && abs((float)$ledgerRow['amount'] - 11500.00) < 0.01 && $ledgerRow['reference_id'] === $validTxid1,
    "Ledger: " . json_encode($ledgerRow)
);

// Verify database record in crypto_deposits
$stmtDep = $pdo->prepare("SELECT * FROM crypto_deposits WHERE txid = ?");
$stmtDep->execute([$validTxid1]);
$depRow = $stmtDep->fetch();
runCheck(
    "Crypto deposit record marked 'completed' and 'swept' to master wallet",
    $depRow && $depRow['status'] === 'completed' && $depRow['sweep_status'] === 'swept' && $depRow['master_wallet'] === 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV'
);

// Test another amount: 250 USDT -> Expect 250 * 115 = 28,750 Coins
$validTxid2 = 'tron_valid_tx_250usdt_' . bin2hex(random_bytes(16));
$validResult2 = CryptoDepositManager::processDeposit(1, $user1AddrInfo['address'], $validTxid2, 250.00);
runCheck(
    "250.00 USDT deposit converts to 28,750.00 Coins",
    !empty($validResult2['success']) && abs((float)$validResult2['coins_credited'] - 28750.00) < 0.01
);

// =========================================================================
// TEST GROUP 5: STRICT IDEMPOTENCY & REPLAY PREVENTION
// =========================================================================
echo "\n[TEST GROUP 5: Strict Idempotency & Replay Prevention]\n";

$walletBeforeReplay = Wallet::getWallet(1);
$balBeforeReplay = (float)$walletBeforeReplay['available_balance'];

// Resubmit the exact same $validTxid1
$replayResult = CryptoDepositManager::processDeposit(1, $user1AddrInfo['address'], $validTxid1, 100.00);

runCheck(
    "Replayed TXID returns idempotent response",
    !empty($replayResult['success']) && !empty($replayResult['idempotent']),
    "Response: " . json_encode($replayResult)
);

$walletAfterReplay = Wallet::getWallet(1);
$balAfterReplay = (float)$walletAfterReplay['available_balance'];
runCheck(
    "User Available Balance does NOT change on replayed TXID",
    abs($balAfterReplay - $balBeforeReplay) < 0.001,
    "Before: {$balBeforeReplay}, After: {$balAfterReplay}"
);

// =========================================================================
// TEST GROUP 6: ADMIN MANUAL CREDIT TOOL
// =========================================================================
echo "\n[TEST GROUP 6: Admin Manual Verification & Credit Tool]\n";

$user2Before = Wallet::getWallet(2);
$user2BalBefore = (float)$user2Before['available_balance'];

$adminTxid = 'admin_credit_tx_' . bin2hex(random_bytes(16));
$adminCreditRes = CryptoDepositManager::adminManualCredit(
    1, // adminId
    2, // userId
    $adminTxid,
    150.00, // 150 USDT -> 17,250 Coins
    'Verified on TronScan explorer block #6789012'
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
// TEST GROUP 7: NOWPAYMENTS WEBHOOK IPN PROCESSING
// =========================================================================
echo "\n[TEST GROUP 7: NOWPayments Webhook IPN Processing]\n";

$webhookTxid = 'nowpay_ipn_tx_' . bin2hex(random_bytes(16));
$webhookPayload = [
    'payment_id' => 'np_pay_998877',
    'payment_status' => 'finished',
    'pay_address' => $user1AddrInfo['address'],
    'pay_amount' => 200.00,
    'actually_paid' => 200.00,
    'pay_currency' => 'usdttrc20',
    'txn_id' => $webhookTxid
];

$rawJson = json_encode($webhookPayload);
$webhookRes = CryptoDepositManager::handleNowPaymentsWebhook($webhookPayload, $rawJson, null);

runCheck(
    "NOWPayments webhook processes confirmed payment and credits user",
    !empty($webhookRes['success']) && abs((float)($webhookRes['coins_credited'] ?? 0) - 23000.00) < 0.01,
    "Result: " . json_encode($webhookRes)
);

// Webhook with unconfirmed status (e.g. 'waiting') should be ignored safely
$waitingPayload = [
    'payment_id' => 'np_pay_waiting',
    'payment_status' => 'waiting',
    'pay_address' => $user1AddrInfo['address'],
    'actually_paid' => 100.00,
    'txn_id' => 'tx_waiting_' . bin2hex(random_bytes(8))
];
$waitingRes = CryptoDepositManager::handleNowPaymentsWebhook($waitingPayload, json_encode($waitingPayload), null);
runCheck(
    "Unconfirmed webhook status ('waiting') ignored without crediting balance",
    !empty($waitingRes['success']) && !empty($waitingRes['ignored'])
);

// Webhook with sub-minimum deposit (<100 USDT)
$subWebhookPayload = [
    'payment_id' => 'np_pay_sub',
    'payment_status' => 'finished',
    'pay_address' => $user1AddrInfo['address'],
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
    'pay_address' => $user1AddrInfo['address'],
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

// Reset secret for other tests
$pdo->prepare("UPDATE platform_settings SET setting_value = '' WHERE setting_key = 'nowpayments_ipn_secret'")->execute();

// =========================================================================
// TEST GROUP 8: DEPOSIT LOGS & AGGREGATE TOTALS
// =========================================================================
echo "\n[TEST GROUP 8: Admin Deposit Logs & Macro Totals]\n";

$logs = CryptoDepositManager::getDepositLogs(50, 0);
runCheck(
    "getDepositLogs returns list of deposits",
    !empty($logs['deposits']) && count($logs['deposits']) >= 4
);

runCheck(
    "getDepositLogs aggregates total completed USDT",
    ($logs['totals']['total_usdt_completed'] ?? 0) >= 700.00,
    "Total USDT: " . ($logs['totals']['total_usdt_completed'] ?? 0)
);

runCheck(
    "getDepositLogs aggregates below_minimum count",
    ($logs['totals']['total_below_minimum_count'] ?? 0) >= 3,
    "Below min count: " . ($logs['totals']['total_below_minimum_count'] ?? 0)
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
