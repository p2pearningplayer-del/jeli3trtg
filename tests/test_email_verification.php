<?php
/**
 * Comprehensive Automated Test Suite: Email Verification & Hostinger SMTP Service
 */

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');
putenv('MOCK_EMAIL_DELIVERY=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EmailService.php';

// Enable mock email delivery during test runs to test fast without SMTP network latency
putenv("MOCK_EMAIL_DELIVERY=1");
$_ENV['MOCK_EMAIL_DELIVERY'] = '1';

SchemaInstaller::ensureSchema();
$pdo = Database::getConnection();

$testsPassed = 0;
$totalTests = 0;

function assertCondition(bool $cond, string $desc): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($cond) {
        $testsPassed++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}\n";
    }
}

echo "======================================================\n";
echo " EMAIL VERIFICATION (OTP & LINK) AUTOMATED TEST SUITE\n";
echo "======================================================\n\n";

$testPhone1 = '990001' . rand(1000, 9999);
$testEmail1 = 'tester_' . uniqid() . '@example.com';
$password = 'TestPass@123';

// -----------------------------------------------------------------
// TEST 1: Registration with Email Creates Pending Account & OTP
// -----------------------------------------------------------------
echo "[Test 1] User Registration & Verification Initiation\n";
$regRes = Auth::register($testPhone1, $password, null, 'tester@upi', 'Verification Tester', $testEmail1, false);

assertCondition($regRes['success'] === true, "Auth::register succeeds");
assertCondition(($regRes['step'] ?? '') === 'verify_email', "Response prompts verify_email step");
assertCondition(($regRes['account_status'] ?? '') === 'pending_verification', "Account status is pending_verification");

$userStmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$userStmt->execute([$testPhone1]);
$user = $userStmt->fetch();

assertCondition(!empty($user), "User record exists in DB");
assertCondition($user['account_status'] === 'pending_verification', "DB account_status is pending_verification");
assertCondition(!empty($user['email_verification_otp']), "DB email_verification_otp hash is populated");
assertCondition(!empty($user['email_verification_token']), "DB email_verification_token is populated");
assertCondition($user['email_verified_at'] === null, "DB email_verified_at is null");

