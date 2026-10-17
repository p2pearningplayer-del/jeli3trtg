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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (empty($_FILES['file']) && empty($_FILES['files']))) {
    jsonError('No file provided for upload.');
}

$rawFiles = !empty($_FILES['files']) ? $_FILES['files'] : $_FILES['file'];
$uploadType = $_POST['type'] ?? 'proof'; // 'proof' or 'evidence'
$targetDir = ($uploadType === 'evidence') ? EVIDENCE_UPLOAD_DIR : PROOF_UPLOAD_DIR;

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Normalize multiple files structure
$fileList = [];
if (is_array($rawFiles['name'])) {
    $count = count($rawFiles['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($rawFiles['error'][$i] === UPLOAD_ERR_OK) {
            $fileList[] = [
                'name' => $rawFiles['name'][$i],
                'type' => $rawFiles['type'][$i],
                'tmp_name' => $rawFiles['tmp_name'][$i],
                'error' => $rawFiles['error'][$i],
                'size' => $rawFiles['size'][$i]
            ];
        }
    }
} else {
    if ($rawFiles['error'] === UPLOAD_ERR_OK) {
        $fileList[] = $rawFiles;
    } else {
        jsonError('Upload error code: ' . $rawFiles['error']);
    }
}

if (empty($fileList)) {
    jsonError('No valid files were uploaded.');
}

// 50 MB limit for statements and video recordings
$maxBytes = 50 * 1024 * 1024;
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'mp4', 'webm', 'mkv'];
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/webp',
    'application/pdf',
    'video/mp4', 'video/webm', 'video/x-matroska', 'video/quicktime', 'application/octet-stream'
];

$savedPaths = [];
$savedUrls = [];

foreach ($fileList as $f) {
    if ($f['size'] > $maxBytes) {
        jsonError("File '{$f['name']}' exceeds 50MB size limit.");
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExtensions, true)) {
        jsonError("Invalid file type for '{$f['name']}'. Only JPG, PNG, PDF, and MP4/WebM videos are permitted.");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes, true) && !in_array($ext, ['pdf', 'mp4', 'webm', 'mkv'])) {
        jsonError("Unrecognized MIME type ({$mime}) for '{$f['name']}'.");
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($f['tmp_name'], $targetPath)) {
        jsonError("Failed to store uploaded file '{$f['name']}'.");
    }

    $relativeUri = ($uploadType === 'evidence') ? "evidence/{$filename}" : "proofs/{$filename}";
    $savedPaths[] = $relativeUri;
    $savedUrls[] = APP_URL . '/serve_file.php?file=' . urlencode($relativeUri);
}

$firstPath = $savedPaths[0];
$joinedPaths = implode(',', $savedPaths);

jsonResponse(true, [
    'message' => count($savedPaths) > 1 ? count($savedPaths) . ' files uploaded successfully.' : 'File uploaded successfully.',
    'file_path' => $joinedPaths,
    'file_paths' => $savedPaths,
    'file_url' => $savedUrls[0],
    'file_urls' => $savedUrls
]);
