-- ====================================================================
-- P2P TOKEN PLATFORM (PRD v1.0) - PRODUCTION SCHEMA & SEED DATA
-- Target Database: u591469534_jelii
-- Production Domain: https://jelipay.in/
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `admin_audit_logs`;
DROP TABLE IF EXISTS `wallet_ledger`;
DROP TABLE IF EXISTS `disputes`;
DROP TABLE IF EXISTS `payment_proofs`;
DROP TABLE IF EXISTS `child_orders`;
DROP TABLE IF EXISTS `parent_sell_orders`;
DROP TABLE IF EXISTS `wallets`;
DROP TABLE IF EXISTS `platform_settings`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. USERS & AUTHENTICATION TABLE
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phone` VARCHAR(20) NOT NULL UNIQUE,
  `email` VARCHAR(100) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `tpin_hash` VARCHAR(255) DEFAULT NULL,
  `account_status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  `admin_role` VARCHAR(50) DEFAULT NULL,
  `is_demo` TINYINT(1) NOT NULL DEFAULT 0,
  `is_flagged` TINYINT(1) NOT NULL DEFAULT 0,
  `flag_reason` VARCHAR(255) DEFAULT NULL,
  `referral_code` VARCHAR(32) NOT NULL UNIQUE,
  `referred_by_id` INT DEFAULT NULL,
  `upi_id` VARCHAR(100) DEFAULT NULL,
  `account_holder_name` VARCHAR(100) DEFAULT NULL,
  `health_score` INT NOT NULL DEFAULT 100,
  `totp_secret` TEXT DEFAULT NULL,
  `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `totp_temp_secret` TEXT DEFAULT NULL,
  `totp_temp_created_at` DATETIME DEFAULT NULL,
  `totp_last_timestamp` BIGINT NOT NULL DEFAULT 0,
  `totp_recovery_codes` TEXT DEFAULT NULL,
  `password_changed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_phone` (`phone`),
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_ref` (`referral_code`),
  INDEX `idx_users_referred_by` (`referred_by_id`),
  CONSTRAINT `fk_users_referred_by` FOREIGN KEY (`referred_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USER WALLETS TABLE (Strict segregation & optimistic locking)
CREATE TABLE `wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `available_balance` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `reserved_balance` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `pending_credit_balance` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `total_rewards_earned` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `total_commissions_earned` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `version` INT NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. PARENT SELL ORDERS TABLE
CREATE TABLE `parent_sell_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `seller_id` INT NOT NULL,
  `original_amount` DECIMAL(16,2) NOT NULL,
  `remaining_amount` DECIMAL(16,2) NOT NULL,
  `reserved_amount` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `completed_amount` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
  `snapshotted_upi_id` VARCHAR(100) NOT NULL,
  `snapshotted_account_holder` VARCHAR(100) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_parent_orders_seller` (`seller_id`),
  INDEX `idx_parent_orders_status` (`status`),
  CONSTRAINT `fk_parent_orders_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. CHILD ORDERS TABLE (Full 10-state lifecycle)
CREATE TABLE `child_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `parent_order_id` INT NOT NULL,
  `seller_id` INT NOT NULL,
  `buyer_id` INT DEFAULT NULL,
  `amount` DECIMAL(16,2) NOT NULL,
  `snapshotted_reward_rate` DECIMAL(5,2) NOT NULL DEFAULT 9.00,
  `snapshotted_reward_amount` DECIMAL(16,2) NOT NULL,
  `snapshotted_upi_id` VARCHAR(100) NOT NULL,
  `snapshotted_account_holder` VARCHAR(100) NOT NULL,
  `status` ENUM(
    'Available',
    'Reserved',
    'Payment Proof Submitted',
    'Seller Confirmation Pending',
    'Seller Response Timeout',
    'Disputed',
    'Under Admin Review',
    'Completed',
    'Expired',
    'Cancelled / Reversed'
  ) NOT NULL DEFAULT 'Available',
  `payment_deadline` DATETIME DEFAULT NULL,
  `verification_deadline` DATETIME DEFAULT NULL,
  `picked_at` DATETIME DEFAULT NULL,
  `proof_submitted_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `idempotency_key` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_child_orders_status` (`status`),
  INDEX `idx_child_orders_parent` (`parent_order_id`),
  INDEX `idx_child_orders_seller` (`seller_id`),
  INDEX `idx_child_orders_buyer` (`buyer_id`),
  INDEX `idx_child_orders_idempotency` (`idempotency_key`),
  CONSTRAINT `fk_child_orders_parent` FOREIGN KEY (`parent_order_id`) REFERENCES `parent_sell_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_child_orders_seller` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_child_orders_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. PAYMENT PROOFS TABLE (Globally unique UTR enforcement)
CREATE TABLE `payment_proofs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `child_order_id` INT NOT NULL UNIQUE,
  `buyer_id` INT NOT NULL,
  `utr_number` VARCHAR(100) NOT NULL UNIQUE,
  `screenshot_path` VARCHAR(255) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_proofs_buyer` (`buyer_id`),
  CONSTRAINT `fk_proofs_child_order` FOREIGN KEY (`child_order_id`) REFERENCES `child_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_proofs_buyer` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. DISPUTES & APPEALS TABLE
CREATE TABLE `disputes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `child_order_id` INT NOT NULL UNIQUE,
  `raised_by_user_id` INT NOT NULL,
  `raised_by_role` ENUM('seller', 'buyer', 'admin') NOT NULL,
  `reason` TEXT NOT NULL,
  `evidence_screenshot_path` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending', 'under_review', 'resolved_buyer', 'resolved_seller') NOT NULL DEFAULT 'pending',
  `additional_evidence_requested` TINYINT(1) NOT NULL DEFAULT 0,
  `refund_status` ENUM('none', 'pending', 'issued', 'not_applicable') NOT NULL DEFAULT 'none',
  `refund_reference` VARCHAR(100) DEFAULT NULL,
  `resolution_notes` TEXT DEFAULT NULL,
  `resolved_by_admin_id` INT DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_disputes_order` (`child_order_id`),
  INDEX `idx_disputes_status` (`status`),
  CONSTRAINT `fk_disputes_order` FOREIGN KEY (`child_order_id`) REFERENCES `child_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_disputes_raised_by` FOREIGN KEY (`raised_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_disputes_admin` FOREIGN KEY (`resolved_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. WALLET LEDGER TABLE (Append-only, immutable financial transactions)
CREATE TABLE `wallet_ledger` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `transaction_type` ENUM(
    'BUY_CREDIT',
    'BUY_REWARD',
    'SELL_RESERVE',
    'SELL_SETTLE',
    'SELL_RELEASE',
    'REFERRAL_L1',
    'REFERRAL_L2',
    'ADMIN_CREDIT',
    'ADMIN_DEBIT',
    'DISPUTE_REVERSAL',
    'INITIAL_DEPOSIT'
  ) NOT NULL,
  `amount` DECIMAL(16,2) NOT NULL,
  `previous_balance` DECIMAL(16,2) NOT NULL,
  `new_balance` DECIMAL(16,2) NOT NULL,
  `balance_type` ENUM('available', 'reserved', 'pending') NOT NULL,
  `reference_id` VARCHAR(100) DEFAULT NULL,
  `actor_id` INT NOT NULL,
  `actor_role` ENUM('user', 'admin', 'system') NOT NULL,
  `status` ENUM('completed', 'reversed') NOT NULL DEFAULT 'completed',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ledger_user` (`user_id`),
  INDEX `idx_ledger_type` (`transaction_type`),
  INDEX `idx_ledger_created` (`created_at`),
  CONSTRAINT `fk_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. ADMIN AUDIT LOGS TABLE
CREATE TABLE `admin_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `target_entity` VARCHAR(100) NOT NULL,
  `target_id` VARCHAR(100) NOT NULL,
  `before_state` LONGTEXT DEFAULT NULL,
  `after_state` LONGTEXT DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_audit_admin` (`admin_id`),
  INDEX `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PLATFORM CONFIGURATION SETTINGS
CREATE TABLE `platform_settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. RATE LIMITING TABLE
CREATE TABLE `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `identifier` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `hits` INT NOT NULL DEFAULT 1,
  `window_start` INT NOT NULL,
  UNIQUE KEY `uq_rate_identifier_action` (`identifier`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. NOTIFICATIONS TABLE
CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `type` VARCHAR(50) NOT NULL DEFAULT 'info',
  `reference_id` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. SUPPORT REQUESTS (Live Chat Sessions)
CREATE TABLE `support_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `username` VARCHAR(100) NULL,
  `message` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `assigned_admin_id` INT NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_support_user` (`user_id`),
  INDEX `idx_support_phone` (`phone`),
  INDEX `idx_support_status` (`status`),
  INDEX `idx_support_assigned` (`assigned_admin_id`),
  CONSTRAINT `fk_support_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_admin` FOREIGN KEY (`assigned_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. SUPPORT MESSAGES (Real-Time Live Chat Transcripts)
CREATE TABLE `support_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `sender_id` INT NULL,
  `sender_name` VARCHAR(100) NULL,
  `message` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_msg_req` (`request_id`),
  INDEX `idx_msg_read` (`is_read`),
  INDEX `idx_msg_created` (`created_at`),
  CONSTRAINT `fk_msg_req` FOREIGN KEY (`request_id`) REFERENCES `support_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. PAYMENT FINGERPRINTS
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

-- 15. FRAUD ASSESSMENTS
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

-- 16. USER RISK PROFILES
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

-- 17. AGENTS TABLE
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

-- 18. AGENT COMMISSIONS TABLE (Idempotent base purchase token commission ledger)
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

-- 19. AGENT WEEKLY STATEMENTS TABLE (Monday 00:00:00 to Sunday 23:59:59 UTC cycles)
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

-- 20. AGENT AUDIT LOGS TABLE
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

-- ====================================================================
-- DEFAULT CONFIGURATION SEEDS
-- ====================================================================
INSERT INTO `platform_settings` (`setting_key`, `setting_value`, `description`) VALUES
('site_name', 'JeliPay', 'Website and platform public brand name'),
('site_tagline', 'Unified Peer-to-Peer Token Trading Platform', 'Platform sub-header description'),
('token_rate_inr', '1', 'Base value of 1 token in INR'),
('purchase_reward_percent', '9.0', 'Buyer purchase reward percentage (default 9%)'),
('referral_l1_percent', '1.0', 'Level 1 direct referral reward percentage'),
('referral_l2_percent', '0.5', 'Level 2 indirect referral reward percentage'),
('min_sell_amount', '100', 'Minimum allowed token amount for sell listing'),
('max_sell_amount', '100000', 'Maximum allowed token amount for sell listing'),
('min_buy_amount', '100', 'Minimum allowed token amount for custom buy allocation'),
('max_buy_amount', '100000', 'Maximum allowed token amount for custom buy allocation'),
('sell_denomination_step', '100', 'Mandatory step modulo for sell amount (Amount MOD 100 == 0)'),
('weekly_selling_target', '400000', 'Weekly selling target in INR for progress calculation'),
('buyer_payment_window_seconds', '600', '10-minute payment countdown window for buyer'),
('seller_verify_window_seconds', '900', '15-minute seller verification window before response timeout'),
('max_child_splits', '8', 'Maximum micro-order portions a parent order can split into'),
('announcement_banner_active', '1', 'Display announcement notice banner on homepage (1=Active, 0=Inactive)'),
('announcement_banner_text', '✨ Active Promotion: Earn 9% instant credit rewards on all peer token purchases!', 'Text banner displayed to traders'),
('announcement_banner_image', '', 'Optional banner graphical asset URL'),
('maintenance_mode', '0', 'Put marketplace into maintenance mode (1=Active, 0=Disabled)'),
('maintenance_message', 'JeliPay is temporarily offline for scheduled system optimization. We will resume trading shortly.', 'Maintenance notice displayed to non-admins'),
('terms_of_service', 'All token trades are peer-to-peer and settled directly via UPI banking. Users must verify payment proofs accurately.', 'Platform terms of service statement'),
('privacy_policy', 'Your privacy is paramount. Personal contact details and phone numbers are masked across public trade order pools.', 'Platform privacy policy statement'),
('support_contact_info', 'support@jelipay.in', 'Official administrative support contact details'),
('approved_upi_config', 'jelipay@upi', 'Approved default UPI gateway reference'),
('reward_eligible_types', 'ALL', 'Eligible transaction types for reward calculation');

-- ====================================================================
-- INITIAL USERS & WALLET SEEDS
-- ====================================================================
-- 1. Super Admin Account: Phone 9999999999 / Password 'Admin@123456' / TPIN '1234'
INSERT INTO `users` (`id`, `phone`, `email`, `password_hash`, `tpin_hash`, `account_status`, `role`, `admin_role`, `referral_code`, `referred_by_id`, `upi_id`, `account_holder_name`)
VALUES (1, '9999999999', 'admin@jelipay.in', '$2y$10$nsaDWNCaNU9TRJp/FJ3NpuncVzjhS7zyQADri9qt0iWnUN1JTAW8e', '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', 'active', 'admin', 'super_admin', 'ADMIN001', NULL, 'jelipayadmin@upi', 'JeliPay Admin');

INSERT INTO `wallets` (`id`, `user_id`, `available_balance`, `reserved_balance`, `pending_credit_balance`, `total_rewards_earned`, `total_commissions_earned`, `version`)
VALUES (1, 1, 1000000.00, 0.00, 0.00, 0.00, 0.00, 1);

INSERT INTO `wallet_ledger` (`user_id`, `transaction_type`, `amount`, `previous_balance`, `new_balance`, `balance_type`, `reference_id`, `actor_id`, `actor_role`, `notes`)
VALUES (1, 'INITIAL_DEPOSIT', 1000000.00, 0.00, 1000000.00, 'available', 'SYS_INIT_1', 1, 'system', 'Platform liquidity initial reserve');

-- 2. Demo Admin Account: Admin ID 'DEMOADMIN001' / Phone 9888800000 / Email 'demoadmin@jelipay.in' / Password 'DemoAdmin@123'
INSERT INTO `users` (`id`, `phone`, `email`, `password_hash`, `tpin_hash`, `account_status`, `role`, `admin_role`, `referral_code`, `referred_by_id`, `upi_id`, `account_holder_name`)
VALUES (2, '9888800000', 'demoadmin@jelipay.in', '$2y$10$HU771qOFL016eO.hpPE5x.IVliUJ1Qca2K8TyzAzFbN7XPo/BRuhO', '$2y$10$aq.G53.wTfw/6bxgafs2O.TcU2ZPsuBlmRUqf9rXCI.TDhQO446ou', 'active', 'admin', 'super_admin', 'DEMOADMIN001', NULL, 'demoadmin@upi', 'Demo Administrator');

INSERT INTO `wallets` (`id`, `user_id`, `available_balance`, `reserved_balance`, `pending_credit_balance`, `total_rewards_earned`, `total_commissions_earned`, `version`)
VALUES (2, 2, 500000.00, 0.00, 0.00, 0.00, 0.00, 1);

INSERT INTO `wallet_ledger` (`user_id`, `transaction_type`, `amount`, `previous_balance`, `new_balance`, `balance_type`, `reference_id`, `actor_id`, `actor_role`, `notes`)
VALUES (2, 'INITIAL_DEPOSIT', 500000.00, 0.00, 500000.00, 'available', 'SYS_INIT_2', 2, 'system', 'Demo administrator staging reserve');

-- 3. Demo User Account: User ID 'DEMOUSER001' / Phone 9888800001 / Password 'DemoUser@123' / TPIN '1234'
INSERT INTO `users` (`id`, `phone`, `email`, `password_hash`, `tpin_hash`, `account_status`, `role`, `is_demo`, `referral_code`, `referred_by_id`, `upi_id`, `account_holder_name`)
VALUES (3, '9888800001', 'demouser@jelipay.in', '$2y$10$1djyY.sn4UJ32HNnRE/3cOqYgCRho99T.Zk1IPzjTbexhKkk0NY2.', '$2y$10$aq.G53.wTfw/6bxgafs2O.TcU2ZPsuBlmRUqf9rXCI.TDhQO446ou', 'active', 'user', 1, 'DEMOUSER001', 1, 'demouser@okhdfcbank', 'Demo Trader');

INSERT INTO `wallets` (`id`, `user_id`, `available_balance`, `reserved_balance`, `pending_credit_balance`, `total_rewards_earned`, `total_commissions_earned`, `version`)
VALUES (3, 3, 10000.00, 0.00, 0.00, 0.00, 0.00, 1);

INSERT INTO `wallet_ledger` (`user_id`, `transaction_type`, `amount`, `previous_balance`, `new_balance`, `balance_type`, `reference_id`, `actor_id`, `actor_role`, `notes`)
VALUES (3, 'INITIAL_DEPOSIT', 10000.00, 0.00, 10000.00, 'available', 'SYS_INIT_3', 3, 'system', 'Demo user initial testing balance');

-- 4. Standard User: Phone 9876543210 / Password 'User@123456' / TPIN '1234'
INSERT INTO `users` (`id`, `phone`, `email`, `password_hash`, `tpin_hash`, `account_status`, `role`, `referral_code`, `referred_by_id`, `upi_id`, `account_holder_name`)
VALUES (4, '9876543210', 'rohan@jelipay.in', '$2y$10$VptZJT57ky0utSer8oOiMerRqnDko3zNNqzM50MHgiWoPJoQNRyjW', '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', 'active', 'user', 'USER001', 1, 'user1@okhdfcbank', 'Rohan Sharma');

INSERT INTO `wallets` (`id`, `user_id`, `available_balance`, `reserved_balance`, `pending_credit_balance`, `total_rewards_earned`, `total_commissions_earned`, `version`)
VALUES (4, 4, 50000.00, 0.00, 0.00, 0.00, 0.00, 1);

INSERT INTO `wallet_ledger` (`user_id`, `transaction_type`, `amount`, `previous_balance`, `new_balance`, `balance_type`, `reference_id`, `actor_id`, `actor_role`, `notes`)
VALUES (4, 'INITIAL_DEPOSIT', 50000.00, 0.00, 50000.00, 'available', 'SYS_INIT_4', 4, 'system', 'Standard peer trader initial balance');

-- 5. Standard User 2: Phone 9811122233 / Password 'User@123456' / TPIN '1234' (Referred by User 1 for multi-tier referral testing)
INSERT INTO `users` (`id`, `phone`, `email`, `password_hash`, `tpin_hash`, `account_status`, `role`, `referral_code`, `referred_by_id`, `upi_id`, `account_holder_name`)
VALUES (5, '9811122233', 'priya@jelipay.in', '$2y$10$VptZJT57ky0utSer8oOiMerRqnDko3zNNqzM50MHgiWoPJoQNRyjW', '$2y$10$xR4yCngo7fNgdPrDkxFJ1OQPrhriUSm2RpwEvFkdBNRT3nEUVWGMm', 'active', 'user', 'USER002', 4, 'user2@paytm', 'Priya Patel');

INSERT INTO `wallets` (`id`, `user_id`, `available_balance`, `reserved_balance`, `pending_credit_balance`, `total_rewards_earned`, `total_commissions_earned`, `version`)
VALUES (5, 5, 20000.00, 0.00, 0.00, 0.00, 0.00, 1);

INSERT INTO `wallet_ledger` (`user_id`, `transaction_type`, `amount`, `previous_balance`, `new_balance`, `balance_type`, `reference_id`, `actor_id`, `actor_role`, `notes`)
VALUES (5, 'INITIAL_DEPOSIT', 20000.00, 0.00, 20000.00, 'available', 'SYS_INIT_5', 5, 'system', 'Standard peer trader 2 initial balance');
