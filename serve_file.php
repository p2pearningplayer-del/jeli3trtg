<?php
/**
 * Protected Evidence & Payment Proof Access Gateway
 * Enforces PRD Section 14 Evidence Security: Access is strictly limited
 * to the Buyer, the Seller, and authorized Administrators.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/src/Auth.php';

$user = Auth::getCurrentUser();
if (!$user) {
    http_response_code(401);
    die('Unauthorized. Please log in.');
}

$requestedFile = $_GET['file'] ?? '';

// Directory traversal protection
if (empty($requestedFile) || str_contains($requestedFile, '..') || str_starts_with($requestedFile, '/') || str_starts_with($requestedFile, '\\')) {
    http_response_code(400);
    die('Invalid file path.');
}

$fullPath = realpath(UPLOAD_DIR . '/' . $requestedFile);
$uploadDirReal = realpath(UPLOAD_DIR);

if (!$fullPath || !str_starts_with($fullPath, $uploadDirReal) || !file_exists($fullPath)) {
    http_response_code(404);
    die('File not found.');
}

$userId = (int)$user['id'];
$isAdmin = ($user['role'] === 'admin') || !empty($user['real_admin_id']);

if (!$isAdmin) {
    // Check if user is either buyer or seller for this proof or dispute evidence
    $pdo = Database::getConnection();

    $authStmt = $pdo->prepare("
        SELECT 1 FROM payment_proofs p
        JOIN child_orders c ON p.child_order_id = c.id
        WHERE p.screenshot_path = ? AND (c.buyer_id = ? OR c.seller_id = ?)
        UNION
        SELECT 1 FROM disputes d
        JOIN child_orders c ON d.child_order_id = c.id
        WHERE d.evidence_screenshot_path = ? AND (c.buyer_id = ? OR c.seller_id = ?)
        UNION
        SELECT 1 FROM support_messages sm
        JOIN support_requests sr ON sm.request_id = sr.id
        WHERE sm.attachment_path = ? AND sr.user_id = ?
    ");
    $authStmt->execute([$requestedFile, $userId, $userId, $requestedFile, $userId, $userId, $requestedFile, $userId]);

    if (!$authStmt->fetch()) {
        http_response_code(403);
        die('Forbidden: You are not authorized to view this transaction evidence.');
    }
}

// Serve file
$mime = mime_content_type($fullPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=86400');
readfile($fullPath);
exit;
