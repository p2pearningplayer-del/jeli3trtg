<?php
/**
 * Automated Database Schema Installer / Migration Runner
 * Automatically provisions tables, missing columns, and initial seeds for production and testing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class SchemaInstaller {
    public static function ensureSchema(): void {
        $pdo = Database::getConnection();
        $driver = Database::getDriver();

        if ($driver === 'sqlite') {
            self::initSqlite($pdo);
            self::migrateColumns($pdo, 'sqlite');
            self::seedPlatformSettings($pdo);
            self::seedDemoAccounts($pdo);
            return;
        }

        // MySQL check
        $tableExists = false;
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
            $tableExists = ($stmt->rowCount() > 0);
        } catch (Exception $e) {
            $tableExists = false;
        }

        if (!$tableExists) {
            $sqlFile = ROOT_PATH . '/schema.sql';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $pdo->exec($sql);
            }
        }

        self::migrateColumns($pdo, 'mysql');
        self::seedPlatformSettings($pdo);
        self::seedDemoAccounts($pdo);
    }

    public static function initSqlite(PDO $pdo): void {
        $schema = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone TEXT UNIQUE NOT NULL,
            email TEXT,
            password_hash TEXT NOT NULL,
            tpin_hash TEXT,
            account_status TEXT NOT NULL DEFAULT 'active',
            role TEXT NOT NULL DEFAULT 'user',
            admin_role TEXT DEFAULT NULL,
            is_demo INTEGER NOT NULL DEFAULT 0,
            is_flagged INTEGER NOT NULL DEFAULT 0,
            flag_reason TEXT,
            referral_code TEXT UNIQUE NOT NULL,
            referred_by_id INTEGER,
            upi_id TEXT,
            account_holder_name TEXT,
            health_score INTEGER NOT NULL DEFAULT 100,
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
            additional_evidence_requested INTEGER NOT NULL DEFAULT 0,
            refund_status TEXT NOT NULL DEFAULT 'none',
            refund_reference TEXT,
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

        CREATE TABLE IF NOT EXISTS support_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            phone TEXT NOT NULL,
            username TEXT,
            message TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            assigned_admin_id INTEGER,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS support_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL,
            sender_type TEXT NOT NULL,
            sender_id INTEGER,
            sender_name TEXT,
            message TEXT NOT NULL,
            attachment_path TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES support_requests(id) ON DELETE CASCADE
        );
        ";

        $pdo->exec($schema);
    }

    /**
     * Incrementally apply any missing columns on existing tables
     */
    public static function migrateColumns(PDO $pdo, string $driver): void {
        $userCols = [
            'email' => 'TEXT',
            'admin_role' => 'TEXT',
            'is_demo' => 'INTEGER NOT NULL DEFAULT 0',
            'is_flagged' => 'INTEGER NOT NULL DEFAULT 0',
            'flag_reason' => 'TEXT',
            'health_score' => 'INTEGER NOT NULL DEFAULT 100'
        ];

        $dispCols = [
            'additional_evidence_requested' => 'INTEGER NOT NULL DEFAULT 0',
            'refund_status' => "TEXT NOT NULL DEFAULT 'none'",
            'refund_reference' => 'TEXT'
        ];

        if ($driver === 'mysql') {
            $userCols = [
                'email' => 'VARCHAR(100) DEFAULT NULL',
                'admin_role' => "ENUM('super_admin', 'order_manager', 'dispute_manager') DEFAULT NULL",
                'is_demo' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_flagged' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'flag_reason' => 'VARCHAR(255) DEFAULT NULL',
                'health_score' => 'INT NOT NULL DEFAULT 100'
            ];
            $dispCols = [
                'additional_evidence_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'refund_status' => "ENUM('none', 'pending', 'issued', 'not_applicable') NOT NULL DEFAULT 'none'",
                'refund_reference' => 'VARCHAR(100) DEFAULT NULL'
            ];
        }

        foreach ($userCols as $col => $type) {
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$type}");
                } else {
                    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                }
            } catch (Exception $e) {
                // Column likely already exists
            }
        }

        foreach ($dispCols as $col => $type) {
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE disputes ADD COLUMN {$col} {$type}");
                } else {
                    $pdo->exec("ALTER TABLE disputes ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                }
            } catch (Exception $e) {
                // Column likely already exists
            }
        }

        // Support requests table migration (Part 8, Item 3)
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS support_requests (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        phone TEXT NOT NULL,
                        username TEXT,
                        message TEXT,
                        status TEXT NOT NULL DEFAULT 'pending',
                        assigned_admin_id INTEGER,
                        notes TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE IF NOT EXISTS support_messages (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        request_id INTEGER NOT NULL,
                        sender_type TEXT NOT NULL,
                        sender_id INTEGER,
                        sender_name TEXT,
                        message TEXT NOT NULL,
                        attachment_path TEXT,
                        is_read INTEGER NOT NULL DEFAULT 0,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (request_id) REFERENCES support_requests(id) ON DELETE CASCADE
                    );
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `support_requests` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT NULL,
                        `phone` VARCHAR(20) NOT NULL,
                        `username` VARCHAR(100) NULL,
                        `message` TEXT NULL,
                        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                        `assigned_admin_id` INT NULL,
                        `notes` TEXT NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX `idx_support_user` (`user_id`),
                        INDEX `idx_support_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `support_messages` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `request_id` INT NOT NULL,
                        `sender_type` VARCHAR(20) NOT NULL,
                        `sender_id` INT NULL,
                        `sender_name` VARCHAR(100) NULL,
                        `message` TEXT NOT NULL,
                        `attachment_path` VARCHAR(255) NULL,
                        `is_read` TINYINT NOT NULL DEFAULT 0,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_msg_req` (`request_id`),
                        INDEX `idx_msg_read` (`is_read`),
                        FOREIGN KEY (`request_id`) REFERENCES `support_requests`(`id`) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            }

            // Ensure columns in support_requests exist even if created in earlier versions
            $supportCols = [
                'assigned_admin_id' => ($driver === 'sqlite') ? 'INTEGER' : 'INT NULL',
                'notes' => ($driver === 'sqlite') ? 'TEXT' : 'TEXT NULL',
                'updated_at' => ($driver === 'sqlite') ? 'TEXT DEFAULT CURRENT_TIMESTAMP' : 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            ];
            foreach ($supportCols as $col => $type) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE support_requests ADD COLUMN {$col} {$type}");
                    } else {
                        $pdo->exec("ALTER TABLE support_requests ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                    }
                } catch (Exception $e) {
                    // Column already exists
                }
            }
        } catch (Exception $e) {
            // Table creation handled
        }
    }

    public static function seedPlatformSettings(PDO $pdo): void {
        $settings = [
            ['site_name', 'JeliPay', 'Website and platform public brand name'],
            ['site_tagline', 'Unified Peer-to-Peer Token Trading Platform', 'Platform sub-header description'],
            ['token_rate_inr', '1', 'Base value of 1 token in INR'],
            ['purchase_reward_percent', '9.0', 'Buyer purchase reward percentage (default 9%)'],
            ['referral_l1_percent', '1.0', 'Level 1 direct referral reward percentage'],
            ['referral_l2_percent', '0.5', 'Level 2 indirect referral reward percentage'],
            ['min_sell_amount', '100', 'Minimum allowed token amount for sell listing'],
            ['max_sell_amount', '100000', 'Maximum allowed token amount for sell listing'],
            ['min_buy_amount', '100', 'Minimum allowed token amount for custom buy allocation'],
            ['max_buy_amount', '100000', 'Maximum allowed token amount for custom buy allocation'],
            ['sell_denomination_step', '100', 'Mandatory step modulo for sell amount (Amount MOD 100 == 0)'],
            ['weekly_selling_target', '400000', 'Weekly selling target in INR for progress calculation'],
            ['buyer_payment_window_seconds', '600', '10-minute payment countdown window for buyer'],
            ['seller_verify_window_seconds', '900', '15-minute seller verification window before response timeout'],
            ['max_child_splits', '8', 'Maximum micro-order portions a parent order can split into'],
            ['announcement_banner_active', '1', 'Display announcement notice banner on homepage (1=Active, 0=Inactive)'],
            ['announcement_banner_text', '✨ Active Promotion: Earn 9% instant credit rewards on all peer token purchases!', 'Text banner displayed to traders'],
            ['announcement_banner_image', '', 'Optional banner graphical asset URL'],
            ['maintenance_mode', '0', 'Put marketplace into maintenance mode (1=Active, 0=Disabled)'],
            ['maintenance_message', 'JeliPay is temporarily offline for scheduled system optimization. We will resume trading shortly.', 'Maintenance notice displayed to non-admins'],
            ['terms_of_service', 'All token trades are peer-to-peer and settled directly via UPI banking. Users must verify payment proofs accurately.', 'Platform terms of service statement'],
            ['privacy_policy', 'Your privacy is paramount. Personal contact details and phone numbers are masked across public trade order pools.', 'Platform privacy policy statement'],
            ['support_contact_info', 'support@jelipay.in', 'Official administrative support contact details'],
            ['approved_upi_config', 'jelipay@upi', 'Approved default UPI gateway reference'],
            ['reward_eligible_types', 'ALL', 'Eligible transaction types for reward calculation'],
            ['telegram_support_url', 'https://t.me/jelipay_support', 'Official Telegram Support Channel URL']
        ];

        $stmt = $pdo->prepare("SELECT setting_key FROM platform_settings WHERE setting_key = ?");
        $ins = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");

        foreach ($settings as $s) {
            $stmt->execute([$s[0]]);
            if (!$stmt->fetch()) {
                $ins->execute($s);
            }
        }
    }

    public static function seedDemoAccounts(PDO $pdo): void {
        // 1. Super Admin Account (ADMIN001 / 9999999999)
        self::ensureUser(
            $pdo,
            '9999999999',
            'admin@jelipay.in',
            '$2y$10$nsaDWNCaNU9TRJp/FJ3NpuncVzjhS7zyQADri9qt0iWnUN1JTAW8e', // Admin@123456
            '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', // 1234
            'ADMIN001',
            'admin',
            'super_admin',
            0,
            1000000.00,
            'jelipayadmin@upi',
            'JeliPay Admin'
        );

        // 2. Demo Admin Account (DEMOADMIN001 / 9888800000 / demoadmin@jelipay.in / DemoAdmin@123)
        self::ensureUser(
            $pdo,
            '9888800000',
            'demoadmin@jelipay.in',
            '$2y$10$HU771qOFL016eO.hpPE5x.IVliUJ1Qca2K8TyzAzFbN7XPo/BRuhO', // DemoAdmin@123
            '$2y$10$aq.G53.wTfw/6bxgafs2O.TcU2ZPsuBlmRUqf9rXCI.TDhQO446ou', // 1234
            'DEMOADMIN001',
            'admin',
            'super_admin',
            0,
            500000.00,
            'demoadmin@upi',
            'Demo Administrator'
        );

        // 3. Demo User Account (DEMOUSER001 / 9888800001 / demouser@jelipay.in / DemoUser@123)
        self::ensureUser(
            $pdo,
            '9888800001',
            'demouser@jelipay.in',
            '$2y$10$1djyY.sn4UJ32HNnRE/3cOqYgCRho99T.Zk1IPzjTbexhKkk0NY2.', // DemoUser@123
            '$2y$10$aq.G53.wTfw/6bxgafs2O.TcU2ZPsuBlmRUqf9rXCI.TDhQO446ou', // 1234
            'DEMOUSER001',
            'user',
            null,
            1, // is_demo
            10000.00,
            'demouser@okhdfcbank',
            'Demo Trader'
        );

        // 4. Standard User 1 (9876543210 / USER001)
        self::ensureUser(
            $pdo,
            '9876543210',
            'rohan@jelipay.in',
            '$2y$10$VptZJT57ky0utSer8oOiMerRqnDko3zNNqzM50MHgiWoPJoQNRyjW', // User@123456
            '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', // 1234
            'USER001',
            'user',
            null,
            0,
            50000.00,
            'user1@okhdfcbank',
            'Rohan Sharma'
        );

        // 5. Standard User 2 (9811122233 / USER002)
        self::ensureUser(
            $pdo,
            '9811122233',
            'priya@jelipay.in',
            '$2y$10$VptZJT57ky0utSer8oOiMerRqnDko3zNNqzM50MHgiWoPJoQNRyjW', // User@123456
            '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', // 1234
            'USER002',
            'user',
            null,
            0,
            20000.00,
            'user2@paytm',
            'Priya Patel'
        );
    }

    private static function ensureUser(
        PDO $pdo,
        string $phone,
        ?string $email,
        string $passwordHash,
        ?string $tpinHash,
        string $referralCode,
        string $role,
        ?string $adminRole,
        int $isDemo,
        float $balance,
        ?string $upiId,
        ?string $name
    ): void {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO users (
                    phone, email, password_hash, tpin_hash, account_status, role, admin_role,
                    is_demo, referral_code, upi_id, account_holder_name
                ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $phone, $email, $passwordHash, $tpinHash, $role, $adminRole,
                $isDemo, $referralCode, $upiId, $name
            ]);
            $userId = (int)$pdo->lastInsertId();

            $wIns = $pdo->prepare("
                INSERT INTO wallets (user_id, available_balance, reserved_balance, pending_credit_balance, version)
                VALUES (?, ?, 0.00, 0.00, 1)
            ");
            $wIns->execute([$userId, $balance]);

            $lIns = $pdo->prepare("
                INSERT INTO wallet_ledger (
                    user_id, transaction_type, amount, previous_balance, new_balance, balance_type,
                    reference_id, actor_id, actor_role, notes
                ) VALUES (?, 'INITIAL_DEPOSIT', ?, 0.00, ?, 'available', ?, ?, 'system', ?)
            ");
            $lIns->execute([
                $userId, $balance, $balance, "SYS_SEED_{$userId}", $userId, "Initial balance for {$referralCode}"
            ]);
        }
    }
}
