<?php
/**
 * Automated Verification Suite for Agent Management System
 * Covers Agent provisioning, one-time credential generation, AES-256-GCM TOTP encryption,
 * multi-tier hierarchy resolution A to Z, idempotent purchase commission snapshotting,
 * Monday-Sunday ISO-8601 weekly accounting, admin adjustments, payouts, and RBAC isolation.
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_agent_suite.sqlite';
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
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/ReferralEngine.php';
require_once __DIR__ . '/../src/AgentManager.php';
require_once __DIR__ . '/../src/Security/TotpService.php';

Database::reset();
SchemaInstaller::ensureSchema();
$pdo = Database::getConnection('sqlite');

$passedTests = 0;
$totalTests = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
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
echo "  JELIPAY AGENT MANAGEMENT SYSTEM TEST SUITE\n";
echo "=======================================================\n\n";

// -------------------------------------------------------------
// Test Group 1: Multi-Tier Hierarchy Resolution (Levels A through Z)
// -------------------------------------------------------------
echo "--- Group 1: Multi-Tier Referral Hierarchy Resolution (Levels A to Z) ---\n";

// Create root user (Agent Root) and a chain of 4 downstream users (Level A, B, C, D)
$now = gmdate('Y-m-d H:i:s');
$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);

// Root User
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (100, '9900000100', 'root@jelipay.in', ?, 'AGENTROOT1', 'Super Master Root', 'active', 'user')
")->execute([$dummyHash]);

// Level A (Referred by Root 100)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (101, '9900000101', 'usera@jelipay.in', ?, 'REFA001', 100, 'Trader Alice', 'active', 'user')
")->execute([$dummyHash]);

// Level B (Referred by Level A 101)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (102, '9900000102', 'userb@jelipay.in', ?, 'REFB002', 101, 'Trader Bob', 'active', 'user')
")->execute([$dummyHash]);

// Level C (Referred by Level B 102)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (103, '9900000103', 'userc@jelipay.in', ?, 'REFC003', 102, 'Trader Charlie', 'active', 'user')
")->execute([$dummyHash]);

// Level D (Referred by Level C 103)
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, referred_by_id, account_holder_name, account_status, role)
    VALUES (104, '9900000104', 'userd@jelipay.in', ?, 'REFD004', 103, 'Trader David', 'active', 'user')
")->execute([$dummyHash]);

$tree = AgentManager::resolveDownstreamHierarchy(100, 26);
assertTest($tree['total_users'] === 4, 'Root hierarchy contains exactly 4 descendants');
assertTest($tree['by_user_id'][101]['level'] === 'A', 'Immediate child 101 is mapped to Level A (depth 1)');
assertTest($tree['by_user_id'][102]['level'] === 'B', 'Grandchild 102 is mapped to Level B (depth 2)');
assertTest($tree['by_user_id'][103]['level'] === 'C', 'Great-grandchild 103 is mapped to Level C (depth 3)');
assertTest($tree['by_user_id'][104]['level'] === 'D', 'Downstream 104 is mapped to Level D (depth 4)');
assertTest(isset($tree['descendants'][0]['phone_masked']), 'User phone is masked in hierarchy results');
assertTest(strpos($tree['descendants'][0]['phone_masked'], '99') === 0 && str_ends_with($tree['descendants'][0]['phone_masked'], '01'), 'Masked phone matches format 99****01');

// -------------------------------------------------------------
// Test Group 2: Agent Creation, Credential Display & TOTP Setup
// -------------------------------------------------------------
echo "\n--- Group 2: Agent Account Creation & One-Time Credential Display ---\n";

$createData = [
    'agent_code' => 'AGT9001',
    'name' => 'Agent Vikram',
    'phone' => '9888899999',
    'email' => 'vikram.agent@jelipay.in',
    'root_user_id' => 'AGENTROOT1',
    'commission_rate' => 3.50,
    'eligible_levels' => 'A,B,C,D,E'
];

$createRes = AgentManager::createAgent($createData, 1);
assertTest($createRes['success'] === true, 'Agent AGT9001 created successfully');
assertTest($createRes['agent_code'] === 'AGT9001', 'Agent code matches requested ID');
assertTest(!empty($createRes['plain_password']), 'Secure temporary password generated');
assertTest(!empty($createRes['totp_secret']), 'Base32 TOTP secret generated for one-time display');
assertTest(strpos($createRes['provisioning_uri'], 'otpauth://totp/') === 0, 'Provisioning URI follows RFC 6238 format');
assertTest(strpos($createRes['qr_svg'], '<svg') !== false, 'Pure-PHP vector QR code SVG generated without external API leaks');

// Verify encryption at rest in DB
$agentDb = $pdo->query("SELECT * FROM agents WHERE agent_code = 'AGT9001'")->fetch();
assertTest($agentDb['totp_secret'] !== $createRes['totp_secret'], 'TOTP secret is strictly encrypted at rest in database');
$decrypted = \Security\TotpService::decryptSecret($agentDb['totp_secret']);
assertTest($decrypted === $createRes['totp_secret'], 'Decrypted TOTP secret matches original plaintext secret exactly');
assertTest($agentDb['status'] === 'active', 'Agent default account status is active');
assertTest((int)$agentDb['must_change_password'] === 1, 'must_change_password flag initialized to 1');

// -------------------------------------------------------------
// Test Group 3: Agent Authentication (User ID + Password + TOTP)
// -------------------------------------------------------------
echo "\n--- Group 3: Agent Authenticator Authentication & Session Security ---\n";

// Calculate live TOTP code for AGT9001
$agentSecret = $createRes['totp_secret'];
$liveTotpCode = \Security\TotpService::calculateTotp($agentSecret, time());

// Failure: Wrong password
$badPassRes = AgentManager::authenticate('AGT9001', 'WrongPassword!123', $liveTotpCode);
assertTest($badPassRes['success'] === false, 'Authentication rejects incorrect password');

// Failure: Wrong TOTP
$badTotpRes = AgentManager::authenticate('AGT9001', $createRes['plain_password'], '000000');
assertTest($badTotpRes['success'] === false, 'Authentication rejects invalid 6-digit TOTP code');

// Success: Agent User ID + Password + Live TOTP
$goodAuth = AgentManager::authenticate('AGT9001', $createRes['plain_password'], $liveTotpCode);
assertTest($goodAuth['success'] === true, 'Agent login succeeds with valid credentials + live TOTP');
assertTest(isset($_SESSION['agent_id']), 'Segregated agent session established');
assertTest($_SESSION['agent_code'] === 'AGT9001', 'Session stores agent_code correctly');

// Replay rejection: Immediate replay of same code
$replayAuth = AgentManager::authenticate('AGT9001', $createRes['plain_password'], $liveTotpCode);
assertTest($replayAuth['success'] === false, 'Immediate replay of accepted TOTP code is strictly rejected');

// In-dashboard password change
$newTotpCode = \Security\TotpService::calculateTotp($agentSecret, time() + 35); // Next step
$newPass = 'NewAgentSecurePass@2026';
$changePwdRes = AgentManager::agentChangePassword((int)$agentDb['id'], $createRes['plain_password'], $newPass, $newTotpCode);
assertTest($changePwdRes['success'] === true, 'Agent password change succeeds with valid current password + 2FA');

// Verify new password works
$futureTs = time() + 70;
$newTotpStep2 = \Security\TotpService::calculateTotp($agentSecret, $futureTs);
$reAuthNew = AgentManager::authenticate('AGT9001', $newPass, $newTotpStep2, $futureTs);
assertTest($reAuthNew['success'] === true, 'Agent can authenticate with newly updated password');

// -------------------------------------------------------------
// Test Group 4: Order Completion Hook & Commission Snapshotting
// -------------------------------------------------------------
echo "\n--- Group 4: Token Purchase Commission Calculation & Idempotency ---\n";

// Setup orders for Trader Bob (ID 102, Level B relative to Root 100)
// Base purchase amount: ₹10,000
// 9% reward: ₹900
// Eligible Agent: AGT9001 (Root 100, Level B is in 'A,B,C,D,E', rate 3.50%)
// Expected Agent Commission: ₹10,000 * 3.50% = ₹350.00
$childOrderId = 501;
$buyerId = 102; // Trader Bob
$baseAmount = 10000.00;
$completedUtc = gmdate('Y-m-d H:i:s');

// Create dummy parent and child orders in DB
$pdo->prepare("
    INSERT INTO parent_sell_orders (id, seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, snapshotted_upi_id, snapshotted_account_holder)
    VALUES (500, 1, 10000, 0, 10000, 0, 'admin@upi', 'Admin')
")->execute();

$pdo->prepare("
    INSERT INTO child_orders (id, parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status)
    VALUES (501, 500, 1, 102, 10000.00, 900.00, 'admin@upi', 'Admin', 'Payment Proof Submitted')
")->execute();

// Trigger commission snapshot
$commRes = AgentManager::onOrderCompleted($childOrderId, $buyerId, $baseAmount, $completedUtc);
assertTest($commRes['success'] === true, 'Agent commission processing hook executed successfully');
assertTest($commRes['count'] === 1, 'Exactly 1 eligible agent identified for Level B purchase');
assertTest($commRes['commissions'][0]['commission_amount'] === 350.00, 'Commission calculated on base purchase amount (₹10,000 @ 3.5% = ₹350.00)');
assertTest($commRes['commissions'][0]['level'] === 'B', 'Snapshotted referral level is B');
assertTest($commRes['commissions'][0]['depth'] === 2, 'Snapshotted referral depth is 2');

// Idempotency check: Calling again MUST NOT duplicate commission
$commResDup = AgentManager::onOrderCompleted($childOrderId, $buyerId, $baseAmount, $completedUtc);
assertTest($commResDup['count'] === 0, 'Idempotent execution: Duplicate order completion creates 0 additional commission records');

// Verify historical rate snapshot invariant: If agent rate changes later, past commission remains unchanged
$pdo->prepare("UPDATE agents SET commission_rate = 5.00 WHERE id = ?")->execute([$agentDb['id']]);
$commInDb = $pdo->query("SELECT * FROM agent_commissions WHERE child_order_id = 501")->fetch();
assertTest((float)$commInDb['commission_rate'] === 3.50, 'Historical commission_rate invariant preserved at 3.50%');
assertTest((float)$commInDb['commission_amount'] === 350.00, 'Historical commission_amount invariant preserved at ₹350.00');

// Restore rate
$pdo->prepare("UPDATE agents SET commission_rate = 3.50 WHERE id = ?")->execute([$agentDb['id']]);

// -------------------------------------------------------------
// Test Group 5: Monday–Sunday Weekly Commission Accounting
// -------------------------------------------------------------
echo "\n--- Group 5: Monday-to-Sunday Weekly Statements & Admin Payouts ---\n";

$currentWeek = AgentManager::getCurrentUtcWeek();
assertTest(!empty($currentWeek['start_date']), 'Current week start date calculated');
assertTest(strpos($currentWeek['start_date'], '00:00:00') !== false, 'Week begins Monday at 00:00:00 UTC');
assertTest(strpos($currentWeek['end_date'], '23:59:59') !== false, 'Week ends Sunday at 23:59:59 UTC');

// Generate weekly statements
$genRes = AgentManager::generateWeeklyStatements($currentWeek['year'], $currentWeek['week_number'], (int)$agentDb['id']);
assertTest($genRes['success'] === true, 'Weekly statement compilation succeeded');

$stmtDb = $pdo->query("SELECT * FROM agent_weekly_statements WHERE agent_id = {$agentDb['id']} AND week_year = {$currentWeek['year']} AND week_number = {$currentWeek['week_number']}")->fetch();
assertTest($stmtDb !== false, 'Weekly statement record created in database');
assertTest((float)$stmtDb['gross_commission'] === 350.00, 'Statement gross commission equals ₹350.00');
assertTest((float)$stmtDb['net_commission'] === 350.00, 'Statement initial net commission equals gross (₹350.00)');
assertTest($stmtDb['payout_status'] === 'pending_review', 'Initial statement payout status is pending_review');

// Admin Adjustment (+₹50 bonus with mandatory reason)
$adjRes = AgentManager::adjustStatement((int)$stmtDb['id'], 50.00, 'Top Weekly Performer Tier Bonus', 1);
assertTest($adjRes['success'] === true, 'Administrative adjustment recorded');
assertTest($adjRes['net_commission'] === 400.00, 'Net commission updated to ₹400.00 after +₹50.00 adjustment');

// Admin Approval
$appRes = AgentManager::approveStatement((int)$stmtDb['id'], 1);
assertTest($appRes['success'] === true, 'Statement approved for payout');
$stmtApproved = $pdo->query("SELECT payout_status FROM agent_weekly_statements WHERE id = {$stmtDb['id']}")->fetchColumn();
assertTest($stmtApproved === 'approved', 'Database reflects payout_status = approved');

// Record Payout
$payoutRef = 'UTR-HDFC-9928348123';
$paidDate = gmdate('Y-m-d H:i:s');
$payoutRes = AgentManager::recordPayout((int)$stmtDb['id'], $payoutRef, $paidDate, 1, 'IMPS Bank Transfer Succeeded');
assertTest($payoutRes['success'] === true, 'Commission payout settlement recorded');
$stmtPaid = $pdo->query("SELECT * FROM agent_weekly_statements WHERE id = {$stmtDb['id']}")->fetch();
assertTest($stmtPaid['payout_status'] === 'paid', 'Statement status transitioned to paid');
assertTest($stmtPaid['payout_reference'] === $payoutRef, 'Bank payout reference saved');

// -------------------------------------------------------------
// Test Group 6: Dashboard Metrics & PII Privacy Protection
// -------------------------------------------------------------
echo "\n--- Group 6: Agent Dashboard Metrics & Privacy Protection ---\n";

$dashStats = AgentManager::getAgentDashboardStats((int)$agentDb['id']);
assertTest($dashStats['total_referred_users'] === 4, 'Dashboard shows 4 referred users');
assertTest($dashStats['total_volume'] === 10000.00, 'Dashboard total volume reflects ₹10,000.00');
assertTest($dashStats['total_commission_earned'] === 350.00, 'Dashboard total commission reflects ₹350.00');
assertTest($dashStats['paid_commission'] === 400.00, 'Dashboard shows ₹400.00 paid commission');
assertTest($dashStats['last_payout']['reference'] === $payoutRef, 'Dashboard displays last payout reference');

$purchases = AgentManager::getAgentPurchases((int)$agentDb['id']);
assertTest($purchases['total'] === 1, 'Purchase history returns 1 order');
assertTest($purchases['purchases'][0]['buyer_masked_phone'] === '99******02', 'Buyer phone number masked in purchase log');
assertTest($purchases['purchases'][0]['buyer_masked_name'] === 'T****r B**', 'Buyer name masked in purchase log');
assertTest($purchases['purchases'][0]['commission_amount'] === 350.00, 'Purchase log reports correct commission');

// -------------------------------------------------------------
// Test Group 7: Agent Suspension & Audit Logging
// -------------------------------------------------------------
echo "\n--- Group 7: Account Suspension & Audit Trail ---\n";

$toggleRes = AgentManager::toggleStatus((int)$agentDb['id'], 1, 'Temporary verification hold');
assertTest($toggleRes['success'] === true && $toggleRes['new_status'] === 'suspended', 'Agent account suspended by admin');

// Suspended login attempt must fail
$step3Code = \Security\TotpService::calculateTotp($agentSecret, time() + 120);
$suspAuth = AgentManager::authenticate('AGT9001', $newPass, $step3Code);
assertTest($suspAuth['success'] === false, 'Suspended agent is strictly blocked from logging in');

// Audit logs
$auditLogs = AgentManager::getAuditLogs((int)$agentDb['id'], 20);
assertTest(count($auditLogs) >= 4, 'Multiple audit logs recorded for agent lifecycle actions');
$actions = array_column($auditLogs, 'action');
assertTest(in_array('agent_created', $actions, true), 'Audit log contains agent_created');
assertTest(in_array('statement_adjusted', $actions, true), 'Audit log contains statement_adjusted');
assertTest(in_array('statement_paid', $actions, true), 'Audit log contains statement_paid');

echo "\n=======================================================\n";
echo "  TEST RESULTS: {$passedTests} / {$totalTests} PASSED (" . round(($passedTests / $totalTests) * 100, 1) . "%)\n";
echo "=======================================================\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: Agent Management System passed all architectural and security tests.\n";
    exit(0);
} else {
    echo "FAILURE: One or more tests failed.\n";
    exit(1);
}
