/**
 * JeliPay P2P Platform - Dedicated Administrative Console Controller
 * Handles real-time metrics, user dossier inspection, order management,
 * dispute arbitration, global settings, audit trails, and report exports.
 */

let currentAdminTab = 'dashboard';
let currentAdminUser = null;
let activeTimers = {};

document.addEventListener('DOMContentLoaded', () => {
    initAdmin();
    setInterval(periodicAdminSync, 10000);
    window.addEventListener('hashchange', () => {
        const hashTab = window.location.hash.replace('#', '');
        const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'fraud', 'rewards', 'content', 'reports', 'audit', 'admins', 'agents', 'risk'];
        if (hashTab && validTabs.includes(hashTab) && hashTab !== currentAdminTab) {
            switchAdminTab(hashTab);
        }
    });
});

async function initAdmin() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    const hashTab = window.location.hash.replace('#', '');
    const savedTab = localStorage.getItem('adminActiveTab');
    const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'fraud', 'rewards', 'content', 'reports', 'audit', 'admins', 'agents', 'risk'];

    let initialTab = 'dashboard';
    if (tabParam && validTabs.includes(tabParam)) {
        initialTab = tabParam;
    } else if (hashTab && validTabs.includes(hashTab)) {
        initialTab = hashTab;
    } else if (savedTab && validTabs.includes(savedTab)) {
        initialTab = savedTab;
    }

    switchAdminTab(initialTab);
}

function setBadge(id, count) {
    const el = document.getElementById(id);
    if (el) el.innerText = count !== undefined && count !== null ? count : 0;
}

function setTxt(id, val) {
    const el = document.getElementById(id);
    if (el) el.innerText = val !== undefined && val !== null ? val : '';
}

window.setBadge = setBadge;
window.setTxt = setTxt;

function switchAdminTab(tabId, filterOptions = null) {
    const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'fraud', 'rewards', 'content', 'reports', 'audit', 'admins', 'agents', 'risk'];
    if (!validTabs.includes(tabId)) {
        tabId = 'dashboard';
    }

    currentAdminTab = tabId;
    localStorage.setItem('adminActiveTab', tabId);
    if (window.location.hash !== '#' + tabId) {
        history.replaceState(null, '', '#' + tabId);
    }

    document.querySelectorAll('.admin-nav-item').forEach(el => {
        el.classList.remove('bg-amber-500/10', 'text-amber-400', 'border-amber-500/30');
        el.classList.add('text-slate-400');
    });

    const activeNav = document.getElementById(`adminNav-${tabId}`);
    if (activeNav) {
        activeNav.classList.remove('text-slate-400');
        activeNav.classList.add('bg-amber-500/10', 'text-amber-400', 'border-amber-500/30');
    }

    document.querySelectorAll('.admin-view').forEach(el => el.classList.add('hidden'));
    const activeView = document.getElementById(`adminView-${tabId}`);
    if (activeView) activeView.classList.remove('hidden');

    // Pre-apply filters if supplied
    if (tabId === 'users' && filterOptions) {
        if (filterOptions.status && document.getElementById('userStatusFilter')) {
            document.getElementById('userStatusFilter').value = filterOptions.status;
        }
        if (filterOptions.role && document.getElementById('userRoleFilter')) {
            document.getElementById('userRoleFilter').value = filterOptions.role;
        }
        if (filterOptions.query !== undefined && document.getElementById('userSearchInput')) {
            document.getElementById('userSearchInput').value = filterOptions.query;
        }
    }

    if (tabId === 'orders' && filterOptions) {
        if (filterOptions.status && document.getElementById('orderStatusFilter')) {
            document.getElementById('orderStatusFilter').value = filterOptions.status;
        }
        if (filterOptions.type && document.getElementById('orderTypeFilter')) {
            document.getElementById('orderTypeFilter').value = filterOptions.type;
        }
        if (filterOptions.query !== undefined && document.getElementById('orderSearchInput')) {
            document.getElementById('orderSearchInput').value = filterOptions.query;
        }
    }

    if (tabId === 'disputes' && filterOptions) {
        if (filterOptions.status && document.getElementById('disputeStatusFilter')) {
            document.getElementById('disputeStatusFilter').value = filterOptions.status;
        }
        if (filterOptions.query !== undefined && document.getElementById('disputeSearchInput')) {
            document.getElementById('disputeSearchInput').value = filterOptions.query;
        }
        if (filterOptions.section) {
            switchDisputeSection(filterOptions.section);
            return;
        }
    }

    // Trigger data loader
    if (tabId === 'dashboard') loadAdminDashboard();
    if (tabId === 'users') loadAdminUsers();
    if (tabId === 'orders') loadAdminOrders();
    if (tabId === 'disputes') loadAdminDisputes();
    if (tabId === 'fraud') loadAdminFraudAssessments();
    if (tabId === 'rewards') loadAdminRewardSettings();
    if (tabId === 'content') loadAdminContentSettings();
    if (tabId === 'reports') loadAdminReports();
    if (tabId === 'audit') loadAdminAuditLogs();
    if (tabId === 'admins') loadAdminDirectory();
    if (tabId === 'agents') loadAdminAgents();
    if (tabId === 'risk') loadAdminRiskUsers();
}

function periodicAdminSync() {
    if (currentAdminTab === 'dashboard') loadAdminDashboard(true);
}

// -------------------------------------------------------------
// TAB 1: DASHBOARD OVERVIEW (Requirement 1 & Part 11 Sync)
// -------------------------------------------------------------

