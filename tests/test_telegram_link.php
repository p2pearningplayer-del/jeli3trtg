<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/AdminManager.php';

echo "Running Official Telegram Support Link Verification Tests...\n";

// 1. In-memory SQLite Database setup
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

$adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminRow = $adminStmt->fetch();
$testAdminId = $adminRow ? (int)$adminRow['id'] : 1;

// 2. Verify default seeded value is https://t.me/amilacorp
$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
$defaultUrl = $stmt->fetchColumn();
assert($defaultUrl === 'https://t.me/amilacorp', "Default seeded Telegram URL must be https://t.me/amilacorp (got '{$defaultUrl}')");

$stmtUser = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_username'");
$defaultUser = $stmtUser->fetchColumn();
assert($defaultUser === '@amilacorp', "Default seeded Telegram username must be @amilacorp (got '{$defaultUser}')");
echo "[PASS] Test 1: Platform default seed configured to https://t.me/amilacorp and @amilacorp\n";

// 3. Test saving with bare 't.me/amilacorp' without https:// prefix via AdminManager
$updateBare = AdminManager::updateSetting('telegram_support_url', 't.me/amilacorp', $testAdminId, 'Updating to t.me/amilacorp');
assert($updateBare['success'] === true, "Updating telegram_support_url with t.me/amilacorp must succeed");

$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
$savedUrl = $stmt->fetchColumn();
assert($savedUrl === 'https://t.me/amilacorp', "t.me/amilacorp must be normalized and saved as https://t.me/amilacorp (got '{$savedUrl}')");

$stmtUser = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_username'");
$savedUser = $stmtUser->fetchColumn();
assert($savedUser === '@amilacorp', "telegram_support_username must be auto-synced to @amilacorp (got '{$savedUser}')");
echo "[PASS] Test 2: 't.me/amilacorp' saves accurately in database as 'https://t.me/amilacorp' with auto-synced @amilacorp username\n";

// 4. Test saving with username '@amilacorp'
$updateAt = AdminManager::updateSetting('telegram_support_url', '@amilacorp', $testAdminId, 'Updating with @ handle');
assert($updateAt['success'] === true, "Updating telegram_support_url with @amilacorp must succeed");

$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
assert($stmt->fetchColumn() === 'https://t.me/amilacorp', "@amilacorp must be normalized to https://t.me/amilacorp");
echo "[PASS] Test 3: '@amilacorp' input normalizes accurately to 'https://t.me/amilacorp'\n";

// 5. Verify index.php and UI buttons have dynamic binding
$indexHtml = file_get_contents(__DIR__ . '/../index.php');
assert(strpos($indexHtml, 'id="supportTelegramLink"') !== false, "index.php must contain supportTelegramLink button");
assert(strpos($indexHtml, 'id="profileTelegramSupportLink"') !== false, "index.php must contain profileTelegramSupportLink button");
assert(strpos($indexHtml, 'href="<?= $telegramSupportUrl ?>"') !== false, "Telegram links must dynamically use telegramSupportUrl variable");
echo "[PASS] Test 4: index.php contains dynamic support buttons bound to \$telegramSupportUrl\n";

// 6. Verify admin.php Website & Branding inputs
$adminHtml = file_get_contents(__DIR__ . '/../admin.php');
assert(strpos($adminHtml, 'id="quickTelegramUrlInput"') !== false, "admin.php must contain quickTelegramUrlInput");
assert(strpos($adminHtml, 'id="quickTelegramTestBtn"') !== false, "admin.php must contain quickTelegramTestBtn");
assert(strpos($adminHtml, 'placeholder="https://t.me/amilacorp') !== false, "admin.php must have amilacorp placeholder");
echo "[PASS] Test 5: admin.php contains Website & Branding Telegram inputs and controls\n";

// 7. Verify api/support.php config and redirection endpoints
$apiSupport = file_get_contents(__DIR__ . '/../api/support.php');
assert(strpos($apiSupport, "case 'telegram':") !== false, "api/support.php must contain direct redirection handler");
assert(strpos($apiSupport, 'header("Location: " . $telegramUrl') !== false, "api/support.php must redirect with 302 Location header");
echo "[PASS] Test 6: api/support.php contains config endpoint and native 302 redirect handler\n";

echo "\nALL OFFICIAL TELEGRAM SUPPORT LINK TESTS PASSED SUCCESSFULLY!\n";
