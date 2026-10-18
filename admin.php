<?php
/**
 * Administrator Central Control Console
 * Production Domain: https://jelipay.in/admin.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/schema_installer.php';
require_once __DIR__ . '/src/Auth.php';

SchemaInstaller::ensureSchema();

$currentUser = Auth::getCurrentUser();
if (!$currentUser || ($currentUser['role'] !== 'admin' && empty($currentUser['real_admin_id']))) {
    header('Location: /admin_login.php');
    exit;
}

$adminRole = $currentUser['admin_role'] ?? 'super_admin';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>JeliPay | Administration & Governance Console</title>
    <!-- Official Favicon & PWA Icons -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/public/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/public/images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/public/images/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#020617">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #090d16; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #334155; }
    </style>
</head>
<body class="h-full text-slate-100 flex flex-col antialiased selection:bg-amber-500 selection:text-black">

    <!-- Top Admin Header Bar -->
    <header class="bg-slate-900/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand & Badge -->
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-amber-600 to-amber-400 flex items-center justify-center shadow-lg shadow-amber-500/20 text-slate-950 font-black">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-extrabold text-white">JeliPay Admin Console</span>
                            <span class="text-[10px] font-mono font-bold uppercase px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                <?= htmlspecialchars(strtoupper($adminRole)) ?>
                            </span>
                            <span class="hidden md:inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                                LIVE MODE
                            </span>
                        </div>
                        <span class="text-[10px] text-slate-400 block -mt-0.5">Admin ID: <?= htmlspecialchars($currentUser['referral_code'] ?? 'ADMIN') ?> &bull; Phone: <?= htmlspecialchars($currentUser['phone']) ?></span>
                    </div>
                </div>

                <!-- Global Actions -->
                <div class="flex items-center gap-2 sm:gap-3">
                    <div class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-[11px] font-mono text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Server UTC: <?= gmdate('H:i:s') ?></span>
                    </div>

                    <a href="/?tab=home" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 transition flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        <span class="hidden sm:inline">Live Marketplace</span>
                    </a>

                    <button onclick="adminLogout()" class="p-2 bg-slate-800 hover:bg-red-500/20 text-slate-400 hover:text-red-400 rounded-xl border border-slate-700 transition" title="Logout">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </div>
            </div>

            <!-- Admin Tab Navigation -->
            <div class="flex space-x-1 sm:space-x-2 border-t border-slate-800/80 overflow-x-auto py-2 text-xs font-bold uppercase tracking-wider scrollbar-none">
                <button onclick="switchAdminTab('dashboard')" id="adminNav-dashboard" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    <span>Dashboard</span>
                </button>
                <button onclick="switchAdminTab('users')" id="adminNav-users" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span>Users</span>
                </button>
                <button onclick="switchAdminTab('orders')" id="adminNav-orders" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span>Orders</span>
                </button>
                <button onclick="switchAdminTab('disputes')" id="adminNav-disputes" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span>Disputes</span>
                </button>
                <button onclick="switchAdminTab('rewards')" id="adminNav-rewards" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Rewards & Fees</span>
                </button>
                <button onclick="switchAdminTab('content')" id="adminNav-content" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>Website & Branding</span>
                </button>
                <button onclick="switchAdminTab('reports')" id="adminNav-reports" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <span>Reports & CSV</span>
                </button>
                <button onclick="switchAdminTab('audit')" id="adminNav-audit" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Audit Logs</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Main Views Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <!-- ========================================== -->
        <!-- VIEW 1: DASHBOARD OVERVIEW -->
        <!-- ========================================== -->
        <section id="adminView-dashboard" class="admin-view space-y-6">
            <!-- Macro Stats Row 1: Users & Balances -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Users</span>
                    <p class="text-2xl sm:text-3xl font-black text-white font-mono mt-1" id="dashTotalUsers">0</p>
                    <div class="flex gap-2 text-[11px] text-slate-500 mt-1 font-mono">
                        <span>Act: <strong class="text-emerald-400" id="dashActiveUsers">0</strong></span>
                        <span>Susp: <strong class="text-red-400" id="dashSuspendedUsers">0</strong></span>
                        <span class="text-emerald-400 font-bold">● LIVE</span>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Tokens In Circulation</span>
                    <p class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono mt-1" id="dashCirculation">₹0.00</p>
                    <div class="text-[11px] text-slate-500 mt-1 font-mono">
                        <span>Avail: <strong class="text-slate-300" id="dashAvailableBal">₹0</strong> &bull; Res: <strong class="text-amber-400" id="dashReservedBal">₹0</strong></span>
                    </div>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Completed Trading Vol</span>
                    <p class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono mt-1" id="dashTradingVol">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Sell Vol: <strong class="text-slate-300 font-mono" id="dashSellVol">₹0.00</strong></span>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Purchase Rewards (9%)</span>
                    <p class="text-2xl sm:text-3xl font-black text-violet-400 font-mono mt-1" id="dashTotalRewards">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Credited to buyers</span>
                </div>
            </div>

            <!-- Macro Stats Row 2: Orders & Pipeline -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-white">Marketplace Order State Breakdown</h3>
                        <p class="text-xs text-slate-400">Total micro-orders processed across all parent allocations</p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full bg-slate-800 text-xs font-mono text-slate-300" id="dashOrdersTotal">0 Total Orders</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 text-center">
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Available</span>
                        <span class="text-xl font-bold font-mono text-slate-200 mt-1 block" id="dashOrdersAvailable">0</span>
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Reserved</span>
                        <span class="text-xl font-bold font-mono text-amber-400 mt-1 block" id="dashOrdersReserved">0</span>
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Proof Pending</span>
                        <span class="text-xl font-bold font-mono text-indigo-400 mt-1 block" id="dashOrdersPendingConfirm">0</span>
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Disputed</span>
                        <span class="text-xl font-bold font-mono text-red-400 mt-1 block" id="dashOrdersDisputed">0</span>
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Completed</span>
                        <span class="text-xl font-bold font-mono text-emerald-400 mt-1 block" id="dashOrdersCompleted">0</span>
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold">Cancelled / Rev</span>
                        <span class="text-xl font-bold font-mono text-slate-400 mt-1 block" id="dashOrdersCancelled">0</span>
                    </div>
                </div>
            </div>

            <!-- Weekly Milestone Target Progress -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <span class="text-xs font-bold text-amber-400 uppercase tracking-wider block">Weekly Platform Milestone</span>
                        <h3 class="text-lg font-bold text-white">Target Trading Volume: <span id="dashWeeklyTarget">₹4,00,000.00</span></h3>
                    </div>
                    <div class="text-right">
                        <span class="text-2xl font-black text-white font-mono" id="dashWeeklyPct">0.0%</span>
                        <span class="text-xs text-slate-500 block">7-Day Completion</span>
                    </div>
                </div>
                <div class="w-full bg-slate-950 rounded-full h-3 overflow-hidden border border-slate-800">
                    <div id="dashWeeklyProgressBar" class="bg-gradient-to-r from-amber-500 to-emerald-400 h-full rounded-full transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex justify-between text-xs text-slate-500 font-mono">
                    <span>Current 7-Day Settled Vol: <strong class="text-slate-300" id="dashWeeklyCompleted">₹0.00</strong></span>
                    <span>2-Tier Commissions: <strong class="text-violet-400" id="dashTotalCommissions">₹0.00</strong> (L1: <span id="dashL1Commissions">₹0</span> &bull; L2: <span id="dashL2Commissions">₹0</span>)</span>
                </div>
            </div>

            <!-- Dual Recent Activities Tables -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Financial Transactions -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                    <h4 class="text-sm font-bold text-white uppercase tracking-wider">Recent Wallet Ledger Movements</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-950 text-slate-400 text-[10px] uppercase border-b border-slate-800">
                                <tr>
                                    <th class="p-2">ID</th>
                                    <th class="p-2">User</th>
                                    <th class="p-2">Type</th>
                                    <th class="p-2">Amount</th>
                                    <th class="p-2">Timestamp (UTC)</th>
                                </tr>
                            </thead>
                            <tbody id="dashRecentTransBody" class="divide-y divide-slate-800/60"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Administrative Activity -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                    <h4 class="text-sm font-bold text-white uppercase tracking-wider">Recent Administrative Actions</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-950 text-slate-400 text-[10px] uppercase border-b border-slate-800">
                                <tr>
                                    <th class="p-2">ID</th>
                                    <th class="p-2">Admin</th>
                                    <th class="p-2">Action</th>
                                    <th class="p-2">Reason</th>
                                    <th class="p-2">Timestamp (UTC)</th>
                                </tr>
                            </thead>
                            <tbody id="dashRecentAuditBody" class="divide-y divide-slate-800/60"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 2: USER MANAGEMENT -->
        <!-- ========================================== -->
        <section id="adminView-users" class="admin-view space-y-6 hidden">
            <!-- Search & Filter Controls -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">Registered User Accounts</h3>
                        <p class="text-xs text-slate-400">Search by User ID, phone, email, or name. Never displays plaintext passwords or Selling TPINs.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="loadAdminUsers()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 transition">
                            Refresh Table
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <input type="text" id="userSearchInput" onkeyup="if(event.key === 'Enter') loadAdminUsers()"
                               placeholder="Search by User ID, phone number, email, or name..."
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <select id="userStatusFilter" onchange="loadAdminUsers()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                            <option value="all">All Statuses (Active & Suspended)</option>
                            <option value="active">Active Only</option>
                            <option value="suspended">Suspended Only</option>
                        </select>
                    </div>
                    <div>
                        <select id="userRoleFilter" onchange="loadAdminUsers()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                            <option value="all">All Roles</option>
                            <option value="user">Traders Only</option>
                            <option value="admin">Administrators Only</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                <table class="w-full text-left">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-3">User ID</th>
                            <th class="p-3">Phone & Email</th>
                            <th class="p-3">Holder & UPI</th>
                            <th class="p-3">Balances</th>
                            <th class="p-3">Referral Code</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminUsersTableBody" class="divide-y divide-slate-800/60"></tbody>
                </table>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 3: ORDER MANAGEMENT -->
        <!-- ========================================== -->
        <section id="adminView-orders" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-white">Buy & Sell Order Management</h3>
                        <p class="text-xs text-slate-400">Track all parent orders, split micro-portions, payment deadlines, and cancellations</p>
                    </div>
                    <button onclick="loadAdminOrders()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 transition">
                        Refresh Orders
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <input type="text" id="orderSearchInput" onkeyup="if(event.key === 'Enter') loadAdminOrders()"
                               placeholder="Search by Order ID, UTR number, or phone..."
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <select id="orderTypeFilter" onchange="loadAdminOrders()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                            <option value="child">Child Micro-Orders (Default)</option>
                            <option value="parent">Parent Sell Listings</option>
                        </select>
                    </div>
                    <div>
                        <select id="orderStatusFilter" onchange="loadAdminOrders()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                            <option value="all">All Statuses</option>
                            <option value="Available">Available</option>
                            <option value="Reserved">Reserved</option>
                            <option value="Payment Proof Submitted">Proof Submitted</option>
                            <option value="Seller Confirmation Pending">Confirmation Pending</option>
                            <option value="Seller Response Timeout">Response Timeout</option>
                            <option value="Disputed">Disputed</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled / Reversed">Cancelled / Reversed</option>
                            <option value="Expired">Expired</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                <table class="w-full text-left">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-3">Order ID</th>
                            <th class="p-3">Seller</th>
                            <th class="p-3">Buyer / Micro-portions</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">UTR / Proof</th>
                            <th class="p-3">Created (UTC)</th>
                            <th class="p-3">Admin Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminOrdersTableBody" class="divide-y divide-slate-800/60"></tbody>
                </table>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 4: DISPUTE & APPEAL MANAGEMENT -->
        <!-- ========================================== -->
        <section id="adminView-disputes" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-bold text-white">Dispute & Appeal Arbitration Console</h3>
                    <p class="text-xs text-slate-400">Review buyer UTR and screenshot proof against seller appeal statements. Tokens remain frozen until arbitration.</p>
                </div>
                <div class="flex items-center gap-3">
                    <select id="disputeStatusFilter" onchange="loadAdminDisputes()" class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white">
                        <option value="all">All Disputes</option>
                        <option value="pending">Pending Only</option>
                        <option value="under_review">Under Review</option>
                        <option value="resolved_buyer">Resolved (Buyer Settled)</option>
                        <option value="resolved_seller">Resolved (Seller Reversed)</option>
                    </select>
                    <button onclick="loadAdminDisputes()" class="p-2 text-slate-400 hover:text-white bg-slate-800 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
            </div>

            <!-- Disputes Container -->
            <div id="adminDisputesContainer" class="space-y-4"></div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 5: REWARD & REFERRAL CONFIGURATION -->
        <!-- ========================================== -->
        <section id="adminView-rewards" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <h3 class="text-lg font-bold text-white">Reward, Commission & Denomination Rules</h3>
                <p class="text-xs text-slate-400 mt-1">
                    Changes apply immediately to new orders. Active in-flight orders retain their snapshotted reward rates to preserve transaction integrity.
                </p>
            </div>
            <div id="rewardSettingsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 6: WEBSITE CONTENT & BRANDING -->
        <!-- ========================================== -->
        <section id="adminView-content" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <h3 class="text-lg font-bold text-white">Website Content, Announcements & Maintenance Mode</h3>
                <p class="text-xs text-slate-400 mt-1">
                    Control live branding, announcement banners, payment timeouts, and maintenance mode without redeploying code.
                </p>
            </div>

            <!-- Dynamic Telegram Support & Live Operations Highlight Deck (Part 9, Items 1 & 4) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Telegram Control Card -->
                <div class="bg-gradient-to-br from-slate-900 to-[#102336] border border-[#229ED9]/40 rounded-2xl p-5 shadow-lg space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#229ED9]/20 flex items-center justify-center text-[#229ED9]">
                                <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.52 2.77-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-white">Dynamic Telegram Support Control</h4>
                                <p class="text-[11px] text-slate-400">Updates support destination in real-time across user interfaces</p>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-[#229ED9]/20 text-[#229ED9] text-[10px] font-bold font-mono">24/7 SUPPORT</span>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label class="text-[11px] font-bold uppercase tracking-wider text-slate-300 block mb-1">Telegram Support Channel / Username URL</label>
                            <input type="url" id="quickTelegramUrlInput" placeholder="https://t.me/jelipay_support" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white font-mono focus:border-[#229ED9] focus:outline-none">
                        </div>

                        <div class="flex items-center gap-2">
                            <button onclick="saveQuickTelegramUrl()" class="flex-1 bg-gradient-to-r from-[#229ED9] to-blue-600 hover:from-[#1b8ec5] hover:to-blue-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Save & Apply Telegram Link</span>
                            </button>
                            <a id="quickTelegramTestBtn" href="https://t.me/jelipay_support" target="_blank" rel="noopener noreferrer" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1">
                                <span>Test Link</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Live Production Mode Card -->
                <div class="bg-gradient-to-br from-slate-900 to-emerald-950/40 border border-emerald-500/30 rounded-2xl p-5 shadow-lg space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/20 flex items-center justify-center text-emerald-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-white">Live Production Mode Governance</h4>
                                <p class="text-[11px] text-slate-400">Platform status: <strong class="text-emerald-400 font-bold">100% REAL PRODUCTION</strong></p>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 text-[10px] font-bold font-mono">LIVE / PRODUCTION</span>
                    </div>

                    <p class="text-xs text-slate-400 leading-relaxed">
                        To guarantee absolute security and pristine operations for real users, you can clean any remaining demo accounts or test records with 1 click.
                    </p>

                    <button onclick="purgeDemoAccountsAndEnforceLive()" class="w-full bg-slate-800 hover:bg-red-500/20 text-slate-300 hover:text-red-400 border border-slate-700 hover:border-red-500/30 font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        <span>Purge All Demo Data & Confirm Live Mode</span>
                    </button>
                </div>
            </div>

            <div id="contentSettingsGrid" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 7: REPORTS & EXPORTS -->
        <!-- ========================================== -->
        <section id="adminView-reports" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
                <h3 class="text-lg font-bold text-white">Downloadable System Data Reports (CSV)</h3>
                <p class="text-xs text-slate-400 mt-1">Export full immutable datasets for auditing, regulatory compliance, and business analytics</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-indigo-400 block tracking-wider">User Directory</span>
                        <h4 class="text-base font-bold text-white mt-1">Users & Balances Report</h4>
                        <p class="text-xs text-slate-400 mt-1">Complete user table with available/reserved balances, referral codes, and registration timestamps.</p>
                    </div>
                    <button onclick="downloadReport('users')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download users.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-indigo-400 block tracking-wider">Trading Activity</span>
                        <h4 class="text-base font-bold text-white mt-1">Buy & Sell Orders Report</h4>
                        <p class="text-xs text-slate-400 mt-1">All micro-orders, parent allocations, amounts, UTR numbers, and completion statuses.</p>
                    </div>
                    <button onclick="downloadReport('orders')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download orders.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-emerald-400 block tracking-wider">Financial Audit</span>
                        <h4 class="text-base font-bold text-white mt-1">Double-Entry Ledger Report</h4>
                        <p class="text-xs text-slate-400 mt-1">Complete immutable ledger of every token credit, debit, reward, and reserve movement.</p>
                    </div>
                    <button onclick="downloadReport('ledger')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download ledger.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-violet-400 block tracking-wider">Promotional Costs</span>
                        <h4 class="text-base font-bold text-white mt-1">Purchase Rewards Report</h4>
                        <p class="text-xs text-slate-400 mt-1">Breakdown of all 9% purchase rewards credited to buyers upon trade settlement.</p>
                    </div>
                    <button onclick="downloadReport('rewards')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download rewards.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-violet-400 block tracking-wider">Affiliate Payouts</span>
                        <h4 class="text-base font-bold text-white mt-1">Referral Commissions Report</h4>
                        <p class="text-xs text-slate-400 mt-1">All Level 1 (1%) and Level 2 (0.5%) referral commission disbursements.</p>
                    </div>
                    <button onclick="downloadReport('commissions')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download commissions.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-red-400 block tracking-wider">Arbitration History</span>
                        <h4 class="text-base font-bold text-white mt-1">Payment Disputes & Appeals</h4>
                        <p class="text-xs text-slate-400 mt-1">History of all seller appeals, evidence submissions, resolution notes, and refund statuses.</p>
                    </div>
                    <button onclick="downloadReport('disputes')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download disputes.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-amber-400 block tracking-wider">Compliance</span>
                        <h4 class="text-base font-bold text-white mt-1">Administrative Audit Logs</h4>
                        <p class="text-xs text-slate-400 mt-1">Every administrative action with admin phone, timestamp, reason, and before/after states.</p>
                    </div>
                    <button onclick="downloadReport('audit_logs')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download audit_logs.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-amber-400 block tracking-wider">Manual Overrides</span>
                        <h4 class="text-base font-bold text-white mt-1">Admin Balance Adjustments</h4>
                        <p class="text-xs text-slate-400 mt-1">Specific ledger adjustments authorized by administrators with mandatory reasons.</p>
                    </div>
                    <button onclick="downloadReport('adjustments')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download adjustments.csv</span>
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3 flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase text-emerald-400 block tracking-wider">Sales Analytics</span>
                        <h4 class="text-base font-bold text-white mt-1">Weekly Selling Volume</h4>
                        <p class="text-xs text-slate-400 mt-1">Settled sell orders grouped for weekly trading target calculations.</p>
                    </div>
                    <button onclick="downloadReport('weekly_volume')" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span>Download weekly_volume.csv</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 8: AUDIT TRAIL -->
        <!-- ========================================== -->
        <section id="adminView-audit" class="admin-view space-y-6 hidden">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-white">Immutable Administrative Audit Log</h3>
                    <p class="text-xs text-slate-400">Chronological trail of every sensitive administrative action, before/after states, and IP addresses</p>
                </div>
                <button onclick="loadAdminAuditLogs()" class="p-2 text-slate-400 hover:text-white bg-slate-800 rounded-xl transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>

            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                <table class="w-full text-left">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-3">Audit ID</th>
                            <th class="p-3">Administrator</th>
                            <th class="p-3">Action Type</th>
                            <th class="p-3">Target Entity</th>
                            <th class="p-3">Mandatory Reason</th>
                            <th class="p-3">IP Address</th>
                            <th class="p-3">Timestamp (UTC)</th>
                        </tr>
                    </thead>
                    <tbody id="adminAuditLogsTableBody" class="divide-y divide-slate-800/60"></tbody>
                </table>
            </div>
        </section>

    </main>

    <!-- ========================================== -->
    <!-- MODAL 1: USER DOSSIER (Requirement 2) -->
    <!-- ========================================== -->
    <div id="userDossierModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto p-6 shadow-2xl relative space-y-6">
            <button onclick="closeUserDossierModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <!-- Dossier Header -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xl font-black text-white" id="dossierUserId">#0</span>
                        <span class="px-2 py-0.5 rounded text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400" id="dossierStatus">ACTIVE</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Registered: <span id="dossierCreated" class="font-mono text-slate-300">-</span></p>
                </div>
                <div class="flex items-center gap-2">
                    <button id="dossierFlagBtn" class="px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/30 rounded-xl text-xs font-bold transition">
                        Flag Suspicious
                    </button>
                    <button id="dossierAdjustBtn" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition">
                        Adjust Balance
                    </button>
                </div>
            </div>

            <!-- Profile & Balances Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-mono">
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                    <span class="text-indigo-400 font-bold block uppercase tracking-wider font-sans">Identity & Banking Details</span>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Mobile Phone:</span> <strong class="text-white" id="dossierPhone">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Email Address:</span> <strong class="text-white" id="dossierEmail">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Holder Name:</span> <strong class="text-white" id="dossierName">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">UPI ID:</span> <strong class="text-emerald-400" id="dossierUpi">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Referral Code:</span> <strong class="text-indigo-400" id="dossierRefCode">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Referred By:</span> <strong class="text-slate-300" id="dossierReferredBy">-</strong></div>
                </div>

                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                    <span class="text-emerald-400 font-bold block uppercase tracking-wider font-sans">Wallet Balances & Rewards</span>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Available Balance:</span> <strong class="text-emerald-400 text-sm" id="dossierAvail">₹0.00</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Reserved in Sell Orders:</span> <strong class="text-amber-400" id="dossierReserved">₹0.00</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Total 9% Rewards Earned:</span> <strong class="text-indigo-400" id="dossierRewards">₹0.00</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Total Referral Earnings:</span> <strong class="text-violet-400" id="dossierCommissions">₹0.00</strong></div>
                    <div class="flex justify-between pt-1 border-t border-slate-800/80"><span class="text-slate-500 font-sans">Buy Orders:</span> <span class="text-white" id="dossierBuyCount">0</span></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Sell Orders:</span> <span class="text-white" id="dossierSellCount">0</span></div>
                </div>
            </div>

            <!-- Ledger History Sub-Table -->
            <div class="space-y-2">
                <span class="text-xs font-bold text-slate-300 uppercase tracking-wider block">Wallet Ledger History</span>
                <div class="overflow-x-auto bg-slate-950 rounded-xl border border-slate-800 max-h-56">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-400 text-[10px] uppercase border-b border-slate-800">
                            <tr>
                                <th class="p-2">ID</th>
                                <th class="p-2">Type</th>
                                <th class="p-2">Amount</th>
                                <th class="p-2">Progression</th>
                                <th class="p-2">Timestamp (UTC)</th>
                            </tr>
                        </thead>
                        <tbody id="dossierLedgerList" class="divide-y divide-slate-800/60"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 2: MANUAL BALANCE ADJUSTMENT -->
    <!-- ========================================== -->
    <div id="adjustmentModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-5">
            <button onclick="closeAdjustmentModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h3 class="text-lg font-bold text-white">Manual Token Adjustment</h3>
                <p class="text-xs text-slate-400 mt-0.5">Target: User #<span id="adjTargetId">0</span> (<span id="adjTargetPhone">-</span>)</p>
            </div>

            <form onsubmit="handleAdjustmentSubmit(event)" class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Adjustment Type</label>
                    <select id="adjType" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="credit">Credit (Deposit to Available Balance)</option>
                        <option value="debit">Debit (Deduct from Available Balance)</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Amount (₹)</label>
                    <input type="number" id="adjAmount" step="1" min="1" required placeholder="5000"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Mandatory Audit Reason</label>
                    <textarea id="adjReason" rows="3" required placeholder="Document the specific operational rationale for this balance adjustment..."
                              class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs text-white focus:outline-none focus:border-amber-500"></textarea>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-black py-3 rounded-xl text-xs uppercase tracking-wider transition">
                    Execute & Audit Adjustment
                </button>
            </form>
        </div>
    </div>

    <!-- Dedicated Admin Controller -->
    <script src="/public/js/admin.js"></script>
</body>
</html>
