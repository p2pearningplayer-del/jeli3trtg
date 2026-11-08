<?php
/**
 * CLI Tool & Cron Job: Monitor Master USDT (TRC-20) Address Deposits
 * 
 * Periodically monitors incoming TRC-20 transfers to TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV.
 * Matches incoming transactions with pending deposits and enriches on-chain verification data.
 * 
 * Usage:
 *   php bin/monitor_master_usdt_deposits.php [--limit=50]
 */

declare(strict_types=1);

if (php_sapi_name() === 'cli' && !getenv('ALLOW_SQLITE_CLI_FALLBACK')) {
    putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Crypto/CryptoDepositManager.php';

SchemaInstaller::ensureSchema();

echo "====================================================================\n";
echo " JELIPAY - MASTER USDT (TRC-20) DEPOSIT MONITOR\n";
echo " Master Address: " . \Crypto\CryptoDepositManager::getMasterWallet() . "\n";
echo "====================================================================\n\n";

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, min(100, (int)$options['limit'])) : 25;

try {
    echo "[*] Querying Tron blockchain explorer for incoming USDT transfers...\n";
    $result = \Crypto\CryptoDepositManager::monitorMasterAddressIncomingTransactions($limit);

    if (empty($result['success'])) {
        echo "[!] Monitor query returned notice: " . ($result['error'] ?? 'API unavailable') . "\n";
        exit(0);
    }

    echo "[✓] Scan completed at {$result['timestamp']}\n";
    echo "    Total Transfers Scanned  : {$result['total_transfers_scanned']}\n";
    echo "    Matched Pending Deposits : " . count($result['matched_pending']) . "\n";
    echo "    Unassociated Transfers   : " . count($result['unassociated_transfers']) . "\n\n";

    if (!empty($result['matched_pending'])) {
        echo "--- Matched Pending Deposits ---\n";
        foreach ($result['matched_pending'] as $m) {
            echo "  [✓] Deposit #{$m['deposit_id']} (User #{$m['user_id']}): {$m['amount_usdt']} USDT | TXID: {$m['txid']} (From: {$m['from_address']})\n";
        }
        echo "\n";
    }

    if (!empty($result['unassociated_transfers'])) {
        echo "--- Recent Unassociated Incoming Transfers ---\n";
        foreach (array_slice($result['unassociated_transfers'], 0, 10) as $u) {
            echo "  [-] {$u['amount_usdt']} USDT from {$u['from_address']} | TXID: {$u['txid']}\n";
        }
        echo "\n";
    }

    echo "[✓] Deposit monitor finished.\n";
    exit(0);
} catch (Exception $e) {
    echo "[ERROR] Monitor error: " . $e->getMessage() . "\n";
    exit(1);
}
