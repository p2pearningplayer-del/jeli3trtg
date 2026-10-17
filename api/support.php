<?php
/**
 * Support & Live Chat API Controller (Part 8, Item 3)
 * Handles Telegram redirection URL and Live Chat agent dispatch with phone transmission.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/response.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'config';
$input = getJsonInput();

$pdo = Database::getConnection();

switch ($action) {
    case 'config':
        // Retrieve configured Telegram support URL
        $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
        $stmt->execute();
        $row = $stmt->fetch();
        $telegramUrl = $row['setting_value'] ?? 'https://t.me/jelipay_support';

        jsonResponse(true, [
            'telegram_support_url' => $telegramUrl,
            'default_support_email' => 'support@jelipay.in'
        ]);
        break;

    case 'live_chat':
        $user = Auth::getCurrentUser();
        $phone = '';
        $username = null;
        $userId = null;
        $message = trim($input['message'] ?? 'User requested live chat assistance.');

        if ($user) {
            $phone = $user['phone'];
            $username = $user['username'] ?? $user['account_holder_name'] ?? 'User';
            $userId = (int)$user['id'];
        } else {
            $phone = trim($input['phone'] ?? '');
            $username = trim($input['name'] ?? 'Guest');
        }

        if (empty($phone)) {
            jsonError('A valid phone number is required to request live chat support.');
        }

        // Clean phone
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneDigits) === 12 && str_starts_with($phoneDigits, '91')) {
            $phoneDigits = substr($phoneDigits, 2);
        }
        if (strlen($phoneDigits) !== 10) {
            jsonError('Please provide a valid 10-digit Indian phone number.');
        }

        // Store live chat request
        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO support_requests (user_id, phone, username, message, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([$userId, $phoneDigits, $username, $message, $nowUtc, $nowUtc]);
        $requestId = (int)$pdo->lastInsertId();

        // Dispatch notifications to admins
        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $adminStmt->fetchAll();
        foreach ($admins as $admin) {
            Auth::createNotification(
                (int)$admin['id'],
                'New Live Chat Request',
                "User +91 {$phoneDigits} ({$username}) requested live chat assistance. Assigned support required.",
                'support',
                $requestId
            );
        }

        // Also notify user if authenticated
        if ($userId) {
            Auth::createNotification(
                $userId,
                'Live Chat Request Submitted',
                "Your phone (+91 {$phoneDigits}) has been transmitted to our live agent queue. An agent will connect with you shortly.",
                'support',
                $requestId
            );
        }

        jsonResponse(true, [
            'message' => "Live Chat request submitted successfully! Your phone (+91 {$phoneDigits}) has been transmitted to our admin desk. An agent is being assigned to assist you.",
            'request_id' => $requestId,
            'phone' => $phoneDigits,
            'status' => 'pending'
        ]);
        break;

    case 'list':
        $user = Auth::getCurrentUser();
        if (!$user || $user['role'] !== 'admin') {
            jsonError('Admin authentication required.', 403);
        }

        $stmt = $pdo->query("
            SELECT s.*, u.health_score, u.account_status
            FROM support_requests s
            LEFT JOIN users u ON s.user_id = u.id
            ORDER BY s.id DESC
            LIMIT 50
        ");
        $requests = $stmt->fetchAll();
        jsonResponse(true, ['requests' => $requests]);
        break;

    default:
        jsonError("Unknown support action '{$action}'.", 404);
}
