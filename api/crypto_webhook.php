<?php
/**
 * USDT (TRC20) Crypto Webhook Endpoint - PERMANENTLY DECOMMISSIONED
 * 
 * Crypto deposit functionality has been removed from the platform.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);

echo json_encode([
    'success' => false,
    'error' => 'USDT crypto deposit service has been permanently decommissioned from this platform.'
]);
exit;
