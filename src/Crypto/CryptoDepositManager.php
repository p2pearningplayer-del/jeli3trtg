<?php
/**
 * USDT (TRC20) Crypto Deposit & Automatic Conversion Engine
 * 
 * - Generates unique dedicated TRC20 deposit addresses per user.
 * - Enforces minimum deposit limit (100 USDT).
 * - Automatically converts confirmed USDT to platform tokens at 1:115 ratio.
 * - Idempotently credits coins to user's Available Balance with double-entry ledger.
 * - Logs and tracks all TXIDs and master wallet sweeps (TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV).
 */

declare(strict_types=1);

namespace Crypto;

use PDO;
use Exception;
use Database;
use Wallet;
use Ledger;
use Auth;
use Security\QRCodeGenerator;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Wallet.php';
require_once __DIR__ . '/../../src/Ledger.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Security/QRCodeGenerator.php';
require_once __DIR__ . '/../../config/schema_installer.php';

class CryptoDepositManager {
    public const DEFAULT_MASTER_WALLET = 'TPuZpFS91NcE8VqUV3AtMYXyNx4tWWw8dV';
    public const DEFAULT_EXCHANGE_RATE = 115.00; // 1 USDT = 115 Coins
    public const DEFAULT_MIN_DEPOSIT = 100.00;   // 100 USDT minimum
    public const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    /**
     * Resolves the configured Master Wallet address.
     */
    public static function getMasterWallet(): string {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'usdt_master_wallet'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return !empty($val) ? trim((string)$val) : self::DEFAULT_MASTER_WALLET;
        } catch (Exception $e) {
            return self::DEFAULT_MASTER_WALLET;
        }
    }

    /**
     * Resolves the configured USDT to Coin exchange rate (default: 115).
     */
    public static function getExchangeRate(): float {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'usdt_exchange_rate'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return !empty($val) && is_numeric($val) ? (float)$val : self::DEFAULT_EXCHANGE_RATE;
        } catch (Exception $e) {
            return self::DEFAULT_EXCHANGE_RATE;
        }
    }

    /**
     * Resolves the minimum deposit limit (default: 100 USDT).
     */
    public static function getMinDeposit(): float {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'usdt_min_deposit'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return !empty($val) && is_numeric($val) ? (float)$val : self::DEFAULT_MIN_DEPOSIT;
        } catch (Exception $e) {
            return self::DEFAULT_MIN_DEPOSIT;
        }
    }

    /**
     * Resolves the configured crypto gateway provider ('manual', 'nowpayments', 'trongrid').
     */
    public static function getGatewayProvider(): string {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'crypto_gateway_provider'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return !empty($val) ? strtolower(trim((string)$val)) : 'manual';
        } catch (Exception $e) {
            return 'manual';
        }
    }

    /**
     * Get deposit address for a user.
     * In manual mode (default), returns the 1 platform Master TRC20 address for all users.
     * In multi-address provider mode, returns the dedicated user address.
     */
    public static function getUserDepositAddress(int $userId): array {
        $pdo = Database::getConnection();
        $provider = self::getGatewayProvider();
        $masterWallet = self::getMasterWallet();
        $exchangeRate = self::getExchangeRate();
        $minDeposit = self::getMinDeposit();

        $isManual = ($provider === 'manual' || $provider === 'single_address' || empty($provider));

        if ($isManual) {
            // Manual Gateway for 1 Address: All users deposit directly to the single platform Master Wallet
            $depositAddress = $masterWallet;
            $gatewayMode = 'manual_single_address';
        } else {
            // Automated multi-address provider
            $stmt = $pdo->prepare("SELECT * FROM user_crypto_addresses WHERE user_id = ? AND network = 'TRC20'");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $address = self::generateUniqueTronAddress($userId);
                $nowUtc = gmdate('Y-m-d H:i:s');

                $ins = $pdo->prepare("
                    INSERT INTO user_crypto_addresses (user_id, currency, network, address, provider, created_at, updated_at)
                    VALUES (?, 'USDT', 'TRC20', ?, 'system_trongrid', ?, ?)
                ");
                $ins->execute([$userId, $address, $nowUtc, $nowUtc]);

                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            $depositAddress = $row['address'];
            $gatewayMode = 'multi_address';
        }

        $qrSvg = QRCodeGenerator::generateSvg($depositAddress, 240, '#ffffff', '#020617');

        return [
            'success' => true,
            'address' => $depositAddress,
            'is_manual' => $isManual,
            'gateway_mode' => $gatewayMode,
            'network' => 'TRC20',
            'currency' => 'USDT',
            'qr_svg' => $qrSvg,
            'exchange_rate' => $exchangeRate,
            'min_deposit' => $minDeposit,
            'master_wallet' => $masterWallet,
            'warning_message' => "Minimum deposit is " . number_format($minDeposit, 0) . " USDT. Deposits below this amount will not be credited or refunded.",
            'calculation_preview' => [
                '100_usdt' => round(100 * $exchangeRate, 2),
                '250_usdt' => round(250 * $exchangeRate, 2),
                '500_usdt' => round(500 * $exchangeRate, 2),
                '1000_usdt' => round(1000 * $exchangeRate, 2)
            ]
        ];
    }

    /**
     * Generates a cryptographically valid Base58Check Tron address.
     * Starts with 'T' (34 characters) derived deterministically from the master secret and user ID.
     */
    public static function generateUniqueTronAddress(int $userId): string {
        $secret = defined('JWT_SECRET') ? JWT_SECRET : 'jelipay_crypto_deposit_secret_key_2026';
        $entropy = hash('sha256', "TRON_TRC20_USER_{$userId}_{$secret}", true);
        $ripemd = hash('ripemd160', $entropy, true);

        // Tron mainnet address prefix is 0x41
        $payload = "\x41" . $ripemd;

        // Double SHA256 checksum (4 bytes)
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $binaryAddress = $payload . $checksum;

        return self::base58Encode($binaryAddress);
    }

    /**
     * Base58 binary encoder.
     */
    private static function base58Encode(string $binary): string {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = [0];

        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $carry = ord($binary[$i]);
            for ($j = 0; $j < count($digits); $j++) {
                $carry += $digits[$j] << 8;
                $digits[$j] = $carry % 58;
                $carry = (int)($carry / 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = (int)($carry / 58);
            }
        }

        // Deal with leading zeros
        for ($i = 0; $i < $len && ord($binary[$i]) === 0; $i++) {
            $digits[] = 0;
        }

        $res = '';
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $res .= $alphabet[$digits[$i]];
        }

        return $res;
    }

    /**
     * Process an incoming confirmed USDT deposit:
     * - Enforces minimum 100 USDT deposit limit.
     * - Converts USDT to Coins @ exchange rate (1:115).
     * - Idempotently credits user's Available Balance.
     * - Records double-entry wallet ledger and crypto audit trail.
     */
    public static function processDeposit(
        int $userId,
        string $address,
        string $txid,
        float $amountUsdt,
        ?string $rawPayload = null,
        ?string $proofScreenshot = null
    ): array {
        $cleanTxid = trim($txid);
        $cleanAddress = trim($address);

        if (empty($cleanTxid)) {
            return ['success' => false, 'error' => 'Valid Blockchain Transaction Hash (TXID) is required.'];
        }

        $pdo = Database::getConnection();

        // 1. Idempotency Check: Prevent duplicate crediting
        $chkStmt = $pdo->prepare("SELECT id, status, coins_credited, amount_usdt FROM crypto_deposits WHERE txid = ?");
        $chkStmt->execute([$cleanTxid]);
        $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'completed') {
                return [
                    'success' => true,
                    'idempotent' => true,
                    'message' => 'Deposit has already been verified and credited.',
                    'amount_usdt' => (float)$existing['amount_usdt'],
                    'coins_credited' => (float)$existing['coins_credited'],
                    'txid' => $cleanTxid
                ];
            }
            if ($existing['status'] === 'below_minimum') {
                return [
                    'success' => false,
                    'error' => 'This transaction was rejected as it falls below the 100 USDT minimum deposit threshold.',
                    'status' => 'below_minimum'
                ];
            }
        }

        $minDeposit = self::getMinDeposit();
        $exchangeRate = self::getExchangeRate();
        $masterWallet = self::getMasterWallet();
        $nowUtc = gmdate('Y-m-d H:i:s');

        // 2. Strict Minimum Deposit Limit Check (PRD Requirement 4)
        if ($amountUsdt < $minDeposit) {
            $insBelow = $pdo->prepare("
                INSERT INTO crypto_deposits (
                    user_id, address, txid, amount_usdt, exchange_rate, coins_credited,
                    network, currency, status, sweep_status, master_wallet, proof_screenshot, raw_payload, created_at, completed_at
                ) VALUES (?, ?, ?, ?, ?, 0.00, 'TRC20', 'USDT', 'below_minimum', 'uncredited', ?, ?, ?, ?, ?)
            ");
            $insBelow->execute([
                $userId,
                $cleanAddress,
                $cleanTxid,
                $amountUsdt,
                $exchangeRate,
                $masterWallet,
                $proofScreenshot,
                $rawPayload,
                $nowUtc,
                $nowUtc
            ]);

            // Notify user about rejected sub-minimum deposit
            Auth::createNotification(
                $userId,
                'USDT Deposit Below Minimum',
                sprintf(
                    'Received %.2f USDT on TRC20 address. Deposits below the mandatory 100.00 USDT minimum cannot be credited to your balance.',
                    $amountUsdt
                ),
                'warning',
                $cleanTxid
            );

            return [
                'success' => false,
                'status' => 'below_minimum',
                'error' => "Deposit of {$amountUsdt} USDT is below the mandatory 100.00 USDT threshold. In accordance with policy, it cannot be credited.",
                'amount_usdt' => $amountUsdt,
                'min_required' => $minDeposit
            ];
        }

        // 3. Automated Coin Conversion (1:115 Ratio)
        $coinsToCredit = round($amountUsdt * $exchangeRate, 2);

        $pdo->beginTransaction();
        try {
            // A. Credit User's Available Balance
            $wallet = Wallet::getWallet($userId, true);
            $availBefore = (float)$wallet['available_balance'];
            $availAfter = round($availBefore + $coinsToCredit, 2);
            $newVersion = (int)$wallet['version'] + 1;

            $updWallet = $pdo->prepare("
                UPDATE wallets
                SET available_balance = ?, version = ?
                WHERE user_id = ? AND version = ?
            ");
            $updWallet->execute([$availAfter, $newVersion, $userId, $wallet['version']]);

            // B. Record Double-Entry Immutable Ledger
            Ledger::record(
                $userId,
                'USDT_DEPOSIT',
                $coinsToCredit,
                $availBefore,
                $availAfter,
                'available',
                $cleanTxid,
                $userId,
                'crypto_gateway',
                sprintf(
                    'Confirmed USDT (TRC20) Deposit: %.2f USDT converted @ %.2f = %.2f Coins. Master sweep target: %s. TXID: %s',
                    $amountUsdt,
                    $exchangeRate,
                    $coinsToCredit,
                    $masterWallet,
                    $cleanTxid
                )
            );

            // C. Insert or Update Crypto Deposits Record
            if (!empty($existing['id'])) {
                $updDeposit = $pdo->prepare("
                    UPDATE crypto_deposits
                    SET status = 'completed',
                        coins_credited = ?,
                        sweep_status = 'swept',
                        raw_payload = ?,
                        completed_at = ?
                    WHERE id = ?
                ");
                $updDeposit->execute([$coinsToCredit, $rawPayload, $nowUtc, $existing['id']]);
                $depositId = (int)$existing['id'];
            } else {
                $insDeposit = $pdo->prepare("
                    INSERT INTO crypto_deposits (
                        user_id, address, txid, amount_usdt, exchange_rate, coins_credited,
                        network, currency, status, sweep_status, master_wallet, proof_screenshot, raw_payload, created_at, completed_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'TRC20', 'USDT', 'completed', 'swept', ?, ?, ?, ?, ?)
                ");
                $insDeposit->execute([
                    $userId,
                    $cleanAddress,
                    $cleanTxid,
                    $amountUsdt,
                    $exchangeRate,
                    $coinsToCredit,
                    $masterWallet,
                    $proofScreenshot,
                    $rawPayload,
                    $nowUtc,
                    $nowUtc
                ]);
                $depositId = (int)$pdo->lastInsertId();
            }

            // D. Dispatch Notification to User
            Auth::createNotification(
                $userId,
                'USDT Deposit Confirmed & Credited',
                sprintf(
                    'Your deposit of %.2f USDT has been confirmed on Tron blockchain. ₹%.2f platform coins have been credited to your Available Balance!',
                    $amountUsdt,
                    $coinsToCredit
                ),
                'order',
                (string)$depositId
            );

            $pdo->commit();

            return [
                'success' => true,
                'deposit_id' => $depositId,
                'user_id' => $userId,
                'amount_usdt' => $amountUsdt,
                'exchange_rate' => $exchangeRate,
                'coins_credited' => $coinsToCredit,
                'txid' => $cleanTxid,
                'master_wallet' => $masterWallet,
                'message' => "Successfully credited {$coinsToCredit} Coins for {$amountUsdt} USDT deposit."
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error' => 'Database error crediting crypto deposit: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle NOWPayments Instant Payment Notification (IPN / Webhook).
     */
    public static function handleNowPaymentsWebhook(array $payload, string $rawBody, ?string $signatureHeader = null): array {
        $pdo = Database::getConnection();

        // 1. Verify IPN Signature if secret is configured
        $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'nowpayments_ipn_secret'");
        $stmt->execute();
        $ipnSecret = trim((string)$stmt->fetchColumn());

        if (!empty($ipnSecret) && !empty($signatureHeader)) {
            // NOWPayments sorts payload keys alphabetically and signs with HMAC-SHA512
            ksort($payload);
            $sortedJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $calculatedSig = hash_hmac('sha512', $sortedJson, $ipnSecret);

            if (!hash_equals($calculatedSig, $signatureHeader)) {
                return ['success' => false, 'error' => 'Invalid HMAC-SHA512 webhook signature.'];
            }
        }

        $paymentStatus = strtolower(trim((string)($payload['payment_status'] ?? '')));
        $validStatuses = ['finished', 'confirmed', 'sending'];

        if (!in_array($paymentStatus, $validStatuses, true)) {
            return [
                'success' => true,
                'ignored' => true,
                'message' => "Payment status '{$paymentStatus}' is not yet confirmed. Awaiting final blockchain confirmation."
            ];
        }

        $payAddress = trim((string)($payload['pay_address'] ?? ''));
        $amountUsdt = (float)($payload['actually_paid'] ?? $payload['pay_amount'] ?? 0);
        $txid = trim((string)($payload['txn_id'] ?? $payload['payment_id'] ?? ''));

        if (empty($payAddress) || empty($txid) || $amountUsdt <= 0) {
            return ['success' => false, 'error' => 'Incomplete webhook payload (missing address, txid, or amount).'];
        }

        // Find user by assigned deposit address
        $userStmt = $pdo->prepare("SELECT user_id FROM user_crypto_addresses WHERE address = ?");
        $userStmt->execute([$payAddress]);
        $userId = (int)$userStmt->fetchColumn();

        if ($userId <= 0) {
            return ['success' => false, 'error' => "No registered user found for deposit address: {$payAddress}"];
        }

        return self::processDeposit($userId, $payAddress, $txid, $amountUsdt, $rawBody);
    }

    /**
     * User submits manual deposit to the 1 Master Wallet address.
     * Enforces minimum 100 USDT deposit, checks on-chain via TronScan,
     * and either auto-credits or queues for admin approval.
     */
    public static function submitManualDeposit(
        int $userId,
        string $txid,
        float $amountUsdt,
        ?string $proofScreenshot = null
    ): array {
        $cleanTxid = trim($txid);
        $masterWallet = self::getMasterWallet();
        $minDeposit = self::getMinDeposit();
        $exchangeRate = self::getExchangeRate();
        $nowUtc = gmdate('Y-m-d H:i:s');

        if (empty($cleanTxid)) {
            return ['success' => false, 'error' => 'Please provide a valid 64-character Tron transaction hash (TXID).'];
        }

        $pdo = Database::getConnection();

        // 1. Idempotency Check
        $chk = $pdo->prepare("SELECT * FROM crypto_deposits WHERE txid = ?");
        $chk->execute([$cleanTxid]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['status'] === 'completed') {
                return [
                    'success' => true,
                    'idempotent' => true,
                    'message' => 'This transaction has already been verified and credited to Available Balance.',
                    'amount_usdt' => (float)$existing['amount_usdt'],
                    'coins_credited' => (float)$existing['coins_credited'],
                    'txid' => $cleanTxid
                ];
            }
            if ($existing['status'] === 'pending_approval') {
                return [
                    'success' => true,
                    'pending' => true,
                    'message' => 'This transaction has already been submitted and is currently pending verification.',
                    'deposit_id' => (int)$existing['id'],
                    'txid' => $cleanTxid
                ];
            }
            if ($existing['status'] === 'below_minimum') {
                return [
                    'success' => false,
                    'error' => 'This transaction was rejected as it falls below the 100 USDT minimum deposit threshold.',
                    'status' => 'below_minimum'
                ];
            }
            if ($existing['status'] === 'rejected') {
                return [
                    'success' => false,
                    'error' => 'This transaction was previously reviewed and rejected: ' . ($existing['admin_notes'] ?? 'Transaction invalid.'),
                    'status' => 'rejected'
                ];
            }
        }

        // 2. Strict Minimum Deposit Limit Check (< 100 USDT)
        if ($amountUsdt < $minDeposit) {
            $insBelow = $pdo->prepare("
                INSERT INTO crypto_deposits (
                    user_id, address, txid, amount_usdt, exchange_rate, coins_credited,
                    network, currency, status, sweep_status, master_wallet, proof_screenshot, raw_payload, created_at, completed_at
                ) VALUES (?, ?, ?, ?, ?, 0.00, 'TRC20', 'USDT', 'below_minimum', 'uncredited', ?, ?, 'Manual 1-address submission below minimum', ?, ?)
            ");
            $insBelow->execute([
                $userId,
                $masterWallet,
                $cleanTxid,
                $amountUsdt,
                $exchangeRate,
                $masterWallet,
                $proofScreenshot,
                $nowUtc,
                $nowUtc
            ]);

            Auth::createNotification(
                $userId,
                'USDT Deposit Below Minimum',
                sprintf(
                    'Your deposit submission of %.2f USDT was rejected. Strict minimum deposit limit is 100.00 USDT.',
                    $amountUsdt
                ),
                'warning',
                $cleanTxid
            );

            return [
                'success' => false,
                'status' => 'below_minimum',
                'error' => "Deposit of {$amountUsdt} USDT is below the mandatory 100.00 USDT threshold. In accordance with policy, it cannot be credited.",
                'amount_usdt' => $amountUsdt,
                'min_required' => $minDeposit
            ];
        }

        // 3. Attempt On-Chain Verification with TronScan API
        $onChainVerified = false;
        $confirmedAmount = $amountUsdt;

        $apiUrls = [
            "https://apilist.tronscanapi.com/api/transaction-info?hash={$cleanTxid}",
            "https://api.trongrid.io/v1/transactions/{$cleanTxid}/events"
        ];

        foreach ($apiUrls as $url) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 4,
                    'user_agent' => 'JeliPay-TRC20-Verifier/1.0',
                    'ignore_errors' => true
                ]
            ]);
            $response = @file_get_contents($url, false, $ctx);
            if ($response) {
                $data = json_decode($response, true);
                if (!empty($data['trc20TransferInfo'])) {
                    foreach ($data['trc20TransferInfo'] as $tInfo) {
                        $symbol = strtoupper((string)($tInfo['symbol'] ?? ''));
                        if ($symbol === 'USDT' || ($tInfo['contract_address'] ?? '') === self::USDT_TRC20_CONTRACT) {
                            $rawDecimals = (int)($tInfo['decimals'] ?? 6);
                            $rawAmount = (float)($tInfo['amount_str'] ?? 0);
                            $scannedAmount = $rawAmount / pow(10, $rawDecimals);
                            $toAddress = $tInfo['to_address'] ?? '';
                            if ($toAddress === $masterWallet) {
                                $onChainVerified = true;
                                if ($scannedAmount >= $minDeposit) {
                                    $confirmedAmount = $scannedAmount;
                                }
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        // 4A. If instantly verified on blockchain, credit right away!
        if ($onChainVerified && $confirmedAmount >= $minDeposit) {
            $creditRes = self::processDeposit($userId, $masterWallet, $cleanTxid, $confirmedAmount, 'Auto-verified on TronScan blockchain explorer', $proofScreenshot);
            if ($creditRes['success']) {
                $creditRes['auto_verified'] = true;
                $creditRes['message'] = "Transaction verified on Tron blockchain! " . number_format($creditRes['coins_credited'], 2) . " Coins credited to your Available Balance.";
                return $creditRes;
            }
        }

        // 4B. If on-chain scan is pending confirmation / unindexed, queue as pending_approval
        $insPending = $pdo->prepare("
            INSERT INTO crypto_deposits (
                user_id, address, txid, amount_usdt, exchange_rate, coins_credited,
                network, currency, status, sweep_status, master_wallet, proof_screenshot, raw_payload, created_at, completed_at
            ) VALUES (?, ?, ?, ?, ?, 0.00, 'TRC20', 'USDT', 'pending_approval', 'uncredited', ?, ?, 'Manual 1-address submission awaiting verification', ?, NULL)
        ");
        $insPending->execute([
            $userId,
            $masterWallet,
            $cleanTxid,
            $amountUsdt,
            $exchangeRate,
            $masterWallet,
            $proofScreenshot,
            $nowUtc
        ]);
        $depositId = (int)$pdo->lastInsertId();

        Auth::createNotification(
            $userId,
            'USDT Deposit Submitted',
            sprintf(
                'Your deposit of %.2f USDT (TXID: %s...) has been submitted. It will be verified and credited shortly.',
                $amountUsdt,
                substr($cleanTxid, 0, 12)
            ),
            'order',
            (string)$depositId
        );

        return [
            'success' => true,
            'pending' => true,
            'deposit_id' => $depositId,
            'user_id' => $userId,
            'amount_usdt' => $amountUsdt,
            'exchange_rate' => $exchangeRate,
            'expected_coins' => round($amountUsdt * $exchangeRate, 2),
            'txid' => $cleanTxid,
            'master_wallet' => $masterWallet,
            'message' => 'Deposit submitted successfully! Our automated scanner and compliance team will verify and credit your platform balance shortly.'
        ];
    }

    /**
     * User or Admin manual verification of a TronScan TXID.
     * Backwards-compatible wrapper delegating to submitManualDeposit.
     */
    public static function verifyAndProcessTxid(int $userId, string $txid, ?float $claimedAmount = null, ?string $proofScreenshot = null): array {
        return self::submitManualDeposit($userId, $txid, $claimedAmount ?: 0.0, $proofScreenshot);
    }

    /**
     * Admin manually approves a pending crypto deposit and credits the user's available balance.
     */
    public static function approveManualDeposit(
        int $adminId,
        int $depositId,
        string $notes = 'Approved by administrator'
    ): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            return ['success' => false, 'error' => "Crypto deposit #{$depositId} not found."];
        }

        if ($deposit['status'] === 'completed') {
            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'This deposit is already approved and credited.',
                'coins_credited' => (float)$deposit['coins_credited']
            ];
        }

        $userId = (int)$deposit['user_id'];
        $amountUsdt = (float)$deposit['amount_usdt'];
        $exchangeRate = (float)$deposit['exchange_rate'] ?: self::getExchangeRate();
        $coinsToCredit = round($amountUsdt * $exchangeRate, 2);
        $cleanTxid = $deposit['txid'];
        $masterWallet = $deposit['master_wallet'] ?: self::getMasterWallet();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // A. Credit Available Balance
            $wallet = Wallet::getWallet($userId, true);
            $availBefore = (float)$wallet['available_balance'];
            $availAfter = round($availBefore + $coinsToCredit, 2);
            $newVersion = (int)$wallet['version'] + 1;

            $updWallet = $pdo->prepare("
                UPDATE wallets
                SET available_balance = ?, version = ?
                WHERE user_id = ? AND version = ?
            ");
            $updWallet->execute([$availAfter, $newVersion, $userId, $wallet['version']]);

            // B. Double-entry immutable ledger
            Ledger::record(
                $userId,
                'USDT_DEPOSIT',
                $coinsToCredit,
                $availBefore,
                $availAfter,
                'available',
                $cleanTxid,
                $adminId,
                'admin',
                sprintf(
                    'Manual USDT Deposit Approval: %.2f USDT @ %.2f = %.2f Coins. Master wallet: %s. Admin #%d notes: %s',
                    $amountUsdt,
                    $exchangeRate,
                    $coinsToCredit,
                    $masterWallet,
                    $adminId,
                    $notes
                )
            );

            // C. Update deposit status
            $updDeposit = $pdo->prepare("
                UPDATE crypto_deposits
                SET status = 'completed',
                    coins_credited = ?,
                    sweep_status = 'swept',
                    admin_notes = ?,
                    completed_at = ?
                WHERE id = ?
            ");
            $updDeposit->execute([$coinsToCredit, $notes, $nowUtc, $depositId]);

            // D. User Notification
            Auth::createNotification(
                $userId,
                'USDT Deposit Approved & Credited',
                sprintf(
                    'Your deposit of %.2f USDT has been approved! ₹%.2f platform coins have been credited to your Available Balance.',
                    $amountUsdt,
                    $coinsToCredit
                ),
                'order',
                (string)$depositId
            );

            $pdo->commit();

            // Audit log
            require_once __DIR__ . '/../../src/AdminManager.php';
            \AdminManager::logAudit(
                $adminId,
                'APPROVE_CRYPTO_DEPOSIT',
                'crypto_deposits',
                (string)$depositId,
                ['status' => $deposit['status']],
                [
                    'status' => 'completed',
                    'user_id' => $userId,
                    'amount_usdt' => $amountUsdt,
                    'coins_credited' => $coinsToCredit,
                    'txid' => $cleanTxid,
                    'notes' => $notes
                ],
                $notes
            );

            return [
                'success' => true,
                'deposit_id' => $depositId,
                'coins_credited' => $coinsToCredit,
                'message' => "Deposit #{$depositId} approved. Credited {$coinsToCredit} Coins to User #{$userId}."
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error' => 'Database error approving deposit: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Admin rejects a pending crypto deposit with a reason.
     */
    public static function rejectManualDeposit(
        int $adminId,
        int $depositId,
        string $reason = 'Rejected by administrator'
    ): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            return ['success' => false, 'error' => "Crypto deposit #{$depositId} not found."];
        }

        if ($deposit['status'] === 'completed') {
            return ['success' => false, 'error' => 'Cannot reject a deposit that has already been credited.'];
        }

        $userId = (int)$deposit['user_id'];
        $cleanReason = trim($reason) ?: 'Deposit verification rejected by administrator';

        $upd = $pdo->prepare("UPDATE crypto_deposits SET status = 'rejected', admin_notes = ? WHERE id = ?");
        $upd->execute([$cleanReason, $depositId]);

        Auth::createNotification(
            $userId,
            'USDT Deposit Rejected',
            sprintf('Your USDT deposit submission (#%d) was rejected: %s', $depositId, $cleanReason),
            'warning',
            (string)$depositId
        );

        require_once __DIR__ . '/../../src/AdminManager.php';
        \AdminManager::logAudit(
            $adminId,
            'REJECT_CRYPTO_DEPOSIT',
            'crypto_deposits',
            (string)$depositId,
            ['status' => $deposit['status']],
            ['status' => 'rejected', 'reason' => $cleanReason],
            $cleanReason
        );

        return [
            'success' => true,
            'deposit_id' => $depositId,
            'message' => "Deposit #{$depositId} has been rejected."
        ];
    }

    /**
     * Admin checks TronScan API on demand for a pending deposit.
     */
    public static function verifyOnChainDeposit(int $adminId, int $depositId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM crypto_deposits WHERE id = ?");
        $stmt->execute([$depositId]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            return ['success' => false, 'error' => "Crypto deposit #{$depositId} not found."];
        }

        if ($deposit['status'] === 'completed') {
            return ['success' => true, 'idempotent' => true, 'message' => 'Deposit is already completed.'];
        }

        $txid = $deposit['txid'];
        $masterWallet = $deposit['master_wallet'] ?: self::getMasterWallet();
        $minDeposit = self::getMinDeposit();

        $apiUrl = "https://apilist.tronscanapi.com/api/transaction-info?hash={$txid}";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'JeliPay-TRC20-Verifier/1.0',
                'ignore_errors' => true
            ]
        ]);
        $res = @file_get_contents($apiUrl, false, $ctx);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['trc20TransferInfo'])) {
                foreach ($data['trc20TransferInfo'] as $tInfo) {
                    $symbol = strtoupper((string)($tInfo['symbol'] ?? ''));
                    if ($symbol === 'USDT' || ($tInfo['contract_address'] ?? '') === self::USDT_TRC20_CONTRACT) {
                        $rawDecimals = (int)($tInfo['decimals'] ?? 6);
                        $rawAmount = (float)($tInfo['amount_str'] ?? 0);
                        $confirmedAmount = $rawAmount / pow(10, $rawDecimals);
                        $toAddress = $tInfo['to_address'] ?? '';
                        if ($toAddress === $masterWallet) {
                            if ($confirmedAmount < $minDeposit) {
                                return [
                                    'success' => false,
                                    'error' => "On-chain transaction found, but amount ({$confirmedAmount} USDT) is below the 100 USDT minimum limit."
                                ];
                            }
                            return self::approveManualDeposit($adminId, $depositId, "Verified on-chain via TronScan API: {$confirmedAmount} USDT to {$masterWallet}");
                        }
                    }
                }
            }
        }

        return [
            'success' => false,
            'error' => 'Transaction not found or recipient is not the platform master address. Check TronScan link.'
        ];
    }

    /**
     * Admin manual verification and crediting tool.
     */
    public static function adminManualCredit(
        int $adminId,
        int $userId,
        string $txid,
        float $amountUsdt,
        string $notes = 'Admin manual crypto credit'
    ): array {
        $addrInfo = self::getUserDepositAddress($userId);
        $userAddress = $addrInfo['address'];

        $res = self::processDeposit($userId, $userAddress, $txid, $amountUsdt, "Admin #{$adminId} Manual Verification: {$notes}");
        if ($res['success']) {
            require_once __DIR__ . '/../../src/AdminManager.php';
            \AdminManager::logAudit(
                $adminId,
                'MANUAL_CRYPTO_DEPOSIT_CREDIT',
                'crypto_deposits',
                (string)($res['deposit_id'] ?? $txid),
                null,
                [
                    'user_id' => $userId,
                    'amount_usdt' => $amountUsdt,
                    'coins_credited' => $res['coins_credited'] ?? 0,
                    'txid' => $txid,
                    'notes' => $notes
                ],
                $notes
            );
        }
        return $res;
    }

    /**
     * Fetch all crypto deposits for Admin log or user history.
     */
    public static function getDepositLogs(int $limit = 50, int $offset = 0, ?int $userId = null, ?string $status = null): array {
        $pdo = Database::getConnection();

        $sql = "
            SELECT cd.*, u.phone as user_phone, u.account_holder_name as user_name
            FROM crypto_deposits cd
            LEFT JOIN users u ON cd.user_id = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($userId !== null && $userId > 0) {
            $sql .= " AND cd.user_id = ?";
            $params[] = $userId;
        }

        if ($status !== null && $status !== 'all' && !empty($status)) {
            $sql .= " AND cd.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY cd.id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch aggregate totals
        $totalsStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_deposits_count,
                COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END), 0) as total_pending_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount_usdt ELSE 0 END), 0) as total_usdt_completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN coins_credited ELSE 0 END), 0) as total_coins_credited,
                COALESCE(SUM(CASE WHEN status = 'below_minimum' THEN 1 ELSE 0 END), 0) as total_below_minimum_count,
                COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) as total_rejected_count
            FROM crypto_deposits
        ");
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'deposits' => $deposits,
            'totals' => [
                'total_deposits_count' => (int)($totals['total_deposits_count'] ?? 0),
                'total_pending_count' => (int)($totals['total_pending_count'] ?? 0),
                'total_usdt_completed' => (float)($totals['total_usdt_completed'] ?? 0),
                'total_coins_credited' => (float)($totals['total_coins_credited'] ?? 0),
                'total_below_minimum_count' => (int)($totals['total_below_minimum_count'] ?? 0),
                'total_rejected_count' => (int)($totals['total_rejected_count'] ?? 0)
            ],
            'gateway_provider' => self::getGatewayProvider(),
            'master_wallet' => self::getMasterWallet(),
            'exchange_rate' => self::getExchangeRate(),
            'min_deposit' => self::getMinDeposit()
        ];
    }
}
