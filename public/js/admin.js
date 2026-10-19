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
        const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'rewards', 'content', 'reports', 'audit', 'admins'];
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
    const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'rewards', 'content', 'reports', 'audit', 'admins'];

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

function switchAdminTab(tabId, filterOptions = null) {
    const validTabs = ['dashboard', 'users', 'orders', 'disputes', 'rewards', 'content', 'reports', 'audit', 'admins'];
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
    }

    // Trigger data loader
    if (tabId === 'dashboard') loadAdminDashboard();
    if (tabId === 'users') loadAdminUsers();
    if (tabId === 'orders') loadAdminOrders();
    if (tabId === 'disputes') loadAdminDisputes();
    if (tabId === 'rewards') loadAdminRewardSettings();
    if (tabId === 'content') loadAdminContentSettings();
    if (tabId === 'reports') loadAdminReports();
    if (tabId === 'audit') loadAdminAuditLogs();
    if (tabId === 'admins') loadAdminDirectory();
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
        const otBtn = document.getElementById('dashOrdersTotal');
        if (otBtn) {
            const span = otBtn.querySelector('span');
            if (span) span.innerText = `${orders.total ?? 0} Total Orders`;
            else otBtn.innerText = `${orders.total ?? 0} Total Orders`;
        }
        setTxt('dashOrdersAvailable', orders.available ?? 0);
        setTxt('dashOrdersReserved', orders.reserved ?? 0);
        setTxt('dashOrdersPendingConfirm', orders.pending_confirmation ?? 0);
        setTxt('dashOrdersDisputed', orders.disputed ?? 0);
        setTxt('dashOrdersCompleted', orders.completed ?? 0);
        setTxt('dashOrdersCancelled', orders.cancelled ?? 0);

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

