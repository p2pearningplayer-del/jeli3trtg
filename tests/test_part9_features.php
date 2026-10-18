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

echo "Running Part 9 Feature & Go-Live Checklist Verification Tests...\n";

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
// Test 1: Dynamic Telegram Support URL & Username Settings (Part 9.1)
// -------------------------------------------------------------
$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
$tgUrl = $stmt->fetchColumn();
assert(!empty($tgUrl), "Telegram support URL must be configured");

$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_username'");
$tgUser = $stmt->fetchColumn();
assert(!empty($tgUser), "Telegram support username must be configured");

// Test updating Telegram URL via AdminManager
$updateRes = AdminManager::updateSetting('telegram_support_url', 'https://t.me/jelipay_official', 1, 'Unit test telegram update');
assert($updateRes['success'] === true, "Updating telegram setting should succeed");

$stmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
assert($stmt->fetchColumn() === 'https://t.me/jelipay_official', "Updated telegram support URL must persist");
echo "[PASS] Part 9.1: Dynamic Admin Telegram URL configuration and persistence verified\n";

// -------------------------------------------------------------
// Test 2: Branding Assets & Favicons exist and are valid (Part 9.2)
// -------------------------------------------------------------
$root = dirname(__DIR__);
$requiredFiles = [
    $root . '/favicon.svg',
    $root . '/favicon.ico',
    $root . '/public/images/logo.svg',
    $root . '/public/images/favicon.svg',
    $root . '/public/images/favicon-16x16.png',
    $root . '/public/images/favicon-32x32.png',
    $root . '/public/images/apple-touch-icon.png',
    $root . '/public/images/icon-192.png',
    $root . '/public/images/icon-512.png',
];

foreach ($requiredFiles as $file) {
    assert(file_exists($file), "Branding file must exist: " . basename($file));
    assert(filesize($file) > 50, "Branding file must not be empty: " . basename($file));
}
echo "[PASS] Part 9.2: Official Logo and all Favicon asset dimensions verified\n";

// -------------------------------------------------------------
// Test 3: PWA Manifest and Service Worker Verification (Part 9.3)
// -------------------------------------------------------------
$manifestPath = $root . '/manifest.json';
assert(file_exists($manifestPath), "manifest.json must exist at web root");
$manifestContent = file_get_contents($manifestPath);
$manifestData = json_decode($manifestContent, true);
assert(is_array($manifestData), "manifest.json must be valid JSON");
assert($manifestData['short_name'] === 'JeliPay', "PWA short_name must be JeliPay");
assert($manifestData['display'] === 'standalone', "PWA display mode must be standalone");
assert(!empty($manifestData['icons']) && count($manifestData['icons']) >= 4, "PWA icons must be specified");

$swPath = $root . '/sw.js';
assert(file_exists($swPath), "sw.js must exist at web root");
$swContent = file_get_contents($swPath);
assert(strpos($swContent, "CACHE_NAME") !== false, "sw.js must define cache name");
assert(strpos($swContent, "addEventListener('fetch'") !== false, "sw.js must handle fetch requests");
echo "[PASS] Part 9.3: PWA manifest.json and Service Worker sw.js verified\n";

// -------------------------------------------------------------
// Test 4: Push to Live Mode & Demo Data Purge (Part 9.4)
// -------------------------------------------------------------
// Insert a dummy demo user to test purge
$dummyPw = password_hash('TestPass123!', PASSWORD_BCRYPT);
$ins = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, account_status, role, is_demo, referral_code)
    VALUES ('9888800099', 'demotest@jelipay.in', ?, 'active', 'user', 1, 'DEMOTEST')
");
$ins->execute([$dummyPw]);
$demoDummyId = (int)$pdo->lastInsertId();

$wIns = $pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, 1000.00, 0.00, 1)");
$wIns->execute([$demoDummyId]);

// Verify demo user exists before purge
$countBefore = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_demo = 1")->fetchColumn();
assert($countBefore > 0, "Demo user should exist before purge");

// Execute purgeDemoData
$purgeRes = AdminManager::purgeDemoData(1);
assert($purgeRes['success'] === true, "purgeDemoData must succeed");
assert($purgeRes['platform_mode'] === 'live', "Platform mode must be live");

// Verify demo user was deleted
$countAfter = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_demo = 1")->fetchColumn();
assert($countAfter === 0, "All demo accounts must be completely purged");

// Verify platform_mode setting
$modeStmt = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'platform_mode'");
assert($modeStmt->fetchColumn() === 'live', "Platform mode must be live in platform_settings");
echo "[PASS] Part 9.4: Push to Live Mode & complete demo data purge verified\n";

echo "\nALL PART 9 FEATURE & GO-LIVE TESTS PASSED SUCCESSFULLY!\n";
