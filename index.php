<?php
/**
 * P2P Token Platform - Main Web Application
 * Single Website, Single Database, Single User Account, Single Shared Token Wallet
 * Navigation: Home | Buy | Sell | Refer | Profile (plus Admin Panel if administrator)
 * Production Domain: https://jelipay.in/
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/schema_installer.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/StateMachine.php';

// Ensure schema is prepared
SchemaInstaller::ensureSchema();

// Sync expired orders
StateMachine::checkAndExpireOrders();

$currentUser = Auth::getCurrentUser();
$isImpersonating = !empty($_SESSION['is_impersonating']);
$refFromQuery = htmlspecialchars($_GET['ref'] ?? '');

// Dynamic Platform Settings (Requirement 6)
$pdo = Database::getConnection();
$settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM platform_settings");
$platformSettings = [];
if ($settingsStmt) {
    while ($row = $settingsStmt->fetch()) {
        $platformSettings[$row['setting_key']] = $row['setting_value'];
    }
}

$siteName = htmlspecialchars($platformSettings['site_name'] ?? 'JeliPay');
$siteTagline = htmlspecialchars($platformSettings['site_tagline'] ?? 'Unified Marketplace');
$tokenRate = htmlspecialchars($platformSettings['token_rate_inr'] ?? '1');
$purchaseRewardPct = htmlspecialchars($platformSettings['purchase_reward_percent'] ?? '9.0');
$isMaintenance = ($platformSettings['maintenance_mode'] ?? '0') === '1';
$maintenanceMsg = htmlspecialchars($platformSettings['maintenance_message'] ?? 'Platform is temporarily offline for scheduled system optimization.');
$announcementActive = ($platformSettings['announcement_banner_active'] ?? '0') === '1';
$announcementText = htmlspecialchars($platformSettings['announcement_banner_text'] ?? '');
$telegramSupportUrl = htmlspecialchars($platformSettings['telegram_support_url'] ?? 'https://t.me/jelipay_support');
$isAdminUser = $currentUser && ($currentUser['role'] === 'admin' || !empty($currentUser['real_admin_id']));

// Maintenance Mode Enforcement for non-admins
if ($isMaintenance && !$isAdminUser):
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteName ?> &bull; Scheduled Maintenance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-slate-950 text-slate-100">
    <div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center space-y-4 shadow-2xl">
        <div class="w-16 h-16 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center mx-auto">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </div>
        <h1 class="text-2xl font-black text-white"><?= $siteName ?> is in Maintenance</h1>
        <p class="text-sm text-slate-400"><?= $maintenanceMsg ?></p>
    </div>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $siteName ?> | Peer-to-Peer Token Trading Platform</title>
    <!-- Official Favicon & PWA Icons -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/public/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/public/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/public/images/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#020617">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $siteName ?>">
    <meta property="og:title" content="<?= $siteName ?> - Peer-to-Peer Token Trading Platform">
    <meta property="og:description" content="Unified P2P Token Trading with Instant UPI Settlement & 9% Rewards.">
    <meta property="og:image" content="/public/images/icon-512.png">
    <meta property="og:type" content="website">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        [x-cloak] { display: none !important; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #090d16; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #334155; }
    </style>
</head>
<body class="h-full text-slate-100 flex flex-col antialiased selection:bg-indigo-500 selection:text-white">

    <!-- PWA Smart Install Banner (Part 9, Item 3) -->
    <div id="pwaInstallBanner" class="hidden bg-slate-900/95 border-b border-indigo-500/30 px-4 py-2.5 shadow-xl backdrop-blur flex items-center justify-between text-xs sticky top-0 z-50 transition-all">
        <div class="flex items-center gap-3">
            <img src="/public/images/favicon-32x32.png" alt="JeliPay Logo" class="w-7 h-7 rounded-lg shadow-sm flex-shrink-0">
            <div>
                <span class="font-bold text-white block leading-tight">Install <?= $siteName ?> App</span>
                <span class="text-[11px] text-slate-400">Add to your home screen for instant 1-tap trading</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="triggerPwaInstall()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-3 py-1.5 rounded-lg text-xs transition shadow-md shadow-indigo-600/30">
                Install
            </button>
            <button onclick="dismissPwaBanner()" class="text-slate-400 hover:text-white p-1" title="Dismiss">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <!-- Admin Support Mode Banner (FR-022) -->
    <?php if ($isImpersonating): ?>
    <div class="bg-amber-500 text-slate-950 px-4 py-2 text-xs sm:text-sm font-bold flex items-center justify-between shadow-md sticky top-0 z-50">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span>ADMIN SUPPORT MODE ACTIVE &bull; Viewing user dashboard in read-only mode. All financial executions are locked.</span>
        </div>
        <button onclick="exitImpersonation()" class="bg-slate-950 text-white px-3 py-1 rounded hover:bg-slate-800 transition text-xs font-semibold">
            Exit Support Mode
        </button>
    </div>
    <?php endif; ?>

    <!-- Active Platform Announcement Notice Banner (Requirement 6) -->
    <?php if ($announcementActive && !empty($announcementText)): ?>
    <div class="bg-gradient-to-r from-indigo-900/90 via-purple-900/80 to-indigo-950 border-b border-indigo-500/30 px-4 py-2 text-center text-xs text-indigo-200 font-semibold shadow-inner flex items-center justify-center gap-2">
        <span class="inline-block w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
        <span><?= $announcementText ?></span>
    </div>
    <?php endif; ?>

    <!-- Platform Header & Navigation -->
    <header class="bg-slate-900/90 backdrop-blur border-b border-slate-800 sticky <?= $isImpersonating ? 'top-8' : 'top-0' ?> z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand & Value Indicators -->
                <div class="flex items-center gap-4 sm:gap-6">
                    <div class="flex items-center gap-2.5 cursor-pointer" onclick="switchTab('home')">
                        <!-- Ultra-Premium Professional Emblem -->
                        <div class="h-10 w-10 rounded-2xl bg-gradient-to-tr from-indigo-600 via-violet-600 to-emerald-400 p-[1.5px] shadow-lg shadow-indigo-500/25 flex items-center justify-center flex-shrink-0">
                            <div class="w-full h-full bg-slate-950/90 rounded-[14px] flex items-center justify-center backdrop-blur-sm">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 2L3.5 7V17L12 22L20.5 17V7L12 2Z" stroke="url(#logoStroke)" stroke-width="2" stroke-linejoin="round"/>
                                    <path d="M12 6.5L7 9.5V14.5L12 17.5L17 14.5V9.5L12 6.5Z" fill="url(#logoInner)" fill-opacity="0.3" stroke="url(#logoInner)" stroke-width="1.6" stroke-linejoin="round"/>
                                    <path d="M10 12L12 14L15 10" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <defs>
                                        <linearGradient id="logoStroke" x1="3.5" y1="2" x2="20.5" y2="22" gradientUnits="userSpaceOnUse">
                                            <stop stop-color="#818cf8"/>
                                            <stop offset="0.5" stop-color="#a78bfa"/>
                                            <stop offset="1" stop-color="#34d399"/>
                                        </linearGradient>
                                        <linearGradient id="logoInner" x1="7" y1="6.5" x2="17" y2="17.5" gradientUnits="userSpaceOnUse">
                                            <stop stop-color="#6366f1"/>
                                            <stop offset="1" stop-color="#10b981"/>
                                        </linearGradient>
                                    </defs>
                                </svg>
                            </div>
                        </div>
                        <div>
                            <span class="text-xl font-black tracking-tight text-white flex items-center">
                                <?= $siteName ?>
                            </span>
                            <span class="text-[10px] text-slate-400 block -mt-0.5"><?= $siteTagline ?></span>
                        </div>
                    </div>

                    <!-- Token Valuation Baseline Badge -->
                    <div class="hidden sm:flex items-center gap-2 bg-slate-950/60 border border-slate-800/80 rounded-lg px-2.5 py-1 text-xs">
                        <span class="text-slate-400">Baseline Rate:</span>
                        <span class="font-bold text-white font-mono">1 Token = ₹<?= $tokenRate ?>.00</span>
                    </div>

                    <!-- Promotional Reward Badge -->
                    <div class="hidden md:flex items-center gap-1.5 bg-emerald-500/10 border border-emerald-500/20 rounded-lg px-2.5 py-1 text-xs text-emerald-400 font-semibold">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>+<?= $purchaseRewardPct ?>% Purchase Reward</span>
                    </div>
                </div>

                <!-- Navigation Links / User Actions -->
                <div class="flex items-center gap-3">
                    <?php if ($currentUser): ?>
                        <!-- Shared Wallet Balance Chip -->
                        <div class="flex items-center bg-slate-800/80 border border-slate-700/80 rounded-xl px-2.5 py-1.5 sm:px-3 sm:py-1.5 gap-2 cursor-pointer max-w-[160px] sm:max-w-xs min-w-0" onclick="switchTab('home')" title="Shared Available Wallet Balance">
                            <div class="text-right hidden sm:block min-w-0">
                                <span class="text-[10px] text-slate-400 uppercase tracking-wider block font-semibold leading-none">Shared Wallet</span>
                                <span class="text-xs sm:text-sm font-black text-emerald-400 truncate block font-mono" id="headerAvailableBalance">₹0.00</span>
                            </div>
                            <svg class="w-5 h-5 text-indigo-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>

                        <!-- Notification Bell Button & Popover -->
                        <div class="relative">
                            <button id="notificationBellBtn" onclick="toggleNotifications()" class="relative p-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl border border-slate-700 transition" title="Notifications">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                <span id="notifBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full h-4 w-4 flex items-center justify-center hidden">0</span>
                            </button>

                            <!-- Notifications Dropdown Popover -->
                            <div id="notificationsPopover" class="hidden absolute right-0 mt-2 w-80 sm:w-96 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-4 z-50 space-y-3">
                                <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                    <h4 class="text-sm font-bold text-white flex items-center gap-2">
                                        Notifications
                                        <span id="notifUnreadCountText" class="text-[10px] bg-indigo-500/20 text-indigo-400 px-1.5 py-0.5 rounded-full font-semibold">0 new</span>
                                    </h4>
                                    <button onclick="markNotificationsRead()" class="text-[11px] text-slate-400 hover:text-indigo-400 transition">Mark all read</button>
                                </div>
                                <div id="notificationsList" class="max-h-72 overflow-y-auto space-y-2 text-xs">
                                    <div class="text-slate-500 text-center py-4">No notifications</div>
                                </div>
                            </div>
                        </div>

                        <!-- Dedicated Appeals Navigation Button (Part 5, Item 1) -->
                        <button onclick="switchTab('appeals')" class="relative p-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl border border-slate-700 transition" title="Dispute & Appeal Center">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                            <span id="appealsBadge" class="absolute -top-1 -right-1 bg-amber-500 text-slate-950 text-[10px] font-bold rounded-full h-4 w-4 flex items-center justify-center hidden">0</span>
                        </button>

                        <!-- Dedicated Profile Navigation Button -->
                        <button onclick="switchTab('profile')" class="flex items-center gap-2 p-1.5 sm:px-2.5 sm:py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl border border-slate-700 transition cursor-pointer" title="My Profile & Settings">
                            <div class="w-6 h-6 rounded-lg bg-indigo-600/30 text-indigo-400 flex items-center justify-center font-bold text-xs" id="headerProfileInitial">
                                <?= strtoupper(substr($currentUser['account_holder_name'] ?? ($currentUser['phone'] ?? 'U'), 0, 1)) ?>
                            </div>
                            <span class="text-xs font-semibold hidden md:inline" id="headerProfileName">
                                <?= htmlspecialchars(!empty($currentUser['account_holder_name']) ? explode(' ', $currentUser['account_holder_name'])[0] : 'Profile') ?>
                            </span>
                        </button>

                        <?php if ($isAdminUser): ?>
                        <!-- Dedicated Admin Console Link -->
                        <a href="/admin.php" class="px-2.5 py-1.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5" title="Dedicated Admin Console">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="hidden md:inline">Admin Console</span>
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <button onclick="openAuthModal('login')" class="text-xs sm:text-sm font-semibold text-slate-300 hover:text-white px-3 py-2 transition">
                            Sign In
                        </button>
                        <button onclick="openAuthModal('register')" class="text-xs sm:text-sm font-bold bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-xl shadow-lg shadow-indigo-600/30 transition">
                            Get Started
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Main View Containers (with bottom padding for fixed mobile nav) -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 pb-24 sm:pb-28">

        <?php if (!$currentUser): ?>
        <!-- ========================================== -->
        <!-- PUBLIC INTRODUCTION LANDING PAGE (Non-Authenticated) -->
        <!-- ========================================== -->
        <section class="space-y-12 py-4 sm:py-8">
            <!-- Hero Introduction -->
            <div class="relative overflow-hidden bg-gradient-to-b from-slate-900/90 via-slate-900/60 to-slate-950 border border-slate-800/80 rounded-3xl p-8 sm:p-14 text-center shadow-2xl space-y-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-bold uppercase tracking-wider">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Verified Peer-to-Peer Token Trading Platform</span>
                </div>

                <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black text-white tracking-tight max-w-4xl mx-auto leading-tight">
                    Instant Peer-to-Peer Token Marketplace with <span class="bg-gradient-to-r from-indigo-400 via-violet-300 to-emerald-400 bg-clip-text text-transparent">9% Purchase Rewards</span>
                </h1>

                <p class="text-base sm:text-lg text-slate-300 max-w-2xl mx-auto leading-relaxed">
                    Buy and sell tokens directly with verified peers across India using direct bank UPI. Enjoy guaranteed 9% purchase rewards, instantaneous settlements, and institutional-grade escrow safety with one unified token wallet.
                </p>

                <!-- Clear Call-to-Action -->
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-2">
                    <button onclick="openAuthModal('register')" class="w-full sm:w-auto px-8 py-4 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-black text-base rounded-2xl shadow-xl shadow-indigo-600/30 transition transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
                        <span>Get Started — Create Free Account</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                    <button onclick="openAuthModal('login')" class="w-full sm:w-auto px-8 py-4 bg-slate-800/90 hover:bg-slate-700 text-slate-200 font-bold text-base rounded-2xl border border-slate-700 transition flex items-center justify-center gap-2">
                        <span>Sign In</span>
                    </button>
                </div>

                <!-- Trust Badges -->
                <div class="pt-6 border-t border-slate-800/80 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-3xl mx-auto text-left sm:text-center text-xs text-slate-400">
                    <div class="flex items-center sm:justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>1 Token = ₹1.00 Pegged</span>
                    </div>
                    <div class="flex items-center sm:justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Direct Bank UPI</span>
                    </div>
                    <div class="flex items-center sm:justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Double-Entry Escrow</span>
                    </div>
                    <div class="flex items-center sm:justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Unified Wallet</span>
                    </div>
                </div>
            </div>

            <!-- Key Feature Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-3 shadow-lg">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center text-xl font-black">
                        9%
                    </div>
                    <h3 class="text-xl font-bold text-white">Instant Purchase Reward</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Every verified token purchase unlocks an immediate 9% promotional reward credited directly to your available balance upon seller settlement.
                    </p>
                </div>

                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-3 shadow-lg">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white">Direct UPI Payouts</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Settlements happen directly between buyers and sellers via any Indian UPI app — Google Pay, PhonePe, Paytm, or BHIM. Zero deposit or withdrawal deductions.
                    </p>
                </div>

                <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-3 shadow-lg">
                    <div class="w-12 h-12 rounded-xl bg-violet-500/10 border border-violet-500/20 text-violet-400 flex items-center justify-center">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white">Escrow-Locked Protection</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Seller tokens are locked in verified double-entry platform escrow during trade execution, ensuring complete dispute arbitration and payment safety.
                    </p>
                </div>
            </div>

            <!-- How It Works Walkthrough -->
            <div class="bg-slate-900/40 border border-slate-800/80 rounded-3xl p-8 sm:p-10 space-y-8">
                <div class="text-center space-y-2">
                    <span class="text-xs font-bold uppercase tracking-widest text-indigo-400">Simple 3-Step Process</span>
                    <h2 class="text-2xl sm:text-3xl font-black text-white">How Trading Works</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative">
                    <div class="bg-slate-950/60 border border-slate-800 rounded-2xl p-6 space-y-3">
                        <span class="text-xs font-black font-mono text-indigo-400 bg-indigo-500/10 px-2.5 py-1 rounded-lg border border-indigo-500/20">01</span>
                        <h4 class="text-base font-bold text-white">Create Account</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">Sign up in seconds with your phone number and secure password. No complex KYC required.</p>
                    </div>
                    <div class="bg-slate-950/60 border border-slate-800 rounded-2xl p-6 space-y-3">
                        <span class="text-xs font-black font-mono text-indigo-400 bg-indigo-500/10 px-2.5 py-1 rounded-lg border border-indigo-500/20">02</span>
                        <h4 class="text-base font-bold text-white">Pick Order & Pay via UPI</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">Choose an available peer order, transfer directly to the seller's UPI, and submit your UTR reference.</p>
                    </div>
                    <div class="bg-slate-950/60 border border-slate-800 rounded-2xl p-6 space-y-3">
                        <span class="text-xs font-black font-mono text-emerald-400 bg-emerald-500/10 px-2.5 py-1 rounded-lg border border-emerald-500/20">03</span>
                        <h4 class="text-base font-bold text-white">Earn +9% & Resell Anytime</h4>
                        <p class="text-xs text-slate-400 leading-relaxed">Tokens plus your 9% bonus arrive in your unified wallet, ready to hold or list for sell to other peers.</p>
                    </div>
                </div>
            </div>

            <!-- Bottom Call to Action Banner -->
            <div class="bg-gradient-to-r from-indigo-900/60 via-slate-900 to-violet-900/60 border border-indigo-800/40 rounded-3xl p-8 sm:p-12 text-center space-y-4 shadow-xl">
                <h3 class="text-2xl sm:text-3xl font-black text-white">Ready to start trading tokens?</h3>
                <p class="text-sm text-slate-300 max-w-xl mx-auto">Create your account now and get immediate access to verified peer listings with guaranteed 9% purchase rewards.</p>
                <div class="pt-2">
                    <button onclick="openAuthModal('register')" class="px-8 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm">
                        Get Started Free
                    </button>
                </div>
            </div>
        </section>
        <?php else: ?>
        <!-- ========================================== -->
        <!-- TAB 1: HOME (Unified Dashboard & Shared Wallet Overview) -->
        <!-- ========================================== -->
        <section id="view-home" class="tab-view space-y-6">
            <!-- Shared Wallet Segregated Pools -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-3.5 sm:p-5 shadow-sm min-w-0 overflow-hidden flex flex-col justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-400 uppercase tracking-wider block truncate">Available Balance</span>
                    <p class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-extrabold text-emerald-400 tracking-tight mt-1 truncate" id="homeStatAvailable" title="₹0.00">₹0.00</p>
                    <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1 block truncate">Ready for transfer or sell</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-3.5 sm:p-5 shadow-sm min-w-0 overflow-hidden flex flex-col justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-400 uppercase tracking-wider block truncate">Reserved Balance</span>
                    <p class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-extrabold text-amber-400 tracking-tight mt-1 truncate" id="homeStatReserved" title="₹0.00">₹0.00</p>
                    <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1 block truncate">Locked in active sell orders</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-3.5 sm:p-5 shadow-sm min-w-0 overflow-hidden flex flex-col justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-400 uppercase tracking-wider block truncate">Total Rewards</span>
                    <p class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-extrabold text-indigo-400 tracking-tight mt-1 truncate" id="homeStatRewards" title="₹0.00">₹0.00</p>
                    <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1 block truncate">9% promotional credits</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-3.5 sm:p-5 shadow-sm min-w-0 overflow-hidden flex flex-col justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-400 uppercase tracking-wider block truncate">Commissions</span>
                    <p class="text-lg xs:text-xl sm:text-2xl lg:text-3xl font-extrabold text-violet-400 tracking-tight mt-1 truncate" id="homeStatCommissions" title="₹0.00">₹0.00</p>
                    <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1 block truncate">2-tier referral earnings</span>
                </div>
            </div>

            <!-- Weekly Milestone Target Progress -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <span class="text-xs font-bold text-indigo-400 uppercase tracking-wider block">Weekly Platform Milestone</span>
                        <h3 class="text-lg font-bold text-white">Target Trading Volume: ₹4,00,000.00</h3>
                    </div>
                    <div class="text-right">
                        <span class="text-2xl font-black text-white font-mono" id="homeWeeklyProgressPct">0.0%</span>
                        <span class="text-xs text-slate-500 block">Progress Completed</span>
                    </div>
                </div>
                <div class="w-full bg-slate-950 rounded-full h-3 overflow-hidden border border-slate-800">
                    <div id="homeWeeklyProgressBar" class="bg-gradient-to-r from-indigo-500 to-emerald-400 h-full rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex justify-between text-xs text-slate-500 font-mono">
                    <span>Your Completed Vol: <strong class="text-slate-300" id="homeStatCompletedVol">₹0.00</strong></span>
                    <span>Platform Target: ₹4,00,000.00</span>
                </div>
            </div>

            <!-- Home Active In-Flight Buy Order Banner (Part 8, Item 4) -->
            <div id="homeActiveOrderBanner" class="hidden bg-gradient-to-r from-indigo-950/80 via-slate-900 to-indigo-950/80 border border-indigo-500/30 rounded-2xl p-5 shadow-xl transition-all duration-300">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start sm:items-center gap-3.5">
                        <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center flex-shrink-0 animate-pulse">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Buy Order in Progress</span>
                                <span id="homeActiveOrderStatusBadge" class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20">Reserved</span>
                                <span id="homeActiveOrderTimerBadge" class="text-[11px] font-mono font-bold text-amber-300 bg-slate-950 px-2 py-0.5 rounded-lg border border-slate-800">--:--</span>
                            </div>
                            <h4 class="text-base font-bold text-white mt-0.5">Order <span id="homeActiveOrderId">#0000</span> &bull; <span id="homeActiveOrderAmount" class="font-mono text-emerald-400">₹0.00</span></h4>
                            <p id="homeActiveOrderSubtext" class="text-xs text-slate-400">SLA Timer running. Complete payment to secure tokens and +9% reward bonus.</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 sm:w-auto w-full">
                        <button type="button" id="homeActiveOrderCancelBtn" onclick="cancelOrderFromHomeBanner()" class="flex-1 sm:flex-none px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold rounded-xl transition">
                            Cancel
                        </button>
                        <button type="button" onclick="handleHomeActiveOrderView()" class="flex-1 sm:flex-none px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-indigo-600/30 transition flex items-center justify-center gap-1.5 whitespace-nowrap">
                            <span>View Status</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 2: BUY (Buy Pool, Custom Match & Purchases) -->
        <!-- ========================================== -->
        <section id="view-buy" class="tab-view space-y-6 hidden">
            <!-- Dedicated My Buy History - Compact Top Placement (Part 8, Item 1) -->
            <div id="buyPageHistorySection" class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 space-y-3 shadow-md">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Purchase History</span>
                            <span class="text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2.5 py-0.5 rounded-full font-mono font-semibold">+9% Rewards</span>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-white mt-0.5">My Buy Orders & Rewards</h3>
                    </div>
                    <button onclick="loadBuyPageHistory()" class="p-2 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-lg transition" title="Refresh Buy History">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
                <div id="buyPageHistoryContainer" class="overflow-x-auto max-h-72 overflow-y-auto bg-slate-950 border border-slate-800/80 rounded-xl">
                    <div class="text-slate-500 text-center py-6 text-xs">Loading buy history...</div>
                </div>
            </div>

            <!-- Custom Buy Allocation (Item 2) -->
            <div class="bg-gradient-to-r from-slate-900 via-indigo-950/40 to-slate-900 border border-slate-800 rounded-2xl p-5 sm:p-6 shadow-lg">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-5">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Custom Buy</span>
                            <span class="text-[11px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-2.5 py-0.5 rounded-full font-semibold">+9% Instant Reward</span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-white">Allocate Custom Token Amount</h2>
                        <p class="text-xs text-slate-400">Enter purchase amount in multiples of ₹100. System automatically splits or matches available sell portions.</p>
                    </div>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:w-auto w-full">
                        <div class="relative flex-1 sm:w-56">
                            <span class="absolute left-3 top-2.5 text-slate-400 font-bold">₹</span>
                            <input type="number" id="customBuyAmount" step="100" min="100" placeholder="5000" class="w-full bg-slate-950 border border-slate-700 rounded-xl pl-8 pr-3 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                        </div>
                        <button onclick="requestCustomAllocation()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-6 py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-indigo-600/30 whitespace-nowrap">
                            Match & Allocate
                        </button>
                    </div>
                </div>
            </div>

            <!-- Live Market Buy Pool Listing (FR-006) -->
            <div class="pt-2 border-t border-slate-800">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">List of Tokens</h3>
                    </div>
                    <button onclick="loadBuyPool()" class="p-2 text-slate-400 hover:text-white bg-slate-900 border border-slate-800 rounded-lg transition" title="Refresh Pool">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
                <div id="buyPoolGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 3: SELL (List Sell Order & Seller Tracking) -->
        <!-- ========================================== -->
        <section id="view-sell" class="tab-view space-y-6 hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Sell Creation Card -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div>
                        <h2 class="text-xl font-bold text-white">List Tokens for Sale</h2>
                    </div>

                    <div class="bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs flex justify-between items-center">
                        <div class="flex items-center gap-1.5">
                            <span class="text-slate-400">Health:</span>
                            <span class="text-emerald-400 font-mono font-bold text-xs bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20" id="sellHealthScoreBadge">100 / 100</span>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-400">Available:</span>
                            <span class="text-emerald-400 font-mono font-bold text-sm ml-1" id="sellFormAvailableBalance">₹0.00</span>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Sell Amount (₹)</label>
                            <input type="number" id="sellAmountInput" min="100" step="100" placeholder="10000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Min ₹100 &bull; Must be exact multiple of ₹100</span>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Account Holder Name</label>
                            <input type="text" id="sellAccountHolderName" placeholder="Full name as registered in UPI / Bank" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Name shown to buyers making direct UPI transfer</span>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">UPI ID</label>
                            <input type="text" id="sellUpiId" placeholder="e.g. mobile@upi or username@okaxis" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Direct UPI payment will be sent to this ID</span>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Secondary</label>
                            <input type="password" id="sellTpinInput" maxlength="6" placeholder="••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono tracking-widest focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Enter your 4–6 digit T-PIN to authorize</span>
                        </div>

                        <button onclick="submitSellOrder()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm">
                            Authorize & List Sell Order
                        </button>
                    </div>
                </div>

                <!-- Seller Dashboard & Order Splitting Overview (FR-014 / Part 7, Item 2) -->
                <div class="lg:col-span-2 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-white">Seller Tracking Dashboard</h3>
                            <p class="text-xs text-slate-400">Current running sell orders and completed listing history</p>
                        </div>
                        <button onclick="loadSellerDashboard()" class="p-2 text-slate-400 hover:text-white bg-slate-900 border border-slate-800 rounded-lg transition" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                    </div>

                    <!-- Clean Sell Tab Switcher: Running Orders vs Listing History (Part 7, Item 2) -->
                    <div class="flex border-b border-slate-800 gap-2">
                        <button onclick="switchSellTab('running')" id="sellTabBtn-running" class="px-4 py-2.5 text-xs font-bold border-b-2 border-indigo-500 text-white transition flex items-center gap-2">
                            <span>Running Sell Orders</span>
                            <span id="sellRunningBadge" class="bg-indigo-500/20 text-indigo-300 px-2 py-0.5 rounded-full font-mono text-[10px]">0</span>
                        </button>
                        <button onclick="switchSellTab('history')" id="sellTabBtn-history" class="px-4 py-2.5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-2">
                            <span>Listing History</span>
                            <span id="sellHistoryBadge" class="bg-slate-800 text-slate-400 px-2 py-0.5 rounded-full font-mono text-[10px]">0</span>
                        </button>
                    </div>

                    <!-- 1. Running Sell Orders (Active listings & pending portions) -->
                    <div id="sellerOrdersList" class="space-y-4">
                        <!-- Populated dynamically via JS -->
                    </div>

                    <!-- 2. Clean Dedicated Sell History (Completed / Expired listings) -->
                    <div id="sellerHistoryContainer" class="space-y-4 hidden">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 4: APPEALS & DISPUTE CENTER (Part 5, Item 1) -->
        <!-- ========================================== -->
        <section id="view-appeals" class="tab-view space-y-6 hidden">
            <!-- Header Banner -->
            <div class="bg-gradient-to-r from-slate-900 via-amber-950/20 to-slate-900 border border-slate-800 rounded-2xl p-5 sm:p-6 shadow-lg flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs uppercase font-bold text-amber-400 tracking-wider">Arbitration Center</span>
                        <span class="text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20 px-2.5 py-0.5 rounded-full font-semibold">Live Admin Arbitration</span>
                    </div>
                    <h2 class="text-xl sm:text-2xl font-bold text-white mt-1">Dispute & Appeal Center</h2>
                    <p class="text-xs text-slate-400 mt-1">Track raised claims, review administrative decisions, and submit photo or video evidence.</p>
                </div>
                <button onclick="loadDisputesCenter()" class="p-2.5 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-xl transition flex items-center gap-2 text-xs font-semibold self-start sm:self-auto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Refresh</span>
                </button>
            </div>

            <!-- Appeals Center List -->
            <div id="appealsCenterList" class="space-y-4">
                <!-- Populated dynamically via JS -->
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 5: REFER (2-Tier Referral Hub) -->
        <!-- ========================================== -->
        <section id="view-refer" class="tab-view space-y-6 hidden">
            <!-- Referral Header -->
            <div class="bg-gradient-to-r from-violet-950/70 to-slate-900 border border-violet-900/40 rounded-2xl p-6 sm:p-8 space-y-4">
                <div class="max-w-2xl space-y-2">
                    <span class="text-xs uppercase font-bold text-violet-400 tracking-wider">Multi-Tier Network</span>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white">Earn 2-Tier Referral Commissions</h2>
                    <p class="text-sm text-slate-400">
                        Earn <strong>1.0% Level 1</strong> direct commission whenever your invited peers complete a buy order, plus <strong>0.5% Level 2</strong> indirect commission on their invited networks. All commissions are credited to your single shared wallet!
                    </p>
                </div>

                <!-- Invite Link Copy Widget -->
                <div class="flex flex-col sm:flex-row items-stretch gap-2 max-w-xl">
                    <input type="text" id="inviteLinkInput" readonly class="bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-sm text-slate-300 font-mono flex-1 focus:outline-none">
                    <button onclick="copyInviteLink()" class="bg-violet-600 hover:bg-violet-500 text-white font-bold px-5 py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                        Copy Link
                    </button>
                </div>
            </div>

            <!-- Referral Network Statistics (Masked Phone Numbers) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Level 1 Members -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <div>
                            <h3 class="text-base font-bold text-white">Level 1 Direct Referrals (1.0%)</h3>
                            <p class="text-xs text-slate-500">Users who registered with your code</p>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-violet-500/20 text-violet-400 text-xs font-bold" id="l1Count">0 Users</span>
                    </div>
                    <div id="l1NetworkList" class="space-y-2 max-h-80 overflow-y-auto">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- Level 2 Members -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <div>
                            <h3 class="text-base font-bold text-white">Level 2 Indirect Referrals (0.5%)</h3>
                            <p class="text-xs text-slate-500">Users referred by your Level 1 peers</p>
                        </div>
                        <span class="px-2.5 py-1 rounded-full bg-indigo-500/20 text-indigo-400 text-xs font-bold" id="l2Count">0 Users</span>
                    </div>
                    <div id="l2NetworkList" class="space-y-2 max-h-80 overflow-y-auto">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 5: PROFILE (Account, TPIN, Bank Details & Complete History) -->
        <!-- ========================================== -->
        <section id="view-profile" class="tab-view space-y-6 hidden">
            <!-- 1. Profile Hero Summary Header Card -->
            <div class="relative overflow-hidden bg-gradient-to-r from-slate-900 via-indigo-950/40 to-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 border-2 border-indigo-400/40 flex items-center justify-center text-white text-2xl font-black shadow-lg shadow-indigo-600/30 flex-shrink-0" id="profileAvatarInitial">
                            <?= strtoupper(substr($currentUser['account_holder_name'] ?? ($currentUser['phone'] ?? 'U'), 0, 1)) ?>
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-xl sm:text-2xl font-extrabold text-white" id="profileNameDisplay"><?= htmlspecialchars($currentUser['account_holder_name'] ?? 'Verified Trader') ?></h2>
                                <span class="text-emerald-400 font-bold uppercase text-[10px] sm:text-[11px] bg-emerald-500/10 px-2.5 py-0.5 rounded-full border border-emerald-500/20" id="profileStatusDisplay"><?= htmlspecialchars($currentUser['account_status'] === 'suspended' ? 'Suspended' : 'Active Verified') ?></span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-slate-400 flex-wrap">
                                <span class="flex items-center gap-1 font-mono text-slate-300">
                                    <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    <span id="profilePhoneDisplay"><?= htmlspecialchars($currentUser['phone'] ?? '-') ?></span>
                                </span>
                                <span class="text-slate-600">&bull;</span>
                                <span class="flex items-center gap-1 text-slate-400">
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    <span id="profileEmailDisplay"><?= htmlspecialchars($currentUser['email'] ?? 'Email not linked') ?></span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 sm:gap-4 flex-wrap sm:flex-nowrap">
                        <!-- Health Score Badge -->
                        <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-3 sm:px-4 sm:py-3 text-center min-w-[110px]">
                            <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold block">Health Score</span>
                            <span class="font-mono font-black text-sm text-emerald-400 mt-0.5 block" id="profileHealthScoreDisplay"><?= (int)($currentUser['health_score'] ?? 100) ?> / 100</span>
                        </div>

                        <!-- Referral Code Quick Copy -->
                        <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-3 sm:px-4 sm:py-3 text-center min-w-[130px]">
                            <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold block">Referral Code</span>
                            <div class="flex items-center justify-center gap-1.5 mt-0.5">
                                <span class="font-mono font-bold text-sm text-indigo-400" id="profileRefCodeDisplay"><?= htmlspecialchars($currentUser['referral_code'] ?? '-') ?></span>
                                <button type="button" onclick="copyReferralCode()" class="text-slate-400 hover:text-white transition p-0.5" title="Copy Referral Code">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Main 2x2 Grid: Banking Details, Account Info, Selling TPIN, Change Password -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- CARD A: Banking & UPI Payout Details (Direct Seller Payouts) -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                            <div>
                                <span class="text-xs uppercase font-bold text-emerald-400 tracking-wider">Direct Payouts</span>
                                <h3 class="text-lg font-bold text-white mt-1">Bank & UPI Payout Details</h3>
                                <p class="text-xs text-slate-400">Buyers will transfer funds directly to this UPI ID when you sell tokens</p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </div>
                        </div>

                        <form onsubmit="handleProfileUpdate(event); return false;" class="space-y-4 mt-4">
                            <div>
                                <label class="text-xs font-semibold text-slate-300 block mb-1">Account Holder Full Name</label>
                                <input type="text" id="profHolderName" required placeholder="e.g. Rahul Sharma" value="<?= htmlspecialchars($currentUser['account_holder_name'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                <span class="text-[11px] text-slate-500 mt-1 block">Must match the registered name on your UPI banking app.</span>
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-slate-300 block mb-1">UPI ID / VPA Address</label>
                                <input type="text" id="profUpiId" required placeholder="e.g. rahul@okaxis, mobile@upi" value="<?= htmlspecialchars($currentUser['upi_id'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-emerald-500 focus:outline-none transition">
                                <span class="text-[11px] text-slate-500 mt-1 block">Your valid Virtual Payment Address (contains '@').</span>
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-slate-300 block mb-1">Email Address (Optional)</label>
                                <input type="email" id="profEmail" placeholder="e.g. trader@example.com" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                <span class="text-[11px] text-slate-500 mt-1 block">For order alerts, dispute notices, and account security.</span>
                            </div>

                            <div id="profUpdateStatus" class="text-xs font-semibold hidden"></div>

                            <button type="submit" id="profUpdateBtn" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-emerald-600/20">
                                Save Payout Details
                            </button>
                        </form>
                    </div>
                </div>

                <!-- CARD B: Account Overview & Referral Details -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                            <div>
                                <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Account Overview</span>
                                <h3 class="text-lg font-bold text-white mt-1">Identity & Referral Hub</h3>
                                <p class="text-xs text-slate-400">Single unified identity for token trading, commissions, and rewards</p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                        </div>

                        <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 text-xs space-y-3 mt-4">
                            <div class="flex justify-between items-center text-slate-400 border-b border-slate-800/80 pb-2.5">
                                <span>User ID:</span>
                                <span class="text-white font-mono font-bold" id="profileUserIdDisplay">#<?= htmlspecialchars($currentUser['id'] ?? '-') ?></span>
                            </div>
                            <div class="flex justify-between items-center text-slate-400 border-b border-slate-800/80 pb-2.5">
                                <span>Registered Phone:</span>
                                <span class="text-white font-mono font-bold"><?= htmlspecialchars($currentUser['phone'] ?? '-') ?></span>
                            </div>
                            <div class="flex justify-between items-center text-slate-400 border-b border-slate-800/80 pb-2.5">
                                <span>Referral Code:</span>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-indigo-400 font-mono font-bold"><?= htmlspecialchars($currentUser['referral_code'] ?? '-') ?></span>
                                    <button type="button" onclick="copyReferralCode()" class="text-slate-400 hover:text-white transition" title="Copy">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="flex justify-between items-center text-slate-400 border-b border-slate-800/80 pb-2.5">
                                <span>Account Health Score:</span>
                                <span class="font-mono font-bold text-xs px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">100 / 100</span>
                            </div>
                            <div class="flex justify-between items-center text-slate-400">
                                <span>Platform Role:</span>
                                <span class="text-indigo-400 font-bold uppercase text-[11px] bg-indigo-500/10 px-2 py-0.5 rounded-full border border-indigo-500/20"><?= htmlspecialchars(strtoupper($currentUser['role'] ?? 'USER')) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button onclick="switchTab('refer')" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition border border-slate-700 flex items-center justify-center gap-2">
                            <span>Open 2-Tier Referral Dashboard</span>
                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </button>
                    </div>
                </div>

                <!-- CARD C: Selling TPIN Configuration -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                            <div>
                                <span class="text-xs uppercase font-bold text-amber-400 tracking-wider">Security Protection</span>
                                <h3 class="text-lg font-bold text-white mt-1" id="profileTpinHeader">Selling Transaction PIN (TPIN)</h3>
                                <p class="text-xs text-slate-400">Secondary 4–6 digit PIN required to authorize every sell order listing</p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            </div>
                        </div>

                        <form onsubmit="event.preventDefault(); handleTpinUpdate(event); return false;" class="space-y-4 mt-4">
                            <div>
                                <label class="text-xs font-semibold text-slate-300 block mb-1" id="profileTpinLabel">Set New Selling TPIN (4-6 Digits)</label>
                                <input type="password" id="profNewTpin" required maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono tracking-widest focus:border-amber-500 focus:outline-none transition">
                                <span class="text-[11px] text-slate-500 mt-1 block">Bcrypt hashed. Never stored in plaintext. Required to create sell orders.</span>
                            </div>

                            <div id="profTpinStatus" class="text-xs font-semibold hidden"></div>

                            <button type="button" onclick="handleTpinUpdate(event)" id="profileTpinBtn" class="w-full bg-amber-600 hover:bg-amber-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-amber-600/20">
                                Save Selling TPIN
                            </button>
                        </form>
                    </div>
                </div>

                <!-- CARD D: Change Password Configuration -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                            <div>
                                <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Security Credentials</span>
                                <h3 class="text-lg font-bold text-white mt-1">Change Account Password</h3>
                                <p class="text-xs text-slate-400">Update your sign-in password to keep your unified wallet secure</p>
                            </div>
                            <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            </div>
                        </div>

                        <form onsubmit="event.preventDefault(); handlePasswordChange(event); return false;" class="space-y-3 mt-4">
                            <div>
                                <label class="text-xs font-semibold text-slate-300 block mb-1">Current Password</label>
                                <input type="password" id="currentPasswordInput" required placeholder="••••••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none transition">
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs font-semibold text-slate-300 block mb-1">New Password</label>
                                    <input type="password" id="newPasswordInput" required minlength="6" placeholder="Min 6 chars" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none transition">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-300 block mb-1">Confirm New Password</label>
                                    <input type="password" id="confirmPasswordInput" required minlength="6" placeholder="Re-enter password" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none transition">
                                </div>
                            </div>
                            <div id="changePasswordStatus" class="text-xs font-semibold hidden"></div>
                            <button type="submit" id="changePasswordBtn" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-indigo-600/30">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- 3. Complete Wallet Audit & Dedicated History Hub Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
                <div class="space-y-1">
                    <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Accounting & Verification</span>
                    <h3 class="text-base sm:text-lg font-bold text-white">Complete Wallet Audit & Transaction History</h3>
                    <p class="text-xs text-slate-400">Review all historical credits, debits, purchase orders, sell portions, and escrow settlements</p>
                </div>
                <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap">
                    <button type="button" onclick="openHistoryModal('buy')" class="text-slate-300 hover:text-white text-xs font-bold px-3 py-2 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800 transition">
                        Buy History
                    </button>
                    <button type="button" onclick="openHistoryModal('sell')" class="text-slate-300 hover:text-white text-xs font-bold px-3 py-2 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800 transition">
                        Sell History
                    </button>
                    <button type="button" onclick="openHistoryModal('ledger')" class="text-indigo-400 hover:text-indigo-300 text-xs font-bold flex items-center gap-1.5 hover:underline px-3.5 py-2 rounded-xl bg-indigo-500/10 border border-indigo-500/20 transition">
                        <span>Check Wallet Audit</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
            </div>

            <!-- 4. Progressive Web App Install Card (Part 9, Item 3) -->
            <div id="pwaProfileCard" class="bg-gradient-to-br from-slate-900 to-indigo-950/40 border border-indigo-500/30 rounded-3xl p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-sm">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center text-indigo-400 flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">Install <?= $siteName ?> Mobile App</h3>
                        <p class="text-xs text-slate-400">Add to your device home screen for fast native app experience & trade notifications</p>
                    </div>
                </div>
                <button type="button" onclick="triggerPwaInstall()" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-4 py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-indigo-600/30 whitespace-nowrap flex items-center justify-center gap-1.5 flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Install App</span>
                </button>
            </div>

            <!-- 5. Customer Support & Helpdesk Hub (Part 8, Item 3) -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-6 space-y-4 shadow-sm">
                <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                    <div>
                        <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Help & Assistance</span>
                        <h3 class="text-lg font-bold text-white mt-0.5">Official Customer Support</h3>
                        <p class="text-xs text-slate-400">Direct assistance for payments, escrow arbitration, or account inquiries</p>
                    </div>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse hidden sm:block" title="Support Online"></span>
                </div>
                <div class="pt-1">
                    <a href="<?= $telegramSupportUrl ?>" target="_blank" rel="noopener noreferrer" class="bg-[#229ED9]/15 hover:bg-[#229ED9]/25 text-[#229ED9] border border-[#229ED9]/30 rounded-2xl p-4 flex items-center justify-between transition group">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#229ED9]/20 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.52 2.77-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-white">Telegram Support Channel</h4>
                                <p class="text-xs text-slate-400">Join official channel & contact verified support agents</p>
                            </div>
                        </div>
                        <span class="text-sm font-bold text-slate-400 group-hover:text-white">&rarr;</span>
                    </a>
                </div>
            </div>

            <!-- 6. Clean Logout Button at Very Bottom -->
            <div class="pt-2">
                <button type="button" onclick="logout()" class="w-full bg-slate-900 hover:bg-red-500/20 text-red-400 hover:text-red-300 border border-slate-800 hover:border-red-500/40 font-bold py-3.5 rounded-2xl transition flex items-center justify-center gap-2 text-sm shadow-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span>Sign Out of Account</span>
                </button>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 6: ADMIN PANEL (Arbitration, Impersonation, Settings) -->
        <!-- ========================================== -->
        <?php if ($currentUser && ($currentUser['role'] === 'admin' || !empty($currentUser['real_admin_id']))): ?>
        <section id="view-admin" class="tab-view space-y-6 hidden">
            <!-- Admin Top Macro Metrics (FR-020) -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Registered Users</span>
                    <p class="text-2xl sm:text-3xl font-black text-white font-mono mt-1" id="adminTotalUsers">0</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Active on platform</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Tokens in Circulation</span>
                    <p class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono mt-1" id="adminCirculating">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Available + Reserved</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Completed Trading Vol</span>
                    <p class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono mt-1" id="adminTradingVol">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">All settled transactions</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                    <span class="text-xs font-bold text-amber-400 uppercase tracking-wider block">Pending Disputes</span>
                    <p class="text-2xl sm:text-3xl font-black text-amber-400 font-mono mt-1" id="adminPendingAppeals">0</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Awaiting arbitration</span>
                </div>
            </div>

            <!-- Admin Sub-Navigation -->
            <div class="flex space-x-2 border-b border-slate-800 pb-3 text-xs font-bold uppercase tracking-wider">
                <button onclick="switchAdminSection('disputes')" id="adminSecBtn-disputes" class="admin-sec-btn px-3 py-1.5 rounded-lg bg-amber-500/20 text-amber-400 border border-amber-500/30">
                    Dispute Arbitration (FR-018)
                </button>
                <button onclick="switchAdminSection('users')" id="adminSecBtn-users" class="admin-sec-btn px-3 py-1.5 rounded-lg text-slate-400 hover:text-white">
                    User Accounts & Support Mode (FR-022)
                </button>
                <button onclick="switchAdminSection('adjustments')" id="adminSecBtn-adjustments" class="admin-sec-btn px-3 py-1.5 rounded-lg text-slate-400 hover:text-white">
                    Manual Ledger Adjustment (FR-021)
                </button>
                <button onclick="switchAdminSection('settings')" id="adminSecBtn-settings" class="admin-sec-btn px-3 py-1.5 rounded-lg text-slate-400 hover:text-white">
                    Platform Settings (FR-023)
                </button>
                <button onclick="switchAdminSection('audit')" id="adminSecBtn-audit" class="admin-sec-btn px-3 py-1.5 rounded-lg text-slate-400 hover:text-white">
                    Audit Logs
                </button>
            </div>

            <!-- Admin Section 1: Disputes Arbitration (FR-018) -->
            <div id="adminSec-disputes" class="admin-section space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Dispute & Appeal Arbitration Console</h3>
                        <p class="text-xs text-slate-400">Review buyer UTR & screenshot evidence against seller appeals to force settlement or reversal</p>
                    </div>
                    <button onclick="loadAdminDisputes()" class="p-2 text-slate-400 hover:text-white bg-slate-900 border border-slate-800 rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
                <div id="adminDisputesList" class="space-y-4">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <!-- Admin Section 2: Users Management & Impersonation (FR-022) -->
            <div id="adminSec-users" class="admin-section space-y-4 hidden">
                <h3 class="text-lg font-bold text-white">User Accounts & Read-Only Support Inspection</h3>
                <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800">
                            <tr>
                                <th class="p-3">ID</th>
                                <th class="p-3">Phone</th>
                                <th class="p-3">Holder Name & UPI</th>
                                <th class="p-3">Available Balance</th>
                                <th class="p-3">Reserved</th>
                                <th class="p-3">Status</th>
                                <th class="p-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="adminUsersTableBody" class="divide-y divide-slate-800/60 font-mono text-slate-300">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Admin Section 3: Manual Ledger Adjustment (FR-021) -->
            <div id="adminSec-adjustments" class="admin-section space-y-4 hidden">
                <div class="max-w-xl bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">Manual Ledger Adjustment</h3>
                        <p class="text-xs text-slate-400">Debit or credit user's available token balance with mandatory audit reason logging</p>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Target User ID</label>
                            <input type="number" id="adjUserId" placeholder="e.g. 2" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Adjustment Type</label>
                            <select id="adjType" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                                <option value="credit">Credit (Deposit to Available Balance)</option>
                                <option value="debit">Debit (Deduct from Available Balance)</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Amount (₹)</label>
                            <input type="number" id="adjAmount" step="1" min="1" placeholder="1000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Mandatory Audit Reason</label>
                            <textarea id="adjReason" rows="3" placeholder="Provide detailed rationale for audit trail..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-sm text-white focus:border-indigo-500 focus:outline-none"></textarea>
                        </div>
                        <button onclick="submitManualAdjustment()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl transition text-sm">
                            Execute & Audit Adjustment
                        </button>
                    </div>
                </div>
            </div>

            <!-- Admin Section 4: Global Settings (FR-023) -->
            <div id="adminSec-settings" class="admin-section space-y-4 hidden">
                <div>
                    <h3 class="text-lg font-bold text-white">Platform Global Configuration Switches</h3>
                    <p class="text-xs text-slate-400">Master platform variables (changes apply to future orders; inflight orders remain snapshotted)</p>
                </div>
                <div id="adminSettingsGrid" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <!-- Admin Section 5: Audit Trail -->
            <div id="adminSec-audit" class="admin-section space-y-4 hidden">
                <h3 class="text-lg font-bold text-white">Immutable Administrator Audit Trail</h3>
                <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800">
                            <tr>
                                <th class="p-3">Log ID</th>
                                <th class="p-3">Admin</th>
                                <th class="p-3">Action</th>
                                <th class="p-3">Entity & ID</th>
                                <th class="p-3">Reason</th>
                                <th class="p-3">Timestamp (UTC)</th>
                            </tr>
                        </thead>
                        <tbody id="adminAuditTableBody" class="divide-y divide-slate-800/60 font-mono text-slate-300">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
        <?php endif; ?>

    </main>

    <!-- ======================================================== -->
    <!-- MODALS & POPUPS -->
    <!-- ======================================================== -->

    <!-- 1. Authentication Modal (Login / Register) -->
    <div id="authModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative">
            <button onclick="closeAuthModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="text-center mb-6">
                <h3 id="authModalTitle" class="text-2xl font-black text-white">Sign In</h3>
                <p id="authModalSubtitle" class="text-xs text-slate-400 mt-1">Access your unified P2P token wallet</p>
            </div>

            <form id="authForm" onsubmit="handleAuthSubmit(event)" class="space-y-4">
                <input type="hidden" id="authMode" value="login">

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Phone Number</label>
                    <input type="tel" id="authPhone" required placeholder="Enter 10-digit mobile number" maxlength="10" pattern="[0-9]{10}" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Password</label>
                    <input type="password" id="authPassword" required placeholder="••••••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                </div>

                <!-- Registration-only fields -->
                <div id="registerFields" class="space-y-4 hidden">
                    <div>
                        <label class="text-xs font-semibold text-slate-300 block mb-1">Referral Code (Optional)</label>
                        <input type="text" id="authReferralCode" value="<?= $refFromQuery ?>" placeholder="JPXXXXXX" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono uppercase focus:border-indigo-500 focus:outline-none">
                    </div>
                </div>

                <div id="authError" class="text-xs text-red-400 font-semibold hidden"></div>

                <button type="submit" id="authSubmitBtn" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm">
                    Continue
                </button>
            </form>

            <div class="mt-6 text-center text-xs text-slate-400">
                <span id="authToggleText">Don't have an account?</span>
                <button type="button" onclick="toggleAuthMode()" class="text-indigo-400 font-bold hover:underline ml-1" id="authToggleBtn">
                    Create Account
                </button>
            </div>
        </div>
    </div>

    <!-- 1B. Dynamic Notification for Active Orders (Part 7, Item 1 - Smooth Animated Alert) -->
    <div id="activeOrderNoticeModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 transition-all duration-300 opacity-0 pointer-events-none">
        <div id="activeOrderNoticeCard" class="bg-gradient-to-b from-slate-900 to-slate-950 border border-amber-500/40 rounded-3xl max-w-md w-full p-6 sm:p-7 shadow-2xl relative space-y-4 transform scale-95 translate-y-3 transition-all duration-300">
            <button onclick="closeActiveOrderNotice()" class="absolute right-4 top-4 text-slate-400 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-bold uppercase tracking-wider">
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
                    <span>Active Order in Progress</span>
                </span>
            </div>
            <div>
                <h3 class="text-xl font-black text-white">Order Already Active</h3>
                <p class="text-xs text-slate-400 mt-1 leading-relaxed" id="activeOrderNoticeMsg">
                    You have an ongoing buy order in progress. Platform limits one active order at a time to ensure guaranteed liquidity and swift settlements.
                </p>
            </div>

            <!-- Compact Active Order Summary Card -->
            <div class="bg-slate-950 border border-slate-800/80 rounded-2xl p-4 space-y-2.5 text-xs font-mono">
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-sans">Active Order ID:</span>
                    <strong class="text-indigo-400 font-bold" id="activeNoticeOrderId">#0000</strong>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-sans">Current Status:</span>
                    <span class="px-2 py-0.5 rounded font-bold text-[11px] bg-amber-500/10 text-amber-400 border border-amber-500/20" id="activeNoticeStatus">Reserved</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-500 font-sans">Order Amount:</span>
                    <strong class="text-white font-bold" id="activeNoticeAmount">₹0.00</strong>
                </div>
            </div>

            <!-- Action Buttons: Review Status vs Dismiss (Part 7, Item 1) -->
            <div class="grid grid-cols-2 gap-3 pt-2">
                <button type="button" onclick="closeActiveOrderNotice()" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 rounded-xl transition text-xs">
                    Keep Browsing
                </button>
                <button type="button" onclick="handleActiveNoticeReview()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition text-xs flex items-center justify-center gap-1.5">
                    <span>View Status</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- 1C. Active Order Transparent Details & Reopen Modal (Part 7, Item 1) -->
    <div id="activeOrderDetailsModal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 transition-all duration-300 opacity-0 pointer-events-none">
        <div id="activeOrderDetailsCard" class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 sm:p-7 shadow-2xl relative space-y-4 transform scale-95 translate-y-3 transition-all duration-300">
            <button onclick="closeActiveOrderDetailsModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex items-center justify-between border-b border-slate-800 pb-3 pr-8">
                <div>
                    <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Transaction Status</span>
                    <h3 class="text-xl font-bold text-white mt-0.5">Order <span id="activeDetailsOrderId">#0000</span></h3>
                    <p class="text-xs text-slate-400">Current progress and options for your in-flight transaction</p>
                </div>
                <div class="text-right bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 flex-shrink-0" id="activeDetailsTimerBox">
                    <span class="text-[10px] text-slate-400 uppercase font-semibold block leading-none" id="activeDetailsTimerLabel">Time Left</span>
                    <span class="text-sm font-black text-amber-400 font-mono" id="activeDetailsCountdown">--:--</span>
                </div>
            </div>

            <!-- Status Details Card -->
            <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 space-y-3 text-xs">
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Order Amount:</span>
                    <span class="text-white font-mono font-bold text-sm" id="activeDetailsAmount">₹0.00</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Purchase Reward (+9%):</span>
                    <span class="text-emerald-400 font-mono font-bold" id="activeDetailsReward">+₹0.00</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-400">Current Order State:</span>
                    <span class="px-2.5 py-0.5 rounded text-[11px] font-bold" id="activeDetailsStatusBadge">Reserved</span>
                </div>
                <div class="pt-2 border-t border-slate-800/80 space-y-1.5">
                    <span class="text-slate-400 uppercase text-[10px] font-bold tracking-wider block">Seller UPI Details:</span>
                    <div class="flex items-center justify-between bg-slate-900 border border-slate-800 rounded-xl p-2.5">
                        <span class="font-mono text-emerald-400 font-bold select-all text-xs" id="activeDetailsSellerUpi">--</span>
                        <button onclick="copyToClipboard(document.getElementById('activeDetailsSellerUpi').innerText)" class="text-[11px] bg-slate-800 hover:bg-slate-700 px-2.5 py-1 rounded-lg text-slate-300">Copy</button>
                    </div>
                    <p class="text-[11px] text-slate-500">Account Holder: <strong class="text-slate-300" id="activeDetailsSellerName">--</strong></p>
                </div>
            </div>

            <div id="activeDetailsInfoBox" class="text-xs text-slate-400 leading-relaxed bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-3">
                <!-- Informative message set dynamically -->
            </div>

            <!-- Dynamic Action Buttons (Part 7, Item 1) -->
            <div id="activeDetailsActionButtons" class="grid grid-cols-2 gap-3 pt-1">
                <!-- Populated dynamically via JS -->
            </div>
        </div>
    </div>

    <!-- 2. Payment Proof Submission Modal (FR-009 / Item 4) -->
    <div id="proofModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeProofModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex items-center justify-between border-b border-slate-800 pb-3 pr-8">
                <div>
                    <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Step 2 of 2</span>
                    <h3 class="text-xl font-bold text-white mt-0.5">Submit UPI Payment Proof</h3>
                    <p class="text-xs text-slate-400">Order #<span id="proofModalOrderId"></span> &bull; Amount: <span id="proofModalAmount" class="font-bold text-white font-mono"></span></p>
                </div>
                <!-- 10-Minute Side Timer (Item 4) -->
                <div class="text-right bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 flex-shrink-0">
                    <span class="text-[10px] text-slate-400 uppercase font-semibold block leading-none">Time Left</span>
                    <span class="text-sm font-black text-amber-400 font-mono" id="proofModalCountdown">10:00</span>
                </div>
            </div>

            <!-- Seller Payment Target Box -->
            <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 space-y-2">
                <span class="text-xs font-semibold text-slate-400 block uppercase tracking-wider">Pay to Seller UPI:</span>
                <div class="flex items-center justify-between">
                    <span id="proofModalSellerUpi" class="text-sm font-bold font-mono text-emerald-400 select-all"></span>
                    <button onclick="copyToClipboard(document.getElementById('proofModalSellerUpi').innerText)" class="text-xs bg-slate-800 hover:bg-slate-700 px-2 py-1 rounded text-slate-300">Copy</button>
                </div>
                <div class="text-[11px] text-slate-400">
                    Account Holder: <strong class="text-slate-200" id="proofModalSellerName"></strong>
                </div>
            </div>

            <form id="proofForm" onsubmit="handleProofSubmit(event)" class="space-y-4">
                <input type="hidden" id="proofOrderIdInput">
                <input type="hidden" id="proofScreenshotPath">

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Bank UTR / Reference Number</label>
                    <input type="text" id="proofUtrInput" required placeholder="e.g. 123456789012" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                    <span class="text-[11px] text-slate-500 mt-1 block">Globally unique 8-35 character bank reference</span>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Payment Screenshot Proof (Select One or Multiple Files)</label>
                    <input type="file" id="proofFileInput" accept="image/*,.pdf" multiple required onchange="handleFileUpload(this, 'proof')" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500">
                    <span id="uploadStatusText" class="text-[11px] text-slate-500 mt-1 block">Select clear screenshots showing UTR and success status</span>
                </div>

                <div id="proofError" class="text-xs text-red-400 font-semibold hidden"></div>

                <!-- Two Distinct Action Buttons (Item 4) -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <button type="button" onclick="cancelCurrentReservation()" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 rounded-xl transition text-sm">
                        Cancel
                    </button>
                    <button type="submit" id="proofSubmitBtn" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-600/30 transition text-sm flex items-center justify-center text-center">
                        Submit and Send Proof to Seller
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. Seller Confirmation Pop-up Modal ("Confirmation Please" - Item 1) -->
    <div id="confirmationModal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-amber-500/40 rounded-3xl max-w-lg w-full p-6 sm:p-7 shadow-2xl relative space-y-5">
            <div class="flex items-start justify-between border-b border-slate-800 pb-4">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-2xl bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-400 flex-shrink-0">
                        <svg class="w-6 h-6 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-black text-white">Confirmation Please</h3>
                        <p class="text-xs text-amber-300">Buyer has uploaded payment proof</p>
                    </div>
                </div>
                <!-- 15-minute Seller Verification Timer (Item 1) -->
                <div class="text-right bg-slate-950 border border-slate-800 rounded-xl px-3 py-1.5 flex-shrink-0">
                    <span class="text-[10px] text-slate-400 uppercase font-semibold block leading-none">Time Left</span>
                    <span class="text-sm font-black text-amber-400 font-mono" id="confirmModalTimer">15:00</span>
                </div>
            </div>

            <!-- Order Details -->
            <div class="bg-slate-950 border border-slate-800 rounded-2xl p-4 space-y-3 text-xs">
                <div class="flex justify-between items-center text-slate-400">
                    <span>Order Portion:</span>
                    <span class="text-white font-mono font-bold" id="confirmModalOrderId">#--</span>
                </div>
                <div class="flex justify-between items-center text-slate-400">
                    <span>Amount Received (₹):</span>
                    <span class="text-emerald-400 font-mono font-black text-base" id="confirmModalAmount">₹0.00</span>
                </div>
                <div class="flex justify-between items-center text-slate-400">
                    <span>Submitted UTR:</span>
                    <span class="text-white font-mono font-bold select-all bg-slate-900 px-2 py-0.5 rounded border border-slate-800" id="confirmModalUtr">--</span>
                </div>
                <div class="flex flex-col gap-1 text-slate-400 pt-2 border-t border-slate-800">
                    <span>Buyer Proof Screenshots:</span>
                    <div id="confirmModalProofsContainer" class="flex gap-2 flex-wrap pt-1">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>

            <div class="text-[11px] text-slate-400 leading-relaxed bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-3">
                ⚠️ <strong class="text-slate-200">Important:</strong> Check your bank account or UPI statement directly. If credited, click 'Receipt'. If payment is not received, click 'Appeal' to upload bank statements or video proof.
            </div>

            <!-- Action Buttons: Receipt vs Appeal (Item 1) -->
            <div class="grid grid-cols-2 gap-3 pt-1">
                <button type="button" onclick="handleConfirmationAppeal()" class="w-full bg-red-600/90 hover:bg-red-500 text-white font-bold py-3 rounded-xl transition text-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span>Appeal</span>
                </button>
                <button type="button" onclick="handleConfirmationReceipt()" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-600/30 transition text-sm flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Receipt</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 4. Dispute Appeal Modal (FR-016 / Item 1 - Video, Statements, PDF Proofs) -->
    <div id="appealModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeAppealModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <span class="text-xs uppercase font-bold text-red-400 tracking-wider">Dispute Filing</span>
                <h3 class="text-xl font-bold text-white mt-1">Payment Not Received</h3>
                <p class="text-xs text-slate-400 mt-1">Order #<span id="appealOrderId"></span> &bull; Upload statement, video, or PDF proof for Admin arbitration</p>
            </div>

            <form id="appealForm" onsubmit="handleAppealSubmit(event)" class="space-y-4">
                <input type="hidden" id="appealOrderIdInput">
                <input type="hidden" id="appealEvidencePath">

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Reason for Appeal</label>
                    <textarea id="appealReasonInput" required rows="3" placeholder="Explain why payment was not received (e.g. Bank statement checked, no credit received)..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-sm text-white focus:border-red-500 focus:outline-none"></textarea>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Bank Statement / Video / PDF Evidence (Optional)</label>
                    <input type="file" id="appealFileInput" accept="image/*,video/*,.pdf" onchange="handleFileUpload(this, 'evidence')" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-600 file:text-white hover:file:bg-red-500">
                    <span id="appealUploadStatusText" class="text-[11px] text-slate-500 mt-1 block">Attach recent bank statement, video recording, or PDF proof</span>
                </div>

                <div id="appealError" class="text-xs text-red-400 font-semibold hidden"></div>

                <button type="submit" id="appealSubmitBtn" class="w-full bg-red-600 hover:bg-red-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-red-600/30 transition text-sm">
                    Freeze Order & Escalate to Admin
                </button>
            </form>
        </div>
    </div>

    <!-- 5. Dedicated Transaction History Modal (Part 5, Item 4) -->
    <div id="historyModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full p-6 sm:p-8 shadow-2xl relative space-y-4 max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div>
                    <h3 class="text-xl font-bold text-white">Transaction History</h3>
                    <p class="text-xs text-slate-400">Dedicated logs for buy purchases, sell portions, and wallet audits</p>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="refreshCurrentHistoryTab()" class="p-2 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-xl transition" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button onclick="closeHistoryModal()" class="p-2 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-xl transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- Tab Switcher (Buy History | Sell History | Wallet Audit) -->
            <div class="flex border-b border-slate-800 gap-1 sm:gap-2">
                <button onclick="switchHistoryTab('buy')" id="historyTabBtn-buy" class="px-3 sm:px-4 py-2 text-xs font-bold border-b-2 border-indigo-500 text-white transition flex items-center gap-1.5">
                    <span>Buy History</span>
                </button>
                <button onclick="switchHistoryTab('sell')" id="historyTabBtn-sell" class="px-3 sm:px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5">
                    <span>Sell History</span>
                </button>
                <button onclick="switchHistoryTab('ledger')" id="historyTabBtn-ledger" class="px-3 sm:px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5">
                    <span>Wallet Audit</span>
                </button>
            </div>

            <!-- Tab Contents -->
            <div class="overflow-y-auto flex-1">
                <!-- 1. Dedicated Buy History (Part 5, Item 4) -->
                <div id="historyView-buy" class="space-y-3">
                    <div class="text-slate-500 text-center py-8">Loading buy history...</div>
                </div>

                <!-- 2. Dedicated Sell History (Part 5, Item 4) -->
                <div id="historyView-sell" class="space-y-3 hidden">
                    <div class="text-slate-500 text-center py-8">Loading sell history...</div>
                </div>

                <!-- 3. Wallet Audit Ledger -->
                <div id="historyView-ledger" class="hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800 sticky top-0">
                            <tr>
                                <th class="p-3">ID</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Amount</th>
                                <th class="p-3">Balance Progression</th>
                                <th class="p-3">Order / Ref</th>
                                <th class="p-3">Timestamp (UTC)</th>
                            </tr>
                        </thead>
                        <tbody id="modalLedgerTableBody" class="divide-y divide-slate-800/60 font-mono text-slate-300">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($currentUser): ?>
    <!-- MOBILE APP BOTTOM NAVIGATION BAR (Fixed at bottom) -->
    <nav id="mobileBottomNav" class="fixed bottom-0 left-0 right-0 z-50 bg-slate-950/95 backdrop-blur-xl border-t border-slate-800/90 shadow-[0_-8px_30px_rgba(0,0,0,0.6)]">
        <div class="max-w-md md:max-w-xl mx-auto px-2 sm:px-6">
            <div class="flex items-center justify-around h-16">
                <!-- 1. Home -->
                <button onclick="switchTab('home')" id="tabBtn-home" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-indigo-400 group">
                    <div class="p-1 rounded-xl group-hover:bg-indigo-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Home</span>
                </button>

                <!-- 2. Buy -->
                <button onclick="switchTab('buy')" id="tabBtn-buy" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-slate-400 hover:text-slate-200 group">
                    <div class="p-1 rounded-xl group-hover:bg-indigo-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Buy</span>
                </button>

                <!-- 3. Sell -->
                <button onclick="switchTab('sell')" id="tabBtn-sell" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-slate-400 hover:text-slate-200 group">
                    <div class="p-1 rounded-xl group-hover:bg-indigo-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Sell</span>
                </button>

                <!-- 4. Appeals & Disputes (Part 5, Item 1) -->
                <button onclick="switchTab('appeals')" id="tabBtn-appeals" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-slate-400 hover:text-slate-200 group relative">
                    <div class="p-1 rounded-xl group-hover:bg-amber-500/10 transition relative">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                        <span id="bottomAppealsDot" class="absolute top-0 right-0 w-2 h-2 rounded-full bg-amber-400 hidden"></span>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Appeals</span>
                </button>

                <!-- 5. Refer -->
                <button onclick="switchTab('refer')" id="tabBtn-refer" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-slate-400 hover:text-slate-200 group">
                    <div class="p-1 rounded-xl group-hover:bg-indigo-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Refer</span>
                </button>

                <!-- 6. Profile -->
                <button onclick="switchTab('profile')" id="tabBtn-profile" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-slate-400 hover:text-slate-200 group">
                    <div class="p-1 rounded-xl group-hover:bg-indigo-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Profile</span>
                </button>

                <?php if ($isAdminUser): ?>
                <!-- Admin Console -->
                <button onclick="switchTab('admin')" id="tabBtn-admin" class="tab-btn flex-1 flex flex-col items-center justify-center py-1.5 transition-all text-amber-400 hover:text-amber-300 group">
                    <div class="p-1 rounded-xl group-hover:bg-amber-500/10 transition">
                        <svg class="w-5 h-5 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <span class="text-[11px] font-bold mt-0.5 tracking-tight">Admin</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Floating Bottom Seller Action Bar (Part 5, Item 5) -->
    <div id="sellerPendingActionBar" class="fixed bottom-20 left-4 right-4 max-w-xl mx-auto z-40 bg-slate-900/95 border border-indigo-500/40 backdrop-blur-xl rounded-2xl p-3.5 shadow-2xl flex items-center justify-between gap-3 text-xs hidden">
        <div class="flex items-center gap-2.5 min-w-0">
            <span class="flex h-3 w-3 relative flex-shrink-0">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
            </span>
            <div class="truncate">
                <span class="font-bold text-white block truncate" id="sellerPendingBarTitle">Payment Proof Received</span>
                <span class="text-[11px] text-indigo-300" id="sellerPendingBarSubtitle">Action Required</span>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0" id="sellerPendingBarActions">
            <!-- Dynamically populated buttons -->
        </div>
    </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- FLOATING INTEGRATED SUPPORT WIDGET (Part 8, Item 3) -->
    <!-- ========================================== -->
    <div id="floatingSupportContainer" class="fixed bottom-20 sm:bottom-6 right-4 sm:right-6 z-40 flex flex-col items-end">
        <!-- Expanded Support Popover / Action Menu -->
        <div id="supportActionMenu" class="hidden mb-3 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-4 w-72 sm:w-80 space-y-3 transform scale-95 transition-all duration-200">
            <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <h4 class="text-xs font-bold text-white uppercase tracking-wider">Official 24/7 Support</h4>
                </div>
                <button type="button" onclick="toggleSupportMenu()" class="text-slate-400 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <p class="text-[11px] text-slate-400 leading-relaxed">Need help with payments, disputes, or account setup? Connect directly with our official support team:</p>
            <div class="space-y-2 pt-1">
                <!-- Telegram Support Button -->
                <a id="supportTelegramLink" href="https://t.me/jelipay_support" target="_blank" rel="noopener noreferrer" class="w-full bg-[#229ED9]/15 hover:bg-[#229ED9]/25 text-[#229ED9] border border-[#229ED9]/30 rounded-xl px-3.5 py-2.5 text-xs font-bold flex items-center justify-between transition group">
                    <div class="flex items-center gap-2.5">
                        <svg class="w-4 h-4 fill-current flex-shrink-0" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.52 2.77-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                        <span>Telegram Support</span>
                    </div>
                    <span class="text-[10px] text-slate-400 group-hover:text-white transition">Join Channel &rarr;</span>
                </a>
            </div>
        </div>

        <!-- Floating Action Button -->
        <button type="button" onclick="toggleSupportMenu()" class="relative flex items-center gap-2 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-bold px-4 py-3 rounded-full shadow-2xl shadow-indigo-600/40 border border-indigo-400/30 transition transform hover:scale-105" title="Customer Support">
            <span id="supportUnreadDot" class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-400 rounded-full border-2 border-slate-950 hidden animate-ping"></span>
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span class="text-xs tracking-wider">Support</span>
        </button>
    </div>

    <!-- iOS Add to Home Screen Instructions Modal (Part 9, Item 3) -->
    <div id="iosInstallModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-sm w-full p-6 text-center space-y-4 shadow-2xl relative">
            <div class="w-14 h-14 rounded-2xl bg-indigo-600/20 border border-indigo-500/30 flex items-center justify-center mx-auto text-indigo-400">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <h3 class="text-lg font-bold text-white">Install on iPhone / iPad</h3>
                <p class="text-xs text-slate-400 mt-1">To add JeliPay to your iOS Home Screen:</p>
            </div>
            <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 text-left space-y-3 text-xs text-slate-300">
                <div class="flex items-start gap-2.5">
                    <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-bold flex-shrink-0 mt-0.5">1</span>
                    <span>Tap the <strong>Share</strong> icon at the bottom of Safari (<svg class="w-3.5 h-3.5 inline text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>).</span>
                </div>
                <div class="flex items-start gap-2.5">
                    <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] font-bold flex-shrink-0 mt-0.5">2</span>
                    <span>Scroll down and select <strong>"Add to Home Screen"</strong> (<svg class="w-3.5 h-3.5 inline text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>).</span>
                </div>
            </div>
            <button type="button" onclick="closeIosInstallModal()" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                Got It
            </button>
        </div>
    </div>

    <!-- JavaScript Controller with Cache Busting -->
    <script src="/public/js/app.js?v=<?= file_exists(__DIR__ . '/public/js/app.js') ? filemtime(__DIR__ . '/public/js/app.js') : time() ?>"></script>
</body>
</html>
