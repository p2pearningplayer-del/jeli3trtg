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
    initAdminSSE();
    setInterval(periodicAdminSync, 10000); // Periodic general metrics sync
    setInterval(syncAdminLiveSupport, 1500); // Ultra-fast 1.5s live support sync
});

async function initAdmin() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    switchAdminTab(tabParam || 'dashboard');
    syncAdminLiveSupport();
}

function switchAdminTab(tabId) {
    currentAdminTab = tabId;

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

    // Trigger data loader
    if (tabId === 'dashboard') loadAdminDashboard();
    if (tabId === 'users') loadAdminUsers();
    if (tabId === 'orders') loadAdminOrders();
    if (tabId === 'disputes') loadAdminDisputes();
    if (tabId === 'rewards') loadAdminRewardSettings();
    if (tabId === 'content') loadAdminContentSettings();
    if (tabId === 'reports') loadAdminReports();
    if (tabId === 'audit') loadAdminAuditLogs();
    if (tabId === 'support') loadAdminSupportChats();
}

function periodicAdminSync() {
    if (currentAdminTab === 'dashboard') loadAdminDashboard(true);
}

// -------------------------------------------------------------
// TAB 1: DASHBOARD OVERVIEW (Requirement 1)
// -------------------------------------------------------------

async function loadAdminDashboard(silent = false) {
    try {
        const res = await fetch('/api/admin.php?action=stats');
        const data = await res.json();
        if (!data.success || !data.stats) return;

        const s = data.stats;

        // User metrics
        document.getElementById('dashTotalUsers').innerText = s.total_users;
        document.getElementById('dashActiveUsers').innerText = s.active_users;
        document.getElementById('dashSuspendedUsers').innerText = s.suspended_users;
        document.getElementById('dashDemoUsers').innerText = s.demo_users;

        // Volumes & Balances
        document.getElementById('dashTradingVol').innerText = `₹${parseFloat(s.total_completed_trading_volume).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashSellVol').innerText = `₹${parseFloat(s.total_sell_volume).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashCirculation').innerText = `₹${parseFloat(s.total_tokens_in_circulation).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashAvailableBal').innerText = `₹${parseFloat(s.total_available_tokens).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashReservedBal').innerText = `₹${parseFloat(s.total_reserved_tokens).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;

        // Orders breakdown
        document.getElementById('dashOrdersTotal').innerText = s.orders.total;
        document.getElementById('dashOrdersAvailable').innerText = s.orders.available;
        document.getElementById('dashOrdersReserved').innerText = s.orders.reserved;
        document.getElementById('dashOrdersPendingConfirm').innerText = s.orders.pending_confirmation;
        document.getElementById('dashOrdersDisputed').innerText = s.orders.disputed;
        document.getElementById('dashOrdersCompleted').innerText = s.orders.completed;
        document.getElementById('dashOrdersCancelled').innerText = s.orders.cancelled;

        // Rewards & Referrals
        document.getElementById('dashTotalRewards').innerText = `₹${parseFloat(s.total_rewards).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashTotalCommissions').innerText = `₹${parseFloat(s.total_commissions).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashL1Commissions').innerText = `₹${parseFloat(s.total_l1_commissions).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashL2Commissions').innerText = `₹${parseFloat(s.total_l2_commissions).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;

        // Weekly Target
        document.getElementById('dashWeeklyTarget').innerText = `₹${parseFloat(s.weekly_selling_target).toLocaleString('en-IN')}`;
        document.getElementById('dashWeeklyCompleted').innerText = `₹${parseFloat(s.weekly_completed_volume).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('dashWeeklyPct').innerText = `${s.weekly_progress_percent}%`;
        document.getElementById('dashWeeklyProgressBar').style.width = `${s.weekly_progress_percent}%`;

        // Recent Activity tables
        const transTbody = document.getElementById('dashRecentTransBody');
        if (transTbody && s.recent_transactions) {
            transTbody.innerHTML = s.recent_transactions.map(t => `
                <tr class="hover:bg-slate-900/60 font-mono text-xs">
                    <td class="p-2.5 text-slate-500">#${t.id}</td>
                    <td class="p-2.5 text-slate-300">${escapeHtml(t.user_phone)}</td>
                    <td class="p-2.5"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 text-amber-400 font-bold">${t.transaction_type}</span></td>
                    <td class="p-2.5 font-bold text-white">₹${parseFloat(t.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                    <td class="p-2.5 text-slate-400 text-[11px]">${t.created_at}</td>
                </tr>
            `).join('');
        }

        const auditTbody = document.getElementById('dashRecentAuditBody');
        if (auditTbody && s.recent_admin_activities) {
            auditTbody.innerHTML = s.recent_admin_activities.map(a => `
                <tr class="hover:bg-slate-900/60 font-mono text-xs">
                    <td class="p-2.5 text-slate-500">#${a.id}</td>
                    <td class="p-2.5 text-amber-400">${escapeHtml(a.admin_phone || 'Admin')}</td>
                    <td class="p-2.5"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 font-bold">${escapeHtml(a.action_type)}</span></td>
                    <td class="p-2.5 text-slate-300 truncate max-w-xs font-sans text-[11px]">${escapeHtml(a.reason)}</td>
                    <td class="p-2.5 text-slate-400 text-[11px]">${a.created_at}</td>
                </tr>
            `).join('');
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
                    ${u.is_demo ? '<span class="ml-1 px-1.5 py-0.5 rounded bg-violet-500/20 text-violet-400 text-[9px] uppercase font-bold">Demo</span>' : ''}
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
                            ${o.screenshot_path ? `<a href="/serve_file.php?file=${encodeURIComponent(o.screenshot_path)}" target="_blank" class="text-[10px] text-indigo-400 underline font-sans">View Proof</a>` : ''}
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

    try {
        const res = await fetch(`/api/admin.php?action=disputes&status=${encodeURIComponent(status)}`);
        const data = await res.json();
        const list = document.getElementById('adminDisputesContainer');
        if (!list) return;

        if (!data.success || !data.disputes || data.disputes.length === 0) {
            list.innerHTML = `<div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-500">No disputes pending review.</div>`;
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
                            <a href="/serve_file.php?file=${encodeURIComponent(d.evidence_screenshot_path)}" target="_blank" class="inline-flex items-center gap-1 text-xs text-indigo-400 underline font-semibold mt-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Seller Evidence Document</span>
                            </a>
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
                            <a href="/serve_file.php?file=${encodeURIComponent(d.buyer_screenshot)}" target="_blank" class="inline-flex items-center gap-1 text-xs text-indigo-400 underline font-semibold mt-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <span>Inspect Buyer Payment Screenshot</span>
                            </a>
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
    loadSettingsIntoGrid('contentSettingsGrid', [
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
        'seller_verify_window_seconds'
    ]);
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
                <button onclick="saveGlobalSetting('${s.setting_key}')" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold py-2 rounded-xl text-xs uppercase tracking-wider transition">
                    Save Configuration
                </button>
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load settings:', e);
    }
}

async function saveGlobalSetting(key) {
    const input = document.getElementById(`settingInput_${key}`);
    const val = input ? input.value : '';
    const reason = prompt(`Enter mandatory reason for modifying setting '${key}':`, 'Admin configuration change');
    if (!reason) return;

    try {
        const res = await fetch('/api/admin.php?action=settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ setting_key: key, setting_value: val, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Setting '${key}' updated and audited successfully!`);
        } else {
            alert(data.error || 'Failed to update setting.');
        }
    } catch (e) {
        alert('Network error.');
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
// LIVE SUPPORT & REAL-TIME CHAT DESK CONTROLLER
// -------------------------------------------------------------

let adminSelectedChatId = null;
let adminSelectedChatData = null;
let adminChatLastMsgId = 0;
let adminChatsCache = [];
let adminChatFilter = 'all';
let adminChatIsSending = false;
let adminSoundEnabled = true;
let adminAlertedMessageIds = new Set();
let toastChatTargetId = null;
let toastTimeout = null;
let adminSyncInProgress = false;

// Sound Alert Toggle
function toggleAdminSound() {
    adminSoundEnabled = !adminSoundEnabled;
    const icon = document.getElementById('adminSoundIcon');
    const label = document.getElementById('adminSoundLabel');
    if (icon) icon.innerText = adminSoundEnabled ? '🔔' : '🔕';
    if (label) label.innerText = adminSoundEnabled ? 'Sound: On' : 'Sound: Muted';
}

// Synthesize pleasant two-tone audio alert chime using Web Audio API
function playAdminNotificationChime() {
    if (!adminSoundEnabled) return;
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.12); // A5

        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);

        osc.start();
        osc.stop(ctx.currentTime + 0.35);
    } catch (e) {
        // Audio playback may await user click on page
    }
}

// Toast Alert Functions
function showAdminLiveChatToast(chatId, userName, userPhone, message) {
    const toast = document.getElementById('adminLiveChatToast');
    const title = document.getElementById('adminToastTitle');
    const msgEl = document.getElementById('adminToastMessage');
    if (!toast) return;

    toastChatTargetId = chatId;
    if (title) title.innerText = `💬 ${userName || 'Trader'} (+91 ${userPhone})`;
    if (msgEl) msgEl.innerText = message || 'Sent a new live support message.';

    toast.classList.remove('opacity-0', 'translate-y-28', 'pointer-events-none');
    toast.classList.add('opacity-100', 'translate-y-0');

    if (toastTimeout) clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => {
        dismissAdminToast();
    }, 8000);
}

function dismissAdminToast() {
    const toast = document.getElementById('adminLiveChatToast');
    if (!toast) return;
    toast.classList.add('opacity-0', 'translate-y-28', 'pointer-events-none');
    toast.classList.remove('opacity-100', 'translate-y-0');
}

function openChatFromToast() {
    if (toastChatTargetId) {
        switchAdminTab('support');
        selectAdminChat(toastChatTargetId);
        dismissAdminToast();
    }
}

// Initialize Server-Sent Events (SSE) Stream
function initAdminSSE() {
    try {
        if (!window.EventSource) return;
        const es = new EventSource('/api/support.php?action=sse_stream');
        es.addEventListener('new_message', () => {
            syncAdminLiveSupport();
        });
        es.onerror = () => {
            // EventSource will automatically retry in the background
        };
    } catch (e) {
        // Fallback to high-frequency polling
    }
}

// Primary Ultra-Fast Real-Time Live Support Sync (1.5s interval)
async function syncAdminLiveSupport() {
    if (adminSyncInProgress) return;
    adminSyncInProgress = true;

    try {
        const activeId = adminSelectedChatId || 0;
        const lastId = (activeId > 0) ? (adminChatLastMsgId || 0) : 0;
        const res = await fetch(`/api/support.php?action=admin_sync&active_request_id=${activeId}&last_msg_id=${lastId}&filter=${adminChatFilter}`);
        const data = await res.json();

        if (!data.success) return;

        // 1. Update chats cache
        if (data.chats) {
            adminChatsCache = data.chats;
            if (currentAdminTab === 'support') {
                renderAdminChatList();
            }
            renderDashboardSupportTable(data.chats, data.unread_total);
        }

        // 2. Update global navbar badge
        updateAdminSupportBadges(data.unread_total);

        // 3. Update active session metadata if loaded
        if (activeId > 0 && data.active_session) {
            adminSelectedChatData = data.active_session;
            const metaEl = document.getElementById('adminChatUserMeta');
            if (metaEl && data.active_session) {
                const healthScore = data.active_session.health_score || 100;
                const agentName = data.active_session.assigned_admin_name || 'Unassigned';
                metaEl.innerHTML = `
                    User #${data.active_session.user_id || 'Guest'} &bull; 
                    Health Score: <strong class="${healthScore <= 50 ? 'text-red-400' : 'text-emerald-400'}">${healthScore}/100</strong> &bull; 
                    Status: <strong class="text-white">${data.active_session.account_status || 'active'}</strong> &bull; 
                    Agent: <strong class="text-indigo-400">${escapeHtml(agentName)}</strong>
                `;
            }
        }

        // 4. Append new messages in the currently open active thread
        if (currentAdminTab === 'support' && activeId > 0 && data.active_messages && data.active_messages.length > 0) {
            appendAdminMessages(data.active_messages);
        }

        // 5. Detect and alert on new user messages across the platform
        if (data.recent_incoming && data.recent_incoming.length > 0) {
            let hasNewAlert = false;
            data.recent_incoming.forEach(m => {
                if (!adminAlertedMessageIds.has(m.id)) {
                    adminAlertedMessageIds.add(m.id);
                    hasNewAlert = true;
                    if (currentAdminTab !== 'support' || adminSelectedChatId !== m.request_id) {
                        showAdminLiveChatToast(m.request_id, m.sender_name, m.phone, m.message);
                    }
                }
            });

            if (hasNewAlert) {
                playAdminNotificationChime();
            }
        }
    } catch (e) {
        // Silent failure; will retry next cycle
    } finally {
        adminSyncInProgress = false;
    }
}

// Render Dashboard Live Support Desk Feed
function renderDashboardSupportTable(chats, unreadTotal) {
    const tbody = document.getElementById('dashLiveSupportTableBody');
    const pill = document.getElementById('dashSupportUnreadPill');
    if (pill) {
        const count = unreadTotal || 0;
        pill.innerText = `${count} Unread Message${count === 1 ? '' : 's'}`;
        if (count > 0) {
            pill.className = 'text-xs font-bold font-mono px-2.5 py-1 rounded-full bg-red-500/10 text-red-400 border border-red-500/30 animate-pulse';
        } else {
            pill.className = 'text-xs font-bold font-mono px-2.5 py-1 rounded-full bg-slate-800 text-slate-400 border border-slate-700';
        }
    }

    if (!tbody) return;

    if (!chats || chats.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="p-8 text-center text-slate-500">
                    <p class="font-semibold">No live support inquiries active</p>
                    <span class="text-[11px] text-slate-600 block mt-1">When traders or guests submit messages or dispute inquiries, they appear here instantly.</span>
                </td>
            </tr>
        `;
        return;
    }

    const topChats = chats.slice(0, 6);
    tbody.innerHTML = topChats.map(c => {
        const name = c.account_holder_name || c.username || 'Guest Trader';
        const phone = c.phone || 'N/A';
        const unread = parseInt(c.unread_count || 0, 10);
        const health = parseInt(c.health_score || 100, 10);
        const timeStr = c.last_message_at ? c.last_message_at.slice(11, 16) : (c.updated_at ? c.updated_at.slice(11, 16) : '');
        const lastMsg = c.last_message || 'Inquiry initiated';
        const agentName = c.assigned_admin_name || 'Unassigned';

        let statusClass = 'bg-slate-800 text-slate-400';
        if (c.status === 'open') statusClass = 'bg-emerald-950/60 text-emerald-400 border border-emerald-800';
        if (c.status === 'in_progress') statusClass = 'bg-indigo-950/60 text-indigo-400 border border-indigo-800';
        if (c.status === 'resolved') statusClass = 'bg-slate-800 text-slate-500';

        let healthClass = 'text-emerald-400';
        if (health <= 50) healthClass = 'text-red-400 font-bold';
        else if (health <= 75) healthClass = 'text-amber-400';

        return `
            <tr class="hover:bg-slate-950/40 transition">
                <td class="p-2.5">
                    <div class="font-bold text-white flex items-center gap-1.5">
                        <span>${escapeHtml(name)}</span>
                        ${unread > 0 ? `<span class="w-2 h-2 rounded-full bg-red-400 animate-ping"></span>` : ''}
                    </div>
                    <span class="text-[11px] text-slate-400 font-mono">+91 ${escapeHtml(phone)}</span>
                </td>
                <td class="p-2.5 font-mono text-xs ${healthClass}">
                    ${health}/100
                </td>
                <td class="p-2.5">
                    <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded ${statusClass}">
                        ${escapeHtml(c.status)}
                    </span>
                </td>
                <td class="p-2.5 text-xs text-slate-300">
                    <span class="inline-flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full ${c.assigned_admin_id ? 'bg-indigo-400' : 'bg-slate-600'}"></span>
                        <strong class="${c.assigned_admin_id ? 'text-indigo-300' : 'text-slate-500'}">${escapeHtml(agentName)}</strong>
                    </span>
                </td>
                <td class="p-2.5 text-slate-300 max-w-xs truncate" title="${escapeHtml(lastMsg)}">
                    ${escapeHtml(lastMsg)}
                </td>
                <td class="p-2.5 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                    ${timeStr}
                </td>
                <td class="p-2.5 text-right whitespace-nowrap">
                    <button onclick="openChatFromDashboard(${c.id})" class="px-2.5 py-1 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-[11px] transition inline-flex items-center gap-1 shadow-sm">
                        <span>Open</span>
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function openChatFromDashboard(chatId) {
    switchAdminTab('support');
    selectAdminChat(chatId);
}

// Load Full Support Conversations List
async function loadAdminSupportChats(force = false) {
    const list = document.getElementById('adminSupportChatList');
    if (!list) return;

    if (!force && adminChatsCache.length === 0) {
        list.innerHTML = '<div class="p-8 text-center text-slate-500 text-xs">Loading support conversations...</div>';
    }

    try {
        await syncAdminLiveSupport();
        renderAdminChatList();
    } catch (e) {
        console.error('Failed to load admin support chats:', e);
        if (list) list.innerHTML = '<div class="p-8 text-center text-red-400 text-xs">Network error loading chat sessions.</div>';
    }
}

// Render Support Tab Conversations List
function renderAdminChatList() {
    const list = document.getElementById('adminSupportChatList');
    const totalCountEl = document.getElementById('adminChatTotalCount');
    if (!list) return;

    const searchTerm = (document.getElementById('adminChatSearchInput')?.value || '').toLowerCase().trim();
    const filtered = adminChatsCache.filter(c => {
        if (!searchTerm) return true;
        const phone = (c.phone || '').toLowerCase();
        const name = (c.username || c.account_holder_name || '').toLowerCase();
        return phone.includes(searchTerm) || name.includes(searchTerm);
    });

    if (totalCountEl) {
        totalCountEl.innerText = `${filtered.length} conversation${filtered.length === 1 ? '' : 's'}`;
    }

    if (filtered.length === 0) {
        list.innerHTML = `
            <div class="p-8 text-center text-slate-500 text-xs space-y-2">
                <svg class="w-8 h-8 text-slate-700 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p>No support conversations found</p>
            </div>
        `;
        return;
    }

    list.innerHTML = filtered.map(c => {
        const isSelected = (adminSelectedChatId === c.id);
        const unreadCount = parseInt(c.unread_count || 0, 10);
        const name = c.account_holder_name || c.username || 'Guest User';
        const initials = name.slice(0, 2).toUpperCase();
        const timeStr = c.last_message_at ? c.last_message_at.slice(11, 16) : (c.updated_at ? c.updated_at.slice(11, 16) : '');
        const lastMsg = c.last_message || 'Session started';

        let statusClass = 'bg-slate-800 text-slate-300';
        if (c.status === 'open') statusClass = 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/60';
        if (c.status === 'in_progress') statusClass = 'bg-indigo-950/60 text-indigo-400 border border-indigo-800/60';
        if (c.status === 'resolved') statusClass = 'bg-slate-800/60 text-slate-500';

        return `
            <div onclick="selectAdminChat(${c.id})" class="p-3.5 cursor-pointer transition flex items-start gap-3 ${isSelected ? 'bg-amber-500/10 border-l-4 border-amber-500' : 'hover:bg-slate-950/60'}">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center font-bold text-xs flex-shrink-0 ${unreadCount > 0 ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-300'}">
                    ${escapeHtml(initials)}
                </div>
                <div class="flex-1 min-w-0 space-y-1">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-xs font-bold text-white truncate">${escapeHtml(name)}</span>
                        <span class="text-[10px] text-slate-500 font-mono">${timeStr}</span>
                    </div>
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[11px] text-slate-400 font-mono">+91 ${escapeHtml(c.phone)}</span>
                        <span class="text-[9px] uppercase font-bold px-1.5 py-0.5 rounded ${statusClass}">${escapeHtml(c.status)}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2 pt-0.5">
                        <p class="text-[11px] text-slate-400 truncate max-w-[190px]">${escapeHtml(lastMsg)}</p>
                        ${unreadCount > 0 ? `
                            <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-amber-500 text-slate-950 flex-shrink-0 animate-pulse">
                                ${unreadCount} new
                            </span>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function filterAdminChats(filter) {
    adminChatFilter = filter;
    ['all', 'open', 'resolved'].forEach(f => {
        const btn = document.getElementById(`chatFilter-${f}`);
        if (!btn) return;
        if (f === filter) {
            btn.className = 'px-2.5 py-1 rounded bg-amber-500/10 text-amber-400 font-bold';
        } else {
            btn.className = 'px-2.5 py-1 rounded text-slate-400 hover:text-white';
        }
    });
    syncAdminLiveSupport();
}

function filterAdminChatListBySearch() {
    renderAdminChatList();
}

// Select a Chat Conversation
async function selectAdminChat(chatId) {
    adminSelectedChatId = chatId;
    adminSelectedChatData = adminChatsCache.find(c => c.id === chatId);

    renderAdminChatList();

    const stream = document.getElementById('adminSupportMessagesStream');
    const actions = document.getElementById('adminChatActions');
    const cannedBar = document.getElementById('adminChatCannedBar');
    const form = document.getElementById('adminChatReplyForm');
    const resolveBtn = document.getElementById('adminChatResolveBtn');
    const claimBtn = document.getElementById('adminChatClaimBtn');

    if (actions) actions.classList.remove('hidden');
    if (cannedBar) cannedBar.classList.remove('hidden');
    if (form) form.classList.remove('hidden');

    if (adminSelectedChatData) {
        const nameEl = document.getElementById('adminChatUserName');
        const phoneEl = document.getElementById('adminChatUserPhone');
        const initialsEl = document.getElementById('adminChatUserInitials');
        const statusBadge = document.getElementById('adminChatStatusBadge');
        const metaEl = document.getElementById('adminChatUserMeta');

        const name = adminSelectedChatData.account_holder_name || adminSelectedChatData.username || 'Guest User';
        if (nameEl) nameEl.innerText = name;
        if (phoneEl) phoneEl.innerText = `+91 ${adminSelectedChatData.phone}`;
        if (initialsEl) initialsEl.innerText = name.slice(0, 2).toUpperCase();

        if (statusBadge) {
            statusBadge.innerText = adminSelectedChatData.status.toUpperCase();
            statusBadge.className = `text-[10px] font-bold px-2 py-0.5 rounded ${adminSelectedChatData.status === 'resolved' ? 'bg-slate-800 text-slate-500' : 'bg-emerald-950 text-emerald-400 border border-emerald-800'}`;
            statusBadge.classList.remove('hidden');
        }

        if (resolveBtn) {
            resolveBtn.innerText = adminSelectedChatData.status === 'resolved' ? 'Reopen Chat' : 'Mark Resolved';
        }

        if (metaEl) {
            const healthScore = adminSelectedChatData.health_score || 100;
            const agentName = adminSelectedChatData.assigned_admin_name || 'Unassigned';
            metaEl.innerHTML = `
                User #${adminSelectedChatData.user_id || 'Guest'} &bull; 
                Health Score: <strong class="${healthScore <= 50 ? 'text-red-400' : 'text-emerald-400'}">${healthScore}/100</strong> &bull; 
                Status: <strong class="text-white">${adminSelectedChatData.account_status || 'active'}</strong> &bull; 
                Agent: <strong class="text-indigo-400">${escapeHtml(agentName)}</strong>
            `;
        }

        if (claimBtn) {
            if (adminSelectedChatData.assigned_admin_name) {
                claimBtn.innerHTML = `
                    <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Assigned: ${escapeHtml(adminSelectedChatData.assigned_admin_name)}</span>
                `;
            } else {
                claimBtn.innerHTML = `
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span>Assign to Me</span>
                `;
            }
        }
    }

    if (stream) stream.innerHTML = '<div class="p-8 text-center text-slate-500 text-xs">Loading message transcript...</div>';

    try {
        const res = await fetch(`/api/support.php?action=poll_messages&request_id=${chatId}&after_id=0`);
        const data = await res.json();

        if (data.success) {
            renderAdminMessagesStream(data.messages || []);
            if (adminSelectedChatData) {
                adminSelectedChatData.unread_count = 0;
                renderAdminChatList();
                updateAdminSupportBadges();
            }
        }
    } catch (e) {
        console.error('Failed to load chat messages:', e);
    }

    const replyInput = document.getElementById('adminChatReplyInput');
    if (replyInput) {
        replyInput.focus();
        replyInput.onkeydown = (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitAdminChatReply();
            }
        };
    }
}

// Claim Chat for Current Admin
async function claimAdminChat() {
    if (!adminSelectedChatId) return;
    const btn = document.getElementById('adminChatClaimBtn');
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('/api/support.php?action=assign_agent', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: adminSelectedChatId })
        });
        const data = await res.json();
        if (data.success) {
            if (adminSelectedChatData) {
                adminSelectedChatData.assigned_admin_name = data.assigned_admin_name;
                adminSelectedChatData.status = 'in_progress';
            }
            selectAdminChat(adminSelectedChatId);
            syncAdminLiveSupport();
        } else {
            alert(data.error || 'Failed to assign agent.');
        }
    } catch (e) {
        alert('Network error assigning agent.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// Render Messages Stream
function renderAdminMessagesStream(messages) {
    const stream = document.getElementById('adminSupportMessagesStream');
    if (!stream) return;

    if (!messages || messages.length === 0) {
        stream.innerHTML = '<div class="p-8 text-center text-slate-500 text-xs">No messages in this chat session yet.</div>';
        return;
    }

    adminChatLastMsgId = 0;
    let html = '';

    messages.forEach(m => {
        if (m.id > adminChatLastMsgId) adminChatLastMsgId = m.id;
        const timeStr = m.created_at ? m.created_at.slice(11, 16) : '';
        const isUser = (m.sender_type === 'user');
        const isSystem = (m.sender_type === 'system');

        if (isSystem) {
            html += `
                <div class="flex justify-center my-1.5">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 max-w-md text-center text-slate-400 text-[11px]">
                        ${escapeHtml(m.message)}
                        <span class="block text-[9px] text-slate-600 mt-0.5">${timeStr}</span>
                    </div>
                </div>
            `;
        } else if (isUser) {
            html += `
                <div class="flex justify-start my-1.5 items-end gap-2">
                    <div class="w-7 h-7 rounded-lg bg-indigo-950 border border-indigo-700/50 text-indigo-400 flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                        U
                    </div>
                    <div class="bg-slate-900 border border-slate-800 text-slate-200 rounded-2xl rounded-tl-sm px-3.5 py-2 max-w-[75%] space-y-1 shadow-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-800 pb-1 mb-1">
                            <span class="text-[10px] font-bold text-indigo-400">${escapeHtml(m.sender_name || 'User')}</span>
                            <span class="text-[9px] text-slate-500 font-mono">${timeStr}</span>
                        </div>
                        <div class="text-xs break-words whitespace-pre-wrap">${escapeHtml(m.message)}</div>
                        ${m.attachment_path ? `
                            <div class="pt-1.5">
                                <a href="/serve_file.php?file=${encodeURIComponent(m.attachment_path)}" target="_blank" class="inline-flex items-center gap-1.5 bg-slate-950 hover:bg-slate-800 text-indigo-400 text-[11px] px-2.5 py-1 rounded-lg border border-slate-800 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span>Attachment (${escapeHtml(m.attachment_path.split('/').pop())})</span>
                                </a>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
        } else {
            html += `
                <div class="flex justify-end my-1.5 items-end gap-2">
                    <div class="bg-amber-500/10 border border-amber-500/30 text-white rounded-2xl rounded-tr-sm px-3.5 py-2 max-w-[75%] space-y-1 shadow-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-amber-500/20 pb-1 mb-1">
                            <span class="text-[10px] font-bold text-amber-400 uppercase tracking-wider">${escapeHtml(m.sender_name || 'Admin')}</span>
                            <span class="text-[9px] text-slate-400 font-mono">${timeStr}</span>
                        </div>
                        <div class="text-xs break-words whitespace-pre-wrap">${escapeHtml(m.message)}</div>
                        ${m.attachment_path ? `
                            <div class="pt-1.5">
                                <a href="/serve_file.php?file=${encodeURIComponent(m.attachment_path)}" target="_blank" class="inline-flex items-center gap-1.5 bg-slate-950 text-amber-400 text-[11px] px-2.5 py-1 rounded-lg border border-amber-500/30 transition">
                                    <span>Attachment</span>
                                </a>
                            </div>
                        ` : ''}
                    </div>
                    <div class="w-7 h-7 rounded-lg bg-amber-500 text-slate-950 flex items-center justify-center text-[10px] font-black flex-shrink-0">
                        AD
                    </div>
                </div>
            `;
        }
    });

    stream.innerHTML = html;
    stream.scrollTop = stream.scrollHeight;
}

// Append Incremental Messages to Stream
function appendAdminMessages(messages) {
    const stream = document.getElementById('adminSupportMessagesStream');
    if (!stream || !messages || messages.length === 0) return;

    let shouldScroll = (stream.scrollHeight - stream.scrollTop - stream.clientHeight < 120);

    messages.forEach(m => {
        if (m.id > adminChatLastMsgId) adminChatLastMsgId = m.id;
        const timeStr = m.created_at ? m.created_at.slice(11, 16) : '';
        const isUser = (m.sender_type === 'user');
        const isSystem = (m.sender_type === 'system');

        const wrapper = document.createElement('div');
        if (isSystem) {
            wrapper.className = 'flex justify-center my-1.5';
            wrapper.innerHTML = `
                <div class="bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 max-w-md text-center text-slate-400 text-[11px]">
                    ${escapeHtml(m.message)}
                    <span class="block text-[9px] text-slate-600 mt-0.5">${timeStr}</span>
                </div>
            `;
        } else if (isUser) {
            wrapper.className = 'flex justify-start my-1.5 items-end gap-2';
            wrapper.innerHTML = `
                <div class="w-7 h-7 rounded-lg bg-indigo-950 border border-indigo-700/50 text-indigo-400 flex items-center justify-center text-[10px] font-bold flex-shrink-0">
                    U
                </div>
                <div class="bg-slate-900 border border-slate-800 text-slate-200 rounded-2xl rounded-tl-sm px-3.5 py-2 max-w-[75%] space-y-1 shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-800 pb-1 mb-1">
                        <span class="text-[10px] font-bold text-indigo-400">${escapeHtml(m.sender_name || 'User')}</span>
                        <span class="text-[9px] text-slate-500 font-mono">${timeStr}</span>
                    </div>
                    <div class="text-xs break-words whitespace-pre-wrap">${escapeHtml(m.message)}</div>
                </div>
            `;
        } else {
            wrapper.className = 'flex justify-end my-1.5 items-end gap-2';
            wrapper.innerHTML = `
                <div class="bg-amber-500/10 border border-amber-500/30 text-white rounded-2xl rounded-tr-sm px-3.5 py-2 max-w-[75%] space-y-1 shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-amber-500/20 pb-1 mb-1">
                        <span class="text-[10px] font-bold text-amber-400 uppercase tracking-wider">${escapeHtml(m.sender_name || 'Admin')}</span>
                        <span class="text-[9px] text-slate-400 font-mono">${timeStr}</span>
                    </div>
                    <div class="text-xs break-words whitespace-pre-wrap">${escapeHtml(m.message)}</div>
                </div>
                <div class="w-7 h-7 rounded-lg bg-amber-500 text-slate-950 flex items-center justify-center text-[10px] font-black flex-shrink-0">
                    AD
                </div>
            `;
        }
        stream.appendChild(wrapper);
    });

    if (shouldScroll) {
        stream.scrollTop = stream.scrollHeight;
    }
}

// Send Admin Reply
async function submitAdminChatReply() {
    if (!adminSelectedChatId || adminChatIsSending) return;
    const input = document.getElementById('adminChatReplyInput');
    const sendBtn = document.getElementById('adminChatSendBtn');
    const message = input ? input.value.trim() : '';

    if (!message) return;

    adminChatIsSending = true;
    if (sendBtn) sendBtn.disabled = true;

    try {
        const res = await fetch('/api/support.php?action=admin_reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: adminSelectedChatId,
                message: message
            })
        });
        const data = await res.json();

        if (data.success) {
            if (input) input.value = '';
            await syncAdminLiveSupport();
        } else {
            alert(data.error || 'Failed to send reply.');
        }
    } catch (e) {
        console.error('Failed to send admin reply:', e);
        alert('Network error sending reply.');
    } finally {
        adminChatIsSending = false;
        if (sendBtn) sendBtn.disabled = false;
    }
}

// Toggle Resolved/Open Status
async function toggleAdminChatStatus() {
    if (!adminSelectedChatId || !adminSelectedChatData) return;
    const currentStatus = adminSelectedChatData.status;
    const targetStatus = (currentStatus === 'resolved') ? 'open' : 'resolved';

    try {
        const res = await fetch('/api/support.php?action=admin_update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                request_id: adminSelectedChatId,
                status: targetStatus
            })
        });
        const data = await res.json();

        if (data.success) {
            adminSelectedChatData.status = targetStatus;
            selectAdminChat(adminSelectedChatId);
            syncAdminLiveSupport();
        } else {
            alert(data.error || 'Failed to update chat status.');
        }
    } catch (e) {
        alert('Network error updating status.');
    }
}

function insertAdminCanned(text) {
    const input = document.getElementById('adminChatReplyInput');
    if (input) {
        input.value = text;
        input.focus();
    }
}

function openAdminChatUserDossier() {
    if (adminSelectedChatData && adminSelectedChatData.user_id) {
        openUserDossier(adminSelectedChatData.user_id);
    } else {
        alert('This session was initiated by a guest without a registered account ID.');
    }
}

function updateAdminSupportBadges(explicitTotal = null) {
    const badge = document.getElementById('adminSupportUnreadBadge');
    if (!badge) return;

    let unreadTotal = (explicitTotal !== null) ? explicitTotal : 0;
    if (explicitTotal === null) {
        adminChatsCache.forEach(c => {
            unreadTotal += parseInt(c.unread_count || 0, 10);
        });
    }

    if (unreadTotal > 0) {
        badge.innerText = unreadTotal > 9 ? '9+' : unreadTotal;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}
