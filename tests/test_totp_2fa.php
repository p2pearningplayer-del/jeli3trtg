<?php
/**
 * Comprehensive Automated Verification Suite for Google Authenticator TOTP 2FA
 * Tests:
 * 1. Base32 encoding/decoding RFC test vectors
 * 2. Authenticated AES-256-GCM secret encryption at rest
 * 3. Offline vector SVG QR code generation & zero external leaks
 * 4. RFC 6238 TOTP computation & drift tolerance
 * 5. Replay attack prevention
 * 6. Single-use cryptographically secure recovery codes & hash verification
 * 7. End-to-end user enrollment, activation, and status API responses
 * 8. Password change protection: enforcement when 2FA enabled, rejection of invalid/replayed codes,
 *    acceptance of valid TOTP codes and recovery codes, and session regeneration
 * 9. Recovery codes regeneration and secure 2FA disabling
 */

declare(strict_types=1);

putenv('DB_DRIVER=sqlite');
$_ENV['DB_DRIVER'] = 'sqlite';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Security/TotpService.php';
require_once __DIR__ . '/../src/Security/QRCodeGenerator.php';

use Security\TotpService;
use Security\QRCodeGenerator;

// Initialize fresh SQLite test database in memory or local test file
$testDbFile = __DIR__ . '/test_totp_suite.sqlite';
if (file_exists($testDbFile)) {
    @unlink($testDbFile);
}
putenv("SQLITE_PATH={$testDbFile}");
$_ENV['SQLITE_PATH'] = $testDbFile;

Database::reset();
SchemaInstaller::ensureSchema();
$pdo = Database::getConnection('sqlite');

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$msg}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$msg}\n";
    }
}

echo "\n=======================================================\n";
echo "  JELIPAY TWO-FACTOR AUTHENTICATION (TOTP) TEST SUITE  \n";
echo "=======================================================\n\n";

// -----------------------------------------------------------
// TEST GROUP 1: Base32 RFC 4648 Vectors & Secret Generation
// -----------------------------------------------------------
echo "--- Group 1: RFC 4648 Base32 Encoding / Decoding ---\n";

$vectors = [
    "" => "",
    "f" => "MY",
    "fo" => "MZXQ",
    "foo" => "MZXW6",
    "foob" => "MZXW6YQ",
    "fooba" => "MZXW6YTB",
    "foobar" => "MZXW6YTBOI"
];

foreach ($vectors as $raw => $b32) {
    $encoded = TotpService::base32Encode($raw);
    assertCondition($encoded === $b32, "Base32 encode '{$raw}' => '{$b32}'");
    $decoded = TotpService::base32Decode($b32);
    assertCondition($decoded === $raw, "Base32 decode '{$b32}' => '{$raw}'");
}

$secret = TotpService::generateSecret(20);
assertCondition(strlen($secret) === 32, "Generated 160-bit Base32 secret length is 32 chars: {$secret}");
assertCondition((bool)preg_match('/^[A-Z2-7]+$/', $secret), "Secret contains only valid Base32 characters");

// -----------------------------------------------------------
// TEST GROUP 2: Authenticated AES-256-GCM Encryption at Rest
// -----------------------------------------------------------
echo "\n--- Group 2: Authenticated AES-256-GCM Secret Encryption at Rest ---\n";

$plaintextSecret = TotpService::generateSecret(20);
$encrypted = TotpService::encryptSecret($plaintextSecret);

assertCondition($encrypted !== $plaintextSecret, "Encrypted payload differs from plaintext");
assertCondition(strlen($encrypted) > 40, "Encrypted payload has valid Base64 length with IV + Tag + Ciphertext");

$decrypted = TotpService::decryptSecret($encrypted);
assertCondition($decrypted === $plaintextSecret, "Decrypted secret matches original plaintext exactly");

// Tampering detection (AEAD tag mismatch)
$rawEnc = base64_decode($encrypted);
$tamperedRaw = substr($rawEnc, 0, -2) . chr(ord(substr($rawEnc, -1)) ^ 0xFF);
$tamperedPayload = base64_encode($tamperedRaw);
$tamperedResult = TotpService::decryptSecret($tamperedPayload);
assertCondition($tamperedResult === null, "Tampered ciphertext payload fails authenticated decryption safely (returns null)");

// -----------------------------------------------------------
// TEST GROUP 3: Offline Vector SVG QR Code & Provisioning URI
// -----------------------------------------------------------
echo "\n--- Group 3: Offline Vector SVG QR Code & Provisioning URI ---\n";

$uri = TotpService::getProvisioningUri($secret, "9876543210", "JeliPay");
assertCondition(str_starts_with($uri, 'otpauth://totp/'), "URI scheme starts with otpauth://totp/");
assertCondition(str_contains($uri, "secret={$secret}"), "URI contains correct secret parameter");
assertCondition(str_contains($uri, "issuer=JeliPay"), "URI contains correct issuer parameter");

