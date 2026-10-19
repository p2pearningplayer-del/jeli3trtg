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
    $pdo = Database::getConnection();

    // Check support request attachment
    $suppStmt = $pdo->prepare("
        SELECT 1 FROM support_messages sm
        JOIN support_requests sr ON sm.request_id = sr.id
        WHERE sm.attachment_path = ? AND sr.user_id = ?
    ");
    $suppStmt->execute([$requestedFile, $userId]);
    $isSupport = (bool)$suppStmt->fetch();

    if (!$isSupport) {
        // Check payment proofs and dispute evidence
        $authStmt = $pdo->prepare("
            SELECT c.status as order_status, d.status as dispute_status
            FROM payment_proofs p
            JOIN child_orders c ON p.child_order_id = c.id
            LEFT JOIN disputes d ON c.id = d.child_order_id
            WHERE p.screenshot_path = ? AND (c.buyer_id = ? OR c.seller_id = ?)
            UNION
            SELECT c.status as order_status, d.status as dispute_status
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            WHERE d.evidence_screenshot_path = ? AND (c.buyer_id = ? OR c.seller_id = ?)
        ");
        $authStmt->execute([$requestedFile, $userId, $userId, $requestedFile, $userId, $userId]);
        $rows = $authStmt->fetchAll();

        if (empty($rows)) {
            http_response_code(403);
            die('Forbidden: You are not authorized to view this transaction evidence.');
        }

        // Part 12, Item 3: Once an order reaches a final state (Success or Cancelled),
        // screenshot evidence is hidden to prevent unauthorized viewing.
        $finalStates = ['completed', 'cancelled', 'expired', 'cancelled / reversed'];
        $hasActiveOrder = false;
        foreach ($rows as $row) {
            if (!in_array(strtolower($row['order_status'] ?? ''), $finalStates)) {
                $hasActiveOrder = true;
                break;
            }
        }

        if (!$hasActiveOrder) {
            http_response_code(403);
            die('Forbidden: Transaction evidence is securely archived and no longer accessible once the order is finalized.');
        }
    }
}

// Serve file
$mime = mime_content_type($fullPath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=86400');
readfile($fullPath);
exit;
