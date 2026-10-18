<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';

echo "Running Profile Section Stabilization & Feature Tests...\n";

// 1. Setup SQLite in-memory database
$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec("PRAGMA foreign_keys = ON;");
Database::setConnection($pdo, 'sqlite');
SchemaInstaller::initSqlite($pdo);
SchemaInstaller::migrateColumns($pdo, 'sqlite');
SchemaInstaller::seedPlatformSettings($pdo);

// 2. Create test user
$hashedPw = password_hash('TestPass123!', PASSWORD_BCRYPT);
$ins = $pdo->prepare("
    INSERT INTO users (phone, email, password_hash, account_status, role, referral_code, health_score)
    VALUES ('9998887776', 'initial@jelipay.in', ?, 'active', 'user', 'PROFTEST1', 100)
");
$ins->execute([$hashedPw]);
$userId = (int)$pdo->lastInsertId();

// 3. Test Auth::updateProfile validations
// Test A: Invalid UPI without '@'
$failUpi = Auth::updateProfile($userId, 'invalidupi', 'Rahul Sharma', 'rahul@jelipay.in');
assert($failUpi['success'] === false, "Auth::updateProfile must reject UPI without @");

// Test B: Empty name
$failName = Auth::updateProfile($userId, 'rahul@okaxis', '', 'rahul@jelipay.in');
assert($failName['success'] === false, "Auth::updateProfile must reject empty name");

// Test C: Invalid email format
$failEmail = Auth::updateProfile($userId, 'rahul@okaxis', 'Rahul Sharma', 'notanemail');
assert($failEmail['success'] === false, "Auth::updateProfile must reject invalid email format");

// Test D: Successful profile update with valid parameters
$okRes = Auth::updateProfile($userId, 'rahul@okaxis', 'Rahul Sharma', 'rahul.sharma@example.com');
assert($okRes['success'] === true, "Auth::updateProfile must succeed with valid data");

// Verify persistence in database
$stmt = $pdo->prepare("SELECT upi_id, account_holder_name, email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$updatedUser = $stmt->fetch();
assert($updatedUser['upi_id'] === 'rahul@okaxis', "UPI ID must match updated value");
assert($updatedUser['account_holder_name'] === 'Rahul Sharma', "Holder name must match updated value");
assert($updatedUser['email'] === 'rahul.sharma@example.com', "Email must match updated value");
echo "[PASS] Profile Update API: Validations, email support, and database persistence verified\n";

// 4. Test Session retrieval of updated profile
$_SESSION['user_id'] = $userId;
$sessionUser = Auth::getCurrentUser();
assert($sessionUser !== null, "Auth::getCurrentUser must return user");
assert($sessionUser['upi_id'] === 'rahul@okaxis', "Session user must contain updated upi_id");
assert($sessionUser['account_holder_name'] === 'Rahul Sharma', "Session user must contain updated name");
assert($sessionUser['email'] === 'rahul.sharma@example.com', "Session user must contain updated email");
echo "[PASS] Profile Session: Auth::getCurrentUser accurately returns updated fields\n";

// 5. Test index.php DOM Integrity for Profile Section
$indexHtml = file_get_contents(__DIR__ . '/../index.php');
$requiredElements = [
    'id="view-profile"',
    'id="profileAvatarInitial"',
    'id="profileNameDisplay"',
    'id="profileEmailDisplay"',
    'id="profilePhoneDisplay"',
    'id="profileRefCodeDisplay"',
    'id="profileHealthScoreDisplay"',
    'id="profileStatusDisplay"',
    'id="profHolderName"',
    'id="profUpiId"',
    'id="profEmail"',
    'id="profUpdateStatus"',
    'id="profUpdateBtn"',
    'id="profileTpinHeader"',
    'id="profileTpinLabel"',
    'id="profNewTpin"',
    'id="profileTpinBtn"',
    'id="currentPasswordInput"',
    'id="newPasswordInput"',
    'id="confirmPasswordInput"',
    'id="changePasswordStatus"',
    'id="changePasswordBtn"',
    'id="pwaProfileCard"',
    'openHistoryModal(\'ledger\')'
];

foreach ($requiredElements as $elem) {
    assert(strpos($indexHtml, $elem) !== false, "index.php must contain element: {$elem}");
}
echo "[PASS] Profile Section HTML: All essential UI elements and forms present in index.php\n";

// 6. Test that HTML container div balance in view-profile is correct
$startPos = strpos($indexHtml, '<section id="view-profile"');
$endPos = strpos($indexHtml, '</section>', $startPos);
assert($startPos !== false && $endPos !== false, "view-profile section must be well-delimited");
$profileChunk = substr($indexHtml, $startPos, $endPos - $startPos + 10);

$openDivCount = substr_count($profileChunk, '<div');
$closeDivCount = substr_count($profileChunk, '</div>');
assert($openDivCount === $closeDivCount, "view-profile must have balanced <div> and </div> tags (found {$openDivCount} open, {$closeDivCount} close)");
echo "[PASS] Profile Section HTML: Clean DOM tree with perfectly balanced container tags ({$openDivCount} tags)\n";

// 7. Test app.js JavaScript integrity
$appJs = file_get_contents(__DIR__ . '/../public/js/app.js');
$requiredJsFunctions = [
    'function updateProfilePreviews',
    'async function handleProfileUpdate',
    'async function handleTpinUpdate',
    'function copyReferralCode',
    'async function handlePasswordChange',
    'confirmPasswordInput'
];
foreach ($requiredJsFunctions as $fn) {
    assert(strpos($appJs, $fn) !== false, "app.js must contain {$fn}");
}
echo "[PASS] Profile Client Script: All sync routines and handlers verified in app.js\n";

echo "\nALL USER PROFILE SECTION TESTS PASSED SUCCESSFULLY!\n";
