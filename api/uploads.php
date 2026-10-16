<?php
/**
 * Secure File Upload Handler
 * Stores payment proofs and dispute evidence securely with MIME validation.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/response.php';

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required to upload files.', 401);
}

if ($user['is_impersonating']) {
    jsonError('File uploads are disabled in Admin Support Mode.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    jsonError('No file provided for upload.');
}

$file = $_FILES['file'];
$uploadType = $_POST['type'] ?? 'proof'; // 'proof' or 'evidence'

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonError('Upload error code: ' . $file['error']);
}

// 5 MB limit
if ($file['size'] > 5 * 1024 * 1024) {
    jsonError('File size exceeds 5MB limit.');
}

// Strict extension & MIME validation
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExtensions, true)) {
    jsonError('Invalid file type. Only JPG, PNG, and WebP images are permitted.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    jsonError('Invalid image content detected.');
}

$targetDir = ($uploadType === 'evidence') ? EVIDENCE_UPLOAD_DIR : PROOF_UPLOAD_DIR;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Generate cryptographically unguessable filename
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$targetPath = $targetDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    jsonError('Failed to save uploaded file.');
}

// Secure relative path
$relativeUri = ($uploadType === 'evidence') ? "evidence/{$filename}" : "proofs/{$filename}";

jsonResponse(true, [
    'message' => 'File uploaded successfully.',
    'file_path' => $relativeUri,
    'file_url' => APP_URL . '/serve_file.php?file=' . urlencode($relativeUri)
]);
