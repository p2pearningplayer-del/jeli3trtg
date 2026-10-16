<?php
/**
 * Automated Database Schema Installer / Migration Runner
 * Automatically provisions tables and initial seeds if the database is uninitialized.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class SchemaInstaller {
    public static function ensureSchema(): void {
        $pdo = Database::getConnection();
        $driver = Database::getDriver();

        if ($driver === 'sqlite') {
            self::initSqlite($pdo);
            return;
        }

        // Check if users table exists in MySQL
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
            if ($stmt->rowCount() > 0) {
                return; // Already initialized
            }
        } catch (Exception $e) {
            // Proceed with initialization
        }

        $sqlFile = ROOT_PATH . '/schema.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $pdo->exec($sql);
        }
    }

    public static function initSqlite(PDO $pdo): void {
        // Creates SQLite compatible schema for automated testing
        $schema = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            tpin_hash TEXT,
            account_status TEXT NOT NULL DEFAULT 'active',
            role TEXT NOT NULL DEFAULT 'user',
            referral_code TEXT UNIQUE NOT NULL,
            referred_by_id INTEGER,
            upi_id TEXT,
            account_holder_name TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (referred_by_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS wallets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER UNIQUE NOT NULL,
            available_balance NUMERIC NOT NULL DEFAULT 0.00,
            reserved_balance NUMERIC NOT NULL DEFAULT 0.00,
            pending_credit_balance NUMERIC NOT NULL DEFAULT 0.00,
            total_rewards_earned NUMERIC NOT NULL DEFAULT 0.00,
            total_commissions_earned NUMERIC NOT NULL DEFAULT 0.00,
            version INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS parent_sell_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seller_id INTEGER NOT NULL,
            original_amount NUMERIC NOT NULL,
            remaining_amount NUMERIC NOT NULL,
            reserved_amount NUMERIC NOT NULL DEFAULT 0.00,
            completed_amount NUMERIC NOT NULL DEFAULT 0.00,
            status TEXT NOT NULL DEFAULT 'active',
            snapshotted_upi_id TEXT NOT NULL,
            snapshotted_account_holder TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS child_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_order_id INTEGER NOT NULL,
            seller_id INTEGER NOT NULL,
            buyer_id INTEGER,
            amount NUMERIC NOT NULL,
            snapshotted_reward_rate NUMERIC NOT NULL DEFAULT 9.00,
            snapshotted_reward_amount NUMERIC NOT NULL,
            snapshotted_upi_id TEXT NOT NULL,
            snapshotted_account_holder TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Available',
            payment_deadline TEXT,
            verification_deadline TEXT,
            picked_at TEXT,
            proof_submitted_at TEXT,
            completed_at TEXT,
            idempotency_key TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_order_id) REFERENCES parent_sell_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS payment_proofs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            child_order_id INTEGER UNIQUE NOT NULL,
            buyer_id INTEGER NOT NULL,
            utr_number TEXT UNIQUE NOT NULL,
            screenshot_path TEXT NOT NULL,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS disputes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            child_order_id INTEGER UNIQUE NOT NULL,
            raised_by_user_id INTEGER NOT NULL,
            raised_by_role TEXT NOT NULL,
            reason TEXT NOT NULL,
            evidence_screenshot_path TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            resolution_notes TEXT,
            resolved_by_admin_id INTEGER,
            resolved_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (raised_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by_admin_id) REFERENCES users(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS wallet_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            transaction_type TEXT NOT NULL,
            amount NUMERIC NOT NULL,
            previous_balance NUMERIC NOT NULL,
            new_balance NUMERIC NOT NULL,
            balance_type TEXT NOT NULL,
            reference_id TEXT,
            actor_id INTEGER NOT NULL,
            actor_role TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'completed',
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            action_type TEXT NOT NULL,
            target_entity TEXT NOT NULL,
            target_id TEXT NOT NULL,
            before_state TEXT,
            after_state TEXT,
            reason TEXT NOT NULL,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            description TEXT,
            updated_by INTEGER,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            action TEXT NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1,
            window_start INTEGER NOT NULL,
            UNIQUE(identifier, action)
        );

        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            type TEXT NOT NULL DEFAULT 'info',
            reference_id TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        ";

        $pdo->exec($schema);

        // Seed default platform settings if empty
        $stmt = $pdo->query("SELECT COUNT(*) FROM platform_settings");
        if ((int)$stmt->fetchColumn() === 0) {
            $settings = [
                ['token_rate_inr', '1', 'Base value of 1 token in INR'],
                ['purchase_reward_percent', '9.0', 'Buyer purchase reward percentage (default 9%)'],
                ['referral_l1_percent', '1.0', 'Level 1 direct referral reward percentage'],
                ['referral_l2_percent', '0.5', 'Level 2 indirect referral reward percentage'],
                ['min_sell_amount', '100', 'Minimum allowed token amount for sell listing'],
                ['sell_denomination_step', '100', 'Mandatory step modulo for sell amount (Amount MOD 100 == 0)'],
                ['weekly_selling_target', '400000', 'Weekly selling target in INR for progress calculation'],
                ['buyer_payment_window_seconds', '600', '10-minute payment countdown window for buyer'],
                ['seller_verify_window_seconds', '900', '15-minute seller verification window before response timeout'],
                ['max_child_splits', '8', 'Maximum micro-order portions a parent order can split into']
            ];
            $ins = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
            foreach ($settings as $s) {
                $ins->execute($s);
            }
        }
    }
}