async function loadAdminDashboard(silent = false) {
    try {
        const res = await fetch('/api/admin.php?action=stats');
        const data = await res.json();
        if (!data.success || !data.stats) return;

        const s = data.stats;
        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.innerText = val;
        };

        // User metrics
        setTxt('dashTotalUsers', s.total_users ?? 0);
        setTxt('dashActiveUsers', s.active_users ?? 0);
        setTxt('dashSuspendedUsers', s.suspended_users ?? 0);
        setTxt('dashDemoUsers', s.demo_users ?? 0);

        // Volumes & Balances
        const tradingVol = parseFloat(s.total_completed_trading_volume || 0);
        const sellVol = parseFloat(s.total_sell_volume || 0);
        const circulation = parseFloat(s.total_tokens_in_circulation || 0);
        const availBal = parseFloat(s.total_available_tokens || 0);
        const resBal = parseFloat(s.total_reserved_tokens || 0);

        setTxt('dashTradingVol', `₹${tradingVol.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashSellVol', `₹${sellVol.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashCirculation', `₹${circulation.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashAvailableBal', `₹${availBal.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashReservedBal', `₹${resBal.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);

        // Orders breakdown
        const orders = s.orders || {};
        const m = s.marketplace || {};

        const otBtn = document.getElementById('dashOrdersTotal');
        if (otBtn) {
            const span = otBtn.querySelector('span');
            if (span) span.innerText = `${orders.total ?? 0} Total Orders`;
            else otBtn.innerText = `${orders.total ?? 0} Total Orders`;
        }

        // Available orders (1 & 2)
        setTxt('dashOrdersAvailableToday', m.orders_available_today ?? (orders.available_today ?? 0));
        setTxt('dashOrdersAvailableYesterday', m.orders_available_yesterday ?? (orders.available_yesterday ?? 0));
        setTxt('dashOrdersAvailable', `${orders.available ?? (m.orders_available_current ?? 0)} Pool`);

        // Reserved orders (3 & 4)
        setTxt('dashOrdersReservedToday', m.orders_reserved_today ?? (orders.reserved_today ?? 0));
        setTxt('dashOrdersReservedYesterday', m.orders_reserved_yesterday ?? (orders.reserved_yesterday ?? 0));
        setTxt('dashOrdersReserved', `${orders.reserved ?? (m.orders_reserved_current ?? 0)} Active`);

        // Disputed orders (5 & 6)
        setTxt('dashOrdersDisputedToday', m.orders_disputed_today ?? (orders.disputed_today ?? 0));
        setTxt('dashOrdersDisputed', `${m.orders_disputed_all ?? (orders.disputed ?? 0)} Unresolved`);
        setTxt('dashOrdersDisputedAll', m.orders_disputed_all ?? (orders.disputed ?? 0));
        setTxt('dashOrdersDisputedResolved', m.orders_disputed_resolved ?? (s.resolved_appeals_count ?? 0));

        // Completed orders (7, 8, 9)
        setTxt('dashOrdersCompletedToday', m.orders_completed_today ?? (orders.completed_today ?? 0));
        setTxt('dashOrdersCompletedYesterday', m.orders_completed_yesterday ?? (orders.completed_yesterday ?? 0));
        setTxt('dashOrdersCompleted', `${orders.completed ?? (m.orders_completed_total ?? 0)} Total`);
        setTxt('dashOrdersCompletedTotal', m.orders_completed_total ?? (orders.completed ?? 0));

        // Cancelled orders (10, 11, 12)
        setTxt('dashOrdersCancelledToday', m.orders_cancelled_today ?? (orders.cancelled_today ?? 0));
        setTxt('dashOrdersCancelledYesterday', m.orders_cancelled_yesterday ?? (orders.cancelled_yesterday ?? 0));
        setTxt('dashOrdersCancelled', `${orders.cancelled ?? (m.orders_cancelled_total ?? 0)} Total`);
        setTxt('dashOrdersCancelledTotal', m.orders_cancelled_total ?? (orders.cancelled ?? 0));

        setTxt('dashOrdersPendingConfirm', orders.pending_confirmation ?? 0);

        // Tokens in Circulation (13 & 14)
        const circToday = parseFloat(m.token_circulation_today ?? circulation);
        const circYesterday = parseFloat(m.token_circulation_yesterday ?? circulation);
        setTxt('dashCirculationToday', `₹${circToday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashCirculationYesterday', `₹${circYesterday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashCirculationTime', `As of ${m.measurement_timestamp || 'Latest'} UTC`);

        // Completed Trading Volume (15 & 16)
        const tVolToday = parseFloat(m.trading_volume_today ?? (s.completed_trading_volume_today ?? 0));
        const tVolYesterday = parseFloat(m.trading_volume_yesterday ?? (s.completed_trading_volume_yesterday ?? 0));
        setTxt('dashTradingVolToday', `₹${tVolToday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashTradingVolYesterday', `₹${tVolYesterday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashTradingVolTotal', `All-Time: ₹${tradingVol.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);

        // Purchase Rewards (17 & 18)
        const rewToday = parseFloat(m.purchase_rewards_today ?? (s.purchase_rewards_today ?? 0));
        const rewYesterday = parseFloat(m.purchase_rewards_yesterday ?? (s.purchase_rewards_yesterday ?? 0));
        setTxt('dashRewardsToday', `₹${rewToday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashRewardsYesterday', `₹${rewYesterday.toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashRewardsTotal', `All-Time: ₹${parseFloat(m.purchase_rewards_total ?? s.total_rewards ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);

        // Rewards & Referrals
        setTxt('dashTotalRewards', `₹${parseFloat(s.total_rewards || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashTotalCommissions', `₹${parseFloat(s.total_commissions || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashL1Commissions', `₹${parseFloat(s.total_l1_commissions || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashL2Commissions', `₹${parseFloat(s.total_l2_commissions || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);

        // Weekly Target
        setTxt('dashWeeklyTarget', `₹${parseFloat(s.weekly_selling_target || 400000).toLocaleString('en-IN')}`);
        setTxt('dashWeeklyCompleted', `₹${parseFloat(s.weekly_completed_volume || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`);
        setTxt('dashWeeklyPct', `${s.weekly_progress_percent ?? 0}%`);
        const pBar = document.getElementById('dashWeeklyProgressBar');
        if (pBar) pBar.style.width = `${s.weekly_progress_percent ?? 0}%`;

        // Recent Activity tables (Live Real-time data with empty states)
        const transTbody = document.getElementById('dashRecentTransBody');
        if (transTbody) {
            if (s.recent_transactions && s.recent_transactions.length > 0) {
                transTbody.innerHTML = s.recent_transactions.map(t => `
                    <tr class="hover:bg-slate-900/60 font-mono text-xs">
                        <td class="p-2.5 text-slate-500">#${t.id}</td>
                        <td class="p-2.5 text-slate-300">${escapeHtml(t.user_phone || 'User #' + t.user_id)}</td>
                        <td class="p-2.5"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 text-amber-400 font-bold">${escapeHtml(t.transaction_type)}</span></td>
                        <td class="p-2.5 font-bold text-white">₹${parseFloat(t.amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                        <td class="p-2.5 text-slate-400 text-[11px]">${t.created_at}</td>
                    </tr>
                `).join('');
            } else {
                transTbody.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-slate-500 font-sans text-xs">No recent ledger transactions recorded.</td></tr>`;
            }
        }

        const auditTbody = document.getElementById('dashRecentAuditBody');
        if (auditTbody) {
            if (s.recent_admin_activities && s.recent_admin_activities.length > 0) {
                auditTbody.innerHTML = s.recent_admin_activities.map(a => `
                    <tr class="hover:bg-slate-900/60 font-mono text-xs">
                        <td class="p-2.5 text-slate-500">#${a.id}</td>
                        <td class="p-2.5 text-amber-400">${escapeHtml(a.admin_phone || a.admin_name || 'Admin #' + a.admin_id)}</td>
                        <td class="p-2.5"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 font-bold">${escapeHtml(a.action_type)}</span></td>
                        <td class="p-2.5 text-slate-300 truncate max-w-xs font-sans text-[11px]">${escapeHtml(a.reason || '-')}</td>
                        <td class="p-2.5 text-slate-400 text-[11px]">${a.created_at}</td>
                    </tr>
                `).join('');
            } else {
                auditTbody.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-slate-500 font-sans text-xs">No administrative actions logged yet.</td></tr>`;
            }
        }
    } catch (e) {
        console.error('Failed to load admin dashboard stats:', e);
    }
}

// -------------------------------------------------------------
// TAB 2: USER MANAGEMENT (Requirement 2)
// -------------------------------------------------------------

let userPageLimit = 20;
let userPageOffset = 0;
let currentUserCategory = 'all';

function switchUserCategory(cat) {
    currentUserCategory = cat;
    userPageOffset = 0;
    document.querySelectorAll('.user-cat-tab').forEach(btn => {
        btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-600/20');
        btn.classList.add('bg-slate-800', 'text-slate-400');
    });
    const activeBtn = document.getElementById(`userCatTab-${cat}`);
    if (activeBtn) {
        activeBtn.classList.remove('bg-slate-800', 'text-slate-400');
        activeBtn.classList.add('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-600/20');
    }
    const filterDropdown = document.getElementById('userStatusFilter');
    if (filterDropdown) filterDropdown.value = cat;
    loadAdminUsers();
}

function changeUserPageSize(size) {
    userPageLimit = parseInt(size, 10) || 20;
    userPageOffset = 0;
    loadAdminUsers();
}

function changeUserPage(direction) {
    if (direction === 'prev' && userPageOffset > 0) {
        userPageOffset = Math.max(0, userPageOffset - userPageLimit);
    } else if (direction === 'next') {
        userPageOffset += userPageLimit;
    }
    loadAdminUsers();
}

async function loadAdminUsers() {
    const query = document.getElementById('userSearchInput')?.value || '';
    const status = currentUserCategory;
    const role = document.getElementById('userRoleFilter')?.value || 'all';

    try {
        const res = await fetch(`/api/admin.php?action=users&query=${encodeURIComponent(query)}&status=${encodeURIComponent(status)}&role=${encodeURIComponent(role)}&limit=${userPageLimit}&offset=${userPageOffset}`);
        const data = await res.json();
        const tbody = document.getElementById('adminUsersTableBody');
        if (!tbody) return;

        // Sync category badge counts
        if (data.category_counts) {
            const cc = data.category_counts;
            const setBadge = (id, count) => {
                const el = document.getElementById(id);
                if (el) el.innerText = count || 0;
            };
            setBadge('userTabCount-all', cc.all || data.total_registered || 0);
            setBadge('userTabCount-active', cc.active || 0);
            setBadge('userTabCount-suspended', cc.suspended || 0);
            setBadge('userTabCount-temp_blocked', cc.temporarily_blocked || 0);
        }

        const total = data.total || 0;
        const from = total > 0 ? (userPageOffset + 1) : 0;
        const to = Math.min(total, userPageOffset + userPageLimit);
        const infoEl = document.getElementById('userPaginationInfo');
        if (infoEl) infoEl.innerText = `Showing ${from} to ${to} of ${total} matching users (Total registered: ${data.total_registered || total})`;

        const prevBtn = document.getElementById('userPrevBtn');
        const nextBtn = document.getElementById('userNextBtn');
        const indEl = document.getElementById('userPageIndicator');
        if (prevBtn) prevBtn.disabled = (userPageOffset === 0);
        if (nextBtn) nextBtn.disabled = (userPageOffset + userPageLimit >= total);
        if (indEl) indEl.innerText = `Page ${Math.floor(userPageOffset / userPageLimit) + 1} of ${Math.max(1, Math.ceil(total / userPageLimit))}`;

        if (!data.success || !data.users || data.users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-500 font-sans">No matching user accounts found in this category.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.users.map(u => {
            const hScore = (u.health_score !== undefined && u.health_score !== null) ? parseInt(u.health_score, 10) : 100;
            const hColor = (hScore <= 50) ? 'text-rose-400 bg-rose-500/10 border-rose-500/20' : (hScore >= 80 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-amber-400 bg-amber-500/10 border-amber-500/20');
            const stColor = (u.account_status === 'active') ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : ((u.account_status === 'temporarily_blocked') ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20');
            return `
            <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                <td class="p-3 text-slate-500 font-bold">
                    #${u.id}
                    ${u.is_flagged ? '<span class="ml-1 px-1.5 py-0.5 rounded bg-red-500/20 text-red-400 text-[9px] uppercase font-bold" title="' + escapeHtml(u.flag_reason || 'Flagged') + '">⚠️ Suspicious</span>' : ''}
                </td>
                <td class="p-3">
                    <span class="text-white font-bold block">${escapeHtml(u.phone)}</span>
                    <span class="text-[10px] text-slate-500 font-sans block">${escapeHtml(u.email || 'No email')}</span>
                </td>
                <td class="p-3">
                    <span class="text-slate-300 font-sans font-semibold">${escapeHtml(u.account_holder_name || 'Not configured')}</span>
                    <span class="text-[11px] text-slate-500 font-mono block">${escapeHtml(u.upi_id || '-')}</span>
                </td>
                <td class="p-3">
                    <span class="text-emerald-400 font-bold">₹${parseFloat(u.available_balance || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
                    <span class="text-[10px] text-slate-500 block">Res: ₹${parseFloat(u.reserved_balance || 0).toFixed(2)}</span>
                </td>
                <td class="p-3">
                    <div class="flex items-center gap-1.5">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border ${hColor}">
                            ${hScore} / 100
                        </span>
                        <button type="button" onclick="openHealthScoreHistoryModal(${u.id}, '${escapeHtml(u.phone)}')" class="text-[10px] text-slate-500 hover:text-indigo-400 underline font-sans" title="View Score History">Audit</button>
                    </div>
                </td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${stColor}">
                        ${escapeHtml(u.account_status)}
                    </span>
                    ${u.sell_restricted ? '<span class="text-[9px] block text-rose-400 font-sans uppercase mt-0.5 font-bold">Sell Restricted</span>' : ''}
                </td>
                <td class="p-3 font-sans">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button onclick="viewUserDossier(${u.id})" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded text-[11px] font-semibold transition cursor-pointer" title="Inspect Full Dossier">
                            Dossier
                        </button>
                        <button onclick="openAdjustmentModal(${u.id}, '${escapeHtml(u.phone)}')" class="px-2 py-1 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-400 border border-indigo-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Adjust Token Balance">
                            Adjust
                        </button>
                        <!-- Requirement 1.3: Authorized Status Controls (Active, Suspended, Temporarily Blocked) -->
                        <button onclick="openSetUserStatusModal(${u.id}, '${escapeHtml(u.phone)}', '${u.account_status}')" class="px-2 py-1 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Change Account Status">
                            Status
                        </button>
                        <button onclick="impersonateUser(${u.id})" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded text-[11px] font-semibold transition cursor-pointer" title="Read-Only Support Mode">
                            Support
                        </button>
                    </div>
                </td>
            </tr>
        `;
        }).join('');
    } catch (e) {
        console.error('Failed to load users:', e);
    }
}

// Open Complete User Dossier
async function viewUserDossier(userId) {
    try {
        const res = await fetch(`/api/admin.php?action=user_detail&user_id=${userId}`);
        const data = await res.json();
        if (!data.success || !data.user) {
            alert('Failed to retrieve user dossier.');
            return;
        }

        const u = data.user;
        const w = data.wallet;

        document.getElementById('dossierUserId').innerText = `#${u.id}`;
        document.getElementById('dossierPhone').innerText = u.phone;
        document.getElementById('dossierEmail').innerText = u.email || 'N/A';
        document.getElementById('dossierName').innerText = u.account_holder_name || 'Not provided';
        document.getElementById('dossierUpi').innerText = u.upi_id || 'Not configured';
        document.getElementById('dossierRefCode').innerText = u.referral_code;
        document.getElementById('dossierReferredBy').innerText = u.referred_by_phone ? `${u.referred_by_phone} (${u.referred_by_name || 'Peer'})` : 'Organic (Direct)';
        document.getElementById('dossierStatus').innerText = u.account_status.toUpperCase();
        document.getElementById('dossierCreated').innerText = u.created_at;

        document.getElementById('dossierAvail').innerText = `₹${parseFloat(w.available_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dossierReserved').innerText = `₹${parseFloat(w.reserved_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dossierRewards').innerText = `₹${parseFloat(w.total_rewards_earned).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dossierCommissions').innerText = `₹${parseFloat(w.total_commissions_earned).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;

        // Orders counts
        document.getElementById('dossierBuyCount').innerText = `${data.buy_orders.length} orders`;
        document.getElementById('dossierSellCount').innerText = `${data.sell_orders.length} listings`;

        // Ledger list
        const ledgerEl = document.getElementById('dossierLedgerList');
        if (data.ledger && data.ledger.length > 0) {
            ledgerEl.innerHTML = data.ledger.map(l => `
                <tr class="font-mono text-[11px] border-b border-slate-800/60">
                    <td class="p-2 text-slate-500">#${l.id}</td>
                    <td class="p-2"><span class="px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 font-bold">${l.transaction_type}</span></td>
                    <td class="p-2 font-bold text-white">₹${parseFloat(l.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                    <td class="p-2 text-slate-400">₹${parseFloat(l.previous_balance).toFixed(2)} &rarr; ₹${parseFloat(l.new_balance).toFixed(2)}</td>
                    <td class="p-2 text-slate-500">${l.created_at}</td>
                </tr>
            `).join('');
        } else {
            ledgerEl.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-slate-500">No ledger movements found.</td></tr>`;
        }

        // Setup action buttons in modal
        document.getElementById('dossierAdjustBtn').onclick = () => { closeUserDossierModal(); openAdjustmentModal(u.id, u.phone); };
        document.getElementById('dossierFlagBtn').onclick = () => toggleUserSuspicious(u.id);

        document.getElementById('userDossierModal').classList.remove('hidden');
    } catch (e) {
        alert('Network error loading dossier.');
    }
}

function closeUserDossierModal() {
    document.getElementById('userDossierModal').classList.add('hidden');
}

async function toggleUserStatus(userId, currentStatus) {
    const action = currentStatus === 'active' ? 'suspend' : 'activate';
    const reason = prompt(`Please enter mandatory reason to ${action} User #${userId}:`);
    if (!reason) return;

    try {
        const res = await fetch('/api/admin.php?action=toggle_suspension', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert(`User #${userId} status updated to: ${data.new_status}`);
            loadAdminUsers();
        } else {
            alert(data.error || 'Action failed.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

async function toggleUserSuspicious(userId) {
    const reason = prompt(`Enter reason to flag/unflag User #${userId} as suspicious:`);
    if (!reason) return;

    try {
        const res = await fetch('/api/admin.php?action=toggle_flag', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert(`User #${userId} flagged status toggled: ${data.is_flagged ? 'SUSPICIOUS' : 'CLEARED'}`);
            closeUserDossierModal();
            loadAdminUsers();
        } else {
            alert(data.error || 'Action failed.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

function openAdjustmentModal(userId, phone) {
    document.getElementById('adjTargetId').value = userId;
    document.getElementById('adjTargetPhone').innerText = phone;
    document.getElementById('adjAmount').value = '';
    document.getElementById('adjReason').value = '';
    document.getElementById('adjustmentModal').classList.remove('hidden');
}

function closeAdjustmentModal() {
    document.getElementById('adjustmentModal').classList.add('hidden');
}

async function handleAdjustmentSubmit(e) {
    e.preventDefault();
    const userId = parseInt(document.getElementById('adjTargetId').value);
    const type = document.getElementById('adjType').value;
    const amount = parseFloat(document.getElementById('adjAmount').value);
    const reason = document.getElementById('adjReason').value.trim();

    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid positive token amount.');
        return;
    }

    if (!reason) {
        alert('A mandatory audit reason is required.');
        return;
    }

    if (!confirm(`Are you sure you want to execute ${type.toUpperCase()} of ₹${amount} on User #${userId}? This will update the immutable double-entry ledger.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=manual_adjustment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, type, amount, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert('Manual adjustment completed! New Available Balance: ₹' + data.new_available);
            closeAdjustmentModal();
            loadAdminUsers();
        } else {
            alert(data.error || 'Adjustment failed.');
        }
    } catch (e) {
        alert('Network error executing adjustment.');
    }
}

async function impersonateUser(userId) {
    if (!confirm(`Launch Admin Support Mode for User #${userId}? This will open the user's dashboard in a read-only support session.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=impersonate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId })
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = '/?tab=home';
        } else {
            alert(data.error || 'Failed to start support mode.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

// -------------------------------------------------------------
// TAB 3: ORDER MANAGEMENT (Requirement 2: 3 Distinct Sections & Server Pagination)
// -------------------------------------------------------------

let currentOrderSection = 'active';
let orderPageLimit = 20;
let orderPageOffset = 0;

function switchOrderSection(sec) {
    currentOrderSection = sec;
    orderPageOffset = 0;
    document.querySelectorAll('.order-sec-tab').forEach(btn => {
        btn.classList.remove('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-600/20');
        btn.classList.add('bg-slate-800', 'text-slate-400');
    });
    const activeBtn = document.getElementById(`orderSecTab-${sec}`);
    if (activeBtn) {
        activeBtn.classList.remove('bg-slate-800', 'text-slate-400');
        activeBtn.classList.add('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-600/20');
    }

    // Dynamic section headers & status filter options
    const heading = document.getElementById('orderSectionHeading');
    const subtext = document.getElementById('orderSectionSubtext');
    const statusSel = document.getElementById('orderStatusFilter');

    if (sec === 'active') {
        if (heading) heading.innerText = 'Active Buy & Sell Orders';
        if (subtext) subtext.innerText = 'Track available, reserved, and payment pending orders under the state machine.';
        if (statusSel) {
            statusSel.innerHTML = `
                <option value="all">All Active Statuses</option>
                <option value="Available">Available Only</option>
                <option value="Reserved">Reserved Only</option>
                <option value="Payment Proof Submitted">Proof Submitted Only</option>
                <option value="Seller Confirmation Pending">Confirmation Pending Only</option>
                <option value="Seller Response Timeout">Response Timeout Only</option>
            `;
        }
    } else if (sec === 'cancelled') {
        if (heading) heading.innerText = 'Cancelled Orders';
        if (subtext) subtext.innerText = 'Orders cancelled by traders or administration (expired reservations not merged unless cancelled).';
        if (statusSel) {
            statusSel.innerHTML = `
                <option value="all">All Cancelled Statuses</option>
                <option value="Cancelled / Reversed">Cancelled / Reversed</option>
                <option value="Cancelled">Cancelled</option>
            `;
        }
    } else if (sec === 'disputed') {
        if (heading) heading.innerText = 'Disputed & Appealed Orders';
        if (subtext) subtext.innerText = 'Orders currently in active dispute review, appeal arbitration, or evidence validation.';
        if (statusSel) {
            statusSel.innerHTML = `
                <option value="all">All Disputed Statuses</option>
                <option value="Disputed">Disputed Only</option>
                <option value="Appealed">Appealed Only</option>
                <option value="In Dispute Review">In Dispute Review</option>
            `;
        }
    }

    loadAdminOrders();
}

function changeOrderPageSize(size) {
    orderPageLimit = parseInt(size, 10) || 20;
    orderPageOffset = 0;
    loadAdminOrders();
}

function changeOrderPage(direction) {
    if (direction === 'prev' && orderPageOffset > 0) {
        orderPageOffset = Math.max(0, orderPageOffset - orderPageLimit);
    } else if (direction === 'next') {
        orderPageOffset += orderPageLimit;
    }
    loadAdminOrders();
}

async function loadAdminOrders() {
    const status = document.getElementById('orderStatusFilter')?.value || 'all';
    const query = document.getElementById('orderSearchInput')?.value || '';
    const dateFrom = document.getElementById('orderDateFrom')?.value || '';
    const dateTo = document.getElementById('orderDateTo')?.value || '';
    const type = 'child';

    try {
        const url = `/api/admin.php?action=orders&status=${encodeURIComponent(status)}&type=${encodeURIComponent(type)}&query=${encodeURIComponent(query)}&section=${encodeURIComponent(currentOrderSection)}&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&limit=${orderPageLimit}&offset=${orderPageOffset}`;
        const res = await fetch(url);
        const data = await res.json();
        const tbody = document.getElementById('adminOrdersTableBody');
        if (!tbody) return;

        // Sync section counts
        if (data.counts) {
            const setBadge = (id, count) => {
                const el = document.getElementById(id);
                if (el) el.innerText = count || 0;
            };
            setBadge('orderSecCount-active', data.counts.active || 0);
            setBadge('orderSecCount-cancelled', data.counts.cancelled || 0);
            setBadge('orderSecCount-disputed', data.counts.disputed || 0);
        }

        const total = data.total || 0;
        const from = total > 0 ? (orderPageOffset + 1) : 0;
        const to = Math.min(total, orderPageOffset + orderPageLimit);
        const infoEl = document.getElementById('orderPaginationInfo');
        if (infoEl) infoEl.innerText = `Showing ${from} to ${to} of ${total} matching orders in ${currentOrderSection.toUpperCase()}`;

        const prevBtn = document.getElementById('orderPrevBtn');
        const nextBtn = document.getElementById('orderNextBtn');
        const indEl = document.getElementById('orderPageIndicator');
        if (prevBtn) prevBtn.disabled = (orderPageOffset === 0);
        if (nextBtn) nextBtn.disabled = (orderPageOffset + orderPageLimit >= total);
        if (indEl) indEl.innerText = `Page ${Math.floor(orderPageOffset / orderPageLimit) + 1} of ${Math.max(1, Math.ceil(total / orderPageLimit))}`;

        if (!data.success || !data.orders || data.orders.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-500 font-sans">No matching orders found in this section.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.orders.map(o => {
            const isChild = true;
            let stBadgeColor = 'bg-slate-800 text-slate-300';
            if (o.status === 'Completed') stBadgeColor = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
            else if (o.status === 'Available') stBadgeColor = 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20';
            else if (o.status === 'Reserved') stBadgeColor = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
            else if (['Disputed', 'Appealed', 'In Dispute Review'].includes(o.status)) stBadgeColor = 'bg-rose-500/20 text-rose-400 border border-rose-500/30';
            else if (o.status.includes('Cancel')) stBadgeColor = 'bg-red-500/10 text-red-400 border border-red-500/20';

            return `
                <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                    <td class="p-3 text-slate-400 font-bold">
                        #${o.id}
                        ${o.parent_order_id ? `<span class="text-[10px] text-slate-500 block font-normal">Parent #${o.parent_order_id}</span>` : ''}
                    </td>
                    <td class="p-3">
                        <span class="text-white font-bold block">${escapeHtml(o.seller_phone || 'Seller')}</span>
                        <span class="text-[10px] text-slate-400 font-sans block">${escapeHtml(o.seller_name || 'Peer Seller')}</span>
                        <span class="text-[10px] text-slate-500 font-mono block">${escapeHtml(o.seller_order_upi_id || '-')}</span>
                    </td>
                    <td class="p-3">
                        ${o.buyer_phone ? `
                            <span class="text-white font-bold block">${escapeHtml(o.buyer_phone)}</span>
                            <span class="text-[10px] text-slate-400 font-sans block">${escapeHtml(o.buyer_name || 'Peer Buyer')}</span>
                        ` : '<span class="text-slate-600 italic">Unreserved</span>'}
                    </td>
                    <td class="p-3">
                        <span class="text-emerald-400 font-bold text-sm">₹${parseFloat(o.amount).toLocaleString('en-IN')}</span>
                        <span class="text-[10px] text-indigo-400 block">+${o.snapshotted_reward_rate}% Rew (₹${parseFloat(o.snapshotted_reward_amount).toFixed(2)})</span>
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${stBadgeColor}">
                            ${escapeHtml(o.status)}
                        </span>
                    </td>
                    <td class="p-3">
                        ${o.utr_number ? `
                            <span class="text-white font-bold block">${escapeHtml(o.utr_number)}</span>
                            ${o.screenshot_path ? `<button type="button" onclick="openEvidenceLightbox('${escapeHtml(o.screenshot_path)}', 'Buyer Payment Proof - Order #${o.id}')" class="text-[10px] text-amber-400 hover:text-amber-300 font-bold underline font-sans block mt-0.5 cursor-pointer">Inspect Proof</button>` : ''}
                        ` : '<span class="text-slate-600">-</span>'}
                    </td>
                    <td class="p-3 text-[11px] text-slate-500">
                        ${o.created_at}
                    </td>
                    <td class="p-3 font-sans">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            ${o.dispute_id ? `
                                <button onclick="openDisputeOrderDetailsModal(${o.dispute_id})" class="px-2 py-1 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Inspect Disputed Seller Payment Details">
                                    Dispute
                                </button>
                            ` : ''}
                            ${!['Completed', 'Cancelled / Reversed', 'Cancelled'].includes(o.status) ? `
                                <button onclick="cancelAdminOrder(${o.id})" class="px-2 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Cancel & Release Tokens to Seller">
                                    Cancel
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load orders:', e);
    }
}

async function cancelAdminOrder(orderId) {
    const reason = prompt(`Enter mandatory reason to cancel Order #${orderId} and return reserved tokens to seller:`);
    if (!reason) return;

    try {
        const res = await fetch('/api/admin.php?action=cancel_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            loadAdminOrders();
        } else {
            alert(data.error || 'Failed to cancel order.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

// -------------------------------------------------------------
// TAB 4: DISPUTE ARBITRATION (Requirement 4 & Dispute Management Bugfix)
// -------------------------------------------------------------

let currentDisputeSection = 'pending';
let disputePageOffset = 0;
let disputePageLimit = 20;
let disputeTotalRecords = 0;

function switchDisputeSection(sec) {
    if (!['pending', 'evidence_required', 'resolved'].includes(sec)) return;
    currentDisputeSection = sec;
    disputePageOffset = 0;

    // Update section tabs styling
    ['pending', 'evidence_required', 'resolved'].forEach(s => {
        const tab = document.getElementById(`disputeSecTab-${s}`);
        const countBadge = document.getElementById(`disputeSecCount-${s}`);
        if (!tab) return;
        if (s === sec) {
            tab.className = 'px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 bg-amber-500/20 text-amber-400 border border-amber-500/40';
            if (countBadge) countBadge.className = 'px-1.5 py-0.5 rounded-full bg-slate-900 text-amber-400 text-[10px] border border-amber-500/20';
        } else {
            tab.className = 'px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-slate-800/60 border border-transparent';
            if (countBadge) countBadge.className = 'px-1.5 py-0.5 rounded-full bg-slate-900 text-slate-400 text-[10px] border border-slate-800';
        }
    });

    // Update heading and subtext
    const heading = document.getElementById('disputeSectionHeading');
    const subtext = document.getElementById('disputeSectionSubtext');
    if (sec === 'pending') {
        if (heading) heading.innerText = 'Pending Disputes Console';
        if (subtext) subtext.innerText = 'Disputes awaiting administrator arbitration or decision. Tokens remain frozen.';
    } else if (sec === 'evidence_required') {
        if (heading) heading.innerText = 'Disputes Awaiting Evidence';
        if (subtext) subtext.innerText = 'Cases where additional statements, screenshots, or bank proofs have been requested from traders.';
    } else if (sec === 'resolved') {
        if (heading) heading.innerText = 'Resolved Disputes Archive';
        if (subtext) subtext.innerText = 'Complete audit history of settled, reversed, and finalized dispute decisions.';
    }

    loadAdminDisputes();
}

function changeDisputePageSize(val) {
    disputePageLimit = parseInt(val) || 20;
    disputePageOffset = 0;
    loadAdminDisputes();
}

function changeDisputePage(direction) {
    if (direction === 'prev' && disputePageOffset > 0) {
        disputePageOffset = Math.max(0, disputePageOffset - disputePageLimit);
        loadAdminDisputes();
    } else if (direction === 'next' && (disputePageOffset + disputePageLimit) < disputeTotalRecords) {
        disputePageOffset += disputePageLimit;
        loadAdminDisputes();
    }
}

function resetDisputeFilters() {
    const searchInput = document.getElementById('disputeSearchInput');
    if (searchInput) searchInput.value = '';
    const statusSelect = document.getElementById('disputeStatusFilter');
    if (statusSelect) statusSelect.value = 'all';
    disputePageOffset = 0;
    loadAdminDisputes();
}

function updateDisputePaginationControls() {
    const info = document.getElementById('disputePaginationInfo');
    const prevBtn = document.getElementById('disputePrevBtn');
    const nextBtn = document.getElementById('disputeNextBtn');
    const pageIndicator = document.getElementById('disputePageIndicator');

    const start = disputeTotalRecords > 0 ? (disputePageOffset + 1) : 0;
    const end = Math.min(disputePageOffset + disputePageLimit, disputeTotalRecords);
    const currentPage = disputeTotalRecords > 0 ? (Math.floor(disputePageOffset / disputePageLimit) + 1) : 1;
    const totalPages = Math.max(1, Math.ceil(disputeTotalRecords / disputePageLimit));

    if (info) info.innerText = `Showing ${start} - ${end} of ${disputeTotalRecords} matching disputes`;
    if (pageIndicator) pageIndicator.innerText = `Page ${currentPage} of ${totalPages}`;
    if (prevBtn) prevBtn.disabled = (disputePageOffset <= 0);
    if (nextBtn) nextBtn.disabled = ((disputePageOffset + disputePageLimit) >= disputeTotalRecords);
}

function renderDisputeCardHtml(d, section) {
    const amountFormatted = parseFloat(d.amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const buyerPhone = escapeHtml(d.buyer_phone || '');
    const sellerPhone = escapeHtml(d.seller_phone || '');
    const buyerName = escapeHtml(d.buyer_name || 'Buyer');
    const sellerName = escapeHtml(d.seller_name || 'Seller');

    // Section A: Pending Disputes
    if (section === 'pending') {
        return `
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4 shadow-sm hover:border-slate-700 transition">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-mono font-bold text-amber-400">Dispute #${d.id} &bull; Order #${d.child_order_id}</span>
                        ${d.parent_order_id ? `<span class="text-xs font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 px-2 py-0.5 rounded">Parent #${d.parent_order_id}</span>` : ''}
                        <span class="px-2.5 py-0.5 rounded text-xs uppercase font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">
                            ${escapeHtml(d.status)}
                        </span>
                        <span class="text-[11px] text-slate-400 font-mono">Created: ${escapeHtml(d.created_at || 'N/A')}</span>
                        <button type="button" onclick="openDisputeOrderDetailsModal(${d.id})" class="px-2.5 py-1 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg transition flex items-center gap-1.5 cursor-pointer ml-1" title="Inspect Full Order Details">
                            <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>View Details</span>
                        </button>
                    </div>
                    <div class="text-right">
                        <span class="text-xl font-black text-white font-mono">₹${amountFormatted}</span>
                        <span class="text-xs text-slate-500 block">Order Amount</span>
                    </div>
                </div>

                ${renderSellerPaymentDetailsHtml(d)}

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <!-- Seller Statement & Proof Status -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-red-400 font-bold uppercase tracking-wider block">Seller Appeal Notice</span>
                            <span class="text-[10px] text-slate-500 font-mono">User #${d.seller_id} (${sellerPhone})</span>
                        </div>
                        <p class="text-slate-300 italic">"${escapeHtml(d.reason || 'No statement provided')}"</p>
                        <div class="text-[11px] text-slate-400">
                            Seller: <strong class="text-white">${sellerName}</strong>
                        </div>
                        ${d.evidence_screenshot_path ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.evidence_screenshot_path)}', 'Seller Evidence Document - Dispute #${d.id}')" class="inline-flex items-center gap-1 text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold mt-1 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Seller Evidence Document</span>
                            </button>
                        ` : '<span class="text-[11px] text-slate-600 block">No seller file attached</span>'}
                    </div>

                    <!-- Buyer Payment Proof Status -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-400 font-bold uppercase tracking-wider block">Buyer Payment Submission</span>
                            <span class="text-[10px] text-slate-500 font-mono">User #${d.buyer_id} (${buyerPhone})</span>
                        </div>
                        <div class="text-slate-300 font-mono">UTR: <strong class="text-white">${escapeHtml(d.utr_number || 'N/A')}</strong></div>
                        <div class="text-[11px] text-slate-400">
                            Buyer: <strong class="text-white">${buyerName}</strong>
                        </div>
                        ${d.buyer_screenshot ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Screenshot - Dispute #${d.id} (Order #${d.child_order_id})')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold transition shadow-sm cursor-pointer mt-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Buyer Payment Screenshot</span>
                            </button>
                        ` : '<span class="text-[11px] text-slate-600 block">No buyer screenshot attached</span>'}
                    </div>
                </div>

                <!-- Refund Tracking Chip -->
                <div class="bg-slate-950/60 p-3 rounded-xl border border-slate-800 flex items-center justify-between text-xs font-mono">
                    <div>
                        <span class="text-slate-500">Refund Status:</span>
                        <span class="text-amber-400 font-bold ml-1">${escapeHtml((d.refund_status || 'NONE').toUpperCase())}</span>
                        ${d.refund_reference ? `<span class="text-slate-400 ml-2">Ref: ${escapeHtml(d.refund_reference)}</span>` : ''}
                    </div>
                    <button onclick="promptRefundStatus(${d.id})" class="text-[10px] text-indigo-400 hover:text-indigo-300 underline font-sans">
                        Update Refund Tracking
                    </button>
                </div>

                <!-- Administrative Arbitration Controls -->
                <div class="space-y-2 pt-2 border-t border-slate-800">
                    <input type="text" id="dispNotes_${d.id}" placeholder="Enter mandatory resolution rationale for immutable audit trail..."
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500">
                    <div class="flex items-center gap-2 justify-end flex-wrap">
                        <button onclick="requestMoreEvidence(${d.id})" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold transition">
                            Request More Evidence
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'force_settle')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-emerald-600/20">
                            Force Settle (Buyer)
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'cancel_reverse')" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-red-600/20">
                            Cancel & Return (Seller)
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    // Section B: Evidence Required
    if (section === 'evidence_required') {
        const evidenceNote = escapeHtml(d.resolution_notes || 'Specific statement or bank proof requested by administrator');
        const reqTime = escapeHtml(d.updated_at || d.created_at || 'Pending');
        const deadline = d.verification_deadline ? escapeHtml(d.verification_deadline) : 'Active SLA (24h Window)';

        return `
            <div class="bg-slate-900 border border-blue-500/30 rounded-2xl p-6 space-y-4 shadow-sm hover:border-blue-500/50 transition">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-mono font-bold text-blue-400">Dispute #${d.id} &bull; Order #${d.child_order_id}</span>
                        ${d.parent_order_id ? `<span class="text-xs font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 px-2 py-0.5 rounded">Parent #${d.parent_order_id}</span>` : ''}
                        <span class="px-2.5 py-0.5 rounded text-xs uppercase font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">
                            Evidence Required
                        </span>
                        <button type="button" onclick="openDisputeOrderDetailsModal(${d.id})" class="px-2.5 py-1 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg transition flex items-center gap-1.5 cursor-pointer ml-1" title="Inspect Full Order Details">
                            <svg class="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>View Details</span>
                        </button>
                    </div>
                    <div class="text-right">
                        <span class="text-xl font-black text-white font-mono">₹${amountFormatted}</span>
                        <span class="text-xs text-slate-500 block">Order Amount</span>
                    </div>
                </div>

                <!-- Evidence Request Summary Banner -->
                <div class="bg-blue-950/40 border border-blue-500/30 rounded-xl p-4 text-xs space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-blue-300 font-bold uppercase tracking-wider flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            Evidence Requested From: Buyer & Seller
                        </span>
                        <span class="text-slate-400 font-mono text-[11px]">Requested At: ${reqTime}</span>
                    </div>
                    <div class="text-slate-200 bg-slate-900/80 p-3 rounded-lg border border-slate-800 font-mono">
                        <span class="text-slate-400 font-sans block text-[10px] uppercase font-bold">Evidence Instructions</span>
                        "${evidenceNote}"
                    </div>
                    <div class="flex items-center justify-between text-[11px] text-slate-400 pt-1">
                        <span>Response Deadline: <strong class="text-amber-400 font-mono">${deadline}</strong></span>
                        <span>Current Status: <strong class="text-blue-400">${escapeHtml(d.status)}</strong></span>
                    </div>
                </div>

                ${renderSellerPaymentDetailsHtml(d)}

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-1">
                        <span class="text-slate-400 uppercase font-bold block text-[10px]">Seller Information</span>
                        <div class="font-mono text-white">User #${d.seller_id}: ${sellerName}</div>
                        <div class="text-slate-400">${sellerPhone}</div>
                        ${d.evidence_screenshot_path ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.evidence_screenshot_path)}', 'Seller Evidence Document - Dispute #${d.id}')" class="text-indigo-400 hover:text-indigo-300 underline font-semibold text-xs mt-1 block">Inspect Seller Evidence</button>
                        ` : '<span class="text-slate-600 block text-[11px] mt-1">No seller evidence uploaded yet</span>'}
                    </div>

                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-1">
                        <span class="text-slate-400 uppercase font-bold block text-[10px]">Buyer Information</span>
                        <div class="font-mono text-white">User #${d.buyer_id}: ${buyerName}</div>
                        <div class="text-slate-400">${buyerPhone}</div>
                        <div class="text-slate-400 font-mono">UTR: ${escapeHtml(d.utr_number || 'N/A')}</div>
                        ${d.buyer_screenshot ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Proof - Dispute #${d.id}')" class="text-amber-400 hover:text-amber-300 underline font-semibold text-xs mt-1 block">Inspect Buyer Screenshot</button>
                        ` : '<span class="text-slate-600 block text-[11px] mt-1">No buyer screenshot uploaded yet</span>'}
                    </div>
                </div>

                <!-- Admin Action Controls if ready to arbitrate or re-request -->
                <div class="space-y-2 pt-2 border-t border-slate-800">
                    <input type="text" id="dispNotes_${d.id}" placeholder="Enter updated instructions or mandatory resolution notes for audit..."
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-blue-500">
                    <div class="flex items-center gap-2 justify-end flex-wrap">
                        <button onclick="requestMoreEvidence(${d.id})" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold transition">
                            Update Evidence Request
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'force_settle')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-emerald-600/20">
                            Force Settle (Buyer)
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'cancel_reverse')" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-red-600/20">
                            Cancel & Return (Seller)
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    // Section C: Resolved Disputes
    if (section === 'resolved') {
        const isBuyerWon = (d.status === 'resolved_buyer');
        const decisionText = isBuyerWon ? 'Force Settled (Buyer Released)' : (d.status === 'resolved_seller' ? 'Cancelled & Reversed (Seller Recredited)' : escapeHtml(d.status));
        const decisionBadgeClass = isBuyerWon ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-indigo-500/20 text-indigo-400 border border-indigo-500/30';
        const resolverAdmin = escapeHtml(d.resolved_by_name || d.resolved_by_phone || (d.resolved_by_admin_id ? 'Admin #' + d.resolved_by_admin_id : 'System Administrator'));
        const resolutionDate = escapeHtml(d.resolved_at || 'Archived');
        const resolutionNotes = escapeHtml(d.resolution_notes || 'Resolved via arbitration');

        return `
            <div class="bg-slate-900 border border-slate-800/80 rounded-2xl p-6 space-y-4 shadow-sm hover:border-slate-700 transition">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-mono font-bold text-slate-300">Dispute #${d.id} &bull; Order #${d.child_order_id}</span>
                        ${d.parent_order_id ? `<span class="text-xs font-mono text-indigo-400 bg-indigo-500/10 border border-indigo-500/20 px-2 py-0.5 rounded">Parent #${d.parent_order_id}</span>` : ''}
                        <span class="px-2.5 py-0.5 rounded text-xs uppercase font-bold ${decisionBadgeClass}">
                            ${decisionText}
                        </span>
                        <button type="button" onclick="openDisputeOrderDetailsModal(${d.id})" class="px-2.5 py-1 text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg transition flex items-center gap-1.5 cursor-pointer ml-1" title="Inspect Full Order History">
                            <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>View History</span>
                        </button>
                    </div>
                    <div class="text-right">
                        <span class="text-xl font-black text-white font-mono">₹${amountFormatted}</span>
                        <span class="text-xs text-slate-500 block">Settled Order Amount</span>
                    </div>
                </div>

                <!-- Resolution Metadata Card -->
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 text-xs space-y-2">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-b border-slate-800/80 pb-2.5">
                        <div>
                            <span class="text-[10px] text-slate-500 uppercase font-bold block">Final Decision</span>
                            <span class="font-bold text-white">${decisionText}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-500 uppercase font-bold block">Resolution Date</span>
                            <span class="font-mono text-slate-300">${resolutionDate}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-slate-500 uppercase font-bold block">Resolved By</span>
                            <span class="font-semibold text-amber-400">${resolverAdmin}</span>
                        </div>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase font-bold block mb-1">Resolution Reason / Audit Notes</span>
                        <p class="text-slate-300 bg-slate-900/90 p-3 rounded-lg border border-slate-800 font-mono text-[11px]">
                            "${resolutionNotes}"
                        </p>
                    </div>
                </div>

                ${renderSellerPaymentDetailsHtml(d)}

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800/80 text-slate-400">
                        Buyer: <strong class="text-white">User #${d.buyer_id} (${buyerName})</strong> &bull; ${buyerPhone}
                        ${d.utr_number ? `<div class="font-mono text-[11px] text-slate-300 mt-1">UTR: ${escapeHtml(d.utr_number)}</div>` : ''}
                    </div>
                    <div class="bg-slate-950 p-3 rounded-xl border border-slate-800/80 text-slate-400">
                        Seller: <strong class="text-white">User #${d.seller_id} (${sellerName})</strong> &bull; ${sellerPhone}
                    </div>
                </div>
            </div>
        `;
    }

    return '';
}

async function loadAdminDisputes() {
    const status = document.getElementById('disputeStatusFilter')?.value || 'all';
    const query = document.getElementById('disputeSearchInput')?.value || '';
    const section = currentDisputeSection || 'pending';
    const page = Math.floor(disputePageOffset / disputePageLimit) + 1;

    try {
        const res = await fetch(`/api/admin.php?action=disputes&section=${encodeURIComponent(section)}&status=${encodeURIComponent(status)}&query=${encodeURIComponent(query)}&limit=${disputePageLimit}&page=${page}`);
        const data = await res.json();
        const list = document.getElementById('adminDisputesContainer');
        if (!list) return;

        if (data.counts) {
            setBadge('disputeSecCount-pending', data.counts.pending || 0);
            setBadge('disputeSecCount-evidence_required', data.counts.evidence_required || 0);
            setBadge('disputeSecCount-resolved', data.counts.resolved || 0);
        }

        disputeTotalRecords = data.total || 0;
        updateDisputePaginationControls();

        if (!data.success) {
            list.innerHTML = `
                <div class="bg-rose-950/40 border border-rose-500/40 rounded-2xl p-6 text-center text-rose-300 font-sans space-y-2">
                    <p class="font-bold text-sm text-rose-200">Unable to Load Disputes</p>
                    <p class="text-xs text-rose-300/80">${escapeHtml(data.error || 'Server returned an unsuccessful response.')}</p>
                    <button onclick="loadAdminDisputes()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-semibold transition mt-2">
                        Retry
                    </button>
                </div>
            `;
            return;
        }

        if (!data.disputes || data.disputes.length === 0) {
            const cleanSecTitle = section === 'pending' ? 'Pending Disputes' : (section === 'evidence_required' ? 'Evidence Required' : 'Resolved Disputes');
            list.innerHTML = `<div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-500 font-sans">No matching disputes found in ${cleanSecTitle}.</div>`;
            return;
        }

        list.innerHTML = data.disputes.map(d => renderDisputeCardHtml(d, section)).join('');
    } catch (e) {
        console.error('Failed to load disputes:', e);
        const list = document.getElementById('adminDisputesContainer');
        if (list) {
            list.innerHTML = `
                <div class="bg-rose-950/40 border border-rose-500/40 rounded-2xl p-6 text-center text-rose-300 font-sans space-y-2">
                    <p class="font-bold text-sm text-rose-200">Communication Error Loading Disputes</p>
                    <p class="text-xs text-rose-300/80">${escapeHtml(e.message || 'Network or server communication failure.')}</p>
                    <button onclick="loadAdminDisputes()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-semibold transition mt-2">
                        Retry
                    </button>
                </div>
            `;
        }
    }
}

async function arbitrateDispute(disputeId, decision) {
    const notesInput = document.getElementById(`dispNotes_${disputeId}`);
    const notes = notesInput ? notesInput.value.trim() : '';

    if (!notes) {
        alert('Please enter mandatory resolution notes for audit tracking before arbitrating.');
        return;
    }

    if (!confirm(`Are you sure you want to execute decision '${decision}' on Dispute #${disputeId}? This action will permanently adjust user balances and be audited.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=arbitrate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dispute_id: disputeId, decision, resolution_notes: notes })
        });
        const data = await res.json();
        if (data.success) {
            alert('Dispute arbitrated and audited successfully.');
            loadAdminDisputes();
        } else {
            alert(data.error || 'Arbitration failed.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

async function requestMoreEvidence(disputeId) {
    const instructions = prompt(`Enter specific instructions for additional evidence needed for Dispute #${disputeId}:`);
    if (!instructions) return;

    try {
        const res = await fetch('/api/admin.php?action=request_evidence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dispute_id: disputeId, instructions })
        });
        const data = await res.json();
        if (data.success) {
            alert('Request logged and sent to traders.');
            loadAdminDisputes();
        } else {
            alert(data.error || 'Failed.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

async function promptRefundStatus(disputeId) {
    const status = prompt(`Enter refund status (none, pending, issued, not_applicable):`, 'issued');
    if (!status) return;

    const ref = prompt(`Enter refund bank reference / UTR number (optional):`, '');
    const notes = prompt(`Enter refund notes:`, 'Bank refund processed');

    try {
        const res = await fetch('/api/admin.php?action=update_refund', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dispute_id: disputeId, status, refund_reference: ref, notes })
        });
        const data = await res.json();
        if (data.success) {
            alert('Refund tracking updated.');
            loadAdminDisputes();
        } else {
            alert(data.error || 'Failed.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

// -------------------------------------------------------------
// DEDICATED SECTION: SELLER PAYMENT DETAILS USED FOR THIS ORDER
// -------------------------------------------------------------

function renderSellerPaymentDetailsHtml(d) {
    const parentOrderText = d.parent_order_id ? `#${d.parent_order_id}` : 'None (Direct Sell Order)';
    const buyerText = d.buyer_id ? `User #${d.buyer_id}` : 'Unassigned';
    const sellerText = d.seller_id ? `User #${d.seller_id}` : 'N/A';
    const amountFormatted = parseFloat(d.amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const creationTime = d.order_created_at || d.created_at || 'Unavailable';
    const proofTime = d.proof_submission_timestamp || (d.proof_submitted_at || d.proof_created_at) || 'Not Submitted / Pending';
    const utrText = d.utr_number || 'Pending Submission';
    const paymentMethod = d.payment_method || 'UPI';

    return `
        <!-- DEDICATED SECTION: Seller Payment Details Used for This Order -->
        <div class="bg-slate-950/95 rounded-2xl border border-amber-500/30 p-4 sm:p-5 space-y-3.5 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-800/80 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 flex items-center justify-center font-black">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <div>
                        <h4 class="text-xs sm:text-sm font-extrabold uppercase tracking-wider text-amber-400">
                            Seller Payment Details Used for This Order
                        </h4>
                        <p class="text-[10px] text-slate-400 font-sans">
                            Immutable payment destination captured at order creation
                        </p>
                    </div>
                </div>
                <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400 hidden sm:inline-block">
                    Dispute Verification Target
                </span>
            </div>

            <!-- Key Fields Grid (13 Required Attributes) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
                <!-- 1 & 2: Order ID & Parent Sell Order ID -->
                <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                    <span class="text-[10px] text-slate-500 uppercase font-sans font-bold block">Order Reference</span>
                    <div class="font-mono text-sm font-bold text-white mt-0.5">
                        Order ID: <span class="text-amber-400 font-black">#${d.child_order_id}</span>
                    </div>
                    <div class="text-[11px] font-mono text-slate-400 mt-1">
                        Parent Sell Order ID: <strong class="${d.parent_order_id ? 'text-indigo-400' : 'text-slate-500'}">${parentOrderText}</strong>
                    </div>
                </div>

                <!-- 3 & 4: Buyer User ID & Seller User ID -->
                <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                    <span class="text-[10px] text-slate-500 uppercase font-sans font-bold block">Transacting Parties</span>
                    <div class="text-[11px] text-slate-300 mt-0.5">
                        Buyer User ID: <strong class="text-white font-mono">${buyerText}</strong>
                        ${d.buyer_phone ? `<span class="text-[10px] text-slate-400 block">${escapeHtml(d.buyer_name || 'Buyer')} (${escapeHtml(d.buyer_phone)})</span>` : ''}
                    </div>
                    <div class="text-[11px] text-slate-300 mt-1.5">
                        Seller User ID: <strong class="text-white font-mono">${sellerText}</strong>
                        ${d.seller_phone ? `<span class="text-[10px] text-slate-400 block">${escapeHtml(d.seller_name || 'Seller')} (${escapeHtml(d.seller_phone)})</span>` : ''}
                    </div>
                </div>

                <!-- 5 & 13: Order Amount & Current Dispute Status -->
                <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                    <span class="text-[10px] text-slate-500 uppercase font-sans font-bold block">Amount &amp; Dispute Status</span>
                    <div class="text-emerald-400 font-mono font-black text-base mt-0.5">
                        ₹${amountFormatted}
                    </div>
                    <div class="mt-1 flex items-center gap-1.5">
                        <span class="text-[10px] text-slate-500 font-sans">Dispute Status:</span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase font-mono ${d.status === 'resolved_buyer' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : (d.status === 'resolved_seller' ? 'bg-indigo-500/20 text-indigo-400 border border-indigo-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30')}">
                            ${escapeHtml(d.status)}
                        </span>
                    </div>
                </div>

                <!-- 6, 7 & 8: Seller's UPI ID, Account Holder Name, Payment Method -->
                <div class="bg-slate-900/90 p-3.5 rounded-xl border border-amber-500/30 sm:col-span-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-amber-400 uppercase font-sans font-extrabold tracking-wider block">
                            Seller's UPI ID Used for This Order
                        </span>
                        <span class="text-[10px] font-mono text-slate-400">
                            Payment Method: <strong class="text-indigo-300 font-bold">${escapeHtml(paymentMethod)}</strong>
                        </span>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        ${d.has_historical_upi ? `
                            <span class="font-mono text-sm sm:text-base font-extrabold text-white bg-slate-950 px-3 py-1 rounded-lg border border-slate-800 select-all tracking-wide">
                                ${escapeHtml(d.seller_order_upi_id)}
                            </span>
                            <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 font-semibold font-mono">
                                &bull; Verified Order Destination
                            </span>
                        ` : `
                            <span class="font-mono text-xs font-bold text-amber-400 bg-amber-500/10 px-3 py-1 rounded-lg border border-amber-500/30">
                                Historical UPI Details Unavailable
                            </span>
                        `}
                    </div>
                    <div class="text-[11px] text-slate-400 mt-2 font-sans flex flex-wrap items-center gap-2">
                        <span>Account Holder Name: <strong class="text-slate-100">${escapeHtml(d.seller_order_account_holder || 'Unavailable')}</strong></span>
                    </div>
                </div>

                <!-- 9 & 10: Order Creation Timestamp & Payment Proof Submission Timestamp -->
                <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800">
                    <span class="text-[10px] text-slate-500 uppercase font-sans font-bold block">Timestamps</span>
                    <div class="text-[11px] text-slate-400 mt-0.5 font-mono">
                        Order Created: <span class="text-slate-200">${escapeHtml(creationTime)}</span>
                    </div>
                    <div class="text-[11px] text-slate-400 mt-1.5 font-mono">
                        Proof Submitted: <span class="${proofTime.includes('Pending') ? 'text-amber-400/80 italic' : 'text-slate-200'}">${escapeHtml(proofTime)}</span>
                    </div>
                </div>

                <!-- 11 & 12: Buyer-Submitted UTR & Payment Screenshot -->
                <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-800 sm:col-span-2 lg:col-span-3">
                    <span class="text-[10px] text-slate-500 uppercase font-sans font-bold block">Buyer-Submitted Payment Proof</span>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 mt-1">
                        <div class="text-xs font-mono">
                            Buyer UTR / Ref: <strong class="${d.utr_number ? 'text-white bg-slate-950 px-2.5 py-1 rounded border border-slate-800 select-all tracking-wider' : 'text-slate-500 italic'}">${escapeHtml(utrText)}</strong>
                        </div>
                        <div>
                            ${d.buyer_screenshot ? `
                                <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Screenshot - Dispute #${d.id} (Order #${d.child_order_id})')" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold transition shadow-sm cursor-pointer">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <span>Inspect Payment Screenshot</span>
                                </button>
                            ` : '<span class="text-xs text-slate-500 italic">No Payment Screenshot Uploaded</span>'}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
}

async function openDisputeOrderDetailsModal(disputeId) {
    const modal = document.getElementById('disputeOrderDetailsModal');
    const content = document.getElementById('disputeOrderDetailsContent');
    const badge = document.getElementById('modalDisputeStatusBadge');
    if (!modal || !content) return;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    content.innerHTML = '<div class="p-8 text-center text-slate-500 font-sans">Loading immutable seller payment snapshot...</div>';

    try {
        const res = await fetch(`/api/admin.php?action=get_dispute&dispute_id=${encodeURIComponent(disputeId)}`);
        const data = await res.json();
        if (!data.success || !data.dispute) {
            content.innerHTML = `<div class="p-6 text-center text-rose-400 font-sans">${escapeHtml(data.error || 'Failed to load dispute order details.')}</div>`;
            return;
        }

        const d = data.dispute;
        if (badge) {
            badge.textContent = d.status || '';
            badge.className = `text-xs px-2.5 py-0.5 rounded font-mono font-bold uppercase ${d.status === 'resolved_buyer' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : (d.status === 'resolved_seller' ? 'bg-indigo-500/20 text-indigo-400 border border-indigo-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30')}`;
        }

        content.innerHTML = `
            ${renderSellerPaymentDetailsHtml(d)}

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                <!-- Seller Notice -->
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-1.5">
                    <span class="text-red-400 font-bold uppercase tracking-wider block">Seller Appeal Notice</span>
                    <p class="text-slate-300 italic">"${escapeHtml(d.reason || 'No statement provided')}"</p>
                    ${d.evidence_screenshot_path ? `
                        <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.evidence_screenshot_path)}', 'Seller Evidence Document - Dispute #${d.id}')" class="inline-flex items-center gap-1 text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold mt-1">
                            <span>Inspect Seller Evidence Document &rarr;</span>
                        </button>
                    ` : '<span class="text-slate-600 text-[11px] block">No seller file attached</span>'}
                </div>

                <!-- Buyer Proof -->
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-1.5">
                    <span class="text-emerald-400 font-bold uppercase tracking-wider block">Buyer Payment Submission</span>
                    <div class="text-slate-300 font-mono">UTR: <strong class="text-white">${escapeHtml(d.utr_number || 'N/A')}</strong></div>
                    <div class="text-slate-400 text-[11px]">Notes: ${escapeHtml(d.buyer_notes || 'None')}</div>
                    ${d.buyer_screenshot ? `
                        <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Proof - Dispute #${d.id}')" class="inline-flex items-center gap-1 text-xs text-amber-400 hover:text-amber-300 underline font-semibold mt-1">
                            <span>View Screenshot in Lightbox &rarr;</span>
                        </button>
                    ` : '<span class="text-slate-600 text-[11px] block">No payment screenshot attached</span>'}
                </div>
            </div>

            ${(d.resolution_notes || d.resolved_at) ? `
                <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 text-xs space-y-1.5 font-mono text-slate-300">
                    <div class="flex items-center justify-between text-[10px] text-slate-400 uppercase font-bold font-sans">
                        <span>Resolution Arbitration History</span>
                        <span>Resolved: ${escapeHtml(d.resolved_at || 'Archived')}</span>
                    </div>
                    ${(d.resolved_by_name || d.resolved_by_phone) ? `
                        <div class="text-[11px] text-amber-400">
                            Arbitrated By: ${escapeHtml(d.resolved_by_name || '')} (${escapeHtml(d.resolved_by_phone || '')})
                        </div>
                    ` : ''}
                    <div class="text-slate-200 bg-slate-900/80 p-3 rounded-lg border border-slate-800 font-mono text-[11px] mt-1">
                        ${escapeHtml(d.resolution_notes || 'Resolved')}
                    </div>
                </div>
            ` : ''}
        `;
    } catch (e) {
        content.innerHTML = `<div class="p-6 text-center text-rose-400 font-sans">Server communication error loading dispute details.</div>`;
    }
}

function closeDisputeOrderDetailsModal() {
    const modal = document.getElementById('disputeOrderDetailsModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
window.renderSellerPaymentDetailsHtml = renderSellerPaymentDetailsHtml;
window.openDisputeOrderDetailsModal = openDisputeOrderDetailsModal;
window.closeDisputeOrderDetailsModal = closeDisputeOrderDetailsModal;
window.switchDisputeSection = switchDisputeSection;
window.changeDisputePageSize = changeDisputePageSize;
window.changeDisputePage = changeDisputePage;
window.resetDisputeFilters = resetDisputeFilters;
window.loadAdminDisputes = loadAdminDisputes;

// -------------------------------------------------------------
// TAB 5 & 6: REWARDS, CONTENT & GLOBAL CONFIGURATIONS
// -------------------------------------------------------------

async function loadAdminRewardSettings() {
    loadSettingsIntoGrid('rewardSettingsGrid', [
        'purchase_reward_percent',
        'referral_l1_percent',
        'referral_l2_percent',
        'reward_eligible_types',
        'min_sell_amount',
        'max_sell_amount',
        'sell_denomination_step',
        'weekly_selling_target',
        'max_child_splits'
    ]);
}

async function loadAdminContentSettings() {
    try {
        const res = await fetch('/api/admin.php?action=settings');
        const data = await res.json();
        if (data.success && data.settings) {
            const tg = data.settings.find(s => s.setting_key === 'telegram_support_url');
            if (tg) {
                const input = document.getElementById('quickTelegramUrlInput');
                const testBtn = document.getElementById('quickTelegramTestBtn');
                if (input && !input.value) input.value = tg.setting_value;
                if (testBtn) testBtn.href = tg.setting_value;
            }
        }
    } catch (e) {}

    loadSettingsIntoGrid('contentSettingsGrid', [
        'telegram_support_url',
        'telegram_support_username',
        'platform_mode',
        'allow_user_registration',
        'allow_sell_listings',
        'allow_buy_orders',
        'site_name',
        'site_tagline',
        'token_rate_inr',
        'announcement_banner_active',
        'announcement_banner_text',
        'announcement_banner_image',
        'maintenance_mode',
        'maintenance_message',
        'approved_upi_config',
        'support_contact_info',
        'terms_of_service',
        'privacy_policy',
        'buyer_payment_window_seconds',
        'seller_verify_window_seconds',
        'platform_logo_url',
        'platform_favicon_url'
    ]);
}

async function saveQuickTelegramUrl() {
    const input = document.getElementById('quickTelegramUrlInput');
    let val = input ? input.value.trim() : '';
    if (!val) {
        alert('Please enter a valid Telegram link (e.g. t.me/amilacorp or https://t.me/amilacorp) or username.');
        return;
    }

    // Normalize to complete clickable https:// URL
    let fullUrl = val;
    if (fullUrl.startsWith('@')) {
        fullUrl = `https://t.me/${fullUrl.substring(1)}`;
    } else if (fullUrl.startsWith('t.me/')) {
        fullUrl = `https://${fullUrl}`;
    } else if (!fullUrl.startsWith('http://') && !fullUrl.startsWith('https://')) {
        fullUrl = `https://t.me/${fullUrl.replace(/^\/+/, '')}`;
    }

    try {
        const res = await fetch('/api/admin.php?action=settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                setting_key: 'telegram_support_url',
                setting_value: fullUrl,
                reason: 'Dynamic admin update for Telegram Support link'
            })
        });
        const data = await res.json();
        if (data.success) {
            if (input) input.value = fullUrl;
            const testBtn = document.getElementById('quickTelegramTestBtn');
            if (testBtn) testBtn.href = fullUrl;
            const gridInp = document.getElementById('settingInput_telegram_support_url');
            if (gridInp) gridInp.value = fullUrl;
            alert('Official Telegram Support link updated successfully! It is now live across the platform.');
            loadAdminContentSettings();
        } else {
            alert(data.error || 'Failed to update Telegram link.');
        }
    } catch (e) {
        alert('Network error updating Telegram link.');
    }
}

async function purgeDemoAccountsAndEnforceLive() {
    const conf = confirm(
        'WARNING: This will permanently purge all test/demo accounts and simulated orders from the database, leaving only real registered users. The platform will be locked in 100% Live Production Mode.\n\nAre you sure you want to proceed?'
    );
    if (!conf) return;

    try {
        const res = await fetch('/api/admin.php?action=purge_demo_data', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Platform successfully switched to 100% Live Mode!');
            loadAdminDashboard();
            loadAdminContentSettings();
        } else {
            alert(data.error || 'Failed to purge demo data.');
        }
    } catch (e) {
        alert('Network error executing live purge.');
    }
}

async function loadSettingsIntoGrid(containerId, keys) {
    const grid = document.getElementById(containerId);
    if (!grid) return;

    try {
        const res = await fetch('/api/admin.php?action=settings');
        const data = await res.json();
        if (!data.success || !data.settings) return;

        const filtered = data.settings.filter(s => keys.includes(s.setting_key));

        grid.innerHTML = filtered.map(s => `
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-mono font-bold text-amber-400">${escapeHtml(s.setting_key)}</span>
                    <span class="text-[10px] text-slate-500">${s.updated_at || 'Default'}</span>
                </div>
                <p class="text-xs text-slate-400">${escapeHtml(s.description || '')}</p>
                <div>
                    <input type="text" id="settingInput_${s.setting_key}" value="${escapeHtml(s.setting_value)}"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white font-mono focus:border-amber-500 focus:outline-none">
                </div>
                <button onclick="saveGlobalSetting('${s.setting_key}', this)" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <span>Save Configuration</span>
                </button>
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load settings:', e);
    }
}

async function saveGlobalSetting(key, btn = null) {
    const input = document.getElementById(`settingInput_${key}`);
    let val = input ? input.value : '';
    if (key === 'telegram_support_url' && val) {
        let normVal = val.trim();
        if (normVal.startsWith('@')) {
            normVal = `https://t.me/${normVal.substring(1)}`;
        } else if (normVal.startsWith('t.me/')) {
            normVal = `https://${normVal}`;
        } else if (!normVal.startsWith('http://') && !normVal.startsWith('https://')) {
            normVal = `https://t.me/${normVal.replace(/^\/+/, '')}`;
        }
        val = normVal;
    } else if (key === 'telegram_support_username' && val) {
        let normVal = val.trim();
        normVal = normVal.replace(/^https?:\/\/t\.me\//i, '').replace(/^t\.me\//i, '').replace(/^\/+/, '');
        val = normVal.startsWith('@') ? normVal : ('@' + normVal);
    }

    const reason = 'Admin configuration update';
    let originalBtnHtml = '';
    if (btn) {
        originalBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<span class="inline-flex items-center gap-1"><svg class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Saving...</span>`;
    }

    try {
        const res = await fetch('/api/admin.php?action=settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ setting_key: key, setting_value: val, reason })
        });
        const data = await res.json();
        if (data.success) {
            if (input) input.value = val;
            if (key === 'telegram_support_url' || key === 'telegram_support_username') {
                const quickInp = document.getElementById('quickTelegramUrlInput');
                const testBtn = document.getElementById('quickTelegramTestBtn');
                const gridUrl = document.getElementById('settingInput_telegram_support_url');
                const gridUser = document.getElementById('settingInput_telegram_support_username');
                if (key === 'telegram_support_url') {
                    if (quickInp) quickInp.value = val;
                    if (testBtn) testBtn.href = val;
                    if (gridUrl) gridUrl.value = val;
                    const handle = val.replace(/^https?:\/\/t\.me\//i, '').replace(/^t\.me\//i, '').replace(/^@/, '');
                    if (gridUser && handle) gridUser.value = '@' + handle;
                } else {
                    const handle = val.replace(/^https?:\/\/t\.me\//i, '').replace(/^t\.me\//i, '').replace(/^@/, '');
                    const fullUrl = 'https://t.me/' + handle;
                    if (quickInp) quickInp.value = fullUrl;
                    if (testBtn) testBtn.href = fullUrl;
                    if (gridUrl) gridUrl.value = fullUrl;
                }
            }

            if (btn) {
                btn.classList.remove('bg-slate-800', 'text-slate-200');
                btn.classList.add('bg-emerald-600', 'text-white');
                btn.innerHTML = `<span>Saved ✓</span>`;
                setTimeout(() => {
                    btn.classList.remove('bg-emerald-600', 'text-white');
                    btn.classList.add('bg-slate-800', 'text-slate-200');
                    btn.innerHTML = originalBtnHtml;
                    btn.disabled = false;
                }, 1500);
            } else {
                alert(`Setting '${key}' saved and applied successfully!`);
            }
        } else {
            alert(data.error || 'Failed to update setting.');
            if (btn) {
                btn.innerHTML = originalBtnHtml;
                btn.disabled = false;
            }
        }
    } catch (e) {
        alert('Network error updating setting.');
        if (btn) {
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;
        }
    }
}

// -------------------------------------------------------------
// TAB 7: REPORTS (Requirement 7)
// -------------------------------------------------------------

function loadAdminReports() {
    // Static UI triggers direct download link
}

function downloadReport(type) {
    window.location.href = `/api/admin.php?action=export_csv&type=${encodeURIComponent(type)}`;
}

// -------------------------------------------------------------
// TAB 8: AUDIT LOGS (Requirement 7)
// -------------------------------------------------------------

async function loadAdminAuditLogs() {
    try {
        const res = await fetch('/api/admin.php?action=audit_logs&limit=100');
        const data = await res.json();
        const tbody = document.getElementById('adminAuditLogsTableBody');
        if (!tbody) return;

        if (!data.success || !data.audit_logs || data.audit_logs.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-500">No audit log records found.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.audit_logs.map(log => `
            <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                <td class="p-3 text-slate-500">#${log.id}</td>
                <td class="p-3 text-amber-400 font-bold">${escapeHtml(log.admin_phone || 'Admin')}</td>
                <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 text-slate-200 font-bold">${escapeHtml(log.action_type)}</span></td>
                <td class="p-3 text-slate-400">${escapeHtml(log.target_entity)} #${escapeHtml(log.target_id)}</td>
                <td class="p-3 font-sans text-slate-300 max-w-sm truncate" title="${escapeHtml(log.reason)}">${escapeHtml(log.reason)}</td>
                <td class="p-3 text-[11px] text-slate-500">${escapeHtml(log.ip_address || '-')}</td>
                <td class="p-3 text-[11px] text-slate-500">${log.created_at}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load audit logs:', e);
    }
}

// -------------------------------------------------------------
// TAB 9: ADMINISTRATORS & TEAMS (Part 10, Item 4)
// -------------------------------------------------------------

async function loadAdminDirectory() {
    const tbody = document.getElementById('adminDirectoryTableBody');
    if (!tbody) return;

    try {
        const res = await fetch('/api/admin.php?action=admins_list');
        const data = await res.json();

        if (!data.success || !data.admins || data.admins.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-500">No administrators found.</td></tr>`;
            return;
        }

        const roleBadges = {
            'super_admin': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-amber-500/20 text-amber-400 border border-amber-500/30">SUPER ADMIN</span>',
            'support_agent': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-blue-500/20 text-blue-400 border border-blue-500/30">SUPPORT TEAM</span>',
            'support_team': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-blue-500/20 text-blue-400 border border-blue-500/30">SUPPORT TEAM</span>',
            'finance_manager': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-purple-500/20 text-purple-400 border border-purple-500/30">FINANCE TEAM</span>',
            'finance_team': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-purple-500/20 text-purple-400 border border-purple-500/30">FINANCE TEAM</span>',
            'order_manager': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">ORDER TEAM</span>',
            'dispute_manager': '<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-rose-500/20 text-rose-400 border border-rose-500/30">DISPUTES TEAM</span>'
        };

        tbody.innerHTML = data.admins.map(a => {
            const roleKey = a.admin_role || (a.role === 'admin' ? 'super_admin' : 'support_agent');
            const badgeHtml = roleBadges[roleKey] || `<span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono bg-slate-800 text-slate-300 uppercase">${escapeHtml(roleKey)}</span>`;
            return `
                <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                    <td class="p-3 text-slate-500 font-bold">
                        #${a.id} &bull; ${escapeHtml(a.referral_code || 'ADM')}
                    </td>
                    <td class="p-3">
                        <span class="text-white font-bold block">${escapeHtml(a.phone)}</span>
                        <span class="text-[10px] text-slate-400 font-sans block">${escapeHtml(a.account_holder_name || a.email || 'Admin Staff')}</span>
                    </td>
                    <td class="p-3">
                        ${badgeHtml}
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${a.account_status === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'}">
                            ${a.account_status}
                        </span>
                    </td>
                    <td class="p-3 text-slate-400 text-[11px]">
                        ${escapeHtml(a.created_at || 'System')}
                    </td>
                    <td class="p-3 font-sans">
                        <div class="flex items-center gap-2">
                            <button onclick="promptChangeAdminRole(${a.id}, '${escapeHtml(roleKey)}')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs font-semibold transition" title="Reassign Team Role">
                                Change Team
                            </button>
                            <button onclick="confirmDeleteAdmin(${a.id}, '${escapeHtml(a.phone)}')" class="px-2.5 py-1 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 rounded-lg text-xs font-semibold transition" title="Remove Administrator">
                                Remove
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load admin directory:', e);
    }
}

function openAddAdminModal() {
    document.getElementById('newAdminPhone').value = '';
    document.getElementById('newAdminName').value = '';
    document.getElementById('newAdminEmail').value = '';
    document.getElementById('newAdminPassword').value = '';
    document.getElementById('newAdminRole').value = 'support_agent';
    document.getElementById('addAdminModal').classList.remove('hidden');
}

function closeAddAdminModal() {
    document.getElementById('addAdminModal').classList.add('hidden');
}

async function handleAddAdminSubmit(event) {
    event.preventDefault();
    const phone = document.getElementById('newAdminPhone').value.trim();
    const name = document.getElementById('newAdminName').value.trim();
    const email = document.getElementById('newAdminEmail').value.trim();
    const password = document.getElementById('newAdminPassword').value;
    const admin_role = document.getElementById('newAdminRole').value;

    if (!phone || phone.length !== 10) {
        alert('Please enter a valid 10-digit mobile number.');
        return;
    }

    if (password.length < 6) {
        alert('Password must be at least 6 characters.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone, name, email, password, admin_role })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Administrator created successfully!');
            closeAddAdminModal();
            loadAdminDirectory();
        } else {
            alert(data.error || 'Failed to create administrator.');
        }
    } catch (e) {
        alert('Network error creating administrator.');
    }
}

async function promptChangeAdminRole(adminId, currentRole) {
    const roles = ['super_admin', 'support_agent', 'finance_manager', 'order_manager'];
    const choice = prompt(
        `Select new role for Admin #${adminId} (current: ${currentRole}):\n` +
        `1 = support_agent (Support Team)\n` +
        `2 = finance_manager (Finance Team)\n` +
        `3 = order_manager (Order Team)\n` +
        `4 = super_admin (Super Admin)\n\n` +
        `Type role name (e.g. support_agent or finance_manager):`,
        currentRole
    );

    if (!choice) return;

    let targetRole = choice.trim();
    if (targetRole === '1') targetRole = 'support_agent';
    else if (targetRole === '2') targetRole = 'finance_manager';
    else if (targetRole === '3') targetRole = 'order_manager';
    else if (targetRole === '4') targetRole = 'super_admin';

    if (!roles.includes(targetRole)) {
        alert(`Invalid role '${targetRole}'. Must be one of: ${roles.join(', ')}`);
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_update_role', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ admin_id: adminId, admin_role: targetRole })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Administrator role updated successfully!');
            loadAdminDirectory();
        } else {
            alert(data.error || 'Failed to update administrator role.');
        }
    } catch (e) {
        alert('Network error updating administrator role.');
    }
}

async function confirmDeleteAdmin(adminId, phone) {
    if (!confirm(`Are you sure you want to remove Administrator #${adminId} (${phone})? This will revoke all administrative privileges.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ admin_id: adminId })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Administrator removed successfully!');
            loadAdminDirectory();
        } else {
            alert(data.error || 'Failed to remove administrator.');
        }
    } catch (e) {
        alert('Network error removing administrator.');
    }
}

async function handleChangeAdminPassword(event) {
    event.preventDefault();
    const curPass = document.getElementById('adminCurrentPassword').value;
    const newPass = document.getElementById('adminNewPassword').value;
    const confPass = document.getElementById('adminConfirmPassword').value;

    if (newPass !== confPass) {
        alert('New passwords do not match. Please re-enter.');
        return;
    }

    if (newPass.length < 6) {
        alert('New password must be at least 6 characters long.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: curPass, new_password: newPass })
        });
        const data = await res.json();
        if (data.success) {
            alert('Your administrator login password has been changed successfully!');
            document.getElementById('adminCurrentPassword').value = '';
            document.getElementById('adminNewPassword').value = '';
            document.getElementById('adminConfirmPassword').value = '';
        } else {
            alert(data.error || 'Password update failed.');
        }
    } catch (e) {
        alert('Network error updating password.');
    }
}

async function adminLogout() {
    await fetch('/api/auth.php?action=logout', { method: 'POST' });
    window.location.href = '/admin_login.php';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatCurrency(val) {
    return Number(val || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// -------------------------------------------------------------
// EVIDENCE LIGHTBOX CONTROLLER (Part 11, Item 5)
// -------------------------------------------------------------

let adminLightboxImages = [];
let adminLightboxIndex = 0;

function openEvidenceLightbox(images, title = 'Payment Screenshot Inspection') {
    if (!images) return;
    let list = [];
    if (typeof images === 'string') {
        list = images.split(',').map(s => s.trim()).filter(Boolean);
    } else if (Array.isArray(images)) {
        list = images.map(s => String(s).trim()).filter(Boolean);
    }
    if (list.length === 0) return;

    adminLightboxImages = list.map(img => {
        if (img.startsWith('http://') || img.startsWith('https://') || img.startsWith('/serve_file.php')) {
            return img;
        }
        return `/serve_file.php?file=${encodeURIComponent(img)}`;
    });
    adminLightboxIndex = 0;

    const modal = document.getElementById('evidenceLightboxModal');
    if (!modal) return;

    const titleEl = document.getElementById('lightboxTitle');
    if (titleEl) titleEl.innerText = title;

    updateAdminLightboxView();
    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

const openAdminLightbox = openEvidenceLightbox;

function closeEvidenceLightbox() {
    const modal = document.getElementById('evidenceLightboxModal');
    if (modal) modal.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

function updateAdminLightboxView() {
    if (adminLightboxImages.length === 0) return;
    const currentSrc = adminLightboxImages[adminLightboxIndex];
    const imgEl = document.getElementById('lightboxImage');
    if (imgEl) imgEl.src = currentSrc;

    const subEl = document.getElementById('lightboxSubtitle');
    if (subEl) {
        subEl.innerText = `Image ${adminLightboxIndex + 1} of ${adminLightboxImages.length}`;
    }

    const downBtn = document.getElementById('lightboxDownloadBtn');
    if (downBtn) downBtn.href = currentSrc;

    const prevBtn = document.getElementById('lightboxPrevBtn');
    const nextBtn = document.getElementById('lightboxNextBtn');
    if (prevBtn && nextBtn) {
        if (adminLightboxImages.length > 1) {
            prevBtn.classList.remove('hidden');
            nextBtn.classList.remove('hidden');
        } else {
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
        }
    }
}

function navigateLightbox(direction) {
    if (adminLightboxImages.length <= 1) return;
    adminLightboxIndex = (adminLightboxIndex + direction + adminLightboxImages.length) % adminLightboxImages.length;
    updateAdminLightboxView();
}

// Global keyboard accessibility for modal
document.addEventListener('keydown', (e) => {
    const modal = document.getElementById('evidenceLightboxModal');
    if (modal && !modal.classList.contains('hidden')) {
        if (e.key === 'Escape') closeEvidenceLightbox();
        if (e.key === 'ArrowLeft') navigateLightbox(-1);
        if (e.key === 'ArrowRight') navigateLightbox(1);
    }
});

// -------------------------------------------------------------
// TAB 10: FRAUD & AI SECURITY DASHBOARD (Requirement 9)
// -------------------------------------------------------------

let currentFraudPage = 1;
const fraudPageSize = 25;
let fraudDebounceTimer = null;
let activeInspectAssessment = null;

function debounceFraudSearch() {
    clearTimeout(fraudDebounceTimer);
    fraudDebounceTimer = setTimeout(() => {
        currentFraudPage = 1;
        loadAdminFraudAssessments();
    }, 350);
}

function changeFraudPage(direction) {
    currentFraudPage = Math.max(1, currentFraudPage + direction);
    loadAdminFraudAssessments();
}

async function loadAdminFraudAssessments(silent = false) {
    const tbody = document.getElementById('adminFraudTableBody');
    if (!tbody) return;

    if (!silent) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="p-8 text-center text-slate-500 font-sans">
                    <div class="inline-flex items-center gap-2">
                        <svg class="animate-spin h-4 w-4 text-rose-500" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        <span>Scanning forensic ledger & risk profiles...</span>
                    </div>
                </td>
            </tr>
        `;
    }

    const query = document.getElementById('fraudSearchInput')?.value?.trim() || '';
    const decision = document.getElementById('fraudDecisionFilter')?.value || 'all';
    const riskLevel = document.getElementById('fraudRiskFilter')?.value || 'all';
    const offset = (currentFraudPage - 1) * fraudPageSize;

    try {
        const url = `/api/admin.php?action=fraud_assessments&query=${encodeURIComponent(query)}&decision=${encodeURIComponent(decision)}&risk_level=${encodeURIComponent(riskLevel)}&limit=${fraudPageSize}&offset=${offset}`;
        const res = await fetch(url);
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="9" class="p-6 text-center text-rose-400 font-sans">${escapeHtml(data.error || 'Failed to fetch fraud assessments.')}</td></tr>`;
            return;
        }

        const total = data.total || 0;
        const assessments = data.assessments || [];

        // Update pagination controls
        const pageInfo = document.getElementById('fraudPaginationInfo');
        if (pageInfo) {
            const start = total === 0 ? 0 : offset + 1;
            const end = Math.min(offset + fraudPageSize, total);
            pageInfo.innerText = `Showing ${start}–${end} of ${total} assessments`;
        }

        const prevBtn = document.getElementById('fraudPrevBtn');
        const nextBtn = document.getElementById('fraudNextBtn');
        if (prevBtn) prevBtn.disabled = currentFraudPage <= 1;
        if (nextBtn) nextBtn.disabled = (offset + fraudPageSize) >= total;

        if (assessments.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="p-8 text-center text-slate-500 font-sans">
                        No fraud assessments found matching the active search & risk criteria.
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = assessments.map(a => {
            // Risk Level Styling
            let riskClass = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
            if (a.risk_level === 'high') {
                riskClass = 'bg-rose-500/20 text-rose-400 border border-rose-500/30';
            } else if (a.risk_level === 'medium') {
                riskClass = 'bg-amber-500/20 text-amber-400 border border-amber-500/30';
            }

            // Decision Badge Styling
            let decisionClass = 'bg-slate-800 text-slate-300 border border-slate-700';
            if (a.decision === 'VERIFIED') {
                decisionClass = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
            } else if (a.decision === 'PENDING_REVIEW') {
                decisionClass = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
            } else if (a.decision === 'REJECTED') {
                decisionClass = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
            } else if (a.decision === 'FAILED') {
                decisionClass = 'bg-slate-700/40 text-slate-400 border border-slate-600';
            }

            // Reason Codes (first 2 chips)
            const reasonBadges = (a.reason_codes_array || []).slice(0, 2).map(r => `
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-mono bg-slate-800 text-slate-300 border border-slate-700">${escapeHtml(r)}</span>
            `).join(' ');
            const extraCount = (a.reason_codes_array || []).length > 2 ? `<span class="text-[10px] text-slate-500">+${(a.reason_codes_array.length - 2)} more</span>` : '';

            return `
                <tr class="hover:bg-slate-800/40 transition">
                    <td class="p-3">
                        <span class="text-white font-bold block">#${a.id}</span>
                        <span class="text-[11px] text-indigo-400 cursor-pointer hover:underline" onclick="switchAdminTab('orders', { query: '${a.child_order_id}' })">Order #${a.child_order_id}</span>
                    </td>
                    <td class="p-3 font-sans">
                        <span class="text-white block font-mono text-xs">${escapeHtml(a.buyer_phone || 'User #' + a.buyer_id)}</span>
                        <span class="text-[11px] text-slate-500 block truncate max-w-[120px]">${escapeHtml(a.buyer_name || '-')}</span>
                    </td>
                    <td class="p-3">
                        <span class="text-emerald-400 font-bold">₹${Number(a.claimed_amount || 0).toLocaleString()}</span>
                    </td>
                    <td class="p-3">
                        <span class="text-slate-300 text-xs select-all">${escapeHtml(a.normalized_utr || a.claimed_utr || '-')}</span>
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${riskClass}">${escapeHtml(a.risk_level)}</span>
                            <span class="font-bold text-white text-xs">${a.risk_score}<span class="text-slate-500 text-[10px]">/100</span></span>
                        </div>
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${decisionClass}">${escapeHtml(a.decision)}</span>
                    </td>
                    <td class="p-3 font-sans">
                        <div class="flex flex-wrap items-center gap-1">
                            ${reasonBadges || '<span class="text-slate-600 text-[11px]">CLEAN_RECORD</span>'}
                            ${extraCount}
                        </div>
                    </td>
                    <td class="p-3 text-[11px] text-slate-400 font-mono">
                        ${escapeHtml(a.created_at || '-')}
                    </td>
                    <td class="p-3 text-right">
                        <button onclick="inspectFraudAssessment(${a.id})" class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white rounded-lg text-xs font-semibold border border-slate-700 transition flex items-center gap-1.5 ml-auto">
                            <svg class="w-3.5 h-3.5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <span>Inspect</span>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="9" class="p-6 text-center text-rose-400 font-sans">Communication error loading fraud ledger.</td></tr>`;
    }
}

async function inspectFraudAssessment(id) {
    try {
        const res = await fetch(`/api/admin.php?action=fraud_detail&id=${id}`);
        const data = await res.json();
        if (!data.success || !data.assessment) {
            alert(data.error || 'Unable to retrieve fraud assessment forensics.');
            return;
        }

        const a = data.assessment;
        activeInspectAssessment = a;

        // Populate Modal Fields
        document.getElementById('fraudModalTitle').innerText = `Fraud Assessment #${a.id}`;
        document.getElementById('fraudModalOrderId').innerText = `#${a.child_order_id}`;
        document.getElementById('fraudModalBuyerPhone').innerText = a.buyer_phone || `User #${a.buyer_id}`;
        document.getElementById('fraudModalAnalyzedAt').innerText = `${a.created_at} UTC`;

        // Decision Badge
        const badge = document.getElementById('fraudModalDecisionBadge');
        if (badge) {
            badge.innerText = a.decision;
            badge.className = 'px-2 py-0.5 rounded text-xs font-bold font-mono ' + (
                a.decision === 'VERIFIED' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' :
                a.decision === 'PENDING_REVIEW' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' :
                a.decision === 'REJECTED' ? 'bg-rose-500/20 text-rose-400 border border-rose-500/30' :
                'bg-slate-700/50 text-slate-300 border border-slate-600'
            );
        }

        // Risk Score & Bar
        const scoreEl = document.getElementById('fraudModalScore');
        const scoreBar = document.getElementById('fraudModalScoreBar');
        if (scoreEl) scoreEl.innerText = `${a.risk_score} / 100`;
        if (scoreBar) {
            scoreBar.style.width = `${Math.min(100, Math.max(0, a.risk_score))}%`;
            scoreBar.className = 'h-2.5 transition-all duration-500 ' + (
                a.risk_score >= 75 ? 'bg-rose-500' :
                a.risk_score >= 40 ? 'bg-amber-500' :
                'bg-emerald-500'
            );
        }

        // Matrix Comparison
        document.getElementById('fraudModalClaimedAmount').innerText = `₹${Number(a.claimed_amount || 0).toLocaleString()}`;
        document.getElementById('fraudModalOcrAmount').innerText = a.ocr_data_parsed.detected_amount ? `₹${Number(a.ocr_data_parsed.detected_amount).toLocaleString()}` : 'N/A (Unrecognized)';
        document.getElementById('fraudModalClaimedUtr').innerText = a.claimed_utr || '-';
        document.getElementById('fraudModalOcrUtr').innerText = a.ocr_data_parsed.detected_utr || 'N/A (Unrecognized)';
        document.getElementById('fraudModalNormalizedUtr').innerText = a.normalized_utr || '-';
        document.getElementById('fraudModalOcrStatus').innerText = a.ocr_data_parsed.detected_status || 'Uncertain / Standard Verification';

        // Forensic Hashes
        document.getElementById('fraudModalSha256').innerText = a.image_analysis_parsed.sha256 || 'N/A';
        document.getElementById('fraudModalPhash').innerText = a.image_analysis_parsed.phash || 'N/A';

        const tools = a.image_analysis_parsed.editing_tools_detected || [];
        const toolsEl = document.getElementById('fraudModalEditingTools');
        if (toolsEl) {
            if (tools.length > 0) {
                toolsEl.innerText = tools.join(', ');
                toolsEl.className = 'text-rose-400 font-bold';
            } else {
                toolsEl.innerText = 'None Detected (Clean Exif/Header)';
                toolsEl.className = 'text-emerald-400';
            }
        }

        // Reviewer Info
        const reviewerEl = document.getElementById('fraudModalReviewerInfo');
        if (reviewerEl) {
            if (a.review_status && a.review_status !== 'pending') {
                reviewerEl.innerText = `${a.review_status.toUpperCase()} by ${a.reviewer_phone || 'Admin #' + a.reviewed_by_admin_id} on ${a.reviewed_at}`;
            } else {
                reviewerEl.innerText = 'Pending Review';
            }
        }

        // Reason Codes List
        const reasonContainer = document.getElementById('fraudModalReasonList');
        if (reasonContainer) {
            const reasons = a.reason_codes_array || [];
            if (reasons.length === 0) {
                reasonContainer.innerHTML = '<span class="text-xs text-slate-500 font-sans">No anomalous risk triggers detected. Standard transaction verification.</span>';
            } else {
                reasonContainer.innerHTML = reasons.map(r => `
                    <span class="px-2 py-1 rounded bg-slate-900 border border-rose-500/30 text-rose-300 text-xs font-mono">
                        ${escapeHtml(r)}
                    </span>
                `).join('');
            }
        }

        // Reset Notes input
        const notesEl = document.getElementById('fraudReviewNotes');
        if (notesEl) notesEl.value = a.review_notes || '';

        // Open Modal
        const modal = document.getElementById('fraudInspectionModal');
        if (modal) modal.classList.remove('hidden');
    } catch (e) {
        alert('Communication error while loading fraud assessment forensics.');
    }
}

function closeFraudInspectionModal() {
    const modal = document.getElementById('fraudInspectionModal');
    if (modal) modal.classList.add('hidden');
    activeInspectAssessment = null;
}

function inspectModalEvidence() {
    if (!activeInspectAssessment) return;
    const path = activeInspectAssessment.screenshot_path;
    if (!path) {
        alert('No payment proof screenshot attached to this assessment.');
        return;
    }
    openAdminLightbox(path, `Forensic Evidence - Order #${activeInspectAssessment.child_order_id}`);
}

async function submitFraudReview(action) {
    if (!activeInspectAssessment) {
        alert('No active fraud assessment selected.');
        return;
    }

    const notesEl = document.getElementById('fraudReviewNotes');
    const notes = notesEl?.value?.trim() || '';

    if (!notes) {
        alert('Mandatory adjudication notes are required to document the operational rationale for this decision.');
        notesEl?.focus();
        return;
    }

    if (!confirm(`Are you sure you want to execute adjudication '${action.toUpperCase()}' on Assessment #${activeInspectAssessment.id}?`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=fraud_review', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assessment_id: activeInspectAssessment.id,
                action: action,
                notes: notes
            })
        });

        const data = await res.json();
        if (!data.success) {
            alert(data.error || 'Failed to submit fraud review.');
            return;
        }

        alert(data.message || 'Fraud review successfully recorded and audited.');
        closeFraudInspectionModal();
        loadAdminFraudAssessments();
    } catch (e) {
        alert('Communication error submitting fraud review.');
    }
}

// ====================================================================
// TAB 11: AGENT MANAGEMENT SYSTEM CONTROLLER
// ====================================================================

let latestCreatedAgentCreds = null;
let agentSearchTimer = null;
let cachedAdminAgentsList = [];

function debounceAgentSearch() {
    clearTimeout(agentSearchTimer);
    agentSearchTimer = setTimeout(() => {
        loadAdminAgents();
    }, 300);
}

async function loadAdminAgents() {
    const tbody = document.getElementById('adminAgentsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="10" class="p-8 text-center text-slate-500 font-sans">Loading agent accounts...</td></tr>';

    const search = document.getElementById('agentSearchInput')?.value.trim() || '';
    const status = document.getElementById('agentStatusFilter')?.value || 'all';

    const params = new URLSearchParams({
        action: 'list_agents',
        query: search,
        status: status
    });

    try {
        const res = await fetch(`/api/admin.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="10" class="p-8 text-center text-rose-400 font-sans">${data.error || 'Failed to load agents'}</td></tr>`;
            return;
        }

        const agents = Array.isArray(data.agents) ? data.agents : [];
        cachedAdminAgentsList = agents;

        // Update Summary Cards
        let totalActive = 0;
        let totalSuspended = 0;
        let totalVol = 0;
        let totalWeekComm = 0;
        let totalPaid = 0;
        let totalPending = 0;

        agents.forEach(a => {
            if (!a) return;
            if (a.status === 'active') totalActive++;
            if (a.status === 'suspended') totalSuspended++;
            totalVol += Number(a.total_volume || 0);
            totalWeekComm += Number(a.current_week_commission || 0);
            totalPaid += Number(a.total_commission_paid || 0);
            totalPending += Number(a.total_commission_pending || 0);
        });

        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        };

        setTxt('agentTotalCount', agents.length);
        setTxt('agentActiveCount', totalActive);
        setTxt('agentSuspendedCount', totalSuspended);
        setTxt('agentTotalVolume', '₹' + formatCurrency(totalVol));
        setTxt('agentWeekCommission', '₹' + formatCurrency(totalWeekComm));
        setTxt('agentPaidCommission', '₹' + formatCurrency(totalPaid));
        setTxt('agentPendingCommission', '₹' + formatCurrency(totalPending));

        if (agents.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="p-8 text-center text-slate-500 font-sans">No agent accounts found matching criteria. Click "Create Agent" above to add one.</td></tr>';
            return;
        }

        tbody.innerHTML = agents.map(a => `
            <tr class="hover:bg-slate-800/40 transition">
                <td class="p-3.5 font-bold text-white">
                    <span class="text-amber-400">${escapeHtml(a.agent_code || '')}</span>
                    <span class="text-[10px] text-slate-500 block font-sans">ID #${a.id}</span>
                </td>
                <td class="p-3.5 text-slate-200">
                    <div class="font-semibold text-white">${escapeHtml(a.name || '')}</div>
                    <div class="text-[11px] text-slate-400 font-sans">
                        ${a.phone ? escapeHtml(a.phone) : ''}
                        ${a.phone && a.email ? ' &bull; ' : ''}
                        ${a.email ? escapeHtml(a.email) : ''}
                        ${!a.phone && !a.email ? '<span class="text-slate-600">No contact provided</span>' : ''}
                    </div>
                </td>
                <td class="p-3.5 text-slate-300">
                    <span class="px-2 py-0.5 rounded bg-slate-800 text-indigo-300 font-bold border border-slate-700">
                        ${escapeHtml(a.root_referral_code || 'N/A')}
                    </span>
                    <span class="text-[10px] text-slate-500 block font-sans">${escapeHtml(a.root_name || '')}</span>
                </td>
                <td class="p-3.5 text-right font-black text-amber-400">${Number(a.commission_rate || 0).toFixed(2)}%</td>
                <td class="p-3.5 text-center">
                    <span class="px-2 py-0.5 rounded bg-slate-950 text-slate-300 border border-slate-800 text-[10px] font-bold" title="${escapeHtml(a.eligible_levels || '')}">
                        ${(a.eligible_levels || '').split(',').length >= 26 ? 'All Levels (1-26)' : escapeHtml(a.eligible_levels || 'None')}
                    </span>
                </td>
                <td class="p-3.5 text-right text-white font-bold">${Number(a.total_referred_users || 0).toLocaleString()}</td>
                <td class="p-3.5 text-right">
                    <div class="text-white font-semibold">₹${formatCurrency(a.total_volume)}</div>
                    <div class="text-[10px] text-emerald-400 font-bold">+₹${formatCurrency(a.total_commission_earned)} comm</div>
                </td>
                <td class="p-3.5 text-right">
                    <div class="text-amber-400 font-bold">₹${formatCurrency(a.current_week_commission)}</div>
                    <div class="text-[10px] text-slate-500 font-sans">${Number(a.current_week_orders || 0)} orders</div>
                </td>
                <td class="p-3.5 text-center">
                    ${a.status === 'active'
                        ? '<span class="px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-[10px] font-sans font-bold">Active</span>'
                        : '<span class="px-2 py-0.5 rounded bg-rose-500/10 text-rose-400 border border-rose-500/30 text-[10px] font-sans font-bold">Suspended</span>'
                    }
                </td>
                <td class="p-3.5 text-right font-sans">
                    <div class="flex items-center justify-end gap-1.5">
                        <button onclick="impersonateAgent(${a.id}, '${escapeHtml(a.agent_code || '')}')" title="Support Mode (Read-Only Inspection)"
                                class="p-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                        <button onclick="openEditAgentModal(${a.id})" title="Edit Settings & Commission"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                        <button onclick="adminResetAgentPassword(${a.id}, '${escapeHtml(a.agent_code || '')}')" title="Reset Password"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-amber-500/20 text-slate-300 hover:text-amber-400 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        </button>
                        <button onclick="adminResetAgentTotp(${a.id}, '${escapeHtml(a.agent_code || '')}')" title="Reset Google Authenticator"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-indigo-500/20 text-slate-300 hover:text-indigo-400 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                        </button>
                        <button onclick="adminToggleAgentStatus(${a.id}, '${a.status || 'active'}')" title="${a.status === 'active' ? 'Suspend Agent' : 'Activate Agent'}"
                                class="p-1.5 rounded-lg ${a.status === 'active' ? 'bg-rose-500/10 hover:bg-rose-500/20 text-rose-400' : 'bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400'} transition">
                            ${a.status === 'active'
                                ? '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>'
                                : '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
                            }
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('loadAdminAgents error:', e);
        tbody.innerHTML = `<tr><td colspan="10" class="p-8 text-center text-rose-400 font-sans">Server communication error (${escapeHtml(e.message || 'Network/Server Error')}). <button onclick="loadAdminAgents()" class="underline text-amber-400 ml-2">Retry</button></td></tr>`;
    }
}

// -------------------------------------------------------------
// CREATE AGENT WORKFLOW & LEVEL CHECKBOXES
// -------------------------------------------------------------

function renderAgentLevelsGrid(containerId, checkedLevels = null) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const all = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
    const selected = checkedLevels !== null ? checkedLevels : all;

    container.innerHTML = all.map(l => {
        const isChecked = selected.includes(l);
        return `
            <label class="flex items-center gap-1.5 px-2 py-1 rounded bg-slate-900 border ${isChecked ? 'border-amber-500/40 text-amber-300' : 'border-slate-800 text-slate-400'} cursor-pointer hover:border-slate-700 text-xs font-mono select-none">
                <input type="checkbox" name="${containerId}Check" value="${l}" ${isChecked ? 'checked' : ''}
                       class="accent-amber-500 rounded text-xs" onchange="this.parentElement.classList.toggle('border-amber-500/40', this.checked); this.parentElement.classList.toggle('text-amber-300', this.checked);">
                <span>${l}</span>
            </label>
        `;
    }).join('');
}

function selectAllAgentLevels(checkAll) {
    document.querySelectorAll('input[name="agentLevelsGridCheck"]').forEach(cb => {
        cb.checked = checkAll;
        cb.parentElement.classList.toggle('border-amber-500/40', checkAll);
        cb.parentElement.classList.toggle('text-amber-300', checkAll);
    });
}

function selectLevelsPreset(presetStr) {
    const list = presetStr.split(',');
    document.querySelectorAll('input[name="agentLevelsGridCheck"]').forEach(cb => {
        const shouldCheck = list.includes(cb.value);
        cb.checked = shouldCheck;
        cb.parentElement.classList.toggle('border-amber-500/40', shouldCheck);
        cb.parentElement.classList.toggle('text-amber-300', shouldCheck);
    });
}

function openCreateAgentModal() {
    renderAgentLevelsGrid('agentLevelsGrid');
    document.getElementById('createAgentAlert').classList.add('hidden');
    document.getElementById('newAgentCode').value = '';
    document.getElementById('newAgentName').value = '';
    document.getElementById('newAgentPhone').value = '';
    document.getElementById('newAgentEmail').value = '';
    document.getElementById('newAgentRootRef').value = '';
    document.getElementById('newAgentCommissionRate').value = '';
    document.getElementById('newAgentPassword').value = '';

    document.getElementById('createAgentModal').classList.remove('hidden');
    document.getElementById('createAgentModal').classList.add('flex');
}

function closeCreateAgentModal() {
    document.getElementById('createAgentModal').classList.add('hidden');
    document.getElementById('createAgentModal').classList.remove('flex');
}

async function handleCreateAgentSubmit(e) {
    e.preventDefault();
    const alertBox = document.getElementById('createAgentAlert');
    const submitBtn = document.getElementById('btnCreateAgentSubmit');

    alertBox.classList.add('hidden');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Generating TOTP & Provisioning...';

    const selectedLevels = Array.from(document.querySelectorAll('input[name="agentLevelsGridCheck"]:checked')).map(cb => cb.value);
    if (selectedLevels.length === 0) {
        alertBox.className = 'p-3 rounded-xl text-xs bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-center gap-2';
        alertBox.textContent = 'Please select at least one eligible referral level.';
        alertBox.classList.remove('hidden');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Agent & Generate Authenticator';
        return;
    }

    const payload = {
        agent_code: document.getElementById('newAgentCode').value.trim(),
        name: document.getElementById('newAgentName').value.trim(),
        phone: document.getElementById('newAgentPhone').value.trim(),
        email: document.getElementById('newAgentEmail').value.trim(),
        root_user_id: document.getElementById('newAgentRootRef').value.trim(),
        commission_rate: parseFloat(document.getElementById('newAgentCommissionRate').value),
        eligible_levels: selectedLevels.join(','),
        password: document.getElementById('newAgentPassword').value
    };

    try {
        const res = await fetch('/api/admin.php?action=create_agent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            closeCreateAgentModal();
            loadAdminAgents();
            showAgentCredentialsModal(data);
        } else {
            alertBox.className = 'p-3 rounded-xl text-xs bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-center gap-2';
            alertBox.textContent = data.error || 'Failed to create agent.';
            alertBox.classList.remove('hidden');
        }
    } catch (err) {
        console.error('handleCreateAgentSubmit error:', err);
        alertBox.className = 'p-3 rounded-xl text-xs bg-rose-500/10 border border-rose-500/30 text-rose-300 flex items-center gap-2';
        alertBox.textContent = `Server communication error (${err.message || 'Network/Server Error'}).`;
        alertBox.classList.remove('hidden');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Create Agent & Generate Authenticator';
    }
}

// -------------------------------------------------------------
// ONE-TIME CREDENTIAL PRESENTATION & COPY HANDLERS
// -------------------------------------------------------------

function showAgentCredentialsModal(creds) {
    latestCreatedAgentCreds = creds;

    document.getElementById('credAgentCode').textContent = creds.agent_code;
    document.getElementById('credPassword').textContent = creds.plain_password;
    document.getElementById('credLoginUrl').textContent = window.location.origin + creds.login_url;
    document.getElementById('credTotpSecret').textContent = creds.totp_secret;

    const qrContainer = document.getElementById('credQrSvgContainer');
    qrContainer.innerHTML = creds.qr_svg;

    document.getElementById('agentCredentialsModal').classList.remove('hidden');
    document.getElementById('agentCredentialsModal').classList.add('flex');
}

function closeAgentCredentialsModal() {
    document.getElementById('agentCredentialsModal').classList.add('hidden');
    document.getElementById('agentCredentialsModal').classList.remove('flex');
}

function copyCredField(elementId) {
    const text = document.getElementById(elementId)?.textContent?.trim() || '';
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        alert(`Copied to clipboard: ${text}`);
    }).catch(() => {
        prompt('Copy this value:', text);
    });
}

function copyAllAgentDetails() {
    if (!latestCreatedAgentCreds) return;
    const c = latestCreatedAgentCreds;
    const text = 
`=========================================
 JELIPAY AGENT PORTAL ONBOARDING DETAILS
=========================================
Agent User ID   : ${c.agent_code}
Agent Name      : ${c.name}
Assigned Tree   : ${c.root_referral_code} (User ID #${c.root_user_id})
Commission Rate : ${Number(c.commission_rate).toFixed(2)}%
Eligible Levels : ${c.eligible_levels}

Login Credentials:
Portal URL      : ${window.location.origin}/agent/login
Password        : ${c.plain_password}

Google Authenticator 2FA Setup:
TOTP Secret Key : ${c.totp_secret}
Issuer / Name   : JeliPay Agent (${c.agent_code})

* IMPORTANT: Add this TOTP key to your Google Authenticator app immediately. You will be required to enter the 6-digit authenticator code on every sign-in.
=========================================`;

    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btnCopyAllDetails');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<span>✓ All Credentials Copied to Clipboard!</span>';
            setTimeout(() => { btn.innerHTML = orig; }, 2500);
        }
    }).catch(() => {
        prompt('Copy all onboarding details below:', text);
    });
}

// -------------------------------------------------------------
// EDIT AGENT SETTINGS
// -------------------------------------------------------------

function openEditAgentModal(agentId) {
    const agent = cachedAdminAgentsList.find(a => a.id === agentId);
    if (!agent) return;

    document.getElementById('editAgentId').value = agent.id;
    document.getElementById('editAgentTitleCode').textContent = agent.agent_code;
    document.getElementById('editAgentName').value = agent.name;
    document.getElementById('editAgentPhone').value = agent.phone || '';
    document.getElementById('editAgentEmail').value = agent.email || '';
    document.getElementById('editAgentCommissionRate').value = agent.commission_rate;

    const levels = agent.eligible_levels.split(',');
    renderAgentLevelsGrid('editAgentLevelsGrid', levels);

    document.getElementById('editAgentModal').classList.remove('hidden');
    document.getElementById('editAgentModal').classList.add('flex');
}

function closeEditAgentModal() {
    document.getElementById('editAgentModal').classList.add('hidden');
    document.getElementById('editAgentModal').classList.remove('flex');
}

function selectAllEditAgentLevels(checkAll) {
    document.querySelectorAll('input[name="editAgentLevelsGridCheck"]').forEach(cb => {
        cb.checked = checkAll;
        cb.parentElement.classList.toggle('border-amber-500/40', checkAll);
        cb.parentElement.classList.toggle('text-amber-300', checkAll);
    });
}

async function handleEditAgentSubmit(e) {
    e.preventDefault();
    const agentId = parseInt(document.getElementById('editAgentId').value);
    const selectedLevels = Array.from(document.querySelectorAll('input[name="editAgentLevelsGridCheck"]:checked')).map(cb => cb.value);

    const payload = {
        agent_id: agentId,
        name: document.getElementById('editAgentName').value.trim(),
        phone: document.getElementById('editAgentPhone').value.trim(),
        email: document.getElementById('editAgentEmail').value.trim(),
        commission_rate: parseFloat(document.getElementById('editAgentCommissionRate').value),
        eligible_levels: selectedLevels.join(',')
    };

    try {
        const res = await fetch('/api/admin.php?action=update_agent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
            alert('Agent settings updated successfully.');
            closeEditAgentModal();
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to update agent.');
        }
    } catch (err) {
        alert('Server communication error.');
    }
}

// -------------------------------------------------------------
// RESET PASSWORD, RESET 2FA, TOGGLE STATUS
// -------------------------------------------------------------

async function adminResetAgentPassword(agentId, agentCode) {
    if (!confirm(`Are you sure you want to generate a new temporary password for Agent ${agentCode}?`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=reset_agent_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId })
        });
        const data = await res.json();

        if (data.success) {
            prompt(`New Temporary Password for Agent ${agentCode} (Copy and share securely):`, data.new_password);
        } else {
            alert(data.error || 'Failed to reset password.');
        }
    } catch (e) {
        alert('Server communication error.');
    }
}

async function adminResetAgentTotp(agentId, agentCode) {
    if (!confirm(`Are you sure you want to reset Google Authenticator 2FA for Agent ${agentCode}? The previous QR code will be invalidated.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=reset_agent_totp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId })
        });
        const data = await res.json();

        if (data.success) {
            showAgentCredentialsModal({
                agent_code: agentCode,
                plain_password: '[Unchanged / Retained]',
                login_url: '/agent/login',
                totp_secret: data.totp_secret,
                qr_svg: data.qr_svg,
                root_referral_code: '[Retained]',
                root_user_id: agentId,
                commission_rate: 0,
                eligible_levels: 'A-Z',
                name: agentCode
            });
        } else {
            alert(data.error || 'Failed to reset Authenticator.');
        }
    } catch (e) {
        alert('Server communication error.');
    }
}

async function adminToggleAgentStatus(agentId, currentStatus) {
    const action = currentStatus === 'active' ? 'SUSPEND' : 'ACTIVATE';
    if (!confirm(`Are you sure you want to ${action} this Agent account?`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=toggle_agent_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId })
        });
        const data = await res.json();

        if (data.success) {
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to toggle status.');
        }
    } catch (e) {
        alert('Server communication error.');
    }
}

async function impersonateAgent(agentId, agentCode) {
    if (!confirm(`Launch Admin Support Mode for Agent ${agentCode || '#' + agentId}? This will open the Agent's dashboard in a read-only support session.`)) {
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=impersonate_agent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_id: agentId })
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = data.redirect || (data.data && data.data.redirect) || '/agent/dashboard';
        } else {
            alert(data.error || 'Failed to start agent support mode.');
        }
    } catch (e) {
        alert('Server communication error launching agent support mode.');
    }
}
window.impersonateAgent = impersonateAgent;

// -------------------------------------------------------------
// WEEKLY PAYOUTS MANAGEMENT CONSOLE
// -------------------------------------------------------------

function openWeeklyPayoutsModal() {
    document.getElementById('adminWeeklyPayoutsModal').classList.remove('hidden');
    document.getElementById('adminWeeklyPayoutsModal').classList.add('flex');
    loadAdminWeeklyStatements();
}

function closeWeeklyPayoutsModal() {
    document.getElementById('adminWeeklyPayoutsModal').classList.add('hidden');
    document.getElementById('adminWeeklyPayoutsModal').classList.remove('flex');
}

async function loadAdminWeeklyStatements() {
    const tbody = document.getElementById('adminStatementsTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="11" class="p-8 text-center text-slate-500 font-sans">Loading statements...</td></tr>';

    const status = document.getElementById('payoutStatusFilter')?.value || 'all';
    const params = new URLSearchParams({
        action: 'list_agent_statements',
        status: status
    });

    try {
        const res = await fetch(`/api/admin.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="11" class="p-8 text-center text-rose-400 font-sans">${data.error || 'Failed to load statements'}</td></tr>`;
            return;
        }

        const statements = data.statements || [];

        if (statements.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="p-8 text-center text-slate-500 font-sans">No statements found. Click "Compile / Sync Statements" to compile the latest weekly records.</td></tr>';
            return;
        }

        tbody.innerHTML = statements.map(s => {
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
                <tr class="hover:bg-slate-900/60 transition">
                    <td class="p-3 font-bold text-white">${escapeHtml(s.statement_code || '')}</td>
                    <td class="p-3 text-slate-200">
                        <span class="text-amber-400 font-bold">${escapeHtml(s.agent_code || '')}</span>
                        <span class="text-[10px] text-slate-500 block font-sans">${escapeHtml(s.agent_name || '')}</span>
                    </td>
                    <td class="p-3 text-slate-300">
                        <div>${startFmt} - ${endFmt}</div>
                        <div class="text-[10px] text-slate-500 font-sans">Week ${s.week_number}, ${s.week_year}</div>
                    </td>
                    <td class="p-3 text-right text-slate-300">${s.total_orders || 0}</td>
                    <td class="p-3 text-right text-slate-300">₹${formatCurrency(s.total_volume)}</td>
                    <td class="p-3 text-right text-slate-300">₹${formatCurrency(s.gross_commission)}</td>
                    <td class="p-3 text-right ${Number(s.adjustment_amount || 0) !== 0 ? 'text-amber-400 font-bold' : 'text-slate-500'}"
                        title="${escapeHtml(s.adjustment_reason || '')}">
                        ${Number(s.adjustment_amount || 0) > 0 ? '+' : ''}₹${formatCurrency(s.adjustment_amount)}
                        ${s.payout_status !== 'paid' ? `
                            <button onclick="openStatementAdjustmentModal(${s.id}, '${escapeHtml(s.statement_code || '')}', ${Number(s.gross_commission || 0)})" class="text-[10px] text-amber-400 hover:underline block font-sans">
                                Adjust
                            </button>
                        ` : ''}
                    </td>
                    <td class="p-3 text-right font-black text-amber-400 text-sm">₹${formatCurrency(s.net_commission)}</td>
                    <td class="p-3 text-center">${statusBadge}</td>
                    <td class="p-3 text-slate-300 text-[11px]">
                        ${s.payout_reference ? `<div class="font-bold text-slate-200">Ref: ${escapeHtml(s.payout_reference)}</div>` : '<div class="text-slate-600">—</div>'}
                        ${s.paid_at ? `<div class="text-[10px] text-slate-500 font-sans">${escapeHtml(s.paid_at)}</div>` : ''}
                    </td>
                    <td class="p-3 text-right font-sans">
                        <div class="flex items-center justify-end gap-1.5">
                            ${s.payout_status === 'pending_review' ? `
                                <button onclick="adminApproveStatement(${s.id}, '${escapeHtml(s.statement_code || '')}')"
                                        class="px-2.5 py-1 bg-sky-600 hover:bg-sky-500 text-white font-bold rounded-lg text-[11px] transition shadow">
                                    Approve
                                </button>
                            ` : ''}
                            ${s.payout_status === 'approved' ? `
                                <button onclick="openRecordPayoutModal(${s.id}, '${escapeHtml(s.statement_code || '')}', ${Number(s.net_commission || 0)})"
                                        class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-lg text-[11px] transition shadow">
                                    Record Payout
                                </button>
                            ` : ''}
                            ${s.payout_status === 'paid' ? `
                                <span class="text-emerald-400 text-xs flex items-center gap-1 font-bold">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Settled
                                </span>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('loadAdminWeeklyStatements error:', e);
        tbody.innerHTML = `<tr><td colspan="11" class="p-8 text-center text-rose-400 font-sans">Server communication error (${escapeHtml(e.message || 'Network/Server Error')}).</td></tr>`;
    }
}

async function triggerGenerateStatements() {
    try {
        const res = await fetch('/api/admin.php?action=generate_agent_statements', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await res.json();
        if (data.success) {
            alert(`Generated/Updated statements for ${data.generated_count} agents (${data.week_range}).`);
            loadAdminWeeklyStatements();
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to generate statements.');
        }
    } catch (e) {
        alert('Server communication error.');
    }
}

// Statement Adjustment
function openStatementAdjustmentModal(stmtId, code, gross) {
    document.getElementById('adjStatementId').value = stmtId;
    document.getElementById('adjStatementCode').textContent = code;
    document.getElementById('adjAmount').value = '';
    document.getElementById('adjReason').value = '';

    document.getElementById('statementAdjustmentModal').classList.remove('hidden');
    document.getElementById('statementAdjustmentModal').classList.add('flex');
}

function closeStatementAdjustmentModal() {
    document.getElementById('statementAdjustmentModal').classList.add('hidden');
    document.getElementById('statementAdjustmentModal').classList.remove('flex');
}

async function submitStatementAdjustment(e) {
    e.preventDefault();
    const statementId = parseInt(document.getElementById('adjStatementId').value);
    const amount = parseFloat(document.getElementById('adjAmount').value);
    const reason = document.getElementById('adjReason').value.trim();

    if (isNaN(amount)) {
        alert('Please enter a valid numeric adjustment amount.');
        return;
    }
    if (!reason) {
        alert('Mandatory reason must be provided.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=adjust_agent_statement', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                statement_id: statementId,
                adjustment_amount: amount,
                reason: reason
            })
        });
        const data = await res.json();

        if (data.success) {
            closeStatementAdjustmentModal();
            loadAdminWeeklyStatements();
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to save adjustment.');
        }
    } catch (err) {
        alert('Server communication error.');
    }
}

// Statement Approval
async function adminApproveStatement(stmtId, code) {
    if (!confirm(`Approve statement ${code} for commission payout?`)) return;

    try {
        const res = await fetch('/api/admin.php?action=approve_agent_statement', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ statement_id: stmtId })
        });
        const data = await res.json();

        if (data.success) {
            loadAdminWeeklyStatements();
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to approve statement.');
        }
    } catch (e) {
        alert('Server communication error.');
    }
}

// Statement Record Payout
function openRecordPayoutModal(stmtId, code, net) {
    document.getElementById('payoutModalStatementId').value = stmtId;
    document.getElementById('payoutModalNetComm').textContent = '₹' + formatCurrency(net);
    document.getElementById('payoutRef').value = '';
    document.getElementById('payoutNotes').value = '';

    // Set default date to current local datetime
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('payoutDate').value = now.toISOString().slice(0, 16);

    document.getElementById('recordPayoutModal').classList.remove('hidden');
    document.getElementById('recordPayoutModal').classList.add('flex');
}

function closeRecordPayoutModal() {
    document.getElementById('recordPayoutModal').classList.add('hidden');
    document.getElementById('recordPayoutModal').classList.remove('flex');
}

async function submitRecordPayout(e) {
    e.preventDefault();
    const statementId = parseInt(document.getElementById('payoutModalStatementId').value);
    const payoutRef = document.getElementById('payoutRef').value.trim();
    const payoutDate = document.getElementById('payoutDate').value;
    const notes = document.getElementById('payoutNotes').value.trim();

    if (!payoutRef) {
        alert('Payment Reference (UTR) is required.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=payout_agent_statement', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                statement_id: statementId,
                payout_reference: payoutRef,
                paid_date: payoutDate,
                notes: notes
            })
        });
        const data = await res.json();

        if (data.success) {
            alert(data.message || 'Payout recorded successfully.');
            closeRecordPayoutModal();
            loadAdminWeeklyStatements();
            loadAdminAgents();
        } else {
            alert(data.error || 'Failed to record payout.');
        }
    } catch (err) {
        alert('Server communication error.');
    }
}





// -------------------------------------------------------------
// STATUS CONTROLS & RISK MANAGEMENT HANDLERS (Requirement 1.3 & 7.5)
// -------------------------------------------------------------

function openSetUserStatusModal(userId, phone, currentStatus) {
    document.getElementById('statusModalUserId').value = userId;
    document.getElementById('statusModalUserLabel').innerText = `User #${userId} (${phone})`;
    document.getElementById('statusModalNewStatus').value = currentStatus || 'active';
    document.getElementById('statusModalReason').value = '';
    document.getElementById('setUserStatusModal').classList.remove('hidden');
}

function closeSetUserStatusModal() {
    document.getElementById('setUserStatusModal').classList.add('hidden');
}

async function submitSetUserStatus(event) {
    event.preventDefault();
    const userId = parseInt(document.getElementById('statusModalUserId').value, 10);
    const newStatus = document.getElementById('statusModalNewStatus').value;
    const reason = document.getElementById('statusModalReason').value.trim();

    if (!reason) {
        alert('A mandatory operational reason is required for status changes.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=set_user_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, status: newStatus, reason: reason })
        });
        const data = await res.json();
        if (data.success) {
            closeSetUserStatusModal();
            alert(`User #${userId} status updated to ${newStatus}.`);
            loadAdminUsers();
            if (currentAdminTab === 'risk') loadAdminRiskUsers();
        } else {
            alert(data.error || 'Failed to update account status.');
        }
    } catch (e) {
        alert('Network error while updating status.');
    }
}

// Sell Controls Modal
function openSellRestrictionsModal(userId, phone, isRestricted, orderLimit, cooldown) {
    document.getElementById('sellRestrModalUserId').value = userId;
    document.getElementById('sellRestrModalUserLabel').innerText = `User #${userId} (${phone})`;
    document.getElementById('sellRestrBlockedCheck').checked = !!parseInt(isRestricted, 10);
    document.getElementById('sellRestrLimitInput').value = orderLimit ? parseFloat(orderLimit) : '';
    document.getElementById('sellRestrCooldownInput').value = cooldown ? parseInt(cooldown, 10) : '';
    document.getElementById('sellRestrReasonInput').value = '';
    document.getElementById('sellRestrictionsModal').classList.remove('hidden');
}

function closeSellRestrictionsModal() {
    document.getElementById('sellRestrictionsModal').classList.add('hidden');
}

async function submitSellRestrictions(event) {
    event.preventDefault();
    const userId = parseInt(document.getElementById('sellRestrModalUserId').value, 10);
    const isRestricted = document.getElementById('sellRestrBlockedCheck').checked;
    const orderLimit = document.getElementById('sellRestrLimitInput').value.trim();
    const cooldown = document.getElementById('sellRestrCooldownInput').value.trim();
    const reason = document.getElementById('sellRestrReasonInput').value.trim();

    if (!reason) {
        alert('A mandatory reason is required for sell control changes.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_sell_restrictions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                user_id: userId,
                sell_restricted: isRestricted,
                order_limit: orderLimit ? parseFloat(orderLimit) : null,
                cooldown_seconds: cooldown ? parseInt(cooldown, 10) : 0,
                reason: reason
            })
        });
        const data = await res.json();
        if (data.success) {
            closeSellRestrictionsModal();
            alert(`Sell controls configured for User #${userId}.`);
            if (currentAdminTab === 'risk') loadAdminRiskUsers();
            loadAdminUsers();
        } else {
            alert(data.error || 'Failed to configure sell controls.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

// Risk Review Modal
function openRiskReviewModal(userId, phone, currentReviewStatus) {
    document.getElementById('riskNoteModalUserId').value = userId;
    document.getElementById('riskNoteModalUserLabel').innerText = `User #${userId} (${phone})`;
    document.getElementById('riskNoteReviewStatus').value = currentReviewStatus || 'reviewed';
    document.getElementById('riskNoteContent').value = '';
    document.getElementById('riskReviewModal').classList.remove('hidden');
}

function closeRiskReviewModal() {
    document.getElementById('riskReviewModal').classList.add('hidden');
}

async function submitRiskReview(event) {
    event.preventDefault();
    const userId = parseInt(document.getElementById('riskNoteModalUserId').value, 10);
    const reviewStatus = document.getElementById('riskNoteReviewStatus').value;
    const note = document.getElementById('riskNoteContent').value.trim();

    if (!note) {
        alert('Review note content is required.');
        return;
    }

    try {
        const res = await fetch('/api/admin.php?action=admin_risk_note', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, review_status: reviewStatus, note: note })
        });
        const data = await res.json();
        if (data.success) {
            closeRiskReviewModal();
            alert(`Review note recorded for User #${userId}.`);
            if (currentAdminTab === 'risk') loadAdminRiskUsers();
        } else {
            alert(data.error || 'Failed to record review note.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

// Health Score History Modal
async function openHealthScoreHistoryModal(userId, phone) {
    document.getElementById('histModalUserLabel').innerText = `User #${userId} (${phone})`;
    const tbody = document.getElementById('histModalTbody');
    tbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-slate-500">Loading score audit history...</td></tr>`;
    document.getElementById('healthScoreHistoryModal').classList.remove('hidden');

    try {
        const res = await fetch(`/api/admin.php?action=user_health_history&user_id=${userId}`);
        const data = await res.json();
        if (!data.success || !data.history || data.history.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-slate-500">No score adjustment records on file (Score initialized at 100).</td></tr>`;
            return;
        }

        tbody.innerHTML = data.history.map(h => {
            const deltaColor = h.delta > 0 ? 'text-emerald-400' : (h.delta < 0 ? 'text-rose-400' : 'text-slate-400');
            const deltaSign = h.delta > 0 ? `+${h.delta}` : h.delta;
            return `
                <tr class="hover:bg-slate-900/60 transition">
                    <td class="p-2 text-slate-400">${h.created_at}</td>
                    <td class="p-2 font-bold ${deltaColor}">${deltaSign}</td>
                    <td class="p-2 text-slate-300">${h.old_score} &rarr; <strong class="text-white">${h.new_score}</strong></td>
                    <td class="p-2 text-slate-300 font-sans">
                        <span class="block">${escapeHtml(h.reason)}</span>
                        <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(h.event_type || 'SYSTEM')}${h.admin_phone ? ' by ' + escapeHtml(h.admin_phone) : ''}</span>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-rose-400">Failed to load history.</td></tr>`;
    }
}

function closeHealthScoreHistoryModal() {
    document.getElementById('healthScoreHistoryModal').classList.add('hidden');
}

// -------------------------------------------------------------
// TAB: RISK MANAGEMENT & LOW HEALTH SCORE USERS (Requirement 7.3)
// -------------------------------------------------------------

let riskPageLimit = 20;
let riskPageOffset = 0;

function changeRiskPageSize(size) {
    riskPageLimit = parseInt(size, 10) || 20;
    riskPageOffset = 0;
    loadAdminRiskUsers();
}

function changeRiskPage(direction) {
    if (direction === 'prev' && riskPageOffset > 0) {
        riskPageOffset = Math.max(0, riskPageOffset - riskPageLimit);
    } else if (direction === 'next') {
        riskPageOffset += riskPageLimit;
    }
    loadAdminRiskUsers();
}

async function loadAdminRiskUsers() {
    const range = document.getElementById('riskRangeFilter')?.value || 'below_50';
    const status = document.getElementById('riskStatusFilter')?.value || 'all';
    const reviewStatus = document.getElementById('riskReviewFilter')?.value || 'all';
    const query = document.getElementById('riskSearchInput')?.value || '';

    try {
        const url = `/api/admin.php?action=low_health_users&range=${encodeURIComponent(range)}&status=${encodeURIComponent(status)}&review_status=${encodeURIComponent(reviewStatus)}&query=${encodeURIComponent(query)}&limit=${riskPageLimit}&offset=${riskPageOffset}`;
        const res = await fetch(url);
        const data = await res.json();
        const tbody = document.getElementById('adminRiskTableBody');
        if (!tbody) return;

        const total = data.total_count || 0;
        const from = total > 0 ? (riskPageOffset + 1) : 0;
        const to = Math.min(total, riskPageOffset + riskPageLimit);
        const infoEl = document.getElementById('riskPaginationInfo');
        if (infoEl) infoEl.innerText = `Showing ${from} to ${to} of ${total} matching users`;

        const prevBtn = document.getElementById('riskPrevBtn');
        const nextBtn = document.getElementById('riskNextBtn');
        const indEl = document.getElementById('riskPageIndicator');
        if (prevBtn) prevBtn.disabled = (riskPageOffset === 0);
        if (nextBtn) nextBtn.disabled = (riskPageOffset + riskPageLimit >= total);
        if (indEl) indEl.innerText = `Page ${Math.floor(riskPageOffset / riskPageLimit) + 1} of ${Math.max(1, Math.ceil(total / riskPageLimit))}`;

        let critCount = 0;
        let pendCount = 0;
        let blockCount = 0;

        (data.users || []).forEach(u => {
            if (parseInt(u.health_score, 10) <= 50) critCount++;
            if (u.risk_review_status === 'pending_review') pendCount++;
            if (u.account_status === 'temporarily_blocked') blockCount++;
        });

        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.innerText = v; };
        setVal('riskCriticalCount', critCount);
        setVal('riskPendingCount', pendCount);
        setVal('riskBlockedCount', blockCount);

        if (!data.success || !data.users || data.users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-500 font-sans">No matching low health score accounts found in this filter.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.users.map(u => {
            const hScore = parseInt(u.health_score, 10);
            const prevScore = (u.previous_health_score !== null && u.previous_health_score !== undefined) ? u.previous_health_score : '-';
            const hColor = hScore <= 50 ? 'bg-rose-500/10 text-rose-400 border border-rose-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
            const stColor = (u.account_status === 'active') ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : ((u.account_status === 'temporarily_blocked') ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20');
            const revColor = u.risk_review_status === 'reviewed' ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20' : 'text-amber-400 bg-amber-500/10 border-amber-500/20';

            return `
                <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                    <td class="p-3">
                        <span class="text-white font-bold block">User #${u.id}</span>
                        <span class="text-slate-400 font-sans block">${escapeHtml(u.phone)}</span>
                        <span class="text-[10px] text-slate-500 font-sans block">${escapeHtml(u.account_holder_name || 'No name')}</span>
                    </td>
                    <td class="p-3">
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-1 rounded-xl text-xs font-bold ${hColor}">
                                ${hScore} / 100
                            </span>
                            <span class="text-[10px] text-slate-500 block">Prev: ${prevScore}</span>
                        </div>
                    </td>
                    <td class="p-3 font-sans">
                        <span class="text-slate-300 block text-[11px]">${escapeHtml(u.last_score_reason || 'No recent adjustment')}</span>
                        <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(u.last_score_date || u.health_score_updated_at || '-')}</span>
                    </td>
                    <td class="p-3">
                        <span class="text-emerald-400 font-bold block">Done: ${u.completed_order_count || 0}</span>
                        <span class="text-rose-400 block">Cancelled: ${u.cancelled_order_count || 0}</span>
                        <span class="text-amber-400 block font-bold">Open Disputes: ${u.open_dispute_count || 0}</span>
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${stColor}">
                            ${escapeHtml(u.account_status)}
                        </span>
                        <div class="mt-1 space-y-0.5 text-[10px]">
                            ${u.sell_restricted ? '<span class="text-rose-400 font-bold block uppercase">Sell Restricted</span>' : '<span class="text-emerald-400 block">Sell Enabled</span>'}
                            ${u.sell_order_limit ? `<span class="text-slate-400 block">Limit: ₹${parseFloat(u.sell_order_limit).toFixed(0)}</span>` : ''}
                            ${u.sell_cooldown_seconds ? `<span class="text-slate-400 block">Cooldown: ${u.sell_cooldown_seconds}s</span>` : ''}
                        </div>
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border ${revColor}">
                            ${escapeHtml(u.risk_review_status || 'none')}
                        </span>
                        ${u.risk_notes ? `<span class="text-[10px] text-slate-400 font-sans block mt-1 line-clamp-2" title="${escapeHtml(u.risk_notes)}">${escapeHtml(u.risk_notes)}</span>` : ''}
                    </td>
                    <td class="p-3 font-sans">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <button onclick="openRiskReviewModal(${u.id}, '${escapeHtml(u.phone)}', '${u.risk_review_status}')" class="px-2 py-1 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-400 border border-indigo-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Record Review Findings">
                                Review Note
                            </button>
                            <button onclick="openSellRestrictionsModal(${u.id}, '${escapeHtml(u.phone)}', ${u.sell_restricted || 0}, ${u.sell_order_limit || 0}, ${u.sell_cooldown_seconds || 0})" class="px-2 py-1 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded text-[11px] font-semibold transition cursor-pointer" title="Configure Sell Limit & Cooldown">
                                Sell Controls
                            </button>
                            <button onclick="openSetUserStatusModal(${u.id}, '${escapeHtml(u.phone)}', '${u.account_status}')" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded text-[11px] font-semibold transition cursor-pointer" title="Change Account Status">
                                Status
                            </button>
                            <button onclick="openHealthScoreHistoryModal(${u.id}, '${escapeHtml(u.phone)}')" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded text-[11px] transition cursor-pointer" title="View Full Audit Log">
                                History
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (e) {
        console.error('loadAdminRiskUsers error:', e);
    }
}
