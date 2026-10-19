<?php
/**
 * JeliPay Two-Factor Authentication (TOTP) Service
 * Standards-compliant implementation of RFC 6238 (TOTP) and RFC 4226 (HOTP).
 * Includes Base32 encoding/decoding, authenticated encryption at rest (AES-256-GCM),
 * clock drift tolerance, replay prevention, and single-use recovery code management.
 */

declare(strict_types=1);

namespace Security;

use Exception;

class TotpService {

    /**
     * RFC 4648 Base32 alphabet
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Master encryption key file location
     */
    private const KEY_FILE = __DIR__ . '/../../config/totp_key.php';

    /**
     * Generate a new cryptographically secure Base32 TOTP secret.
     * RFC 6238 recommends a 160-bit (20 byte) key for HMAC-SHA1.
     *
     * @param int $byteLength
     * @return string 32-character Base32 encoded secret
     */
    public static function generateSecret(int $byteLength = 20): string {
        $randomBytes = random_bytes($byteLength);
        return self::base32Encode($randomBytes);
    }

    /**
     * Encode binary string to RFC 4648 Base32 without padding.
     */
    public static function base32Encode(string $data): string {
        if ($data === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $binaryString = '';
        $dataLen = strlen($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $binaryString .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $fiveBitChunks = str_split($binaryString, 5);
        $encoded = '';

        foreach ($fiveBitChunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Decode RFC 4648 Base32 string back to binary.
     * Case-insensitive, removes spaces, dashes, and padding '='.
     */
    public static function base32Decode(string $b32): string {
        $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?: '');
        if ($clean === '') {
            return '';
        }

        $alphabet = self::BASE32_ALPHABET;
        $binaryString = '';
        $cleanLen = strlen($clean);

        for ($i = 0; $i < $cleanLen; $i++) {
            $pos = strpos($alphabet, $clean[$i]);
            if ($pos === false) {
                continue;
            }
            $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $eightBitChunks = str_split($binaryString, 8);
        $binary = '';

        foreach ($eightBitChunks as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }

    /**
     * Calculate 6-digit TOTP code for a secret and timestamp according to RFC 6238.
     *
     * @param string $secret Base32 encoded secret
     * @param int|null $timestamp Unix epoch seconds (defaults to current time)
     * @param int $timeStep Step window in seconds (default 30s)
     * @param int $digits Code length (default 6 digits)
     * @return string
     */
    public static function generateCode(string $secret, ?int $timestamp = null, int $timeStep = 30, int $digits = 6): string {
        $time = $timestamp ?? time();
        $counter = (int)floor($time / $timeStep);

        // Pack 64-bit integer big-endian (compatible with both 32-bit and 64-bit PHP)
        $high = ($counter >> 32) & 0xFFFFFFFF;
        $low = $counter & 0xFFFFFFFF;
        $binaryCounter = pack('NN', $high, $low);

        $binaryKey = self::base32Decode($secret);
        if ($binaryKey === '') {
            return '000000';
        }

        // HMAC-SHA1
        $hash = hash_hmac('sha1', $binaryCounter, $binaryKey, true);

        // Dynamic truncation (RFC 4226 section 5.4)
        $offset = ord($hash[19]) & 0x0F;
        $truncatedHash = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $truncatedHash % (10 ** $digits);
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Alias for generateCode (RFC 6238 TOTP calculation).
     */
    public static function calculateTotp(string $secret, ?int $timestamp = null, int $timeStep = 30, int $digits = 6): string {
        return self::generateCode($secret, $timestamp, $timeStep, $digits);
    }

    /**
     * Verify a user-submitted code against the secret, with drift tolerance and replay protection.
     *
     * @param string $secret Base32 encoded secret
     * @param string $submittedCode 6-digit string
     * @param int $lastUsedStep Previous accepted time step index (for replay protection)
     * @param int $window Tolerance in steps (+/- 1 step = +/- 30 seconds)
     * @param int|null $timestamp Current timestamp
     * @return array ['valid' => bool, 'step' => int|null, 'error' => string|null, 'code' => string|null]
     */
    public static function verifyCode(
        string $secret,
        string $submittedCode,
        int $lastUsedStep = 0,
        int $window = 1,
        ?int $timestamp = null
    ): array {
        $cleanCode = preg_replace('/\s+/', '', trim($submittedCode));
        if (!preg_match('/^[0-9]{6}$/', $cleanCode)) {
            return [
                'valid' => false,
                'step' => null,
                'error' => 'Code must be exactly 6 digits.',
                'code' => 'INVALID_FORMAT'
            ];
        }

        $now = $timestamp ?? time();
        $timeStep = 30;
        $currentStep = (int)floor($now / $timeStep);

        // Check window: currentStep - $window to currentStep + $window
        for ($i = -$window; $i <= $window; $i++) {
            $step = $currentStep + $i;
            $stepTime = $step * $timeStep;
            $expectedCode = self::generateCode($secret, $stepTime, $timeStep, 6);

            if (hash_equals($expectedCode, $cleanCode)) {
                // Replay check: reject if this step was already verified
                if ($step <= $lastUsedStep) {
                    return [
                        'valid' => false,
                        'step' => null,
                        'error' => 'This authenticator code was already used. Please wait for the next 30-second code.',
                        'code' => 'REPLAYED_CODE'
                    ];
                }

                return [
                    'valid' => true,
                    'step' => $step,
                    'error' => null,
                    'code' => 'SUCCESS'
                ];
            }
        }

        return [
            'valid' => false,
            'step' => null,
            'error' => 'Invalid or expired authenticator code. Check your device time sync.',
            'code' => 'CODE_MISMATCH'
        ];
    }

    /**
     * Build standard authenticator-compatible provisioning URI (Key URI Scheme).
     *
     * @param string $secret Base32 secret
     * @param string $accountIdentifier e.g. user phone number or email
     * @param string $issuer Platform brand name (defaults to JeliPay)
     * @return string
     */
    public static function getProvisioningUri(string $secret, string $accountIdentifier, string $issuer = 'JeliPay'): string {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountIdentifier);
        $label = "{$encodedIssuer}:{$encodedAccount}";

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Get or initialize server-side master encryption key for encrypted secrets at rest.
     */
    public static function getMasterKey(): string {
        // 1. Check environment variable
        $envKey = getenv('TOTP_ENCRYPTION_KEY');
        if (!empty($envKey)) {
            return (string)$envKey;
        }

        if (!empty($_ENV['TOTP_ENCRYPTION_KEY'])) {
            return (string)$_ENV['TOTP_ENCRYPTION_KEY'];
        }

        // 2. Check local keyfile (outside source control via .gitignore)
        $keyFile = self::KEY_FILE;
        if (file_exists($keyFile)) {
            $key = @include $keyFile;
            if (is_string($key) && strlen($key) >= 32) {
                return $key;
            }
        }

        // 3. Generate a persistent 256-bit secure key and store locally
        $newKey = bin2hex(random_bytes(32));
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $code = "<?php\n// Master encryption key for TOTP secrets at rest. NEVER commit to version control.\nreturn '" . $newKey . "';\n";
        @file_put_contents($keyFile, $code, LOCK_EX);
        @chmod($keyFile, 0600);

        return $newKey;
    }

    /**
     * Encrypt a TOTP secret at rest using authenticated AES-256-GCM.
     *
     * @param string $plaintextSecret
     * @return string Base64 encoded (12-byte IV + 16-byte Tag + Ciphertext)
     */
    public static function encryptSecret(string $plaintextSecret): string {
        $masterKey = self::getMasterKey();
        $encryptionKey = hash('sha256', $masterKey, true); // 32 raw bytes

        $iv = random_bytes(12); // Standard 96-bit IV for GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintextSecret,
            'aes-256-gcm',
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'JeliPayTOTP_v1', // Associated authenticated data
            16
        );

        if ($ciphertext === false) {
            throw new Exception('Failed to encrypt TOTP secret using AES-256-GCM.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a TOTP secret from at-rest storage.
     *
     * @param string $encryptedPayload
     * @return string|null Plaintext Base32 secret, or null on failure/tampering
     */
    public static function decryptSecret(string $encryptedPayload): ?string {
        $raw = base64_decode($encryptedPayload, true);
        if ($raw === false || strlen($raw) < 28) { // 12 IV + 16 Tag = 28 bytes min
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $masterKey = self::getMasterKey();
        $encryptionKey = hash('sha256', $masterKey, true);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'JeliPayTOTP_v1'
        );

        return ($plaintext !== false) ? $plaintext : null;
    }

    /**
     * Generate 8 cryptographically secure single-use recovery codes.
     * Returns plaintext codes for one-time display to user, and hashed records for database storage.
     *
     * @param int $count Number of recovery codes (default 8)
     * @return array ['plain' => string[], 'stored' => array[]]
     */
    public static function generateRecoveryCodes(int $count = 8): array {
        // Unambiguous character set (no 0/O, 1/I)
        $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $charLen = strlen($charset);

        $plain = [];
        $stored = [];

        for ($i = 0; $i < $count; $i++) {
            // Generate 8-character code formatted as XXXX-XXXX
            $code = '';
            for ($c = 0; $c < 8; $c++) {
                $randIndex = ord(random_bytes(1)) % $charLen;
                $code .= $charset[$randIndex];
            }
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4);

            $plain[] = $formatted;
            $stored[] = [
                'hash' => password_hash($formatted, PASSWORD_BCRYPT, ['cost' => 10]),
                'used' => false,
                'used_at' => null
            ];
        }

        return [
            'plain' => $plain,
            'stored' => $stored
        ];
    }

    /**
     * Verify a submitted recovery code against stored hashed codes.
     * If valid and unused, marks the code as used.
     *
     * @param string $submittedCode
     * @param array $storedCodes Array of stored code entries
     * @return array ['valid' => bool, 'updated_codes' => array, 'matched_index' => int|null]
     */
    public static function verifyRecoveryCode(string $submittedCode, array $storedCodes): array {
        $clean = strtoupper(trim($submittedCode));
        // Normalize: if user entered without hyphen, add hyphen
        if (preg_match('/^[2-9A-HJ-NP-Z]{8}$/', $clean)) {
            $clean = substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
        }

        foreach ($storedCodes as $idx => &$item) {
            if (empty($item['used']) && !empty($item['hash'])) {
                if (password_verify($clean, $item['hash'])) {
                    $item['used'] = true;
                    $item['used_at'] = gmdate('Y-m-d H:i:s');
                    return [
                        'valid' => true,
                        'updated_codes' => $storedCodes,
                        'matched_index' => $idx
                    ];
                }
            }
        }

        return [
            'valid' => false,
            'updated_codes' => $storedCodes,
            'matched_index' => null
        ];
    }
}
