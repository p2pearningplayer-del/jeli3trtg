<?php
/**
 * Verification Suite:
 * 1. Admin "Pending Confirmations" Fast-Track Resolution (Resolve for Buyer / Resolve for Seller)
 * 2. Expanded Support Button Options (Jelipay Group & Jelipay High Priority Support) + Dynamic Admin Control
 */

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

echo "=== Test Suite: Pending Confirmations Fast-Track Resolution & Support Options ===\n\n";

// Create test Admin, Seller, and Buyer
$suffix = substr((string)time(), -5) . rand(10, 99);
$adminPhone = '910' . $suffix;
$sellerPhone = '920' . $suffix;
$buyerPhone = '930' . $suffix;

$insUser = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, tpin_hash, account_status, role, admin_role, referral_code, upi_id, account_holder_name)
    VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
");
$hash = password_hash('Test@12345', PASSWORD_BCRYPT);
$tpin = password_hash('1234', PASSWORD_BCRYPT);

$insUser->execute([$adminPhone, "admin_{$suffix}@gmail.com", $hash, $tpin, 'admin', 'super_admin', "ADM{$suffix}", "adm{$suffix}@upi", "FastTrack Admin"]);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$adminId]);

$insUser->execute([$sellerPhone, "seller_{$suffix}@gmail.com", $hash, $tpin, 'user', null, "SEL{$suffix}", "seller{$suffix}@upi", "Test Seller"]);
$sellerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 5000, 0, 0, 1)")->execute([$sellerId]);

$insUser->execute([$buyerPhone, "buyer_{$suffix}@gmail.com", $hash, $tpin, 'user', null, "BUY{$suffix}", "buyer{$suffix}@upi", "Test Buyer"]);
$buyerId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version) VALUES (?, 0, 0, 0, 1)")->execute([$buyerId]);

// -----------------------------------------------------------------------------
// TEST 1: Create Sell Listing & Submit Payment Proofs for 2 Orders
// -----------------------------------------------------------------------------
echo "--- 1. Pending Payment Confirmations Live Queue ---\n";

$sellRes = OrderEngine::createSellOrder($sellerId, 1000.00, '1234', "Test Seller", "seller{$suffix}@upi");
assertTrue(!empty($sellRes['success']), "Seller listed ₹1000 sell order");
$parentOrderId = (int)($sellRes['parent_order_id'] ?? 0);

// Create two ₹500 child orders in 'Payment Proof Submitted' state (timer still active — 15 mins in future)
$pdo->prepare("DELETE FROM child_orders WHERE parent_order_id = ?")->execute([$parentOrderId]);

$nowUtc = gmdate('Y-m-d H:i:s');
$futureDeadline = gmdate('Y-m-d H:i:s', time() + 800); // Timer has NOT run out!

