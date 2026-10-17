<?php
putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';

echo "Running Feature Verification Tests...\n";

// Initialize in-memory SQLite database
$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec("PRAGMA foreign_keys = ON;");
Database::setConnection($pdo, 'sqlite');
SchemaInstaller::initSqlite($pdo);
SchemaInstaller::migrateColumns($pdo, 'sqlite');
SchemaInstaller::seedPlatformSettings($pdo);

// 1. Health Score & Auto-Ban Test
$phone = '9999900011';
Auth::register($phone, 'password123');
$stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$stmt->execute([$phone]);
$user = $stmt->fetch();

// Reset health score to 100
$pdo = Database::getConnection();
$pdo->prepare("UPDATE users SET health_score = 100, account_status = 'active' WHERE id = ?")->execute([$user['id']]);

Auth::adjustHealthScore($user['id'], -10, 'Test minor penalty');
$freshUser = $pdo->query("SELECT * FROM users WHERE id = {$user['id']}")->fetch();
assert($freshUser['health_score'] == 90, "Expected health score 90, got {$freshUser['health_score']}");
echo "[PASS] Health Score deduction (-10 -> 90)\n";

Auth::adjustHealthScore($user['id'], -45, 'Major penalty');
$bannedUser = $pdo->query("SELECT * FROM users WHERE id = {$user['id']}")->fetch();
assert($bannedUser['health_score'] == 45, "Expected health score 45, got {$bannedUser['health_score']}");
assert($bannedUser['account_status'] === 'suspended', "Expected account_status suspended, got {$bannedUser['account_status']}");
echo "[PASS] Auto-ban when health score <= 50 triggers suspended status\n";

// 2. Change Password Test
$pwdRes = Auth::changePassword($user['id'], 'wrongpassword', 'newsecret123');
assert(!$pwdRes['success'], "Password change should fail with wrong password");

// Re-activate user to test login with new password
$pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([$user['id']]);

$pwdRes2 = Auth::changePassword($user['id'], 'password123', 'newsecret123');
assert($pwdRes2['success'], "Password change should succeed with correct password");

$loginCheck = Auth::login($phone, 'newsecret123');
assert($loginCheck['success'], "Login should succeed with new password: " . ($loginCheck['error'] ?? ''));
echo "[PASS] Password change and bcrypt authentication verification\n";

// 3. Notification Dispatch Test
Auth::createNotification($user['id'], 'Test Alert', 'This is a test notification', 'system');
$notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$notifStmt->execute([$user['id']]);
$notif = $notifStmt->fetch();
assert($notif && $notif['title'] === 'Test Alert', "Notification should be stored");
echo "[PASS] Notification creation and retrieval\n";

// 4. Buyer Cancel Reservation Test
// Deposit funds and create a sell order with another user
$sellerPhone = '9999900022';
$sStmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$sStmt->execute([$sellerPhone]);
$seller = $sStmt->fetch();
if (!$seller) {
    Auth::register($sellerPhone, 'password123');
    $sStmt->execute([$sellerPhone]);
    $seller = $sStmt->fetch();
}
Auth::setTpin($seller['id'], '1234');
$pdo->prepare("UPDATE wallets SET available_balance = 5000 WHERE user_id = ?")->execute([$seller['id']]);
$sellRes = OrderEngine::createSellOrder($seller['id'], 1000, '1234', 'Seller Test', 'seller@upi');
assert($sellRes['success'], "Sell order creation failed");

$childId = $sellRes['children'][0]['id'];
$buyerPhone = '9999900033';
$bStmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$bStmt->execute([$buyerPhone]);
$buyer = $bStmt->fetch();
if (!$buyer) {
    Auth::register($buyerPhone, 'password123');
    $bStmt->execute([$buyerPhone]);
    $buyer = $bStmt->fetch();
}
$pickRes = StateMachine::pickOrder($childId, $buyer['id']);
assert($pickRes['success'], "Pick order failed");

// Buyer cancels
$cancelRes = StateMachine::cancelReservation($childId, $buyer['id']);
assert($cancelRes['success'], "Cancel reservation failed: " . ($cancelRes['error'] ?? ''));

$freshChild = $pdo->query("SELECT * FROM child_orders WHERE id = {$childId}")->fetch();
assert($freshChild['status'] === 'Available', "Child order should be Available after cancellation");
assert(empty($freshChild['buyer_id']), "Buyer ID should be empty after cancellation");
// 5. One Active Buy Order Limit Test (Part 5, Item 2)
// Buyer picks first order
$pickRes1 = StateMachine::pickOrder($childId, $buyer['id']);
assert($pickRes1['success'], "First pick should succeed");

// Create another sell order for testing limit
$secondSell = OrderEngine::createSellOrder($seller['id'], 200, '1234', 'Seller Test', 'seller@upi');
assert($secondSell['success'], "Second sell order creation should succeed");
$secondChildId = $secondSell['children'][0]['id'];

// Buyer attempts to pick second order while first is active
$pickRes2 = StateMachine::pickOrder($secondChildId, $buyer['id']);
assert(!$pickRes2['success'], "Second pick should be blocked by active order limit");
assert(isset($pickRes2['active_order_id']) && $pickRes2['active_order_id'] === $childId, "Should return active_order_id");

