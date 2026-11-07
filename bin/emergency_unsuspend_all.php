<?php
/**
 * CLI Tool: Emergency Unsuspend All Users
 * Usage: php bin/emergency_unsuspend_all.php [--exclude-id=ID] [--exclude-phone=PHONE]
 */

declare(strict_types=1);

if (php_sapi_name() === 'cli' && !getenv('ALLOW_SQLITE_CLI_FALLBACK')) {
    putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';

echo "====================================================================\n";
echo " JELIPAY P2P - EMERGENCY UN-SUSPEND ALL USERS\n";
echo "====================================================================\n\n";

$options = getopt('', ['exclude-id::', 'exclude-phone::']);
$excludeId = isset($options['exclude-id']) ? (int)$options['exclude-id'] : null;
$excludePhone = isset($options['exclude-phone']) ? trim((string)$options['exclude-phone']) : null;

try {
    $pdo = Database::getConnection();
    echo "[✓] Connected to Database (Driver: " . Database::getDriver() . ")\n";

    if (!empty($excludePhone) && $excludeId === null) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$excludePhone]);
        $excludeId = (int)$stmt->fetchColumn() ?: null;
        if ($excludeId) {
            echo "[*] Excluded hacker user ID #{$excludeId} (phone: {$excludePhone}) from un-suspend.\n";
        }
    }

    $res = AdminManager::emergencyUnsuspendAll(1, $excludeId);

    echo "\n[✓] {$res['message']}\n";
    echo "    Total Users Un-suspended: {$res['unsuspended_count']}\n\n";
    exit(0);
} catch (Exception $e) {
    echo "\n[ERROR] Emergency un-suspend failed: " . $e->getMessage() . "\n";
    exit(1);
}
