<?php
/**
 * Agent Support Mode Verification Test Suite
 * Tests Admin impersonation into Agent Dashboard, read-only guarantees,
 * audit trails, and roundtrip session restoration.
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

$testDbFile = __DIR__ . '/test_agent_support_suite.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
putenv("SQLITE_PATH={$testDbFile}");
$_ENV['SQLITE_PATH'] = $testDbFile;

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/AgentManager.php';
require_once __DIR__ . '/../src/Auth.php';

Database::reset();
SchemaInstaller::ensureSchema();
$pdo = Database::getConnection('sqlite');

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $desc, bool $condition): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $testsFailed++;
    }
}

echo "=======================================================\n";
echo " AGENT SUPPORT MODE VERIFICATION TEST SUITE\n";
echo "=======================================================\n\n";

// -----------------------------------------------------------------
// Setup: Ensure Admin & Agent Exist
// -----------------------------------------------------------------
echo "--- Section 1: Test Fixtures & Setup ---\n";

$dummyHash = password_hash('Pass@123', PASSWORD_BCRYPT);

// Admin User (Seed Admin #1)
$adminStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$adminId = (int)$adminStmt->fetchColumn();
assertTest("Admin fixture identified (ID #{$adminId})", $adminId > 0);

// Root User
$pdo->prepare("
    INSERT INTO users (id, phone, email, password_hash, referral_code, account_holder_name, account_status, role)
    VALUES (200, '9900000200', 'rootsup@jelipay.in', ?, 'AGENTROOTSUP', 'Master Root User', 'active', 'user')
")->execute([$dummyHash]);
$rootUserId = 200;
assertTest("Root user fixture created (ID #{$rootUserId})", $rootUserId > 0);

// Agent Creation
$createRes = AgentManager::createAgent([
    'agent_code' => 'AGT_TEST_SUP',
    'name' => 'Support Mode Test Agent',
    'phone' => '9777777777',
    'email' => 'agent_support@jelipay.in',
    'root_user_id' => $rootUserId,
    'commission_rate' => 4.5,
    'eligible_levels' => ['A', 'B', 'C']
], $adminId);
$agentId = (int)$createRes['agent_id'];
$agentCode = 'AGT_TEST_SUP';
assertTest("Agent fixture created (ID #{$agentId}, Code: {$agentCode})", $agentId > 0);

// -----------------------------------------------------------------
// Section 2: Launch Agent Support Mode
// -----------------------------------------------------------------
echo "\n--- Section 2: Launch Agent Support Mode ---\n";

// Start session as admin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION = [];
$_SESSION['user_id'] = $adminId;
$_SESSION['role'] = 'admin';

// Launch agent impersonation
$startRes = AgentManager::startAgentImpersonation($adminId, $agentId);

assertTest("startAgentImpersonation returns success", $startRes['success'] === true);
assertTest("Redirect target is /agent/dashboard", $startRes['redirect'] === '/agent/dashboard');
assertTest("Session real_admin_id is set to admin ID", ($_SESSION['real_admin_id'] ?? null) === $adminId);
assertTest("Session is_agent_impersonating flag is true", ($_SESSION['is_agent_impersonating'] ?? null) === true);
assertTest("Session agent_id matches target agent ID", ($_SESSION['agent_id'] ?? null) === $agentId);
assertTest("Session agent_code matches target agent", ($_SESSION['agent_code'] ?? null) === $agentCode);
assertTest("Session agent_logged_in flag is true", ($_SESSION['agent_logged_in'] ?? null) === true);

// -----------------------------------------------------------------
// Section 3: Identity & Dashboard Resolution
// -----------------------------------------------------------------
echo "\n--- Section 3: Identity & Dashboard Resolution in Support Mode ---\n";

$currentAgent = AgentManager::getCurrentAgent();
assertTest("getCurrentAgent resolves target agent", $currentAgent !== null && (int)$currentAgent['id'] === $agentId);

$stats = AgentManager::getAgentDashboardStats($agentId);
assertTest("getAgentDashboardStats returns numeric metrics in support mode", is_numeric($stats['commission_rate']));

// -----------------------------------------------------------------
// Section 4: Read-Only Invariants (Mutations strictly blocked)
// -----------------------------------------------------------------
echo "\n--- Section 4: Read-Only Protection (Mutations Blocked) ---\n";

$changePwdRes = AgentManager::agentChangePassword($agentId, 'OldPassword123!', 'NewPassword123!', '123456');
assertTest("AgentManager::agentChangePassword strictly blocked in support mode", $changePwdRes['success'] === false);
assertTest("Error indicates support mode restriction", strpos($changePwdRes['error'], 'Support Mode') !== false);

// -----------------------------------------------------------------
// Section 5: Audit Trail Verification
// -----------------------------------------------------------------
echo "\n--- Section 5: Audit Trail Verification ---\n";

$agentAuditStmt = $pdo->prepare("SELECT * FROM agent_audit_logs WHERE agent_id = ? AND action = 'start_agent_impersonation' ORDER BY id DESC LIMIT 1");
$agentAuditStmt->execute([$agentId]);
$agentLog = $agentAuditStmt->fetch();
assertTest("Agent audit log recorded start_agent_impersonation", !empty($agentLog));

$adminAuditStmt = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE action_type = 'IMPERSONATE_AGENT' ORDER BY id DESC LIMIT 1");
$adminAuditStmt->execute();
$adminLog = $adminAuditStmt->fetch();
assertTest("Admin audit log recorded IMPERSONATE_AGENT", !empty($adminLog));

// -----------------------------------------------------------------
// Section 6: Exit Agent Support Mode & Session Restoration
// -----------------------------------------------------------------
echo "\n--- Section 6: Exit Support Mode & Admin Session Restoration ---\n";

$exitRes = AgentManager::exitAgentImpersonation();

assertTest("exitAgentImpersonation returns success", $exitRes['success'] === true);
assertTest("Exit redirect points to /admin.php#agents", $exitRes['redirect'] === '/admin.php#agents');
assertTest("Session agent_id is completely unset", empty($_SESSION['agent_id']));
assertTest("Session is_agent_impersonating is completely unset", empty($_SESSION['is_agent_impersonating']));
assertTest("Session agent_logged_in is completely unset", empty($_SESSION['agent_logged_in']));
assertTest("Session user_id restored to admin ID", ($_SESSION['user_id'] ?? null) === $adminId);
assertTest("Session role restored to admin", ($_SESSION['role'] ?? null) === 'admin');
assertTest("Session real_admin_id is cleared", empty($_SESSION['real_admin_id']));

$agentExitAuditStmt = $pdo->prepare("SELECT * FROM agent_audit_logs WHERE agent_id = ? AND action = 'exit_agent_impersonation' ORDER BY id DESC LIMIT 1");
$agentExitAuditStmt->execute([$agentId]);
assertTest("Agent audit log recorded exit_agent_impersonation", !empty($agentExitAuditStmt->fetch()));

$adminExitAuditStmt = $pdo->prepare("SELECT * FROM admin_audit_logs WHERE action_type = 'EXIT_IMPERSONATE_AGENT' ORDER BY id DESC LIMIT 1");
$adminExitAuditStmt->execute();
assertTest("Admin audit log recorded EXIT_IMPERSONATE_AGENT", !empty($adminExitAuditStmt->fetch()));

// -----------------------------------------------------------------
// Section 7: UI Component & Template Invariants
// -----------------------------------------------------------------
echo "\n--- Section 7: UI & Template Inspection ---\n";

$dashboardContent = file_get_contents(__DIR__ . '/../agent_dashboard.php');
assertTest("agent_dashboard.php contains isAgentImpersonating check", strpos($dashboardContent, '$isAgentImpersonating') !== false);
assertTest("agent_dashboard.php contains ADMIN SUPPORT MODE ACTIVE banner", strpos($dashboardContent, 'ADMIN SUPPORT MODE ACTIVE') !== false);
assertTest("agent_dashboard.php contains exitAgentSupportMode function", strpos($dashboardContent, 'exitAgentSupportMode') !== false);

$adminJsContent = file_get_contents(__DIR__ . '/../public/js/admin.js');
assertTest("admin.js contains impersonateAgent table button", strpos($adminJsContent, 'impersonateAgent(${a.id}') !== false);
assertTest("admin.js contains impersonateAgent function definition", strpos($adminJsContent, 'async function impersonateAgent(') !== false);
assertTest("admin.js attaches window.impersonateAgent", strpos($adminJsContent, 'window.impersonateAgent = impersonateAgent') !== false);

$adminApiContent = file_get_contents(__DIR__ . '/../api/admin.php');
assertTest("api/admin.php handles impersonate_agent", strpos($adminApiContent, "case 'impersonate_agent':") !== false);
assertTest("api/admin.php handles exit_agent_impersonate", strpos($adminApiContent, "case 'exit_agent_impersonate':") !== false);

$agentApiContent = file_get_contents(__DIR__ . '/../api/agent.php');
assertTest("api/agent.php handles exit_impersonation", strpos($agentApiContent, "\$action === 'exit_impersonation'") !== false);
assertTest("api/agent.php guards change_password in support mode", strpos($agentApiContent, 'is_agent_impersonating') !== false);

// Clean up test sqlite
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}

echo "\n=======================================================\n";
echo "  RESULTS: {$testsPassed} PASSED, {$testsFailed} FAILED\n";
echo "=======================================================\n";

if ($testsFailed === 0) {
    echo "SUCCESS: Agent Support Mode fully verified and production-ready.\n";
    exit(0);
} else {
    echo "FAILURE: {$testsFailed} test(s) failed.\n";
    exit(1);
}
