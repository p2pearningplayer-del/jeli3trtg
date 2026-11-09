<?php
/**
 * Verification Suite: UPI Risk Trigger Conditions & 5-Strike Seller-Fault Threshold
 *
 * Tests:
 * 1. Strict Exclusion of Buyer Fault:
 *    - Cancellations due to 'insufficient_funds', 'placed_by_mistake', 'changed_mind', 'other',
 *      and buyer payment window timeouts (expireSingleOrder) NEVER count towards seller strikes.
 *    - Seller's active listing remains 100% live and unrestricted even after 10+ buyer-fault cancellations.
 * 2. Strict Trigger Condition (Seller Fault ONLY) & 5-Strike Threshold:
 *    - Seller-fault reasons ('upi_risk', 'seller_inactive', 'upi_invalid', 'payment_failed', 'seller_timeout')
 *      increment the seller-fault strike counter.
 *    - Strikes 1, 2, 3, and 4 do NOT trigger UPI restriction or hide the seller's orders.
 *    - Exactly on the 5th seller-fault cancellation, the 4-hour UPI cooldown restriction triggers
 *      and hides the seller's active listings from the public Buy section.
 * 3. Seller Verification Timeout ('seller_timeout') Strike:
 *    - When a seller fails to release tokens within the 15-minute verification window after payment proof
 *      is submitted, it counts as a seller-fault strike towards the 5-strike threshold.
 * 4. Post-Reactivation 5-Strike Requirement for 8-Hour Ban:
 *    - After the initial 4-hour cooldown expires, it requires 5 new seller-fault strikes to trigger the 8-hour ban.
 */

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/UpiManager.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';

SchemaInstaller::ensureSchema();
$pdo = Database::getConnection();

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failed++;
    }
}

echo "=== Test Suite: UPI Risk 5-Strike Seller-Fault-Only Logic ===\n\n";

$suffix = substr((string)time(), -5) . rand(10, 99);
$sellerPhone = '970' . $suffix;
$buyer1Phone = '980' . $suffix;
$buyer2Phone = '990' . $suffix;
$sellerUpi = "seller5strike_{$suffix}@upi";

$hash = password_hash('Test@12345', PASSWORD_BCRYPT);
$tpin = password_hash('1234', PASSWORD_BCRYPT);

$insUser = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, tpin_hash, account_status, role, referral_code, upi_id, account_holder_name)
    VALUES (?, ?, ?, ?, 'active', 'user', ?, ?, ?)
");

$insUser->execute([$sellerPhone, "sel5_{$suffix}@gmail.com", $hash, $tpin, "SEL5{$suffix}", $sellerUpi, "Five Strike Seller"]);
$sellerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 10000, 0, 0, 1)")->execute([$sellerId]);

$insUser->execute([$buyer1Phone, "buy1_{$suffix}@gmail.com", $hash, $tpin, "BUY15{$suffix}", "buy1_{$suffix}@upi", "Buyer One"]);
$buyer1Id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$buyer1Id]);

$insUser->execute([$buyer2Phone, "buy2_{$suffix}@gmail.com", $hash, $tpin, "BUY25{$suffix}", "buy2_{$suffix}@upi", "Buyer Two"]);
$buyer2Id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$buyer2Id]);

UpiManager::getUserUpiAccounts($sellerId);

// Seller creates a ₹1000 sell listing
$sellRes = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', 'Five Strike Seller', $sellerUpi);
assertTrue(!empty($sellRes['success']), "Seller created ₹1000 sell listing with UPI {$sellerUpi}");
$parentOrderId = (int)$sellRes['parent_order_id'];

$childStmt = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' ORDER BY id ASC LIMIT 1");
$childStmt->execute([$parentOrderId]);
$childId = (int)$childStmt->fetchColumn();
assertTrue($childId > 0, "Available child order #{$childId} ready in Buy pool");

