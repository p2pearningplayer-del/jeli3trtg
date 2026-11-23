<?php
/**
 * Test Suite: One-Click Global Order Disable & Individual User Permission System (PRD)
 *
 * Verifies:
 * 1. Default configuration: global_order_enabled = 0, can_place_orders = 0
 * 2. Critical formula: CAN_PLACE_ORDER = GLOBAL_ORDER_ENABLED OR INDIVIDUAL_USER_PERMISSION_ENABLED
 * 3. Server-side blocking of new orders with exact PRD rejection message
 * 4. One-click DISABLE ORDERS FOR ALL USERS (with optional/default revoke of all individual permissions)
 * 5. One-click ENABLE ORDERS FOR ALL USERS (restores normal access for all registered users)
 * 6. Granular individual user approval while global control is disabled
 * 7. Live admin statistics & metrics calculation (Global Status, Total Users, Authorized Users, Blocked Users)
 * 8. Zero disruption to existing active orders, settlements, wallet balances, or logins
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/UpiManager.php';

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

// Run schema installer to ensure platform_settings has global_order_enabled and users has can_place_orders
SchemaInstaller::ensureSchema();

$pdo = Database::getConnection();

$totalTests = 0;
$passedTests = 0;

function assertCondition(bool $condition, string $message, string $details = ''): void {
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "====================================================================\n";
echo " JELIPAY - GLOBAL ORDER CONTROL & USER PERMISSION TEST SUITE (PRD)\n";
echo "====================================================================\n\n";

$adminId = 1;

// -------------------------------------------------------------------
// SECTION 1: Database & Default Configuration
// -------------------------------------------------------------------
echo "--- SECTION 1: Database Schema & Default Configuration ---\n";

// Force default state
$pdo->prepare("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'global_order_enabled'")->execute();
$pdo->prepare("UPDATE platform_settings SET setting_value = '0' WHERE setting_key = 'sell_orders_require_approval'")->execute();

$globVal = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'global_order_enabled'")->fetchColumn();
assertCondition($globVal === '0', "platform_settings 'global_order_enabled' defaults to '0' (Disabled)");
assertCondition(Auth::isGlobalOrderEnabled() === false, "Auth::isGlobalOrderEnabled() returns false");

// Register test users
$suffix = time() . '_' . mt_rand(100, 999);
$sellerPhone = '93' . mt_rand(10000000, 99999999);
$buyerPhone = '94' . mt_rand(10000000, 99999999);
$sellerUpi = "seller_g_{$suffix}@okhdfcbank";
$buyerUpi = "buyer_g_{$suffix}@paytm";

$sellerReg = Auth::register($sellerPhone, 'Pass1234!', null, $sellerUpi, 'Test Global Seller', "seller_g{$suffix}@gmail.com", true);
$sellerId = (int)$sellerReg['user_id'];
Auth::setTPIN($sellerId, '1234');

$buyerReg = Auth::register($buyerPhone, 'Pass1234!', null, $buyerUpi, 'Test Global Buyer', "buyer_g{$suffix}@gmail.com", true);
$buyerId = (int)$buyerReg['user_id'];

// Seed balances
Wallet::adminAdjust($sellerId, 'credit', 20000.00, 'Seed seller balance', $adminId);
Wallet::adminAdjust($buyerId, 'credit', 20000.00, 'Seed buyer balance', $adminId);

assertCondition(Auth::hasIndividualOrderPermission($sellerId) === false, "Seller default can_place_orders is 0 (Disabled)");
assertCondition(Auth::hasIndividualOrderPermission($buyerId) === false, "Buyer default can_place_orders is 0 (Disabled)");
assertCondition(Auth::canPlaceOrders($sellerId) === false, "Formula: 0 OR 0 = 0 -> Auth::canPlaceOrders(Seller) returns false");
assertCondition(Auth::canPlaceOrders($buyerId) === false, "Formula: 0 OR 0 = 0 -> Auth::canPlaceOrders(Buyer) returns false");

// -------------------------------------------------------------------
// SECTION 2: Order Blocking When Global is Disabled & Individual is Disabled
// -------------------------------------------------------------------
echo "\n--- SECTION 2: Order Blocking (Global Disabled, Individual Disabled) ---\n";

$sellRes = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', 'Test Global Seller', $sellerUpi, false);
assertCondition($sellRes['success'] === false, "Seller order blocked when Global=0 and Individual=0");
assertCondition($sellRes['error'] === 'Your account is currently not authorized to place orders. Please contact the administrator.',
    "Sell rejection message matches exact PRD verbatim string", $sellRes['error'] ?? '');

$buyRes = OrderEngine::allocateCustomBuy($buyerId, 500.00);
assertCondition($buyRes['success'] === false, "Buyer custom allocation blocked when Global=0 and Individual=0");
assertCondition($buyRes['error'] === 'Your account is currently not authorized to place orders. Please contact the administrator.',
    "Custom buy rejection message matches exact PRD verbatim string", $buyRes['error'] ?? '');

// -------------------------------------------------------------------
// SECTION 3: Grant Individual Permission While Global is Disabled
// -------------------------------------------------------------------
echo "\n--- SECTION 3: Individual Approval (Global Disabled) ---\n";

$grantRes = AdminManager::updateUserOrderPermission($sellerId, 1, $adminId, 'Special seller authorization');
assertCondition($grantRes['success'] === true, "Admin granted individual order permission to Seller");
assertCondition(Auth::isGlobalOrderEnabled() === false, "Global order control is still DISABLED");
assertCondition(Auth::hasIndividualOrderPermission($sellerId) === true, "Seller individual permission is now 1 (Enabled)");
assertCondition(Auth::canPlaceOrders($sellerId) === true, "Formula: 0 OR 1 = 1 -> Seller can now place orders!");

// Seller can now place sell orders
$sellRes2 = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', 'Test Global Seller', $sellerUpi, false);
assertCondition($sellRes2['success'] === true, "Seller successfully listed order after individual permission granted", $sellRes2['error'] ?? '');
$childOrder = $pdo->query("SELECT id FROM child_orders WHERE seller_id = {$sellerId} AND status = 'Available' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$childId = (int)($childOrder['id'] ?? 0);
assertCondition($childId > 0, "Child order portion created in Buy pool (#{$childId})");

// Buyer still has can_place_orders = 0 -> Blocked from picking
$pickBlocked = StateMachine::pickOrder($childId, $buyerId);
assertCondition($pickBlocked['success'] === false, "Buyer still blocked from picking order (Individual=0, Global=0)");
assertCondition($pickBlocked['error'] === 'Your account is currently not authorized to place orders. Please contact the administrator.',
    "Buyer pick rejection message matches exact PRD verbatim string");

// Admin grants individual permission to Buyer
$grantBuyer = AdminManager::updateUserOrderPermission($buyerId, 1, $adminId, 'Special buyer authorization');
assertCondition($grantBuyer['success'] === true, "Admin granted individual order permission to Buyer");
assertCondition(Auth::canPlaceOrders($buyerId) === true, "Formula: 0 OR 1 = 1 -> Buyer can now place orders!");

$pickSuccess = StateMachine::pickOrder($childId, $buyerId);
assertCondition($pickSuccess['success'] === true, "Buyer successfully picked order after individual permission granted", $pickSuccess['error'] ?? '');

// -------------------------------------------------------------------
// SECTION 4: One-Click "DISABLE ORDERS FOR ALL USERS"
// -------------------------------------------------------------------
echo "\n--- SECTION 4: One-Click 'DISABLE ORDERS FOR ALL USERS' ---\n";

// Disable orders for everyone and revoke all individual permissions
$disRes = AdminManager::updateGlobalOrderControl(0, $adminId, true, 'Emergency global shutdown');
assertCondition($disRes['success'] === true, "updateGlobalOrderControl(0, true) succeeded");
assertCondition(Auth::isGlobalOrderEnabled() === false, "Auth::isGlobalOrderEnabled() is false");
assertCondition(Auth::hasIndividualOrderPermission($sellerId) === false, "Seller individual permission revoked to 0");
assertCondition(Auth::hasIndividualOrderPermission($buyerId) === false, "Buyer individual permission revoked to 0");
assertCondition(Auth::canPlaceOrders($sellerId) === false, "Seller canPlaceOrders is false");
assertCondition(Auth::canPlaceOrders($buyerId) === false, "Buyer canPlaceOrders is false");

// Verify audit log
$auditStmt = $pdo->prepare("SELECT action_type, reason FROM admin_audit_logs WHERE action_type = 'DISABLE_GLOBAL_ORDERS_AND_REVOKE_ALL' ORDER BY id DESC LIMIT 1");
$auditStmt->execute();
$auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($auditRow), "Audit trail correctly recorded DISABLE_GLOBAL_ORDERS_AND_REVOKE_ALL");

// Re-attempt sell order: immediately blocked
$sellBlockedAgain = OrderEngine::createSellOrder($sellerId, 500.00, '1234', 'Test Global Seller', $sellerUpi, false);
assertCondition($sellBlockedAgain['success'] === false, "Order placement immediately blocked for Seller after Global Disable All");
assertCondition($sellBlockedAgain['error'] === 'Your account is currently not authorized to place orders. Please contact the administrator.',
    "Rejection message matches PRD verbatim text");

// -------------------------------------------------------------------
// SECTION 5: One-Click "ENABLE ORDERS FOR ALL USERS"
// -------------------------------------------------------------------
echo "\n--- SECTION 5: One-Click 'ENABLE ORDERS FOR ALL USERS' ---\n";

// Enable orders globally for all users
$enRes = AdminManager::updateGlobalOrderControl(1, $adminId, false, 'Restoring normal platform trading');
assertCondition($enRes['success'] === true, "updateGlobalOrderControl(1) succeeded");
assertCondition(Auth::isGlobalOrderEnabled() === true, "Auth::isGlobalOrderEnabled() is true");

// Even with can_place_orders = 0: Formula 1 OR 0 = 1!
assertCondition(Auth::hasIndividualOrderPermission($sellerId) === false, "Seller individual can_place_orders remains 0");
assertCondition(Auth::canPlaceOrders($sellerId) === true, "Formula: 1 OR 0 = 1 -> Seller can place orders under Global Enabled");
assertCondition(Auth::canPlaceOrders($buyerId) === true, "Formula: 1 OR 0 = 1 -> Buyer can place orders under Global Enabled");

// Seller successfully lists order
$sellGlobalRes = OrderEngine::createSellOrder($sellerId, 500.00, '1234', 'Test Global Seller', $sellerUpi, false);
assertCondition($sellGlobalRes['success'] === true, "Seller placed order successfully under Global Enabled", $sellGlobalRes['error'] ?? '');

$globalChild = $pdo->query("SELECT id FROM child_orders WHERE seller_id = {$sellerId} AND status = 'Available' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$globalChildId = (int)($globalChild['id'] ?? 0);
assertCondition($globalChildId > 0, "Available child order created (#{$globalChildId})");

// Buyer successfully picks order
$pickGlobalRes = StateMachine::pickOrder($globalChildId, $buyerId);
assertCondition($pickGlobalRes['success'] === true, "Buyer picked order successfully under Global Enabled", $pickGlobalRes['error'] ?? '');

// Verify audit log
$auditStmt2 = $pdo->prepare("SELECT action_type FROM admin_audit_logs WHERE action_type = 'ENABLE_GLOBAL_ORDERS' ORDER BY id DESC LIMIT 1");
$auditStmt2->execute();
$auditRow2 = $auditStmt2->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($auditRow2), "Audit trail correctly recorded ENABLE_GLOBAL_ORDERS");

// -------------------------------------------------------------------
// SECTION 6: Metrics & Live Dashboard Statistics (PRD Section 5)
// -------------------------------------------------------------------
echo "\n--- SECTION 6: Admin Dashboard Metrics & Statistics ---\n";

// When Global is ENABLED:
$statsGlobalOn = AdminManager::getUserOrderPermissions('', 'all', 10, 0);
assertCondition($statsGlobalOn['success'] === true, "getUserOrderPermissions returns success");
assertCondition($statsGlobalOn['stats']['global_order_enabled'] === true, "stats.global_order_enabled is true");
assertCondition($statsGlobalOn['stats']['authorized_users'] === $statsGlobalOn['stats']['total_users'],
    "When Global=1, authorized_users ({$statsGlobalOn['stats']['authorized_users']}) equals total_users ({$statsGlobalOn['stats']['total_users']})");
assertCondition($statsGlobalOn['stats']['blocked_users'] === 0, "When Global=1, blocked_users is 0");

// Switch Global to DISABLED (without revoking):
AdminManager::updateGlobalOrderControl(0, $adminId, false, 'Testing disabled stats');
$statsGlobalOff = AdminManager::getUserOrderPermissions('', 'all', 10, 0);
assertCondition($statsGlobalOff['stats']['global_order_enabled'] === false, "stats.global_order_enabled is false");
assertCondition($statsGlobalOff['stats']['authorized_users'] === $statsGlobalOff['stats']['individual_enabled_users'],
    "When Global=0, authorized_users equals individual_enabled_users ({$statsGlobalOff['stats']['individual_enabled_users']})");
assertCondition($statsGlobalOff['stats']['blocked_users'] === $statsGlobalOff['stats']['individual_disabled_users'],
    "When Global=0, blocked_users equals individual_disabled_users ({$statsGlobalOff['stats']['individual_disabled_users']})");

// -------------------------------------------------------------------
// SECTION 7: Existing In-Flight Orders Remain Safe (PRD Section 9)
// -------------------------------------------------------------------
echo "\n--- SECTION 7: In-Flight Orders and Wallet Operations Remain Safe ---\n";

// Picked order from Section 3 ($childId) is in 'Reserved' status
$ordCheck = $pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
$ordCheck->execute([$childId]);
$curStatus = $ordCheck->fetchColumn();
assertCondition($curStatus === 'Reserved', "In-flight order #{$childId} remains active in 'Reserved' state");

// Create dummy proof image
$proofDir = __DIR__ . '/../uploads/proofs';
if (!is_dir($proofDir)) @mkdir($proofDir, 0777, true);
$proofPath = $proofDir . '/proof_glob_' . uniqid() . '.png';
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(60, 60);
    $c = imagecolorallocate($im, mt_rand(50, 200), mt_rand(50, 200), mt_rand(50, 200));
    imagefill($im, 0, 0, $c);
    imagestring($im, 2, 5, 5, (string)mt_rand(1000, 9999), imagecolorallocate($im, 255, 255, 255));
    imagepng($im, $proofPath);
    imagedestroy($im);
} else {
    file_put_contents($proofPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==') . random_bytes(256));
}

// Buyer submits proof even while global order placement is disabled
$subProof = StateMachine::submitPaymentProof($childId, $buyerId, 'UTR' . mt_rand(100000000, 999999999), $proofPath, 'Paid');
assertCondition($subProof['success'] === true, "Buyer can submit payment proof for existing order while global orders disabled", $subProof['error'] ?? '');

// Seller confirms payment and releases tokens
$confirmRes = StateMachine::confirmPayment($childId, $sellerId, '1234');
assertCondition($confirmRes['success'] === true, "Seller can confirm payment for existing order while global orders disabled", $confirmRes['error'] ?? '');

// Final order check
$ordCheck->execute([$childId]);
assertCondition($ordCheck->fetchColumn() === 'Completed', "Order #{$childId} settled cleanly to Completed");

// -------------------------------------------------------------------
// SECTION 8: Security & Parameter Validation
// -------------------------------------------------------------------
echo "\n--- SECTION 8: Security, Boundaries & Error Handling ---\n";

$invRes = AdminManager::updateUserOrderPermission(0, 1, $adminId);
assertCondition($invRes['success'] === false, "AdminManager rejects user ID 0");

$nonExRes = AdminManager::updateUserOrderPermission(9999999, 1, $adminId);
assertCondition($nonExRes['success'] === false, "AdminManager rejects non-existent user ID");

// Restore default configuration (Global = 0, per PRD requirement)
AdminManager::updateGlobalOrderControl(0, $adminId, false, 'Default PRD test state restored');
assertCondition(Auth::isGlobalOrderEnabled() === false, "Default PRD configuration confirmed: Global Order Control is DISABLED");

echo "\n====================================================================\n";
echo " RESULT: {$passedTests} / {$totalTests} TESTS PASSED\n";
echo "====================================================================\n";

if ($passedTests === $totalTests) {
    exit(0);
} else {
    exit(1);
}
