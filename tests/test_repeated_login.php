<?php
putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');
putenv('MOCK_EMAIL_DELIVERY=1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';

SchemaInstaller::ensureSchema();

$phone = '9888877771';
$pass = 'Pass1234';
$email = 'login_test@example.com';

// Clean existing
$pdo = Database::getConnection();
$pdo->prepare("DELETE FROM users WHERE phone = ?")->execute([$phone]);
$pdo->prepare("DELETE FROM email_verifications WHERE email = ?")->execute([$email]);

// 1. Register
$reg = Auth::register($phone, $pass, null, null, 'Test User', $email, false);
echo "Register: " . json_encode($reg['success']) . "\n";

// 2. Verify OTP (First login)
$otp = $pdo->query("SELECT otp_code FROM email_verifications WHERE email = '{$email}'")->fetchColumn();
$v = Auth::verifyEmailOtp($email, $otp);
echo "Verify OTP (First login): " . json_encode($v['success']) . " UserID=" . ($_SESSION['user_id'] ?? 'none') . "\n";

// Check me endpoint
$currentUser = Auth::getCurrentUser();
echo "Current user after first login: " . ($currentUser ? $currentUser['id'] : 'null') . "\n";

// 3. Logout
Auth::logout();
echo "After logout: UserID=" . ($_SESSION['user_id'] ?? 'none') . "\n";

// 4. Login Attempt 1
$l1 = Auth::login($phone, $pass);
echo "Login 1: " . json_encode($l1['success']) . " UserID=" . ($_SESSION['user_id'] ?? 'none') . "\n";

// 5. Logout
Auth::logout();

// 6. Login Attempt 2 with Email
$l2 = Auth::login($email, $pass);
echo "Login 2 (with email): " . json_encode($l2['success']) . " UserID=" . ($_SESSION['user_id'] ?? 'none') . "\n";

// 7. Logout
Auth::logout();

// 8. Login Attempt 3 with User ID
$uid = (string)$currentUser['id'];
$l3 = Auth::login($uid, $pass);
echo "Login 3 (with User ID {$uid}): " . json_encode($l3['success']) . " Error=" . ($l3['error'] ?? 'none') . "\n";