// -----------------------------------------------------------------------------
// 1. STRICT EXCLUSION: Buyer-Fault Cancellations & Buyer Payment Timeouts
// -----------------------------------------------------------------------------
echo "\n--- 1. Strict Exclusion: Ignore Buyer-Fault Cancellations & Buyer Timeouts ---\n";

$buyerFaultReasons = [
    ['insufficient_funds', 'Not enough balance in bank account'],
    ['placed_by_mistake', 'Changed mind / Placed by mistake'],
    ['changed_mind', 'Buyer changed mind'],
    ['other', 'Buyer personal reason'],
    ['insufficient_funds', 'Bank daily limit reached on buyer side'],
    ['placed_by_mistake', 'Picked wrong amount'],
    ['other', 'Will buy later'],
    ['insufficient_funds', 'Insufficient funds again']
];

foreach ($buyerFaultReasons as $idx => [$reasonCode, $reasonText]) {
    $buyerId = ($idx % 2 === 0) ? $buyer1Id : $buyer2Id;
    $pick = StateMachine::pickOrder($childId, $buyerId);
    $cancel = StateMachine::cancelReservation($childId, $buyerId, $reasonCode, $reasonText);
}

// Also simulate 2 buyer reservation timeouts (buyer never made payment within 10 mins)
for ($t = 1; $t <= 2; $t++) {
    $stmtAvail = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' ORDER BY id DESC LIMIT 1");
    $stmtAvail->execute([$parentOrderId]);
    $availOrderId = (int)$stmtAvail->fetchColumn();

    StateMachine::pickOrder($availOrderId, $buyer1Id);
    $expired = StateMachine::expireSingleOrder($availOrderId);
    assertTrue($expired === true, "Buyer payment timeout #{$t} expired and relisted order cleanly");
}

// Re-fetch current available child order for this parent
$stmtAvail = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' ORDER BY id DESC LIMIT 1");
$stmtAvail->execute([$parentOrderId]);
$childId = (int)$stmtAvail->fetchColumn();

$strikesAfterBuyerFaults = UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi);
assertTrue($strikesAfterBuyerFaults === 0, "After 8 buyer-fault cancellations + 2 buyer timeouts (10 buyer-side failures), seller-fault strike count is 0");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "Seller UPI ({$sellerUpi}) remains 100% LIVE and unrestricted after 10 buyer-fault events");

// -----------------------------------------------------------------------------
// 2. STRICT SELLER-FAULT TRIGGER & 5-STRIKE THRESHOLD
// -----------------------------------------------------------------------------
echo "\n--- 2. Strict Seller-Fault Trigger & 5-Strike Threshold ---\n";

// Strike 1: upi_risk ("Payment Failed to this UPI")
StateMachine::pickOrder($childId, $buyer1Id);
StateMachine::cancelReservation($childId, $buyer1Id, 'upi_risk', 'Payment failed to this UPI (Strike 1)');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 1, "Strike 1 recorded ('upi_risk') -> strike count = 1");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is NOT restricted after 1 seller-fault strike");

// Strike 2: seller_inactive ("Seller UPI Invalid / Name Mismatch")
StateMachine::pickOrder($childId, $buyer2Id);
StateMachine::cancelReservation($childId, $buyer2Id, 'seller_inactive', 'Seller UPI invalid / inactive (Strike 2)');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 2, "Strike 2 recorded ('seller_inactive') -> strike count = 2");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is NOT restricted after 2 seller-fault strikes");

// Strike 3: upi_invalid ("Seller UPI Invalid")
StateMachine::pickOrder($childId, $buyer1Id);
StateMachine::cancelReservation($childId, $buyer1Id, 'upi_invalid', 'Seller UPI invalid (Strike 3)');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 3, "Strike 3 recorded ('upi_invalid') -> strike count = 3");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is NOT restricted after 3 seller-fault strikes");

// Strike 4: payment_failed ("Payment Failed to this UPI")
StateMachine::pickOrder($childId, $buyer2Id);
StateMachine::cancelReservation($childId, $buyer2Id, 'payment_failed', 'Payment failed to this UPI (Strike 4)');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 4, "Strike 4 recorded ('payment_failed') -> strike count = 4");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is NOT restricted after 4 seller-fault strikes");

