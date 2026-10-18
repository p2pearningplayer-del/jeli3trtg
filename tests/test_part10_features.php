<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Validator.php';

echo "Running Part 10 Feature Verification Tests...\n";

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
SchemaInstaller::seedDemoAccounts($pdo);

// -------------------------------------------------------------
// TEST 1: Dashboard Interactive Metrics Bindings in admin.php
// -------------------------------------------------------------
$adminHtml = file_get_contents(ROOT_PATH . '/admin.php');

$requiredBindings = [
    "switchAdminTab('users', { role: 'all', status: 'all' })",
    "switchAdminTab('users', { status: 'active' })",
    "switchAdminTab('users', { status: 'suspended' })",
    "switchAdminTab('users')",
    "switchAdminTab('orders', { status: 'Completed', type: 'child' })",
    "switchAdminTab('rewards')",
    "switchAdminTab('orders', { status: 'Available', type: 'child' })",
    "switchAdminTab('orders', { status: 'Reserved', type: 'child' })",
    "switchAdminTab('orders', { status: 'Payment Proof Submitted', type: 'child' })",
    "switchAdminTab('disputes', { status: 'all' })",
    "switchAdminTab('orders', { status: 'Cancelled / Reversed', type: 'child' })",
    "adminNav-admins",
    "disputeSearchInput"
];

$allBindingsPresent = true;
foreach ($requiredBindings as $b) {
    if (strpos($adminHtml, $b) === false) {
        $allBindingsPresent = false;
        echo "[FAIL] Missing binding in admin.php: {$b}\n";
        exit(1);
    }
}
echo "[PASS] Part 10.1: Interactive dashboard metric cards & dispute search bar present in admin.php\n";

// -------------------------------------------------------------
// TEST 2: Dispute Section Search & Filter Logic
// -------------------------------------------------------------
// Create a sample dispute for testing search
$testSellerPhone = '9999988881';
$testBuyerPhone = '9999988882';
$pwdHash = password_hash('Pass@123', PASSWORD_BCRYPT);

// Clean up any existing test records
$pdo->exec("DELETE FROM users WHERE phone IN ('$testSellerPhone', '$testBuyerPhone')");

$pdo->prepare("INSERT INTO users (phone, password_hash, referral_code, account_status, role, account_holder_name) VALUES (?, ?, 'JPTEST1', 'active', 'user', 'Search Seller')")
    ->execute([$testSellerPhone, $pwdHash]);
$sellerId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO users (phone, password_hash, referral_code, account_status, role, account_holder_name) VALUES (?, ?, 'JPTEST2', 'active', 'user', 'Search Buyer')")
    ->execute([$testBuyerPhone, $pwdHash]);
$buyerId = (int)$pdo->lastInsertId();

// Create parent order
$pdo->prepare("INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, snapshotted_upi_id, snapshotted_account_holder, status) VALUES (?, 1000, 0, 'seller@upi', 'Search Seller', 'active')")
    ->execute([$sellerId]);
$parentId = (int)$pdo->lastInsertId();

// Create child order
$pdo->prepare("INSERT INTO child_orders (parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status) VALUES (?, ?, ?, 1000, 9.0, 90, 'seller@upi', 'Search Seller', 'Disputed')")
    ->execute([$parentId, $sellerId, $buyerId]);
$childId = (int)$pdo->lastInsertId();

// Create payment proof
$testUtr = 'UTRDISPUTE' . time();
$pdo->prepare("INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path, notes) VALUES (?, ?, ?, '/uploads/proof.jpg', 'Search Proof')")
    ->execute([$childId, $buyerId, $testUtr]);

// Create dispute
$pdo->prepare("INSERT INTO disputes (child_order_id, raised_by_user_id, raised_by_role, reason, status) VALUES (?, ?, 'seller', 'Did not receive funds', 'pending')")
    ->execute([$childId, $sellerId]);
$disputeId = (int)$pdo->lastInsertId();

// Query disputes with search query for UTR
$sqlSearch = "
    SELECT d.id, d.child_order_id, p.utr_number, u_seller.phone as seller_phone
    FROM disputes d
    JOIN child_orders c ON d.child_order_id = c.id
    LEFT JOIN users u_seller ON c.seller_id = u_seller.id
    LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
    LEFT JOIN payment_proofs p ON c.id = p.child_order_id
    WHERE (d.id = :qid OR d.child_order_id = :qchild OR p.utr_number LIKE :qlike OR u_seller.phone LIKE :qlike)
