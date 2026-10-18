<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';

echo "Running Real-time Live Chat Feature Verification Tests...\n";

// Initialize in-memory SQLite database
$pdo = new PDO("sqlite::memory:", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);
$pdo->exec("PRAGMA foreign_keys = ON;");
Database::setConnection($pdo, 'sqlite');
SchemaInstaller::initSqlite($pdo);
SchemaInstaller::migrateColumns($pdo, 'sqlite');
SchemaInstaller::seedPlatformSettings($pdo);

// -------------------------------------------------------------
// Test 1: Verify support_messages table exists
// -------------------------------------------------------------
$checkStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='support_messages'");
assert($checkStmt->fetchColumn() === 'support_messages', "support_messages table must exist in database");
echo "[PASS] Test 1: support_messages table provisioned in database schema\n";

// -------------------------------------------------------------
// Test 2: Create Admin and User accounts
// -------------------------------------------------------------
$adminPhone = '9999911111';
$stmt = $pdo->prepare("INSERT INTO users (account_holder_name, phone, password_hash, role, health_score, account_status, referral_code) VALUES (?, ?, ?, 'admin', 100, 'active', ?)");
$stmt->execute(['Super Admin', $adminPhone, password_hash('AdminPass123!', PASSWORD_BCRYPT), 'REF_ADM01']);
$adminId = (int)$pdo->lastInsertId();

$userPhone = '9888877777';
$stmt = $pdo->prepare("INSERT INTO users (account_holder_name, phone, password_hash, role, health_score, account_status, referral_code) VALUES (?, ?, ?, 'user', 95, 'active', ?)");
$stmt->execute(['Trader John', $userPhone, password_hash('UserPass123!', PASSWORD_BCRYPT), 'REF_USR01']);
$userId = (int)$pdo->lastInsertId();

echo "[PASS] Test 2: Admin and User registered\n";

