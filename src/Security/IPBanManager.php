<?php
/**
 * IP Ban & Security Violation Manager
 * Detects and mitigates IDOR exploits, automated bot abuse, and unauthorized API tampering.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/schema_installer.php';

class IPBanManager {
    /**
     * Resolves the authoritative client IP address, safely parsing trusted proxy headers.
     */
    public static function getClientIp(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $rawList = explode(',', (string)$_SERVER[$header]);
                $candidate = trim($rawList[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Checks if an IP is currently banned.
     */
    public static function isBanned(?string $ip = null): bool {
        $clientIp = $ip ?: self::getClientIp();
        if (empty($clientIp)) {
            return false;
        }

        try {
            $pdo = Database::getConnection();
            $nowUtc = gmdate('Y-m-d H:i:s');

            $stmt = $pdo->prepare("SELECT id, banned_until, reason FROM ip_bans WHERE ip_address = ? AND banned_until > ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$clientIp, $nowUtc]);
            $ban = $stmt->fetch();

            return (bool)$ban;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Enforces ban policy. Halts execution with JSON 403 response if IP is banned.
     */
    public static function enforceCleanIp(?string $ip = null): void {
        $clientIp = $ip ?: self::getClientIp();
        if (self::isBanned($clientIp)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Access Denied: Your IP address is temporarily banned due to security policy violations.',
                'code' => 'IP_BANNED'
            ]);
            exit;
        }
    }

    /**
     * Records a security violation (e.g. IDOR, brute-force settlement attempt) and bans IP.
     */
    public static function recordViolation(string $ip, string $reason, string $type = 'IDOR_VIOLATION', int $banHours = 24): void {
        $cleanIp = trim($ip);
        if (empty($cleanIp)) {
            return;
        }

        try {
            $pdo = Database::getConnection();
            $nowUtc = gmdate('Y-m-d H:i:s');
            $bannedUntil = gmdate('Y-m-d H:i:s', time() + ($banHours * 3600));

            $stmt = $pdo->prepare("SELECT id, violation_count FROM ip_bans WHERE ip_address = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$cleanIp]);
            $existing = $stmt->fetch();

            if ($existing) {
                $newCount = ((int)$existing['violation_count']) + 1;
                $upd = $pdo->prepare("
                    UPDATE ip_bans
                    SET violation_count = ?,
                        violation_type = ?,
                        reason = ?,
                        banned_until = ?,
                        created_at = ?
                    WHERE id = ?
                ");
                $upd->execute([$newCount, $type, $reason, $bannedUntil, $nowUtc, $existing['id']]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO ip_bans (ip_address, reason, violation_type, violation_count, banned_until, created_at)
                    VALUES (?, ?, ?, 1, ?, ?)
                ");
                $ins->execute([$cleanIp, $reason, $type, $bannedUntil, $nowUtc]);
            }
        } catch (Exception $e) {
            error_log('Failed to record IP violation: ' . $e->getMessage());
        }
    }

    /**
     * Directly ban an IP address.
     */
    public static function banIp(string $ip, string $reason, string $type = 'MANUAL_BAN', int $banHours = 24): void {
        self::recordViolation($ip, $reason, $type, $banHours);
    }

    /**
     * Unban an IP address.
     */
    public static function unbanIp(string $ip): bool {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("DELETE FROM ip_bans WHERE ip_address = ?");
            $stmt->execute([trim($ip)]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get list of banned IP records for Admin Console inspection.
     */
    public static function getBannedIps(int $limit = 50): array {
        try {
            $pdo = Database::getConnection();
            $nowUtc = gmdate('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT * FROM ip_bans WHERE banned_until > ? ORDER BY id DESC LIMIT ?");
            $stmt->execute([$nowUtc, $limit]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
}

// Namespace compatibility alias
if (!class_exists('Security\\IPBanManager', false)) {
    class_alias('IPBanManager', 'Security\\IPBanManager');
}
