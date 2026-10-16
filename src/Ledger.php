<?php
/**
 * Immutable Wallet Ledger Service
 * Strict append-only financial tracking for every token debit and credit.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class Ledger {
    /**
     * Record an immutable transaction entry into the wallet ledger (FR-005)
     */
    public static function record(
        int $userId,
        string $transactionType,
        float $amount,
        float $previousBalance,
        float $newBalance,
        string $balanceType,
        ?string $referenceId,
        int $actorId,
        string $actorRole = 'user',
        ?string $notes = null
    ): int {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO wallet_ledger (
                user_id, transaction_type, amount, previous_balance, new_balance,
                balance_type, reference_id, actor_id, actor_role, status, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?)
        ");

        $stmt->execute([
            $userId,
            $transactionType,
            $amount,
            $previousBalance,
            $newBalance,
            $balanceType,
            $referenceId,
            $actorId,
            $actorRole,
            $notes,
            $nowUtc
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Get paginated ledger history for a specific user
     */
    public static function getUserHistory(int $userId, int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM wallet_ledger
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get recent platform-wide ledger transactions for Admin Console
     */
    public static function getAllLedger(int $limit = 100, int $offset = 0): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT l.*, u.phone, u.account_holder_name
            FROM wallet_ledger l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
