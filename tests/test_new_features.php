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
echo "[PASS] Buyer cancellation releases reservation cleanly back to Available\n";

echo "\nALL NEW FEATURE TESTS PASSED SUCCESSFULLY!\n";
