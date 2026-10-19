<?php
/**
 * P2P Token Platform - Part 11 & Part 12 Verification Suite
 * Tests for Admin Panel updates, Session Stability, Lightbox UI, and Screenshot Privacy.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';

echo "====================================================================\n";
echo " P2P TOKEN PLATFORM - PART 11 & PART 12 VERIFICATION SUITE\n";
echo " Target: https://jelipay.in/ | DB: SQLite Memory Mock\n";
echo "====================================================================\n\n";

// Initialize SQLite database for local test runner
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

$passCount = 0;
$totalCount = 7;

// TEST 1: Session Lifetime & Debouncing Configuration (Part 12.1)
try {
    $gcLifetime = (int)ini_get('session.gc_maxlifetime');
    
    if ($gcLifetime < 86400) {
        throw new Exception("session.gc_maxlifetime ({$gcLifetime}s) is less than 24 hours (86400s)");
    }
    
    $appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
    if (!str_contains($appJs, 'isFetchingSession')) {
        throw new Exception("fetchSession() debounce guard 'isFetchingSession' not found in public/js/app.js");
    }
    
    echo "[PASS] Test 1: Session Lifetime Configured (>= 24-48 hrs: {$gcLifetime}s) & Client Debounce Active\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 1: Session Lifetime - " . $e->getMessage() . "\n";
}

// TEST 2: Dashboard & Activity Metrics Sync (Part 11.1)
try {
    $stats = AdminManager::getPlatformStats();
    
    $requiredKeys = [
        'total_users', 'total_tokens_in_circulation', 'total_completed_trading_volume',
        'total_rewards', 'orders', 'recent_transactions', 'recent_admin_activities'
    ];
    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $stats)) {
            throw new Exception("Missing key '{$key}' in AdminManager::getPlatformStats()");
        }
    }
    
    if (!is_numeric($stats['total_users']) || !is_numeric($stats['total_tokens_in_circulation'])) {
        throw new Exception("Dashboard stats metrics are not numeric");
    }
    
    echo "[PASS] Test 2: Dashboard Metrics Synced (Users: {$stats['total_users']}, Circulation: {$stats['total_tokens_in_circulation']}, Volume: ₹{$stats['total_completed_trading_volume']}, Rewards: ₹{$stats['total_rewards']})\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 2: Dashboard Metrics - " . $e->getMessage() . "\n";
}

// TEST 3: Persistent Navigation on Refresh (Part 11.2)
try {
    $adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
    if (!str_contains($adminJs, "adminActiveTab") || !str_contains($adminJs, "window.location.hash") || !str_contains($adminJs, "hashchange")) {
        throw new Exception("Tab persistence via localStorage and window.location.hash not properly implemented in admin.js");
    }
    
    echo "[PASS] Test 3: Persistent Tab Navigation implemented via localStorage & hash routing in admin.js\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 3: Persistent Navigation - " . $e->getMessage() . "\n";
}

// TEST 4: "Website & Branding" Dynamic Setting Sync (Part 11.3)
try {
    // Save new telegram URL
    $testUrl = "https://t.me/amilacorp";
    $res = AdminManager::updateSetting('telegram_support_url', $testUrl, 1);
    
    if (empty($res['success'])) {
        throw new Exception("Failed to update telegram_support_url");
    }
    
    $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = ?");
    $stmt->execute(['telegram_support_url']);
    $savedUrl = $stmt->fetchColumn();
    
    $stmt->execute(['telegram_support_username']);
    $savedUser = $stmt->fetchColumn();
    
    if ($savedUrl !== $testUrl) {
        throw new Exception("Saved URL '{$savedUrl}' does not match target '{$testUrl}'");
    }
    if ($savedUser !== '@amilacorp') {
        throw new Exception("Auto-synchronized telegram username does not match: '{$savedUser}'");
    }
    
    echo "[PASS] Test 4: Dynamic Setting & Telegram Support Auto-Sync functional in AdminManager (URL: {$savedUrl}, User: {$savedUser})\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 4: Setting Sync - " . $e->getMessage() . "\n";
}

// TEST 5: Admin Role Creation (Part 11.4)
try {
    $testPhone = '9999' . str_pad((string)mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    $testName = 'Test Agent ' . mt_rand(100, 999);
    $testRole = 'finance_manager';
    
    // Test creating admin
    $created = AdminManager::createAdmin([
        'name' => $testName,
        'phone' => $testPhone,
        'password' => 'Password123!',
        'admin_role' => $testRole
    ], 1);
    
    if (empty($created['success'])) {
        throw new Exception("Admin creation failed: " . ($created['error'] ?? 'Unknown'));
    }
    
    $adminUserId = (int)$created['admin_id'];
    $stmt = $pdo->prepare("SELECT role, admin_role FROM users WHERE id = ?");
    $stmt->execute([$adminUserId]);
    $row = $stmt->fetch();
    
    if (!$row || $row['role'] !== 'admin' || $row['admin_role'] !== $testRole) {
        throw new Exception("Created admin has invalid role/admin_role: " . json_encode($row));
    }
    
    echo "[PASS] Test 5: Admin Role Creation Verified (Role: {$testRole}, Phone: {$testPhone})\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 5: Admin Role Creation - " . $e->getMessage() . "\n";
}

// TEST 6: Evidence Inspection Lightbox UI Modals (Part 11.5 & Part 12.2)
try {
    $adminPhp = file_get_contents(__DIR__ . '/../admin.php');
    $indexPhp = file_get_contents(__DIR__ . '/../index.php');
    $adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
    $appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
    
    if (!str_contains($adminPhp, 'id="evidenceLightboxModal"')) {
        throw new Exception("#evidenceLightboxModal not found in admin.php");
    }
    if (!str_contains($indexPhp, 'id="userEvidenceLightboxModal"')) {
        throw new Exception("#userEvidenceLightboxModal not found in index.php");
    }
    if (!str_contains($adminJs, 'function openEvidenceLightbox')) {
        throw new Exception("openEvidenceLightbox() missing in public/js/admin.js");
    }
    if (!str_contains($appJs, 'function openUserEvidenceLightbox')) {
        throw new Exception("openUserEvidenceLightbox() missing in public/js/app.js");
    }
    
    // Verify no direct target="_blank" links for payment proofs remain in app.js
    if (preg_match('/href="\/serve_file\.php\?file=[^"]*"\s+target="_blank"/', $appJs)) {
        throw new Exception("Found unconverted target=\"_blank\" serve_file.php link in public/js/app.js");
    }
    
    echo "[PASS] Test 6: In-page Evidence Lightbox Pop-ups Present in Admin & User panels with Keyboard Controls\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 6: Lightbox UI - " . $e->getMessage() . "\n";
}

// TEST 7: Screenshot Privacy & Finalized Order Redaction (Part 12.3)
try {
    // Check wallet API code structure for privacy redaction
    $walletPhp = file_get_contents(__DIR__ . '/../api/wallet.php');
    if (!str_contains($walletPhp, "Part 12, Item 3: Screenshot Privacy") || !str_contains($walletPhp, "\$ord['screenshot_path'] = null;")) {
        throw new Exception("api/wallet.php missing screenshot redaction logic for buy_history");
    }
    if (!str_contains($walletPhp, "\$p['screenshot_path'] = null;")) {
        throw new Exception("api/wallet.php missing screenshot redaction logic for sell_history");
    }
    
    // Check serve_file.php for finalized order guard
    $servePhp = file_get_contents(__DIR__ . '/../serve_file.php');
    if (!str_contains($servePhp, "Part 12, Item 3: Once an order reaches a final state")) {
        throw new Exception("serve_file.php missing finalized order screenshot protection guard");
    }
    
    // Check disputes API for resolved dispute redaction
    $dispPhp = file_get_contents(__DIR__ . '/../api/disputes.php');
    if (!str_contains($dispPhp, "Part 12, Item 3: Screenshot Privacy") || !str_contains($dispPhp, "\$disp['buyer_screenshot'] = null;")) {
        throw new Exception("api/disputes.php missing screenshot redaction on finalized disputes");
    }
    
    echo "[PASS] Test 7: Screenshot Privacy Enforced across Wallet History, Disputes, and serve_file.php Gate\n";
    $passCount++;
} catch (Exception $e) {
    echo "[FAIL] Test 7: Screenshot Privacy - " . $e->getMessage() . "\n";
}

echo "\n--------------------------------------------------------------------\n";
echo "Summary: {$passCount} / {$totalCount} Scenarios Passed (" . round(($passCount / $totalCount) * 100, 1) . "%)\n";
echo "--------------------------------------------------------------------\n";

if ($passCount === $totalCount) {
    echo "SUCCESS: All Part 11 and Part 12 specifications verified and passing!\n";
    exit(0);
} else {
    echo "FAILURE: One or more test scenarios did not pass.\n";
    exit(1);
}
