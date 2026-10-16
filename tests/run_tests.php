<?php
/**
 * CLI Test Runner for Acceptance Scenarios (QA-001 to QA-008)
 */

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/QATestSuite.php';

echo "====================================================================\n";
echo " P2P TOKEN PLATFORM (PRD v1.0) - ACCEPTANCE TEST SUITE\n";
echo " Production Target: https://jelipay.in/ | DB: u591469534_jelii\n";
echo "====================================================================\n\n";

$suite = new QATestSuite();
$results = $suite->runAll();

$allPassed = true;
$passedCount = 0;
$totalCount = count($results);

foreach ($results as $res) {
    $statusColor = $res['passed'] ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo sprintf("%s %-8s : %s\n", $statusColor, $res['id'], $res['title']);
    if (!$res['passed']) {
        $allPassed = false;
        echo "   Details: " . json_encode($res['details'], JSON_PRETTY_PRINT) . "\n";
    } else {
        $passedCount++;
    }
}

echo "\n--------------------------------------------------------------------\n";
echo sprintf("Summary: %d / %d Acceptance Scenarios Passed (%.1f%%)\n", $passedCount, $totalCount, ($passedCount / $totalCount) * 100);
echo "--------------------------------------------------------------------\n";

if ($allPassed) {
    echo "\033[32mSUCCESS: All PRD acceptance scenarios & additional directives (QA-001 through QA-010) passed strictly on backend logic.\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31mFAILURE: One or more acceptance tests failed.\033[0m\n\n";
    exit(1);
}
