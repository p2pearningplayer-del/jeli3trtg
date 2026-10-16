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
});

async function initAdmin() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    switchAdminTab(tabParam || 'dashboard');
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
