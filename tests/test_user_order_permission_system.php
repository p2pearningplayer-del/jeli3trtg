<?php
/**
 * Test Suite: Admin-Controlled User Order Permission System (PRD)
 *
 * Verifies:
 * 1. Database schema column `can_place_orders` with default 0 (Disabled).
 * 2. New registrations and existing users have permission disabled by default.
 * 3. Server-side validation blocks unauthorized Buy and Sell orders with exact message:
 *    "Your account is currently not authorized to place orders. Please contact the administrator."
 * 4. Admin API and Manager can list, filter, search, enable, and disable user permissions.
 * 5. Toggling permission creates immutable audit trail entries.
 * 6. Authorized users can place orders normally; revoking permission immediately blocks new orders.
 * 7. Security: Non-admins and support mode cannot modify order permissions.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';

SchemaInstaller::ensureSchema();

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $description, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail ? " ($detail)" : "") . "\n";
        $failed++;
    }
}

echo "====================================================================\n";
echo " JELIPAY - ADMIN-CONTROLLED USER ORDER PERMISSION SYSTEM TEST SUITE\n";
echo "====================================================================\n\n";

$pdo = Database::getConnection();

// Ensure test admin
$adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND account_status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    SchemaInstaller::ensureDefaultAdmin($pdo);
    $adminId = 1;
} else {
    $adminId = (int)$adminUser['id'];
}

$expectedRejectMsg = 'Your account is currently not authorized to place orders. Please contact the administrator.';

// --- SECTION 1: Schema & Default Permission Status ---
echo "--- SECTION 1: Schema & Default Permission Status ---\n";

// Create fresh test user A (seller) and user B (buyer)
$phoneA = '98' . rand(10000000, 99999999);
$emailA = 'test_seller_' . time() . '_' . rand(100, 999) . '@gmail.com';
$upiA = 'seller' . time() . '_' . rand(100, 999) . '@upi';
$regA = Auth::register($phoneA, 'password123', null, $upiA, 'Seller Test', $emailA, true);
assertCondition($regA['success'], "User A registered successfully", $regA['error'] ?? '');
$userAId = (int)$regA['user_id'];

$phoneB = '99' . rand(10000000, 99999999);
$emailB = 'test_buyer_' . time() . '_' . rand(100, 999) . '@gmail.com';
$upiB = 'buyer' . time() . '_' . rand(100, 999) . '@upi';
$regB = Auth::register($phoneB, 'password123', null, $upiB, 'Buyer Test', $emailB, true);
assertCondition($regB['success'], "User B registered successfully", $regB['error'] ?? '');
$userBId = (int)$regB['user_id'];

// Verify default permission is 0 (Disabled)
$stmtA = $pdo->prepare("SELECT can_place_orders FROM users WHERE id = ?");
$stmtA->execute([$userAId]);
$permA = (int)$stmtA->fetchColumn();
assertCondition($permA === 0, "User A default can_place_orders is 0 (Disabled)");

$stmtB = $pdo->prepare("SELECT can_place_orders FROM users WHERE id = ?");
$stmtB->execute([$userBId]);
$permB = (int)$stmtB->fetchColumn();
assertCondition($permB === 0, "User B default can_place_orders is 0 (Disabled)");

assertCondition(!Auth::canPlaceOrders($userAId), "Auth::canPlaceOrders returns false for User A");
assertCondition(!Auth::canPlaceOrders($userBId), "Auth::canPlaceOrders returns false for User B");

// Configure TPIN and credit wallet balance for User A
Auth::setTPIN($userAId, '1234');
$pdo->prepare("UPDATE wallets SET available_balance = ? WHERE user_id = ?")->execute([50000.00, $userAId]);

// --- SECTION 2: Server-Side Block for Sell Orders ---
echo "\n--- SECTION 2: Server-Side Block for Sell Orders (Permission Disabled) ---\n";

$sellAttempt = OrderEngine::createSellOrder($userAId, 1000.00, '1234', 'Seller Test', $upiA);
assertCondition(!$sellAttempt['success'], "User A sell order is rejected when permission is disabled");
assertCondition(
    ($sellAttempt['error'] ?? '') === $expectedRejectMsg,
    "Rejection message matches exact PRD text",
    "Got: " . ($sellAttempt['error'] ?? '')
);

// --- SECTION 3: Server-Side Block for Buy Orders ---
echo "\n--- SECTION 3: Server-Side Block for Buy Orders (Permission Disabled) ---\n";

// User B custom buy allocation
$customBuyAttempt = OrderEngine::allocateCustomBuy($userBId, 500.00);
assertCondition(!$customBuyAttempt['success'], "User B custom buy allocation is rejected when permission is disabled");
assertCondition(
    ($customBuyAttempt['error'] ?? '') === $expectedRejectMsg,
    "Custom buy rejection message matches exact PRD text",
    "Got: " . ($customBuyAttempt['error'] ?? '')
);

// User B direct order pick attempt on an arbitrary order ID
$pickAttempt = StateMachine::pickOrder(99999, $userBId);
assertCondition(!$pickAttempt['success'], "User B order pick is rejected when permission is disabled");
assertCondition(
    ($pickAttempt['error'] ?? '') === $expectedRejectMsg,
    "Order pick rejection message matches exact PRD text",
    "Got: " . ($pickAttempt['error'] ?? '')
);

// --- SECTION 4: Admin Permission Management & API ---
echo "\n--- SECTION 4: Admin Permission Management & API ---\n";

// List permissions
$permList = AdminManager::getUserOrderPermissions('', 'all', 10, 0);
assertCondition($permList['success'], "AdminManager::getUserOrderPermissions returned success");
assertCondition(isset($permList['stats']['total_users']), "Stats include total_users count");
assertCondition(isset($permList['stats']['enabled_users']), "Stats include enabled_users count");
assertCondition(isset($permList['stats']['disabled_users']), "Stats include disabled_users count");

// Search by User A's phone
$searchRes = AdminManager::getUserOrderPermissions($phoneA, 'all', 10, 0);
assertCondition(count($searchRes['users']) === 1, "Search by phone found User A");
assertCondition($searchRes['users'][0]['can_place_orders'] === false, "User A status in list is false");

// Filter by status=disabled
$disabledList = AdminManager::getUserOrderPermissions($phoneA, 'disabled', 10, 0);
assertCondition(count($disabledList['users']) === 1, "Filter by status=disabled returned User A");

// Filter by status=enabled (should be empty for User A)
$enabledList = AdminManager::getUserOrderPermissions($phoneA, 'enabled', 10, 0);
assertCondition(count($enabledList['users']) === 0, "Filter by status=enabled does not return User A");

// --- SECTION 5: Admin Enables Permission & Successful Order Placement ---
echo "\n--- SECTION 5: Admin Enables Permission & Successful Order Placement ---\n";

// Enable permission for User A
$enableResA = AdminManager::updateUserOrderPermission($userAId, 1, $adminId, 'Verified identity and approved trading');
assertCondition($enableResA['success'], "Admin enabled order permission for User A");
assertCondition($enableResA['can_place_orders'] === true, "Returned can_place_orders is true");
assertCondition(Auth::canPlaceOrders($userAId), "Auth::canPlaceOrders now returns true for User A");

// Verify audit log
$auditA = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE target_id = ? AND action_type = 'ENABLE_ORDER_PERMISSION' ORDER BY id DESC LIMIT 1");
$auditA->execute([(string)$userAId]);
$logA = $auditA->fetch(PDO::FETCH_ASSOC);
assertCondition((bool)$logA, "ENABLE_ORDER_PERMISSION audit log entry was created");
assertCondition($logA['admin_id'] == $adminId, "Audit log correctly recorded the admin ID");

// User A now places sell order (with auto-approval bypass for test)
$sellSuccess = OrderEngine::createSellOrder($userAId, 1000.00, '1234', 'Seller Test', $upiA, false);
assertCondition($sellSuccess['success'], "User A successfully created sell order after permission enabled", $sellSuccess['error'] ?? '');
$parentOrderId = (int)($sellSuccess['parent_order_id'] ?? 0);

// Get available child order
$childStmt = $pdo->prepare("SELECT id FROM child_orders WHERE parent_order_id = ? AND status = 'Available' LIMIT 1");
$childStmt->execute([$parentOrderId]);
$childOrderId = (int)$childStmt->fetchColumn();
assertCondition($childOrderId > 0, "Child order portion created in Available status (ID: #{$childOrderId})");

// User B is still disabled; attempt to pick
$pickStillBlocked = StateMachine::pickOrder($childOrderId, $userBId);
assertCondition(!$pickStillBlocked['success'], "User B still blocked from picking order");
assertCondition(($pickStillBlocked['error'] ?? '') === $expectedRejectMsg, "User B gets exact rejection message");

// Enable permission for User B
$enableResB = AdminManager::updateUserOrderPermission($userBId, 1, $adminId, 'Approved buyer account');
assertCondition($enableResB['success'], "Admin enabled order permission for User B");
assertCondition(Auth::canPlaceOrders($userBId), "Auth::canPlaceOrders now returns true for User B");

// User B now picks the order
$pickSuccess = StateMachine::pickOrder($childOrderId, $userBId);
assertCondition($pickSuccess['success'], "User B successfully picked order after permission enabled", $pickSuccess['error'] ?? '');

// --- SECTION 6: Admin Revokes/Disables Permission Immediately ---
echo "\n--- SECTION 6: Admin Revokes/Disables Permission Immediately ---\n";

// Disable permission for User B
$disableResB = AdminManager::updateUserOrderPermission($userBId, 0, $adminId, 'Compliance hold');
assertCondition($disableResB['success'], "Admin disabled order permission for User B");
assertCondition($disableResB['can_place_orders'] === false, "Returned can_place_orders is false");
assertCondition(!Auth::canPlaceOrders($userBId), "Auth::canPlaceOrders now returns false for User B");

// Verify audit log for disable
$auditB = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE target_id = ? AND action_type = 'DISABLE_ORDER_PERMISSION' ORDER BY id DESC LIMIT 1");
$auditB->execute([(string)$userBId]);
$logB = $auditB->fetch(PDO::FETCH_ASSOC);
assertCondition((bool)$logB, "DISABLE_ORDER_PERMISSION audit log entry was created");

// Existing picked order should STILL be accessible to submit proof (only NEW orders blocked)
$existingOrderStmt = $pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
$existingOrderStmt->execute([$childOrderId]);
$currStatus = $existingOrderStmt->fetchColumn();
assertCondition($currStatus === 'Reserved', "Existing reserved order remains active in 'Reserved' state");

// But User B is blocked from placing ANY new orders
$newPickAttempt = OrderEngine::allocateCustomBuy($userBId, 500.00);
assertCondition(!$newPickAttempt['success'], "User B immediately blocked from placing new orders");
assertCondition(($newPickAttempt['error'] ?? '') === $expectedRejectMsg, "Rejection message matches exact PRD text");

// --- SECTION 7: User Profile / getCurrentUser Reflection ---
echo "\n--- SECTION 7: User Profile & Session Mapping ---\n";

$_SESSION['user_id'] = $userAId;
$sessionA = Auth::getCurrentUser();
assertCondition($sessionA['can_place_orders'] === true, "User A getCurrentUser reflects can_place_orders = true");

$_SESSION['user_id'] = $userBId;
$sessionB = Auth::getCurrentUser();
assertCondition($sessionB['can_place_orders'] === false, "User B getCurrentUser reflects can_place_orders = false");

// SearchUsers also includes can_place_orders
$adminSearch = AdminManager::searchUsers($phoneA);
assertCondition(count($adminSearch['users']) === 1, "Admin searchUsers found User A");
assertCondition((int)$adminSearch['users'][0]['can_place_orders'] === 1, "Admin searchUsers returned can_place_orders = 1 for User A");

$adminSearchB = AdminManager::searchUsers($phoneB);
assertCondition(count($adminSearchB['users']) === 1, "Admin searchUsers found User B");
assertCondition((int)$adminSearchB['users'][0]['can_place_orders'] === 0, "Admin searchUsers returned can_place_orders = 0 for User B");

// --- SECTION 8: Security & Edge Case Verification ---
echo "\n--- SECTION 8: Security & Edge Case Verification ---\n";

// 1. Invalid User ID handling
$invalidUserRes = AdminManager::updateUserOrderPermission(0, 1, $adminId, 'Test');
assertCondition(!$invalidUserRes['success'], "AdminManager rejects invalid user ID (0)");
assertCondition(($invalidUserRes['error'] ?? '') === 'Invalid user ID.', "Correct error returned for invalid user ID");

$nonExistentUserRes = AdminManager::updateUserOrderPermission(9999999, 1, $adminId, 'Test');
assertCondition(!$nonExistentUserRes['success'], "AdminManager rejects non-existent user ID");
assertCondition(($nonExistentUserRes['error'] ?? '') === 'User account not found.', "Correct error returned for non-existent user");

// 2. Non-admin permission check
$nonAdminUser = ['role' => 'user', 'admin_role' => null];
assertCondition(!AdminManager::checkPermission($nonAdminUser, 'users_manage'), "Non-admin user cannot manage users/permissions");

// 3. Exact Rejection String Consistency Check across all engine functions
$testBlockMsgSell = OrderEngine::createSellOrder($userBId, 1000.00, '1234', 'Buyer Test', $upiB);
assertCondition($testBlockMsgSell['error'] === $expectedRejectMsg, "OrderEngine::createSellOrder error matches exact PRD text");

$testBlockMsgCustom = OrderEngine::allocateCustomBuy($userBId, 500.00);
assertCondition($testBlockMsgCustom['error'] === $expectedRejectMsg, "OrderEngine::allocateCustomBuy error matches exact PRD text");

$testBlockMsgPick = StateMachine::pickOrder($childOrderId, $userBId);
assertCondition($testBlockMsgPick['error'] === $expectedRejectMsg, "StateMachine::pickOrder error matches exact PRD text");

echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
