<?php
/**
 * P2P Token Platform - Master Configuration
 * Domain: https://jelipay.in/
 * System: Production Configuration & Business Rule Defaults
 */

// Strict error reporting for debugging / production logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set default timezone to UTC for authoritative server-side timing
date_default_timezone_set('UTC');

// Production Database Credentials (Hostinger MySQL)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'u591469534_jelii');
define('DB_USER', getenv('DB_USER') ?: 'u591469534_jelii123');
define('DB_PASS', getenv('DB_PASS') ?: 'Jelii@123@');
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'mysql'); // 'mysql' or 'sqlite' for local tests

// Platform Constants
define('APP_NAME', 'JeliPay P2P');
define('APP_URL', 'https://jelipay.in');
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_PATH . '/uploads');
define('PROOF_UPLOAD_DIR', UPLOAD_DIR . '/proofs');
define('EVIDENCE_UPLOAD_DIR', UPLOAD_DIR . '/evidence');

// Core Business Rules Defaults
define('TOKEN_VALUE_INR', 1.00);                 // 1 Platform Token = ₹1
define('PURCHASE_REWARD_RATE', 0.09);            // 9% purchase reward (0.09)
define('REFERRAL_L1_RATE', 0.01);               // 1% Level 1 referral commission
define('REFERRAL_L2_RATE', 0.005);              // 0.5% Level 2 referral commission
define('MIN_SELL_AMOUNT', 100);                 // Minimum ₹100
define('SELL_DENOMINATION_STEP', 100);          // Exact multiples of 100 (mod 100 == 0)
define('WEEKLY_SELLING_TARGET', 400000.00);     // ₹4,00,000 target
define('BUYER_PAYMENT_WINDOW_SECONDS', 600);    // 10 minutes (600 seconds)
define('SELLER_VERIFY_WINDOW_SECONDS', 900);    // 15 minutes (900 seconds)
define('MAX_CHILD_SPLITS', 8);                  // Up to 8 child micro-orders

// Session & Security Settings (Part 12, Item 1: 48-Hour Session Stability)
ini_set('session.gc_maxlifetime', '172800'); // 48 hours
ini_set('session.cookie_lifetime', '172800'); // 48 hours

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === '1')) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
        (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on')
    );

    session_set_cookie_params([
        'lifetime' => 172800, // 48 hours
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

