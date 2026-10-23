<?php
/**
 * Agent Dedicated Intelligence & Commission Dashboard
 * Route: /agent/dashboard
 * Features live summary metrics, purchase history with masked trader PII,
 * Monday-to-Sunday weekly statements, and 2FA password management.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/schema_installer.php';
require_once __DIR__ . '/src/AgentManager.php';

SchemaInstaller::ensureSchema();

$agent = AgentManager::getCurrentAgent();
if (!$agent) {
    header('Location: /agent/login');
    exit;
}

$agentId = (int)$agent['id'];
$stats = AgentManager::getAgentDashboardStats($agentId);
$isAgentImpersonating = !empty($_SESSION['is_agent_impersonating']);
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Agent Intelligence Dashboard | JeliPay</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
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

    <!-- Admin Support Mode Banner -->
    <?php if ($isAgentImpersonating): ?>
    <div class="bg-amber-500 text-slate-950 px-4 py-2.5 text-xs sm:text-sm font-bold flex items-center justify-between shadow-lg sticky top-0 z-50">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span>ADMIN SUPPORT MODE ACTIVE &bull; Viewing Agent <?= htmlspecialchars($agent['agent_code']) ?> in read-only mode. All modifications are locked.</span>
        </div>
        <button onclick="exitAgentSupportMode()" class="bg-slate-950 text-white px-3.5 py-1.5 rounded-lg hover:bg-slate-800 transition text-xs font-bold shadow flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <span>Exit Support Mode</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- Top Navigation Bar -->
    <header class="bg-slate-900/90 backdrop-blur border-b border-slate-800 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Brand & Agent Badge -->
                <div class="flex items-center gap-3">
                    <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-amber-600 to-amber-400 flex items-center justify-center shadow-lg shadow-amber-500/20 text-slate-950 font-black">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-extrabold text-white">JeliPay Agent Portal</span>
                            <span class="text-[11px] font-mono font-bold uppercase px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                <?= htmlspecialchars($agent['agent_code']) ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 hidden sm:block">Agent: <span class="text-slate-200 font-medium"><?= htmlspecialchars($agent['name']) ?></span> &bull; Rate: <span class="text-amber-400 font-mono font-bold"><?= number_format((float)$agent['commission_rate'], 2) ?>%</span> &bull; Tree Root: <span class="text-slate-300 font-mono"><?= htmlspecialchars($stats['root_referral_code'] ?? 'N/A') ?></span></p>
                    </div>
                </div>

                <!-- Right Actions -->
                <div class="flex items-center gap-2 sm:gap-3">
                    <?php if ($isAgentImpersonating): ?>
                    <button onclick="exitAgentSupportMode()"
                            class="px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 text-xs font-bold shadow-md flex items-center gap-1.5 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <span>Exit Support Mode</span>
                    </button>
                    <?php else: ?>
                    <button onclick="openChangePasswordModal()"
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold border border-slate-700 flex items-center gap-1.5 transition">
                        <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <span class="hidden sm:inline">Change Password</span>
                    </button>
                    <button onclick="handleLogout()"
                            class="px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 text-xs font-semibold border border-rose-500/30 flex items-center gap-1.5 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <span>Logout</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Privacy & Protection Notice -->
        <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 flex items-start gap-3 text-xs text-slate-400">
            <svg class="w-5 h-5 text-amber-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <span class="font-semibold text-slate-200">Trader Privacy Protection Active:</span>
                Downstream user mobile numbers and personal names are masked (e.g. 98****21) to protect customer confidentiality while enabling full purchase and commission verification across the referral hierarchy.
            </div>
        </div>

        <!-- 8 Executive Summary Cards Grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- 1. Total Referred Users -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden group hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Referred Users</span>
                    <button onclick="toggleLevelBreakdownModal()" class="text-[11px] text-amber-400 hover:text-amber-300 font-mono">View Breakdown &rarr;</button>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono" id="statTotalUsers"><?= number_format($stats['total_referred_users']) ?></div>
                <div class="text-xs text-slate-500 mt-1">Downstream tree (26 referral depths)</div>
            </div>

            <!-- 2. Current Week Volume & Commission (Monday-Sunday UTC Highlight) -->
            <div class="bg-gradient-to-br from-amber-950/40 via-slate-900 to-slate-900 border border-amber-500/30 rounded-2xl p-4 sm:p-5 relative overflow-hidden">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-amber-400 uppercase tracking-wider">Current Week (Mon-Sun)</span>
                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 font-bold">W<?= $stats['current_week_number'] ?></span>
                </div>
                <div class="text-2xl sm:text-3xl font-black text-amber-400 font-mono">₹<?= number_format($stats['current_week_commission'], 2) ?></div>
                <div class="text-xs text-slate-400 mt-1">From ₹<?= number_format($stats['current_week_volume'], 2) ?> volume (<?= $stats['current_week_orders'] ?> orders)</div>
            </div>

            <!-- 3. Today's Volume -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Today's Volume (UTC)</span>
                    <span class="text-[10px] font-mono text-emerald-400">Live</span>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono">₹<?= number_format($stats['today_volume'], 2) ?></div>
                <div class="text-xs text-slate-500 mt-1">Comm: <span class="text-emerald-400 font-mono font-semibold">+₹<?= number_format($stats['today_commission'], 2) ?></span> (<?= $stats['today_orders'] ?> orders)</div>
            </div>

            <!-- 4. Rolling 7-Day Volume -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Rolling 7 Days</span>
                    <span class="text-[10px] font-mono text-slate-500">Past 168h</span>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono">₹<?= number_format($stats['rolling_7d_volume'], 2) ?></div>
                <div class="text-xs text-slate-500 mt-1">Comm: <span class="text-amber-400 font-mono font-semibold">₹<?= number_format($stats['rolling_7d_commission'], 2) ?></span> (<?= $stats['rolling_7d_orders'] ?> orders)</div>
            </div>

            <!-- 5. Total All-Time Volume -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Volume</span>
                    <span class="text-[10px] font-mono text-slate-500">All-Time</span>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono">₹<?= number_format($stats['total_volume'], 2) ?></div>
                <div class="text-xs text-slate-500 mt-1">Across <?= number_format($stats['total_orders']) ?> completed orders</div>
            </div>

            <!-- 6. Total Commission Earned -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Commission</span>
                    <span class="text-[10px] font-mono text-slate-500">Gross</span>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono">₹<?= number_format($stats['total_commission_earned'], 2) ?></div>
                <div class="text-xs text-slate-500 mt-1">Snapshotted @ <?= number_format((float)$agent['commission_rate'], 2) ?>%</div>
            </div>

            <!-- 7. Paid vs Pending Commissions -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Paid / Pending</span>
                    <span class="text-[10px] font-mono text-emerald-400">Payouts</span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-xl sm:text-2xl font-bold text-emerald-400 font-mono">₹<?= number_format($stats['paid_commission'], 2) ?></span>
                    <span class="text-xs text-slate-500">/ ₹<?= number_format($stats['pending_commission'], 2) ?></span>
                </div>
                <div class="text-xs text-slate-500 mt-1"><span class="text-emerald-400 font-semibold">Settled</span> vs awaiting cycle review</div>
            </div>

            <!-- 8. Last Payout Details -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 relative overflow-hidden hover:border-slate-700 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Last Payout</span>
                    <span class="text-[10px] font-mono text-slate-500"><?= $stats['last_payout'] ? htmlspecialchars($stats['last_payout']['paid_at']) : 'None' ?></span>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono">
                    <?= $stats['last_payout'] ? '₹' . number_format($stats['last_payout']['amount'], 2) : '—' ?>
                </div>
                <div class="text-xs text-slate-400 mt-1 truncate" title="<?= $stats['last_payout']['reference'] ?? '' ?>">
                    <?= $stats['last_payout'] ? 'Ref: ' . htmlspecialchars($stats['last_payout']['reference']) : 'No payouts recorded yet' ?>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs for Sections -->
        <div class="flex items-center gap-2 border-b border-slate-800 pb-3">
            <button onclick="switchTab('purchases')" id="tabBtnPurchases"
                    class="px-4 py-2 rounded-xl text-sm font-bold bg-amber-500 text-slate-950 shadow-md shadow-amber-500/20 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                <span>Purchase History</span>
            </button>
            <button onclick="switchTab('statements')" id="tabBtnStatements"
                    class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-400 hover:text-white hover:bg-slate-900 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <span>Weekly Statements (Mon-Sun)</span>
            </button>
            <button onclick="switchTab('hierarchy')" id="tabBtnHierarchy"
                    class="px-4 py-2 rounded-xl text-sm font-semibold text-slate-400 hover:text-white hover:bg-slate-900 transition flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                <span>Referral Tree Structure</span>
            </button>
        </div>

        <!-- SECTION 1: PURCHASE HISTORY -->
        <div id="sectionPurchases" class="space-y-4">
            <!-- Filter Bar -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Search Order ID</label>
                    <input type="text" id="filterOrderId" placeholder="e.g. 104"
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white placeholder:text-slate-600 focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <div class="w-36">
                    <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Referral Depth</label>
                    <select id="filterLevel"
                            class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:ring-1 focus:ring-amber-500">
                        <option value="">All Depths (1-26)</option>
                        <?php foreach (range('A', 'Z') as $l): ?>
                            <option value="<?= $l ?>">Depth <?= ord($l) - 64 ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-40">
                    <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">From Date</label>
                    <input type="date" id="filterDateFrom"
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <div class="w-40">
                    <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">To Date</label>
                    <input type="date" id="filterDateTo"
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-sm text-white focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <div class="flex items-end gap-2 pt-5">
                    <button onclick="loadPurchases(0)"
                            class="px-4 py-2 bg-amber-500 hover:bg-amber-400 text-slate-950 text-sm font-bold rounded-xl transition">
                        Filter
                    </button>
                    <button onclick="resetPurchaseFilters()"
                            class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm rounded-xl transition">
                        Reset
                    </button>
                </div>
            </div>

            <!-- Purchases Table -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/80 text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-3.5 font-semibold">Order #</th>
                                <th class="px-4 py-3.5 font-semibold">Buyer (Masked PII)</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Base Purchase</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Commission Rate</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Commission Earned</th>
                                <th class="px-4 py-3.5 font-semibold text-center">Referral Level</th>
                                <th class="px-4 py-3.5 font-semibold">Completed At (UTC)</th>
                                <th class="px-4 py-3.5 font-semibold text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="purchasesTableBody" class="divide-y divide-slate-800/60 font-mono text-xs">
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-slate-500 font-sans">Loading purchases...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <div class="px-4 py-3 bg-slate-950/80 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                    <span id="paginationInfo">Showing 0 of 0</span>
                    <div class="flex items-center gap-2">
                        <button id="btnPrevPage" onclick="prevPurchasePage()" disabled
                                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-white font-medium transition">
                            &larr; Previous
                        </button>
                        <button id="btnNextPage" onclick="nextPurchasePage()" disabled
                                class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-white font-medium transition">
                            Next &rarr;
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 2: WEEKLY STATEMENTS -->
        <div id="sectionStatements" class="hidden space-y-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Weekly Commission Statements</h2>
                        <p class="text-xs text-slate-400">Statements run Monday 00:00:00 to Sunday 23:59:59 UTC cycles</p>
                    </div>
                    <span class="text-xs font-mono px-3 py-1 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/30">
                        Current ISO Week: W<?= $stats['current_week_number'] ?>, <?= $stats['current_week_year'] ?>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/80 text-[11px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-3.5 font-semibold">Statement Code</th>
                                <th class="px-4 py-3.5 font-semibold">Week Period (Mon-Sun)</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Orders</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Total Volume</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Gross Comm</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Adjustment</th>
                                <th class="px-4 py-3.5 font-semibold text-right">Net Commission</th>
                                <th class="px-4 py-3.5 font-semibold text-center">Payout Status</th>
                                <th class="px-4 py-3.5 font-semibold">Payment Ref / Date</th>
                            </tr>
                        </thead>
                        <tbody id="statementsTableBody" class="divide-y divide-slate-800/60 font-mono text-xs">
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-slate-500 font-sans">Loading statements...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- SECTION 3: REFERRAL HIERARCHY STRUCTURE -->
        <div id="sectionHierarchy" class="hidden space-y-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Referral Hierarchy Depth Breakdown</h2>
                        <p class="text-xs text-slate-400">Total referred users under Root User <span class="text-amber-400 font-mono"><?= htmlspecialchars($stats['root_referral_code'] ?? 'N/A') ?></span> mapped across the referral network</p>
                    </div>
                    <span class="text-sm font-mono font-bold text-white bg-slate-800 px-3 py-1 rounded-xl">
                        Total Users: <?= number_format($stats['total_referred_users']) ?>
                    </span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-3">
                    <?php foreach ($stats['users_by_level'] as $lvl => $cnt): ?>
                        <div class="bg-slate-950 border <?= $cnt > 0 ? 'border-amber-500/40 bg-amber-950/10' : 'border-slate-800/70' ?> rounded-xl p-3 text-center">
                            <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-widest block">Depth <?= ord($lvl) - 64 ?></span>
                            <div class="text-lg font-mono font-bold <?= $cnt > 0 ? 'text-amber-400' : 'text-slate-600' ?> mt-1">
                                <?= number_format($cnt) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal: Change Password -->
    <div id="passwordModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full p-6 shadow-2xl relative">
            <button onclick="closeChangePasswordModal()" class="absolute top-5 right-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <h3 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <span>Change Agent Password</span>
            </h3>
            <p class="text-xs text-slate-400 mb-5">Requires your current password and a live 6-digit Google Authenticator code.</p>

            <div id="pwdAlert" class="hidden mb-4 p-3 rounded-xl text-xs flex items-start gap-2"></div>

            <form id="changePwdForm" class="space-y-4" onsubmit="submitChangePassword(event)">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Current Password</label>
                    <input type="password" id="curPassword" required
                           class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-sm focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">New Password (Min 8 chars)</label>
                    <input type="password" id="newPassword" minlength="8" required
                           class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-sm focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1">Google Authenticator Code</label>
                    <input type="text" id="pwdTotpCode" maxlength="6" inputmode="numeric" placeholder="123456" required
                           class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-center font-mono tracking-widest text-base focus:outline-none focus:ring-1 focus:ring-amber-500">
                </div>
                <button type="submit" id="btnSubmitPwd"
                        class="w-full py-3 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold rounded-xl transition text-sm">
                    Update Password
                </button>
            </form>
        </div>
    </div>

    <!-- Modal: Level Breakdown Overview -->
    <div id="levelModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 shadow-2xl relative">
            <button onclick="toggleLevelBreakdownModal()" class="absolute top-5 right-5 text-slate-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <h3 class="text-lg font-bold text-white mb-1">Downstream User Tree Breakdown</h3>
            <p class="text-xs text-slate-400 mb-5">Number of registered users by referral depth level under Root User</p>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-h-96 overflow-y-auto pr-1">
                <?php foreach ($stats['users_by_level'] as $lvl => $cnt): ?>
                    <div class="bg-slate-950 border border-slate-800 rounded-xl p-3 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-slate-200">Depth <?= ord($lvl) - 64 ?></span>
                        </div>
                        <span class="font-mono font-bold text-sm <?= $cnt > 0 ? 'text-amber-400' : 'text-slate-600' ?>"><?= number_format($cnt) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Frontend Script -->
    <script>
        let currentPurchaseOffset = 0;
        const purchaseLimit = 50;
        let totalPurchasesCount = 0;

        document.addEventListener('DOMContentLoaded', () => {
            loadPurchases(0);
            loadStatements();
        });

        function switchTab(tab) {
            document.getElementById('sectionPurchases').classList.toggle('hidden', tab !== 'purchases');
            document.getElementById('sectionStatements').classList.toggle('hidden', tab !== 'statements');
            document.getElementById('sectionHierarchy').classList.toggle('hidden', tab !== 'hierarchy');

            const btnPurchases = document.getElementById('tabBtnPurchases');
            const btnStatements = document.getElementById('tabBtnStatements');
            const btnHierarchy = document.getElementById('tabBtnHierarchy');

            const activeClass = 'bg-amber-500 text-slate-950 shadow-md shadow-amber-500/20 font-bold';
            const inactiveClass = 'text-slate-400 hover:text-white hover:bg-slate-900 font-semibold';

            btnPurchases.className = 'px-4 py-2 rounded-xl text-sm transition flex items-center gap-2 ' + (tab === 'purchases' ? activeClass : inactiveClass);
            btnStatements.className = 'px-4 py-2 rounded-xl text-sm transition flex items-center gap-2 ' + (tab === 'statements' ? activeClass : inactiveClass);
            btnHierarchy.className = 'px-4 py-2 rounded-xl text-sm transition flex items-center gap-2 ' + (tab === 'hierarchy' ? activeClass : inactiveClass);
        }

        async function loadPurchases(offset = 0) {
            currentPurchaseOffset = offset;
            const tbody = document.getElementById('purchasesTableBody');
            tbody.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-slate-500 font-sans">Loading purchases...</td></tr>';

            const orderId = document.getElementById('filterOrderId').value.trim();
            const level = document.getElementById('filterLevel').value;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;

            const params = new URLSearchParams({
                action: 'purchases',
                limit: purchaseLimit,
                offset: currentPurchaseOffset
            });
            if (orderId) params.append('order_id', orderId);
            if (level) params.append('level', level);
            if (dateFrom) params.append('date_from', dateFrom);
            if (dateTo) params.append('date_to', dateTo);

            try {
                const res = await fetch(`/api/agent.php?${params.toString()}`);
                const data = await res.json();

                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-8 text-center text-rose-400 font-sans">${escapeHtml(data.error || 'Failed to load purchases')}</td></tr>`;
                    return;
                }

                totalPurchasesCount = data.total || 0;
                const rows = Array.isArray(data.purchases) ? data.purchases : [];

                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-slate-500 font-sans">No purchase records found matching criteria.</td></tr>';
                } else {
                    tbody.innerHTML = rows.map(r => `
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="px-4 py-3 font-bold text-white">#${r.child_order_id}</td>
                            <td class="px-4 py-3 text-slate-300">
                                <div>${escapeHtml(r.buyer_masked_phone || '')}</div>
                                <div class="text-[10px] text-slate-500 font-sans">${escapeHtml(r.buyer_masked_name || '')}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-white font-semibold">₹${formatCurrency(r.purchase_amount)}</td>
                            <td class="px-4 py-3 text-right text-slate-400">${Number(r.commission_rate || 0).toFixed(2)}%</td>
                            <td class="px-4 py-3 text-right font-bold text-amber-400">+₹${formatCurrency(r.commission_amount)}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2.5 py-1 rounded bg-slate-800 text-amber-300 border border-slate-700 font-bold font-mono text-xs">
                                    Depth ${r.referral_depth || (r.referral_level ? (r.referral_level.charCodeAt(0) - 64) : 1)}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-400 text-[11px]">${escapeHtml(r.completed_at || '')}</td>
                            <td class="px-4 py-3 text-center">
                                ${r.status === 'earned'
                                    ? '<span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-sans font-bold">Earned</span>'
                                    : '<span class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[10px] font-sans font-bold">Reversed</span>'
                                }
                            </td>
                        </tr>
                    `).join('');
                }

                updatePaginationUI();
            } catch (e) {
                console.error('loadPurchases error:', e);
                tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-8 text-center text-rose-400 font-sans">Server communication error (${escapeHtml(e.message || 'Network/Server Error')}).</td></tr>`;
            }
        }

        function updatePaginationUI() {
            const start = totalPurchasesCount > 0 ? currentPurchaseOffset + 1 : 0;
            const end = Math.min(currentPurchaseOffset + purchaseLimit, totalPurchasesCount);
            document.getElementById('paginationInfo').textContent = `Showing ${start} - ${end} of ${totalPurchasesCount} purchases`;

            document.getElementById('btnPrevPage').disabled = (currentPurchaseOffset <= 0);
            document.getElementById('btnNextPage').disabled = (currentPurchaseOffset + purchaseLimit >= totalPurchasesCount);
        }

        function prevPurchasePage() {
            if (currentPurchaseOffset > 0) {
                loadPurchases(Math.max(0, currentPurchaseOffset - purchaseLimit));
            }
        }

        function nextPurchasePage() {
            if (currentPurchaseOffset + purchaseLimit < totalPurchasesCount) {
                loadPurchases(currentPurchaseOffset + purchaseLimit);
            }
        }

        function resetPurchaseFilters() {
            document.getElementById('filterOrderId').value = '';
            document.getElementById('filterLevel').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            loadPurchases(0);
        }

        async function loadStatements() {
            const tbody = document.getElementById('statementsTableBody');
            tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-slate-500 font-sans">Loading statements...</td></tr>';

            try {
                const res = await fetch('/api/agent.php?action=statements');
                const data = await res.json();

                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="9" class="px-4 py-8 text-center text-rose-400 font-sans">${escapeHtml(data.error || 'Failed to load statements')}</td></tr>`;
                    return;
                }

                const rows = Array.isArray(data.statements) ? data.statements : [];
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="9" class="px-4 py-8 text-center text-slate-500 font-sans">No weekly statements generated yet. Statements are compiled every Monday 00:00 UTC.</td></tr>';
                } else {
                    tbody.innerHTML = rows.map(s => {
                        let statusBadge = '';
                        if (s.payout_status === 'paid') {
                            statusBadge = '<span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-[10px] font-sans font-bold">Paid</span>';
                        } else if (s.payout_status === 'approved') {
                            statusBadge = '<span class="px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 border border-sky-500/30 text-[10px] font-sans font-bold">Approved</span>';
                        } else {
                            statusBadge = '<span class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30 text-[10px] font-sans font-bold">Pending Review</span>';
                        }

                        const parseDate = (d) => {
                            if (!d) return '—';
                            const clean = d.replace(' ', 'T') + (d.includes('Z') ? '' : 'Z');
                            const dt = new Date(clean);
                            return isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                        };
                        const startFmt = parseDate(s.start_date);
                        const endFmt = parseDate(s.end_date);

                        return `
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="px-4 py-3 font-bold text-white">${escapeHtml(s.statement_code || '')}</td>
                                <td class="px-4 py-3 text-slate-300">
                                    <div>${startFmt} - ${endFmt}</div>
                                    <div class="text-[10px] text-slate-500 font-sans">Week ${s.week_number}, ${s.week_year}</div>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-300">${s.total_orders || 0}</td>
                                <td class="px-4 py-3 text-right text-slate-300">₹${formatCurrency(s.total_volume)}</td>
                                <td class="px-4 py-3 text-right text-slate-300">₹${formatCurrency(s.gross_commission)}</td>
                                <td class="px-4 py-3 text-right ${Number(s.adjustment_amount || 0) !== 0 ? 'text-amber-400 font-bold' : 'text-slate-500'}"
                                    title="${escapeHtml(s.adjustment_reason || '')}">
                                    ${Number(s.adjustment_amount || 0) > 0 ? '+' : ''}₹${formatCurrency(s.adjustment_amount)}
                                </td>
                                <td class="px-4 py-3 text-right font-black text-amber-400 text-sm">₹${formatCurrency(s.net_commission)}</td>
                                <td class="px-4 py-3 text-center">${statusBadge}</td>
                                <td class="px-4 py-3 text-slate-300 text-[11px]">
                                    ${s.payout_reference ? `<div class="font-bold text-slate-200">Ref: ${escapeHtml(s.payout_reference)}</div>` : '<div class="text-slate-600">—</div>'}
                                    ${s.paid_at ? `<div class="text-[10px] text-slate-500 font-sans">${escapeHtml(s.paid_at)}</div>` : ''}
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            } catch (e) {
                console.error('loadStatements error:', e);
                tbody.innerHTML = `<tr><td colspan="9" class="px-4 py-8 text-center text-rose-400 font-sans">Server communication error (${escapeHtml(e.message || 'Network/Server Error')}).</td></tr>`;
            }
        }

        // Modals & Support Mode
        async function exitAgentSupportMode() {
            try {
                const res = await fetch('/api/agent.php?action=exit_impersonation', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await res.json();
                if (data && data.success && data.data && data.data.redirect) {
                    window.location.href = data.data.redirect;
                } else {
                    window.location.href = '/admin.php#agents';
                }
            } catch (e) {
                window.location.href = '/admin.php#agents';
            }
        }

        function openChangePasswordModal() {
            const isImpersonating = <?= $isAgentImpersonating ? 'true' : 'false' ?>;
            if (isImpersonating) {
                alert('Password changes are strictly disabled in Admin Support Mode.');
                return;
            }
            document.getElementById('pwdAlert').classList.add('hidden');
            document.getElementById('curPassword').value = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('pwdTotpCode').value = '';
            document.getElementById('passwordModal').classList.remove('hidden');
            document.getElementById('passwordModal').classList.add('flex');
        }

        function closeChangePasswordModal() {
            document.getElementById('passwordModal').classList.add('hidden');
            document.getElementById('passwordModal').classList.remove('flex');
        }

        function toggleLevelBreakdownModal() {
            const m = document.getElementById('levelModal');
            if (m.classList.contains('hidden')) {
                m.classList.remove('hidden');
                m.classList.add('flex');
            } else {
                m.classList.add('hidden');
                m.classList.remove('flex');
            }
        }

        async function submitChangePassword(e) {
            e.preventDefault();
            const isImpersonating = <?= $isAgentImpersonating ? 'true' : 'false' ?>;
            if (isImpersonating) {
                alert('Password changes are strictly disabled in Admin Support Mode.');
                return;
            }
            const alertBox = document.getElementById('pwdAlert');
            const btn = document.getElementById('btnSubmitPwd');

            alertBox.classList.add('hidden');
            btn.disabled = true;
            btn.textContent = 'Updating...';

            const payload = {
                current_password: document.getElementById('curPassword').value,
                new_password: document.getElementById('newPassword').value,
                totp_code: document.getElementById('pwdTotpCode').value.trim()
            };

            try {
                const res = await fetch('/api/agent.php?action=change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.success) {
                    alertBox.className = 'mb-4 p-3 rounded-xl text-xs bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 flex items-center gap-2';
                    alertBox.textContent = 'Password changed successfully!';
                    alertBox.classList.remove('hidden');
                    setTimeout(() => {
                        closeChangePasswordModal();
                    }, 1500);
                } else {
                    alertBox.className = 'mb-4 p-3 rounded-xl text-xs bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-center gap-2';
                    alertBox.textContent = data.error || 'Failed to update password.';
                    alertBox.classList.remove('hidden');
                    document.getElementById('pwdTotpCode').value = '';
                    document.getElementById('pwdTotpCode').focus();
                }
            } catch (err) {
                alertBox.className = 'mb-4 p-3 rounded-xl text-xs bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-center gap-2';
                alertBox.textContent = 'Server error. Please try again.';
                alertBox.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Update Password';
            }
        }

        async function handleLogout() {
            if (!confirm('Are you sure you want to end your Agent session?')) return;
            try {
                await fetch('/api/agent.php?action=logout');
            } catch (e) {}
            window.location.href = '/agent/login';
        }

        function formatCurrency(val) {
            return Number(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m]);
        }
    </script>
</body>
</html>
