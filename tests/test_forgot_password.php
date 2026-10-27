<?php
/**
 * Comprehensive Automated Test Suite: Forgot Password & Recovery Flow
 */

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');
putenv('MOCK_EMAIL_DELIVERY=1');
$_ENV['MOCK_EMAIL_DELIVERY'] = '1';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EmailService.php';

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
echo " FORGOT PASSWORD & RECOVERY AUTOMATED TEST SUITE\n";
echo "======================================================\n\n";

$testPhone1 = '980001' . rand(1000, 9999);
$testEmail1 = 'forgot_user_' . uniqid() . '@example.com';
$initialPass = 'OriginalPass@123';

// Setup: Register and activate user
$regRes = Auth::register($testPhone1, $initialPass, null, 'user@upi', 'Forgot User 1', $testEmail1, false);
assertCondition($regRes['success'] === true, "User registration succeeds");

// Activate user via OTP
$otpStmt = $pdo->prepare("SELECT otp_code FROM email_verifications WHERE email = ? ORDER BY id DESC LIMIT 1");
$otpStmt->execute([$testEmail1]);
$regOtp = $otpStmt->fetchColumn();
$actRes = Auth::verifyEmailOtp($testEmail1, $regOtp);
assertCondition($actRes['success'] === true, "User account is verified & active");

// Verify can log in with initial password
$loginRes = Auth::login($testEmail1, $initialPass);
assertCondition($loginRes['success'] === true, "Can log in with initial password");

// -----------------------------------------------------------------
// TEST 1: Request Password Reset with Invalid Email
// -----------------------------------------------------------------
echo "\n[Test 1] Request Password Reset with Non-Existent Email\n";
$nonExistentRes = Auth::requestPasswordReset('nonexistent_' . uniqid() . '@jelipay.in');
assertCondition($nonExistentRes['success'] === false, "Non-existent email is rejected");

// -----------------------------------------------------------------
// TEST 2: Request Password Reset with Valid Registered Email
// -----------------------------------------------------------------
echo "\n[Test 2] Request Password Reset with Registered Email\n";
EmailService::clearMockEmails();
$resetReqRes = Auth::requestPasswordReset($testEmail1);
assertCondition($resetReqRes['success'] === true, "Auth::requestPasswordReset succeeds");
assertCondition(!empty($resetReqRes['masked_email']), "Response contains masked email");
assertCondition($resetReqRes['email'] === $testEmail1, "Response contains clean email");

