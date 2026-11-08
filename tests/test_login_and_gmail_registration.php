<?php
/**
 * Test Suite: Login API Resolution & Strict Gmail Registration Policy
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/Security/IPBanManager.php';

$testDbPath = __DIR__ . '/test_auth_' . time() . '.sqlite';
putenv("SQLITE_DATABASE_PATH={$testDbPath}");

$pdo = new PDO("sqlite:{$testDbPath}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::setConnection($pdo, 'sqlite');

SchemaInstaller::ensureSchema();

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $desc, string $extra = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($extra ? " ({$extra})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo " JELIPAY - LOGIN RESOLUTION & GMAIL REGISTRATION RESTRICTION SUITE\n";
echo "====================================================================\n\n";

// --- TEST GROUP 1: IPBanManager Class Alias and Resolution ---
echo "--- TEST GROUP 1: IPBanManager & Auth Endpoint Health ---\n";
assertCondition(
    class_exists('IPBanManager'),
    "IPBanManager global class exists and is loadable"
);
assertCondition(
    class_exists('Security\\IPBanManager'),
    "Security\\IPBanManager alias exists and resolves to IPBanManager"
);
assertCondition(
    !IPBanManager::isBanned('127.0.0.1'),
    "IPBanManager::isBanned('127.0.0.1') executes without error"
);
assertCondition(
    !\Security\IPBanManager::isBanned('127.0.0.1'),
    "\\Security\\IPBanManager::isBanned('127.0.0.1') executes without error"
);

// --- TEST GROUP 2: Login Flow without Fatal Errors ---
echo "\n--- TEST GROUP 2: Login Flow Execution ---\n";

// Register a valid user
$regAlice = Auth::register('9999900001', 'ValidPass123!', null, 'alice@bank', 'Alice Test', 'alice.test@gmail.com', true);
assertCondition($regAlice['success'], "Alice registered with @gmail.com successfully");

// Test valid login
$loginSuccess = Auth::login('9999900001', 'ValidPass123!');
assertCondition($loginSuccess['success'], "Alice can login with phone and password without network/server error");
assertCondition((int)$loginSuccess['user']['id'] === (int)$regAlice['user_id'], "User ID matches in login response");

// Test login with Gmail address as identifier
$loginEmail = Auth::login('alice.test@gmail.com', 'ValidPass123!');
assertCondition($loginEmail['success'], "Alice can login using her Gmail address as identifier");

// Test invalid credentials (should return clean error, NOT throw exception or 500)
$loginBadPass = Auth::login('9999900001', 'WrongPassword!');
assertCondition(!$loginBadPass['success'], "Invalid credentials rejected cleanly");
assertCondition(
    str_contains($loginBadPass['error'], 'Invalid credentials'),
    "Error message explains credentials are invalid"
);

// --- TEST GROUP 3: Strict Gmail.com Only Registration Policy ---
echo "\n--- TEST GROUP 3: Strict Gmail.com Registration Policy ---\n";

// Non-gmail provider attempts
$disallowedEmails = [
    'user@yahoo.com',
    'test@outlook.com',
    'scammer@hotmail.com',
    'bot@protonmail.com',
    'temp@mailinator.com',
    'fake@yopmail.com',
    'john@icloud.com',
    'corporate@company.in'
];

foreach ($disallowedEmails as $badEmail) {
    $badReg = Auth::register('9999900099', 'ValidPass123!', null, null, null, $badEmail, false);
    assertCondition(
        !$badReg['success'],
        "Registration strictly REJECTED for non-gmail domain '{$badEmail}'"
    );
    assertCondition(
        str_contains($badReg['error'] ?? '', '@gmail.com'),
        "Error message mentions @gmail.com requirement for '{$badEmail}'"
    );
}

// Valid Gmail registrations
$validGmail1 = Auth::register('9999900002', 'ValidPass123!', null, null, null, 'bob.builder@gmail.com', true);
assertCondition(
    $validGmail1['success'],
    "Registration SUCCEEDED for 'bob.builder@gmail.com'"
);

$validGmail2 = Auth::register('9999900003', 'ValidPass123!', null, null, null, 'CHARLIE.P2P@GMAIL.COM', true);
assertCondition(
    $validGmail2['success'],
    "Case-insensitive registration SUCCEEDED for 'CHARLIE.P2P@GMAIL.COM'"
);

// Validator::validateGmail
assertCondition(Validator::validateGmail('valid@gmail.com'), "Validator::validateGmail('valid@gmail.com') is true");
assertCondition(Validator::validateGmail('USER.NAME+tag@GMAIL.COM'), "Validator::validateGmail case-insensitive with sub-address is true");
assertCondition(!Validator::validateGmail('user@yahoo.com'), "Validator::validateGmail('user@yahoo.com') is false");
assertCondition(!Validator::validateGmail('user@notgmail.com'), "Validator::validateGmail('user@notgmail.com') is false");
assertCondition(!Validator::validateGmail(''), "Validator::validateGmail('') is false");

// --- TEST GROUP 4: Frontend JavaScript Regex Validation ---
echo "\n--- TEST GROUP 4: Frontend Validation Pattern Check ---\n";
$appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
assertCondition(
    str_contains($appJs, '@gmail\\.com'),
    "public/js/app.js contains @gmail.com regex pattern"
);
assertCondition(
    str_contains($appJs, 'Registration requires an official @gmail.com address'),
    "public/js/app.js displays clear Gmail-only message"
);

$indexHtml = file_get_contents(__DIR__ . '/../index.php');
assertCondition(
    str_contains($indexHtml, 'Gmail Address (Only @gmail.com permitted)'),
    "index.php form label specifies @gmail.com permitted"
);

// ====================================================================
// SUMMARY & CLEANUP
// ====================================================================
echo "\n====================================================================\n";
echo " RESULT: {$passed} / " . ($passed + $failed) . " TESTS PASSED (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "====================================================================\n";

$pdo = null;
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

exit($failed > 0 ? 1 : 0);
