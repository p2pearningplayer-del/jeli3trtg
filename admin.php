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
                <button onclick="switchAdminTab('fraud')" id="adminNav-fraud" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>Fraud & AI Security</span>
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
                <button onclick="switchAdminTab('admins')" id="adminNav-admins" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span>Admins & Teams</span>
                </button>
                <button onclick="switchAdminTab('agents')" id="adminNav-agents" class="admin-nav-item px-3.5 py-2 rounded-lg flex items-center gap-1.5 whitespace-nowrap text-slate-400 hover:text-white">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span>Agent Management</span>
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
                <div onclick="switchAdminTab('users', { role: 'all', status: 'all' })" class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm cursor-pointer hover:border-amber-500/50 hover:bg-slate-900/90 transition transform hover:-translate-y-0.5 group" title="Click to view all users">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block group-hover:text-amber-400 transition">Total Users</span>
                        <svg class="w-4 h-4 text-slate-600 group-hover:text-amber-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-black text-white font-mono mt-1" id="dashTotalUsers">0</p>
                    <div class="flex gap-2 text-[11px] text-slate-500 mt-1 font-mono">
                        <span onclick="event.stopPropagation(); switchAdminTab('users', { status: 'active' });" class="hover:underline hover:text-emerald-300 transition cursor-pointer" title="Click to filter Active users">Act: <strong class="text-emerald-400" id="dashActiveUsers">0</strong></span>
                        <span>&bull;</span>
                        <span onclick="event.stopPropagation(); switchAdminTab('users', { status: 'suspended' });" class="hover:underline hover:text-red-300 transition cursor-pointer" title="Click to filter Suspended users">Susp: <strong class="text-red-400" id="dashSuspendedUsers">0</strong></span>
                        <span>&bull;</span>
                        <span class="text-emerald-400 font-bold">● LIVE</span>
                    </div>
                </div>

                <div onclick="switchAdminTab('users')" class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm cursor-pointer hover:border-emerald-500/50 hover:bg-slate-900/90 transition transform hover:-translate-y-0.5 group" title="Click to inspect users & token balances">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block group-hover:text-emerald-400 transition">Tokens In Circulation</span>
                        <svg class="w-4 h-4 text-slate-600 group-hover:text-emerald-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono mt-1" id="dashCirculation">₹0.00</p>
                    <div class="text-[11px] text-slate-500 mt-1 font-mono">
                        <span>Avail: <strong class="text-slate-300" id="dashAvailableBal">₹0</strong> &bull; Res: <strong class="text-amber-400" id="dashReservedBal">₹0</strong></span>
                    </div>
                </div>

                <div onclick="switchAdminTab('orders', { status: 'Completed', type: 'child' })" class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm cursor-pointer hover:border-indigo-500/50 hover:bg-slate-900/90 transition transform hover:-translate-y-0.5 group" title="Click to filter Completed orders">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block group-hover:text-indigo-400 transition">Completed Trading Vol</span>
                        <svg class="w-4 h-4 text-slate-600 group-hover:text-indigo-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono mt-1" id="dashTradingVol">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Sell Vol: <strong class="text-slate-300 font-mono" id="dashSellVol">₹0.00</strong></span>
                </div>

                <div onclick="switchAdminTab('rewards')" class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm cursor-pointer hover:border-violet-500/50 hover:bg-slate-900/90 transition transform hover:-translate-y-0.5 group" title="Click to view Reward & Fee rules">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block group-hover:text-violet-400 transition">Purchase Rewards (9%)</span>
                        <svg class="w-4 h-4 text-slate-600 group-hover:text-violet-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                    <p class="text-2xl sm:text-3xl font-black text-violet-400 font-mono mt-1" id="dashTotalRewards">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Credited to buyers</span>
                </div>
            </div>

            <!-- Macro Stats Row 2: Orders & Pipeline -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-white">Marketplace Order State Breakdown</h3>
                        <p class="text-xs text-slate-400">Total micro-orders processed across all parent allocations &bull; Click any metric to filter</p>
                    </div>
                    <button onclick="switchAdminTab('orders', { status: 'all', type: 'child' })" class="px-2.5 py-1 rounded-full bg-slate-800 hover:bg-slate-700 text-xs font-mono text-slate-300 hover:text-white transition flex items-center gap-1.5" id="dashOrdersTotal">
                        <span>0 Total Orders</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 text-center">
                    <div onclick="switchAdminTab('orders', { status: 'Available', type: 'child' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-slate-600 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to filter Available micro-orders">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-slate-300 transition">Available</span>
                        <span class="text-xl font-bold font-mono text-slate-200 mt-1 block" id="dashOrdersAvailable">0</span>
                    </div>
                    <div onclick="switchAdminTab('orders', { status: 'Reserved', type: 'child' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-amber-500/50 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to filter Reserved orders">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-amber-400 transition">Reserved</span>
                        <span class="text-xl font-bold font-mono text-amber-400 mt-1 block" id="dashOrdersReserved">0</span>
                    </div>
                    <div onclick="switchAdminTab('orders', { status: 'Payment Proof Submitted', type: 'child' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-indigo-500/50 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to filter Proof Pending orders">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-indigo-400 transition">Proof Pending</span>
                        <span class="text-xl font-bold font-mono text-indigo-400 mt-1 block" id="dashOrdersPendingConfirm">0</span>
                    </div>
                    <div onclick="switchAdminTab('disputes', { status: 'all' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-red-500/50 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to navigate to Disputes Console">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-red-400 transition">Disputed</span>
                        <span class="text-xl font-bold font-mono text-red-400 mt-1 block" id="dashOrdersDisputed">0</span>
                    </div>
                    <div onclick="switchAdminTab('orders', { status: 'Completed', type: 'child' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-emerald-500/50 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to filter Completed orders">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-emerald-400 transition">Completed</span>
                        <span class="text-xl font-bold font-mono text-emerald-400 mt-1 block" id="dashOrdersCompleted">0</span>
                    </div>
                    <div onclick="switchAdminTab('orders', { status: 'Cancelled / Reversed', type: 'child' })" class="bg-slate-950 p-3 rounded-xl border border-slate-800 hover:border-slate-500 hover:bg-slate-900/90 transition cursor-pointer transform hover:-translate-y-0.5 group" title="Click to filter Cancelled/Reversed orders">
                        <span class="text-[10px] text-slate-500 uppercase block font-bold group-hover:text-slate-300 transition">Cancelled / Rev</span>
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
                <div class="flex flex-wrap items-center gap-2.5">
                    <div class="relative">
                        <input type="text" id="disputeSearchInput" onkeyup="if(event.key === 'Enter') loadAdminDisputes();"
                               placeholder="Search Order ID, UTR, phone, trader..."
                               class="bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 w-56 sm:w-64">
                        <svg class="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <select id="disputeStatusFilter" onchange="loadAdminDisputes()" class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="all">All Disputes</option>
                        <option value="pending">Pending Only</option>
                        <option value="under_review">Under Review</option>
                        <option value="resolved_buyer">Resolved (Buyer Settled)</option>
                        <option value="resolved_seller">Resolved (Seller Reversed)</option>
                    </select>
                    <button onclick="loadAdminDisputes()" class="px-3 py-2 text-xs font-semibold text-slate-300 hover:text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl transition flex items-center gap-1.5" title="Search & Refresh">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span>Search</span>
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
                            <input type="text" id="quickTelegramUrlInput" placeholder="https://t.me/amilacorp (or t.me/amilacorp)" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white font-mono focus:border-[#229ED9] focus:outline-none">
                        </div>

                        <div class="flex items-center gap-2">
                            <button onclick="saveQuickTelegramUrl()" class="flex-1 bg-gradient-to-r from-[#229ED9] to-blue-600 hover:from-[#1b8ec5] hover:to-blue-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Save & Apply Telegram Link</span>
                            </button>
                            <a id="quickTelegramTestBtn" href="https://t.me/amilacorp" target="_blank" rel="noopener noreferrer" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1">
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

        <!-- ========================================== -->
        <!-- VIEW 9: ADMINISTRATORS & TEAM ROLES (Part 10, Item 4) -->
        <!-- ========================================== -->
        <section id="adminView-admins" class="admin-view space-y-6 hidden">
            <!-- Header Deck with Summary and Action Buttons -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-bold text-white">Administrator Directory & Team Roles</h3>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-amber-500/10 text-amber-400 border border-amber-500/30">RBAC GOVERNANCE</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Manage platform administrators, assign team responsibilities (Support Team, Finance Team, Order Team, Super Admin), and oversee access permissions.</p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="openAddAdminModal()" class="px-4 py-2 bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-bold rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-amber-500/10 flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <span>Add Administrator</span>
                    </button>
                    <button onclick="loadAdminDirectory()" class="p-2 text-slate-400 hover:text-white bg-slate-800 rounded-xl transition" title="Refresh Directory">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
            </div>

            <!-- Administrators Table -->
            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl">
                <table class="w-full text-left">
                    <thead class="bg-slate-950 text-slate-400 text-xs uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-3">Admin ID</th>
                            <th class="p-3">Display Name & Contact</th>
                            <th class="p-3">Assigned Team Role</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Created Date</th>
                            <th class="p-3">Manage</th>
                        </tr>
                    </thead>
                    <tbody id="adminDirectoryTableBody" class="divide-y divide-slate-800/60">
                        <tr><td colspan="6" class="p-8 text-center text-slate-500 font-sans">Loading administrator directory...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Centralized Super Admin Password Reset Card -->
            <div class="bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950/40 border border-slate-800 rounded-2xl p-6 shadow-lg space-y-4 max-w-xl">
                <div class="flex items-center gap-3 border-b border-slate-800 pb-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/20 flex items-center justify-center text-indigo-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-white">Centralized Administrator Password Reset</h4>
                        <p class="text-xs text-slate-400">Securely change your administrator password. Verified against current credentials and audited.</p>
                    </div>
                </div>

                <form onsubmit="handleChangeAdminPassword(event)" class="space-y-3.5">
                    <div>
                        <label class="text-xs font-semibold text-slate-300 block mb-1">Current Password</label>
                        <input type="password" id="adminCurrentPassword" required placeholder="Enter current password"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">New Password (min 6)</label>
                            <input type="password" id="adminNewPassword" required minlength="6" placeholder="Enter new password"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-300 block mb-1">Confirm New Password</label>
                            <input type="password" id="adminConfirmPassword" required minlength="6" placeholder="Re-enter new password"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        </div>
                    </div>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-5 py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-md shadow-indigo-600/20 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>Update Login Password</span>
                    </button>
                </form>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 10: AI-POWERED FRAUD & FORENSICS (Requirement 9) -->
        <!-- ========================================== -->
        <section id="adminView-fraud" class="admin-view space-y-6 hidden">
            <!-- Header & Action Bar -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse"></span>
                            <h3 class="text-lg font-bold text-white">AI-Powered Fraud & Forensic Intelligence</h3>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20 uppercase tracking-wider">Zero-Trust Engine</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1">Multi-signal payment verification: Perceptual dHash, cryptographic SHA-256 deduplication, canonical UTR normalization, OCR cross-match, and explainable risk scoring.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="loadAdminFraudAssessments()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-semibold border border-slate-700 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <span>Refresh Intelligence</span>
                        </button>
                    </div>
                </div>

                <!-- Filters & Search Controls -->
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2 relative">
                        <input type="text" id="fraudSearchInput" onkeyup="if(event.key === 'Enter') loadAdminFraudAssessments();" oninput="debounceFraudSearch()"
                               placeholder="Search by Order ID, Buyer Phone, UTR, or Reason Code..."
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-9 pr-4 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-rose-500 font-mono">
                        <svg class="w-4 h-4 text-slate-500 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <div>
                        <select id="fraudDecisionFilter" onchange="loadAdminFraudAssessments()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500">
                            <option value="all">All Engine Decisions</option>
                            <option value="pending_review">Flagged (Pending Review)</option>
                            <option value="rejected">Auto-Rejected / Blocked</option>
                            <option value="verified">Verified (Passed)</option>
                            <option value="failed">System Exception (Failed)</option>
                        </select>
                    </div>
                    <div>
                        <select id="fraudRiskFilter" onchange="loadAdminFraudAssessments()" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-rose-500">
                            <option value="all">All Risk Levels</option>
                            <option value="high">High Risk (Score ≥ 75)</option>
                            <option value="medium">Medium Risk (Score 40–74)</option>
                            <option value="low">Low Risk (Score &lt; 40)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Fraud Forensics Table -->
            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl shadow-sm">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 text-[11px] uppercase border-b border-slate-800 tracking-wider">
                        <tr>
                            <th class="p-3">ID / Order</th>
                            <th class="p-3">Buyer</th>
                            <th class="p-3">Claimed Amount</th>
                            <th class="p-3">Normalized UTR</th>
                            <th class="p-3">Risk Assessment</th>
                            <th class="p-3">Engine Decision</th>
                            <th class="p-3">Signals & Reason Codes</th>
                            <th class="p-3">Analyzed (UTC)</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminFraudTableBody" class="divide-y divide-slate-800/60 font-mono">
                        <tr>
                            <td colspan="9" class="p-8 text-center text-slate-500 font-sans">
                                Loading fraud and forensic records...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="flex items-center justify-between text-xs text-slate-400 px-2">
                <span id="fraudPaginationInfo">Showing 0 of 0 assessments</span>
                <div class="flex items-center gap-2">
                    <button id="fraudPrevBtn" onclick="changeFraudPage(-1)" disabled class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800 text-slate-300 transition">Previous</button>
                    <button id="fraudNextBtn" onclick="changeFraudPage(1)" disabled class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800 text-slate-300 transition">Next</button>
                </div>
            </div>
        </section>

        <!-- ========================================== -->
        <!-- VIEW 11: AGENT MANAGEMENT SYSTEM -->
        <!-- ========================================== -->
        <section id="adminView-agents" class="admin-view hidden space-y-6">
            <!-- Summary Metric Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Total Agents</span>
                    <p class="text-2xl sm:text-3xl font-black text-white font-mono mt-1" id="agentTotalCount">0</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Active: <strong class="text-emerald-400" id="agentActiveCount">0</strong> &bull; Suspended: <strong class="text-red-400" id="agentSuspendedCount">0</strong></span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Agent Downstream Vol</span>
                    <p class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono mt-1" id="agentTotalVolume">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Across eligible levels A-Z</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Current Week Comm</span>
                    <p class="text-2xl sm:text-3xl font-black text-amber-400 font-mono mt-1" id="agentWeekCommission">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Mon 00:00 - Sun 23:59:59 UTC</span>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">Paid Commissions</span>
                    <p class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono mt-1" id="agentPaidCommission">₹0.00</p>
                    <span class="text-[11px] text-slate-500 mt-1 block">Pending: <strong class="text-amber-400 font-mono" id="agentPendingCommission">₹0.00</strong></span>
                </div>
            </div>

            <!-- Action & Filter Bar -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-slate-900 p-4 rounded-2xl border border-slate-800 shadow-sm">
                <div class="flex flex-1 items-center gap-2">
                    <div class="relative flex-1 max-w-xs">
                        <input type="text" id="agentSearchInput" oninput="debounceAgentSearch()" placeholder="Search Agent ID, Name, Phone..."
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                        <svg class="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <select id="agentStatusFilter" onchange="loadAdminAgents()" class="bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="all">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button onclick="openCreateAgentModal()" class="px-4 py-2 bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-bold text-xs rounded-xl shadow-lg shadow-amber-500/20 transition flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Create Agent</span>
                    </button>
                    <button onclick="openWeeklyPayoutsModal()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs rounded-xl border border-slate-700 transition flex items-center gap-1.5">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        <span>Weekly Payouts & Adjustments</span>
                    </button>
                    <button onclick="loadAdminAgents()" class="p-2 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-xl border border-slate-700 transition" title="Refresh Agents">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
            </div>

            <!-- Agents Data Table -->
            <div class="overflow-x-auto bg-slate-900 border border-slate-800 rounded-2xl shadow-sm">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950 text-slate-400 text-[11px] uppercase border-b border-slate-800 tracking-wider">
                        <tr>
                            <th class="p-3.5">Agent ID</th>
                            <th class="p-3.5">Agent Name & Contact</th>
                            <th class="p-3.5">Assigned Root Referral</th>
                            <th class="p-3.5 text-right">Commission Rate</th>
                            <th class="p-3.5 text-center">Eligible Levels</th>
                            <th class="p-3.5 text-right">Referred Users</th>
                            <th class="p-3.5 text-right">Total Vol / Earned</th>
                            <th class="p-3.5 text-right">This Week (Mon-Sun)</th>
                            <th class="p-3.5 text-center">Status</th>
                            <th class="p-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminAgentsTableBody" class="divide-y divide-slate-800/60 font-mono">
                        <tr>
                            <td colspan="10" class="p-8 text-center text-slate-500 font-sans">Loading agent accounts...</td>
                        </tr>
                    </tbody>
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

    <!-- ========================================== -->
    <!-- MODAL 3: ADD ADMINISTRATOR (Part 10, Item 4) -->
    <!-- ========================================== -->
    <div id="addAdminModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-5">
            <button onclick="closeAddAdminModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h3 class="text-lg font-bold text-white">Add New Administrator</h3>
                <p class="text-xs text-slate-400 mt-0.5">Create an administrative account with role-based team permissions</p>
            </div>

            <form onsubmit="handleAddAdminSubmit(event)" class="space-y-4">
                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Mobile Phone (Login ID) *</label>
                    <input type="tel" id="newAdminPhone" required placeholder="9876543210" pattern="[0-9]{10}" maxlength="10"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Display Name (Optional)</label>
                    <input type="text" id="newAdminName" placeholder="e.g. Finance Operations"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Email Address (Optional)</label>
                    <input type="email" id="newAdminEmail" placeholder="admin@jelipay.in"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Initial Password (min 6 characters) *</label>
                    <input type="password" id="newAdminPassword" required minlength="6" placeholder="Set secure password"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <div>
                    <label class="text-xs font-semibold text-slate-300 block mb-1">Team Role & Permissions *</label>
                    <select id="newAdminRole" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                        <option value="support_agent">Support Team (Disputes & Customer Care)</option>
                        <option value="finance_manager">Finance Team (Ledgers & Balances)</option>
                        <option value="order_manager">Order Team (Orders & Trading)</option>
                        <option value="super_admin">Super Admin (Full Platform Control)</option>
                    </select>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-black py-3 rounded-xl text-xs uppercase tracking-wider transition">
                    Create Administrator
                </button>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 4: EVIDENCE INSPECTION LIGHTBOX (Part 11, Item 5) -->
    <!-- ========================================== -->
    <div id="evidenceLightboxModal" class="fixed inset-0 bg-slate-950/90 backdrop-blur-md z-50 flex items-center justify-center p-3 sm:p-6 hidden" onclick="if(event.target === this) closeEvidenceLightbox();">
        <div class="relative max-w-4xl w-full bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl flex flex-col max-h-[92vh]">
            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-3.5 border-b border-slate-800 bg-slate-950/90">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <div>
                        <h3 id="lightboxTitle" class="text-sm font-bold text-white">Payment Screenshot Inspection</h3>
                        <p id="lightboxSubtitle" class="text-[11px] text-slate-400 font-mono">Image 1 of 1</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a id="lightboxDownloadBtn" href="#" target="_blank" rel="noopener noreferrer" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold flex items-center gap-1 transition" title="Open Full Size">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        <span class="hidden sm:inline">Original</span>
                    </a>
                    <button onclick="closeEvidenceLightbox()" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-red-500/20 hover:text-red-400 text-slate-400 flex items-center justify-center transition" title="Close Lightbox (Esc)">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- Image Viewport -->
            <div class="relative flex-1 flex items-center justify-center p-4 bg-slate-950/60 overflow-hidden min-h-[320px]">
                <img id="lightboxImage" src="" alt="Evidence Screenshot" class="max-h-[70vh] max-w-full object-contain rounded-xl shadow-lg transition-transform duration-200">

                <!-- Navigation Controls for Multi-screenshot -->
                <button id="lightboxPrevBtn" onclick="navigateLightbox(-1)" class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/80 hover:bg-slate-800 border border-slate-700 text-white flex items-center justify-center shadow-lg transition hidden" title="Previous Image">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button id="lightboxNextBtn" onclick="navigateLightbox(1)" class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/80 hover:bg-slate-800 border border-slate-700 text-white flex items-center justify-center shadow-lg transition hidden" title="Next Image">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 5: FRAUD FORENSIC INSPECTION & OVERRIDE (Requirement 9) -->
    <!-- ========================================== -->
    <div id="fraudInspectionModal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-sm z-50 flex items-center justify-center p-3 sm:p-6 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-4xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl relative space-y-6">
            <button onclick="closeFraudInspectionModal()" class="absolute right-4 top-4 text-slate-400 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <!-- Modal Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-4 gap-2">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-lg font-black text-white" id="fraudModalTitle">Fraud Assessment #0</span>
                        <span id="fraudModalDecisionBadge" class="px-2 py-0.5 rounded text-xs font-bold font-mono bg-rose-500/20 text-rose-400 border border-rose-500/30">PENDING_REVIEW</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1 font-mono">
                        Target Order: <strong class="text-indigo-400" id="fraudModalOrderId">#0</strong> &bull;
                        Buyer Phone: <strong class="text-slate-200" id="fraudModalBuyerPhone">-</strong> &bull;
                        Analyzed: <span id="fraudModalAnalyzedAt" class="text-slate-400">-</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <span class="text-[10px] text-slate-500 block uppercase font-bold">Calculated Risk Score</span>
                        <span class="text-2xl font-black font-mono text-rose-400" id="fraudModalScore">0 / 100</span>
                    </div>
                </div>
            </div>

            <!-- Risk Score Progress Bar -->
            <div class="space-y-1">
                <div class="w-full bg-slate-950 rounded-full h-2.5 overflow-hidden border border-slate-800">
                    <div id="fraudModalScoreBar" class="h-2.5 bg-rose-500 transition-all duration-500" style="width: 0%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-500 font-mono">
                    <span>Low Risk (0–39)</span>
                    <span>Review Threshold (40–74)</span>
                    <span>Auto-Reject (≥75)</span>
                </div>
            </div>

            <!-- Forensics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-mono">
                <!-- Payment Reference & OCR Comparison -->
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2.5">
                    <span class="text-indigo-400 font-bold block uppercase tracking-wider font-sans text-xs">Payment & OCR Discrepancy Matrix</span>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Claimed Amount:</span> <strong class="text-white" id="fraudModalClaimedAmount">₹0.00</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Extracted OCR Amount:</span> <strong class="text-emerald-400" id="fraudModalOcrAmount">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Claimed UTR:</span> <strong class="text-slate-200 break-all" id="fraudModalClaimedUtr">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Extracted OCR UTR:</span> <strong class="text-indigo-300 break-all" id="fraudModalOcrUtr">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Normalized UTR:</span> <strong class="text-amber-400 break-all" id="fraudModalNormalizedUtr">-</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">OCR Detected Status:</span> <strong class="text-slate-300" id="fraudModalOcrStatus">-</strong></div>
                </div>

                <!-- Image Fingerprint & Perceptual Hash Details -->
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2.5">
                    <span class="text-amber-400 font-bold block uppercase tracking-wider font-sans text-xs">Visual & Cryptographic Evidence</span>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">SHA-256 Checksum:</span> <span class="text-slate-400 break-all text-[11px]" id="fraudModalSha256">-</span></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Perceptual dHash:</span> <span class="text-slate-300 break-all text-[11px]" id="fraudModalPhash">-</span></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Editing Tool Flags:</span> <strong class="text-rose-400" id="fraudModalEditingTools">None Detected</strong></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Screenshot View:</span> <button type="button" onclick="inspectModalEvidence()" class="text-indigo-400 hover:underline">View in Lightbox &rarr;</button></div>
                    <div class="flex justify-between"><span class="text-slate-500 font-sans">Reviewer Log:</span> <span class="text-slate-400 font-sans" id="fraudModalReviewerInfo">Not Reviewed</span></div>
                </div>
            </div>

            <!-- Reason Codes Explanation Breakdown -->
            <div class="space-y-2">
                <span class="text-xs font-bold text-slate-300 uppercase tracking-wider block font-sans">Detected Anomaly Signals & Reason Codes</span>
                <div id="fraudModalReasonList" class="flex flex-wrap gap-1.5 p-3 bg-slate-950 rounded-xl border border-slate-800 min-h-[44px]"></div>
            </div>

            <!-- Admin Review & Audit Form -->
            <div class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 space-y-3 font-sans">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-300 uppercase tracking-wider">Administrative Adjudication & Audit Override</span>
                    <span class="text-[11px] text-slate-500 font-mono">Requires fraud_review Permission</span>
                </div>
                <div>
                    <textarea id="fraudReviewNotes" rows="2" placeholder="Mandatory adjudication notes documenting the operational rationale for this review/override..."
                              class="w-full bg-slate-900 border border-slate-800 rounded-xl p-3 text-xs text-white focus:outline-none focus:border-rose-500"></textarea>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2.5">
                    <button type="button" onclick="submitFraudReview('dismissed')" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs rounded-xl transition">
                        Dismiss Alert
                    </button>
                    <button type="button" onclick="submitFraudReview('rejected')" class="px-4 py-2 bg-rose-600/20 hover:bg-rose-600/30 text-rose-400 border border-rose-500/30 font-bold text-xs rounded-xl transition">
                        Confirm Fraud (Block & Reject)
                    </button>
                    <button type="button" onclick="submitFraudReview('override_verified')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl transition shadow-md shadow-emerald-600/20">
                        Override & Approve Verification
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 5: CREATE AGENT MODAL -->
    <!-- ========================================== -->
    <div id="createAgentModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl relative space-y-5">
            <button onclick="closeCreateAgentModal()" class="absolute right-5 top-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    <span>Create Agent Account</span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Provision an authorized Agent with referral tree monitoring and Google Authenticator 2FA.</p>
            </div>

            <div id="createAgentAlert" class="hidden p-3 rounded-xl text-xs flex items-start gap-2"></div>

            <form onsubmit="handleCreateAgentSubmit(event)" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Agent User ID (Optional)</label>
                        <input type="text" id="newAgentCode" placeholder="Auto-generated (e.g. AGT1001)"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white font-mono uppercase focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Agent Full Name *</label>
                        <input type="text" id="newAgentName" required placeholder="e.g. Rajesh Kumar"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Mobile Phone</label>
                        <input type="tel" id="newAgentPhone" placeholder="e.g. 9876543210" maxlength="15"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Email Address</label>
                        <input type="email" id="newAgentEmail" placeholder="agent@jelipay.in"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Root Referral Code / User ID *</label>
                        <input type="text" id="newAgentRootRef" required placeholder="e.g. USER001 or 4"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white font-mono uppercase focus:outline-none focus:border-amber-500">
                        <span class="text-[10px] text-slate-500 block mt-1">Descendants of this root user (Levels A-Z) will earn commissions for this agent.</span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Commission Rate (%) *</label>
                        <div class="relative">
                            <input type="number" id="newAgentCommissionRate" required step="0.01" min="0" max="100" placeholder="e.g. 2.50"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 pr-8 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                            <span class="absolute right-3 top-2.5 text-xs text-slate-500 font-bold">%</span>
                        </div>
                        <span class="text-[10px] text-slate-500 block mt-1">One uniform rate applied across all assigned levels.</span>
                    </div>
                </div>

                <!-- Eligible Referral Levels Checkboxes -->
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Eligible Referral Levels (A through Z)</label>
                        <div class="flex items-center gap-1.5 text-[11px]">
                            <button type="button" onclick="selectAllAgentLevels(true)" class="text-amber-400 hover:underline">All A-Z</button>
                            <span class="text-slate-600">&bull;</span>
                            <button type="button" onclick="selectLevelsPreset('A,B,C,D,E')" class="text-amber-400 hover:underline">A-E Only</button>
                            <span class="text-slate-600">&bull;</span>
                            <button type="button" onclick="selectAllAgentLevels(false)" class="text-slate-400 hover:underline">Clear</button>
                        </div>
                    </div>
                    <div id="agentLevelsGrid" class="grid grid-cols-6 sm:grid-cols-9 gap-1.5 p-3 bg-slate-950 rounded-xl border border-slate-800 max-h-36 overflow-y-auto">
                        <!-- Populated by JS with A-Z checkboxes -->
                    </div>
                </div>

                <!-- Custom Password (optional) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Temporary Password (Optional)</label>
                    <input type="password" id="newAgentPassword" placeholder="Leave blank to auto-generate a secure password"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>

                <button type="submit" id="btnCreateAgentSubmit"
                        class="w-full bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-bold py-3 rounded-xl text-xs uppercase tracking-wider transition shadow-lg shadow-amber-500/20">
                    Create Agent & Generate Authenticator
                </button>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 6: ONE-TIME AGENT CREDENTIALS DISPLAY -->
    <!-- ========================================== -->
    <div id="agentCredentialsModal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-amber-500/40 rounded-3xl max-w-lg w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl relative space-y-5">
            <!-- Header -->
            <div class="text-center">
                <div class="inline-flex items-center justify-center h-12 w-12 rounded-2xl bg-emerald-500/20 text-emerald-400 mb-3 border border-emerald-500/30">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h3 class="text-lg font-bold text-white">Agent Credentials Provisioned</h3>
                <p class="text-xs text-slate-400 mt-1">Please copy and transmit these details to the agent now.</p>
            </div>

            <!-- Warning Banner -->
            <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs flex items-start gap-2.5">
                <svg class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <strong>One-Time Display Warning:</strong> The temporary password and raw TOTP secret key are shown only once. They are encrypted at rest and cannot be retrieved again after closing this window.
                </div>
            </div>

            <!-- Credentials Box -->
            <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-3 font-mono text-xs">
                <!-- Agent User ID -->
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase tracking-wider block font-sans">Agent User ID</span>
                        <strong class="text-white text-sm" id="credAgentCode">AGT1001</strong>
                    </div>
                    <button type="button" onclick="copyCredField('credAgentCode')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-amber-400 rounded-lg text-[11px] font-sans font-semibold transition">
                        Copy
                    </button>
                </div>

                <!-- Temporary Password -->
                <div class="flex items-center justify-between border-t border-slate-800/80 pt-2.5">
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase tracking-wider block font-sans">Temporary Password</span>
                        <strong class="text-amber-400 text-sm select-all" id="credPassword">••••••••</strong>
                    </div>
                    <button type="button" onclick="copyCredField('credPassword')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-amber-400 rounded-lg text-[11px] font-sans font-semibold transition">
                        Copy
                    </button>
                </div>

                <!-- Login URL -->
                <div class="flex items-center justify-between border-t border-slate-800/80 pt-2.5">
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase tracking-wider block font-sans">Agent Portal URL</span>
                        <span class="text-slate-300 text-[11px]" id="credLoginUrl">https://jelipay.in/agent/login</span>
                    </div>
                    <button type="button" onclick="copyCredField('credLoginUrl')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-amber-400 rounded-lg text-[11px] font-sans font-semibold transition">
                        Copy
                    </button>
                </div>

                <!-- TOTP Secret Key -->
                <div class="flex items-center justify-between border-t border-slate-800/80 pt-2.5">
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase tracking-wider block font-sans">TOTP Secret Key (Manual Entry)</span>
                        <strong class="text-indigo-300 text-xs break-all select-all" id="credTotpSecret">ABCD-EFGH</strong>
                    </div>
                    <button type="button" onclick="copyCredField('credTotpSecret')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-amber-400 rounded-lg text-[11px] font-sans font-semibold transition">
                        Copy
                    </button>
                </div>
            </div>

            <!-- Pure-PHP Vector SVG QR Code Container -->
            <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-center">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider block font-sans mb-3">Google Authenticator Setup QR Code</span>
                <div id="credQrSvgContainer" class="flex justify-center p-3 bg-white rounded-xl max-w-[200px] mx-auto shadow-inner">
                    <!-- SVG injected here -->
                </div>
                <p class="text-[11px] text-slate-500 mt-2 font-sans">Scan inside Google Authenticator &bull; Offline vector rendering</p>
            </div>

            <!-- Master Copy All Details Button & Close Button -->
            <div class="space-y-2">
                <button type="button" onclick="copyAllAgentDetails()" id="btnCopyAllDetails"
                        class="w-full py-3 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-black rounded-xl text-xs uppercase tracking-wider shadow-lg shadow-amber-500/20 transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                    <span>Copy All Details to Clipboard</span>
                </button>
                <button type="button" onclick="closeAgentCredentialsModal()"
                        class="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-xl text-xs transition">
                    I Have Safely Recorded Credentials &bull; Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 7: EDIT AGENT SETTINGS -->
    <!-- ========================================== -->
    <div id="editAgentModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full max-h-[90vh] overflow-y-auto p-6 shadow-2xl relative space-y-4">
            <button onclick="closeEditAgentModal()" class="absolute right-5 top-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <span>Edit Agent Settings: </span>
                    <span id="editAgentTitleCode" class="text-amber-400 font-mono">AGT1001</span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Modify commission rate, assigned levels, and contact info.</p>
            </div>

            <input type="hidden" id="editAgentId">

            <form onsubmit="handleEditAgentSubmit(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Display Name</label>
                    <input type="text" id="editAgentName" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Phone</label>
                        <input type="tel" id="editAgentPhone"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Email</label>
                        <input type="email" id="editAgentEmail"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Commission Rate (%)</label>
                    <input type="number" id="editAgentCommissionRate" step="0.01" min="0" max="100" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Eligible Referral Levels</label>
                        <div class="flex items-center gap-1.5 text-[11px]">
                            <button type="button" onclick="selectAllEditAgentLevels(true)" class="text-amber-400 hover:underline">All A-Z</button>
                            <span class="text-slate-600">&bull;</span>
                            <button type="button" onclick="selectAllEditAgentLevels(false)" class="text-slate-400 hover:underline">Clear</button>
                        </div>
                    </div>
                    <div id="editAgentLevelsGrid" class="grid grid-cols-6 sm:grid-cols-9 gap-1.5 p-3 bg-slate-950 rounded-xl border border-slate-800 max-h-36 overflow-y-auto">
                        <!-- Populated by JS -->
                    </div>
                </div>

                <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                    Save Changes
                </button>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 8: ADMIN WEEKLY PAYOUTS & ADJUSTMENTS -->
    <!-- ========================================== -->
    <div id="adminWeeklyPayoutsModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-3 sm:p-6 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-5xl w-full max-h-[92vh] overflow-y-auto p-6 shadow-2xl relative space-y-5">
            <button onclick="closeWeeklyPayoutsModal()" class="absolute right-5 top-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                <div>
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        <span>Agent Weekly Commission Payouts Console</span>
                    </h3>
                    <p class="text-xs text-slate-400">Weekly accounting cycles run strictly Monday 00:00:00 to Sunday 23:59:59 UTC</p>
                </div>
                <button onclick="triggerGenerateStatements()" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 self-start sm:self-auto">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Compile / Sync Statements</span>
                </button>
            </div>

            <!-- Filter Controls -->
            <div class="flex flex-wrap items-center gap-3 bg-slate-950 p-3.5 rounded-xl border border-slate-800 text-xs">
                <div>
                    <label class="block text-[10px] font-semibold text-slate-500 uppercase tracking-wider mb-1">Payout Status</label>
                    <select id="payoutStatusFilter" onchange="loadAdminWeeklyStatements()" class="bg-slate-900 border border-slate-800 rounded-lg px-2.5 py-1.5 text-white text-xs">
                        <option value="all">All Statuses</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="approved">Approved for Payout</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="ml-auto">
                    <button onclick="loadAdminWeeklyStatements()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition">
                        Refresh List
                    </button>
                </div>
            </div>

            <!-- Statements Table -->
            <div class="overflow-x-auto bg-slate-950 border border-slate-800 rounded-2xl">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-900 text-slate-400 text-[10px] uppercase border-b border-slate-800 tracking-wider">
                        <tr>
                            <th class="p-3">Statement</th>
                            <th class="p-3">Agent</th>
                            <th class="p-3">Period (Mon-Sun)</th>
                            <th class="p-3 text-right">Orders</th>
                            <th class="p-3 text-right">Volume</th>
                            <th class="p-3 text-right">Gross Comm</th>
                            <th class="p-3 text-right">Adjustment</th>
                            <th class="p-3 text-right">Net Commission</th>
                            <th class="p-3 text-center">Status</th>
                            <th class="p-3">Payout Details</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminStatementsTableBody" class="divide-y divide-slate-800/60 font-mono">
                        <tr>
                            <td colspan="11" class="p-8 text-center text-slate-500 font-sans">Loading statements...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 9: STATEMENT ADJUSTMENT MODAL -->
    <!-- ========================================== -->
    <div id="statementAdjustmentModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeStatementAdjustmentModal()" class="absolute right-5 top-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <span>Adjust Statement: </span>
                <span id="adjStatementCode" class="text-amber-400 font-mono">STMT-001</span>
            </h3>
            <p class="text-xs text-slate-400">Add an audit-logged administrative bonus (+) or deduction (-) with a mandatory reason.</p>

            <input type="hidden" id="adjStatementId">

            <form onsubmit="submitStatementAdjustment(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Adjustment Amount (₹) *</label>
                    <input type="number" id="adjAmount" step="0.01" required placeholder="e.g. +500.00 or -200.00"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white font-mono focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Mandatory Operational Reason *</label>
                    <textarea id="adjReason" rows="3" required placeholder="Explain why this adjustment is being made (e.g. performance bonus, reconciled dispute adjustment)..."
                              class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-xs text-white focus:outline-none focus:border-amber-500"></textarea>
                </div>
                <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition">
                    Save Adjustment
                </button>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 10: RECORD STATEMENT PAYOUT -->
    <!-- ========================================== -->
    <div id="recordPayoutModal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="closeRecordPayoutModal()" class="absolute right-5 top-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div>
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Record Agent Payout Settlement</span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Mark statement as Paid with banking transaction reference.</p>
            </div>

            <div class="bg-slate-950 p-3.5 rounded-xl border border-slate-800 font-mono text-xs flex justify-between items-center">
                <span class="text-slate-400 font-sans">Net Payout Amount:</span>
                <strong class="text-emerald-400 text-base" id="payoutModalNetComm">₹0.00</strong>
            </div>

            <input type="hidden" id="payoutModalStatementId">

            <form onsubmit="submitRecordPayout(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Payment Reference (UTR / Transfer ID) *</label>
                    <input type="text" id="payoutRef" required placeholder="e.g. UTR429381029384"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white font-mono uppercase focus:outline-none focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Payment Date (UTC) *</label>
                    <input type="datetime-local" id="payoutDate" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Notes (Optional)</label>
                    <input type="text" id="payoutNotes" placeholder="e.g. Bank IMPS transfer via HDFC"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-2.5 rounded-xl text-xs uppercase tracking-wider transition shadow-lg shadow-emerald-600/20">
                    Confirm Payout & Mark as Paid
                </button>
            </form>
        </div>
    </div>

    <!-- Global Formatting Fallback (Protects against stale CDN/browser JS caches) -->
    <script>
        if (typeof window.formatCurrency !== 'function') {
            window.formatCurrency = function(val) {
                return Number(val || 0).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            };
        }
    </script>
    <!-- Dedicated Admin Controller with Dynamic Timestamp Cache Buster -->
    <script src="/public/js/admin.js?v=<?= file_exists(__DIR__ . '/public/js/admin.js') ? filemtime(__DIR__ . '/public/js/admin.js') : time() ?>"></script>
</body>
</html>