// Check database record
$prStmt = $pdo->prepare("SELECT * FROM password_resets WHERE email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
$prStmt->execute([$testEmail1]);
$prRecord = $prStmt->fetch();
assertCondition(!empty($prRecord), "Record created in password_resets table");
assertCondition(strlen($prRecord['otp_code']) === 6, "6-digit OTP code stored");
assertCondition(!empty($prRecord['reset_token']), "32-byte reset token stored");
assertCondition($prRecord['status'] === 'pending', "Status is pending");

// Check user row updated
$uStmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$uStmt->execute([$testEmail1]);
$uRecord = $uStmt->fetch();
assertCondition(!empty($uRecord['password_reset_otp']), "User password_reset_otp populated");
assertCondition(!empty($uRecord['password_reset_token']), "User password_reset_token populated");

// Check email dispatch
$mockEmails = EmailService::getMockEmails();
assertCondition(count($mockEmails) >= 1, "Password reset email dispatched");
assertCondition(strpos($mockEmails[0]['subject'], 'Reset Your JeliPay Password') !== false, "Email subject is correct");
assertCondition(strpos($mockEmails[0]['html'], $prRecord['otp_code']) !== false, "Email contains 6-digit OTP code");
assertCondition(strpos($mockEmails[0]['html'], $prRecord['reset_token']) !== false, "Email contains 1-click reset link");

// -----------------------------------------------------------------
// TEST 3: Rate Limiting on Immediate Duplicate Reset Request
// -----------------------------------------------------------------
echo "\n[Test 3] Rate Limiting Cooldown on Reset Requests\n";
$rateLimitRes = Auth::requestPasswordReset($testEmail1);
assertCondition($rateLimitRes['success'] === false, "Immediate duplicate request is blocked by rate limiter");
assertCondition(strpos($rateLimitRes['error'], '60 seconds') !== false, "Error message states 60-second cooldown");

// -----------------------------------------------------------------
// TEST 4: Invalid OTP Verification & Attempt Tracking
// -----------------------------------------------------------------
echo "\n[Test 4] Invalid OTP Verification & Attempt Tracking\n";
$badOtpRes = Auth::verifyPasswordResetOtp($testEmail1, '000000');
assertCondition($badOtpRes['success'] === false, "Incorrect OTP is rejected");
assertCondition(strpos($badOtpRes['error'], 'Incorrect reset code') !== false, "Error message specifies incorrect code");

$prStmt->execute([$testEmail1]);
$prUpdated = $prStmt->fetch();
assertCondition((int)$prUpdated['attempts'] === 1, "Attempts incremented to 1");

// -----------------------------------------------------------------
// TEST 5: Valid OTP Verification
// -----------------------------------------------------------------
echo "\n[Test 5] Valid OTP Verification\n";
$correctOtp = $prRecord['otp_code'];
$verifyOtpRes = Auth::verifyPasswordResetOtp($testEmail1, $correctOtp);
assertCondition($verifyOtpRes['success'] === true, "Valid OTP is accepted");
assertCondition(!empty($verifyOtpRes['reset_token']), "Response returns authorization reset_token");

// -----------------------------------------------------------------
// TEST 6: Password Reset Validation (Length & Confirmation Mismatch)
// -----------------------------------------------------------------
echo "\n[Test 6] Password Reset Validation\n";
$shortPassRes = Auth::resetPassword($testEmail1, $correctOtp, '123', '123');
assertCondition($shortPassRes['success'] === false, "Short password (<6 chars) rejected");

$mismatchPassRes = Auth::resetPassword($testEmail1, $correctOtp, 'NewPassword@123', 'DifferentPassword@123');
assertCondition($mismatchPassRes['success'] === false, "Mismatched confirmation password rejected");

// -----------------------------------------------------------------
// TEST 7: Successful Password Reset via OTP
// -----------------------------------------------------------------
echo "\n[Test 7] Successful Password Reset via OTP\n";
$newPassword1 = 'NewSecretPassword@2026';
$resetSuccessRes = Auth::resetPassword($testEmail1, $correctOtp, $newPassword1, $newPassword1);
assertCondition($resetSuccessRes['success'] === true, "Password reset with valid OTP succeeds");
assertCondition(!empty($resetSuccessRes['user']), "User details returned in response");
assertCondition($_SESSION['user_id'] == $uRecord['id'], "User session is established automatically upon reset");

// Verify password_resets record is now marked 'used'
$checkPr = $pdo->prepare("SELECT * FROM password_resets WHERE id = ?");
$checkPr->execute([(int)$prRecord['id']]);
$usedPr = $checkPr->fetch();
assertCondition($usedPr['status'] === 'used', "Reset record status transitioned to 'used'");
assertCondition(!empty($usedPr['used_at']), "used_at timestamp recorded");

// Verify cannot reuse same OTP again
$reuseRes = Auth::resetPassword($testEmail1, $correctOtp, 'AnotherPass@123', 'AnotherPass@123');
assertCondition($reuseRes['success'] === false, "Reusing already used OTP is strictly rejected");

// -----------------------------------------------------------------
// TEST 8: Login with Old vs New Password
// -----------------------------------------------------------------
echo "\n[Test 8] Login Guard with Old vs New Password\n";
$oldLogin = Auth::login($testEmail1, $initialPass);
assertCondition($oldLogin['success'] === false, "Login with old password now fails");

$newLogin = Auth::login($testEmail1, $newPassword1);
assertCondition($newLogin['success'] === true, "Login with newly reset password succeeds");

// -----------------------------------------------------------------
// TEST 9: 1-Click Reset Link Flow via Token
// -----------------------------------------------------------------
echo "\n[Test 9] 1-Click Reset Link Verification & Reset via Token\n";

// Clear rate limits for test user
$pdo->exec("DELETE FROM rate_limits");

$req2 = Auth::requestPasswordReset($testEmail1);
assertCondition($req2['success'] === true, "Second reset request succeeds");

$prStmt->execute([$testEmail1]);
$prRecord2 = $prStmt->fetch();
$resetToken = $prRecord2['reset_token'];

// Verify token
$tokenCheck = Auth::verifyPasswordResetToken($testEmail1, $resetToken);
assertCondition($tokenCheck['success'] === true, "Auth::verifyPasswordResetToken validates token successfully");

$invalidTokenCheck = Auth::verifyPasswordResetToken($testEmail1, 'invalid_token_123');
assertCondition($invalidTokenCheck['success'] === false, "Invalid reset token is rejected");

// Reset password using token (no OTP entered)
$newPassword2 = 'LinkResetPassword@999';
$tokenResetRes = Auth::resetPassword($testEmail1, $resetToken, $newPassword2, $newPassword2);
assertCondition($tokenResetRes['success'] === true, "Password reset via token succeeds without OTP");

// Verify login with latest password
$tokenLogin = Auth::login($testEmail1, $newPassword2);
assertCondition($tokenLogin['success'] === true, "Login with token-reset password succeeds");

// -----------------------------------------------------------------
// TEST 10: Suspended Account Guard
// -----------------------------------------------------------------
echo "\n[Test 10] Suspended Account Reset Guard\n";
$pdo->prepare("UPDATE users SET account_status = 'suspended' WHERE id = ?")->execute([(int)$uRecord['id']]);
$pdo->exec("DELETE FROM rate_limits");

$suspendedReq = Auth::requestPasswordReset($testEmail1);
assertCondition($suspendedReq['success'] === false, "Suspended account cannot request password reset");
assertCondition(strpos($suspendedReq['error'], 'suspended') !== false, "Error message informs account is suspended");

// Re-activate
$pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([(int)$uRecord['id']]);

// -----------------------------------------------------------------
// TEST 11: Expired Reset Code / Session Guard
// -----------------------------------------------------------------
echo "\n[Test 11] Expired Reset Code Guard\n";
$pdo->exec("DELETE FROM rate_limits");
$req3 = Auth::requestPasswordReset($testEmail1);
$prStmt->execute([$testEmail1]);
$prRecord3 = $prStmt->fetch();

// Artificially expire the record in the past
$pastTime = gmdate('Y-m-d H:i:s', time() - 3600);
$pdo->prepare("UPDATE password_resets SET expires_at = ? WHERE id = ?")->execute([$pastTime, (int)$prRecord3['id']]);

$expiredVerify = Auth::verifyPasswordResetOtp($testEmail1, $prRecord3['otp_code']);
assertCondition($expiredVerify['success'] === false, "Expired OTP verification is rejected");
assertCondition(strpos($expiredVerify['error'], 'expired') !== false, "Error message states code has expired");

$expiredReset = Auth::resetPassword($testEmail1, $prRecord3['otp_code'], 'AnotherPass@123', 'AnotherPass@123');
assertCondition($expiredReset['success'] === false, "Expired password reset is rejected");

echo "\n------------------------------------------------------\n";
echo "SUMMARY: {$testsPassed} / {$totalTests} Tests Passed (" . round(($testsPassed / $totalTests) * 100, 1) . "%)\n";
echo "------------------------------------------------------\n";

if ($testsPassed === $totalTests) {
    echo "SUCCESS: All Forgot Password & Recovery tests passed completely!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
