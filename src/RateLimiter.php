<?php
/**
 * Rate Limiter Service
 * Protects critical endpoints (Login, TPIN validation, Order Picking) from brute force.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class RateLimiter {
    /**
     * Check and record an attempt.
     * Returns true if allowed, false if limit exceeded.
     */
    public static function check(string $identifier, string $action, int $maxHits = 5, int $windowSeconds = 300): bool {
        $pdo = Database::getConnection();
        $now = time();

        $stmt = $pdo->prepare("SELECT id, hits, window_start FROM rate_limits WHERE identifier = ? AND action = ?");
        $stmt->execute([$identifier, $action]);
        $row = $stmt->fetch();

        if (!$row) {
            $insert = $pdo->prepare("INSERT INTO rate_limits (identifier, action, hits, window_start) VALUES (?, ?, 1, ?)");
            $insert->execute([$identifier, $action, $now]);
            return true;
        }

        if (($now - (int)$row['window_start']) > $windowSeconds) {
            // Window expired, reset counter
            $reset = $pdo->prepare("UPDATE rate_limits SET hits = 1, window_start = ? WHERE id = ?");
            $reset->execute([$now, $row['id']]);
            return true;
        }

        if ((int)$row['hits'] >= $maxHits) {
            return false;
        }

        $incr = $pdo->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?");
        $incr->execute([$row['id']]);
        return true;
    }

    /**
     * Reset rate limit on successful action
     */
    public static function reset(string $identifier, string $action): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE identifier = ? AND action = ?");
        $stmt->execute([$identifier, $action]);
    }
}