async function loadAdminUsers() {
    const query = document.getElementById('userSearchInput')?.value || '';
    const status = document.getElementById('userStatusFilter')?.value || 'all';
    const role = document.getElementById('userRoleFilter')?.value || 'all';

    try {
        const res = await fetch(`/api/admin.php?action=users&query=${encodeURIComponent(query)}&status=${encodeURIComponent(status)}&role=${encodeURIComponent(role)}`);
        const data = await res.json();
        const tbody = document.getElementById('adminUsersTableBody');
        if (!tbody) return;

        if (!data.success || !data.users || data.users.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-500 font-sans">No matching user accounts found.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.users.map(u => `
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
                    <span class="text-indigo-400 font-mono font-bold">${escapeHtml(u.referral_code)}</span>
                    <span class="text-[10px] text-slate-500 block">Role: ${u.role}${u.admin_role ? ' (' + u.admin_role + ')' : ''}</span>
                </td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${u.account_status === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20'}">
                        ${u.account_status}
                    </span>
                </td>
                <td class="p-3 font-sans">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <button onclick="viewUserDossier(${u.id})" class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded text-[11px] font-semibold transition" title="Inspect Full Dossier">
                            Dossier
                        </button>
                        <button onclick="openAdjustmentModal(${u.id}, '${escapeHtml(u.phone)}')" class="px-2 py-1 bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-400 border border-indigo-500/30 rounded text-[11px] font-semibold transition" title="Adjust Token Balance">
                            Adjust
                        </button>
                        <button onclick="toggleUserStatus(${u.id}, '${u.account_status}')" class="px-2 py-1 ${u.account_status === 'active' ? 'bg-red-500/20 text-red-400 hover:bg-red-500/30' : 'bg-emerald-500/20 text-emerald-400 hover:bg-emerald-500/30'} rounded text-[11px] font-semibold transition">
                            ${u.account_status === 'active' ? 'Suspend' : 'Activate'}
                        </button>
                        <button onclick="impersonateUser(${u.id})" class="px-2 py-1 bg-amber-500/20 hover:bg-amber-500/30 text-amber-400 border border-amber-500/30 rounded text-[11px] font-semibold transition" title="Read-Only Support Mode">
                            Support
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
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
// TAB 3: ORDER MANAGEMENT (Requirement 3)
// -------------------------------------------------------------

async function loadAdminOrders() {
    const status = document.getElementById('orderStatusFilter')?.value || 'all';
    const type = document.getElementById('orderTypeFilter')?.value || 'child';
    const query = document.getElementById('orderSearchInput')?.value || '';

    try {
        const res = await fetch(`/api/admin.php?action=orders&status=${encodeURIComponent(status)}&type=${encodeURIComponent(type)}&query=${encodeURIComponent(query)}`);
        const data = await res.json();
        const tbody = document.getElementById('adminOrdersTableBody');
        if (!tbody) return;

        if (!data.success || !data.orders || data.orders.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-8 text-center text-slate-500 font-sans">No matching orders found.</td></tr>`;
            return;
        }

        tbody.innerHTML = data.orders.map(o => {
            const isChild = (type === 'child');
            return `
                <tr class="hover:bg-slate-900/80 transition text-xs font-mono">
                    <td class="p-3 text-slate-400 font-bold">
                        #${o.id}
                        ${isChild ? `<span class="text-[10px] text-slate-500 block font-normal">Parent #${o.parent_order_id}</span>` : ''}
                    </td>
                    <td class="p-3">
                        <span class="text-white font-bold block">${escapeHtml(o.seller_phone || 'Seller')}</span>
                        <span class="text-[10px] text-slate-500 font-sans block">${escapeHtml(o.seller_name || '')}</span>
                    </td>
                    <td class="p-3">
                        ${isChild ? (o.buyer_phone ? `
                            <span class="text-white font-bold block">${escapeHtml(o.buyer_phone)}</span>
                            <span class="text-[10px] text-slate-500 font-sans block">${escapeHtml(o.buyer_name || '')}</span>
                        ` : '<span class="text-slate-600 italic">Unreserved</span>') : `
                            <span class="text-slate-400">${o.child_count} micro-orders</span>
                        `}
                    </td>
                    <td class="p-3">
                        <span class="text-emerald-400 font-bold text-sm">₹${parseFloat(isChild ? o.amount : o.original_amount).toLocaleString('en-IN')}</span>
                        ${isChild ? `<span class="text-[10px] text-indigo-400 block">+${o.snapshotted_reward_rate}% Rew</span>` : `
                            <span class="text-[10px] text-slate-500 block">Rem: ₹${parseFloat(o.remaining_amount).toLocaleString('en-IN')}</span>
                        `}
                    </td>
                    <td class="p-3">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${o.status === 'Completed' ? 'bg-emerald-500/10 text-emerald-400' : (o.status === 'Disputed' ? 'bg-red-500/20 text-red-400' : 'bg-slate-800 text-slate-300')}">
                            ${o.status}
                        </span>
                    </td>
                    <td class="p-3">
                        ${isChild && o.utr_number ? `
                            <span class="text-white font-bold block">${escapeHtml(o.utr_number)}</span>
                            ${o.screenshot_path ? `<button type="button" onclick="openEvidenceLightbox('${escapeHtml(o.screenshot_path)}', 'Buyer Payment Proof - Order #${o.id}')" class="text-[10px] text-amber-400 hover:text-amber-300 font-bold underline font-sans block mt-0.5 cursor-pointer">Inspect Proof</button>` : ''}
                        ` : '<span class="text-slate-600">-</span>'}
                    </td>
                    <td class="p-3 text-[11px] text-slate-500">
                        ${o.created_at}
                    </td>
                    <td class="p-3 font-sans">
                        <div class="flex items-center gap-1.5">
                            ${isChild && !['Completed', 'Cancelled / Reversed'].includes(o.status) ? `
                                <button onclick="cancelAdminOrder(${o.id})" class="px-2 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/30 rounded text-[11px] font-semibold transition" title="Cancel & Release Tokens to Seller">
                                    Cancel & Return
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
// TAB 4: DISPUTE ARBITRATION (Requirement 4)
// -------------------------------------------------------------

async function loadAdminDisputes() {
    const status = document.getElementById('disputeStatusFilter')?.value || 'all';
    const query = document.getElementById('disputeSearchInput')?.value || '';

    try {
        const res = await fetch(`/api/admin.php?action=disputes&status=${encodeURIComponent(status)}&query=${encodeURIComponent(query)}`);
        const data = await res.json();
        const list = document.getElementById('adminDisputesContainer');
        if (!list) return;

        if (!data.success || !data.disputes || data.disputes.length === 0) {
            list.innerHTML = `<div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-500 font-sans">No matching disputes found.</div>`;
            return;
        }

        list.innerHTML = data.disputes.map(d => `
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div>
                        <span class="text-sm font-mono font-bold text-amber-400">Dispute #${d.id} &bull; Order #${d.child_order_id}</span>
                        <span class="ml-2 px-2.5 py-0.5 rounded text-xs uppercase font-bold ${d.status === 'resolved_buyer' ? 'bg-emerald-500/10 text-emerald-400' : (d.status === 'resolved_seller' ? 'bg-indigo-500/10 text-indigo-400' : 'bg-red-500/20 text-red-400')}">
                            ${d.status}
                        </span>
                        ${d.additional_evidence_requested ? '<span class="ml-2 text-[10px] px-2 py-0.5 rounded bg-blue-500/20 text-blue-400 font-bold uppercase">Evidence Requested</span>' : ''}
                    </div>
                    <div class="text-right">
                        <span class="text-xl font-black text-white font-mono">₹${parseFloat(d.amount).toLocaleString('en-IN')}</span>
                        <span class="text-xs text-slate-500 block">Base Order Amount</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <!-- Seller Statement -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-red-400 font-bold uppercase tracking-wider block">Seller Appeal Notice</span>
                            <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(d.seller_phone || '')}</span>
                        </div>
                        <p class="text-slate-300 italic">"${escapeHtml(d.reason)}"</p>
                        <div class="text-[11px] text-slate-400">
                            Seller: <strong class="text-white">${escapeHtml(d.seller_name || 'Seller')}</strong>
                        </div>
                        ${d.evidence_screenshot_path ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.evidence_screenshot_path)}', 'Seller Evidence Document - Dispute #${d.id}')" class="inline-flex items-center gap-1 text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold mt-1 cursor-pointer">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Seller Evidence Document</span>
                            </button>
                        ` : '<span class="text-[11px] text-slate-600 block">No seller file attached</span>'}
                    </div>

                    <!-- Buyer Payment Proof -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-emerald-400 font-bold uppercase tracking-wider block">Buyer Payment Submission</span>
                            <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(d.buyer_phone || '')}</span>
                        </div>
                        <div class="text-slate-300 font-mono">UTR: <strong class="text-white">${escapeHtml(d.utr_number || 'N/A')}</strong></div>
                        <div class="text-[11px] text-slate-400">
                            Buyer: <strong class="text-white">${escapeHtml(d.buyer_name || 'Buyer')}</strong>
                        </div>
                        ${d.buyer_screenshot ? `
                            <button type="button" onclick="openEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Screenshot - Dispute #${d.id} (Order #${d.child_order_id})')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold transition shadow-sm cursor-pointer mt-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Buyer Payment Screenshot</span>
                            </button>
                        ` : '<span class="text-[11px] text-slate-600 block">No buyer screenshot</span>'}
                    </div>
                </div>

                <!-- Refund Tracking Chip -->
                <div class="bg-slate-950/60 p-3 rounded-xl border border-slate-800 flex items-center justify-between text-xs font-mono">
                    <div>
                        <span class="text-slate-500">Refund Status:</span>
                        <span class="text-amber-400 font-bold ml-1">${escapeHtml(d.refund_status.toUpperCase())}</span>
                        ${d.refund_reference ? `<span class="text-slate-400 ml-2">Ref: ${escapeHtml(d.refund_reference)}</span>` : ''}
                    </div>
                    <button onclick="promptRefundStatus(${d.id})" class="text-[10px] text-indigo-400 hover:text-indigo-300 underline font-sans">
                        Update Refund Tracking
                    </button>
                </div>

                ${d.status === 'pending' || d.status === 'under_review' ? `
                    <div class="space-y-2 pt-2 border-t border-slate-800">
                        <input type="text" id="dispNotes_${d.id}" placeholder="Enter mandatory resolution rationale for immutable audit trail..."
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <div class="flex items-center gap-2 justify-end">
                            <button onclick="requestMoreEvidence(${d.id})" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition">
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
                ` : `
                    <div class="text-xs text-slate-400 bg-slate-950 p-3 rounded-xl font-mono border border-slate-800/60">
                        Resolved: <span class="text-slate-200">${escapeHtml(d.resolution_notes || 'Resolved by arbitration')}</span>
                    </div>
                `}
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load disputes:', e);
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

