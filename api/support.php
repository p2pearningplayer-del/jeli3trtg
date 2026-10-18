<?php
/**
 * Support & Real-time Live Chat API Controller
 * Provides real-time messaging between users and support agents,
 * Telegram redirection, file attachment support, instant agent assignment,
 * and high-frequency admin synchronization.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/response.php';

// Ensure schema is installed (including support_messages and support_requests)
SchemaInstaller::ensureSchema();

/**
 * Resolves the authenticated administrator, supporting super_admin, demoadmin,
 * support agents, and impersonation / support mode sessions.
 */
function getAdminUser(): ?array {
    $user = Auth::getCurrentUser();
    if (!$user && !empty($_SESSION['admin_user_id'])) {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['admin_user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    if (!$user) return null;
    if ($user['role'] === 'admin' || !empty($user['real_admin_id']) || !empty($user['admin_role']) || !empty($_SESSION['admin_role']) || !empty($_SESSION['is_admin'])) {
        return $user;
    }
    return null;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'config';
$input = getJsonInput();

$pdo = Database::getConnection();

switch ($action) {
    case 'config':
        $stmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
        $stmt->execute();
        $row = $stmt->fetch();
        $telegramUrl = $row['setting_value'] ?? 'https://t.me/jelipay_support';

        jsonResponse(true, [
            'telegram_support_url' => $telegramUrl,
            'default_support_email' => 'support@jelipay.in',
            'support_hours' => '24/7 Live Assistance',
            'average_response_minutes' => 2
        ]);
        break;

    // -------------------------------------------------------------
    // USER: GET OR START ACTIVE CHAT SESSION
    // -------------------------------------------------------------
    case 'session':
        $user = Auth::getCurrentUser();
        $phone = '';
        $username = 'Guest';
        $userId = null;

        if ($user) {
            $phone = $user['phone'];
            $username = $user['account_holder_name'] ?? $user['username'] ?? 'User';
            $userId = (int)$user['id'];
        } else {
            $phone = trim($input['phone'] ?? $_SESSION['guest_support_phone'] ?? '');
            $username = trim($input['username'] ?? 'Guest User');
        }

        // Look for open session
        $session = null;
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT * FROM support_requests
                WHERE user_id = ? AND status != 'resolved'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            $session = $stmt->fetch();
        } elseif (!empty($_SESSION['guest_support_request_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
            $stmt->execute([(int)$_SESSION['guest_support_request_id']]);
            $session = $stmt->fetch();
        } elseif (!empty($phone)) {
            $cleanPhone = Validator::cleanPhone($phone);
            $stmt = $pdo->prepare("
                SELECT * FROM support_requests
                WHERE phone = ? AND status != 'resolved'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$cleanPhone]);
            $session = $stmt->fetch();
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        // Create new session if none exists
        if (!$session) {
            $cleanPhone = !empty($phone) ? Validator::cleanPhone($phone) : '9999999999';
            $stmt = $pdo->prepare("
                INSERT INTO support_requests (user_id, phone, username, message, status, created_at, updated_at)
                VALUES (?, ?, ?, 'Live Chat session initiated', 'open', ?, ?)
            ");
            $stmt->execute([$userId, $cleanPhone, $username, $nowUtc, $nowUtc]);
            $requestId = (int)$pdo->lastInsertId();

            if (!$userId) {
                $_SESSION['guest_support_request_id'] = $requestId;
                $_SESSION['guest_support_phone'] = $cleanPhone;
            }

            // Insert initial welcome message from Support Desk
            $welcomeMsg = "👋 Hello " . ($userId ? htmlspecialchars($username) : '') . "! Welcome to JeliPay Live Support. How can our escrow & settlement desk assist you today?";
            $msgStmt = $pdo->prepare("
                INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
                VALUES (?, 'system', 'Support Desk', ?, 1, ?)
            ");
            $msgStmt->execute([$requestId, $welcomeMsg, $nowUtc]);

            $stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
            $stmt->execute([$requestId]);
            $session = $stmt->fetch();
        }

        $requestId = (int)$session['id'];

        // Fetch messages for this session
        $msgStmt = $pdo->prepare("
            SELECT id, request_id, sender_type, sender_id, sender_name, message, attachment_path, is_read, created_at
            FROM support_messages
            WHERE request_id = ?
            ORDER BY id ASC
        ");
        $msgStmt->execute([$requestId]);
        $messages = $msgStmt->fetchAll();

        // Mark admin/system messages as read by user
        $pdo->prepare("
            UPDATE support_messages
            SET is_read = 1
            WHERE request_id = ? AND sender_type IN ('admin', 'system') AND is_read = 0
        ")->execute([$requestId]);

        jsonResponse(true, [
            'session' => $session,
            'messages' => $messages,
            'is_authenticated' => (bool)$userId,
            'user' => $user ? [
                'id' => $user['id'],
                'name' => $user['account_holder_name'] ?? 'User',
                'phone' => $user['phone']
            ] : null
        ]);
        break;

    // -------------------------------------------------------------
    // USER / ADMIN: POLL NEW MESSAGES FOR A SESSION
    // -------------------------------------------------------------
    case 'poll_messages':
        $requestId = (int)($_GET['request_id'] ?? $input['request_id'] ?? 0);
        $afterId = (int)($_GET['after_id'] ?? $input['after_id'] ?? 0);

        if ($requestId <= 0) {
            jsonError('Invalid request ID.');
        }

        $admin = getAdminUser();
        $user = Auth::getCurrentUser();
        $isAdmin = (bool)$admin;

        // Check ownership if not admin
        $stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $session = $stmt->fetch();
        if (!$session) {
            jsonError('Chat session not found.', 404);
        }

        if (!$isAdmin) {
            $isOwner = false;
            if ($user && (int)$session['user_id'] === (int)$user['id']) $isOwner = true;
            if (!empty($_SESSION['guest_support_request_id']) && (int)$_SESSION['guest_support_request_id'] === $requestId) $isOwner = true;
            if (!$isOwner) {
                jsonError('Unauthorized access to this chat session.', 403);
            }
        }

        $msgStmt = $pdo->prepare("
            SELECT id, request_id, sender_type, sender_id, sender_name, message, attachment_path, is_read, created_at
            FROM support_messages
            WHERE request_id = ? AND id > ?
            ORDER BY id ASC
        ");
        $msgStmt->execute([$requestId, $afterId]);
        $newMessages = $msgStmt->fetchAll();

        // Mark incoming messages as read
        if (!empty($newMessages)) {
            $markType = $isAdmin ? "'user'" : "'admin', 'system'";
            $pdo->prepare("
                UPDATE support_messages
                SET is_read = 1
                WHERE request_id = ? AND sender_type IN ({$markType}) AND is_read = 0
            ")->execute([$requestId]);
        }

        jsonResponse(true, [
            'request_id' => $requestId,
            'session_status' => $session['status'],
            'messages' => $newMessages
        ]);
        break;

    // -------------------------------------------------------------
    // USER: SEND A MESSAGE IN LIVE CHAT
    // -------------------------------------------------------------
    case 'send_message':
        $requestId = (int)($input['request_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        $attachmentPath = trim($input['attachment_path'] ?? '');
        $phone = trim($input['phone'] ?? '');

        if (empty($message) && empty($attachmentPath)) {
            jsonError('Message cannot be empty.');
        }

        $user = Auth::getCurrentUser();
        $nowUtc = gmdate('Y-m-d H:i:s');

        // If no request_id provided or <= 0, find open session first to prevent thread splitting
        if ($requestId <= 0) {
            $userId = $user ? (int)$user['id'] : null;
            $userPhone = $user ? $user['phone'] : (!empty($phone) ? Validator::cleanPhone($phone) : '');
            $userName = $user ? ($user['account_holder_name'] ?? 'User') : 'Guest User';

            if (empty($userPhone) && !$userId) {
                jsonError('Please provide your 10-digit phone number to start live chat.');
            }

            // Look for existing active session first
            $existing = null;
            if ($userId) {
                $stmt = $pdo->prepare("SELECT id FROM support_requests WHERE user_id = ? AND status != 'resolved' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
                $existing = $stmt->fetch();
            } elseif (!empty($_SESSION['guest_support_request_id'])) {
                $stmt = $pdo->prepare("SELECT id FROM support_requests WHERE id = ? AND status != 'resolved'");
                $stmt->execute([(int)$_SESSION['guest_support_request_id']]);
                $existing = $stmt->fetch();
            } elseif (!empty($userPhone)) {
                $stmt = $pdo->prepare("SELECT id FROM support_requests WHERE phone = ? AND status != 'resolved' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userPhone]);
                $existing = $stmt->fetch();
            }

            if ($existing) {
                $requestId = (int)$existing['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO support_requests (user_id, phone, username, message, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'open', ?, ?)
                ");
                $stmt->execute([$userId, $userPhone, $userName, $message, $nowUtc, $nowUtc]);
                $requestId = (int)$pdo->lastInsertId();

                if (!$userId) {
                    $_SESSION['guest_support_request_id'] = $requestId;
                    $_SESSION['guest_support_phone'] = $userPhone;
                }
            }
        }

        // Verify session exists
        $stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $session = $stmt->fetch();
        if (!$session) {
            jsonError('Chat session not found.', 404);
        }

        $senderName = $user ? ($user['account_holder_name'] ?? 'User') : ($session['username'] ?: 'User');
        $senderId = $user ? (int)$user['id'] : null;

        // Insert message
        $msgStmt = $pdo->prepare("
            INSERT INTO support_messages (request_id, sender_type, sender_id, sender_name, message, attachment_path, is_read, created_at)
            VALUES (?, 'user', ?, ?, ?, ?, 0, ?)
        ");
        $msgStmt->execute([$requestId, $senderId, $senderName, $message, $attachmentPath ?: null, $nowUtc]);
        $msgId = (int)$pdo->lastInsertId();

        // Update session timestamp & status (reopen if resolved)
        $pdo->prepare("
            UPDATE support_requests
            SET status = CASE WHEN status = 'resolved' THEN 'open' ELSE status END,
                updated_at = ?
            WHERE id = ?
        ")->execute([$nowUtc, $requestId]);

        // Dispatch alerts to assigned admin agent specifically, and all admins
        $preview = mb_substr($message, 0, 50);
        if (!empty($session['assigned_admin_id'])) {
            Auth::createNotification(
                (int)$session['assigned_admin_id'],
                'Assigned Live Chat Alert',
                "User +91 {$session['phone']}: {$preview}",
                'support',
                (string)$requestId
            );
        }

        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $adminStmt->fetchAll();
        foreach ($admins as $adm) {
            if (!empty($session['assigned_admin_id']) && (int)$adm['id'] === (int)$session['assigned_admin_id']) {
                continue;
            }
            Auth::createNotification(
                (int)$adm['id'],
                'New Live Chat Message',
                "User +91 {$session['phone']}: {$preview}",
                'support',
                (string)$requestId
            );
        }

        // Automated Instant Assistant:
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE request_id = ? AND sender_type = 'system'");
        $countStmt->execute([$requestId]);
        $systemMsgCount = (int)$countStmt->fetchColumn();

        if ($systemMsgCount <= 1) {
            $lower = strtolower($message);
            $autoReply = null;

            if (str_contains($lower, 'utr') || str_contains($lower, 'payment') || str_contains($lower, 'upi')) {
                $autoReply = "ℹ️ Note: Payment verifications are processed promptly. Please double-check your 12-digit bank UTR. An agent is reviewing your active transaction.";
            } elseif (str_contains($lower, 'tpin') || str_contains($lower, 'pin')) {
                $autoReply = "ℹ️ Note: You can configure or change your Selling T-PIN in your Profile tab under 'Security & T-PIN'.";
            } elseif (str_contains($lower, 'appeal') || str_contains($lower, 'dispute')) {
                $autoReply = "ℹ️ Note: For disputes, you can upload bank statements or video recordings directly in the Appeals Center for admin arbitration.";
            } else {
                $autoReply = "An agent has been assigned to your chat and will reply shortly. Feel free to attach screenshots if relevant.";
            }

            if ($autoReply) {
                $pdo->prepare("
                    INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
                    VALUES (?, 'system', 'Support Desk', ?, 1, ?)
                ")->execute([$requestId, $autoReply, gmdate('Y-m-d H:i:s', time() + 1)]);
            }
        }

        jsonResponse(true, [
            'request_id' => $requestId,
            'message_id' => $msgId,
            'created_at' => $nowUtc
        ]);
        break;

    // -------------------------------------------------------------
    // ADMIN: UNIFIED FAST REAL-TIME SYNC
    // -------------------------------------------------------------
    case 'admin_sync':
        $admin = getAdminUser();
        if (!$admin) {
            jsonError('Admin authentication required.', 403);
        }

        $activeRequestId = (int)($_GET['active_request_id'] ?? $input['active_request_id'] ?? 0);
        $lastMsgId = (int)($_GET['last_msg_id'] ?? $input['last_msg_id'] ?? 0);
        $filter = $_GET['filter'] ?? $input['filter'] ?? 'all';
        $since = trim($_GET['since'] ?? $input['since'] ?? '');

        // 1. Fetch conversations with assigned admin name
        $sql = "
            SELECT s.id, s.user_id, s.phone, s.username, s.status, s.assigned_admin_id,
                   s.created_at, s.updated_at,
                   u.account_holder_name, u.health_score, u.account_status,
                   adm.account_holder_name as assigned_admin_name,
                   (SELECT message FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_message_at,
                   (SELECT sender_type FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_sender_type,
                   (SELECT COUNT(*) FROM support_messages WHERE request_id = s.id AND sender_type = 'user' AND is_read = 0) as unread_count
            FROM support_requests s
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN users adm ON s.assigned_admin_id = adm.id
        ";

        if ($filter === 'open') {
            $sql .= " WHERE s.status IN ('pending', 'open', 'in_progress') ";
        } elseif ($filter === 'resolved') {
            $sql .= " WHERE s.status = 'resolved' ";
        }

        $sql .= " ORDER BY unread_count DESC, s.updated_at DESC LIMIT 60";

        $stmt = $pdo->query($sql);
        $chats = $stmt->fetchAll();

        // 2. Fetch total unread user messages across platform
        $totalUnreadStmt = $pdo->query("
            SELECT COUNT(*) FROM support_messages m
            INNER JOIN support_requests s ON m.request_id = s.id
            WHERE m.sender_type = 'user' AND m.is_read = 0 AND s.status != 'resolved'
        ");
        $totalUnread = (int)$totalUnreadStmt->fetchColumn();

        // 3. Fetch active chat messages if active_request_id passed
        $activeMessages = [];
        $activeSession = null;
        if ($activeRequestId > 0) {
            $sessStmt = $pdo->prepare("
                SELECT s.*, u.account_holder_name, u.health_score, u.account_status,
                       adm.account_holder_name as assigned_admin_name
                FROM support_requests s
                LEFT JOIN users u ON s.user_id = u.id
                LEFT JOIN users adm ON s.assigned_admin_id = adm.id
                WHERE s.id = ?
            ");
            $sessStmt->execute([$activeRequestId]);
            $activeSession = $sessStmt->fetch();

            $msgStmt = $pdo->prepare("
                SELECT id, request_id, sender_type, sender_id, sender_name, message, attachment_path, is_read, created_at
                FROM support_messages
                WHERE request_id = ? AND id > ?
                ORDER BY id ASC
            ");
            $msgStmt->execute([$activeRequestId, $lastMsgId]);
            $activeMessages = $msgStmt->fetchAll();

            // Mark active chat user messages as read
            if (!empty($activeMessages)) {
                $pdo->prepare("
                    UPDATE support_messages
                    SET is_read = 1
                    WHERE request_id = ? AND sender_type = 'user' AND is_read = 0
                ")->execute([$activeRequestId]);
            }
        }

        // 4. Check for any recent incoming message (in the last 10 seconds or since timestamp)
        $nowTs = time();
        $cutoff = !empty($since) ? $since : gmdate('Y-m-d H:i:s', $nowTs - 10);
        $recentStmt = $pdo->prepare("
            SELECT m.id, m.request_id, m.sender_name, m.message, m.created_at, s.phone, s.assigned_admin_id
            FROM support_messages m
            INNER JOIN support_requests s ON m.request_id = s.id
            WHERE m.sender_type = 'user' AND m.created_at >= ?
            ORDER BY m.id DESC LIMIT 5
        ");
        $recentStmt->execute([$cutoff]);
        $recentIncoming = $recentStmt->fetchAll();

        jsonResponse(true, [
            'chats' => $chats,
            'unread_total' => $totalUnread,
            'active_session' => $activeSession,
            'active_messages' => $activeMessages,
            'recent_incoming' => $recentIncoming,
            'server_time' => gmdate('Y-m-d H:i:s', $nowTs),
            'admin' => [
                'id' => $admin['id'],
                'name' => $admin['account_holder_name'] ?? 'Admin',
                'role' => $admin['admin_role'] ?? $admin['role']
            ]
        ]);
        break;

    // -------------------------------------------------------------
    // ADMIN: LIST ALL SUPPORT CHATS
    // -------------------------------------------------------------
    case 'admin_chats':
    case 'list':
        $admin = getAdminUser();
        if (!$admin) {
            jsonError('Admin authentication required.', 403);
        }

        $filter = $_GET['filter'] ?? 'all'; // 'all', 'open', 'resolved'

        $sql = "
            SELECT s.id, s.user_id, s.phone, s.username, s.status, s.assigned_admin_id,
                   s.created_at, s.updated_at,
                   u.account_holder_name, u.health_score, u.account_status,
                   adm.account_holder_name as assigned_admin_name,
                   (SELECT message FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_message_at,
                   (SELECT sender_type FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_sender_type,
                   (SELECT COUNT(*) FROM support_messages WHERE request_id = s.id AND sender_type = 'user' AND is_read = 0) as unread_count
            FROM support_requests s
            LEFT JOIN users u ON s.user_id = u.id
            LEFT JOIN users adm ON s.assigned_admin_id = adm.id
        ";

        if ($filter === 'open') {
            $sql .= " WHERE s.status IN ('pending', 'open', 'in_progress') ";
        } elseif ($filter === 'resolved') {
            $sql .= " WHERE s.status = 'resolved' ";
        }

        $sql .= " ORDER BY unread_count DESC, s.updated_at DESC LIMIT 100";

        $stmt = $pdo->query($sql);
        $chats = $stmt->fetchAll();

        jsonResponse(true, ['chats' => $chats]);
        break;

    // -------------------------------------------------------------
    // ADMIN: ASSIGN AGENT TO CHAT
    // -------------------------------------------------------------
    case 'assign_agent':
        $admin = getAdminUser();
        if (!$admin) {
            jsonError('Admin authentication required.', 403);
        }

        $requestId = (int)($input['request_id'] ?? 0);
        $agentId = (int)($input['admin_id'] ?? $admin['id']);

        if ($requestId <= 0) {
            jsonError('Invalid request ID.');
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $agentName = $admin['account_holder_name'] ?? 'Support Agent';

        // Check if agent exists
        if ($agentId !== (int)$admin['id']) {
            $agentStmt = $pdo->prepare("SELECT account_holder_name FROM users WHERE id = ?");
            $agentStmt->execute([$agentId]);
            $agentRow = $agentStmt->fetch();
            if ($agentRow) {
                $agentName = $agentRow['account_holder_name'];
            }
        }

        $pdo->prepare("
            UPDATE support_requests
            SET assigned_admin_id = ?, status = 'in_progress', updated_at = ?
            WHERE id = ?
        ")->execute([$agentId, $nowUtc, $requestId]);

        // Add notice message
        $pdo->prepare("
            INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
            VALUES (?, 'system', 'Support Desk', ?, 1, ?)
        ")->execute([$requestId, "Support Agent {$agentName} has joined and taken ownership of this chat session.", $nowUtc]);

        jsonResponse(true, [
            'message' => 'Agent assigned successfully.',
            'assigned_admin_id' => $agentId,
            'assigned_admin_name' => $agentName
        ]);
        break;

    // -------------------------------------------------------------
    // ADMIN: SEND REPLY TO USER
    // -------------------------------------------------------------
    case 'admin_reply':
        $admin = getAdminUser();
        if (!$admin) {
            jsonError('Admin authentication required.', 403);
        }

        $requestId = (int)($input['request_id'] ?? 0);
        $message = trim($input['message'] ?? '');
        $attachmentPath = trim($input['attachment_path'] ?? '');

        if ($requestId <= 0 || (empty($message) && empty($attachmentPath))) {
            jsonError('Invalid request ID or empty message.');
        }

        $stmt = $pdo->prepare("SELECT * FROM support_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        $session = $stmt->fetch();
        if (!$session) {
            jsonError('Chat session not found.', 404);
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $adminName = $admin['account_holder_name'] ?? 'Support Desk';

        $msgStmt = $pdo->prepare("
            INSERT INTO support_messages (request_id, sender_type, sender_id, sender_name, message, attachment_path, is_read, created_at)
            VALUES (?, 'admin', ?, ?, ?, ?, 0, ?)
        ");
        $msgStmt->execute([$requestId, $admin['id'], $adminName, $message, $attachmentPath ?: null, $nowUtc]);
        $msgId = (int)$pdo->lastInsertId();

        // Mark session in_progress and assign to this admin if unassigned
        $assignedId = !empty($session['assigned_admin_id']) ? $session['assigned_admin_id'] : $admin['id'];
        $pdo->prepare("
            UPDATE support_requests
            SET status = 'in_progress', assigned_admin_id = ?, updated_at = ?
            WHERE id = ?
        ")->execute([$assignedId, $nowUtc, $requestId]);

        // Notify user if authenticated
        if (!empty($session['user_id'])) {
            $preview = mb_substr($message, 0, 50);
            Auth::createNotification(
                (int)$session['user_id'],
                'Support Agent Reply',
                "Agent: {$preview}",
                'support',
                (string)$requestId
            );
        }

        jsonResponse(true, [
            'message_id' => $msgId,
            'request_id' => $requestId,
            'created_at' => $nowUtc
        ]);
        break;

    // -------------------------------------------------------------
    // ADMIN: UPDATE SESSION STATUS (open, in_progress, resolved)
    // -------------------------------------------------------------
    case 'admin_update_status':
        $admin = getAdminUser();
        if (!$admin) {
            jsonError('Admin authentication required.', 403);
        }

        $requestId = (int)($input['request_id'] ?? 0);
        $newStatus = trim($input['status'] ?? 'resolved');

        if (!in_array($newStatus, ['open', 'in_progress', 'resolved'], true)) {
            jsonError('Invalid status.');
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            UPDATE support_requests
            SET status = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $nowUtc, $requestId]);

        if ($newStatus === 'resolved') {
            $pdo->prepare("
                INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
                VALUES (?, 'system', 'Support Desk', '✅ This support inquiry was marked as resolved. If you need any further assistance, feel free to reply below at any time.', 1, ?)
            ")->execute([$requestId, $nowUtc]);
        }

        jsonResponse(true, ['message' => "Chat status updated to {$newStatus}."]);
        break;

    // -------------------------------------------------------------
    // REAL-TIME SERVER-SENT EVENTS (SSE) STREAM
    // -------------------------------------------------------------
    case 'sse_stream':
        $admin = getAdminUser();
        $user = Auth::getCurrentUser();

        if (!$admin && !$user && empty($_SESSION['guest_support_request_id'])) {
            http_response_code(403);
            echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
            exit;
        }

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $requestId = (int)($_GET['request_id'] ?? 0);
        $lastId = (int)($_GET['last_id'] ?? 0);
        $isAdmin = (bool)$admin;

        $start = time();
        // Stream up to 25 seconds per HTTP request
        while (time() - $start < 25) {
            if (connection_aborted()) {
                break;
            }

            if ($isAdmin) {
                $stmt = $pdo->prepare("
                    SELECT m.id, m.request_id, m.sender_name, m.message, m.created_at, s.phone
                    FROM support_messages m
                    INNER JOIN support_requests s ON m.request_id = s.id
                    WHERE m.sender_type = 'user' AND m.id > ?
                    ORDER BY m.id ASC LIMIT 10
                ");
                $stmt->execute([$lastId]);
                $msgs = $stmt->fetchAll();
                if (!empty($msgs)) {
                    foreach ($msgs as $m) {
                        if ($m['id'] > $lastId) $lastId = $m['id'];
                    }
                    echo "event: new_message\n";
                    echo "data: " . json_encode(['messages' => $msgs, 'last_id' => $lastId]) . "\n\n";
                    @flush();
                }
            } elseif ($requestId > 0) {
                $stmt = $pdo->prepare("
                    SELECT id, request_id, sender_type, sender_name, message, attachment_path, created_at
                    FROM support_messages
                    WHERE request_id = ? AND id > ? AND sender_type IN ('admin', 'system')
                    ORDER BY id ASC LIMIT 10
                ");
                $stmt->execute([$requestId, $lastId]);
                $msgs = $stmt->fetchAll();
                if (!empty($msgs)) {
                    foreach ($msgs as $m) {
                        if ($m['id'] > $lastId) $lastId = $m['id'];
                    }
                    echo "event: new_message\n";
                    echo "data: " . json_encode(['messages' => $msgs, 'last_id' => $lastId]) . "\n\n";
                    @flush();
                }
            }

            echo ": ping\n\n";
            @flush();
            usleep(1500000); // 1.5 seconds sleep
        }
        exit;

    // -------------------------------------------------------------
    // LEGACY COMPATIBILITY: SUBMIT PHONE REQUEST
    // -------------------------------------------------------------
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

        $phoneDigits = Validator::cleanPhone($phone);
        if (strlen($phoneDigits) !== 10) {
            jsonError('Please provide a valid 10-digit Indian phone number.');
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO support_requests (user_id, phone, username, message, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'open', ?, ?)
        ");
        $stmt->execute([$userId, $phoneDigits, $username, $message, $nowUtc, $nowUtc]);
        $requestId = (int)$pdo->lastInsertId();

        // Create initial message
        $pdo->prepare("
            INSERT INTO support_messages (request_id, sender_type, sender_id, sender_name, message, is_read, created_at)
            VALUES (?, 'user', ?, ?, ?, 0, ?)
        ")->execute([$requestId, $userId, $username, $message, $nowUtc]);

        // Dispatch notifications to admins
        $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'admin'");
        $admins = $adminStmt->fetchAll();
        foreach ($admins as $admin) {
            Auth::createNotification(
                (int)$admin['id'],
                'New Live Chat Request',
                "User +91 {$phoneDigits} ({$username}) requested live chat assistance. Assigned support required.",
                'support',
                (string)$requestId
            );
        }

        if ($userId) {
            Auth::createNotification(
                $userId,
                'Live Chat Request Submitted',
                "Your phone (+91 {$phoneDigits}) has been transmitted to our live agent queue. An agent will connect with you shortly.",
                'support',
                (string)$requestId
            );
        }

        jsonResponse(true, [
            'message' => "Live Chat request submitted successfully! Your phone (+91 {$phoneDigits}) has been transmitted to our admin desk. An agent is being assigned to assist you.",
            'request_id' => $requestId,
            'phone' => $phoneDigits,
            'status' => 'pending'
        ]);
        break;

    default:
        jsonError("Unknown support action '{$action}'.", 404);
}
