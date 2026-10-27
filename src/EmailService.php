<?php
declare(strict_types=1);

/**
 * JeliPay - Production Email Verification & Hostinger Mailing Service
 *
 * Handles SMTP delivery over SSL/TLS using provided Hostinger credentials.
 * Implements OTP delivery, 1-click email verification links, and account activation.
 */

class EmailService {
    /**
     * In-memory mock storage for automated tests when MOCK_EMAIL_DELIVERY=1
     */
    private static array $mockSentEmails = [];

    /**
     * Clear mock sent emails (for test suites)
     */
    public static function clearMockEmails(): void {
        self::$mockSentEmails = [];
    }

    /**
     * Get mock sent emails (for test suites)
     */
    public static function getMockEmails(): array {
        return self::$mockSentEmails;
    }

    /**
     * Send email via Hostinger SMTP (ssl://smtp.hostinger.com:465)
     */
    public static function sendSmtpEmail(string $toEmail, string $subject, string $htmlBody, ?string $textBody = null): array {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid recipient email address.'];
        }

        // Mock delivery for test suites or when explicitly instructed via environment
        if (getenv('MOCK_EMAIL_DELIVERY') === '1') {
            self::$mockSentEmails[] = [
                'to' => $toEmail,
                'subject' => $subject,
                'html' => $htmlBody,
                'sent_at' => gmdate('Y-m-d H:i:s')
            ];
            return ['success' => true, 'mock' => true, 'message' => 'Email captured in mock storage.'];
        }

        $host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.hostinger.com';
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
        $username = defined('SMTP_USER') ? SMTP_USER : 'team@jelipay.in';
        $password = defined('SMTP_PASS') ? SMTP_PASS : 'Owner@team4';
        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'team@jelipay.in';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'JeliPay Security';

        $socketHost = ($port === 465) ? "ssl://{$host}" : "tcp://{$host}";
        $errno = 0;
        $errstr = '';
        $timeout = 15;

        $fp = @stream_socket_client($socketHost . ":{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            error_log("JeliPay EmailService SMTP connect error: {$errno} - {$errstr}");
            return ['success' => false, 'error' => "Failed to connect to mail server: {$errstr}"];
        }

        stream_set_timeout($fp, $timeout);

