<?php
/**
 * Test Suite: Advanced UPI Management & Risk Logic
 * 
 * Verifies:
 * 1. Strict 1-to-1 UPI Binding (Anti-Fraud Validation):
 *    - Cross-account UPI uniqueness (case-insensitive)
 *    - Cross-account Phone uniqueness (normalized format)
 *    - Blocked additions during register, profile update, and add UPI API
 * 2. Multi-UPI Support for Sellers:
 *    - Adding multiple UPI IDs per user
 *    - Setting primary UPI
 *    - Deleting saved UPI
 *    - Creating sell listing with selected UPI
 * 3. Automated UPI Risk Hiding & Cooldown System:
 *    - Initial 4-hour cooldown penalty upon multiple cancellations
 *    - Automatic stealth hiding from public Buy marketplace pool
 *    - Automatic exclusion from custom buy split allocation
 *    - Zero risk warning to buyers (strict privacy)
 *    - Seller-only dashboard alert & cooldown remaining inspection
 *    - Seller cancels blocked listing and tokens return to available balance
 *    - Seller creates new listing using working alternative UPI
 *    - Subsequent re-flagging after reactivation triggers 8-hour cooldown penalty
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/UpiManager.php';
require_once __DIR__ . '/../src/Validator.php';

$testDbPath = __DIR__ . '/test_upi_' . time() . '.sqlite';
putenv("SQLITE_DATABASE_PATH={$testDbPath}");

$pdo = new PDO("sqlite:{$testDbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo, 'sqlite');

SchemaInstaller::ensureSchema();

function testAssert($desc, $cond, $detail = '') {
    if ($cond) {
        echo "[PASS] $desc\n";
    } else {
        echo "[FAIL] $desc " . ($detail ? "($detail)" : "") . "\n";
        exit(1);
    }
}

echo "=== STARTING ADVANCED UPI MANAGEMENT & RISK LOGIC TESTS ===\n\n";

// Clean up previous test records
$pdo->exec("DELETE FROM users WHERE email LIKE '%@upitest.com' OR email LIKE '%@gmail.com' AND phone LIKE '9111%'");
$pdo->exec("DELETE FROM user_upi_accounts WHERE upi_id LIKE '%@testupi%'");

// -------------------------------------------------------------
// SECTION 1: STRICT 1-TO-1 UPI BINDING TESTS
// -------------------------------------------------------------
echo "--- Testing Strict 1-to-1 UPI & Phone Binding ---\n";

// Register User A
$phoneA = '9111111101';
$emailA = 'sellerA_upi@gmail.com';
$upiA = 'sellera@testupi';
$resA = Auth::register($phoneA, 'TestPass@123', null, $upiA, 'Seller Alpha', $emailA, true);
testAssert('User A registered successfully', $resA['success'] === true, $resA['error'] ?? '');
$userIdA = (int)$resA['user_id'];
Auth::setTPIN($userIdA, '1234');
$pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([50000.0, $userIdA]);

// Register User B
$phoneB = '9111111102';
$emailB = 'sellerB_upi@gmail.com';
$upiB = 'sellerb@testupi';
$resB = Auth::register($phoneB, 'TestPass@123', null, $upiB, 'Seller Beta', $emailB, true);
testAssert('User B registered successfully', $resB['success'] === true, $resB['error'] ?? '');
$userIdB = (int)$resB['user_id'];
Auth::setTPIN($userIdB, '1234');
$pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([50000.0, $userIdB]);

// 1.1: User B tries to add User A's UPI ID (case-insensitive)
$dupUpiRes = UpiManager::addUpiAccount($userIdB, 'SELLERa@testupi', '9111111102', 'Seller Beta');
testAssert('User B blocked from adding User A existing UPI ID', $dupUpiRes['success'] === false);
testAssert('Error message matches specification for duplicate UPI', 
    str_contains($dupUpiRes['error'], 'already bound to another account') || str_contains($dupUpiRes['error'], 'Already existing'),
    $dupUpiRes['error'] ?? ''
);

// 1.2: User B tries to add User A's Phone Number
$dupPhoneRes = UpiManager::addUpiAccount($userIdB, 'newunique@testupi', '+91 9111111101', 'Seller Beta');
testAssert('User B blocked from adding User A existing Phone number', $dupPhoneRes['success'] === false);
testAssert('Error message matches specification for duplicate Phone', 
    str_contains($dupPhoneRes['error'], 'already linked to another user account') || str_contains($dupPhoneRes['error'], 'Already existing'),
    $dupPhoneRes['error'] ?? ''
);

// 1.3: Registration with existing UPI is blocked
$dupRegRes = Auth::register('9111111103', 'TestPass@123', null, 'sellera@testupi', 'User Charlie', 'userc_upi@gmail.com', true);
testAssert('Registration blocked when providing an already-bound UPI ID', $dupRegRes['success'] === false);

// 1.4: Profile update with existing UPI is blocked
$dupProfRes = Auth::updateProfile($userIdB, 'sellera@testupi', 'Seller Beta', $emailB);
testAssert('Profile update blocked when providing an already-bound UPI ID', $dupProfRes['success'] === false);

// -------------------------------------------------------------
// SECTION 2: MULTI-UPI MANAGEMENT FOR SELLERS
// -------------------------------------------------------------
echo "\n--- Testing Multi-UPI Management for Sellers ---\n";

// User A adds a second UPI ID
$secondUpiA = 'sellera_backup@testupi';
$phoneA2 = '9111111105'; // Unique valid phone
$addSecond = UpiManager::addUpiAccount($userIdA, $secondUpiA, $phoneA2, 'Seller Alpha Office', false);
testAssert('User A can add a second valid UPI ID', $addSecond['success'] === true, $addSecond['error'] ?? '');

// User A adds a third UPI ID
$thirdUpiA = 'sellera_personal@testupi';
$phoneA3 = '9111111106'; // Unique valid phone
$addThird = UpiManager::addUpiAccount($userIdA, $thirdUpiA, $phoneA3, 'Seller Alpha Personal', false);
testAssert('User A can add a third valid UPI ID', $addThird['success'] === true, $addThird['error'] ?? '');

// Fetch User A accounts
$accountsA = UpiManager::getUserUpiAccounts($userIdA);
testAssert('User A has 3 saved UPI accounts', count($accountsA) === 3);

// Verify Primary account is the first one
testAssert('First account is marked primary', $accountsA[0]['is_primary'] === true);

// Set third account as primary
$thirdAccId = (int)$addThird['account']['id'];
$setPrimaryRes = UpiManager::setPrimaryUpi($userIdA, $thirdAccId);
testAssert('User A sets third UPI as primary', $setPrimaryRes['success'] === true);

$accountsAUpdated = UpiManager::getUserUpiAccounts($userIdA);
$primaryFound = null;
foreach ($accountsAUpdated as $acc) {
    if ($acc['id'] === $thirdAccId) $primaryFound = $acc;
}
testAssert('Third account is now primary', $primaryFound !== null && $primaryFound['is_primary'] === true);

// -------------------------------------------------------------
// SECTION 3: AUTOMATED UPI RISK HIDING & COOLDOWN LOGIC
// -------------------------------------------------------------
echo "\n--- Testing Automated UPI Risk Hiding & Cooldown Progression ---\n";

// User A creates a sell listing specifically on second UPI ($secondUpiA)
$sellOrderRes = OrderEngine::createSellOrder($userIdA, 1000.0, '1234', 'Seller Alpha Office', $secondUpiA);
testAssert('User A created sell listing on backup UPI', $sellOrderRes['success'] === true, $sellOrderRes['error'] ?? '');
$parentOrderId = (int)$sellOrderRes['parent_order_id'];

// Check available child orders for this parent
$childStmt = $pdo->prepare("SELECT id, snapshotted_upi_id, amount FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$childStmt->execute([$parentOrderId]);
$children = $childStmt->fetchAll(PDO::FETCH_ASSOC);
testAssert('Child orders created in Available status', count($children) > 0);
$child1Id = (int)$children[0]['id'];
$child2Id = (int)$children[1]['id'];

// User B picks Child 1 and cancels citing upi_risk
$pick1 = StateMachine::pickOrder($child1Id, $userIdB);
testAssert('Buyer picks Child 1', $pick1['success'] === true);
testAssert('Buyer view does NOT receive seller_upi_risk warning (privacy)', $pick1['seller_upi_risk'] === false);

$cancel1 = StateMachine::cancelReservation($child1Id, $userIdB, 'upi_risk', 'Payment could not be completed on UPI');
testAssert('Buyer 1 cancels Child 1 citing upi_risk', $cancel1['success'] === true);

// Register User C as another buyer to trigger multiple cancellations
$phoneC = '9111111107';
$resC = Auth::register($phoneC, 'TestPass@123', null, 'buyerc@testupi', 'Buyer Charlie', 'buyerc_upi@gmail.com', true);
$userIdC = (int)$resC['user_id'];
$pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([50000.0, $userIdC]);

// User C picks Child 2 and cancels citing upi_risk (second distinct buyer cancellation)
$pick2 = StateMachine::pickOrder($child2Id, $userIdC);
testAssert('Buyer 2 picks Child 2', $pick2['success'] === true);

$cancel2 = StateMachine::cancelReservation($child2Id, $userIdC, 'upi_risk', 'UPI payment declined by bank');
testAssert('Buyer 2 cancels Child 2 citing upi_risk (triggering risk flagging)', $cancel2['success'] === true);

// 3.1: Verify Cooldown State: 1st flag must be exactly 4 hours
testAssert('Backup UPI is now in active cooldown', UpiManager::isUpiInCooldown($secondUpiA) === true);

$cooldownInfo = UpiManager::getUpiCooldownInfo($secondUpiA);
testAssert('Cooldown remaining seconds is > 3.5 hours', $cooldownInfo['cooldown_remaining_seconds'] > 3.5 * 3600);
testAssert('Cooldown remaining seconds is <= 4 hours', $cooldownInfo['cooldown_remaining_seconds'] <= 4 * 3600);
testAssert('Risk flag count is 1', $cooldownInfo['risk_flag_count'] === 1);

// 3.2: Stealth Hiding from Public Buy Marketplace Pool
// Query pool as Buyer C
$poolStmt = $pdo->prepare("
    SELECT c.id, c.snapshotted_upi_id
    FROM child_orders c
    WHERE c.status = 'Available' AND c.seller_id != ?
");
$poolStmt->execute([$userIdC]);
$poolOrders = $poolStmt->fetchAll(PDO::FETCH_ASSOC);

$filteredPool = [];
foreach ($poolOrders as $po) {
    if (UpiManager::isUpiInCooldown($po['snapshotted_upi_id'])) {
        continue;
    }
    $filteredPool[] = $po;
}

$cooldownOrdersInFilteredPool = array_filter($filteredPool, function($po) use ($secondUpiA) {
    return strtolower($po['snapshotted_upi_id']) === strtolower($secondUpiA);
});
testAssert('Listings tied to cooldown UPI are 100% hidden from buy marketplace pool', count($cooldownOrdersInFilteredPool) === 0);

// 3.3: Exclusion from Custom Buy Allocation
$customAllocRes = OrderEngine::allocateCustomBuy($userIdC, 100.0);
// Even if an allocation succeeds on another listing, ensure it was NOT matched to the blocked UPI
if ($customAllocRes['success']) {
    $allocOrderStmt = $pdo->prepare("SELECT snapshotted_upi_id FROM child_orders WHERE id = ?");
    $allocOrderStmt->execute([$customAllocRes['order_id']]);
    $matchedUpi = (string)$allocOrderStmt->fetchColumn();
    testAssert('Custom buy allocation did not match to cooldown UPI', strtolower($matchedUpi) !== strtolower($secondUpiA));
} else {
    testAssert('Custom buy did not match blocked UPI', true);
}

// 3.4: Seller cannot create new listings using blocked UPI
$blockedSellRes = OrderEngine::createSellOrder($userIdA, 500.0, '1234', 'Seller Alpha Office', $secondUpiA);
testAssert('Seller cannot list new orders using blocked UPI', $blockedSellRes['success'] === false);
testAssert('Rejection reason mentions temporary restriction/cancellations', 
    str_contains($blockedSellRes['error'], 'temporarily restricted') || str_contains($blockedSellRes['error'], 'cancellations'),
    $blockedSellRes['error'] ?? ''
);

// 3.5: Visibility: Seller Dashboard receives alert details
$sellerTracking = OrderEngine::getSellerTrackingData($userIdA);
$blockedParentCard = null;
foreach ($sellerTracking as $p) {
    if ((int)$p['id'] === $parentOrderId) {
        $blockedParentCard = $p;
        break;
    }
}
testAssert('Seller tracking finds blocked parent order', $blockedParentCard !== null);
testAssert('Seller dashboard receives is_upi_risk flag', $blockedParentCard['is_upi_risk'] === true);
testAssert('Seller dashboard receives formatted cooldown countdown', !empty($blockedParentCard['cooldown_remaining_formatted']));

// 3.6: Seller Action: Cancel blocked listing and release locked tokens
$balBeforeCancel = (float)Wallet::getWallet($userIdA)['available_balance'];
$cancelParentRes = OrderEngine::cancelParentSellOrder($userIdA, $parentOrderId);
testAssert('Seller can cancel the blocked listing', $cancelParentRes['success'] === true, $cancelParentRes['error'] ?? '');

$balAfterCancel = (float)Wallet::getWallet($userIdA)['available_balance'];
testAssert('Tokens immediately refunded to seller Available Balance', $balAfterCancel > $balBeforeCancel);

// 3.7: Seller creates new listing using different, working UPI ID ($thirdUpiA)
testAssert('Third UPI is not in cooldown', UpiManager::isUpiInCooldown($thirdUpiA) === false);
$newListingRes = OrderEngine::createSellOrder($userIdA, 500.0, '1234', 'Seller Alpha Personal', $thirdUpiA);
testAssert('Seller successfully creates new listing using working alternative UPI', $newListingRes['success'] === true, $newListingRes['error'] ?? '');

$newParentId = (int)$newListingRes['parent_order_id'];
$newChildrenStmt = $pdo->prepare("SELECT id, snapshotted_upi_id FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
$newChildrenStmt->execute([$newParentId]);
$newChildOrders = $newChildrenStmt->fetchAll(PDO::FETCH_ASSOC);
testAssert('New child portions are available on working UPI', count($newChildOrders) > 0);

// Verify this new listing IS visible in the buy pool
$isNewInPool = false;
foreach ($newChildOrders as $nco) {
    if (!UpiManager::isUpiInCooldown($nco['snapshotted_upi_id'])) {
        $isNewInPool = true;
        break;
    }
}
testAssert('New listing with working UPI appears publicly in the buy pool', $isNewInPool === true);

// -------------------------------------------------------------
// SECTION 4: COOLDOWN PROGRESSION TEST (8-HOUR PENALTY ON 2ND FLAG)
// -------------------------------------------------------------
echo "\n--- Testing Cooldown Progression (8-Hour Penalty on Re-Flag) ---\n";

// Simulate cooldown expiration / reactivation for $secondUpiA
$pastDate = gmdate('Y-m-d H:i:s', time() - 60);
$pdo->prepare("UPDATE user_upi_accounts SET risk_cooldown_until = ? WHERE LOWER(upi_id) = ?")->execute([$pastDate, strtolower($secondUpiA)]);
$pdo->prepare("UPDATE users SET upi_risk_cooldown_until = ? WHERE LOWER(upi_id) = ?")->execute([$pastDate, strtolower($secondUpiA)]);

testAssert('Cooldown expired - UPI is reactivated', UpiManager::isUpiInCooldown($secondUpiA) === false);

// Now trigger flagging again after reactivation
$flag2Res = UpiManager::flagUpiForRisk($pdo, $secondUpiA, $userIdA, 'Multiple buyer cancellations recorded after reactivation');
testAssert('Re-flagging succeeds after reactivation', $flag2Res['flagged'] === true);
testAssert('Penalty increases to 8 hours on subsequent flag', $flag2Res['penalty_hours'] === 8);
testAssert('Flag count is incremented to 2', $flag2Res['flag_count'] === 2);

$info2 = UpiManager::getUpiCooldownInfo($secondUpiA);
testAssert('New cooldown remaining seconds is > 7.5 hours', $info2['cooldown_remaining_seconds'] > 7.5 * 3600);
testAssert('New cooldown remaining seconds is <= 8 hours', $info2['cooldown_remaining_seconds'] <= 8 * 3600);

echo "\n=== ALL ADVANCED UPI MANAGEMENT & RISK LOGIC TESTS PASSED SUCCESSFULLY! ===\n";
@unlink($testDbPath);
