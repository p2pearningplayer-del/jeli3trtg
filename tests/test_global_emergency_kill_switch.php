<?php
/**
 * Test Suite: Global Emergency Kill Switch (Cancel All Active Orders)
 * Tests platform-wide mass cancellation of all active/pending buy and sell orders,
 * immediate refund of locked tokens to users' Available Balances,
 * strict Super Admin role security, and strict confirmation phrase validation.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/UpiManager.php';

SchemaInstaller::ensureSchema();

$passed = 0;
$failed = 0;

function assertCondition(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "====================================================================\n";
echo " JELIPAY - GLOBAL EMERGENCY KILL SWITCH TEST SUITE\n";
echo "====================================================================\n\n";

$pdo = Database::getConnection();

// 1. Setup Admins (Super Admin and Restricted Sub-Admin)
$suffix = time() . '_' . mt_rand(100, 999);
$superAdminPhone = '99' . mt_rand(10000000, 99999999);
$orderAdminPhone = '98' . mt_rand(10000000, 99999999);
$hash = password_hash('Pass1234!', PASSWORD_BCRYPT);
$tpin = password_hash('1234', PASSWORD_BCRYPT);

$insAdmin = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, tpin_hash, role, admin_role, referral_code, upi_id, account_holder_name, account_status)
    VALUES (?, ?, ?, ?, 'admin', 'super_admin', ?, ?, 'Super Administrator', 'active')
");
$insAdmin->execute([$superAdminPhone, "superadmin_{$suffix}@gmail.com", $hash, $tpin, "SA{$suffix}", "sa_{$suffix}@upi"]);
$superAdminId = (int)$pdo->lastInsertId();

$insSubAdmin = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, tpin_hash, role, admin_role, referral_code, upi_id, account_holder_name, account_status)
    VALUES (?, ?, ?, ?, 'admin', 'order_manager', ?, ?, 'Order Manager Admin', 'active')
");
$insSubAdmin->execute([$orderAdminPhone, "ordermanager_{$suffix}@gmail.com", $hash, $tpin, "OM{$suffix}", "om_{$suffix}@upi"]);
$orderManagerId = (int)$pdo->lastInsertId();

// Setup Users
$sellerPhone1 = '97' . mt_rand(10000000, 99999999);
$sellerPhone2 = '97' . mt_rand(10000000, 99999999);
$buyerPhone1  = '96' . mt_rand(10000000, 99999999);
$buyerPhone2  = '96' . mt_rand(10000000, 99999999);

$seller1Reg = Auth::register($sellerPhone1, 'Pass1234!', null, "seller1_{$suffix}@okhdfcbank", 'Seller Alpha', "seller1_{$suffix}@gmail.com", true);
$seller1Id = (int)$seller1Reg['user_id'];
Auth::setTPIN($seller1Id, '1234');

$seller2Reg = Auth::register($sellerPhone2, 'Pass1234!', null, "seller2_{$suffix}@oksbi", 'Seller Beta', "seller2_{$suffix}@gmail.com", true);
$seller2Id = (int)$seller2Reg['user_id'];
Auth::setTPIN($seller2Id, '1234');

$buyer1Reg = Auth::register($buyerPhone1, 'Pass1234!', null, "buyer1_{$suffix}@paytm", 'Buyer Charlie', "buyer1_{$suffix}@gmail.com", true);
$buyer1Id = (int)$buyer1Reg['user_id'];
Auth::setTPIN($buyer1Id, '1234');

$buyer2Reg = Auth::register($buyerPhone2, 'Pass1234!', null, "buyer2_{$suffix}@paytm", 'Buyer Delta', "buyer2_{$suffix}@gmail.com", true);
$buyer2Id = (int)$buyer2Reg['user_id'];
Auth::setTPIN($buyer2Id, '1234');

// Fund Wallets
Wallet::adminAdjust($seller1Id, 'credit', 5000.00, 'Initial Seller 1 Funding', $superAdminId);
Wallet::adminAdjust($seller2Id, 'credit', 3000.00, 'Initial Seller 2 Funding', $superAdminId);
Wallet::adminAdjust($buyer1Id, 'credit', 1000.00, 'Initial Buyer 1 Funding', $superAdminId);

echo "--- SECTION 1: Super Admin Role Security & Authorization Verification ---\n";
// Non-admin user cannot access kill switch
$normalUser = ['id' => $seller1Id, 'role' => 'user', 'admin_role' => null];
assertCondition(!AdminManager::checkPermission($normalUser, 'kill_switch_execute'), "Regular user denied kill switch execute permission");

// Sub-admin (order_manager) cannot execute kill switch
$subAdminUser = ['id' => $orderManagerId, 'role' => 'admin', 'admin_role' => 'order_manager'];
assertCondition(!AdminManager::checkPermission($subAdminUser, 'kill_switch_execute'), "order_manager admin denied kill switch execute permission");

// Support Agent denied kill switch
$supportAgent = ['id' => 999, 'role' => 'admin', 'admin_role' => 'support_agent'];
assertCondition(!AdminManager::checkPermission($supportAgent, 'kill_switch_execute'), "support_agent admin denied kill switch execute permission");

// Finance Manager denied kill switch
$financeManager = ['id' => 998, 'role' => 'admin', 'admin_role' => 'finance_manager'];
assertCondition(!AdminManager::checkPermission($financeManager, 'kill_switch_execute'), "finance_manager admin denied kill switch execute permission");

// Super Admin granted kill switch
$superAdmin = ['id' => $superAdminId, 'role' => 'admin', 'admin_role' => 'super_admin'];
assertCondition(AdminManager::checkPermission($superAdmin, 'kill_switch_execute') === true, "super_admin granted kill switch execute permission");

echo "\n--- SECTION 2: Marketplace Order Creation & Token Locking Across Multiple Users ---\n";
// Seller 1 creates sell order for ₹1500 (splits into portions: e.g. 500, 500, 500)
// Using requireApproval = false to simulate active live marketplace listing
$sellOrder1 = OrderEngine::createSellOrder($seller1Id, 1500.00, '1234', 'Seller Alpha', "seller1_{$suffix}@okhdfcbank", false);
assertCondition($sellOrder1['success'] === true, "Seller 1 listed ₹1,500 on active marketplace");
$parent1Id = (int)$sellOrder1['parent_order_id'];

// Check seller 1 wallet balances
$w1 = Wallet::getWallet($seller1Id);
assertCondition((float)$w1['available_balance'] === 3500.00, "Seller 1 available balance deducted by ₹1,500 (now ₹3,500)");
assertCondition((float)$w1['reserved_balance'] === 1500.00, "Seller 1 reserved balance increased by ₹1,500 (now ₹1,500)");

// Fetch child orders of parent 1
$cStmt = $pdo->prepare("SELECT id, amount, status FROM child_orders WHERE parent_order_id = ? ORDER BY id ASC");
$cStmt->execute([$parent1Id]);
$children1 = $cStmt->fetchAll(PDO::FETCH_ASSOC);
assertCondition(count($children1) >= 2, "Parent 1 divided into multiple micro-portions (count: " . count($children1) . ")");

$childOrder1 = (int)$children1[0]['id'];
$childOrder2 = (int)$children1[1]['id'];

// Buyer 1 picks childOrder1 -> transitions to 'Reserved'
$pick1 = StateMachine::pickOrder($childOrder1, $buyer1Id);
assertCondition($pick1['success'] === true, "Buyer 1 picked portion #{$childOrder1} ('Reserved')");

// Buyer 1 submits payment proof -> transitions to 'Payment Proof Submitted'
$proofStmt = $pdo->prepare("UPDATE child_orders SET status = 'Payment Proof Submitted' WHERE id = ?");
$proofStmt->execute([$childOrder1]);
assertCondition($proofStmt->rowCount() === 1, "Portion #{$childOrder1} moved to 'Payment Proof Submitted'");

// Buyer 2 picks childOrder2 -> transitions to 'Reserved'
$pick2 = StateMachine::pickOrder($childOrder2, $buyer2Id);
assertCondition($pick2['success'] === true, "Buyer 2 picked portion #{$childOrder2} ('Reserved')");

// Seller 2 creates a sell order for ₹1,000 waiting for admin approval ('pending_approval')
$sellOrder2 = OrderEngine::createSellOrder($seller2Id, 1000.00, '1234', 'Seller Beta', "seller2_{$suffix}@oksbi", true);
assertCondition($sellOrder2['success'] === true, "Seller 2 created sell order for ₹1,000 (status: pending_approval)");
$parent2Id = (int)$sellOrder2['parent_order_id'];

// Check seller 2 wallet balances
$w2 = Wallet::getWallet($seller2Id);
assertCondition((float)$w2['available_balance'] === 2000.00, "Seller 2 available balance deducted by ₹1,000 (now ₹2,000)");
assertCondition((float)$w2['reserved_balance'] === 1000.00, "Seller 2 reserved balance increased by ₹1,000 (now ₹1,000)");

// Verify overall platform locked balance includes our test sellers
$totalLockedBefore = (float)$pdo->query("SELECT SUM(reserved_balance) FROM wallets WHERE reserved_balance > 0")->fetchColumn();
assertCondition($totalLockedBefore >= 2500.00, "Total platform locked reserved tokens is at least ₹2,500.00 (actual: ₹{$totalLockedBefore})");

echo "\n--- SECTION 3: Live Kill Switch Statistics Pre-Flight Verification ---\n";
$stats = AdminManager::getEmergencyKillSwitchStats();
assertCondition($stats['active_parent_orders'] >= 2, "Stats reports active parent sell orders: " . $stats['active_parent_orders']);
assertCondition($stats['active_child_orders'] >= 3, "Stats reports active child orders: " . $stats['active_child_orders']);
assertCondition($stats['users_with_locked_tokens'] >= 2, "Stats reports users with locked tokens: " . $stats['users_with_locked_tokens']);
assertCondition($stats['total_reserved_tokens'] >= 2500.00, "Stats reports total locked tokens: ₹" . number_format($stats['total_reserved_tokens'], 2));

echo "\n--- SECTION 4: Super Admin Executes Global Emergency Kill Switch ---\n";
$killSwitchResult = AdminManager::emergencyCancelAllOrders($superAdminId, "Emergency drill test: platform-wide order cancellation");

assertCondition($killSwitchResult['success'] === true, "Emergency Kill Switch executed successfully");
assertCondition($killSwitchResult['summary']['cancelled_parent_orders'] >= 2, "Parent orders cancelled: " . $killSwitchResult['summary']['cancelled_parent_orders']);
assertCondition($killSwitchResult['summary']['cancelled_child_orders'] >= 3, "Child micro-orders cancelled: " . $killSwitchResult['summary']['cancelled_child_orders']);
assertCondition($killSwitchResult['summary']['sellers_refunded'] >= 2, "Sellers refunded count: " . $killSwitchResult['summary']['sellers_refunded']);
assertCondition($killSwitchResult['summary']['total_tokens_refunded'] >= 2500.00, "Exact locked tokens refunded: ₹" . number_format($killSwitchResult['summary']['total_tokens_refunded'], 2));
assertCondition($killSwitchResult['summary']['buyers_notified'] >= 2, "Active buyers notified: " . $killSwitchResult['summary']['buyers_notified']);

echo "\n--- SECTION 5: Post-Kill-Switch State & Balances Verification ---\n";
// 1. Parent orders status
$p1Status = $pdo->query("SELECT status, remaining_amount, reserved_amount FROM parent_sell_orders WHERE id = {$parent1Id}")->fetch(PDO::FETCH_ASSOC);
assertCondition(strtolower($p1Status['status']) === 'cancelled', "Parent Order 1 transitioned to 'cancelled'");
assertCondition((float)$p1Status['remaining_amount'] === 0.00, "Parent Order 1 remaining_amount set to 0.00");
assertCondition((float)$p1Status['reserved_amount'] === 0.00, "Parent Order 1 reserved_amount set to 0.00");

$p2Status = $pdo->query("SELECT status, remaining_amount, reserved_amount FROM parent_sell_orders WHERE id = {$parent2Id}")->fetch(PDO::FETCH_ASSOC);
assertCondition(strtolower($p2Status['status']) === 'cancelled', "Parent Order 2 transitioned to 'cancelled'");

// 2. Child orders status
$openChildCount = (int)$pdo->query("
    SELECT COUNT(*) FROM child_orders
    WHERE LOWER(status) IN ('available', 'pending approval', 'reserved', 'payment proof submitted', 'seller confirmation pending', 'seller response timeout', 'disputed')
")->fetchColumn();
assertCondition($openChildCount === 0, "Zero active child orders remain across entire platform");

$c1Status = $pdo->query("SELECT status, cancellation_reason_code FROM child_orders WHERE id = {$childOrder1}")->fetch(PDO::FETCH_ASSOC);
assertCondition($c1Status['status'] === 'Cancelled', "In-flight child order #{$childOrder1} transitioned to 'Cancelled'");
assertCondition($c1Status['cancellation_reason_code'] === 'admin_emergency_kill_switch', "Cancellation reason code recorded as 'admin_emergency_kill_switch'");

// 3. User Wallets Unlocked & Refunded
$w1After = Wallet::getWallet($seller1Id);
assertCondition((float)$w1After['reserved_balance'] === 0.00, "Seller 1 reserved_balance fully restored to 0.00");
assertCondition((float)$w1After['available_balance'] === 5000.00, "Seller 1 available_balance fully refunded to ₹5,000.00");

$w2After = Wallet::getWallet($seller2Id);
assertCondition((float)$w2After['reserved_balance'] === 0.00, "Seller 2 reserved_balance fully restored to 0.00");
assertCondition((float)$w2After['available_balance'] === 3000.00, "Seller 2 available_balance fully refunded to ₹3,000.00");

// Zero users on platform have reserved_balance > 0
$totalReservedRemaining = (float)$pdo->query("SELECT COALESCE(SUM(reserved_balance), 0) FROM wallets WHERE reserved_balance > 0")->fetchColumn();
assertCondition($totalReservedRemaining === 0.00, "Platform total reserved tokens is exactly 0.00");

// 4. Wallet Ledger Verification
$ledgerCount1 = (int)$pdo->query("
    SELECT COUNT(*) FROM wallet_ledger
    WHERE user_id = {$seller1Id} AND transaction_type = 'SELL_RELEASE' AND amount = 1500.00
")->fetchColumn();
assertCondition($ledgerCount1 >= 1, "Seller 1 wallet_ledger recorded SELL_RELEASE entry for ₹1,500.00 refund");

$ledgerCount2 = (int)$pdo->query("
    SELECT COUNT(*) FROM wallet_ledger
    WHERE user_id = {$seller2Id} AND transaction_type = 'SELL_RELEASE' AND amount = 1000.00
")->fetchColumn();
assertCondition($ledgerCount2 >= 1, "Seller 2 wallet_ledger recorded SELL_RELEASE entry for ₹1,000.00 refund");

// 5. Notifications
$sellerNotifCount = (int)$pdo->query("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = {$seller1Id} AND title LIKE '%Refunded%'
")->fetchColumn();
assertCondition($sellerNotifCount >= 1, "Seller 1 received refund notification");

$buyerNotifCount = (int)$pdo->query("
    SELECT COUNT(*) FROM notifications
    WHERE user_id = {$buyer1Id} AND title LIKE '%Order Cancelled%'
")->fetchColumn();
assertCondition($buyerNotifCount >= 1, "Buyer 1 received order cancellation notification");

// 6. Admin Audit Log Verification
$auditLog = $pdo->query("
    SELECT * FROM admin_audit_logs
    WHERE action_type = 'GLOBAL_EMERGENCY_KILL_SWITCH_CANCEL_ALL'
    ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($auditLog), "Admin audit log entry recorded for GLOBAL_EMERGENCY_KILL_SWITCH_CANCEL_ALL");
assertCondition((int)$auditLog['admin_id'] === $superAdminId, "Audit log records Super Admin as executor");

echo "\n--- SECTION 6: Idempotent Execution Check (Queue Already Clean) ---\n";
$idempotentResult = AdminManager::emergencyCancelAllOrders($superAdminId, "Second kill switch run");
assertCondition($idempotentResult['success'] === true, "Repeated kill switch run succeeds gracefully");
assertCondition($idempotentResult['summary']['cancelled_parent_orders'] === 0, "Zero parent orders to cancel on rerun");
assertCondition($idempotentResult['summary']['cancelled_child_orders'] === 0, "Zero child orders to cancel on rerun");
assertCondition($idempotentResult['summary']['total_tokens_refunded'] === 0.0, "Zero tokens refunded on clean queue");

echo "\n--- SECTION 7: API Security, Lockdown & UI Safety Net Verification ---\n";
$adminApiCode = (string)file_get_contents(__DIR__ . '/../api/admin.php');
assertCondition(strpos($adminApiCode, "'emergency_cancel_all_orders'") !== false, "api/admin.php includes emergency_cancel_all_orders in support mode stateModifyingActions lockdown");
assertCondition(strpos($adminApiCode, "case 'emergency_cancel_all_orders':") !== false, "api/admin.php defines emergency_cancel_all_orders case");
assertCondition(strpos($adminApiCode, "case 'kill_switch_stats':") !== false, "api/admin.php defines kill_switch_stats case");
assertCondition(strpos($adminApiCode, "\$confirmPhrase !== 'CANCEL ALL ORDERS'") !== false, "api/admin.php enforces strict double-confirmation phrase 'CANCEL ALL ORDERS'");
assertCondition(strpos($adminApiCode, "\$callerRole !== 'super_admin'") !== false, "api/admin.php strictly restricts kill switch to Super Admin role only");

$adminPhpCode = (string)file_get_contents(__DIR__ . '/../admin.php');
assertCondition(strpos($adminPhpCode, 'Emergency Platform Kill Switch') !== false, "admin.php contains Emergency Platform Kill Switch banner");
assertCondition(strpos($adminPhpCode, 'Cancel ALL Active Orders') !== false, "admin.php contains 'Cancel ALL Active Orders' button");
assertCondition(strpos($adminPhpCode, 'id="globalEmergencyKillSwitchModal"') !== false, "admin.php contains globalEmergencyKillSwitchModal");
assertCondition(strpos($adminPhpCode, 'id="killSwitchStep1"') !== false && strpos($adminPhpCode, 'id="killSwitchStep2"') !== false, "admin.php modal provides 2-step double-confirmation workflow");
assertCondition(strpos($adminPhpCode, 'id="killSwitchPhraseInput"') !== false, "admin.php modal contains phrase authorization input");

$adminJsCode = (string)file_get_contents(__DIR__ . '/../public/js/admin.js');
assertCondition(strpos($adminJsCode, 'function openEmergencyKillSwitchModal') !== false, "admin.js implements openEmergencyKillSwitchModal()");
assertCondition(strpos($adminJsCode, 'function checkKillSwitchPhrase') !== false, "admin.js implements checkKillSwitchPhrase() validation");
assertCondition(strpos($adminJsCode, 'function submitEmergencyKillSwitch') !== false, "admin.js implements submitEmergencyKillSwitch()");
assertCondition(strpos($adminJsCode, 'confirm("Are you sure you want to cancel ALL orders on the entire platform? This cannot be undone.")') !== false, "admin.js executes strict double confirmation popup");

echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED (" . round(($passed / max(1, $passed + $failed)) * 100) . "%)\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