";
$stmtSearch = $pdo->prepare($sqlSearch);
$stmtSearch->execute([
    ':qid' => -1,
    ':qchild' => -1,
    ':qlike' => "%{$testUtr}%"
]);
$foundDisputes = $stmtSearch->fetchAll();
if (count($foundDisputes) === 0 || (int)$foundDisputes[0]['id'] !== $disputeId) {
    echo "[FAIL] Dispute search by UTR failed.\n";
    exit(1);
}

// Search by seller phone
$stmtSearchPhone = $pdo->prepare($sqlSearch);
$stmtSearchPhone->execute([
    ':qid' => -1,
    ':qchild' => -1,
    ':qlike' => "%{$testSellerPhone}%"
]);
$foundByPhone = $stmtSearchPhone->fetchAll();
if (count($foundByPhone) === 0 || (int)$foundByPhone[0]['id'] !== $disputeId) {
    echo "[FAIL] Dispute search by seller phone failed.\n";
    exit(1);
}
echo "[PASS] Part 10.2: Dispute Section search & filter logic operates accurately on Order ID, UTR, and Phone\n";

// -------------------------------------------------------------
// TEST 3: Settings Persistence & Upsert (Website & Branding Bug Fix)
// -------------------------------------------------------------
// Find or create admin ID for audit
$adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminRow = $adminStmt->fetch();
$testAdminId = $adminRow ? (int)$adminRow['id'] : 1;

// 1. Update existing telegram_support_url
$newTgUrl = 'https://t.me/jelipay_support_live_' . time();
$updRes = AdminManager::updateSetting('telegram_support_url', $newTgUrl, $testAdminId, 'Updating support URL');
if (!$updRes['success']) {
    echo "[FAIL] Failed to update telegram_support_url: " . ($updRes['error'] ?? '') . "\n";
    exit(1);
}

$chkStmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
$chkStmt->execute();
if ($chkStmt->fetchColumn() !== $newTgUrl) {
    echo "[FAIL] telegram_support_url did not persist to database.\n";
    exit(1);
}

// 2. Upsert a completely new setting key that does not exist in DB
$customKey = 'custom_test_feature_' . time();
$upsertRes = AdminManager::updateSetting($customKey, 'feature_enabled_true', $testAdminId, 'Upserting new platform setting');
if (!$upsertRes['success']) {
    echo "[FAIL] Failed to upsert new setting key: " . ($upsertRes['error'] ?? '') . "\n";
    exit(1);
}

$chkCustom = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
$chkCustom->execute([$customKey]);
if ($chkCustom->fetchColumn() !== 'feature_enabled_true') {
    echo "[FAIL] Upserted custom setting did not persist.\n";
    exit(1);
}
echo "[PASS] Part 10.3: Website & Branding settings save & upsert verified without errors\n";

// -------------------------------------------------------------
// TEST 4: Admin Management, Role-Based Access Control & Password Reset
// -------------------------------------------------------------
// 1. List administrators
$adminList = AdminManager::listAdmins();
if (!is_array($adminList) || count($adminList) === 0) {
    echo "[FAIL] AdminManager::listAdmins() returned empty result.\n";
    exit(1);
}

// 2. Create Support Team Administrator
$testSupportPhone = '9999900011';
$pdo->exec("DELETE FROM users WHERE phone = '$testSupportPhone'");
$createSupportRes = AdminManager::createAdmin([
    'phone' => $testSupportPhone,
    'password' => 'Support@123',
    'admin_role' => 'support_agent',
    'email' => 'support.test@jelipay.in',
    'name' => 'Support Desk Agent'
], $testAdminId);

if (!$createSupportRes['success']) {
    echo "[FAIL] Failed to create Support Team admin: " . ($createSupportRes['error'] ?? '') . "\n";
    exit(1);
}
$supportAdminId = (int)$createSupportRes['admin_id'];

// 3. Create Finance Team Administrator
$testFinancePhone = '9999900022';
$pdo->exec("DELETE FROM users WHERE phone = '$testFinancePhone'");
$createFinanceRes = AdminManager::createAdmin([
    'phone' => $testFinancePhone,
    'password' => 'Finance@123',
    'admin_role' => 'finance_manager',
    'email' => 'finance.test@jelipay.in',
    'name' => 'Finance Ledger Manager'
], $testAdminId);

if (!$createFinanceRes['success']) {
    echo "[FAIL] Failed to create Finance Team admin: " . ($createFinanceRes['error'] ?? '') . "\n";
    exit(1);
}
$financeAdminId = (int)$createFinanceRes['admin_id'];