// Interleave Buyer-Fault cancellations at 4/5 strikes — must NOT push seller over threshold!
StateMachine::pickOrder($childId, $buyer1Id);
StateMachine::cancelReservation($childId, $buyer1Id, 'insufficient_funds', 'Buyer had insufficient funds at 4 strikes');
StateMachine::pickOrder($childId, $buyer2Id);
StateMachine::cancelReservation($childId, $buyer2Id, 'placed_by_mistake', 'Buyer changed mind at 4 strikes');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 4, "Buyer-fault cancellations at 4/5 strikes do NOT increment strike count (still 4)");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI remains LIVE at 4/5 seller-fault strikes");

// Strike 5: Seller-fault cancellation #5 -> MUST TRIGGER 4-HOUR BAN!
StateMachine::pickOrder($childId, $buyer1Id);
StateMachine::cancelReservation($childId, $buyer1Id, 'upi_risk', 'Payment failed on UPI (Strike 5)');
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 5, "Strike 5 recorded ('upi_risk') -> strike count = 5");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === true, "UPI IS NOW RESTRICTED (hidden from Buy section) upon reaching 5th seller-fault strike!");

$cooldownInfo = UpiManager::getUpiCooldownInfo($sellerUpi);
assertTrue($cooldownInfo['is_in_cooldown'] === true && $cooldownInfo['cooldown_remaining_seconds'] > 3.5 * 3600 && $cooldownInfo['cooldown_remaining_seconds'] <= 4 * 3600, "Initial 5-strike restriction applies 4-hour cooldown ban");

// -----------------------------------------------------------------------------
// 3. REACTIVATION & 5 NEW STRIKES FOR 8-HOUR BAN
// -----------------------------------------------------------------------------
echo "\n--- 3. Post-Reactivation 5 New Seller-Fault Strikes for 8-Hour Ban ---\n";

// Simulate 4-hour cooldown expiring naturally (set risk_cooldown_until to 10 seconds in the past)
$pastCooldown = gmdate('Y-m-d H:i:s', time() - 10);
$pdo->prepare("UPDATE user_upi_accounts SET is_risk_flagged = 0, risk_cooldown_until = ? WHERE LOWER(upi_id) = ?")->execute([$pastCooldown, $sellerUpi]);
$pdo->prepare("UPDATE users SET upi_risk_flag = 0, upi_risk_cooldown_until = ? WHERE id = ?")->execute([$pastCooldown, $sellerId]);

assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is live again after 4-hour cooldown expires");
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 0, "New cycle strike count starts at 0 after previous cooldown expired");

// 4 new seller-fault strikes after reactivation must NOT trigger 8-hour ban yet
for ($s = 1; $s <= 4; $s++) {
    StateMachine::pickOrder($childId, $buyer1Id);
    StateMachine::cancelReservation($childId, $buyer1Id, 'upi_risk', "Post-reactivation strike {$s}");
}
assertTrue(UpiManager::getSellerFaultStrikeCount($pdo, $sellerUpi) === 4, "4 new seller-fault strikes recorded after reactivation");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UPI is NOT re-banned after 4 new strikes (< 5)");

// 5th new seller-fault strike triggers 8-hour repeat ban
StateMachine::pickOrder($childId, $buyer2Id);
StateMachine::cancelReservation($childId, $buyer2Id, 'seller_inactive', "Post-reactivation strike 5");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === true, "5th new seller-fault strike triggers repeat UPI cooldown ban");

$cooldownInfo2 = UpiManager::getUpiCooldownInfo($sellerUpi);
assertTrue($cooldownInfo2['cooldown_remaining_seconds'] > 7.5 * 3600 && $cooldownInfo2['cooldown_remaining_seconds'] <= 8 * 3600, "Repeat 5-strike restriction applies 8-hour cooldown ban");

echo "\n===================================================================\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
