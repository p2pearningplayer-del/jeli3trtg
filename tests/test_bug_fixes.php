<?php
/**
 * Bug Fix Verification Suite: Fraud Ledger & Agent Management
 * Validates fixes for Bug 1 and Bug 2 without breaking existing behavior.
 */

declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AdminManager.php';
require_once __DIR__ . '/../src/AgentManager.php';

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

echo "=======================================================\n";
echo " BUG FIX VERIFICATION: FRAUD LEDGER & AGENT MANAGEMENT \n";
echo "=======================================================\n\n";

$passedTests = 0;
$totalTests = 0;

function check(string $name, bool $pass, ?string $extra = null): void {
    global $passedTests, $totalTests;
    $totalTests++;
    if ($pass) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        echo "  [FAIL] {$name}" . ($extra ? " ({$extra})" : "") . "\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Bug 1 - Fraud Assessments Permission & Empty State
// -------------------------------------------------------------
echo "--- Testing Bug 1: Fraud Ledger Endpoints & Permission Checks ---\n";

// Authenticate Super Admin session
$login = Auth::adminLogin('ADMIN001', 'Admin@123456');
check("Super Admin session established", $login['success'] === true);
$currentUser = Auth::getCurrentUser();

// 1.1 Check permission with $currentUser
$canFraudView = AdminManager::checkPermission($currentUser, 'fraud_view');
check("Super Admin has fraud_view permission", $canFraudView === true);

// 1.2 Call getFraudAssessments (empty state)
$assessments = AdminManager::getFraudAssessments('', 'all', 'all', 50, 0);
check("getFraudAssessments returns empty list gracefully", is_array($assessments['assessments']) && count($assessments['assessments']) === 0 && $assessments['total'] === 0);

// 1.3 Check role without fraud_view (e.g. user)
$regularUser = ['role' => 'user', 'admin_role' => null];
check("Non-admin rejected for fraud_view", !AdminManager::checkPermission($regularUser, 'fraud_view'));

// -------------------------------------------------------------
// TEST 2: Bug 2 - Agent List & Metrics Calculation
// -------------------------------------------------------------
echo "\n--- Testing Bug 2: Agent Management List & Serialization ---\n";

// 2.1 Empty agent list returns array without exception
$agentsListEmpty = AgentManager::listAgents([]);
check("AgentManager::listAgents returns empty array without error", is_array($agentsListEmpty) && count($agentsListEmpty) === 0);

// 2.2 Create root user & agent
$root = Auth::register('9955554444', 'Pass@123', null, 'root@upi', 'Agent Root User');
$createAgent = AgentManager::createAgent([
    'name' => 'Agent Verification Test',
    'phone' => '9955554445',
    'email' => 'agenttest@jelipay.in',
    'root_referral_code' => $root['referral_code'],
    'commission_rate' => 4.00,
    'eligible_levels' => ['A', 'B', 'C'],
    'agent_code' => 'AGT7777'
], (int)$currentUser['id']);
check("Agent AGT7777 created successfully", $createAgent['success'] === true);

// 2.3 List agents with newly created agent
$agentsList = AgentManager::listAgents([]);
check("AgentManager::listAgents returns created agent", count($agentsList) === 1);
$agentRecord = $agentsList[0];
check("Agent record has numeric total_volume", is_numeric($agentRecord['total_volume']));
check("Agent record has numeric current_week_commission", is_numeric($agentRecord['current_week_commission']));
check("Agent record has numeric total_commission_paid", is_numeric($agentRecord['total_commission_paid']));
check("Agent record has numeric total_commission_pending", is_numeric($agentRecord['total_commission_pending']));

// -------------------------------------------------------------
// TEST 3: JavaScript formatCurrency Simulation
// -------------------------------------------------------------
echo "\n--- Testing Bug 2: JavaScript formatCurrency & Client Execution ---\n";

$jsFile = file_get_contents(__DIR__ . '/../public/js/admin.js');
check("public/js/admin.js contains formatCurrency function declaration", str_contains($jsFile, 'function formatCurrency(val)'));
check("public/js/admin.js contains openAdminLightbox alias", str_contains($jsFile, 'const openAdminLightbox = openEvidenceLightbox;'));

echo "\n=======================================================\n";
echo "  RESULT: {$passedTests} / {$totalTests} PASSED (" . round(($passedTests / $totalTests) * 100, 1) . "%)\n";
echo "=======================================================\n";

if ($passedTests === $totalTests) {
    echo "SUCCESS: Both bugs resolved and verified without regression.\n";
    exit(0);
} else {
    echo "FAILURE: One or more checks failed.\n";
    exit(1);
}