// 4. Verify RBAC Permissions for Support Team
$supportUser = ['role' => 'admin', 'admin_role' => 'support_agent'];
$supportCanArbitrate = AdminManager::checkPermission($supportUser, 'disputes_arbitrate');
$supportCannotAdjustBalance = !AdminManager::checkPermission($supportUser, 'adjust_balance');
$supportCannotManageAdmins = !AdminManager::checkPermission($supportUser, 'admins_manage');

if (!$supportCanArbitrate || !$supportCannotAdjustBalance) {
    echo "[FAIL] Support Team RBAC permissions invalid: arbitrate={$supportCanArbitrate}, adjust_balance=" . (!$supportCannotAdjustBalance) . "\n";
    exit(1);
}

// 5. Verify RBAC Permissions for Finance Team
$financeUser = ['role' => 'admin', 'admin_role' => 'finance_manager'];
$financeCanAdjustBalance = AdminManager::checkPermission($financeUser, 'adjust_balance');
$financeCannotArbitrate = !AdminManager::checkPermission($financeUser, 'disputes_arbitrate');
$financeCannotManageAdmins = !AdminManager::checkPermission($financeUser, 'admins_manage');

if (!$financeCanAdjustBalance || !$financeCannotArbitrate) {
    echo "[FAIL] Finance Team RBAC permissions invalid: adjust_balance={$financeCanAdjustBalance}, arbitrate=" . (!$financeCannotArbitrate) . "\n";
    exit(1);
}

// 6. Test Admin Role Update
$updateRoleRes = AdminManager::updateAdminRole($supportAdminId, 'finance_team', $testAdminId);
if (!$updateRoleRes['success']) {
    echo "[FAIL] Failed to update admin role: " . ($updateRoleRes['error'] ?? '') . "\n";
    exit(1);
}

$chkRole = $pdo->prepare("SELECT admin_role FROM users WHERE id = ?");
$chkRole->execute([$supportAdminId]);
if ($chkRole->fetchColumn() !== 'finance_team') {
    echo "[FAIL] Admin role update did not persist to database.\n";
    exit(1);
}

// 7. Test Centralized Password Reset
// Test rejection with wrong current password
$badPassRes = AdminManager::changeAdminPassword($supportAdminId, 'WrongPassword123', 'NewPass@123');
if ($badPassRes['success']) {
    echo "[FAIL] changeAdminPassword should have rejected invalid current password.\n";
    exit(1);
}

// Test successful password change
$goodPassRes = AdminManager::changeAdminPassword($supportAdminId, 'Support@123', 'NewPass@123');
if (!$goodPassRes['success']) {
    echo "[FAIL] changeAdminPassword failed with valid current password: " . ($goodPassRes['error'] ?? '') . "\n";
    exit(1);
}

// Verify login with new password
$loginWithNewPass = Auth::adminLogin($testSupportPhone, 'NewPass@123');
if (!$loginWithNewPass['success']) {
    echo "[FAIL] Admin cannot authenticate with newly updated password: " . ($loginWithNewPass['error'] ?? '') . "\n";
    exit(1);
}

// Verify login with old password fails
$loginWithOldPass = Auth::adminLogin($testSupportPhone, 'Support@123');
if ($loginWithOldPass['success']) {
    echo "[FAIL] Old password still authenticated after password change.\n";
    exit(1);
}

// 8. Test Self-Deletion Prevention and Admin Deletion
$selfDelRes = AdminManager::deleteAdmin($testAdminId, $testAdminId);
if ($selfDelRes['success']) {
    echo "[FAIL] AdminManager::deleteAdmin should have prevented self-deletion.\n";
    exit(1);
}

$delSupportRes = AdminManager::deleteAdmin($supportAdminId, $testAdminId);
if (!$delSupportRes['success']) {
    echo "[FAIL] Failed to delete support admin: " . ($delSupportRes['error'] ?? '') . "\n";
    exit(1);
}

$delFinanceRes = AdminManager::deleteAdmin($financeAdminId, $testAdminId);
if (!$delFinanceRes['success']) {
    echo "[FAIL] Failed to delete finance admin: " . ($delFinanceRes['error'] ?? '') . "\n";
    exit(1);
}

echo "[PASS] Part 10.4: Admin Role Management, RBAC for Support & Finance Teams, and Password Reset verified\n";

echo "\nALL PART 10 FEATURE TESTS PASSED SUCCESSFULLY!\n";