$svg = QRCodeGenerator::generateSvg($uri, 220, '#00e5ff', '#020617');
assertCondition(str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'), "QR generator returns valid XML <svg> element");
assertCondition(!str_contains($svg, 'http://') && !str_contains($svg, 'https://') || !str_contains($svg, 'chart.googleapis.com'), "Zero external API calls or third-party image URLs in SVG");
assertCondition(str_contains($svg, 'viewBox="0 0'), "SVG contains responsive viewBox");

// -----------------------------------------------------------
// TEST GROUP 4: RFC 6238 TOTP Computation, Drift & Replay
// -----------------------------------------------------------
echo "\n--- Group 4: RFC 6238 TOTP Computation, Drift Tolerance & Replay Protection ---\n";

$testTimestamp = 1700000000; // Fixed reference timestamp
$expectedStep = (int)floor($testTimestamp / 30);
$codeAtT = TotpService::calculateTotp($secret, $testTimestamp);

assertCondition(strlen($codeAtT) === 6 && ctype_digit($codeAtT), "Calculated TOTP is exactly 6 digits: {$codeAtT}");

// Verify at exact timestamp
$resCurrent = TotpService::verifyCode($secret, $codeAtT, 0, 1, $testTimestamp);
assertCondition($resCurrent['valid'] === true && $resCurrent['step'] === $expectedStep, "Exact timestamp verification succeeds");

// Verify at +25s (same 30s step)
$resSameStep = TotpService::verifyCode($secret, $codeAtT, 0, 1, $testTimestamp + 25);
assertCondition($resSameStep['valid'] === true, "Verification within same 30s step succeeds");

// Verify at +35s (1 step clock drift forward)
$resDriftForward = TotpService::verifyCode($secret, $codeAtT, 0, 1, $testTimestamp + 35);
assertCondition($resDriftForward['valid'] === true, "+1 step clock drift (future) verification succeeds");

// Verify at -25s (1 step clock drift backward)
$resDriftBackward = TotpService::verifyCode($secret, $codeAtT, 0, 1, $testTimestamp - 25);
assertCondition($resDriftBackward['valid'] === true, "-1 step clock drift (past) verification succeeds");

// Verify beyond 1 step window (+70s should fail)
$resOutOfWindow = TotpService::verifyCode($secret, $codeAtT, 0, 1, $testTimestamp + 70);
assertCondition($resOutOfWindow['valid'] === false, "Out of window verification (+70s) is strictly rejected");

// REPLAY ATTACK PREVENTION
// If the user submits the exact same code, and $lastUsedStep is already set to $expectedStep:
$replayAttempt = TotpService::verifyCode($secret, $codeAtT, $expectedStep, 1, $testTimestamp);
assertCondition($replayAttempt['valid'] === false && $replayAttempt['code'] === 'REPLAYED_CODE', "Replay of code within same step is strictly rejected with REPLAYED_CODE error");

// -----------------------------------------------------------
// TEST GROUP 5: Single-Use Recovery Codes
// -----------------------------------------------------------
echo "\n--- Group 5: Single-Use Cryptographically Secure Recovery Codes ---\n";

$recoveryData = TotpService::generateRecoveryCodes(8);
assertCondition(count($recoveryData['plain']) === 8, "Generated exactly 8 plain recovery codes");
assertCondition(count($recoveryData['stored']) === 8, "Generated exactly 8 stored hash records");

$sampleCode = $recoveryData['plain'][0];
assertCondition((bool)preg_match('/^[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}$/', $sampleCode), "Recovery code format matches XXXX-XXXX unambiguous charset: {$sampleCode}");

// First use of recovery code
$use1 = TotpService::verifyRecoveryCode($sampleCode, $recoveryData['stored']);
assertCondition($use1['valid'] === true, "First use of recovery code succeeds");
assertCondition($use1['updated_codes'][0]['used'] === true, "Recovery code is marked as used with timestamp");

// Second use of the EXACT same recovery code (must fail)
$use2 = TotpService::verifyRecoveryCode($sampleCode, $use1['updated_codes']);
assertCondition($use2['valid'] === false, "Second use of the same recovery code is strictly rejected");

// Test normalized entry without hyphen (e.g. user types 8 characters directly)
$sampleCode2 = $recoveryData['plain'][1];
$unhyphenated = str_replace('-', '', $sampleCode2);
$useUnhyphenated = TotpService::verifyRecoveryCode($unhyphenated, $use1['updated_codes']);
assertCondition($useUnhyphenated['valid'] === true, "Unhyphenated entry normalization works correctly");

// -----------------------------------------------------------
// TEST GROUP 6: End-to-End User Enrollment & Activation
// -----------------------------------------------------------
echo "\n--- Group 6: End-to-End User Enrollment, Activation & API State ---\n";

$regPhone = '9888877777';
$regPass = 'SecretP@ss123';
$regRes = Auth::register($regPhone, $regPass, null, 'seller@upi', 'Security Tester');
assertCondition($regRes['success'] === true, "Test user registered successfully");

$userId = (int)$regRes['user_id'];

// Initial 2FA status
$status1 = Auth::getTotpStatus($userId);
assertCondition($status1['success'] === true && $status1['enabled'] === false, "User initial 2FA status is disabled");

// Check that getCurrentUser() does not expose totp_secret
$_SESSION['user_id'] = $userId;
$currentUser = Auth::getCurrentUser();
assertCondition(isset($currentUser['totp_enabled']) && $currentUser['totp_enabled'] === false, "getCurrentUser includes totp_enabled boolean");
assertCondition(!array_key_exists('totp_secret', $currentUser), "getCurrentUser NEVER exposes totp_secret");
assertCondition(!array_key_exists('totp_temp_secret', $currentUser), "getCurrentUser NEVER exposes totp_temp_secret");
assertCondition(!array_key_exists('totp_recovery_codes', $currentUser), "getCurrentUser NEVER exposes totp_recovery_codes");

// Initiate setup
$setupRes = Auth::initiateTotpSetup($userId);
assertCondition($setupRes['success'] === true, "TOTP setup initiated successfully");
assertCondition(!empty($setupRes['secret']) && !empty($setupRes['qr_svg']), "Setup response returns secret and QR SVG");

// Check DB: temporary secret is encrypted, totp_enabled is still 0
$dbUser = $pdo->query("SELECT totp_enabled, totp_secret, totp_temp_secret FROM users WHERE id = {$userId}")->fetch();
assertCondition((int)$dbUser['totp_enabled'] === 0, "totp_enabled remains 0 prior to verification");
assertCondition($dbUser['totp_secret'] === null, "Permanent totp_secret is NULL prior to verification");
assertCondition($dbUser['totp_temp_secret'] !== $setupRes['secret'], "totp_temp_secret in database is encrypted at rest");

// Attempt verification with invalid code
$invalidEnroll = Auth::verifyTotpEnrollment($userId, "000000");
assertCondition($invalidEnroll['success'] === false, "Verification with incorrect code fails");

$dbUserStillInactive = $pdo->query("SELECT totp_enabled FROM users WHERE id = {$userId}")->fetch();
assertCondition((int)$dbUserStillInactive['totp_enabled'] === 0, "2FA is NOT activated on invalid code");

// Verification with valid live code at t0
$t0 = 1700000000;
$validCode = TotpService::calculateTotp($setupRes['secret'], $t0);
$validEnroll = Auth::verifyTotpEnrollment($userId, $validCode, $t0);
assertCondition($validEnroll['success'] === true, "Verification with valid 6-digit code succeeds");
assertCondition(isset($validEnroll['recovery_codes']) && count($validEnroll['recovery_codes']) === 8, "Enrollment returns 8 plain recovery codes");

// Verify database activation state
$dbUserActive = $pdo->query("SELECT totp_enabled, totp_secret, totp_temp_secret, totp_recovery_codes FROM users WHERE id = {$userId}")->fetch();
assertCondition((int)$dbUserActive['totp_enabled'] === 1, "totp_enabled is now 1 in database");
assertCondition($dbUserActive['totp_temp_secret'] === null, "totp_temp_secret is cleared after successful enrollment");
assertCondition(!empty($dbUserActive['totp_secret']), "Permanent encrypted totp_secret is saved");

$status2 = Auth::getTotpStatus($userId);
assertCondition($status2['enabled'] === true && $status2['remaining_recovery_codes'] === 8, "getTotpStatus reports 2FA enabled with 8 remaining recovery codes");

// -----------------------------------------------------------
// TEST GROUP 7: Password Change Protection with 2FA
// -----------------------------------------------------------
echo "\n--- Group 7: Password Change Protection with 2FA Enforcement ---\n";

$newPass1 = 'NewSecretP@ss456';
$t1 = $t0 + 30; // Next 30s step

// 1. Password change without TOTP code must fail with requires_totp
$noTotpRes = Auth::changePassword($userId, $regPass, $newPass1, null, $t1);
assertCondition($noTotpRes['success'] === false && !empty($noTotpRes['requires_totp']), "Password change without 2FA code is rejected with requires_totp flag");

// 2. Password change with invalid TOTP code
$badTotpRes = Auth::changePassword($userId, $regPass, $newPass1, "111222", $t1);
assertCondition($badTotpRes['success'] === false, "Password change with incorrect 2FA code is rejected");

// 3. Password change with wrong current password even if TOTP is valid
$liveTotp = TotpService::calculateTotp($setupRes['secret'], $t1);
$wrongCurPassRes = Auth::changePassword($userId, "WrongCurrentPass!", $newPass1, $liveTotp, $t1);
assertCondition($wrongCurPassRes['success'] === false && str_contains($wrongCurPassRes['error'], 'Current password'), "Password change with incorrect current password is rejected");

// 4. Password change with valid current password + valid live TOTP code
$oldSessionId = session_id();
$validPwdChange = Auth::changePassword($userId, $regPass, $newPass1, $liveTotp, $t1);
assertCondition($validPwdChange['success'] === true, "Password change with valid current password + live TOTP code succeeds");

// Verify audit notification was recorded
$notifStmt = $pdo->prepare("SELECT title, message, type FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$notifStmt->execute([$userId]);
$latestNotif = $notifStmt->fetch();
assertCondition($latestNotif && str_contains($latestNotif['title'], 'Password'), "Audit notification recorded for password update: {$latestNotif['title']}");

// 5. Replay protection on password change: immediate reuse of same TOTP code should fail
$replayPwdChange = Auth::changePassword($userId, $newPass1, "AnotherPass789", $liveTotp, $t1);
assertCondition($replayPwdChange['success'] === false, "Immediate replay of accepted TOTP code for password change is rejected");

// 6. Password change using single-use emergency backup recovery code
$storedRecoveryCodes = $validEnroll['recovery_codes'];
$recoveryCodeToUse = $storedRecoveryCodes[0];
$newPass2 = 'PasswordViaRecovery123!';

$recoveryPwdChange = Auth::changePassword($userId, $newPass1, $newPass2, $recoveryCodeToUse, $t1);
assertCondition($recoveryPwdChange['success'] === true, "Password change using backup recovery code succeeds");

// Check remaining recovery codes
$status3 = Auth::getTotpStatus($userId);
assertCondition($status3['remaining_recovery_codes'] === 7, "Remaining recovery codes reduced from 8 to 7");

// Reusing the same recovery code must fail
$reuseRecCode = Auth::changePassword($userId, $newPass2, "AnotherPass999", $recoveryCodeToUse, $t1);
assertCondition($reuseRecCode['success'] === false, "Reusing consumed backup recovery code is rejected");

// -----------------------------------------------------------
// TEST GROUP 8: Recovery Codes Regeneration & 2FA Disabling
// -----------------------------------------------------------
echo "\n--- Group 8: Recovery Codes Regeneration & 2FA Disabling ---\n";

$t2 = $t0 + 60; // Step +2
$totpForRegen = TotpService::calculateTotp($setupRes['secret'], $t2);
$regenRes = Auth::regenerateRecoveryCodes($userId, $newPass2, $totpForRegen, $t2);
assertCondition($regenRes['success'] === true && count($regenRes['recovery_codes']) === 8, "Recovery codes regenerated successfully with valid password and TOTP");

$status4 = Auth::getTotpStatus($userId);
assertCondition($status4['remaining_recovery_codes'] === 8, "All 8 recovery codes restored fresh");

$t3 = $t0 + 90; // Step +3
$totpForDisable = TotpService::calculateTotp($setupRes['secret'], $t3);
$badPassDisable = Auth::disableTotp($userId, "WrongPassword", $totpForDisable, $t3);
assertCondition($badPassDisable['success'] === false, "Disable 2FA with wrong password is rejected");

// Disable 2FA with correct password and valid TOTP code
$disableRes = Auth::disableTotp($userId, $newPass2, $totpForDisable, $t3);
assertCondition($disableRes['success'] === true, "Disable 2FA with correct password and TOTP succeeds");

$status5 = Auth::getTotpStatus($userId);
assertCondition($status5['enabled'] === false, "2FA is now fully disabled");

$dbUserDisabled = $pdo->query("SELECT totp_enabled, totp_secret, totp_recovery_codes FROM users WHERE id = {$userId}")->fetch();
assertCondition((int)$dbUserDisabled['totp_enabled'] === 0 && $dbUserDisabled['totp_secret'] === null && $dbUserDisabled['totp_recovery_codes'] === null, "Database columns wiped clean on disable");

// Verify password change works normally without 2FA when disabled
$newPass3 = 'PasswordWithout2FA123!';
$pwdChangeDisabled = Auth::changePassword($userId, $newPass2, $newPass3, null, $t3);
assertCondition($pwdChangeDisabled['success'] === true, "Password change succeeds without 2FA code when 2FA is disabled");

// Cleanup test sqlite db
@unlink($testDbFile);

echo "\n=======================================================\n";
echo "  TEST SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================\n\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