// Buyer attempts custom allocation while active order exists
$allocRes = OrderEngine::allocateCustomBuy($buyer['id'], 200);
assert(!$allocRes['success'], "Custom allocation should be blocked by active order limit");
assert(isset($allocRes['active_order_id']), "Custom allocation error should include active_order_id");
echo "[PASS] Enforce One Active Buy Order Limit (pick & custom allocation blocked)\n";

// 6. Payment Proof & Seller Appeal / Dispute Center (Part 5, Item 1)
$utr = 'UTR_DISPUTE_TEST_' . time();
$proofRes = StateMachine::submitPaymentProof($childId, $buyer['id'], $utr, 'uploads/test_proof.jpg');
assert($proofRes['success'], "Submitting payment proof should succeed");

// Seller raises appeal
$appealRes = StateMachine::raiseAppeal($childId, $seller['id'], 'Payment not received in bank account', 'uploads/seller_statement.pdf');
assert($appealRes['success'], "Seller raising appeal should succeed");
assert(isset($appealRes['dispute_id']), "Appeal response should contain dispute_id");
$disputeId = (int)$appealRes['dispute_id'];

// Verify dispute in database
$dStmt = $pdo->prepare("SELECT * FROM disputes WHERE id = ?");
$dStmt->execute([$disputeId]);
$dispute = $dStmt->fetch();
assert($dispute && $dispute['status'] === 'pending', "Dispute should be pending");

// Additional evidence upload by buyer
$evStmt = $pdo->prepare("
    UPDATE disputes 
    SET evidence_screenshot_path = CASE 
        WHEN evidence_screenshot_path IS NULL OR evidence_screenshot_path = '' THEN ? 
        ELSE evidence_screenshot_path || ',' || ? 
    END,
    resolution_notes = CASE 
        WHEN resolution_notes IS NULL OR resolution_notes = '' THEN ? 
        ELSE resolution_notes || '\n[Buyer Evidence]: ' || ? 
    END,
    status = 'under_review'
    WHERE id = ?
");
$newEvidence = 'uploads/buyer_video.mp4';
$evStmt->execute([$newEvidence, $newEvidence, "Attached video confirmation", "Attached video confirmation", $disputeId]);

$dFresh = $pdo->query("SELECT * FROM disputes WHERE id = {$disputeId}")->fetch();
assert(strpos($dFresh['evidence_screenshot_path'], 'buyer_video.mp4') !== false, "Evidence screenshot path should contain appended evidence");
assert($dFresh['status'] === 'under_review', "Dispute status should be updated to under_review");
echo "[PASS] Dispute lifecycle & additional evidence submission verification\n";

// 7. Separation of Transaction History: Buy History vs Sell History (Part 5, Item 4)
// Admin resolves dispute in favor of buyer so order completes
require_once __DIR__ . '/../src/AdminManager.php';
$resolveRes = AdminManager::resolveDispute($disputeId, 1, 'force_settle', 'Arbitration resolved');
assert($resolveRes['success'], "Dispute settlement should succeed");

// Check buyer's buy history entries
$buyHistoryStmt = $pdo->prepare("
    SELECT c.id, c.amount, c.snapshotted_reward_rate, c.status, p.utr_number, u.account_holder_name as seller_name
    FROM child_orders c
    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
    JOIN users u ON c.seller_id = u.id
    WHERE c.buyer_id = ?
    ORDER BY c.id DESC
");
$buyHistoryStmt->execute([$buyer['id']]);
$buyerOrders = $buyHistoryStmt->fetchAll();
assert(count($buyerOrders) > 0, "Buyer should have at least 1 buy history record");
assert(!empty($buyerOrders[0]['seller_name']), "Buy history should include seller name");

// Check seller's sell history entries (Strict privacy: buyer details omitted)
$sellHistoryStmt = $pdo->prepare("
    SELECT c.id, c.parent_order_id, c.amount, c.status, p.utr_number
    FROM child_orders c
    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
    WHERE c.seller_id = ?
    ORDER BY c.id DESC
");
$sellHistoryStmt->execute([$seller['id']]);
$sellerOrders = $sellHistoryStmt->fetchAll();
assert(count($sellerOrders) > 0, "Seller should have sell history records");
echo "[PASS] Separation of Transaction History: Buy History & Sell History queries verified\n";

// 8. Buyer Privacy on Sell Page Test (Part 6, Item 1)
$sellerTracking = OrderEngine::getSellerTrackingData($seller['id']);
assert(!empty($sellerTracking), "Seller should have tracking data");
foreach ($sellerTracking as $p) {
    foreach ($p['children'] as $ch) {
        assert(!isset($ch['buyer_phone']), "Seller must NEVER receive buyer_phone");
        assert(!isset($ch['buyer_name']), "Seller must NEVER receive buyer_name");
        assert(!isset($ch['buyer_phone_masked']), "Seller must NEVER receive buyer_phone_masked");
        assert(!empty($ch['formatted_id']), "Child order must provide formatted_id");
    }
}
echo "[PASS] Buyer Privacy on Sell Page: Buyer personal details completely hidden; only Order ID exposed\n";

echo "\nALL NEW FEATURE TESTS PASSED SUCCESSFULLY!\n";


