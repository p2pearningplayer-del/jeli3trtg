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
        <div class="pt-4 border-t border-slate-800">
            <a href="/admin_login.php" class="text-xs text-amber-400 hover:underline font-semibold">Administrator Access Portal &rarr;</a>
        </div>
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
                    <div class="flex items-center gap-2 cursor-pointer" onclick="switchTab('home')">
                        <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center shadow-lg shadow-indigo-500/20">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <div>
                            <span class="text-lg font-black tracking-tight text-white flex items-center gap-1.5">
                                <?= $siteName ?> <span class="text-[10px] uppercase font-bold tracking-widest px-1.5 py-0.5 rounded bg-indigo-500/20 text-indigo-400 border border-indigo-500/30">P2P</span>
                            </span>
                            <span class="text-[10px] text-slate-400 block -mt-1"><?= $siteTagline ?></span>
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
                        <div class="flex items-center bg-slate-800/80 border border-slate-700/80 rounded-xl p-1 sm:px-3 sm:py-1.5 gap-2 cursor-pointer" onclick="switchTab('home')" title="Shared Available Wallet Balance">
                            <div class="text-right hidden sm:block">
                                <span class="text-[10px] text-slate-400 uppercase tracking-wider block font-semibold leading-none">Shared Wallet</span>
                                <span class="text-sm font-black text-emerald-400 font-mono" id="headerAvailableBalance">₹0.00</span>
                            </div>
                            <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                        </div>

                        <!-- Profile Trigger -->
                        <button onclick="switchTab('profile')" class="p-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl border border-slate-700 transition" title="Profile">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </button>

                        <?php if ($isAdminUser): ?>
                        <!-- Dedicated Admin Console Link -->
                        <a href="/admin.php" class="px-2.5 py-1.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5" title="Dedicated Admin Console">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span class="hidden md:inline">Admin Console</span>
                        </a>
                        <?php endif; ?>

                        <!-- Logout -->
                        <button onclick="logout()" class="p-2 bg-slate-800 hover:bg-red-500/20 text-slate-400 hover:text-red-400 rounded-xl border border-slate-700 transition" title="Logout">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </button>
                    <?php else: ?>
                        <a href="/admin_login.php" class="text-xs text-amber-400 hover:underline px-2 py-1 font-semibold">
                            Admin Portal
                        </a>
                        <button onclick="openAuthModal('login')" class="text-xs sm:text-sm font-semibold text-slate-300 hover:text-white px-3 py-2 transition">
                            Sign In
                        </button>
                        <button onclick="openAuthModal('register')" class="text-xs sm:text-sm font-bold bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-xl shadow-lg shadow-indigo-600/30 transition">
                            Get Started
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PRIMARY NAVIGATION: Home | Buy | Sell | Refer | Profile -->
            <div class="flex space-x-1 sm:space-x-2 border-t border-slate-800/80 overflow-x-auto py-2 text-xs sm:text-sm font-semibold scrollbar-none">
                <button onclick="switchTab('home')" id="tabBtn-home" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-indigo-400 bg-slate-800/90 border border-slate-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    <span>Home</span>
                </button>
                <button onclick="switchTab('buy')" id="tabBtn-buy" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-slate-400 hover:text-slate-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>Buy</span>
                </button>
                <button onclick="switchTab('sell')" id="tabBtn-sell" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-slate-400 hover:text-slate-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                    <span>Sell</span>
                </button>
                <button onclick="switchTab('refer')" id="tabBtn-refer" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-slate-400 hover:text-slate-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span>Refer</span>
                </button>
                <button onclick="switchTab('profile')" id="tabBtn-profile" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-slate-400 hover:text-slate-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>Profile</span>
                </button>
                <?php if ($currentUser && ($currentUser['role'] === 'admin' || !empty($currentUser['real_admin_id']))): ?>
                <button onclick="switchTab('admin')" id="tabBtn-admin" class="tab-btn px-3.5 py-2 rounded-lg flex items-center gap-2 whitespace-nowrap text-amber-400 hover:text-amber-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>Admin</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main View Containers -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- ========================================== -->
        <!-- TAB 1: HOME (Unified Dashboard & Shared Wallet Overview) -->
        <!-- ========================================== -->
        <section id="view-home" class="tab-view space-y-6">
            <!-- Welcome Banner -->
            <div class="bg-gradient-to-r from-indigo-950/80 via-slate-900 to-slate-900 border border-indigo-900/40 rounded-2xl p-6 sm:p-8 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-xl">
                <div class="space-y-2 max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                        <span>Unified P2P Marketplace</span>
                        <span>&bull;</span>
                        <span>One Account &bull; Shared Wallet</span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Buy, Hold & Sell Tokens Seamlessly</h1>
                    <p class="text-sm text-slate-300">
                        Buy tokens from peers to receive a <strong>9% purchase reward</strong> directly into your wallet. Use that same wallet balance to list sell orders anytime. All transactions update one centralized ledger.
                    </p>
                </div>

                <!-- Quick Action Buttons -->
                <div class="flex flex-col sm:flex-row items-stretch gap-3 w-full md:w-auto">
                    <button onclick="switchTab('buy')" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Buy Tokens (+9%)</span>
                    </button>
                    <button onclick="switchTab('sell')" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl border border-slate-700 transition text-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"/></svg>
                        <span>Sell Tokens</span>
                    </button>
                </div>
            </div>

            <!-- Shared Wallet Segregated Pools -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block">Available Balance</span>
                    <p class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono mt-1" id="homeStatAvailable">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Ready for transfer or sell listing</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block">Reserved Balance</span>
                    <p class="text-2xl sm:text-3xl font-black text-amber-400 font-mono mt-1" id="homeStatReserved">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Locked in active sell orders</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block">Total Rewards</span>
                    <p class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono mt-1" id="homeStatRewards">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">9% promotional credits earned</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block">Commissions</span>
                    <p class="text-2xl sm:text-3xl font-black text-violet-400 font-mono mt-1" id="homeStatCommissions">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">2-tier referral earnings</span>
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

            <!-- Unified Transaction History (Centralized Wallet Ledger) -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Unified Wallet Transaction History</h3>
                        <p class="text-xs text-slate-400">All token movements (purchases, rewards, reserves, settlements, referrals) in one ledger</p>
                    </div>
                    <button onclick="loadLedger()" class="p-2 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-lg transition" title="Refresh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800">
                            <tr>
                                <th class="p-3">Ledger ID</th>
                                <th class="p-3">Action Type</th>
                                <th class="p-3">Amount</th>
                                <th class="p-3">Balance Progression</th>
                                <th class="p-3">Reference / Order ID</th>
                                <th class="p-3">Timestamp (UTC)</th>
                            </tr>
                        </thead>
                        <tbody id="homeLedgerTableBody" class="divide-y divide-slate-800/60 font-mono text-slate-300">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 2: BUY (Buy Pool, Custom Match & Purchases) -->
        <!-- ========================================== -->
        <section id="view-buy" class="tab-view space-y-6 hidden">
            <!-- Buy Hero Banner -->
            <div class="bg-gradient-to-r from-indigo-950/70 via-slate-900 to-slate-900 border border-indigo-900/40 rounded-2xl p-6 sm:p-8 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-xl">
                <div class="space-y-2 max-w-xl">
                    <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                        <span>✨ Active Promotion</span>
                        <span>&bull;</span>
                        <span>9% Instant Credit Reward</span>
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Purchase Peer-to-Peer Tokens</h2>
                    <p class="text-sm text-slate-400">
                        Select an available token portion or specify a custom buy amount. Complete direct manual UPI transfer within the 10-minute deadline to receive your tokens plus a 9% purchase reward.
                    </p>
                    <div class="flex items-center gap-2 pt-1 text-[11px] text-indigo-400 font-semibold">
                        <svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>Unified Account: Buy tokens, earn 9% rewards, and sell available tokens anytime. Self-trading is strictly prohibited.</span>
                    </div>
                </div>
                <!-- Custom Buy Allocation Widget (FR-008) -->
                <div class="bg-slate-950/80 border border-slate-800 rounded-xl p-5 w-full md:w-80 space-y-3">
                    <span class="text-xs uppercase font-bold text-slate-300 tracking-wider block">Custom Buy Allocation</span>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Enter Custom Amount (Multiples of ₹100)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-2.5 text-slate-400 font-bold">₹</span>
                            <input type="number" id="customBuyAmount" step="100" min="100" placeholder="5000" class="w-full bg-slate-900 border border-slate-700 rounded-lg pl-8 pr-3 py-2 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                        </div>
                    </div>
                    <button onclick="requestCustomAllocation()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2 rounded-lg text-xs uppercase tracking-wider transition shadow-md shadow-indigo-600/30">
                        Match & Allocate
                    </button>
                </div>
            </div>

            <!-- Active Buy Orders & Payment Status (My Purchases in Buy Section) -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">My Active Buy Orders & Payment Deadlines</h3>
                        <p class="text-xs text-slate-400">Complete transfer within 10 minutes and submit UTR screenshot</p>
                    </div>
                    <button onclick="loadMyPurchases()" class="p-2 text-slate-400 hover:text-white bg-slate-900 border border-slate-800 rounded-lg transition" title="Refresh Purchases">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
                <div id="myPurchasesList" class="space-y-3">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <!-- Live Market Buy Pool Listing (FR-006) -->
            <div class="pt-4 border-t border-slate-800">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">Available Peer Sell Portions</h3>
                        <p class="text-xs text-slate-400">Select an unpicked micro-order portion to buy</p>
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
                        <p class="text-xs text-slate-400 mt-1">
                            Tokens are sold from your Available Wallet Balance. The system automatically divides large orders into micro-orders (up to 8 portions) and displays them in the Buy section.
                        </p>
                    </div>

                    <div class="bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs flex justify-between items-center">
                        <span class="text-slate-400">Available to Sell:</span>
                        <span class="text-emerald-400 font-mono font-bold text-sm" id="sellFormAvailableBalance">₹0.00</span>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Sell Amount (₹)</label>
                            <input type="number" id="sellAmountInput" min="100" step="100" placeholder="10000" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Min ₹100 &bull; Must be exact multiple of ₹100</span>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Selling TPIN (4-6 Digits)</label>
                            <input type="password" id="sellTpinInput" maxlength="6" placeholder="••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono tracking-widest focus:border-indigo-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Secondary Transaction PIN required for authorization</span>
                        </div>

                        <!-- Banking Snapshot Notice (FR-013) -->
                        <div class="bg-slate-950/60 border border-slate-800/80 rounded-xl p-3 text-xs text-slate-400 space-y-1">
                            <span class="text-indigo-400 font-bold block">Snapshotted Payout Details:</span>
                            <div id="sellProfilePreview" class="text-[11px]">Loading verified UPI details...</div>
                        </div>

                        <button onclick="submitSellOrder()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-indigo-600/30 transition text-sm">
                            Authorize & List Sell Order
                        </button>
                    </div>
                </div>

                <!-- Seller Dashboard & Order Splitting Overview (FR-014) -->
                <div class="lg:col-span-2 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-white">Seller Tracking Dashboard</h3>
                            <p class="text-xs text-slate-400">Parent sell orders, micro-order statuses, and buyer confirmation timers</p>
                        </div>
                        <button onclick="loadSellerDashboard()" class="p-2 text-slate-400 hover:text-white bg-slate-900 border border-slate-800 rounded-lg transition" title="Refresh">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </button>
                    </div>

                    <div id="sellerOrdersList" class="space-y-4">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- TAB 4: REFER (2-Tier Referral Hub) -->
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
            <!-- Profile Info & Account Security -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Banking Payout Settings -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div class="border-b border-slate-800 pb-3">
                        <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">UPI Configuration</span>
                        <h3 class="text-lg font-bold text-white mt-1">Payout Banking Details</h3>
                        <p class="text-xs text-slate-400">Buyers will transfer funds directly to this verified UPI ID when picking your sell orders</p>
                    </div>

                    <form onsubmit="handleProfileUpdate(event)" class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Account Holder Full Name</label>
                            <input type="text" id="profHolderName" required placeholder="Rohan Sharma" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">UPI ID (e.g. user@okhdfcbank)</label>
                            <input type="text" id="profUpiId" required placeholder="user@bank" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                            Save Payout Details
                        </button>
                    </form>
                </div>

                <!-- Selling TPIN Configuration -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <div class="border-b border-slate-800 pb-3">
                        <span class="text-xs uppercase font-bold text-amber-400 tracking-wider">Security Protection</span>
                        <h3 class="text-lg font-bold text-white mt-1">Selling Transaction PIN (TPIN)</h3>
                        <p class="text-xs text-slate-400">Secondary 4–6 digit PIN required to authorize every sell order listing</p>
                    </div>

                    <form onsubmit="handleTpinUpdate(event)" class="space-y-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Set New Selling TPIN (4-6 Digits)</label>
                            <input type="password" id="profNewTpin" required maxlength="6" placeholder="••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono tracking-widest focus:border-amber-500 focus:outline-none">
                            <span class="text-[11px] text-slate-500 mt-1 block">Bcrypt hashed. Never stored in plaintext.</span>
                        </div>
                        <button type="submit" class="w-full bg-amber-600 hover:bg-amber-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                            Save Selling TPIN
                        </button>
                    </form>

                    <!-- Account Metadata Chip -->
                    <div class="bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs space-y-1">
                        <div class="flex justify-between text-slate-400">
                            <span>Phone:</span>
                            <span class="text-white font-mono" id="profilePhoneDisplay">-</span>
                        </div>
                        <div class="flex justify-between text-slate-400">
                            <span>Referral Code:</span>
                            <span class="text-indigo-400 font-mono font-bold" id="profileRefCodeDisplay">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Complete Unified Ledger Audit Log -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-white">Complete Wallet Audit History</h3>
                        <p class="text-xs text-slate-400">Immutable, append-only record of every token credit, debit, reward, and settlement</p>
                    </div>
                    <button onclick="loadLedger()" class="p-2 text-slate-400 hover:text-white bg-slate-950 border border-slate-800 rounded-lg transition" title="Refresh Ledger">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800">
                            <tr>
                                <th class="p-3">ID</th>
                                <th class="p-3">Type</th>
                                <th class="p-3">Amount</th>
                                <th class="p-3">Previous Balance</th>
                                <th class="p-3">New Balance</th>
                                <th class="p-3">Order / Reference</th>
                                <th class="p-3">Timestamp (UTC)</th>
                            </tr>
                        </thead>
                        <tbody id="profileLedgerTableBody" class="divide-y divide-slate-800/60 font-mono text-slate-300">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
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

    </main>

    <!-- Footer -->
    <footer class="bg-slate-900/60 border-t border-slate-800/80 py-6 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
                <span>&copy; <?= date('Y') ?> JeliPay P2P Platform. All rights reserved.</span>
                <span class="mx-2">&bull;</span>
                <span class="text-slate-400">Production: https://jelipay.in/</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="/test_qa.php" target="_blank" class="text-indigo-400 hover:text-indigo-300 font-semibold underline">
                    Live QA Acceptance Report (11/11 Passed)
                </a>
                <span>&bull;</span>
                <span class="font-mono text-[11px] text-slate-400">Server UTC: <?= gmdate('H:i:s') ?></span>
            </div>
        </div>
    </footer>

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
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Phone Number or User ID</label>
                    <input type="text" id="authPhone" required placeholder="9876543210 or DEMOUSER001" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Password</label>
                    <input type="password" id="authPassword" required placeholder="••••••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                </div>

                <!-- Registration-only fields -->
                <div id="registerFields" class="space-y-4 hidden">
                    <div>
                        <label class="text-xs font-semibold text-slate-300 block mb-1">Account Holder Full Name</label>
                        <input type="text" id="authName" placeholder="Your Name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-300 block mb-1">UPI ID (For Receiving Payments)</label>
                        <input type="text" id="authUpi" placeholder="yourname@bank" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white font-mono focus:border-indigo-500 focus:outline-none">
                    </div>
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

            <!-- Demo Accounts Helper (Requirement 9) -->
            <div class="mt-4 pt-4 border-t border-slate-800 space-y-2">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block text-center">Demo Accounts (For Evaluation & Testing)</span>
                <div class="grid grid-cols-2 gap-2 text-left">
                    <button type="button" onclick="fillDemoUserCredentials()" class="p-2.5 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800 transition">
                        <span class="text-xs font-bold text-emerald-400 block font-mono">DEMOUSER001</span>
                        <span class="text-[10px] text-slate-400 block">User &bull; ₹10k Test Bal</span>
                    </button>
                    <a href="/admin_login.php" class="p-2.5 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800 transition block">
                        <span class="text-xs font-bold text-amber-400 block font-mono">DEMOADMIN001</span>
                        <span class="text-[10px] text-slate-400 block">Admin Portal &rarr;</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Payment Proof Submission Modal (FR-009) -->
    <div id="proofModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeProofModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <span class="text-xs uppercase font-bold text-indigo-400 tracking-wider">Step 2 of 2</span>
                <h3 class="text-xl font-bold text-white mt-1">Submit UPI Payment Proof</h3>
                <p class="text-xs text-slate-400 mt-1">Order #<span id="proofModalOrderId"></span> &bull; Amount: <span id="proofModalAmount" class="font-bold text-white font-mono"></span></p>
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
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Payment Screenshot Proof</label>
                    <input type="file" id="proofFileInput" accept="image/*" required onchange="handleFileUpload(this, 'proof')" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500">
                    <span id="uploadStatusText" class="text-[11px] text-slate-500 mt-1 block">Upload clear screenshot showing UTR and success status</span>
                </div>

                <div id="proofError" class="text-xs text-red-400 font-semibold hidden"></div>

                <button type="submit" id="proofSubmitBtn" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-600/30 transition text-sm">
                    Confirm & Send Proof to Seller
                </button>
            </form>
        </div>
    </div>

    <!-- 3. Dispute Appeal Modal (FR-016) -->
    <div id="appealModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeAppealModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <span class="text-xs uppercase font-bold text-red-400 tracking-wider">Dispute Filing</span>
                <h3 class="text-xl font-bold text-white mt-1">Payment Not Received</h3>
                <p class="text-xs text-slate-400 mt-1">Order #<span id="appealOrderId"></span> &bull; Appeal triggers permanent freeze and escalates to Admin Console</p>
            </div>

            <form id="appealForm" onsubmit="handleAppealSubmit(event)" class="space-y-4">
                <input type="hidden" id="appealOrderIdInput">
                <input type="hidden" id="appealEvidencePath">

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Reason for Appeal</label>
                    <textarea id="appealReasonInput" required rows="3" placeholder="Explain why payment was not received (e.g. Bank statement checked, no credit received)..." class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-sm text-white focus:border-red-500 focus:outline-none"></textarea>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Bank Statement / Screenshot Evidence (Optional)</label>
                    <input type="file" id="appealFileInput" accept="image/*" onchange="handleFileUpload(this, 'evidence')" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-xs text-slate-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-red-600 file:text-white hover:file:bg-red-500">
                    <span id="appealUploadStatusText" class="text-[11px] text-slate-500 mt-1 block">Attach recent bank passbook/statement excerpt</span>
                </div>

                <div id="appealError" class="text-xs text-red-400 font-semibold hidden"></div>

                <button type="submit" id="appealSubmitBtn" class="w-full bg-red-600 hover:bg-red-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-red-600/30 transition text-sm">
                    Freeze Order & Escalate to Admin
                </button>
            </form>
        </div>
    </div>

    <!-- JavaScript Controller -->
    <script src="/public/js/app.js"></script>
</body>
</html>
