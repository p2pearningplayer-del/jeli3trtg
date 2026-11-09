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
     * IP banning is disabled across the platform to prevent blocking shared mobile carrier/CGNAT IPs.
     */
    public static function isBanned(?string $ip = null): bool {
        return false;
    }

    /**
     * Enforces ban policy (No-op: IP banning removed).
     */
    public static function enforceCleanIp(?string $ip = null): void {
        // IP banning has been removed
    }

    /**
     * Records a security violation (No-op: IP banning removed).
     */
    public static function recordViolation(string $ip, string $reason, string $type = 'IDOR_VIOLATION', int $banHours = 24): void {
        // IP banning has been removed; do not insert into ip_bans
    }

    /**
     * Directly ban an IP address (No-op: IP banning removed).
     */
    public static function banIp(string $ip, string $reason, string $type = 'MANUAL_BAN', int $banHours = 24): void {
        // IP banning has been removed
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
     * Clears all IP bans from the database.
     */
    public static function clearAllBans(): bool {
        try {
            $pdo = Database::getConnection();
            $pdo->exec("DELETE FROM ip_bans");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get list of banned IP records for Admin Console inspection.
     */
    public static function getBannedIps(int $limit = 50): array {
        return [];
    }
}

// Namespace compatibility alias
if (!class_exists('Security\\IPBanManager', false)) {
    class_alias('IPBanManager', 'Security\\IPBanManager');
}
