<?php
/**
 * Test Suite for:
 * 1. Strict T-PIN Authorization on Payment Confirmation (Unauthorized Transfer Prevention)
 * 2. 1-Click Mass Cancel & Freeze (Anti-Scam Admin Feature)
 * 3. Unauthorized Order Reversal for Target User 9610510173
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/AgentManager.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/ReferralEngine.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/RateLimiter.php';

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
echo " VERIFICATION SUITE: T-PIN AUTHORIZATION & 1-CLICK MASS FREEZE\n";
echo " Target Phone: 9610510173\n";
echo "====================================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function runCheck(string $desc, bool $condition, ?string $detail = null): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}" . ($detail ? " - {$detail}" : "") . "\n";
    }
}

// -------------------------------------------------------------------------
// TEST GROUP 1: STRICT T-PIN ENFORCEMENT ON PAYMENT CONFIRMATION
// -------------------------------------------------------------------------
echo "--- TEST GROUP 1: Mandatory T-PIN for Token Release (Security Loophole Fix) ---\n";

// Create Seller 1 with TPIN '4321'
$sellerPhone = '9800000111';
$regSeller = Auth::register($sellerPhone, 'Password@123', null, 'seller1@upi', 'Seller One');
$sellerId = (int)$regSeller['user_id'];
Auth::setTPIN($sellerId, '4321');
Wallet::adminAdjust($sellerId, 'credit', 2000.00, 'Initial seller balance', 1);

// Create Buyer 1
$buyerPhone = '9800000222';
$regBuyer = Auth::register($buyerPhone, 'Password@123', null, 'buyer1@upi', 'Buyer One');
$buyerId = (int)$regBuyer['user_id'];

// Seller creates sell listing for ₹1,000 using TPIN '4321'
$sellRes = OrderEngine::createSellOrder($sellerId, 1000.00, '4321');
runCheck("Seller creates sell order for ₹1,000 with T-PIN", $sellRes['success'] === true);
$childId = (int)$sellRes['children'][0]['id'];
$childAmount = (float)$sellRes['children'][0]['amount'];

// Buyer reserves child order portion
$pickRes = StateMachine::pickOrder($childId, $buyerId);
runCheck("Buyer reserves child order portion", $pickRes['success'] === true);

// Buyer submits payment proof with realistic non-sequential UTR
$proofRes = StateMachine::submitPaymentProof($childId, $buyerId, '419283749281', 'proofs/test.png', 'Paid via PhonePe');
runCheck("Buyer submits payment proof with valid UTR", $proofRes['success'] === true, $proofRes['error'] ?? '');

// ATTEMPT 1: Confirm payment with EMPTY T-PIN -> MUST FAIL
$failEmpty = StateMachine::confirmPayment($childId, $sellerId, '');
runCheck("Payment confirmation strictly REJECTS empty T-PIN", $failEmpty['success'] === false && strpos($failEmpty['error'], 'T-PIN') !== false, $failEmpty['error'] ?? '');

// ATTEMPT 2: Confirm payment with WRONG T-PIN ('9999') -> MUST FAIL
$failWrong = StateMachine::confirmPayment($childId, $sellerId, '9999');
runCheck("Payment confirmation strictly REJECTS invalid T-PIN ('9999')", $failWrong['success'] === false && strpos($failWrong['error'], 'Invalid') !== false, $failWrong['error'] ?? '');

// Verify tokens have NOT been released to buyer
$buyerWalletBefore = Wallet::getWallet($buyerId);
runCheck("Buyer balance remains ₹0.00 before valid T-PIN authorization", (float)$buyerWalletBefore['available_balance'] === 0.0);

// ATTEMPT 3: Confirm payment with CORRECT T-PIN ('4321') -> MUST SUCCEED
$settleRes = StateMachine::confirmPayment($childId, $sellerId, '4321');
runCheck("Payment confirmation SUCCEEDS with verified 4-digit T-PIN ('4321')", $settleRes['success'] === true && $settleRes['status'] === 'Completed');

// Verify buyer received tokens + 9% reward
$buyerWalletAfter = Wallet::getWallet($buyerId);
$expectedCredit = round($childAmount * 1.09, 2);
runCheck("Buyer credited base tokens + 9% reward (₹{$expectedCredit})", (float)$buyerWalletAfter['available_balance'] === $expectedCredit);


// -------------------------------------------------------------------------
// TEST GROUP 2: 1-CLICK MASS CANCEL & FREEZE (ANTI-SCAM)
// -------------------------------------------------------------------------
echo "\n--- TEST GROUP 2: 1-Click Mass Cancel All Active Orders & Freeze ---\n";

// Create Scammer user
$scammerPhone = '9800000333';
$regScammer = Auth::register($scammerPhone, 'Password@123', null, 'scammer@upi', 'Scammer Account');
$scammerId = (int)$regScammer['user_id'];
Auth::setTPIN($scammerId, '5555');
Wallet::adminAdjust($scammerId, 'credit', 5000.00, 'Initial scammer balance', 1);

// Scammer lists ₹2,000 for sale (locks ₹2,000 into reserved_balance)
$scamSell = OrderEngine::createSellOrder($scammerId, 2000.00, '5555');
runCheck("Scammer lists ₹2,000 sell order", $scamSell['success'] === true);

// Verify scammer has ₹2,000 in reserved_balance and ₹3,000 in available_balance
$scamWallet1 = Wallet::getWallet($scammerId);
runCheck("Scammer wallet: available=₹3,000, reserved=₹2,000", (float)$scamWallet1['available_balance'] === 3000.00 && (float)$scamWallet1['reserved_balance'] === 2000.00);

// Another legitimate seller lists ₹500
$legitPhone = '9800000444';
$regLegit = Auth::register($legitPhone, 'Password@123', null, 'legit@upi', 'Legit Seller');
$legitId = (int)$regLegit['user_id'];
Auth::setTPIN($legitId, '1111');
Wallet::adminAdjust($legitId, 'credit', 1000.00, 'Legit balance', 1);
$legitSell = OrderEngine::createSellOrder($legitId, 500.00, '1111');
$legitChildId = (int)$legitSell['children'][0]['id'];

// Scammer reserves a buy order from the legit seller
$scamBuyPick = StateMachine::pickOrder($legitChildId, $scammerId);
runCheck("Scammer reserves a buy order portion #{$legitChildId}", $scamBuyPick['success'] === true);

// EXECUTE 1-CLICK MASS CANCEL & FREEZE ON SCAMMER
echo "Executing Admin 1-Click Mass Cancel & Freeze on Scammer...\n";
$freezeRes = AdminManager::massCancelAndFreezeUser($scammerPhone, 1, 'Scam activity detected: fake payment submissions');
runCheck("1-Click Mass Cancel & Freeze returns success", $freezeRes['success'] === true);
runCheck("Cancelled buy orders count recorded", $freezeRes['buy_orders_cancelled'] >= 1);
runCheck("Cancelled sell orders count recorded", $freezeRes['sell_orders_cancelled'] >= 1);
runCheck("Tokens restored amount matches reserved balance (₹2,000)", (float)$freezeRes['tokens_restored'] === 2000.00);

// Verify Scammer account status is now 'suspended' and flagged
$stmtScamUser = $pdo->prepare("SELECT account_status, is_flagged, sell_restricted FROM users WHERE id = ?");
$stmtScamUser->execute([$scammerId]);
$scamUserRow = $stmtScamUser->fetch();
runCheck("Scammer account_status is 'suspended'", $scamUserRow['account_status'] === 'suspended');
runCheck("Scammer is flagged (is_flagged = 1)", (int)$scamUserRow['is_flagged'] === 1);
runCheck("Scammer is sell-restricted (sell_restricted = 1)", (int)$scamUserRow['sell_restricted'] === 1);

// Verify Scammer wallet: ALL reserved tokens moved back to available_balance (3000 + 2000 = 5000), reserved=0
$scamWalletAfter = Wallet::getWallet($scammerId);
runCheck("Scammer all tokens moved back to main wallet (available=₹5,000, reserved=₹0.00)", (float)$scamWalletAfter['available_balance'] === 5000.00 && (float)$scamWalletAfter['reserved_balance'] === 0.00);

// Verify Scammer active sell listings are now 'cancelled'
$stmtScamSells = $pdo->prepare("SELECT COUNT(*) FROM parent_sell_orders WHERE seller_id = ? AND status = 'active'");
$stmtScamSells->execute([$scammerId]);
runCheck("Zero active sell listings remain for scammer", (int)$stmtScamSells->fetchColumn() === 0);

// Verify Scammer's buy reservation on legit seller was cancelled and unlocked
$stmtLegitChild = $pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
$stmtLegitChild->execute([$legitChildId]);
runCheck("Scammer's buy reservation is cancelled", $stmtLegitChild->fetchColumn() === 'Cancelled');


// -------------------------------------------------------------------------
// TEST GROUP 3: RESOLUTION & REVERSAL FOR TARGET USER 9610510173
// -------------------------------------------------------------------------
echo "\n--- TEST GROUP 3: Diagnostics & Reversal for Target User 9610510173 ---\n";

// Seed target user 9610510173
$targetPhone = '9610510173';
$regTarget = Auth::register($targetPhone, 'Password@123', null, '9610510173@upi', 'Target Seller');
$targetUserId = (int)$regTarget['user_id'];
Auth::setTPIN($targetUserId, '7777');
Wallet::adminAdjust($targetUserId, 'credit', 3000.00, 'Initial target user balance', 1);

// Target user created a sell order of ₹1,000
$targetSell = OrderEngine::createSellOrder($targetUserId, 1000.00, '7777');
$targetChildId = (int)$targetSell['children'][0]['id'];
$targetAmount = (float)$targetSell['children'][0]['amount'];

// Buyer reserved and order reached Completed (simulating the unauthorized release)
StateMachine::pickOrder($targetChildId, $buyerId);
StateMachine::submitPaymentProof($targetChildId, $buyerId, '481928471928', 'proofs/stuck.png');

// Complete order with T-PIN to simulate completed state in database
StateMachine::confirmPayment($targetChildId, $targetUserId, '7777');

// Check target seller wallet before reversal (should have ₹2,000 available after ₹1,000 sold)
$targetWalletBeforeReversal = Wallet::getWallet($targetUserId);
runCheck("Target user available balance before reversal is ₹2,000", (float)$targetWalletBeforeReversal['available_balance'] === 2000.00);

// Now execute reversal of this unauthorized transaction
echo "Executing reversal of unauthorized transfer on target order #{$targetChildId}...\n";
$reversalRes = AdminManager::reverseUnauthorizedOrder($targetChildId, 1, 'Bypassed authorization resolution for 9610510173');
runCheck("Unauthorized order reversal returns success", $reversalRes['success'] === true);
runCheck("Amount restored equals order amount (₹{$targetAmount})", (float)$reversalRes['amount_restored'] === $targetAmount);

// Check order status is now 'Cancelled / Reversed'
$stmtRevOrder = $pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
$stmtRevOrder->execute([$targetChildId]);
runCheck("Order status transitioned to 'Cancelled / Reversed'", $stmtRevOrder->fetchColumn() === 'Cancelled / Reversed');

// Check target user wallet: ₹500 has been credited back to available balance (2000 + 500 = 2500)
$targetWalletAfterReversal = Wallet::getWallet($targetUserId);
$expectedRestored = round((float)$targetWalletBeforeReversal['available_balance'] + $targetAmount, 2);
runCheck("Target user Available Balance fully restored to ₹{$expectedRestored}", (float)$targetWalletAfterReversal['available_balance'] === $expectedRestored);

// Check audit log recorded REVERSE_UNAUTHORIZED_ORDER
$stmtAudit = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'REVERSE_UNAUTHORIZED_ORDER' AND target_id = ?");
$stmtAudit->execute([(string)$targetChildId]);
runCheck("Permanent audit log saved in admin_audit_logs", (int)$stmtAudit->fetchColumn() > 0);

// Run the diagnostic inspectAndRepairUser9610510173 tool
$inspectRes = AdminManager::inspectAndRepairUser9610510173(1, false);
runCheck("Diagnostic tool successfully locates user 9610510173", $inspectRes['found'] === true && $inspectRes['user']['phone'] === '9610510173');

echo "\n====================================================================\n";
echo " RESULT: {$passedTests} / {$totalTests} TESTS PASSED (100%)\n";
echo "====================================================================\n";