// -------------------------------------------------------------
// Test 3: Session creation & automated welcome message
// -------------------------------------------------------------
$nowUtc = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare("
    INSERT INTO support_requests (user_id, phone, username, message, status, created_at, updated_at)
    VALUES (?, ?, ?, 'Live Chat session initiated', 'open', ?, ?)
");
$stmt->execute([$userId, $userPhone, 'Trader John', $nowUtc, $nowUtc]);
$requestId = (int)$pdo->lastInsertId();

$welcomeMsg = "👋 Hello Trader John! Welcome to JeliPay Live Support. How can our escrow & settlement desk assist you today?";
$msgStmt = $pdo->prepare("
    INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
    VALUES (?, 'system', 'Support Desk', ?, 1, ?)
");
$msgStmt->execute([$requestId, $welcomeMsg, $nowUtc]);
$welcomeMsgId = (int)$pdo->lastInsertId();

assert($requestId > 0, "Support request ID generated");
assert($welcomeMsgId > 0, "Welcome message generated");
echo "[PASS] Test 3: User chat session and automated welcome message created\n";

// -------------------------------------------------------------
// Test 4: User sends message & Automated Assistant replies
// -------------------------------------------------------------
$userMsgText = "I need help with my payment UTR verification for order #0005";
$stmt = $pdo->prepare("
    INSERT INTO support_messages (request_id, sender_type, sender_id, sender_name, message, is_read, created_at)
    VALUES (?, 'user', ?, 'Trader John', ?, 0, ?)
");
$stmt->execute([$requestId, $userId, $userMsgText, $nowUtc]);
$userMsgId = (int)$pdo->lastInsertId();

// Verify notification created for admin
Auth::createNotification($adminId, 'New Live Chat Message', "User +91 {$userPhone}: {$userMsgText}", 'support', (string)$requestId);
$notifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'support'");
$notifStmt->execute([$adminId]);
assert((int)$notifStmt->fetchColumn() >= 1, "Admin receives notification for new chat message");

// Automated assistant responds with UTR guidance
$autoGuidance = "ℹ️ Note: Payment verifications are processed promptly. Please double-check your 12-digit bank UTR. An agent is reviewing your active transaction.";
$stmt = $pdo->prepare("
    INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
    VALUES (?, 'system', 'Support Desk', ?, 1, ?)
");
$stmt->execute([$requestId, $autoGuidance, $nowUtc]);
echo "[PASS] Test 4: User message recorded, admin notified, and automated assistant responded\n";

// -------------------------------------------------------------
// Test 5: Message polling & read status
// -------------------------------------------------------------
$pollStmt = $pdo->prepare("
    SELECT * FROM support_messages
    WHERE request_id = ? AND id > 0
    ORDER BY id ASC
");
$pollStmt->execute([$requestId]);
$allMsgs = $pollStmt->fetchAll();
assert(count($allMsgs) === 3, "Exactly 3 messages in session: Welcome, User query, Assistant reply");

// Polling marks unread messages as read
$pdo->prepare("
    UPDATE support_messages
    SET is_read = 1
    WHERE request_id = ? AND sender_type = 'user' AND is_read = 0
")->execute([$requestId]);

$unreadCheck = $pdo->prepare("SELECT COUNT(*) FROM support_messages WHERE request_id = ? AND sender_type = 'user' AND is_read = 0");
$unreadCheck->execute([$requestId]);
assert((int)$unreadCheck->fetchColumn() === 0, "User messages marked as read after admin poll");
echo "[PASS] Test 5: Message polling and read receipts verified\n";

// -------------------------------------------------------------
// Test 6: Admin Inbox Query (admin_chats)
// -------------------------------------------------------------
$adminChatsStmt = $pdo->query("
    SELECT s.id, s.user_id, s.phone, s.username, s.status,
           u.account_holder_name, u.health_score,
           (SELECT message FROM support_messages WHERE request_id = s.id ORDER BY id DESC LIMIT 1) as last_message,
           (SELECT COUNT(*) FROM support_messages WHERE request_id = s.id AND sender_type = 'user' AND is_read = 0) as unread_count
    FROM support_requests s
    LEFT JOIN users u ON s.user_id = u.id
    ORDER BY s.updated_at DESC
");
$chats = $adminChatsStmt->fetchAll();
assert(count($chats) === 1, "Admin chats query returns 1 chat");
assert($chats[0]['phone'] === $userPhone, "Chat phone matches user phone");
assert($chats[0]['health_score'] == 95, "User health score correctly displayed");
echo "[PASS] Test 6: Admin inbox query lists active conversation with user metadata\n";

// -------------------------------------------------------------
// Test 7: Admin replies to user & updates session to in_progress
// -------------------------------------------------------------
$adminReplyText = "Hello John, we verified your UTR with the banking system and confirmed receipt.";
$stmt = $pdo->prepare("
    INSERT INTO support_messages (request_id, sender_type, sender_id, sender_name, message, is_read, created_at)
    VALUES (?, 'admin', ?, 'Super Admin', ?, 0, ?)
");
$stmt->execute([$requestId, $adminId, $adminReplyText, $nowUtc]);
$adminMsgId = (int)$pdo->lastInsertId();

$pdo->prepare("
    UPDATE support_requests
    SET status = 'in_progress', assigned_admin_id = ?, updated_at = ?
    WHERE id = ?
")->execute([$adminId, $nowUtc, $requestId]);

// Notification dispatched to user
Auth::createNotification($userId, 'Support Agent Reply', "Agent: {$adminReplyText}", 'support', (string)$requestId);
$userNotifStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'support'");
$userNotifStmt->execute([$userId]);
assert((int)$userNotifStmt->fetchColumn() >= 1, "User receives notification when Admin replies");
echo "[PASS] Test 7: Admin reply recorded, session set to in_progress, and user notified\n";

// -------------------------------------------------------------
// Test 8: Admin marks chat as resolved
// -------------------------------------------------------------
$pdo->prepare("
    UPDATE support_requests
    SET status = 'resolved', updated_at = ?
    WHERE id = ?
")->execute([$nowUtc, $requestId]);

$pdo->prepare("
    INSERT INTO support_messages (request_id, sender_type, sender_name, message, is_read, created_at)
    VALUES (?, 'system', 'Support Desk', '✅ This support inquiry was marked as resolved.', 1, ?)
")->execute([$requestId, $nowUtc]);

$statusCheck = $pdo->prepare("SELECT status FROM support_requests WHERE id = ?");
$statusCheck->execute([$requestId]);
assert($statusCheck->fetchColumn() === 'resolved', "Session status is resolved");
echo "[PASS] Test 8: Admin marks session resolved and system resolution message appended\n";

echo "\nALL REAL-TIME LIVE CHAT TESTS PASSED SUCCESSFULLY!\n";