        $readResp = function($socket): string {
            $resp = '';
            while ($line = fgets($socket)) {
                $resp .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') {
                    break;
                }
            }
            return $resp;
        };

        $sendCommand = function($socket, string $cmd, string $expectedCode) use ($readResp): array {
            fputs($socket, $cmd . "\r\n");
            $resp = $readResp($socket);
            $code = substr(trim($resp), 0, 3);
            if ($code !== $expectedCode) {
                return ['success' => false, 'code' => $code, 'response' => $resp];
            }
            return ['success' => true, 'response' => $resp];
        };

        try {
            // 1. Read greeting (220)
            $greeting = $readResp($fp);
            if (substr(trim($greeting), 0, 3) !== '220') {
                fclose($fp);
                return ['success' => false, 'error' => "Unexpected mail server greeting: {$greeting}"];
            }

            // 2. EHLO
            $ehloRes = $sendCommand($fp, "EHLO jelipay.in", "250");
            if (!$ehloRes['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "EHLO failed: {$ehloRes['response']}"];
            }

            // If port 587, upgrade to TLS if needed
            if ($port === 587) {
                $startTls = $sendCommand($fp, "STARTTLS", "220");
                if ($startTls['success']) {
                    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                    $sendCommand($fp, "EHLO jelipay.in", "250");
                }
            }

            // 3. AUTH LOGIN
            $authRes = $sendCommand($fp, "AUTH LOGIN", "334");
            if (!$authRes['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "AUTH LOGIN failed: {$authRes['response']}"];
            }

            // Username
            $userRes = $sendCommand($fp, base64_encode($username), "334");
            if (!$userRes['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "SMTP username rejected: {$userRes['response']}"];
            }

            // Password
            $passRes = $sendCommand($fp, base64_encode($password), "235");
            if (!$passRes['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "SMTP authentication failed: {$passRes['response']}"];
            }

            // 4. MAIL FROM
            $mailFrom = $sendCommand($fp, "MAIL FROM:<{$fromEmail}>", "250");
            if (!$mailFrom['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "MAIL FROM rejected: {$mailFrom['response']}"];
            }

            // 5. RCPT TO
            $rcptTo = $sendCommand($fp, "RCPT TO:<{$toEmail}>", "250");
            if (!$rcptTo['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "RCPT TO rejected: {$rcptTo['response']}"];
            }

            // 6. DATA
            $dataRes = $sendCommand($fp, "DATA", "354");
            if (!$dataRes['success']) {
                fclose($fp);
                return ['success' => false, 'error' => "DATA rejected: {$dataRes['response']}"];
            }

            // Format RFC 5322 MIME message
            $msgId = '<' . gmdate('YmdHis') . '.' . bin2hex(random_bytes(8)) . '@jelipay.in>';
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

            $headers = [];
            $headers[] = "From: {$encodedFromName} <{$fromEmail}>";
            $headers[] = "To: <{$toEmail}>";
            $headers[] = "Subject: {$encodedSubject}";
            $headers[] = "Date: " . date('r');
            $headers[] = "Message-ID: {$msgId}";
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: base64";
            $headers[] = "X-Mailer: JeliPay-Mailer/2.0";

            $bodyEncoded = rtrim(chunk_split(base64_encode($htmlBody)));
            $fullMessage = implode("\r\n", $headers) . "\r\n\r\n" . $bodyEncoded . "\r\n.\r\n";

            fputs($fp, $fullMessage);
            $sendRes = $readResp($fp);
            $sendCode = substr(trim($sendRes), 0, 3);

            fputs($fp, "QUIT\r\n");
            fclose($fp);

            if ($sendCode !== '250') {
                return ['success' => false, 'error' => "Message rejected by server: {$sendRes}"];
            }

            return [
                'success' => true,
                'message_id' => $msgId,
                'server_response' => trim($sendRes)
            ];

        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            error_log("JeliPay EmailService Exception: " . $e->getMessage());
            return ['success' => false, 'error' => 'Email sending failed: ' . $e->getMessage()];
        }
    }

    /**
     * Send email verification code (OTP) and 1-click verification link to new user
     */
    public static function sendVerificationOtp(string $toEmail, string $otp, string $token, ?string $userName = null): array {
        $appUrl = defined('APP_URL') ? APP_URL : 'https://jelipay.in';
        $verifyLink = $appUrl . '/?verify_email_token=' . urlencode($token) . '&email=' . urlencode($toEmail);
        $displayName = !empty($userName) ? htmlspecialchars(trim($userName)) : 'Valued Trader';

        $subject = "Your JeliPay Verification Code: {$otp}";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JeliPay Email Verification</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #0b0f19; margin: 0; padding: 0; color: #f1f5f9; }
        .wrapper { max-width: 580px; margin: 30px auto; background-color: #0f172a; border: 1px solid #1e293b; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4); }
        .header { background: linear-gradient(135deg, #1e1b4b, #312e81); padding: 32px 30px; text-align: center; border-bottom: 1px solid #3730a3; }
        .header h1 { margin: 0; font-size: 26px; font-weight: 900; color: #ffffff; letter-spacing: -0.5px; }
        .header p { margin: 6px 0 0; color: #c7d2fe; font-size: 13px; font-weight: 500; }
        .content { padding: 36px 30px; }
        .greeting { font-size: 16px; color: #cbd5e1; margin-bottom: 20px; }
        .otp-container { background-color: #020617; border: 2px dashed #4f46e5; border-radius: 16px; padding: 24px 20px; text-align: center; margin: 26px 0; }
        .otp-label { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; font-weight: bold; margin-bottom: 8px; }
        .otp-code { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 38px; font-weight: 900; color: #818cf8; letter-spacing: 10px; margin: 0; }
        .otp-expiry { font-size: 12px; color: #fbbf24; margin-top: 10px; font-weight: 600; }
        .divider { border-top: 1px solid #1e293b; margin: 26px 0; text-align: center; position: relative; }
        .divider-text { position: relative; top: -11px; background-color: #0f172a; padding: 0 12px; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .btn-container { text-align: center; margin: 24px 0; }
        .btn { display: inline-block; background: linear-gradient(135deg, #4f46e5, #4338ca); color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: 800; padding: 14px 34px; border-radius: 12px; box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        .security-box { background-color: #1e293b; border-left: 4px solid #4f46e5; border-radius: 8px; padding: 14px 16px; font-size: 12px; color: #94a3b8; line-height: 1.5; margin-top: 24px; }
        .footer { background-color: #090d16; padding: 20px 30px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #1e293b; }
        .footer a { color: #818cf8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>JeliPay Token Platform</h1>
            <p>Peer-to-Peer Verified Exchange</p>
        </div>
        <div class="content">
            <div class="greeting">Hello <strong>{$displayName}</strong>,</div>
            <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin: 0 0 16px;">
                Thank you for joining JeliPay. To prevent fake registrations and protect your wallet security, please verify your email address to activate your account.
            </p>

            <div class="otp-container">
                <div class="otp-label">Your Email Verification Code</div>
                <div class="otp-code">{$otp}</div>
                <div class="otp-expiry">&#9201; Valid for 15 minutes</div>
            </div>

            <div class="divider">
                <span class="divider-text">OR VERIFY WITH ONE CLICK</span>
            </div>

            <div class="btn-container">
                <a href="{$verifyLink}" class="btn" target="_blank">Verify & Activate Account</a>
            </div>

            <div class="security-box">
                &#128274; <strong>Security Notice:</strong> Never share this verification code or link with anyone. JeliPay administrators will never ask for your verification code or TPIN. If you did not sign up for JeliPay, you can safely ignore this email.
            </div>
        </div>
        <div class="footer">
            &copy; 2026 JeliPay P2P Exchange. All rights reserved.<br>
            Official Communications: <a href="mailto:team@jelipay.in">team@jelipay.in</a> &bull; <a href="https://jelipay.in">https://jelipay.in</a>
        </div>
    </div>
</body>
</html>
HTML;

        return self::sendSmtpEmail($toEmail, $subject, $htmlBody);
    }

    /**
     * Send password reset code (OTP) and 1-click password reset link to user
     */
    public static function sendPasswordResetEmail(string $toEmail, string $otp, string $token, ?string $userName = null): array {
        $appUrl = defined('APP_URL') ? APP_URL : 'https://jelipay.in';
        $resetLink = $appUrl . '/?reset_token=' . urlencode($token) . '&email=' . urlencode($toEmail);
        $displayName = !empty($userName) ? htmlspecialchars(trim($userName)) : 'Valued Trader';

        $subject = "Reset Your JeliPay Password: {$otp}";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JeliPay Password Reset</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #0b0f19; margin: 0; padding: 0; color: #f1f5f9; }
        .wrapper { max-width: 580px; margin: 30px auto; background-color: #0f172a; border: 1px solid #1e293b; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4); }
        .header { background: linear-gradient(135deg, #1e1b4b, #312e81); padding: 32px 30px; text-align: center; border-bottom: 1px solid #3730a3; }
        .header h1 { margin: 0; font-size: 26px; font-weight: 900; color: #ffffff; letter-spacing: -0.5px; }
        .header p { margin: 6px 0 0; color: #c7d2fe; font-size: 13px; font-weight: 500; }
        .content { padding: 36px 30px; }
        .greeting { font-size: 16px; color: #cbd5e1; margin-bottom: 20px; }
        .otp-container { background-color: #020617; border: 2px dashed #4f46e5; border-radius: 16px; padding: 24px 20px; text-align: center; margin: 26px 0; }
        .otp-label { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #94a3b8; font-weight: bold; margin-bottom: 8px; }
        .otp-code { font-family: 'SF Mono', Consolas, Monaco, monospace; font-size: 38px; font-weight: 900; color: #818cf8; letter-spacing: 10px; margin: 0; }
        .otp-expiry { font-size: 12px; color: #fbbf24; margin-top: 10px; font-weight: 600; }
        .divider { border-top: 1px solid #1e293b; margin: 26px 0; text-align: center; position: relative; }
        .divider-text { position: relative; top: -11px; background-color: #0f172a; padding: 0 12px; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .btn-container { text-align: center; margin: 24px 0; }
        .btn { display: inline-block; background: linear-gradient(135deg, #4f46e5, #4338ca); color: #ffffff !important; text-decoration: none; font-size: 14px; font-weight: 800; padding: 14px 34px; border-radius: 12px; box-shadow: 0 8px 20px rgba(79, 70, 229, 0.4); text-transform: uppercase; letter-spacing: 1px; }
        .security-box { background-color: #1e293b; border-left: 4px solid #ef4444; border-radius: 8px; padding: 14px 16px; font-size: 12px; color: #94a3b8; line-height: 1.5; margin-top: 24px; }
        .footer { background-color: #090d16; padding: 20px 30px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #1e293b; }
        .footer a { color: #818cf8; text-decoration: none; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <h1>JeliPay Token Platform</h1>
            <p>Account Security &amp; Password Recovery</p>
        </div>
        <div class="content">
            <div class="greeting">Hello <strong>{$displayName}</strong>,</div>
            <p style="color: #94a3b8; font-size: 14px; line-height: 1.6; margin: 0 0 16px;">
                We received a request to reset the password for your JeliPay account. Please use the 6-digit recovery code below or click the button to set a new password.
            </p>

            <div class="otp-container">
                <div class="otp-label">Password Reset Code</div>
                <div class="otp-code">{$otp}</div>
                <div class="otp-expiry">&#9201; Valid for 15 minutes</div>
            </div>

            <div class="divider">
                <span class="divider-text">OR RESET WITH ONE CLICK</span>
            </div>

            <div class="btn-container">
                <a href="{$resetLink}" class="btn" target="_blank">Reset Account Password</a>
            </div>

            <div class="security-box">
                &#128274; <strong>Security Alert:</strong> If you did not request a password reset, you can safely ignore this email. Your password remains unchanged. Never share your recovery code with anyone.
            </div>
        </div>
        <div class="footer">
            &copy; 2026 JeliPay P2P Exchange. All rights reserved.<br>
            Official Communications: <a href="mailto:team@jelipay.in">team@jelipay.in</a> &bull; <a href="https://jelipay.in">https://jelipay.in</a>
        </div>
    </div>
</body>
</html>
HTML;

        return self::sendSmtpEmail($toEmail, $subject, $htmlBody);
    }

    /**
     * Create verification record in DB and send OTP to user
     */
    public static function initiateVerification(int $userId, string $email, ?string $userName = null): array {
        $cleanEmail = strtolower(trim($email));
        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }

        $otp = sprintf('%06d', random_int(100000, 999999));
        $token = bin2hex(random_bytes(32));
        $nowUtc = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 900); // 15 mins
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $pdo = Database::getConnection();

        // Expire previous pending verifications for this email
        $expStmt = $pdo->prepare("UPDATE email_verifications SET status = 'expired' WHERE email = ? AND status = 'pending'");
        $expStmt->execute([$cleanEmail]);

        // Insert new verification record
        $insStmt = $pdo->prepare("
            INSERT INTO email_verifications (user_id, email, otp_code, verification_token, status, attempts, ip_address, created_at, expires_at)
            VALUES (?, ?, ?, ?, 'pending', 0, ?, ?, ?)
        ");
        $insStmt->execute([$userId, $cleanEmail, $otp, $token, $clientIp, $nowUtc, $expiresAt]);

        // Update user row
        $updUser = $pdo->prepare("
            UPDATE users
            SET email = ?,
                email_verification_otp = ?,
                email_verification_token = ?,
                email_verification_expires_at = ?
            WHERE id = ?
        ");
        $updUser->execute([$cleanEmail, $otp, $token, $expiresAt, $userId]);

        // Dispatch email
        $mailRes = self::sendVerificationOtp($cleanEmail, $otp, $token, $userName);

        return [
            'success' => true,
            'email' => $cleanEmail,
            'otp' => (getenv('ALLOW_SQLITE_CLI_FALLBACK') === '1') ? $otp : null, // exposed only in test mode
            'mail_sent' => $mailRes['success'],
            'mail_error' => $mailRes['error'] ?? null,
            'message' => 'Verification code sent to your email.'
        ];
    }

    /**
     * Verify email via 6-digit OTP code
     */
    public static function verifyOtp(string $email, string $otp): array {
        $cleanEmail = strtolower(trim($email));
        $cleanOtp = trim($otp);

        if (!filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address.'];
        }

        if (strlen($cleanOtp) !== 6 || !ctype_digit($cleanOtp)) {
            return ['success' => false, 'error' => 'Please enter a valid 6-digit verification code.'];
        }

        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Look for pending verification
        $stmt = $pdo->prepare("
            SELECT * FROM email_verifications
            WHERE email = ? AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$cleanEmail]);
        $rec = $stmt->fetch();

        if (!$rec) {
            return ['success' => false, 'error' => 'No pending verification found for this email. Please request a new code.'];
        }

        if ((int)$rec['attempts'] >= 5) {
            $pdo->prepare("UPDATE email_verifications SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Too many failed verification attempts. Please request a new code.'];
        }

        if (strtotime($rec['expires_at']) < strtotime($nowUtc)) {
            $pdo->prepare("UPDATE email_verifications SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Verification code has expired. Please request a new code.'];
        }

        if ($rec['otp_code'] !== $cleanOtp) {
            $pdo->prepare("UPDATE email_verifications SET attempts = attempts + 1 WHERE id = ?")->execute([$rec['id']]);
            $remainingAttempts = 5 - ((int)$rec['attempts'] + 1);
            return [
                'success' => false,
                'error' => "Incorrect verification code. {$remainingAttempts} attempt(s) remaining."
            ];
        }

        // OTP matches! Activate account
        $userId = (int)$rec['user_id'];
        $pdo->beginTransaction();
        try {
            $updVerif = $pdo->prepare("UPDATE email_verifications SET status = 'verified', verified_at = ? WHERE id = ?");
            $updVerif->execute([$nowUtc, $rec['id']]);

            $updUser = $pdo->prepare("
                UPDATE users
                SET account_status = 'active',
                    email = ?,
                    email_verified_at = ?,
                    email_verification_otp = NULL,
                    email_verification_token = NULL,
                    email_verification_expires_at = NULL
                WHERE id = ?
            ");
            $updUser->execute([$cleanEmail, $nowUtc, $userId]);

            $pdo->commit();

            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $cleanEmail,
                'message' => 'Email verified successfully! Your account is now active.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to activate account: ' . $e->getMessage()];
        }
    }

    /**
     * Verify email via 1-click token
     */
    public static function verifyToken(string $email, string $token): array {
        $cleanEmail = strtolower(trim($email));
        $cleanToken = trim($token);

        if (empty($cleanEmail) || empty($cleanToken)) {
            return ['success' => false, 'error' => 'Invalid verification link parameters.'];
        }

        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT * FROM email_verifications
            WHERE email = ? AND verification_token = ? AND status = 'pending'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$cleanEmail, $cleanToken]);
        $rec = $stmt->fetch();

        if (!$rec) {
            // Check if already verified
            $chk = $pdo->prepare("SELECT * FROM users WHERE email = ? AND email_verified_at IS NOT NULL");
            $chk->execute([$cleanEmail]);
            if ($chk->fetch()) {
                return ['success' => true, 'already_verified' => true, 'message' => 'Your email has already been verified.'];
            }
            return ['success' => false, 'error' => 'Invalid or expired verification link.'];
        }

        if (strtotime($rec['expires_at']) < strtotime($nowUtc)) {
            $pdo->prepare("UPDATE email_verifications SET status = 'expired' WHERE id = ?")->execute([$rec['id']]);
            return ['success' => false, 'error' => 'Verification link has expired. Please request a new code.'];
        }

        $userId = (int)$rec['user_id'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE email_verifications SET status = 'verified', verified_at = ? WHERE id = ?")->execute([$nowUtc, $rec['id']]);
            $pdo->prepare("
                UPDATE users
                SET account_status = 'active',
                    email = ?,
                    email_verified_at = ?,
                    email_verification_otp = NULL,
                    email_verification_token = NULL,
                    email_verification_expires_at = NULL
                WHERE id = ?
            ")->execute([$cleanEmail, $nowUtc, $userId]);

            $pdo->commit();

            return [
                'success' => true,
                'user_id' => $userId,
                'email' => $cleanEmail,
                'message' => 'Email verified successfully! Your account is now active.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Verification failed: ' . $e->getMessage()];
        }
    }
}
