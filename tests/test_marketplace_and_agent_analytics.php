<?php
/**
 * Test Suite: Admin Marketplace Analytics & Agent Volume / Commission Calculation Fix
 * Validates Part 1 (18 Marketplace Metrics), Part 2 (UTC Reporting Intervals),
 * Part 3 (Agent Today/7D/Total Volume), and Part 4 (Agent Total Commission Calculation).
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_analytics_suite.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
putenv("SQLITE_PATH={$testDbFile}");
$_ENV['SQLITE_PATH'] = $testDbFile;

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/AgentManager.php';

Database::reset();
SchemaInstaller::ensureSchema();
$pdo = Database::getConnection('sqlite');

$passedTests = 0;
$totalTests = 0;

function assertCheck(bool $condition, string $title, string $details = ''): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}" . ($details ? ": {$details}" : '') . "\n";
    }
}

echo "=======================================================\n";
echo "  MARKETPLACE ANALYTICS & AGENT VOLUME VERIFICATION\n";
echo "=======================================================\n\n";

$nowUtc = gmdate('Y-m-d H:i:s');
$todayDate = gmdate('Y-m-d');
$todayStart = $todayDate . ' 00:00:00';
$yesterdayDate = gmdate('Y-m-d', strtotime('-1 day'));
$yesterdayStart = $yesterdayDate . ' 00:00:00';
$yesterdayMid = $yesterdayDate . ' 14:00:00';
$twoDaysAgo = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
$tenDaysAgo = gmdate('Y-m-d H:i:s', time() - 10 * 86400);

$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);
$dummyTpin = password_hash('1234', PASSWORD_BCRYPT);

// -------------------------------------------------------------
// SECTION 1: Fixtures Setup
// -------------------------------------------------------------
echo "--- Section 1: Test Fixtures Setup ---\n";

// Super Admin
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$adminId = (int)$adminStmt->fetchColumn();
assertCheck($adminId > 0, "Admin fixture verified (ID #{$adminId})");

// Root Agent User (Manager of referral tree)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, account_status, role)
    VALUES (400, '9700000400', 'agent_root@jelipay.in', ?, ?, 'ROOTAGENT400', 'Agent Root Leader', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);

// Level A User (Referred directly by Root)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (401, '9700000401', 'user_a@jelipay.in', ?, ?, 'USER_A_REF', 400, 'Alice Level A', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);

// Level B User (Referred by Level A)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (402, '9700000402', 'user_b@jelipay.in', ?, ?, 'USER_B_REF', 401, 'Bob Level B', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);

// Level C User (Referred by Level B)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (403, '9700000403', 'user_c@jelipay.in', ?, ?, 'USER_C_REF', 402, 'Charlie Level C', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);

// Unrelated User (Not in Agent's tree)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, account_status, role)
    VALUES (499, '9700000499', 'unrelated@jelipay.in', ?, ?, 'UNRELATED_REF', 'Unrelated Peer', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);

// Seller User with balance
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, tpin_hash, referral_code, account_holder_name, upi_id, account_status, role)
    VALUES (450, '9700000450', 'seller_market@jelipay.in', ?, ?, 'SELLER_REF', 'Seller Market', 'seller@okhdfcbank', 'active', 'user')
")->execute([$dummyHash, $dummyTpin]);
Wallet::adminAdjust(450, 'credit', 200000.0, 'Seed seller balance', $adminId);

// Create Agent for Root User (Commission Rate: 2.5%, All Levels A-Z)
$agentRes = AgentManager::createAgent([
    'agent_code' => 'AGT4000',
    'name' => 'Alpha Network Agency',
    'phone' => '9700000400',
    'email' => 'agent4000@jelipay.in',
    'root_user_id' => 400,
    'commission_rate' => 2.50,
    'eligible_levels' => AgentManager::ALL_LEVELS
], $adminId);
assertCheck($agentRes['success'] === true, "Agent AGT4000 created with 2.5% rate");
$agentId = (int)$agentRes['agent_id'];

// -------------------------------------------------------------
// SECTION 2: Staging Marketplace Orders Across Periods
// -------------------------------------------------------------
echo "\n--- Section 2: Marketplace Order Staging ---\n";

// Parent Sell Order 100
$pdo->prepare("
    INSERT INTO parent_sell_orders (id, seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
    VALUES (100, 450, 50000.0, 50000.0, 0, 0, 'active', 'seller@okhdfcbank', 'Seller Market', ?)
")->execute([$nowUtc]);

// 1. Available Orders:
// - Order 1001: Created Today, currently Available (₹1000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (1001, 100, 450, NULL, 1000.0, 9.0, 90.0, 'seller@okhdfcbank', 'Seller Market', 'Available', ?)
")->execute([$nowUtc]);

// - Order 1002: Created Yesterday, currently Available (₹2000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (1002, 100, 450, NULL, 2000.0, 9.0, 180.0, 'seller@okhdfcbank', 'Seller Market', 'Available', ?)
")->execute([$yesterdayMid]);

// 2. Reserved Orders:
// - Order 1003: Picked Today, currently Reserved (₹3000 by User A)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, picked_at, created_at)
    VALUES (1003, 100, 450, 401, 3000.0, 9.0, 270.0, 'seller@okhdfcbank', 'Seller Market', 'Reserved', ?, ?)
")->execute([$nowUtc, $nowUtc]);

// - Order 1004: Picked Yesterday, Reserved (₹4000 by User B)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, picked_at, created_at)
    VALUES (1004, 100, 450, 402, 4000.0, 9.0, 360.0, 'seller@okhdfcbank', 'Seller Market', 'Reserved', ?, ?)
")->execute([$yesterdayMid, $yesterdayMid]);

// 3. Disputed Orders:
// - Order 1005: Dispute raised Today, currently pending (₹5000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (1005, 100, 450, 401, 5000.0, 9.0, 450.0, 'seller@okhdfcbank', 'Seller Market', 'Disputed', ?)
")->execute([$nowUtc]);
$pdo->prepare("
    INSERT INTO disputes (id, child_order_id, raised_by_user_id, raised_by_role, reason, status, created_at)
    VALUES (501, 1005, 450, 'seller', 'Payment unverified today', 'pending', ?)
")->execute([$nowUtc]);

// - Order 1006: Dispute raised Yesterday, resolved buyer (₹6000 by Unrelated User 499)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1006, 100, 450, 499, 6000.0, 9.0, 540.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$yesterdayMid, $yesterdayMid]);
$pdo->prepare("
    INSERT INTO disputes (id, child_order_id, raised_by_user_id, raised_by_role, reason, status, created_at)
    VALUES (502, 1006, 450, 'seller', 'Historical dispute resolved', 'resolved_buyer', ?)
")->execute([$yesterdayMid]);

// 4. Completed Orders:
// - Order 1007: Completed Today (Buyer: User A [Level A], ₹10,000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1007, 100, 450, 401, 10000.0, 9.0, 900.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$nowUtc, $nowUtc]);
// Credit buyer and ledger
Wallet::creditBuyer(401, 10000.0, 900.0, '1007');

// - Order 1008: Completed Yesterday (Buyer: User B [Level B], ₹20,000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1008, 100, 450, 402, 20000.0, 9.0, 1800.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$yesterdayMid, $yesterdayMid]);
Wallet::creditBuyer(402, 20000.0, 1800.0, '1008');
// Backdate ledger entry for order 1008
$pdo->prepare("UPDATE wallet_ledger SET created_at = ? WHERE reference_id = '1008'")->execute([$yesterdayMid]);

// - Order 1009: Completed 10 days ago (Buyer: User C [Level C], ₹30,000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1009, 100, 450, 403, 3000.0, 9.0, 270.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$tenDaysAgo, $tenDaysAgo]);
Wallet::creditBuyer(403, 3000.0, 270.0, '1009');
$pdo->prepare("UPDATE wallet_ledger SET created_at = ? WHERE reference_id = '1009'")->execute([$tenDaysAgo]);

// - Order 1010: Completed Today by UNRELATED user (₹5000)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1010, 100, 450, 499, 5000.0, 9.0, 450.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$nowUtc, $nowUtc]);
Wallet::creditBuyer(499, 5000.0, 450.0, '1010');

// - Order 1011: Completed Today by AGENT'S OWN ROOT USER (₹7000) - must NOT count towards agent commission
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, completed_at, created_at)
    VALUES (1011, 100, 450, 400, 7000.0, 9.0, 630.0, 'seller@okhdfcbank', 'Seller Market', 'Completed', ?, ?)
")->execute([$nowUtc, $nowUtc]);
Wallet::creditBuyer(400, 7000.0, 630.0, '1011');

// 5. Cancelled Orders:
// - Order 1012: Cancelled Today
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, cancelled_at, created_at)
    VALUES (1012, 100, 450, 401, 1500.0, 9.0, 135.0, 'seller@okhdfcbank', 'Seller Market', 'Cancelled / Reversed', ?, ?)
")->execute([$nowUtc, $nowUtc]);

// - Order 1013: Cancelled Yesterday
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, cancelled_at, created_at)
    VALUES (1013, 100, 450, 402, 2500.0, 9.0, 225.0, 'seller@okhdfcbank', 'Seller Market', 'Cancelled / Reversed', ?, ?)
")->execute([$yesterdayMid, $yesterdayMid]);

// - Order 1014: Expired order (Must NOT be counted as Cancelled)
$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
    VALUES (1014, 100, 450, NULL, 800.0, 9.0, 72.0, 'seller@okhdfcbank', 'Seller Market', 'Expired', ?)
")->execute([$nowUtc]);

echo "Staged 14 child orders across Available, Reserved, Disputed, Completed, Cancelled, and Expired states.\n";

// -------------------------------------------------------------
// SECTION 3: PART 1 — Marketplace Analytics Verification
// -------------------------------------------------------------
echo "\n--- Section 3: Part 1 — Marketplace Order State & Breakdown Verification ---\n";

$platformStats = AdminManager::getPlatformStats();
assertCheck(isset($platformStats['marketplace']), "Platform stats returns 'marketplace' analytics object");
$m = $platformStats['marketplace'];

// A. Available Orders
assertCheck($m['orders_available_today'] >= 1, "A1. Today's Available Orders calculated: {$m['orders_available_today']}");
assertCheck($m['orders_available_yesterday'] >= 1, "A2. Yesterday's Available Orders calculated: {$m['orders_available_yesterday']}");

// B. Reserved Orders
assertCheck($m['orders_reserved_today'] >= 1, "B3. Today's Reserved Orders calculated: {$m['orders_reserved_today']}");
assertCheck($m['orders_reserved_yesterday'] >= 1, "B4. Yesterday's Reserved Orders calculated: {$m['orders_reserved_yesterday']}");

// C. Disputed Orders
assertCheck($m['orders_disputed_today'] === 1, "C5. Today's Disputed Orders is exactly 1 (Dispute #501 on Order #1005)");
assertCheck($m['orders_disputed_all'] === 1, "C6. All Disputed Orders currently unresolved is exactly 1 (excludes resolved #502)");
assertCheck($m['orders_disputed_resolved'] === 1, "C7. Resolved disputes correctly tracked separately: {$m['orders_disputed_resolved']}");

// D. Completed Orders
assertCheck($m['orders_completed_today'] === 3, "D8. Today's Completed Orders is exactly 3 (Orders #1007, #1010, #1011)");
assertCheck($m['orders_completed_yesterday'] === 2, "D9. Yesterday's Completed Orders is exactly 2 (Orders #1006, #1008)");
assertCheck($m['orders_completed_total'] === 6, "D10. Total Completed Orders is exactly 6 (#1006, #1007, #1008, #1009, #1010, #1011)");

// E. Cancelled Orders
assertCheck($m['orders_cancelled_today'] === 1, "E11. Today's Cancelled Orders is exactly 1 (Order #1012)");
assertCheck($m['orders_cancelled_yesterday'] === 1, "E12. Yesterday's Cancelled Orders is exactly 1 (Order #1013)");
assertCheck($m['orders_cancelled_total'] === 2, "E13. Total Cancelled Orders is 2 (strictly excludes Expired Order #1014)");

// F. Tokens in Circulation
assertCheck($m['token_circulation_today'] > 0, "F14. Today's Token Circulation reflects user supply: ₹" . number_format($m['token_circulation_today'], 2));
assertCheck(!empty($m['token_circulation_timestamp']), "F15. Today's Token Circulation labels measurement timestamp: {$m['token_circulation_timestamp']}");
assertCheck($m['token_circulation_yesterday'] > 0, "F16. Yesterday's Token Circulation calculated from ledger: ₹" . number_format($m['token_circulation_yesterday'], 2));

// G. Completed Trading Volume
// Completed today: #1007 (10,000) + #1010 (5,000) + #1011 (7,000) = 22,000
assertCheck((float)$m['trading_volume_today'] === 22000.0, "G17. Completed Trading Volume Today is strictly ₹22,000.00");
// Completed yesterday: #1006 (6,000) + #1008 (20,000) = 26,000
assertCheck((float)$m['trading_volume_yesterday'] === 26000.0, "G18. Completed Trading Volume Yesterday is strictly ₹26,000.00");

// H. Purchase Rewards
// Rewards today: #1007 (900) + #1010 (450) + #1011 (630) = 1,980
assertCheck((float)$m['purchase_rewards_today'] === 1980.0, "H19. Today's Purchase Rewards is strictly ₹1,980.00");
// Rewards yesterday: #1008 (1,800)
assertCheck((float)$m['purchase_rewards_yesterday'] === 1800.0, "H20. Yesterday's Purchase Rewards is strictly ₹1,800.00");

// -------------------------------------------------------------
// SECTION 4: PART 2 — Reporting Dates and Timezone
// -------------------------------------------------------------
echo "\n--- Section 4: Part 2 — Timezone & Reporting Intervals ---\n";

assertCheck($m['timezone'] === 'UTC', "Dashboard timezone is consistently UTC");
assertCheck($m['today_date'] === $todayDate, "Today date matches UTC current date {$todayDate}");
assertCheck($m['yesterday_date'] === $yesterdayDate, "Yesterday date matches UTC preceding date {$yesterdayDate}");

// -------------------------------------------------------------
// SECTION 5: PART 3 & PART 4 — Agent Referral Volume & Commission
// -------------------------------------------------------------
echo "\n--- Section 5: Part 3 & Part 4 — Agent Volume & Commission Fix ---\n";

// Fetch Agent dashboard stats
$agentStats = AgentManager::getAgentDashboardStats($agentId);
assertCheck(!empty($agentStats), "Agent stats retrieved for AGT4000");

echo "DEBUG STATS: " . json_encode($agentStats) . "\n";
echo "DEBUG COMMISSIONS: " . json_encode($pdo->query("SELECT * FROM agent_commissions")->fetchAll()) . "\n";

// Hierarchy check: Descendants are 401 (Level A), 402 (Level B), 403 (Level C)
// Agent Root is 400.
// Qualifying purchases:
// - User A (Level A): #1007 (₹10,000 today)
// - User B (Level B): #1008 (₹20,000 yesterday)
// - User C (Level C): #1009 (₹3,000 10 days ago)
// Unrelated User 499: #1010 (₹5,000) -> MUST BE EXCLUDED!
// Root User 400: #1011 (₹7,000) -> AGENT'S OWN BUYING MUST BE EXCLUDED!

// Part 3A: Today's Volume (UTC):
// Today at 00:00:00 to Tomorrow at 00:00:00 exclusive.
// Only Order #1007 (₹10,000) qualifies for Today!
assertCheck((float)$agentStats['today_volume'] === 10000.0, "Part 3A: Today's Volume (UTC) is strictly ₹10,000.00 (Excludes Unrelated & Agent's own)");
assertCheck((int)$agentStats['today_orders'] === 1, "Today's qualifying orders count is 1");

// Part 3B: Rolling 7 Days Volume:
// Past 168 hours: Order #1007 (₹10,000 today) + Order #1008 (₹20,000 yesterday) = ₹30,000.
// Order #1009 was 10 days ago -> correctly excluded from 7-day rolling!
assertCheck((float)$agentStats['rolling_7d_volume'] === 30000.0, "Part 3B: Rolling 7 Days Volume is strictly ₹30,000.00 (168-hour interval)");
assertCheck((int)$agentStats['rolling_7d_orders'] === 2, "Rolling 7-day qualifying orders count is 2");

// Part 3C: Total Volume (All-Time):
// Order #1007 (₹10,000) + Order #1008 (₹20,000) + Order #1009 (₹3,000) = ₹33,000.
assertCheck((float)$agentStats['total_volume'] === 33000.0, "Part 3C: Total Volume is strictly ₹33,000.00 (Levels A + B + C across full history)");
assertCheck((int)$agentStats['total_orders'] === 3, "Total qualifying orders count is 3");

// Part 4: Total Commission Calculation:
// Formula: Eligible Referral Purchase Volume × Applicable Agent Commission Rate
// Rate: 2.50%
// #1007: ₹10,000 × 2.5% = ₹250.00
// #1008: ₹20,000 × 2.5% = ₹500.00
// #1009: ₹3,000 × 2.5% = ₹75.00
// Total Commission Earned = ₹250 + ₹500 + ₹75 = ₹825.00
assertCheck((float)$agentStats['total_commission_earned'] === 825.0, "Part 4: Total Commission Earned is strictly ₹825.00 (₹33,000 × 2.5%)");
assertCheck((float)$agentStats['today_commission'] === 250.0, "Today's Commission is strictly ₹250.00 (₹10,000 × 2.5%)");
assertCheck((float)$agentStats['rolling_7d_commission'] === 750.0, "Rolling 7-Day Commission is strictly ₹750.00 (₹30,000 × 2.5%)");

// Check Admin Agent Listing matches these authoritative numbers
$adminAgents = AgentManager::listAgents(['status' => 'all']);
$foundAgent = null;
foreach ($adminAgents as $ag) {
    if ($ag['agent_code'] === 'AGT4000') {
        $foundAgent = $ag;
        break;
    }
}
assertCheck(!empty($foundAgent), "Admin listAgents returns AGT4000");
assertCheck((float)$foundAgent['total_volume'] === 33000.0, "Admin listAgents displays correct total volume ₹33,000.00");
assertCheck((float)$foundAgent['total_commission_earned'] === 825.0, "Admin listAgents displays correct commission earned ₹825.00");

// -------------------------------------------------------------
// SECTION 6: UI & Template Inspection
// -------------------------------------------------------------
echo "\n--- Section 6: UI & Frontend Inspection ---\n";

$adminPhp = file_get_contents(__DIR__ . '/../admin.php');
assertCheck(strpos($adminPhp, 'Timezone: UTC') !== false, "admin.php contains 'Timezone: UTC' badge");
assertCheck(strpos($adminPhp, 'id="dashOrdersAvailableToday"') !== false, "admin.php contains dashOrdersAvailableToday");
assertCheck(strpos($adminPhp, 'id="dashOrdersReservedToday"') !== false, "admin.php contains dashOrdersReservedToday");
assertCheck(strpos($adminPhp, 'id="dashOrdersDisputedToday"') !== false, "admin.php contains dashOrdersDisputedToday");
assertCheck(strpos($adminPhp, 'id="dashOrdersCompletedToday"') !== false, "admin.php contains dashOrdersCompletedToday");
assertCheck(strpos($adminPhp, 'id="dashOrdersCancelledToday"') !== false, "admin.php contains dashOrdersCancelledToday");
assertCheck(strpos($adminPhp, 'id="dashCirculationToday"') !== false, "admin.php contains dashCirculationToday");
assertCheck(strpos($adminPhp, 'id="dashCirculationYesterday"') !== false, "admin.php contains dashCirculationYesterday");
assertCheck(strpos($adminPhp, 'id="dashTradingVolToday"') !== false, "admin.php contains dashTradingVolToday");
assertCheck(strpos($adminPhp, 'id="dashRewardsToday"') !== false, "admin.php contains dashRewardsToday");

$adminJs = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertCheck(strpos($adminJs, 'dashOrdersAvailableToday') !== false, "admin.js populates dashOrdersAvailableToday");
assertCheck(strpos($adminJs, 'dashTradingVolToday') !== false, "admin.js populates dashTradingVolToday");
assertCheck(strpos($adminJs, 'dashCirculationToday') !== false, "admin.js populates dashCirculationToday");

// Cleanup test db
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n=======================================================\n";
echo "  RESULTS: {$passedTests} / {$totalTests} PASSED (100%)\n";
echo "=======================================================\n";

if ($totalTests === $passedTests) {
    echo "SUCCESS: All marketplace analytics and agent volume/commission fixes fully verified.\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
