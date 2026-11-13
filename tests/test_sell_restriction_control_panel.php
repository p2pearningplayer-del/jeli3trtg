<?php
/**
 * Verification Suite: Admin "Sell Restriction Control" Panel
 * Tests:
 * 1. Listing restricted sellers & UPI IDs (4-hour ban & 8-hour ban)
 * 2. Searching by Phone Number and User ID
 * 3. Removing restriction ("Remove Restriction" / Make Live) clears risk flag,
 *    bypasses cooldown timer, and immediately restores active sell orders to the public Buy section.
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
require_once __DIR__ . '/../src/AdminManager.php';

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

echo "=== Test Suite: Admin 'Sell Restriction Control' Panel ===\n\n";

$suffix = substr((string)time(), -5) . rand(10, 99);
$adminPhone = '940' . $suffix;
$sellerPhone = '950' . $suffix;
$buyerPhone = '960' . $suffix;
$sellerUpi = "restricted_{$suffix}@upi";

$hash = password_hash('Test@12345', PASSWORD_BCRYPT);
$tpin = password_hash('1234', PASSWORD_BCRYPT);

$insUser = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, tpin_hash, account_status, role, admin_role, referral_code, upi_id, account_holder_name)
    VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
");

$insUser->execute([$adminPhone, "adm_sr_{$suffix}@gmail.com", $hash, $tpin, 'admin', 'super_admin', "ADMSR{$suffix}", "admsr{$suffix}@upi", "Restriction Admin"]);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$adminId]);

$insUser->execute([$sellerPhone, "sel_sr_{$suffix}@gmail.com", $hash, $tpin, 'user', null, "SELSR{$suffix}", $sellerUpi, "Restricted Seller"]);
$sellerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 5000, 0, 0, 1)")->execute([$sellerId]);

$insUser->execute([$buyerPhone, "buy_sr_{$suffix}@gmail.com", $hash, $tpin, 'user', null, "BUYSR{$suffix}", "buysr{$suffix}@upi", "Marketplace Buyer"]);
$buyerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$buyerId]);

// Add UPI account for seller
UpiManager::getUserUpiAccounts($sellerId);

// 1. Seller lists a ₹500 sell order (creates ₹300 and ₹200 child orders)
echo "--- 1. Automated UPI Risk Flagging & Hiding from Buy Section ---\n";
$sellRes = OrderEngine::createSellOrder($sellerId, 500.00, '1234', 'Restricted Seller', $sellerUpi, false);
assertTrue(!empty($sellRes['success']), "Seller created ₹500 sell listing with UPI {$sellerUpi}");

// Trigger 1st automated UPI risk flag (4-hour ban)
$flag1 = UpiManager::flagUpiForRisk($pdo, $sellerUpi, $sellerId, 'Multiple buyer cancellations reported on UPI');
assertTrue(!empty($flag1['flagged']) && (int)($flag1['penalty_hours'] ?? 0) === 4, "1st offense triggers 4-hour UPI cooldown ban");
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === true, "UpiManager::isUpiInCooldown({$sellerUpi}) is TRUE during ban");

// Verify active child orders for this UPI are hidden from public Buy pool
$hiddenCheckStmt = $pdo->prepare("
    SELECT id, snapshotted_upi_id FROM child_orders
    WHERE seller_id = ? AND status = 'Available'
");
$hiddenCheckStmt->execute([$sellerId]);
$sellerAvailableOrders = $hiddenCheckStmt->fetchAll(PDO::FETCH_ASSOC);
$visibleInBuyPool = 0;
foreach ($sellerAvailableOrders as $ord) {
    if (!UpiManager::isUpiInCooldown($ord['snapshotted_upi_id'])) {
        $visibleInBuyPool++;
    }
}
assertTrue(count($sellerAvailableOrders) === 2 && $visibleInBuyPool === 0, "Seller's 2 active sell orders (₹500) are hidden from public Buy section while UPI is restricted");

// 2. Admin Sell Restriction Control Panel Search & Display
echo "\n--- 2. Admin 'Sell Restriction Control' Panel Search & Data Display ---\n";

// Search by Phone Number
$byPhone = AdminManager::getSellRestrictions($sellerPhone, 'active');
assertTrue($byPhone['total'] === 1, "Admin search by Phone Number ({$sellerPhone}) finds the restricted seller");
$item = $byPhone['restrictions'][0] ?? [];
assertTrue((int)($item['user_id'] ?? 0) === $sellerId, "Displays correct User ID (#{$sellerId})");
assertTrue(($item['user_phone'] ?? '') === $sellerPhone, "Displays correct Phone Number ({$sellerPhone})");
assertTrue(($item['upi_id'] ?? '') === $sellerUpi, "Displays specific restricted UPI ID ({$sellerUpi})");
assertTrue(($item['ban_badge'] ?? '') === '4-Hour Ban' && (int)($item['penalty_hours'] ?? 0) === 4, "Displays restriction status: '4-Hour Ban'");
assertTrue((int)($item['hidden_orders_count'] ?? 0) === 2 && (float)($item['hidden_orders_amount'] ?? 0) === 500.00, "Displays 2 hidden active sell orders totaling ₹500.00");

// Search by User ID
$byUserId = AdminManager::getSellRestrictions((string)$sellerId, 'active');
assertTrue($byUserId['total'] === 1 && (int)($byUserId['restrictions'][0]['user_id'] ?? 0) === $sellerId, "Admin search by User ID ({$sellerId}) finds the restricted seller");

// 3. Test 8-Hour Ban Display when risk_flag_count >= 2
echo "\n--- 3. Verify 8-Hour Ban Status Display ---\n";
$pdo->prepare("UPDATE user_upi_accounts SET risk_flag_count = 2 WHERE LOWER(upi_id) = ?")->execute([$sellerUpi]);
$by8h = AdminManager::getSellRestrictions($sellerPhone, '8h');
assertTrue($by8h['total'] === 1 && ($by8h['restrictions'][0]['ban_badge'] ?? '') === '8-Hour Ban', "Displays '8-Hour Ban' status when UPI is on repeat offense cooldown");

// 4. Admin Action: "Remove Restriction" (Make Live / Revive)
echo "\n--- 4. Admin Action: 'Remove Restriction' (Make Live / Revive) ---\n";
$reviveRes = AdminManager::removeUpiSellRestriction($adminId, $sellerUpi, $sellerId, true, 'Verified UPI working - Admin manual Make Live');
assertTrue(!empty($reviveRes['success']), "AdminManager::removeUpiSellRestriction succeeded");
assertTrue((int)($reviveRes['live_orders_count'] ?? 0) === 2 && (float)($reviveRes['live_orders_amount'] ?? 0) === 500.00, "Response confirms 2 active sell orders (₹500.00) are now LIVE");

// Verify cooldown & risk flag are cleared immediately
assertTrue(UpiManager::isUpiInCooldown($sellerUpi) === false, "UpiManager::isUpiInCooldown({$sellerUpi}) is immediately FALSE (cooldown bypassed)");

// Verify active sell orders are immediately visible in public Buy section again
$visibleAfterRevive = 0;
foreach ($sellerAvailableOrders as $ord) {
    if (!UpiManager::isUpiInCooldown($ord['snapshotted_upi_id'])) {
        $visibleAfterRevive++;
    }
}
assertTrue($visibleAfterRevive === 2, "All 2 active sell orders are immediately visible in public Buy section again");

// Verify seller no longer appears in 'active' restricted list
$afterList = AdminManager::getSellRestrictions($sellerPhone, 'active');
assertTrue($afterList['total'] === 0, "Seller is removed from active restricted list after Make Live");

// 5. Verify UI & JS wiring in admin.php and public/js/admin.js
echo "\n--- 5. UI & Frontend Wiring Verification ---\n";
$adminHtml = file_get_contents(__DIR__ . '/../admin.php');
assertTrue(str_contains($adminHtml, 'id="adminNav-sell_restrictions"') && str_contains($adminHtml, 'Sell Restriction Control'), "admin.php includes 'Sell Restriction Control' tab in Admin navigation");
assertTrue(str_contains($adminHtml, 'id="adminView-sell_restrictions"') && str_contains($adminHtml, 'id="sellRestSearchInput"'), "admin.php includes Sell Restriction Control view and search input");

$adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertTrue(str_contains($adminJs, 'function loadAdminSellRestrictions') && str_contains($adminJs, 'function removeUpiSellRestriction'), "public/js/admin.js implements loadAdminSellRestrictions() and removeUpiSellRestriction()");
assertTrue(str_contains($adminJs, 'Remove Restriction (Make Live)'), "public/js/admin.js renders 'Remove Restriction (Make Live)' button for each restricted UPI ID");

echo "\n===================================================================\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