// Check verification table
$verifStmt = $pdo->prepare("SELECT * FROM email_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$verifStmt->execute([(int)$user['id']]);
$verifRecord = $verifStmt->fetch();
assertCondition(!empty($verifRecord), "Record created in email_verifications audit table");
assertCondition($verifRecord['email'] === $testEmail1, "Audit email matches registered email");

// -----------------------------------------------------------------
// TEST 2: Unverified User Blocked from Logging In
// -----------------------------------------------------------------
echo "\n[Test 2] Unverified User Login Guard\n";
$loginRes = Auth::login($testPhone1, $password);
assertCondition($loginRes['success'] === false, "Unverified user cannot log in");
assertCondition(!empty($loginRes['is_pending_verification']), "Login error indicates is_pending_verification = true");
assertCondition($loginRes['email'] === $testEmail1, "Login error returns user's registered email");

// -----------------------------------------------------------------
// TEST 3: Invalid OTP Handling & Attempt Counter
// -----------------------------------------------------------------
echo "\n[Test 3] Invalid OTP Handling\n";
$wrongOtpRes = Auth::verifyEmailOtp($testEmail1, '000000');
assertCondition($wrongOtpRes['success'] === false, "Incorrect OTP is rejected");

$verifStmt->execute([(int)$user['id']]);
$updatedVerif = $verifStmt->fetch();
assertCondition((int)$updatedVerif['attempts'] >= 1, "Attempt counter is incremented on failed OTP");

// -----------------------------------------------------------------
// TEST 4: Valid OTP Activates Account & Establishes Session
// -----------------------------------------------------------------
echo "\n[Test 4] Valid OTP Verification & Account Activation\n";

// Retrieve the actual generated 6-digit OTP from database
$otpStmt = $pdo->prepare("SELECT otp_code FROM email_verifications WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$otpStmt->execute([(int)$user['id']]);
$generatedOtp = $otpStmt->fetchColumn();

assertCondition(!empty($generatedOtp) && strlen($generatedOtp) === 6, "Generated OTP exists in email_verifications table");

$verifyRes = Auth::verifyEmailOtp($testEmail1, $generatedOtp);
assertCondition($verifyRes['success'] === true, "Valid 6-digit OTP verification succeeds");
assertCondition(isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id'], "User session is established upon verification");

$userStmt->execute([$testPhone1]);
$activatedUser = $userStmt->fetch();
assertCondition($activatedUser['account_status'] === 'active', "Account status transitioned to active");
assertCondition(!empty($activatedUser['email_verified_at']), "email_verified_at timestamp is set");
assertCondition($activatedUser['email_verification_otp'] === null, "email_verification_otp is cleared upon activation");

// -----------------------------------------------------------------
// TEST 5: Active User Can Now Log In Successfully
// -----------------------------------------------------------------
echo "\n[Test 5] Active User Login Verification\n";
Auth::logout();
$activeLoginRes = Auth::login($testPhone1, $password);
assertCondition($activeLoginRes['success'] === true, "Active user logs in successfully");
assertCondition(isset($activeLoginRes['user']['id']), "User profile data returned in login response");

// -----------------------------------------------------------------
// TEST 6: 1-Click Verification Link Token Lifecycle
// -----------------------------------------------------------------
echo "\n[Test 6] 1-Click Token Link Verification\n";
$testPhone2 = '990002' . rand(1000, 9999);
$testEmail2 = 'tester_link_' . uniqid() . '@example.com';
$regRes2 = Auth::register($testPhone2, $password, null, null, 'Link Tester', $testEmail2, false);

$user2Stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$user2Stmt->execute([$testPhone2]);
$user2 = $user2Stmt->fetch();
$token = $user2['email_verification_token'];

assertCondition(!empty($token), "Registration generated secure verification token");

// Test invalid token
$invalidTokenRes = Auth::verifyEmailToken($testEmail2, 'invalid_token_12345');
assertCondition($invalidTokenRes['success'] === false, "Invalid verification token is rejected");

// Test valid token
$validTokenRes = Auth::verifyEmailToken($testEmail2, $token);
assertCondition($validTokenRes['success'] === true, "Valid 1-click token activates account");

$user2Stmt->execute([$testPhone2]);
$activatedUser2 = $user2Stmt->fetch();
assertCondition($activatedUser2['account_status'] === 'active', "User 2 account status transitioned to active");
assertCondition(!empty($activatedUser2['email_verified_at']), "User 2 email_verified_at timestamp populated");

// Test token replay (cannot use same token twice for re-activation)
$replayRes = Auth::verifyEmailToken($testEmail2, $token);
assertCondition(!empty($replayRes['already_verified']), "Replay of used token safely indicates already verified");

// -----------------------------------------------------------------
// TEST 7: Resend OTP & Rate Limiting (60s Cooldown)
// -----------------------------------------------------------------
echo "\n[Test 7] Resend OTP & Cooldown Rate Limiting\n";
$testPhone3 = '990003' . rand(1000, 9999);
$testEmail3 = 'tester_resend_' . uniqid() . '@example.com';
Auth::register($testPhone3, $password, null, null, 'Resend Tester', $testEmail3, false);

// First resend (should succeed)
$resendRes1 = Auth::resendEmailOtp($testEmail3);
assertCondition($resendRes1['success'] === true, "First resend OTP request succeeds");

// Immediate second resend (should be rate-limited by 60-second cooldown)
$resendRes2 = Auth::resendEmailOtp($testEmail3);
assertCondition($resendRes2['success'] === false, "Second immediate resend OTP is rate-limited (cooldown enforced)");
assertCondition(strpos($resendRes2['error'], '60 seconds') !== false, "Rate limit message informs user of 60 seconds cooldown");

// -----------------------------------------------------------------
// TEST 8: Re-Registration of Pending Account Updates Credentials
// -----------------------------------------------------------------
echo "\n[Test 8] Re-Registration of Unverified Account\n";
$newPassword = 'NewPassword@456';
$updatedEmail = 'updated_' . uniqid() . '@example.com';
$reRegRes = Auth::register($testPhone3, $newPassword, null, null, 'Updated Resend Tester', $updatedEmail, false);
assertCondition($reRegRes['success'] === true, "Unverified user can re-register to correct email/password");

$user3Stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
$user3Stmt->execute([$testPhone3]);
$user3 = $user3Stmt->fetch();
assertCondition($user3['email'] === $updatedEmail, "Email was updated on re-registration");
assertCondition(password_verify($newPassword, $user3['password_hash']), "Password hash was updated to new password");

// -----------------------------------------------------------------
// TEST 9: Active Account Cannot Be Overwritten by Re-Registration
// -----------------------------------------------------------------
echo "\n[Test 9] Active Account Re-Registration Block\n";
$reRegActiveRes = Auth::register($testPhone1, 'HackerPass@123', null, null, 'Duplicate Tester', 'hacker@example.com', false);
assertCondition($reRegActiveRes['success'] === false, "Active account cannot be overwritten by re-registration");
assertCondition(strpos($reRegActiveRes['error'], 'already exists') !== false, "Error message directs user to sign in");

echo "\n------------------------------------------------------\n";
echo "SUMMARY: {$testsPassed} / {$totalTests} Tests Passed (" . round(($testsPassed / $totalTests) * 100, 1) . "%)\n";
echo "------------------------------------------------------\n";

if ($testsPassed === $totalTests) {
    echo "SUCCESS: All Email Verification tests passed completely!\n";
    exit(0);
} else {
    echo "FAILURE: Some email verification tests failed.\n";
    exit(1);
}
