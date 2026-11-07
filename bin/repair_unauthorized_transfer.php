<?php
/**
 * CLI Tool: Repair Unauthorized Token Transfer & Security Audit
 * Target Phone: 9610510173
 * Usage: php bin/repair_unauthorized_transfer.php [--phone=9610510173] [--order=123] [--dry-run]
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/AgentManager.php';

echo "====================================================================\n";
echo " JELIPAY P2P - SECURITY AUDIT & UNAUTHORIZED TRANSFER RESOLUTION\n";
echo " Target Phone: 9610510173\n";
echo "====================================================================\n\n";

// Parse CLI options
$options = getopt('', ['phone::', 'order::', 'dry-run']);
$targetPhone = $options['phone'] ?? '9610510173';
$specificOrderId = isset($options['order']) ? (int)$options['order'] : null;
$isDryRun = isset($options['dry-run']);

try {
    $pdo = Database::getConnection();
    echo "[✓] Connected to Database (Driver: " . Database::getDriver() . ")\n\n";

    // 1. Locate user
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE phone = ? LIMIT 1");
    $uStmt->execute([$targetPhone]);
    $user = $uStmt->fetch();

    if (!$user) {
        echo "[!] User with phone {$targetPhone} was NOT found in the database.\n";
        exit(1);
    }

    $userId = (int)$user['id'];
    echo "--- Target User Profile ---\n";
    echo "  User ID        : #{$userId}\n";
    echo "  Phone Number   : {$user['phone']}\n";
    echo "  Account Status : {$user['account_status']}\n";
    echo "  Health Score   : " . ($user['health_score'] ?? 100) . " / 100\n";
    echo "  Holder Name    : " . ($user['account_holder_name'] ?? 'N/A') . "\n";
    echo "  UPI ID         : " . ($user['upi_id'] ?? 'N/A') . "\n\n";

    // 2. Fetch Wallet
    $wallet = Wallet::getWallet($userId);
    echo "--- Wallet Balances ---\n";
    echo "  Available Balance : ₹" . number_format((float)$wallet['available_balance'], 2) . "\n";
    echo "  Reserved Balance  : ₹" . number_format((float)$wallet['reserved_balance'], 2) . "\n";
    echo "  Total Rewards     : ₹" . number_format((float)$wallet['total_rewards_earned'], 2) . "\n\n";

    // 3. Fetch Orders
    $ordersStmt = $pdo->prepare("
        SELECT c.*, ps.original_amount as parent_amount, ps.status as parent_status
        FROM child_orders c
        LEFT JOIN parent_sell_orders ps ON c.parent_order_id = ps.id
        WHERE c.seller_id = ?
        ORDER BY c.id DESC
    ");
    $ordersStmt->execute([$userId]);
    $orders = $ordersStmt->fetchAll();

    echo "--- Seller Orders (Found " . count($orders) . ") ---\n";
    if (empty($orders)) {
        echo "  No seller orders found for this account.\n";
    }

    $targetOrder = null;
    foreach ($orders as $o) {
        $flag = '';
        if ($specificOrderId && (int)$o['id'] === $specificOrderId) {
            $flag = ' <-- [SPECIFIED TARGET]';
            $targetOrder = $o;
        } elseif (!$specificOrderId && $o['status'] === 'Completed' && !$targetOrder) {
            $flag = ' <-- [CANDIDATE FOR REVERSAL]';
            $targetOrder = $o;
        }
        echo "  Order #{$o['id']} | Status: {$o['status']} | Amount: ₹{$o['amount']} | Buyer ID: {$o['buyer_id']} | Completed: " . ($o['completed_at'] ?? 'N/A') . "{$flag}\n";
    }
    echo "\n";

    if (!$targetOrder) {
        echo "[✓] No stuck completed orders requiring reversal were detected for user {$targetPhone}.\n";
        exit(0);
    }

    $childOrderId = (int)$targetOrder['id'];
    $orderAmount = (float)$targetOrder['amount'];
    echo ">>> Target Order Selected: #{$childOrderId} for ₹" . number_format($orderAmount, 2) . " <<<\n";

    if ($isDryRun) {
        echo "[DRY RUN] Would reverse Order #{$childOrderId}, debit buyer #{$targetOrder['buyer_id']}, and restore ₹" . number_format($orderAmount, 2) . " to seller #{$userId}.\n";
        exit(0);
    }

    // Execute reversal using AdminManager
    echo "Executing reversal of Order #{$childOrderId}...\n";
    $adminId = 1; // Super Admin actor
    $res = AdminManager::reverseUnauthorizedOrder(
        $childOrderId,
        $adminId,
        "Automated CLI resolution for user {$targetPhone}: unauthorized token release without T-PIN"
    );

    if ($res['success']) {
        echo "\n[SUCCESS] {$res['message']}\n";
        $newWallet = Wallet::getWallet($userId);
        echo "  Updated Available Balance: ₹" . number_format((float)$newWallet['available_balance'], 2) . "\n";
        echo "  Updated Reserved Balance : ₹" . number_format((float)$newWallet['reserved_balance'], 2) . "\n";
        echo "Transaction reversed and audit log permanently saved.\n";
    } else {
        echo "\n[ERROR] Failed to reverse order: {$res['error']}\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "\n[FATAL] " . $e->getMessage() . "\n";
    exit(1);
}