$insChild = $pdo->prepare("
    INSERT INTO child_orders (
        parent_order_id, seller_id, buyer_id, amount,
        snapshotted_upi_id, snapshotted_account_holder,
        snapshotted_reward_rate, snapshotted_reward_amount,
        status, proof_submitted_at, verification_deadline, created_at
    ) VALUES (?, ?, ?, 500.00, ?, 'Test Seller', 9.0, 45.00, 'Payment Proof Submitted', ?, ?, ?)
");

$insChild->execute([$parentOrderId, $sellerId, $buyerId, "seller{$suffix}@upi", $nowUtc, $futureDeadline, $nowUtc]);
$order1Id = (int)$pdo->lastInsertId();

$insChild->execute([$parentOrderId, $sellerId, $buyerId, "seller{$suffix}@upi", $nowUtc, $futureDeadline, $nowUtc]);
$order2Id = (int)$pdo->lastInsertId();

// Update parent order reserved_amount = 1000
$pdo->prepare("UPDATE parent_sell_orders SET reserved_amount = 1000.00 WHERE id = ?")->execute([$parentOrderId]);

// Insert payment proofs (UTR + Screenshot)
$insProof = $pdo->prepare("
    INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path, notes, created_at)
    VALUES (?, ?, ?, ?, 'Submitted payment proof', ?)
");
$utr1 = "UTR111{$suffix}";
$utr2 = "UTR222{$suffix}";
$insProof->execute([$order1Id, $buyerId, $utr1, "/uploads/proofs/test_proof_1_{$suffix}.png", $nowUtc]);
$insProof->execute([$order2Id, $buyerId, $utr2, "/uploads/proofs/test_proof_2_{$suffix}.png", $nowUtc]);

// Query Pending Confirmations via AdminManager::getPendingConfirmations
$pendingQueue = AdminManager::getPendingConfirmations($utr1);
assertTrue($pendingQueue['total'] === 1, "getPendingConfirmations locates Order #1 by UTR in live waiting queue");
assertTrue(($pendingQueue['orders'][0]['id'] ?? 0) === $order1Id, "Pending confirmation row matches Order #1 ID");
assertTrue(($pendingQueue['orders'][0]['screenshot_path'] ?? '') === "/uploads/proofs/test_proof_1_{$suffix}.png", "Pending confirmation row includes screenshot_path for immediate admin viewing");
assertTrue(($pendingQueue['orders'][0]['utr_number'] ?? '') === $utr1, "Pending confirmation row includes UTR number");
assertTrue(($pendingQueue['orders'][0]['seconds_remaining'] ?? 0) > 0, "Order #1 still has active SLA timer remaining (> 0s)");

// Query via AdminManager::getOrders(..., section='pending_confirmations')
$ordersSec = AdminManager::getOrders('all', 'child', $utr1, 20, 0, 'pending_confirmations');
assertTrue($ordersSec['total'] === 1, "getOrders(section='pending_confirmations') returns Order #1");
assertTrue(($ordersSec['counts']['pending_confirmations'] ?? 0) >= 2, "getOrders counts['pending_confirmations'] reflects active pending confirmations");

// -----------------------------------------------------------------------------
// TEST 2: Fast-Track "Resolve for Buyer" (Force Settle) BEFORE Timer Expires
// -----------------------------------------------------------------------------
echo "\n--- 2. Fast-Track 'Resolve for Buyer' (Force Settle) Instant Override ---\n";

$buyerWalletBefore = Wallet::getWallet($buyerId);
$sellerWalletBefore = Wallet::getWallet($sellerId);

$resBuyer = AdminManager::fastTrackResolveOrder(
    $order1Id,
    $adminId,
    'resolve_buyer',
    'Payment proof verified by Admin Fast-Track'
);
assertTrue(!empty($resBuyer['success']), "fastTrackResolveOrder('resolve_buyer') succeeded immediately without waiting for timer or dispute");
assertTrue(($resBuyer['new_status'] ?? '') === 'Completed', "Order #1 status transitioned to 'Completed'");

$buyerWalletAfter = Wallet::getWallet($buyerId);
$sellerWalletAfter = Wallet::getWallet($sellerId);

$buyerAvailDiff = round((float)$buyerWalletAfter['available_balance'] - (float)$buyerWalletBefore['available_balance'], 2);
$sellerResDiff = round((float)$sellerWalletBefore['reserved_balance'] - (float)$sellerWalletAfter['reserved_balance'], 2);

assertTrue($buyerAvailDiff === 545.00, "Buyer available balance credited with ₹545.00 (₹500 principal + ₹45 reward)");
assertTrue($sellerResDiff === 500.00, "Seller reserved balance settled by ₹500.00");

// Verify Order #1 is no longer in Pending Confirmations queue
$pendingAfter1 = AdminManager::getPendingConfirmations($utr1);
assertTrue($pendingAfter1['total'] === 0, "Order #1 removed from Pending Confirmations queue after Resolve for Buyer");

// -----------------------------------------------------------------------------
// TEST 3: Fast-Track "Resolve for Seller" (Cancel Order) BEFORE Timer Expires
// -----------------------------------------------------------------------------
echo "\n--- 3. Fast-Track 'Resolve for Seller' (Cancel Order) Instant Override ---\n";

$sellerWalletBeforeCancel = Wallet::getWallet($sellerId);
$buyerWalletBeforeCancel = Wallet::getWallet($buyerId);

$resSeller = AdminManager::fastTrackResolveOrder(
    $order2Id,
    $adminId,
    'resolve_seller',
    'Fake screenshot detected by Admin Fast-Track'
);
assertTrue(!empty($resSeller['success']), "fastTrackResolveOrder('resolve_seller') succeeded immediately without waiting for timer or dispute");
assertTrue(($resSeller['new_status'] ?? '') === 'Cancelled / Reversed', "Order #2 status transitioned to 'Cancelled / Reversed'");

$sellerWalletAfterCancel = Wallet::getWallet($sellerId);
$buyerWalletAfterCancel = Wallet::getWallet($buyerId);

$sellerAvailReturned = round((float)$sellerWalletAfterCancel['available_balance'] - (float)$sellerWalletBeforeCancel['available_balance'], 2);
$sellerResReleased = round((float)$sellerWalletBeforeCancel['reserved_balance'] - (float)$sellerWalletAfterCancel['reserved_balance'], 2);
$buyerUnchanged = round((float)$buyerWalletAfterCancel['available_balance'] - (float)$buyerWalletBeforeCancel['available_balance'], 2);

assertTrue($sellerAvailReturned === 500.00, "Seller available balance restored by ₹500.00");
assertTrue($sellerResReleased === 500.00, "Seller reserved balance reduced by ₹500.00");
assertTrue($buyerUnchanged === 0.00, "Buyer balance unchanged when resolved for seller");

// Verify Order #2 is no longer in Pending Confirmations queue
$pendingAfter2 = AdminManager::getPendingConfirmations($utr2);
assertTrue($pendingAfter2['total'] === 0, "Order #2 removed from Pending Confirmations queue after Resolve for Seller");

// Verify double-resolution is blocked
$doubleRes = AdminManager::fastTrackResolveOrder($order2Id, $adminId, 'resolve_buyer');
assertTrue($doubleRes['success'] === false, "Already-resolved order cannot be resolved a second time");

// -----------------------------------------------------------------------------
// TEST 4: Expanded Support Options & Dynamic Admin Settings Control
// -----------------------------------------------------------------------------
echo "\n--- 4. Expanded Support Options & Dynamic Admin URL Control ---\n";

$updGroup = AdminManager::updateSetting('jelipay_group_url', 'https://t.me/jelipay_official_community', $adminId, 'Update Jelipay Group link');
assertTrue(!empty($updGroup['success']), "Admin dynamically updated 'jelipay_group_url' in Website & Branding settings");

$updPriority = AdminManager::updateSetting('jelipay_priority_support_url', 'https://t.me/jelipay_vip_priority', $adminId, 'Update High Priority Support link');
assertTrue(!empty($updPriority['success']), "Admin dynamically updated 'jelipay_priority_support_url' in Website & Branding settings");

$settingsStmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM platform_settings
    WHERE setting_key IN ('telegram_support_url', 'jelipay_group_url', 'jelipay_priority_support_url')
");
$supportMap = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

assertTrue(($supportMap['jelipay_group_url'] ?? '') === 'https://t.me/jelipay_official_community', "Database persisted updated Jelipay Group URL");
assertTrue(($supportMap['jelipay_priority_support_url'] ?? '') === 'https://t.me/jelipay_vip_priority', "Database persisted updated Jelipay High Priority Support URL");
assertTrue(!empty($supportMap['telegram_support_url']), "Standard Telegram Support URL remains active alongside new options");

// Check UI templates & JS wiring
$indexHtml = file_get_contents(__DIR__ . '/../index.php');
assertTrue(str_contains($indexHtml, 'id="supportPriorityLink"') && str_contains($indexHtml, 'Jelipay High Priority Support'), "index.php renders 'Jelipay High Priority Support' option in user support menu");
assertTrue(str_contains($indexHtml, 'id="supportGroupLink"') && str_contains($indexHtml, 'Jelipay Group'), "index.php renders 'Jelipay Group' option in user support menu");
assertTrue(str_contains($indexHtml, 'id="supportTelegramLink"') && str_contains($indexHtml, 'Telegram Support'), "index.php retains standard 'Telegram Support' option in user support menu");

$adminHtml = file_get_contents(__DIR__ . '/../admin.php');
assertTrue(str_contains($adminHtml, 'id="adminNav-pending_confirmations"') && str_contains($adminHtml, 'id="adminView-pending_confirmations"'), "admin.php includes dedicated 'Pending Confirmations' navigation tab and view");
assertTrue(str_contains($adminHtml, 'id="quickGroupUrlInput"') && str_contains($adminHtml, 'id="quickPrioritySupportUrlInput"'), "admin.php Website & Branding section includes dynamic inputs for Jelipay Group and High Priority Support URLs");

$adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertTrue(str_contains($adminJs, 'function loadAdminPendingConfirmations') && str_contains($adminJs, 'function fastTrackResolveOrder'), "public/js/admin.js implements loadAdminPendingConfirmations() and fastTrackResolveOrder()");
assertTrue(str_contains($adminJs, 'Resolve for Buyer') && str_contains($adminJs, 'Resolve for Seller'), "public/js/admin.js renders 'Resolve for Buyer' and 'Resolve for Seller' override buttons");

echo "\n===================================================================\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
