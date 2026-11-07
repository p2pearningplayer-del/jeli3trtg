<?php
/**
 * USDT (TRC20) Crypto Webhook Endpoint
 * 
 * Receives automated IPN notifications from NOWPayments, TronGrid, or direct blockchain triggers.
 * Verifies authenticity, parses incoming transaction, enforces minimum deposit (100 USDT),
 * converts to platform tokens @ 1:115 ratio, credits available balance idempotently,
 * and maintains double-entry audit records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Crypto/CryptoDepositManager.php';
require_once __DIR__ . '/response.php';

// Ensure tables exist
SchemaInstaller::ensureSchema();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Health check endpoint for webhook tester / gateway verification
    echo json_encode([
        'status' => 'active',
        'service' => 'JeliPay USDT (TRC20) Webhook Listener',
        'network' => 'TRC20',
        'currency' => 'USDT',
        'exchange_rate' => \Crypto\CryptoDepositManager::getExchangeRate(),
        'min_deposit' => \Crypto\CryptoDepositManager::getMinDeposit(),
        'master_wallet' => \Crypto\CryptoDepositManager::getMasterWallet(),
        'timestamp' => gmdate('Y-m-d H:i:s')
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. POST required.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    parse_str($rawBody, $payload);
    if (!is_array($payload) || empty($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON or form payload.']);
        exit;
    }
}

// Retrieve HMAC signature from header if provided (NOWPayments standard header)
$sigHeader = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? $_SERVER['HTTP_X_SIG'] ?? null;
if (!$sigHeader && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'x-nowpayments-sig') {
            $sigHeader = $v;
            break;
        }
    }
}

try {
    $result = \Crypto\CryptoDepositManager::handleNowPaymentsWebhook($payload, $rawBody, $sigHeader);
    
    if (!empty($result['success'])) {
        http_response_code(200);
        echo json_encode($result);
    } else {
        $statusCode = (isset($result['status']) && $result['status'] === 'below_minimum') ? 200 : 400;
        http_response_code($statusCode);
        echo json_encode($result);
    }
} catch (Exception $e) {
    error_log("api/crypto_webhook.php exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error processing crypto deposit: ' . $e->getMessage()
    ]);
}
exit;
