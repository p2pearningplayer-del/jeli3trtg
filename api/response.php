<?php
/**
 * RESTful API Response Utility
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

function jsonResponse(bool $success, mixed $data = null, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode(
        is_array($data) ? array_merge(['success' => $success], $data) : ['success' => $success, 'data' => $data],
        JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function jsonError(string $message, int $statusCode = 400, ?array $extra = null): void {
    http_response_code($statusCode);
    $payload = ['success' => false, 'error' => $message];
    if ($extra) {
        $payload = array_merge($payload, $extra);
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_merge($_POST, $decoded) : $_POST;
}
