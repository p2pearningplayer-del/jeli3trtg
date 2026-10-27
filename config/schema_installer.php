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
            totp_secret TEXT DEFAULT NULL,
            totp_enabled INTEGER NOT NULL DEFAULT 0,
            totp_temp_secret TEXT DEFAULT NULL,
            totp_temp_created_at TEXT DEFAULT NULL,
            totp_last_timestamp INTEGER NOT NULL DEFAULT 0,
            totp_recovery_codes TEXT DEFAULT NULL,
            password_changed_at TEXT DEFAULT NULL,
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

        CREATE TABLE IF NOT EXISTS payment_fingerprints (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            child_order_id INTEGER NOT NULL,
            buyer_id INTEGER NOT NULL,
            utr_raw TEXT NOT NULL,
            utr_normalized TEXT NOT NULL,
            utr_hash TEXT NOT NULL,
            image_sha256 TEXT NOT NULL,
            image_phash TEXT,
            file_path TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_fp_utr_hash ON payment_fingerprints(utr_hash);
        CREATE INDEX IF NOT EXISTS idx_fp_image_sha256 ON payment_fingerprints(image_sha256);
        CREATE INDEX IF NOT EXISTS idx_fp_buyer_id ON payment_fingerprints(buyer_id);

        CREATE TABLE IF NOT EXISTS fraud_assessments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            child_order_id INTEGER NOT NULL,
            buyer_id INTEGER NOT NULL,
            decision TEXT NOT NULL DEFAULT 'PENDING_REVIEW',
            risk_score INTEGER NOT NULL DEFAULT 0,
            risk_level TEXT NOT NULL DEFAULT 'low',
            reason_codes TEXT NOT NULL,
            ocr_data TEXT,
            image_analysis TEXT,
            claimed_amount NUMERIC NOT NULL,
            claimed_utr TEXT NOT NULL,
            reviewed_by_admin_id INTEGER,
            review_decision TEXT,
            review_notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_fa_order ON fraud_assessments(child_order_id);
        CREATE INDEX IF NOT EXISTS idx_fa_buyer ON fraud_assessments(buyer_id);
        CREATE INDEX IF NOT EXISTS idx_fa_decision ON fraud_assessments(decision);

        CREATE TABLE IF NOT EXISTS user_risk_profiles (
            user_id INTEGER PRIMARY KEY,
            total_submissions INTEGER NOT NULL DEFAULT 0,
            failed_submissions INTEGER NOT NULL DEFAULT 0,
            duplicate_attempts INTEGER NOT NULL DEFAULT 0,
            fraud_score_avg NUMERIC NOT NULL DEFAULT 0.0,
            last_risk_level TEXT NOT NULL DEFAULT 'low',
            is_auto_flagged INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            phone TEXT,
            email TEXT,
            password_hash TEXT NOT NULL,
            root_user_id INTEGER NOT NULL,
            commission_rate NUMERIC NOT NULL DEFAULT 0.00,
            eligible_levels TEXT NOT NULL DEFAULT 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z',
            totp_secret TEXT NOT NULL,
            totp_last_timestamp INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            must_change_password INTEGER NOT NULL DEFAULT 1,
            created_by_admin_id INTEGER,
            last_login_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (root_user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_agents_code ON agents(agent_code);
        CREATE INDEX IF NOT EXISTS idx_agents_root ON agents(root_user_id);
        CREATE INDEX IF NOT EXISTS idx_agents_status ON agents(status);

        CREATE TABLE IF NOT EXISTS agent_commissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            agent_id INTEGER NOT NULL,
            child_order_id INTEGER NOT NULL,
            buyer_id INTEGER NOT NULL,
            purchase_amount NUMERIC NOT NULL,
            commission_rate NUMERIC NOT NULL,
            commission_amount NUMERIC NOT NULL,
            referral_level TEXT NOT NULL,
            referral_depth INTEGER NOT NULL,
            statement_id INTEGER,
            status TEXT NOT NULL DEFAULT 'earned',
            order_completed_at TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(agent_id, child_order_id),
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
            FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_ac_agent ON agent_commissions(agent_id);
        CREATE INDEX IF NOT EXISTS idx_ac_order ON agent_commissions(child_order_id);
        CREATE INDEX IF NOT EXISTS idx_ac_buyer ON agent_commissions(buyer_id);
        CREATE INDEX IF NOT EXISTS idx_ac_statement ON agent_commissions(statement_id);
        CREATE INDEX IF NOT EXISTS idx_ac_completed ON agent_commissions(order_completed_at);

        CREATE TABLE IF NOT EXISTS agent_weekly_statements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            statement_code TEXT UNIQUE NOT NULL,
            agent_id INTEGER NOT NULL,
            week_number INTEGER NOT NULL,
            week_year INTEGER NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            total_volume NUMERIC NOT NULL DEFAULT 0.00,
            total_orders INTEGER NOT NULL DEFAULT 0,
            gross_commission NUMERIC NOT NULL DEFAULT 0.00,
            adjustment_amount NUMERIC NOT NULL DEFAULT 0.00,
            adjustment_reason TEXT,
            net_commission NUMERIC NOT NULL DEFAULT 0.00,
            payout_status TEXT NOT NULL DEFAULT 'pending_review',
            payout_reference TEXT,
            paid_at TEXT,
            paid_by_admin_id INTEGER,
            approved_at TEXT,
            approved_by_admin_id INTEGER,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(agent_id, week_year, week_number),
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_aws_agent ON agent_weekly_statements(agent_id);
        CREATE INDEX IF NOT EXISTS idx_aws_status ON agent_weekly_statements(payout_status);

        CREATE TABLE IF NOT EXISTS agent_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_type TEXT NOT NULL,
            actor_id INTEGER NOT NULL,
            agent_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_aal_agent ON agent_audit_logs(agent_id);
        CREATE INDEX IF NOT EXISTS idx_aal_action ON agent_audit_logs(action);

        CREATE TABLE IF NOT EXISTS email_verifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            email TEXT NOT NULL,
            otp_code TEXT NOT NULL,
            verification_token TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            verified_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_ev_email ON email_verifications(email);
        CREATE INDEX IF NOT EXISTS idx_ev_token ON email_verifications(verification_token);
        CREATE INDEX IF NOT EXISTS idx_ev_status ON email_verifications(status);

        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            email TEXT NOT NULL,
            otp_code TEXT NOT NULL,
            reset_token TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_pr_email ON password_resets(email);
        CREATE INDEX IF NOT EXISTS idx_pr_token ON password_resets(reset_token);
        CREATE INDEX IF NOT EXISTS idx_pr_status ON password_resets(status);

        CREATE TABLE IF NOT EXISTS health_score_audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            old_score INTEGER NOT NULL,
            new_score INTEGER NOT NULL,
            delta INTEGER NOT NULL,
            reason TEXT NOT NULL,
            event_type TEXT NOT NULL DEFAULT 'SYSTEM_EVENT',
            admin_id INTEGER DEFAULT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_hsal_user ON health_score_audit_logs(user_id);
        CREATE INDEX IF NOT EXISTS idx_hsal_created ON health_score_audit_logs(created_at);
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
            'health_score' => 'INTEGER NOT NULL DEFAULT 100',
            'totp_secret' => 'TEXT DEFAULT NULL',
            'totp_enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'totp_temp_secret' => 'TEXT DEFAULT NULL',
            'totp_temp_created_at' => 'TEXT DEFAULT NULL',
            'totp_last_timestamp' => 'INTEGER NOT NULL DEFAULT 0',
            'totp_recovery_codes' => 'TEXT DEFAULT NULL',
            'password_changed_at' => 'TEXT DEFAULT NULL',
            'email_verified_at' => 'TEXT DEFAULT NULL',
            'email_verification_otp' => 'TEXT DEFAULT NULL',
            'email_verification_token' => 'TEXT DEFAULT NULL',
            'email_verification_expires_at' => 'TEXT DEFAULT NULL',
            'password_reset_otp' => 'TEXT DEFAULT NULL',
            'password_reset_token' => 'TEXT DEFAULT NULL',
            'password_reset_expires_at' => 'TEXT DEFAULT NULL',
            'previous_health_score' => 'INTEGER NOT NULL DEFAULT 100',
            'health_score_updated_at' => 'TEXT DEFAULT NULL',
            'sell_restricted' => 'INTEGER NOT NULL DEFAULT 0',
            'sell_restriction_reason' => 'TEXT DEFAULT NULL',
            'sell_order_limit' => 'REAL DEFAULT NULL',
            'sell_cooldown_seconds' => 'INTEGER NOT NULL DEFAULT 0',
            'last_sell_order_at' => 'TEXT DEFAULT NULL',
            'risk_review_status' => "TEXT NOT NULL DEFAULT 'none'",
            'risk_notes' => 'TEXT DEFAULT NULL'
        ];

        $dispCols = [
            'additional_evidence_requested' => 'INTEGER NOT NULL DEFAULT 0',
            'refund_status' => "TEXT NOT NULL DEFAULT 'none'",
            'refund_reference' => 'TEXT'
        ];

        if ($driver === 'mysql') {
            $userCols = [
                'email' => 'VARCHAR(100) DEFAULT NULL',
                'admin_role' => 'VARCHAR(50) DEFAULT NULL',
                'is_demo' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_flagged' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'flag_reason' => 'VARCHAR(255) DEFAULT NULL',
                'health_score' => 'INT NOT NULL DEFAULT 100',
                'totp_secret' => 'TEXT DEFAULT NULL',
                'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'totp_temp_secret' => 'TEXT DEFAULT NULL',
                'totp_temp_created_at' => 'DATETIME DEFAULT NULL',
                'totp_last_timestamp' => 'BIGINT NOT NULL DEFAULT 0',
                'totp_recovery_codes' => 'TEXT DEFAULT NULL',
                'password_changed_at' => 'DATETIME DEFAULT NULL',
                'email_verified_at' => 'DATETIME DEFAULT NULL',
                'email_verification_otp' => 'VARCHAR(10) DEFAULT NULL',
                'email_verification_token' => 'VARCHAR(128) DEFAULT NULL',
                'email_verification_expires_at' => 'DATETIME DEFAULT NULL',
                'password_reset_otp' => 'VARCHAR(10) DEFAULT NULL',
                'password_reset_token' => 'VARCHAR(128) DEFAULT NULL',
                'password_reset_expires_at' => 'DATETIME DEFAULT NULL',
                'previous_health_score' => 'INT NOT NULL DEFAULT 100',
                'health_score_updated_at' => 'DATETIME DEFAULT NULL',
                'sell_restricted' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'sell_restriction_reason' => 'VARCHAR(255) DEFAULT NULL',
                'sell_order_limit' => 'DECIMAL(12,2) DEFAULT NULL',
                'sell_cooldown_seconds' => 'INT NOT NULL DEFAULT 0',
                'last_sell_order_at' => 'DATETIME DEFAULT NULL',
                'risk_review_status' => "VARCHAR(30) NOT NULL DEFAULT 'none'",
                'risk_notes' => 'TEXT DEFAULT NULL'
            ];
            $dispCols = [
                'additional_evidence_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'refund_status' => "ENUM('none', 'pending', 'issued', 'not_applicable') NOT NULL DEFAULT 'none'",
                'refund_reference' => 'VARCHAR(100) DEFAULT NULL'
            ];

            try {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN `admin_role` VARCHAR(50) DEFAULT NULL");
            } catch (Exception $e) {
                // Column modify handled
            }

            try {
                $pdo->exec("ALTER TABLE disputes MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending'");
            } catch (Exception $e) {
                // Column modify handled
            }

            try {
                $pdo->exec("ALTER TABLE disputes MODIFY COLUMN `raised_by_role` VARCHAR(20) NOT NULL DEFAULT 'seller'");
            } catch (Exception $e) {
                // Column modify handled
            }
        }

        foreach ($userCols as $col => $type) {
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$type}");
                } else {
                    $chk = $pdo->query("SHOW COLUMNS FROM `users` LIKE '{$col}'")->fetch();
                    if (!$chk) {
                        try {
                            $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$col}` {$type}");
                        } catch (Exception $e) {
                            try {
                                $pdo->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                            } catch (Exception $e2) {}
                        }
                    }
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
                    $chk = $pdo->query("SHOW COLUMNS FROM `disputes` LIKE '{$col}'")->fetch();
                    if (!$chk) {
                        try {
                            $pdo->exec("ALTER TABLE `disputes` ADD COLUMN `{$col}` {$type}");
                        } catch (Exception $e) {
                            try {
                                $pdo->exec("ALTER TABLE `disputes` ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                            } catch (Exception $e2) {}
                        }
                    }
                }
            } catch (Exception $e) {
                // Column likely already exists
            }
        }

        // Ensure immutable payment snapshot and cancellation columns on orders
        $orderSnapCols = [
            'snapshotted_upi_id' => ($driver === 'mysql' ? 'VARCHAR(100) DEFAULT NULL' : 'TEXT DEFAULT NULL'),
            'snapshotted_account_holder' => ($driver === 'mysql' ? 'VARCHAR(100) DEFAULT NULL' : 'TEXT DEFAULT NULL'),
            'cancelled_at' => ($driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT DEFAULT NULL')
        ];
        foreach ($orderSnapCols as $col => $type) {
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE child_orders ADD COLUMN {$col} {$type}");
                } else {
                    try {
                        $pdo->exec("ALTER TABLE `child_orders` ADD COLUMN `{$col}` {$type}");
                    } catch (Exception $e) {
                        try {
                            $pdo->exec("ALTER TABLE `child_orders` ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                        } catch (Exception $e2) {}
                    }
                }
            } catch (Exception $e) {}
            try {
                if ($driver === 'sqlite') {
                    $pdo->exec("ALTER TABLE parent_sell_orders ADD COLUMN {$col} {$type}");
                } else {
                    try {
                        $pdo->exec("ALTER TABLE `parent_sell_orders` ADD COLUMN `{$col}` {$type}");
                    } catch (Exception $e) {
                        try {
                            $pdo->exec("ALTER TABLE `parent_sell_orders` ADD COLUMN IF NOT EXISTS `{$col}` {$type}");
                        } catch (Exception $e2) {}
                    }
                }
            } catch (Exception $e) {}
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

        // Fraud Detection & AI Inspection tables
        try {
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS payment_fingerprints (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        child_order_id INTEGER NOT NULL,
                        buyer_id INTEGER NOT NULL,
                        utr_raw TEXT NOT NULL,
                        utr_normalized TEXT NOT NULL,
                        utr_hash TEXT NOT NULL,
                        image_sha256 TEXT NOT NULL,
                        image_phash TEXT,
                        file_path TEXT NOT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
                        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_fp_utr_hash ON payment_fingerprints(utr_hash);
                    CREATE INDEX IF NOT EXISTS idx_fp_image_sha256 ON payment_fingerprints(image_sha256);
                    CREATE INDEX IF NOT EXISTS idx_fp_buyer_id ON payment_fingerprints(buyer_id);

                    CREATE TABLE IF NOT EXISTS fraud_assessments (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        child_order_id INTEGER NOT NULL,
                        buyer_id INTEGER NOT NULL,
                        decision TEXT NOT NULL DEFAULT 'PENDING_REVIEW',
                        risk_score INTEGER NOT NULL DEFAULT 0,
                        risk_level TEXT NOT NULL DEFAULT 'low',
                        reason_codes TEXT NOT NULL,
                        ocr_data TEXT,
                        image_analysis TEXT,
                        claimed_amount NUMERIC NOT NULL,
                        claimed_utr TEXT NOT NULL,
                        reviewed_by_admin_id INTEGER,
                        review_decision TEXT,
                        review_notes TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
                        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_fa_order ON fraud_assessments(child_order_id);
                    CREATE INDEX IF NOT EXISTS idx_fa_buyer ON fraud_assessments(buyer_id);
                    CREATE INDEX IF NOT EXISTS idx_fa_decision ON fraud_assessments(decision);

                    CREATE TABLE IF NOT EXISTS user_risk_profiles (
                        user_id INTEGER PRIMARY KEY,
                        total_submissions INTEGER NOT NULL DEFAULT 0,
                        failed_submissions INTEGER NOT NULL DEFAULT 0,
                        duplicate_attempts INTEGER NOT NULL DEFAULT 0,
                        fraud_score_avg NUMERIC NOT NULL DEFAULT 0.0,
                        last_risk_level TEXT NOT NULL DEFAULT 'low',
                        is_auto_flagged INTEGER NOT NULL DEFAULT 0,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                ");
            } else {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `payment_fingerprints` (
                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                          `child_order_id` INT NOT NULL,
                          `buyer_id` INT NOT NULL,
                          `utr_raw` VARCHAR(100) NOT NULL,
                          `utr_normalized` VARCHAR(100) NOT NULL,
                          `utr_hash` VARCHAR(64) NOT NULL,
                          `image_sha256` VARCHAR(64) NOT NULL,
                          `image_phash` VARCHAR(64) DEFAULT NULL,
                          `file_path` VARCHAR(255) NOT NULL,
                          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          INDEX `idx_fp_utr_hash` (`utr_hash`),
                          INDEX `idx_fp_image_sha256` (`image_sha256`),
                          INDEX `idx_fp_buyer_id` (`buyer_id`),
                          CONSTRAINT `fk_fp_child_order` FOREIGN KEY (`child_order_id`) REFERENCES `child_orders` (`id`) ON DELETE CASCADE,
                          CONSTRAINT `fk_fp_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}

                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `fraud_assessments` (
                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                          `child_order_id` INT NOT NULL,
                          `buyer_id` INT NOT NULL,
                          `decision` ENUM('VERIFIED', 'PENDING_REVIEW', 'REJECTED', 'FAILED') NOT NULL DEFAULT 'PENDING_REVIEW',
                          `risk_score` INT NOT NULL DEFAULT 0,
                          `risk_level` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'low',
                          `reason_codes` TEXT NOT NULL,
                          `ocr_data` JSON DEFAULT NULL,
                          `image_analysis` JSON DEFAULT NULL,
                          `claimed_amount` DECIMAL(12, 2) NOT NULL,
                          `claimed_utr` VARCHAR(100) NOT NULL,
                          `reviewed_by_admin_id` INT DEFAULT NULL,
                          `review_decision` VARCHAR(50) DEFAULT NULL,
                          `review_notes` TEXT DEFAULT NULL,
                          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          INDEX `idx_fa_order` (`child_order_id`),
                          INDEX `idx_fa_buyer` (`buyer_id`),
                          INDEX `idx_fa_decision` (`decision`),
                          CONSTRAINT `fk_fa_child_order` FOREIGN KEY (`child_order_id`) REFERENCES `child_orders` (`id`) ON DELETE CASCADE,
                          CONSTRAINT `fk_fa_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}

                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `user_risk_profiles` (
                          `user_id` INT PRIMARY KEY,
                          `total_submissions` INT NOT NULL DEFAULT 0,
                          `failed_submissions` INT NOT NULL DEFAULT 0,
                          `duplicate_attempts` INT NOT NULL DEFAULT 0,
                          `fraud_score_avg` DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
                          `last_risk_level` VARCHAR(20) NOT NULL DEFAULT 'low',
                          `is_auto_flagged` TINYINT(1) NOT NULL DEFAULT 0,
                          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                          CONSTRAINT `fk_urp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {}
            }

            // Agent Management System Tables (Additive Migration)
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS agents (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        agent_code TEXT UNIQUE NOT NULL,
                        name TEXT NOT NULL,
                        phone TEXT,
                        email TEXT,
                        password_hash TEXT NOT NULL,
                        root_user_id INTEGER NOT NULL,
                        commission_rate NUMERIC NOT NULL DEFAULT 0.00,
                        eligible_levels TEXT NOT NULL DEFAULT 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z',
                        totp_secret TEXT NOT NULL,
                        totp_last_timestamp INTEGER NOT NULL DEFAULT 0,
                        status TEXT NOT NULL DEFAULT 'active',
                        must_change_password INTEGER NOT NULL DEFAULT 1,
                        created_by_admin_id INTEGER,
                        last_login_at TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (root_user_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_agents_code ON agents(agent_code);
                    CREATE INDEX IF NOT EXISTS idx_agents_root ON agents(root_user_id);
                    CREATE INDEX IF NOT EXISTS idx_agents_status ON agents(status);

                    CREATE TABLE IF NOT EXISTS agent_commissions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        agent_id INTEGER NOT NULL,
                        child_order_id INTEGER NOT NULL,
                        buyer_id INTEGER NOT NULL,
                        purchase_amount NUMERIC NOT NULL,
                        commission_rate NUMERIC NOT NULL,
                        commission_amount NUMERIC NOT NULL,
                        referral_level TEXT NOT NULL,
                        referral_depth INTEGER NOT NULL,
                        statement_id INTEGER,
                        status TEXT NOT NULL DEFAULT 'earned',
                        order_completed_at TEXT NOT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(agent_id, child_order_id),
                        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
                        FOREIGN KEY (child_order_id) REFERENCES child_orders(id) ON DELETE CASCADE,
                        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_ac_agent ON agent_commissions(agent_id);
                    CREATE INDEX IF NOT EXISTS idx_ac_order ON agent_commissions(child_order_id);
                    CREATE INDEX IF NOT EXISTS idx_ac_buyer ON agent_commissions(buyer_id);
                    CREATE INDEX IF NOT EXISTS idx_ac_statement ON agent_commissions(statement_id);
                    CREATE INDEX IF NOT EXISTS idx_ac_completed ON agent_commissions(order_completed_at);

                    CREATE TABLE IF NOT EXISTS agent_weekly_statements (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        statement_code TEXT UNIQUE NOT NULL,
                        agent_id INTEGER NOT NULL,
                        week_number INTEGER NOT NULL,
                        week_year INTEGER NOT NULL,
                        start_date TEXT NOT NULL,
                        end_date TEXT NOT NULL,
                        total_volume NUMERIC NOT NULL DEFAULT 0.00,
                        total_orders INTEGER NOT NULL DEFAULT 0,
                        gross_commission NUMERIC NOT NULL DEFAULT 0.00,
                        adjustment_amount NUMERIC NOT NULL DEFAULT 0.00,
                        adjustment_reason TEXT,
                        net_commission NUMERIC NOT NULL DEFAULT 0.00,
                        payout_status TEXT NOT NULL DEFAULT 'pending_review',
                        payout_reference TEXT,
                        paid_at TEXT,
                        paid_by_admin_id INTEGER,
                        approved_at TEXT,
                        approved_by_admin_id INTEGER,
                        notes TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(agent_id, week_year, week_number),
                        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_aws_agent ON agent_weekly_statements(agent_id);
                    CREATE INDEX IF NOT EXISTS idx_aws_status ON agent_weekly_statements(payout_status);

                    CREATE TABLE IF NOT EXISTS agent_audit_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        actor_type TEXT NOT NULL,
                        actor_id INTEGER NOT NULL,
                        agent_id INTEGER NOT NULL,
                        action TEXT NOT NULL,
                        details TEXT,
                        ip_address TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_aal_agent ON agent_audit_logs(agent_id);
                    CREATE INDEX IF NOT EXISTS idx_aal_action ON agent_audit_logs(action);
                ");
            } else {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `agents` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `agent_code` VARCHAR(50) NOT NULL UNIQUE,
                            `name` VARCHAR(100) NOT NULL,
                            `phone` VARCHAR(20) DEFAULT NULL,
                            `email` VARCHAR(100) DEFAULT NULL,
                            `password_hash` VARCHAR(255) NOT NULL,
                            `root_user_id` INT NOT NULL,
                            `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                            `eligible_levels` VARCHAR(255) NOT NULL DEFAULT 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z',
                            `totp_secret` TEXT NOT NULL,
                            `totp_last_timestamp` BIGINT NOT NULL DEFAULT 0,
                            `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
                            `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
                            `created_by_admin_id` INT DEFAULT NULL,
                            `last_login_at` DATETIME DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            INDEX `idx_agents_code` (`agent_code`),
                            INDEX `idx_agents_root` (`root_user_id`),
                            INDEX `idx_agents_status` (`status`),
                            CONSTRAINT `fk_agents_root_user` FOREIGN KEY (`root_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `agents` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `agent_code` VARCHAR(50) NOT NULL UNIQUE,
                                `name` VARCHAR(100) NOT NULL,
                                `phone` VARCHAR(20) DEFAULT NULL,
                                `email` VARCHAR(100) DEFAULT NULL,
                                `password_hash` VARCHAR(255) NOT NULL,
                                `root_user_id` INT NOT NULL,
                                `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                                `eligible_levels` VARCHAR(255) NOT NULL DEFAULT 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z',
                                `totp_secret` TEXT NOT NULL,
                                `totp_last_timestamp` BIGINT NOT NULL DEFAULT 0,
                                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                                `must_change_password` TINYINT(1) NOT NULL DEFAULT 1,
                                `created_by_admin_id` INT DEFAULT NULL,
                                `last_login_at` DATETIME DEFAULT NULL,
                                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                INDEX `idx_agents_code` (`agent_code`),
                                INDEX `idx_agents_root` (`root_user_id`),
                                INDEX `idx_agents_status` (`status`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ");
                    } catch (Exception $e2) {}
                }

                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `agent_commissions` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `agent_id` INT NOT NULL,
                            `child_order_id` INT NOT NULL,
                            `buyer_id` INT NOT NULL,
                            `purchase_amount` DECIMAL(16,2) NOT NULL,
                            `commission_rate` DECIMAL(5,2) NOT NULL,
                            `commission_amount` DECIMAL(16,2) NOT NULL,
                            `referral_level` VARCHAR(5) NOT NULL,
                            `referral_depth` INT NOT NULL,
                            `statement_id` INT DEFAULT NULL,
                            `status` ENUM('earned', 'reversed') NOT NULL DEFAULT 'earned',
                            `order_completed_at` DATETIME NOT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE KEY `uk_agent_child_order` (`agent_id`, `child_order_id`),
                            INDEX `idx_ac_agent` (`agent_id`),
                            INDEX `idx_ac_order` (`child_order_id`),
                            INDEX `idx_ac_buyer` (`buyer_id`),
                            INDEX `idx_ac_statement` (`statement_id`),
                            INDEX `idx_ac_completed` (`order_completed_at`),
                            CONSTRAINT `fk_ac_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
                            CONSTRAINT `fk_ac_child_order` FOREIGN KEY (`child_order_id`) REFERENCES `child_orders` (`id`) ON DELETE CASCADE,
                            CONSTRAINT `fk_ac_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `agent_commissions` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `agent_id` INT NOT NULL,
                                `child_order_id` INT NOT NULL,
                                `buyer_id` INT NOT NULL,
                                `purchase_amount` DECIMAL(16,2) NOT NULL,
                                `commission_rate` DECIMAL(5,2) NOT NULL,
                                `commission_amount` DECIMAL(16,2) NOT NULL,
                                `referral_level` VARCHAR(5) NOT NULL,
                                `referral_depth` INT NOT NULL,
                                `statement_id` INT DEFAULT NULL,
                                `status` VARCHAR(20) NOT NULL DEFAULT 'earned',
                                `order_completed_at` DATETIME NOT NULL,
                                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                UNIQUE KEY `uk_agent_child_order` (`agent_id`, `child_order_id`),
                                INDEX `idx_ac_agent` (`agent_id`),
                                INDEX `idx_ac_order` (`child_order_id`),
                                INDEX `idx_ac_buyer` (`buyer_id`),
                                INDEX `idx_ac_statement` (`statement_id`),
                                INDEX `idx_ac_completed` (`order_completed_at`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ");
                    } catch (Exception $e2) {}
                }

                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `agent_weekly_statements` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `statement_code` VARCHAR(50) NOT NULL UNIQUE,
                            `agent_id` INT NOT NULL,
                            `week_number` INT NOT NULL,
                            `week_year` INT NOT NULL,
                            `start_date` DATETIME NOT NULL,
                            `end_date` DATETIME NOT NULL,
                            `total_volume` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                            `total_orders` INT NOT NULL DEFAULT 0,
                            `gross_commission` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                            `adjustment_amount` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                            `adjustment_reason` TEXT DEFAULT NULL,
                            `net_commission` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                            `payout_status` ENUM('draft', 'pending_review', 'approved', 'paid', 'cancelled') NOT NULL DEFAULT 'pending_review',
                            `payout_reference` VARCHAR(100) DEFAULT NULL,
                            `paid_at` DATETIME DEFAULT NULL,
                            `paid_by_admin_id` INT DEFAULT NULL,
                            `approved_at` DATETIME DEFAULT NULL,
                            `approved_by_admin_id` INT DEFAULT NULL,
                            `notes` TEXT DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            UNIQUE KEY `uk_aws_agent_week` (`agent_id`, `week_year`, `week_number`),
                            INDEX `idx_aws_agent` (`agent_id`),
                            INDEX `idx_aws_status` (`payout_status`),
                            CONSTRAINT `fk_aws_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `agent_weekly_statements` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `statement_code` VARCHAR(50) NOT NULL UNIQUE,
                                `agent_id` INT NOT NULL,
                                `week_number` INT NOT NULL,
                                `week_year` INT NOT NULL,
                                `start_date` DATETIME NOT NULL,
                                `end_date` DATETIME NOT NULL,
                                `total_volume` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                                `total_orders` INT NOT NULL DEFAULT 0,
                                `gross_commission` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                                `adjustment_amount` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                                `adjustment_reason` TEXT DEFAULT NULL,
                                `net_commission` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
                                `payout_status` VARCHAR(20) NOT NULL DEFAULT 'pending_review',
                                `payout_reference` VARCHAR(100) DEFAULT NULL,
                                `paid_at` DATETIME DEFAULT NULL,
                                `paid_by_admin_id` INT DEFAULT NULL,
                                `approved_at` DATETIME DEFAULT NULL,
                                `approved_by_admin_id` INT DEFAULT NULL,
                                `notes` TEXT DEFAULT NULL,
                                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                UNIQUE KEY `uk_aws_agent_week` (`agent_id`, `week_year`, `week_number`),
                                INDEX `idx_aws_agent` (`agent_id`),
                                INDEX `idx_aws_status` (`payout_status`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ");
                    } catch (Exception $e2) {}
                }

                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `agent_audit_logs` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `actor_type` VARCHAR(20) NOT NULL,
                            `actor_id` INT NOT NULL,
                            `agent_id` INT NOT NULL,
                            `action` VARCHAR(50) NOT NULL,
                            `details` TEXT DEFAULT NULL,
                            `ip_address` VARCHAR(50) DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_aal_agent` (`agent_id`),
                            INDEX `idx_aal_action` (`action`),
                            CONSTRAINT `fk_aal_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e) {
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS `agent_audit_logs` (
                                `id` INT AUTO_INCREMENT PRIMARY KEY,
                                `actor_type` VARCHAR(20) NOT NULL,
                                `actor_id` INT NOT NULL,
                                `agent_id` INT NOT NULL,
                                `action` VARCHAR(50) NOT NULL,
                                `details` TEXT DEFAULT NULL,
                                `ip_address` VARCHAR(50) DEFAULT NULL,
                                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                INDEX `idx_aal_agent` (`agent_id`),
                                INDEX `idx_aal_action` (`action`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                        ");
                    } catch (Exception $e2) {}
                }
            }

            // Create email_verifications table for persistent OTP and token tracking
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS email_verifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        email TEXT NOT NULL,
                        otp_code TEXT NOT NULL,
                        verification_token TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'pending',
                        attempts INTEGER NOT NULL DEFAULT 0,
                        ip_address TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        expires_at TEXT NOT NULL,
                        verified_at TEXT,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_ev_email ON email_verifications(email);
                    CREATE INDEX IF NOT EXISTS idx_ev_token ON email_verifications(verification_token);
                    CREATE INDEX IF NOT EXISTS idx_ev_status ON email_verifications(status);

                    CREATE TABLE IF NOT EXISTS password_resets (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER,
                        email TEXT NOT NULL,
                        otp_code TEXT NOT NULL,
                        reset_token TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'pending',
                        attempts INTEGER NOT NULL DEFAULT 0,
                        ip_address TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        expires_at TEXT NOT NULL,
                        used_at TEXT,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_pr_email ON password_resets(email);
                    CREATE INDEX IF NOT EXISTS idx_pr_token ON password_resets(reset_token);
                    CREATE INDEX IF NOT EXISTS idx_pr_status ON password_resets(status);

                    CREATE TABLE IF NOT EXISTS health_score_audit_logs (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        old_score INTEGER NOT NULL,
                        new_score INTEGER NOT NULL,
                        delta INTEGER NOT NULL,
                        reason TEXT NOT NULL,
                        event_type TEXT NOT NULL DEFAULT 'SYSTEM_EVENT',
                        admin_id INTEGER DEFAULT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    );
                    CREATE INDEX IF NOT EXISTS idx_hsal_user ON health_score_audit_logs(user_id);
                    CREATE INDEX IF NOT EXISTS idx_hsal_created ON health_score_audit_logs(created_at);
                ");
            } else {
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `email_verifications` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NULL,
                            `email` VARCHAR(191) NOT NULL,
                            `otp_code` VARCHAR(10) NOT NULL,
                            `verification_token` VARCHAR(128) NOT NULL,
                            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                            `attempts` INT NOT NULL DEFAULT 0,
                            `ip_address` VARCHAR(45) DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `expires_at` DATETIME NOT NULL,
                            `verified_at` DATETIME DEFAULT NULL,
                            INDEX `idx_ev_email` (`email`),
                            INDEX `idx_ev_token` (`verification_token`),
                            INDEX `idx_ev_status` (`status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                        CREATE TABLE IF NOT EXISTS `password_resets` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NULL,
                            `email` VARCHAR(191) NOT NULL,
                            `otp_code` VARCHAR(10) NOT NULL,
                            `reset_token` VARCHAR(128) NOT NULL,
                            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                            `attempts` INT NOT NULL DEFAULT 0,
                            `ip_address` VARCHAR(45) DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `expires_at` DATETIME NOT NULL,
                            `used_at` DATETIME DEFAULT NULL,
                            INDEX `idx_pr_email` (`email`),
                            INDEX `idx_pr_token` (`reset_token`),
                            INDEX `idx_pr_status` (`status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                        CREATE TABLE IF NOT EXISTS `health_score_audit_logs` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `user_id` INT NOT NULL,
                            `old_score` INT NOT NULL,
                            `new_score` INT NOT NULL,
                            `delta` INT NOT NULL,
                            `reason` VARCHAR(255) NOT NULL,
                            `event_type` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM_EVENT',
                            `admin_id` INT DEFAULT NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            INDEX `idx_hsal_user` (`user_id`),
                            INDEX `idx_hsal_created` (`created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } catch (Exception $e3) {}
            }
        } catch (Exception $e) {
            // Tables already exist or migration handled
        }
    }

    public static function seedPlatformSettings(PDO $pdo): void {
        $settings = [
            ['fraud_detection_enabled', '1', 'AI and rule-based fraud detection engine status (1=Active, 0=Disabled)'],
            ['fraud_auto_reject_score', '75', 'Risk score threshold at or above which submissions are automatically rejected'],
            ['fraud_review_threshold', '25', 'Risk score threshold at or above which submissions are marked PENDING_REVIEW'],
            ['fraud_gemini_api_key', '', 'Optional Google Gemini Vision API key for enhanced OCR screenshot inspection'],
            ['fraud_perceptual_hash_tolerance', '5', 'Max Hamming distance threshold for perceptual duplicate detection'],
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
            ['telegram_support_url', 'https://t.me/amilacorp', 'Official Telegram Support Channel or Username URL'],
            ['telegram_support_username', '@amilacorp', 'Official Telegram Support handle/username'],
            ['platform_mode', 'live', 'Platform operating mode (live / test)'],
            ['allow_user_registration', '1', 'Allow public user registrations (1=Active, 0=Disabled)'],
            ['allow_sell_listings', '1', 'Allow peer users to list new sell orders (1=Active, 0=Disabled)'],
            ['allow_buy_orders', '1', 'Allow peer buyers to pick and allocate orders (1=Active, 0=Disabled)'],
            ['platform_logo_url', '/public/images/logo.svg', 'Official brand vector logo URL'],
            ['platform_favicon_url', '/favicon.svg', 'Official brand vector favicon URL']
        ];

        $stmt = $pdo->prepare("SELECT setting_key FROM platform_settings WHERE setting_key = ?");
        $ins = $pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, description) VALUES (?, ?, ?)");

        foreach ($settings as $s) {
            $stmt->execute([$s[0]]);
            if (!$stmt->fetch()) {
                $ins->execute($s);
            }
        }

        // Migrate placeholder telegram link if not customized
        try {
            $chk = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_url'");
            if ($chk) {
                $curVal = $chk->fetchColumn();
                if ($curVal === 'https://t.me/jelipay_support' || $curVal === 't.me/jelipay_support' || empty($curVal)) {
                    $pdo->exec("UPDATE platform_settings SET setting_value = 'https://t.me/amilacorp' WHERE setting_key = 'telegram_support_url'");
                }
            }
            $chkUser = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key = 'telegram_support_username'");
            if ($chkUser) {
                $curUser = $chkUser->fetchColumn();
                if ($curUser === '@jelipay_support' || $curUser === 'jelipay_support' || empty($curUser)) {
                    $pdo->exec("UPDATE platform_settings SET setting_value = '@amilacorp' WHERE setting_key = 'telegram_support_username'");
                }
            }
        } catch (Exception $e) {
            // Ignore migration exception
        }
    }

    public static function seedDemoAccounts(PDO $pdo): void {
        self::ensureDefaultAdmin($pdo);

        // When running automated SQLite test suite, seed test admin for QA-012
        if (Database::getDriver() === 'sqlite' || !empty(getenv('ALLOW_SQLITE_CLI_FALLBACK'))) {
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
        }
    }

    public static function ensureDefaultAdmin(PDO $pdo): void {
        // Super Admin Account (ADMIN001 / 9999999999)
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

        // On production MySQL, permanently purge demo accounts
        if (Database::getDriver() !== 'sqlite' && empty(getenv('ALLOW_SQLITE_CLI_FALLBACK'))) {
            try {
                $pdo->exec("DELETE FROM users WHERE is_demo = 1 OR phone IN ('9888800000', '9888800001')");
            } catch (Exception $e) {
                // Ignore during intermediate migration phases
            }
        }
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
