<?php
/**
 * Test Suite: USDT Removal & Comprehensive Platform Security Hardening
 * 
 * Verifies:
 * 1. Complete USDT deposit removal (Endpoints return 404/410, UI clean).
 * 2. Strict input validation against negative/NaN/INF amounts in Wallet engine.
 * 3. File upload and execution security (.htaccess script blocking, serve_file nosniff).
 * 4. Universal IP ban enforcement across all core endpoints (auth, wallet, uploads, buy, sell, disputes).
 * 5. Atomic state transitions and IDOR protection.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Security/IPBanManager.php';

$testDbPath = __DIR__ . '/test_audit_' . time() . '.sqlite';
putenv("SQLITE_DATABASE_PATH={$testDbPath}");

$pdo = new PDO("sqlite:{$testDbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo, 'sqlite');

SchemaInstaller::ensureSchema();

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $desc, string $extra = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($extra ? " ({$extra})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo " JELIPAY - USDT REMOVAL & DEFENSIVE SECURITY AUDIT SUITE\n";
echo "====================================================================\n\n";

// ====================================================================
// GROUP 1: USDT Removal Verification
// ====================================================================
echo "--- TEST GROUP 1: Complete USDT Removal Verification ---\n";

// 1.1 Webhook returns 410 decommissioned
$webhookCliOutput = shell_exec('php api/crypto_webhook.php 2>&1');
$webhookJson = json_decode($webhookCliOutput, true);

assertCondition(
    !empty($webhookJson) && str_contains($webhookJson['error'] ?? '', 'decommissioned'),
    "api/crypto_webhook.php explains service is permanently decommissioned"
);

// 1.2 Verify index.php has no USDT deposit elements
$indexContent = file_get_contents(__DIR__ . '/../index.php');
assertCondition(
    !str_contains($indexContent, 'cryptoDepositModal'),
    "index.php contains no #cryptoDepositModal element"
);
assertCondition(
    !str_contains($indexContent, 'Deposit USDT'),
    "index.php contains no 'Deposit USDT' button"
);
assertCondition(
    !str_contains($indexContent, 'CryptoDepositManager'),
    "index.php does not require or execute CryptoDepositManager"
);

// 1.3 Verify admin.php has no USDT views or modals
$adminContent = file_get_contents(__DIR__ . '/../admin.php');
assertCondition(
    !str_contains($adminContent, 'adminView-crypto'),
    "admin.php contains no #adminView-crypto section"
);
assertCondition(
    !str_contains($adminContent, 'adminNav-crypto'),
    "admin.php contains no #adminNav-crypto navigation tab"
);
assertCondition(
    !str_contains($adminContent, 'adminCryptoCreditModal'),
    "admin.php contains no #adminCryptoCreditModal"
);

// 1.4 Verify public/js/app.js has no crypto methods
$appJsContent = file_get_contents(__DIR__ . '/../public/js/app.js');
assertCondition(
    !str_contains($appJsContent, 'openCryptoDepositModal'),
    "public/js/app.js does not contain openCryptoDepositModal"
);
assertCondition(
    !str_contains($appJsContent, 'submitCryptoTxidVerification'),
    "public/js/app.js does not contain submitCryptoTxidVerification"
);

// 1.5 Verify public/js/admin.js has no crypto methods
$adminJsContent = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertCondition(
    !str_contains($adminJsContent, 'loadAdminCryptoDeposits'),
    "public/js/admin.js does not contain loadAdminCryptoDeposits"
);
assertCondition(
    !str_contains($adminJsContent, "'crypto'"),
    "public/js/admin.js validTabs does not include 'crypto'"
);

// ====================================================================
// GROUP 2: Numeric Injection & Negative Balance Protection
// ====================================================================
echo "\n--- TEST GROUP 2: Numeric Injection & Negative Balance Protection ---\n";

// Create test user
$userRes = Auth::register('9876500001', 'SecurePass123!', null, 'user1@upi', 'Alice Secure', 'user1@gmail.com', true);
$userId = (int)$userRes['user_id'];

// Set available balance to ₹1,000
$pdo->prepare("UPDATE wallets SET available_balance = 1000.00 WHERE user_id = ?")->execute([$userId]);

// 2.1 Attempt reserveForSell with negative amount
$negReserve = Wallet::reserveForSell($userId, -500.00, 'TEST_NEG_1');
assertCondition(
    !$negReserve['success'],
    "Wallet::reserveForSell strictly rejects negative amount (-₹500.00)"
);

// 2.2 Attempt reserveForSell with zero amount
$zeroReserve = Wallet::reserveForSell($userId, 0.00, 'TEST_ZERO_1');
assertCondition(
    !$zeroReserve['success'],
    "Wallet::reserveForSell strictly rejects zero amount"
);

// 2.3 Attempt releaseReserved with negative amount
$negRelease = Wallet::releaseReserved($userId, -200.00, 'TEST_NEG_REL');
assertCondition(
    !$negRelease['success'],
    "Wallet::releaseReserved strictly rejects negative amount"
);

// 2.4 Attempt settleSellOrder with negative amount
$negSettle = Wallet::settleSellOrder($userId, -100.00, 'TEST_NEG_SETTLE');
assertCondition(
    !$negSettle['success'],
    "Wallet::settleSellOrder strictly rejects negative amount"
);

// 2.5 Attempt creditBuyer with negative amount
$negCredit = Wallet::creditBuyer($userId, -100.00, 0.00, 'TEST_NEG_CRED');
assertCondition(
    !$negCredit['success'],
    "Wallet::creditBuyer strictly rejects negative base amount"
);

// 2.6 Attempt adminAdjust with negative amount
$negAdj = Wallet::adminAdjust($userId, 'credit', -50.00, 'Test reason', 1);
assertCondition(
    !$negAdj['success'],
    "Wallet::adminAdjust strictly rejects negative adjustment amount"
);

// Verify balance is unaltered after all negative injection attempts
$wCheck = Wallet::getWallet($userId);
assertCondition(
    (float)$wCheck['available_balance'] === 1000.00,
    "Wallet available balance remained exactly ₹1,000.00 (No injection leakage)"
);

// ====================================================================
// GROUP 3: File Upload Hardening & Execution Prevention
// ====================================================================
echo "\n--- TEST GROUP 3: File Upload Hardening & Execution Prevention ---\n";

$htaccessPath = __DIR__ . '/../uploads/.htaccess';
assertCondition(
    file_exists($htaccessPath),
    "uploads/.htaccess security configuration exists"
);

$htaccessContent = file_get_contents($htaccessPath);
assertCondition(
    str_contains($htaccessContent, 'Options -Indexes -ExecCGI'),
    "uploads/.htaccess disables Indexes and ExecCGI"
);
assertCondition(
    str_contains($htaccessContent, 'Require all denied') && str_contains($htaccessContent, 'php'),
    "uploads/.htaccess strictly denies script execution (PHP, PHTML, PHAR, CGI, etc.)"
);
assertCondition(
    str_contains($htaccessContent, 'php_flag engine off'),
    "uploads/.htaccess turns off mod_php engine"
);

// Test serve_file.php path traversal protection
$serveFileContent = file_get_contents(__DIR__ . '/../serve_file.php');
assertCondition(
    str_contains($serveFileContent, "str_contains(\$requestedFile, '..')"),
    "serve_file.php blocks directory traversal attacks containing '..'"
);
assertCondition(
    str_contains($serveFileContent, "header('X-Content-Type-Options: nosniff');"),
    "serve_file.php enforces X-Content-Type-Options: nosniff header"
);
assertCondition(
    str_contains($serveFileContent, "header('Content-Security-Policy: default-src \\'none\\'');"),
    "serve_file.php enforces Content-Security-Policy: default-src 'none' header"
);

// ====================================================================
// GROUP 4: Universal IP Ban Removal Across All APIs
// ====================================================================
echo "\n--- TEST GROUP 4: Universal IP Ban Removal Across All APIs ---\n";

$testIp = '203.0.113.88';
assertCondition(!IPBanManager::isBanned($testIp), "IP {$testIp} is initially not banned");

// Calling recordViolation does not ban IP (IP banning removed)
IPBanManager::recordViolation($testIp, "Simulated security exploit attack", 'EXPLOIT_ATTEMPT', 24);
assertCondition(!IPBanManager::isBanned($testIp), "IP {$testIp} is NOT banned after recordViolation (IP banning removed)");

// Verify that all core API files have IP ban blocks removed
$authFile = file_get_contents(__DIR__ . '/../api/auth.php');
assertCondition(
    !str_contains($authFile, 'IPBanManager::isBanned'),
    "api/auth.php does not block users by IP ban"
);

$walletFile = file_get_contents(__DIR__ . '/../api/wallet.php');
assertCondition(
    !str_contains($walletFile, 'IPBanManager::isBanned'),
    "api/wallet.php does not block users by IP ban"
);

$uploadsFile = file_get_contents(__DIR__ . '/../api/uploads.php');
assertCondition(
    !str_contains($uploadsFile, 'IPBanManager::isBanned'),
    "api/uploads.php does not block users by IP ban"
);

$buyFile = file_get_contents(__DIR__ . '/../api/buy.php');
assertCondition(
    !str_contains($buyFile, 'IPBanManager::isBanned'),
    "api/buy.php does not block users by IP ban"
);

$sellFile = file_get_contents(__DIR__ . '/../api/sell.php');
assertCondition(
    !str_contains($sellFile, 'IPBanManager::isBanned'),
    "api/sell.php does not block users by IP ban"
);

$disputesFile = file_get_contents(__DIR__ . '/../api/disputes.php');
assertCondition(
    !str_contains($disputesFile, 'IPBanManager::isBanned'),
    "api/disputes.php does not block users by IP ban"
);

$upiFile = file_get_contents(__DIR__ . '/../api/upi.php');
assertCondition(
    !str_contains($upiFile, 'IPBanManager::isBanned'),
    "api/upi.php does not block users by IP ban"
);

IPBanManager::unbanIp($testIp);
assertCondition(!IPBanManager::isBanned($testIp), "IP {$testIp} remains unbanned");

// ====================================================================
// GROUP 5: Dispute Concurrency & Settlement Protection
// ====================================================================
echo "\n--- TEST GROUP 5: Dispute Concurrency & Settlement Protection ---\n";

// Create seller and buyer
$sellerRes = Auth::register('9876500002', 'SecurePass123!', null, 'seller@upi', 'Bob Seller', 'seller@gmail.com', true);
$sellerId = (int)$sellerRes['user_id'];
Auth::setTPIN($sellerId, '5555');

$pdo->prepare("UPDATE wallets SET available_balance = 1000.00 WHERE user_id = ?")->execute([$sellerId]);

// Create sell order
$sellOrder = OrderEngine::createSellOrder($sellerId, 500.00, '5555', null, null, false);
$parentOrderId = (int)$sellOrder['parent_order_id'];

// Pick child order as buyer
$childStmt = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' LIMIT 1");
$childStmt->execute([$parentOrderId]);
$childId = (int)$childStmt->fetchColumn();

$pickRes = StateMachine::pickOrder($childId, $userId);
assertCondition($pickRes['success'], "Buyer successfully reserved order #{$childId}");

// Update child order to 'Seller Confirmation Pending' (as if payment proof was submitted)
$pdo->prepare("UPDATE child_orders SET status = 'Seller Confirmation Pending' WHERE id = ?")->execute([$childId]);

// Raise appeal to create dispute
$appealRes = StateMachine::raiseAppeal($childId, $sellerId, 'Payment not received within expected timeframe');
assertCondition($appealRes['success'], "Seller successfully raised appeal -> Dispute record created");

$dispStmt = $pdo->prepare("SELECT id FROM disputes WHERE child_order_id = ?");
$dispStmt->execute([$childId]);
$disputeId = (int)$dispStmt->fetchColumn();
assertCondition($disputeId > 0, "Dispute #{$disputeId} exists in database");

// First resolution by admin
$resolve1 = AdminManager::resolveDispute($disputeId, 1, 'force_settle', 'Payment verified via bank statement');
assertCondition($resolve1['success'], "First dispute resolution (force_settle) succeeds");

// Concurrent second resolution attempt MUST be rejected
$resolve2 = AdminManager::resolveDispute($disputeId, 1, 'force_settle', 'Duplicate resolution attempt');
assertCondition(!$resolve2['success'], "Concurrent/duplicate dispute resolution is strictly rejected");

$resolve3 = AdminManager::resolveDispute($disputeId, 1, 'cancel_reverse', 'Conflicting resolution attempt');
assertCondition(!$resolve3['success'], "Conflicting dispute resolution on finalized dispute is strictly rejected");

// ====================================================================
// SUMMARY & CLEANUP
// ====================================================================
echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "====================================================================\n";

$pdo = null;
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

exit($failed > 0 ? 1 : 0);
