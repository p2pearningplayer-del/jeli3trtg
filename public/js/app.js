/**
 * JeliPay P2P Platform - Frontend Reactive Application Controller
 * Handles order state machine UI, live timers, proof uploads,
 * double-entry ledger rendering, and administrative arbitration.
 */

let currentUser = null;
let currentTab = 'home';
let activeTimers = {};
let pendingConfirmOrderId = null;
let pendingConfirmSeconds = 0;
let proofModalSeconds = 0;
let activeElapsedTimers = {};
let currentHistoryTab = 'buy';
let notificationsOpen = false;

// Global Order State Badge Style Mapping
const statusBadges = {
    'Available': 'bg-slate-800 text-slate-400',
    'Reserved': 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
    'Payment Proof Submitted': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
    'Seller Confirmation Pending': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
    'Seller Response Timeout': 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
    'Disputed': 'bg-red-500/20 text-red-400 border border-red-500/30',
    'Under Admin Review': 'bg-purple-500/20 text-purple-300 border border-purple-500/30',
    'Completed': 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
    'Expired': 'bg-slate-800 text-slate-500',
    'Cancelled': 'bg-red-500/10 text-red-400',
    'Cancelled / Reversed': 'bg-red-500/10 text-red-400',
    'active': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
    'open': 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
    'resolved': 'bg-emerald-500/10 text-emerald-400'
};

function getStatusBadgeClass(status) {
    return statusBadges[status] || 'bg-slate-800 text-slate-400 border border-slate-700';
}

// Format order IDs into compact 4-digit format (Part 5, Item 3)
function formatOrderId(id) {
    if (!id && id !== 0) return '';
    const num = parseInt(id, 10);
    return isNaN(num) ? String(id) : '#' + String(num).padStart(4, '0');
}

// Format elapsed sell time into human-readable counter (Part 5, Item 5)
function formatElapsed(seconds) {
    if (isNaN(seconds) || seconds < 0) return '0s';
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    if (hrs > 0) return `${hrs}h ${mins}m ${secs}s`;
    if (mins > 0) return `${mins}m ${secs}s`;
    return `${secs}s`;
}

document.addEventListener('DOMContentLoaded', () => {
    initApp();
    loadSupportConfig();
    setInterval(updateLiveCountdowns, 1000);
    setInterval(periodicSync, 5000);
});

async function initApp() {
    await fetchSession();
    const urlParams = new URLSearchParams(window.location.search);
    if (!currentUser) {
        // Direct to Registration / Create Account page by default when referral link (?ref=...) or ?action=register is clicked
        if (urlParams.get('action') === 'register' || urlParams.get('ref')) {
            openAuthModal('register');
        } else if (urlParams.get('action') === 'login' || urlParams.get('tab')) {
            openAuthModal('login');
        }
        return;
    }

    loadNotifications();
    checkPendingSellerConfirmations();
    syncHomeActiveOrder();

    const tabParam = urlParams.get('tab');
    if (tabParam) {
        switchTab(tabParam);
    } else {
        switchTab('home');
    }
}

// -------------------------------------------------------------
// SESSION & AUTHENTICATION
// -------------------------------------------------------------

let isFetchingSession = null;

async function fetchSession() {
    if (isFetchingSession) return isFetchingSession;
    isFetchingSession = (async () => {
        try {
            const res = await fetch('/api/auth.php?action=me');
            if (res.status === 401) {
                currentUser = null;
                return null;
            }
            const data = await res.json();
            if (data.success && data.user) {
                currentUser = data.user;
                updateHeaderBalances(data.wallet);
                updateProfilePreviews(data.user);
                return currentUser;
            } else if (res.status === 401 || (data && data.error === 'Authentication required.')) {
                currentUser = null;
                return null;
            }
        } catch (e) {
            console.warn('Transient session fetch issue (keeping active session):', e);
        } finally {
            isFetchingSession = null;
        }
        return currentUser;
    })();
    return isFetchingSession;
}

function updateHeaderBalances(wallet) {
    if (!wallet) return;
    const availEl = document.getElementById('headerAvailableBalance');
    if (availEl) {
        const formatted = `₹${parseFloat(wallet.available_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        availEl.innerText = formatted;
        availEl.title = formatted;
    }
}

function updateProfilePreviews(user) {
    if (!user) return;

    // Prefill sell page banking inputs if available and empty
    const sellHolder = document.getElementById('sellAccountHolderName');
    const sellUpi = document.getElementById('sellUpiId');
    if (sellHolder && user.account_holder_name && !sellHolder.value) {
        sellHolder.value = user.account_holder_name;
    }
    if (sellUpi && user.upi_id && !sellUpi.value) {
        sellUpi.value = user.upi_id;
    }

    // Prefill profile edit inputs
    const profHolder = document.getElementById('profHolderName');
    const profUpi = document.getElementById('profUpiId');
    const profEmail = document.getElementById('profEmail');
    if (profHolder && user.account_holder_name && (!profHolder.value || profHolder.value !== user.account_holder_name)) {
        profHolder.value = user.account_holder_name;
    }
    if (profUpi && user.upi_id && (!profUpi.value || profUpi.value !== user.upi_id)) {
        profUpi.value = user.upi_id;
    }
    if (profEmail && user.email && (!profEmail.value || profEmail.value !== user.email)) {
        profEmail.value = user.email;
    }

    // Profile Top Header Cards & Displays
    const nameDisplay = document.getElementById('profileNameDisplay');
    const emailDisplay = document.getElementById('profileEmailDisplay');
    const avatarInitial = document.getElementById('profileAvatarInitial');
    const headerInitial = document.getElementById('headerProfileInitial');
    const headerName = document.getElementById('headerProfileName');
    const userIdDisplay = document.getElementById('profileUserIdDisplay');

    const displayName = user.account_holder_name || (user.phone ? `Trader ${user.phone.slice(-4)}` : 'Verified Trader');
    const initialChar = (user.account_holder_name ? user.account_holder_name.trim().charAt(0) : (user.phone ? user.phone.trim().charAt(0) : 'U')).toUpperCase();

    if (nameDisplay) nameDisplay.innerText = displayName;
    if (emailDisplay) emailDisplay.innerText = user.email || 'Email not linked';
    if (avatarInitial) avatarInitial.innerText = initialChar;
    if (headerInitial) headerInitial.innerText = initialChar;
    if (headerName) headerName.innerText = user.account_holder_name ? user.account_holder_name.split(' ')[0] : 'Profile';
    if (userIdDisplay) userIdDisplay.innerText = `#${user.id}`;

    const phoneDisplay = document.getElementById('profilePhoneDisplay');
    const refCodeDisplay = document.getElementById('profileRefCodeDisplay');
    if (phoneDisplay && user.phone) phoneDisplay.innerText = user.phone;
    if (refCodeDisplay && user.referral_code) refCodeDisplay.innerText = user.referral_code;

    // User Health Score System (Item 5)
    const healthScore = (user.health_score !== undefined && user.health_score !== null) ? parseInt(user.health_score, 10) : 100;
    const isHealthy = healthScore > 50;
    const badgeText = `${healthScore} / 100`;

    const sellBadge = document.getElementById('sellHealthScoreBadge');
    if (sellBadge) {
        sellBadge.innerText = badgeText;
        sellBadge.className = isHealthy 
            ? "text-emerald-400 font-mono font-bold text-xs bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20"
            : "text-red-400 font-mono font-bold text-xs bg-red-500/10 px-2 py-0.5 rounded-full border border-red-500/20";
    }

    const profBadge = document.getElementById('profileHealthScoreDisplay');
    if (profBadge) {
        profBadge.innerText = badgeText;
        profBadge.className = isHealthy 
            ? "font-mono font-bold text-xs px-2.5 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20"
            : "font-mono font-bold text-xs px-2.5 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20";
    }

    const statusDisplay = document.getElementById('profileStatusDisplay');
    if (statusDisplay && user.account_status) {
        if (user.account_status === 'suspended') {
            statusDisplay.innerText = 'Suspended';
            statusDisplay.className = 'text-red-400 font-bold uppercase text-[11px] bg-red-500/10 px-2 py-0.5 rounded-full border border-red-500/20';
        } else {
            statusDisplay.innerText = 'Active Verified';
            statusDisplay.className = 'text-emerald-400 font-bold uppercase text-[11px] bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20';
        }
    }

    // Dynamic T-PIN Configuration Text (Item 7)
    const tpinHeader = document.getElementById('profileTpinHeader');
    const tpinLabel = document.getElementById('profileTpinLabel');
    const tpinBtn = document.getElementById('profileTpinBtn');
    if (user.has_tpin) {
        if (tpinHeader) tpinHeader.innerText = "Change Selling TPIN (TPIN)";
        if (tpinLabel) tpinLabel.innerText = "Change Selling TPIN (4-6 Digits)";
        if (tpinBtn) tpinBtn.innerText = "Change Selling TPIN";
    } else {
        if (tpinHeader) tpinHeader.innerText = "Selling Transaction PIN (TPIN)";
        if (tpinLabel) tpinLabel.innerText = "Set New Selling TPIN (4-6 Digits)";
        if (tpinBtn) tpinBtn.innerText = "Save Selling TPIN";
    }

    // Two-Factor Authentication UI Sync
    updateTotpUi(user);
}

function switchTab(tabId) {
    if (!currentUser) {
        openAuthModal('login');
        return;
    }

    // Aliases for smooth compatibility
    if (tabId === 'orders') tabId = 'buy';
    if (tabId === 'wallet') tabId = 'home';
    if (tabId === 'referrals') tabId = 'refer';

    currentTab = tabId;

    document.querySelectorAll('.tab-view').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('text-indigo-400', 'text-amber-400', 'font-black');
        btn.classList.add('text-slate-400');
    });

    const activeView = document.getElementById(`view-${tabId}`);
    if (activeView) activeView.classList.remove('hidden');

    const activeBtn = document.getElementById(`tabBtn-${tabId}`);
    if (activeBtn) {
        activeBtn.classList.remove('text-slate-400');
        if (tabId === 'admin') {
            activeBtn.classList.add('text-amber-400', 'font-black');
        } else {
            activeBtn.classList.add('text-indigo-400', 'font-black');
        }
    }

    // Trigger tab data load
    if (tabId === 'home') { loadWalletStats(); loadLedger(); syncHomeActiveOrder(); }
    if (tabId === 'buy') { loadBuyPool(); loadBuyPageHistory(); }
    if (tabId === 'sell') { loadWalletStats(); loadSellerDashboard(); }
    if (tabId === 'appeals') { loadDisputesCenter(); }
    if (tabId === 'refer') loadReferrals();
    if (tabId === 'profile') { loadWalletStats(); loadLedger(); }
    if (tabId === 'admin') loadAdminDashboard();
}

function periodicSync() {
    if (!currentUser) return;
    loadNotifications();
    checkPendingSellerConfirmations();
    if (currentTab === 'home') { loadWalletStats(true); syncHomeActiveOrder(true); }
    if (currentTab === 'buy') { loadBuyPool(true); loadBuyPageHistory(true); }
    if (currentTab === 'sell') { loadSellerDashboard(true); loadWalletStats(true); }
    if (currentTab === 'appeals') { loadDisputesCenter(true); }
    if (currentTab === 'refer') loadReferrals();
    if (currentTab === 'profile') loadWalletStats(true);
}

// -------------------------------------------------------------
// TAB 1: BUY LOGIC (FR-006, FR-007, FR-008)
// -------------------------------------------------------------

async function loadBuyPool(silent = false) {
    if (!currentUser) {
        renderGuestBuyPool();
        return;
    }

    try {
        const res = await fetch('/api/buy.php?action=pool');
        const data = await res.json();
        const grid = document.getElementById('buyPoolGrid');
        if (!grid) return;

        // Hide user's own sell portions from their Buy feed (prevent self-trading display)
        const visibleOrders = (data.orders || []).filter(order => {
            if (currentUser && currentUser.id && order.seller_id) {
                return parseInt(order.seller_id, 10) !== parseInt(currentUser.id, 10);
            }
            return true;
        });

        if (!data.success || visibleOrders.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <svg class="w-12 h-12 mx-auto text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    <p class="font-bold text-white text-base">No Peer Sell Orders Available</p>
                    <p class="text-xs text-slate-500 mt-1">Be the first to list tokens or specify a custom allocation above.</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = visibleOrders.map(order => `
            <div class="bg-slate-900 border border-slate-800 hover:border-slate-700/80 rounded-2xl p-5 flex flex-col justify-between transition-all duration-200 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-400">Order ${formatOrderId(order.id)}</span>
                        <span class="text-xs font-bold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                            +${order.reward_rate}% Reward
                        </span>
                    </div>

                    <div>
                        <span class="text-xs text-slate-400 block">Purchase Amount:</span>
                        <div class="text-2xl font-black text-white font-mono">₹${parseFloat(order.amount).toLocaleString('en-IN')}</div>
                    </div>

                    <div class="bg-slate-950 border border-slate-800/80 rounded-xl p-3 text-xs space-y-1">
                        <div class="flex justify-between text-slate-400">
                            <span>Base Tokens:</span>
                            <span class="text-white font-mono">${parseFloat(order.amount).toLocaleString('en-IN')}</span>
                        </div>
                        <div class="flex justify-between text-emerald-400 font-semibold">
                            <span>Promotional Reward:</span>
                            <span class="font-mono">+${parseFloat(order.reward_amount).toFixed(2)}</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-800 pt-1 text-slate-200 font-bold">
                            <span>Total Wallet Credit:</span>
                            <span class="text-indigo-400 font-mono">${parseFloat(order.total_credit).toFixed(2)}</span>
                        </div>
                    </div>

                    <div class="text-[11px] text-slate-400 flex items-center justify-between">
                        <span>Seller: <strong class="text-slate-300">${escapeHtml(order.seller_name_masked || 'Verified Peer')}</strong></span>
                        <span class="text-emerald-500 font-semibold flex items-center gap-1">&bull; Instant UPI</span>
                    </div>
                </div>

                <button onclick="pickOrder(${order.id})" class="mt-4 w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-2.5 rounded-xl transition text-xs uppercase tracking-wider shadow-md shadow-indigo-600/20">
                    Pick Order &bull; 10m Window
                </button>
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load buy pool:', e);
    }
}

function renderGuestBuyPool() {
    const grid = document.getElementById('buyPoolGrid');
    if (!grid) return;
    grid.innerHTML = `
        <div class="col-span-full bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
            <p class="font-bold text-white text-base">Sign In to View Live Market Pool</p>
            <p class="text-xs text-slate-500 mt-1">Authenticate to reserve available portions and earn 9% purchase rewards.</p>
            <button onclick="openAuthModal('login')" class="mt-4 bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-6 py-2 rounded-xl text-xs uppercase tracking-wider transition">
                Sign In Now
            </button>
        </div>
    `;
}

async function pickOrder(orderId) {
    if (!currentUser) {
        openAuthModal('login');
        return;
    }

    try {
        const res = await fetch('/api/buy.php?action=pick', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        const data = await res.json();
        if (data.success) {
            loadBuyPool();
            loadBuyPageHistory();
            syncHomeActiveOrder();
            openProofModal(data.order_id, data.amount, data.seller_upi, data.seller_name, data.seconds_remaining);
        } else {
            if (data.active_order_id) {
                showActiveOrderNotice(data.active_order_id, data.error);
                syncHomeActiveOrder();
            } else {
                alert(data.error || 'Failed to pick order.');
            }
            loadBuyPool();
        }
    } catch (e) {
        alert('Network error while reserving order.');
    }
}

// -------------------------------------------------------------
// DYNAMIC NOTIFICATION & TRANSPARENT STATUS SYSTEM (Part 7, Item 1)
// -------------------------------------------------------------

let currentActiveOrderId = null;
let activeOrderTimerInterval = null;

async function showActiveOrderNotice(orderId, errorMsg) {
    currentActiveOrderId = orderId;
    const modal = document.getElementById('activeOrderNoticeModal');
    const card = document.getElementById('activeOrderNoticeCard');
    const orderIdEl = document.getElementById('activeNoticeOrderId');
    const statusEl = document.getElementById('activeNoticeStatus');
    const amountEl = document.getElementById('activeNoticeAmount');
    const msgEl = document.getElementById('activeOrderNoticeMsg');

    if (orderIdEl) orderIdEl.innerText = formatOrderId(orderId);
    if (msgEl && errorMsg) msgEl.innerText = errorMsg;

    try {
        let ord = null;
        if (orderId) {
            try {
                const directRes = await fetch(`/api/buy.php?action=order_details&order_id=${encodeURIComponent(orderId)}`);
                if (directRes.ok) {
                    const directData = await directRes.json();
                    if (directData.success && directData.order) {
                        ord = directData.order;
                    }
                }
            } catch (_) {}
        }

        if (!ord) {
            try {
                const activeRes = await fetch('/api/buy.php?action=active_order');
                if (activeRes.ok) {
                    const activeData = await activeRes.json();
                    if (activeData.success && activeData.has_active && activeData.order) {
                        ord = activeData.order;
                    }
                }
            } catch (_) {}
        }

        if (!ord) {
            const res = await fetch('/api/buy.php?action=my_purchases');
            const data = await res.json();
            if (data.success && data.purchases && data.purchases.length > 0) {
                ord = data.purchases.find(p => parseInt(p.id, 10) === parseInt(orderId, 10)) || data.purchases[0];
            }
        }

        if (ord) {
            currentActiveOrderId = ord.id;
            if (orderIdEl) orderIdEl.innerText = formatOrderId(ord.id);
            if (statusEl) statusEl.innerText = ord.status;
            if (amountEl) amountEl.innerText = `₹${parseFloat(ord.amount).toLocaleString('en-IN')}`;
        }
    } catch (e) {
        console.warn('Could not pre-fetch notice details:', e);
    }

    if (modal && card) {
        modal.classList.remove('pointer-events-none', 'opacity-0');
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'translate-y-3');
        card.classList.add('scale-100', 'translate-y-0');
    }
}

function closeActiveOrderNotice() {
    const modal = document.getElementById('activeOrderNoticeModal');
    const card = document.getElementById('activeOrderNoticeCard');
    if (modal && card) {
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0', 'pointer-events-none');
        card.classList.remove('scale-100', 'translate-y-0');
        card.classList.add('scale-95', 'translate-y-3');
    }
}

function handleActiveNoticeReview() {
    closeActiveOrderNotice();
    if (currentActiveOrderId) {
        openActiveOrderStatusModal(currentActiveOrderId);
    } else {
        openActiveOrderStatusModal();
    }
}

async function openActiveOrderStatusModal(orderId) {
    const modal = document.getElementById('activeOrderDetailsModal');
    const card = document.getElementById('activeOrderDetailsCard');
    if (!modal || !card) return;

    try {
        let ord = null;

        // 1. Try order_details if orderId provided
        if (orderId) {
            try {
                const directRes = await fetch(`/api/buy.php?action=order_details&order_id=${encodeURIComponent(orderId)}`);
                if (directRes.ok) {
                    const directData = await directRes.json();
                    if (directData.success && directData.order) {
                        ord = directData.order;
                    }
                }
            } catch (err) {
                console.warn('Direct order fetch failed, attempting active_order fallback:', err);
            }
        }

        // 2. Try active_order endpoint
        if (!ord) {
            try {
                const activeRes = await fetch('/api/buy.php?action=active_order');
                if (activeRes.ok) {
                    const activeData = await activeRes.json();
                    if (activeData.success && activeData.has_active && activeData.order) {
                        ord = activeData.order;
                    }
                }
            } catch (err) {
                console.warn('Active order fetch failed, attempting my_purchases fallback:', err);
            }
        }

        // 3. Fallback to my_purchases
        if (!ord) {
            const res = await fetch('/api/buy.php?action=my_purchases');
            const data = await res.json();
            if (data.success && data.purchases && data.purchases.length > 0) {
                if (orderId) {
                    ord = data.purchases.find(p => parseInt(p.id, 10) === parseInt(orderId, 10));
                }
                if (!ord) {
                    ord = data.purchases[0];
                }
            }
        }

        if (!ord) {
            alert(orderId ? `Order ${formatOrderId(orderId)} not found or already settled.` : 'No active buy orders found.');
            return;
        }

        currentActiveOrderId = ord.id;
        const orderIdEl = document.getElementById('activeDetailsOrderId');
        const amountEl = document.getElementById('activeDetailsAmount');
        const rewardEl = document.getElementById('activeDetailsReward');
        const badgeEl = document.getElementById('activeDetailsStatusBadge');
        const upiEl = document.getElementById('activeDetailsSellerUpi');
        const nameEl = document.getElementById('activeDetailsSellerName');

        if (orderIdEl) orderIdEl.innerText = formatOrderId(ord.id);
        if (amountEl) amountEl.innerText = `₹${parseFloat(ord.amount).toLocaleString('en-IN')}`;

        const rewardVal = parseFloat(ord.snapshotted_reward_amount || ord.reward_amount || (ord.amount * 0.09)).toFixed(2);
        const totalVal = (parseFloat(ord.amount) + parseFloat(rewardVal)).toFixed(2);
        if (rewardEl) rewardEl.innerText = `+₹${rewardVal} (Total: ₹${totalVal})`;

        if (badgeEl) {
            badgeEl.innerText = ord.status;
            badgeEl.className = `px-2.5 py-0.5 rounded text-[11px] font-bold ${getStatusBadgeClass(ord.status)}`;
        }

        const sellerUpi = ord.seller_upi_id || ord.snapshotted_upi_id || ord.seller_upi || '--';
        const sellerName = ord.seller_account_holder || ord.snapshotted_account_holder || ord.seller_name || 'Platform Peer';

        if (upiEl) upiEl.innerText = sellerUpi;
        if (nameEl) nameEl.innerText = sellerName;

        // Timer setup
        const timerLabel = document.getElementById('activeDetailsTimerLabel');
        const timerCount = document.getElementById('activeDetailsCountdown');
        if (activeOrderTimerInterval) clearInterval(activeOrderTimerInterval);

        let remainingSecs = ord.seconds_remaining > 0 ? parseInt(ord.seconds_remaining, 10) : 0;
        if (timerCount) timerCount.innerText = formatSeconds(remainingSecs);
        if (timerLabel) timerLabel.innerText = ord.status === 'Reserved' ? 'Payment SLA' : 'Verification SLA';

        activeOrderTimerInterval = setInterval(() => {
            if (remainingSecs > 0) {
                remainingSecs--;
                if (timerCount) timerCount.innerText = formatSeconds(remainingSecs);
            } else {
                clearInterval(activeOrderTimerInterval);
            }
        }, 1000);

        // Explanatory Info & Dynamic Action Buttons (Part 7, Item 1)
        const infoBox = document.getElementById('activeDetailsInfoBox');
        const actionsBox = document.getElementById('activeDetailsActionButtons');

        if (ord.status === 'Reserved') {
            if (infoBox) {
                infoBox.innerHTML = `
                    <p class="font-semibold text-slate-200">Payment Pending:</p>
                    <p class="mt-1">Transfer exact ₹${parseFloat(ord.amount).toLocaleString('en-IN')} to the seller UPI above using PhonePe, GPay, Paytm, or BHIM. When you have completed the transfer, reopen the payment screen to submit your UTR and screenshot proof.</p>
                `;
            }
            if (actionsBox) {
                actionsBox.innerHTML = `
                    <button type="button" onclick="cancelOrderFromStatusModal(${ord.id})" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 rounded-xl transition text-xs">
                        Cancel Reservation
                    </button>
                    <button type="button" onclick="reopenActiveOrderPayment(${ord.id}, ${ord.amount}, '${escapeHtml(sellerUpi)}', '${escapeHtml(sellerName)}', ${remainingSecs})" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold py-3 rounded-xl shadow-lg shadow-emerald-600/30 transition text-xs flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Reopen Payment Screen</span>
                    </button>
                `;
            }
        } else if (['Payment Proof Submitted', 'Seller Confirmation Pending'].includes(ord.status)) {
            if (infoBox) {
                infoBox.innerHTML = `
                    <p class="font-semibold text-emerald-400">Payment Proof Submitted (UTR: ${escapeHtml(ord.utr_number || 'Under Review')}):</p>
                    <p class="mt-1">The seller has a 15-minute verification SLA to check their bank account. Once confirmed, ₹${totalVal} will be credited directly to your Available Balance.</p>
                `;
            }
            if (actionsBox) {
                actionsBox.innerHTML = `
                    <button type="button" onclick="closeActiveOrderDetailsModal()" class="w-full col-span-2 bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl transition text-xs">
                        Dismiss & Wait for Seller
                    </button>
                `;
            }
        } else if (ord.status === 'Disputed') {
            if (infoBox) {
                infoBox.innerHTML = `
                    <p class="font-semibold text-amber-400">Order Disputed & Under Admin Review:</p>
                    <p class="mt-1">This transaction is frozen under administrative arbitration. Both parties have evidence submission rights in the Appeals Center.</p>
                `;
            }
            if (actionsBox) {
                actionsBox.innerHTML = `
                    <button type="button" onclick="closeActiveOrderDetailsModal(); switchTab('appeals');" class="w-full col-span-2 bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl transition text-xs">
                        Open Appeals Center
                    </button>
                `;
            }
        } else {
            if (actionsBox) {
                actionsBox.innerHTML = `
                    <button type="button" onclick="closeActiveOrderDetailsModal()" class="w-full col-span-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold py-3 rounded-xl transition text-xs">
                        Close
                    </button>
                `;
            }
        }

        modal.classList.remove('pointer-events-none', 'opacity-0');
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'translate-y-3');
        card.classList.add('scale-100', 'translate-y-0');

    } catch (e) {
        console.error('Failed to load active order details:', e);
        alert('Could not load active order details. Please check your Buy history.');
    }
}

function closeActiveOrderDetailsModal() {
    if (activeOrderTimerInterval) clearInterval(activeOrderTimerInterval);
    const modal = document.getElementById('activeOrderDetailsModal');
    const card = document.getElementById('activeOrderDetailsCard');
    if (modal && card) {
        modal.classList.remove('opacity-100');
        modal.classList.add('opacity-0', 'pointer-events-none');
        card.classList.remove('scale-100', 'translate-y-0');
        card.classList.add('scale-95', 'translate-y-3');
    }
}

function reopenActiveOrderPayment(orderId, amount, upi, name, seconds) {
    closeActiveOrderDetailsModal();
    openProofModal(orderId, amount, upi, name, seconds);
}

async function cancelOrderFromStatusModal(orderId) {
    if (!confirm(`Cancel order reservation #${orderId}? Tokens will return to the live buy market.`)) return;
    try {
        const res = await fetch('/api/buy.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: parseInt(orderId, 10) })
        });
        const data = await res.json();
        if (data.success) {
            closeActiveOrderDetailsModal();
            loadBuyPool();
            loadBuyPageHistory();
            syncHomeActiveOrder();
            fetchSession();
            alert('Reservation cancelled. You can now select any new buy order.');
        } else {
            alert(data.error || 'Failed to cancel reservation.');
        }
    } catch (e) {
        alert('Network error cancelling reservation.');
    }
}

// -------------------------------------------------------------
// HOME DASHBOARD ACTIVE ORDER SYSTEM (Part 8, Item 4)
// -------------------------------------------------------------

let currentHomeActiveOrderId = null;
let homeActiveOrderTimerInterval = null;

async function syncHomeActiveOrder(silent = false) {
    if (!currentUser) return;
    const banner = document.getElementById('homeActiveOrderBanner');
    if (!banner) return;

    try {
        const res = await fetch('/api/buy.php?action=active_order');
        if (!res.ok) {
            banner.classList.add('hidden');
            return;
        }
        const data = await res.json();

        if (data.success && data.has_active && data.order) {
            const ord = data.order;
            currentHomeActiveOrderId = ord.id;

            const idEl = document.getElementById('homeActiveOrderId');
            const amtEl = document.getElementById('homeActiveOrderAmount');
            const badgeEl = document.getElementById('homeActiveOrderStatusBadge');
            const timerEl = document.getElementById('homeActiveOrderTimerBadge');
            const subtextEl = document.getElementById('homeActiveOrderSubtext');
            const cancelBtn = document.getElementById('homeActiveOrderCancelBtn');

            if (idEl) idEl.innerText = formatOrderId(ord.id);
            if (amtEl) amtEl.innerText = `₹${parseFloat(ord.amount).toLocaleString('en-IN')}`;

            if (badgeEl) {
                badgeEl.innerText = ord.status;
                badgeEl.className = `px-2 py-0.5 rounded text-[10px] font-bold ${getStatusBadgeClass(ord.status)}`;
            }

            if (cancelBtn) {
                if (ord.status === 'Reserved') {
                    cancelBtn.classList.remove('hidden');
                } else {
                    cancelBtn.classList.add('hidden');
                }
            }

            if (subtextEl) {
                if (ord.status === 'Reserved') {
                    subtextEl.innerText = `Transfer exact ₹${parseFloat(ord.amount).toLocaleString('en-IN')} to seller UPI (${ord.seller_upi_id || ''}) and submit proof before timer expires.`;
                } else if (['Payment Proof Submitted', 'Seller Confirmation Pending'].includes(ord.status)) {
                    subtextEl.innerText = `Payment proof submitted (UTR: ${ord.utr_number || 'Under Review'}). Seller is verifying bank receipt.`;
                } else if (ord.status === 'Disputed') {
                    subtextEl.innerText = `Order under admin arbitration. Open Appeals Center to view dispute evidence.`;
                } else {
                    subtextEl.innerText = `Order status: ${ord.status}. Click View Status to check details.`;
                }
            }

            if (homeActiveOrderTimerInterval) clearInterval(homeActiveOrderTimerInterval);
            let remaining = ord.seconds_remaining > 0 ? parseInt(ord.seconds_remaining, 10) : 0;
            if (timerEl) {
                timerEl.innerText = formatSeconds(remaining);
                if (remaining <= 60 && remaining > 0) {
                    timerEl.className = 'text-[11px] font-mono font-bold text-red-400 bg-red-950/60 px-2 py-0.5 rounded-lg border border-red-800 animate-pulse';
                } else {
                    timerEl.className = 'text-[11px] font-mono font-bold text-amber-300 bg-slate-950 px-2 py-0.5 rounded-lg border border-slate-800';
                }
            }

            homeActiveOrderTimerInterval = setInterval(() => {
                if (remaining > 0) {
                    remaining--;
                    if (timerEl) {
                        timerEl.innerText = formatSeconds(remaining);
                        if (remaining <= 60) {
                            timerEl.className = 'text-[11px] font-mono font-bold text-red-400 bg-red-950/60 px-2 py-0.5 rounded-lg border border-red-800 animate-pulse';
                        }
                    }
                } else {
                    clearInterval(homeActiveOrderTimerInterval);
                    if (ord.status === 'Reserved') {
                        if (timerEl) timerEl.innerText = '00:00 (Expired)';
                        setTimeout(() => {
                            syncHomeActiveOrder(true);
                            loadBuyPool(true);
                            loadBuyPageHistory(true);
                        }, 1000);
                    }
                }
            }, 1000);

            banner.classList.remove('hidden');
        } else {
            currentHomeActiveOrderId = null;
            if (homeActiveOrderTimerInterval) clearInterval(homeActiveOrderTimerInterval);
            banner.classList.add('hidden');
        }
    } catch (e) {
        if (!silent) console.warn('Could not sync home active order:', e);
    }
}

function handleHomeActiveOrderView() {
    if (currentHomeActiveOrderId) {
        openActiveOrderStatusModal(currentHomeActiveOrderId);
    } else {
        openActiveOrderStatusModal();
    }
}

async function cancelOrderFromHomeBanner() {
    if (!currentHomeActiveOrderId) return;
    if (!confirm(`Are you sure you want to cancel order reservation #${currentHomeActiveOrderId}? Tokens will immediately return to the live sell queue.`)) return;

    try {
        const res = await fetch('/api/buy.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: currentHomeActiveOrderId })
        });
        const data = await res.json();
        if (data.success) {
            alert('Reservation cancelled. Tokens have returned to the live sell queue.');
            syncHomeActiveOrder();
            loadBuyPool();
            loadBuyPageHistory();
            loadWalletStats();
        } else {
            alert(data.error || 'Failed to cancel reservation.');
        }
    } catch (e) {
        alert('Network error while cancelling order.');
    }
}

// -------------------------------------------------------------
// INTEGRATED SUPPORT & LIVE CHAT (Part 8, Item 3)
// -------------------------------------------------------------

let supportMenuOpen = false;

function toggleSupportMenu() {
    const menu = document.getElementById('supportActionMenu');
    if (!menu) return;
    supportMenuOpen = !supportMenuOpen;
    if (supportMenuOpen) {
        menu.classList.remove('hidden');
    } else {
        menu.classList.add('hidden');
    }
}

async function loadSupportConfig() {
    try {
        const res = await fetch('/api/support.php?action=config');
        const data = await res.json();
        if (data.success && data.telegram_support_url) {
            let tg = data.telegram_support_url;
            if (!tg.startsWith('http://') && !tg.startsWith('https://')) {
                tg = tg.startsWith('t.me/') ? ('https://' + tg) : ('https://t.me/' + tg.replace(/^[@/]+/, ''));
            }
            const tgLink = document.getElementById('supportTelegramLink');
            if (tgLink) tgLink.href = tg;
            const profTgLink = document.getElementById('profileTelegramSupportLink');
            if (profTgLink) profTgLink.href = tg;
        }
    } catch (e) {
        // Fallback default url is already in HTML
    }
}

async function requestCustomAllocation() {
    if (!currentUser) {
        openAuthModal('login');
        return;
    }

    const input = document.getElementById('customBuyAmount');
    const amount = parseFloat(input.value);

    if (isNaN(amount) || amount < 100 || amount % 100 !== 0) {
        alert('Custom amount must be at least ₹100 and an exact multiple of ₹100 (e.g. ₹500, ₹1000, ₹5000).');
        return;
    }

    try {
        const res = await fetch('/api/buy.php?action=custom_allocation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount })
        });
        const data = await res.json();
        if (data.success) {
            input.value = '';
            loadBuyPool();
            loadBuyPageHistory();
            syncHomeActiveOrder();
            openProofModal(data.order_id, data.amount, data.seller_upi, data.seller_name, data.seconds_remaining);
        } else {
            if (data.active_order_id) {
                showActiveOrderNotice(data.active_order_id, data.error);
                syncHomeActiveOrder();
            } else {
                alert(data.error || 'Allocation failed.');
            }
        }
    } catch (e) {
        alert('Network error during custom allocation.');
    }
}

// -------------------------------------------------------------
// TAB 2: SELL LOGIC (FR-011, FR-012, FR-014)
// -------------------------------------------------------------

async function submitSellOrder() {
    if (!currentUser) {
        openAuthModal('login');
        return;
    }

    const amountInput = document.getElementById('sellAmountInput');
    const holderInput = document.getElementById('sellAccountHolderName');
    const upiInput = document.getElementById('sellUpiId');
    const tpinInput = document.getElementById('sellTpinInput');

    const amount = parseFloat(amountInput.value);
    const account_holder_name = holderInput ? holderInput.value.trim() : '';
    const upi_id = upiInput ? upiInput.value.trim() : '';
    const tpin = tpinInput.value.trim();

    if (isNaN(amount) || amount < 100 || amount % 100 !== 0) {
        alert('Sell amount must be at least ₹100 and an exact multiple of ₹100 (e.g. ₹100, ₹500, ₹1000).');
        return;
    }

    if (!account_holder_name || account_holder_name.length < 2) {
        alert('Please enter your valid Account Holder Name (minimum 2 characters).');
        return;
    }

    if (!upi_id || !upi_id.includes('@')) {
        alert('Please enter a valid UPI ID (e.g. mobile@upi or username@bank).');
        return;
    }

    if (!tpin) {
        alert('Please enter your Secondary TPIN to authorize this listing.');
        return;
    }

    try {
        const res = await fetch('/api/sell.php?action=create_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount, account_holder_name, upi_id, tpin })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Success! Parent Sell Order #${data.parent_order_id} created and automatically divided into ${data.splits_count} micro-orders.`);
            amountInput.value = '';
            tpinInput.value = '';
            fetchSession();
            loadWalletStats();
            loadSellerDashboard();
        } else {
            alert(data.error || 'Failed to list sell order.');
        }
    } catch (e) {
        alert('Network error listing sell order.');
    }
}

let currentSellTab = 'running';

function switchSellTab(tab) {
    currentSellTab = tab;
    const rBtn = document.getElementById('sellTabBtn-running');
    const hBtn = document.getElementById('sellTabBtn-history');
    const rBox = document.getElementById('sellerOrdersList');
    const hBox = document.getElementById('sellerHistoryContainer');

    if (tab === 'running') {
        if (rBtn) {
            rBtn.className = 'px-4 py-2.5 text-xs font-bold border-b-2 border-indigo-500 text-white transition flex items-center gap-2';
        }
        if (hBtn) {
            hBtn.className = 'px-4 py-2.5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-2';
        }
        if (rBox) rBox.classList.remove('hidden');
        if (hBox) hBox.classList.add('hidden');
    } else {
        if (hBtn) {
            hBtn.className = 'px-4 py-2.5 text-xs font-bold border-b-2 border-indigo-500 text-white transition flex items-center gap-2';
        }
        if (rBtn) {
            rBtn.className = 'px-4 py-2.5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-2';
        }
        if (hBox) hBox.classList.remove('hidden');
        if (rBox) rBox.classList.add('hidden');
    }
}

async function loadBuyPageHistory(silent = false) {
    if (!currentUser) return;
    const container = document.getElementById('buyPageHistoryContainer');
    if (!container) return;

    if (!silent && container.innerHTML.trim() === '') {
        container.innerHTML = '<div class="text-slate-500 text-center py-6 text-xs">Loading buy history...</div>';
    }

    try {
        const res = await fetch('/api/wallet.php?action=buy_history');
        const data = await res.json();

        if (!data.success || !data.buy_history || data.buy_history.length === 0) {
            container.innerHTML = `
                <div class="text-center py-6 text-slate-400">
                    <p class="font-bold text-white text-xs">No Buy History Yet</p>
                    <p class="text-[11px] text-slate-500 mt-1">When you purchase tokens, your settlements and +9% reward bonus will appear here.</p>
                </div>
            `;
            return;
        }

        container.innerHTML = `
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800 sticky top-0 z-10">
                    <tr>
                        <th class="p-2.5 sm:p-3">Order ID</th>
                        <th class="p-2.5 sm:p-3">Purchase</th>
                        <th class="p-2.5 sm:p-3">Reward (+9%)</th>
                        <th class="p-2.5 sm:p-3">Total Credit</th>
                        <th class="p-2.5 sm:p-3">Seller Details</th>
                        <th class="p-2.5 sm:p-3">UTR / Proof</th>
                        <th class="p-2.5 sm:p-3">Status</th>
                        <th class="p-2.5 sm:p-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
                    ${data.buy_history.map(row => `
                        <tr class="hover:bg-slate-900/60 transition">
                            <td class="p-2.5 sm:p-3 text-indigo-400 font-bold">${formatOrderId(row.id)}</td>
                            <td class="p-2.5 sm:p-3 text-white font-bold">₹${parseFloat(row.amount).toLocaleString('en-IN')}</td>
                            <td class="p-2.5 sm:p-3 text-emerald-400 font-bold">+₹${parseFloat(row.reward_amount).toFixed(2)}</td>
                            <td class="p-2.5 sm:p-3 text-white font-bold">₹${parseFloat(row.total_credit).toFixed(2)}</td>
                            <td class="p-2.5 sm:p-3 font-sans text-slate-300">
                                <span>${escapeHtml(row.seller_name_masked || 'Peer')}</span>
                                <span class="text-slate-500 text-[11px] block font-mono select-all">${escapeHtml(row.snapshotted_upi_id || row.seller_upi_id || '')}</span>
                            </td>
                            <td class="p-2.5 sm:p-3">
                                ${row.utr_number ? `<span class="text-slate-200 font-mono text-[11px] block font-bold">${escapeHtml(row.utr_number)}</span>` : '<span class="text-slate-600">-</span>'}
                                ${row.screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(row.screenshot_path)}', 'Payment Proof - Order ${formatOrderId(row.id)}')" class="text-[11px] text-indigo-400 hover:text-indigo-300 underline font-sans cursor-pointer">View Proof</button>` : ''}
                            </td>
                            <td class="p-2.5 sm:p-3 font-sans">
                                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold ${getStatusBadgeClass(row.status)}">
                                    ${row.status}
                                </span>
                            </td>
                            <td class="p-2.5 sm:p-3 text-slate-500 text-[11px] font-sans">${row.created_at}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    } catch (e) {
        console.error('Failed to load buy page history:', e);
        if (!silent) {
            container.innerHTML = `
                <div class="text-center py-6 text-slate-400">
                    <p class="font-bold text-white text-xs">Could not load purchase history</p>
                    <button onclick="loadBuyPageHistory()" class="mt-2 text-indigo-400 hover:text-indigo-300 text-xs font-semibold underline">Click to Retry</button>
                </div>
            `;
        }
    }
}

async function loadSellerDashboard(silent = false) {
    if (!currentUser) return;

    try {
        const res = await fetch('/api/sell.php?action=tracking');
        const data = await res.json();
        const list = document.getElementById('sellerOrdersList');
        const historyList = document.getElementById('sellerHistoryContainer');
        if (!list) return;

        if (!data.success || !data.parent_orders || data.parent_orders.length === 0) {
            list.innerHTML = `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <p class="font-bold text-white">No Active Sell Orders</p>
                    <p class="text-xs text-slate-500 mt-1">Your listed sell orders and their child portions will appear here.</p>
                </div>
            `;
            if (historyList) {
                historyList.innerHTML = `
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                        <p class="font-bold text-white">No Listing History</p>
                        <p class="text-xs text-slate-500 mt-1">Completed, expired, or cancelled listings will appear here.</p>
                    </div>
                `;
            }
            return;
        }

        // Separate running sell orders vs listing history (Part 7, Item 2)
        const runningParents = [];
        const historyParents = [];

        data.parent_orders.forEach(parent => {
            const runningChildren = (parent.children || []).filter(c => 
                ['Available', 'Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout', 'Disputed'].includes(c.status)
            );
            const historyChildren = (parent.children || []).filter(c => 
                ['Completed', 'Expired', 'Cancelled', 'Cancelled / Reversed'].includes(c.status)
            );

            const isParentRunning = (parent.status === 'active' || (parseFloat(parent.remaining_amount) > 0 && !parent.status.includes('cancelled') && parent.status !== 'completed') || runningChildren.length > 0);

            if (isParentRunning) {
                runningParents.push({
                    ...parent,
                    children: runningChildren
                });
            }
            if (!isParentRunning || historyChildren.length > 0) {
                historyParents.push({
                    ...parent,
                    children: historyChildren
                });
            }
        });

        // Update tab badges
        const rBadge = document.getElementById('sellRunningBadge');
        const hBadge = document.getElementById('sellHistoryBadge');
        if (rBadge) rBadge.innerText = runningParents.length;
        if (hBadge) hBadge.innerText = historyParents.length;

        // Scan for pending seller actions to update bottom floating action bar (Part 5, Item 5)
        const pendingActionItems = [];
        data.parent_orders.forEach(parent => {
            (parent.children || []).forEach(child => {
                if (['Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout'].includes(child.status)) {
                    pendingActionItems.push(child);
                }
            });
        });

        const pBar = document.getElementById('sellerPendingActionBar');
        if (pBar) {
            if (pendingActionItems.length > 0) {
                const first = pendingActionItems[0];
                const tEl = document.getElementById('sellerPendingBarTitle');
                const sEl = document.getElementById('sellerPendingBarSubtitle');
                const aEl = document.getElementById('sellerPendingBarActions');
                if (tEl) tEl.innerText = `Payment Proof Received for Order ${formatOrderId(first.id)} (₹${parseFloat(first.amount).toLocaleString('en-IN')})`;
                if (sEl) sEl.innerText = `${pendingActionItems.length} order(s) awaiting your verification`;
                if (aEl) {
                    aEl.innerHTML = `
                        <button onclick="confirmPayment(${first.id})" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-bold transition shadow-sm">Confirm</button>
                        <button onclick="openAppealModal(${first.id})" class="px-3 py-1.5 bg-red-600/30 hover:bg-red-600/40 text-red-300 border border-red-500/30 rounded-lg text-xs font-bold transition">Appeal</button>
                        ${first.screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(first.screenshot_path)}', 'Buyer Payment Proof - Order ${formatOrderId(first.id)}')" class="text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold ml-1 cursor-pointer">Proof</button>` : ''}
                    `;
                }
                pBar.classList.remove('hidden');
            } else {
                pBar.classList.add('hidden');
            }
        }

        // Render 1: Running Sell Orders
        if (runningParents.length === 0) {
            list.innerHTML = `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <svg class="w-12 h-12 mx-auto text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="font-bold text-white text-base">No Running Sell Orders</p>
                    <p class="text-xs text-slate-500 mt-1">You have no active listings running in the market. Create a sell order on the left to start selling.</p>
                </div>
            `;
        } else {
            list.innerHTML = runningParents.map(parent => `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-5 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-2.5 gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-mono font-bold text-indigo-400">Parent ${formatOrderId(parent.id)}</span>
                                <span class="text-[10px] px-2 py-0.5 rounded uppercase font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active Running</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-0.5">UPI: <span class="font-mono text-slate-200">${escapeHtml(parent.snapshotted_upi_id)}</span></p>
                        </div>
                        <div class="flex items-center gap-3 sm:gap-4 text-xs font-mono">
                            <div><span class="text-slate-500 block text-[10px]">Total</span> <strong class="text-white">₹${parseFloat(parent.original_amount).toLocaleString('en-IN')}</strong></div>
                            <div><span class="text-slate-500 block text-[10px]">Completed</span> <strong class="text-emerald-400">₹${parseFloat(parent.completed_amount).toLocaleString('en-IN')}</strong></div>
                            <div><span class="text-slate-500 block text-[10px]">Remaining</span> <strong class="text-slate-300">₹${parseFloat(parent.remaining_amount).toLocaleString('en-IN')}</strong></div>
                            ${(parent.status === 'active' || (parseFloat(parent.remaining_amount) > 0 && !parent.status.includes('cancelled') && parent.status !== 'completed')) ? `
                                <button onclick="cancelParentSellOrder(${parent.id})" class="ml-1 sm:ml-2 px-2.5 py-1 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/30 rounded-lg text-xs font-bold transition flex items-center gap-1 shadow-sm">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <span>Cancel</span>
                                </button>
                            ` : ''}
                        </div>
                    </div>

                    <!-- Child portions list -->
                    <div class="space-y-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Active Micro-Portions (${parent.children.length})</span>
                        <div class="grid grid-cols-1 gap-2">
                            ${parent.children.length > 0 ? parent.children.map(child => renderSellerChildCard(child)).join('') : '<span class="text-xs text-slate-500 italic">All portions resolved or unlisted.</span>'}
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // Render 2: Dedicated Sell Listing History
        if (historyList) {
            if (historyParents.length === 0) {
                historyList.innerHTML = `
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                        <p class="font-bold text-white text-base">No Completed Listing History</p>
                        <p class="text-xs text-slate-500 mt-1">Past completed, expired, or cancelled listings will appear here.</p>
                    </div>
                `;
            } else {
                historyList.innerHTML = historyParents.map(parent => `
                    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 sm:p-5 space-y-3 opacity-90">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-2.5 gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono font-bold text-indigo-400">Parent ${formatOrderId(parent.id)}</span>
                                    <span class="text-[10px] px-2 py-0.5 rounded uppercase font-bold ${parent.status === 'completed' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-slate-800 text-slate-400'}">${parent.status}</span>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-0.5">Listed: ${parent.created_at || 'Recorded'}</p>
                            </div>
                            <div class="flex items-center gap-3 sm:gap-4 text-xs font-mono">
                                <div><span class="text-slate-500 block text-[10px]">Total</span> <strong class="text-white">₹${parseFloat(parent.original_amount).toLocaleString('en-IN')}</strong></div>
                                <div><span class="text-slate-500 block text-[10px]">Completed</span> <strong class="text-emerald-400">₹${parseFloat(parent.completed_amount).toLocaleString('en-IN')}</strong></div>
                                <div><span class="text-slate-500 block text-[10px]">Remaining</span> <strong class="text-slate-400">₹${parseFloat(parent.remaining_amount).toLocaleString('en-IN')}</strong></div>
                            </div>
                        </div>

                        <!-- Historical child portions -->
                        <div class="space-y-1.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 block">Settled / Expired Portions (${parent.children.length})</span>
                            <div class="grid grid-cols-1 gap-2">
                                ${parent.children.map(child => renderSellerChildCard(child)).join('')}
                            </div>
                        </div>
                    </div>
                `).join('');
            }
        }
    } catch (e) {
        console.error('Failed to load seller tracking:', e);
    }
}

function renderSellerChildCard(child) {
    const statusBadges = {
        'Available': 'bg-slate-800 text-slate-400',
        'Reserved': 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
        'Payment Proof Submitted': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
        'Seller Confirmation Pending': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
        'Seller Response Timeout': 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
        'Disputed': 'bg-red-500/20 text-red-400 border border-red-500/30',
        'Completed': 'bg-emerald-500/10 text-emerald-400',
        'Expired': 'bg-slate-800 text-slate-500',
        'Cancelled': 'bg-red-500/10 text-red-400',
        'Cancelled / Reversed': 'bg-red-500/10 text-red-400'
    };

    let actions = '';
    const pendingVerification = ['Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout'].includes(child.status);

    if (pendingVerification) {
        actions = `
            <div class="flex items-center gap-2 mt-2 pt-2 border-t border-slate-800">
                <button onclick="confirmPayment(${child.id})" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-bold transition">
                    Confirm Payment Received
                </button>
                <button onclick="openAppealModal(${child.id})" class="px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 border border-red-500/30 rounded-lg text-xs font-bold transition">
                    Appeal (Not Received)
                </button>
                ${child.screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(child.screenshot_path)}', 'Buyer Proof - Order ${formatOrderId(child.id)}')" class="text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold ml-auto cursor-pointer">View Buyer Proof</button>` : ''}
            </div>
        `;
    } else if (child.status === 'Available') {
        actions = `
            <div class="flex items-center justify-between mt-2 pt-2 border-t border-slate-800/80">
                <span class="text-[11px] text-slate-500">Unsold in Buy Pool</span>
                <button onclick="cancelChildSellPortion(${child.id})" class="px-2.5 py-1 bg-red-600/15 hover:bg-red-600/25 text-red-400 border border-red-500/25 rounded-lg text-[11px] font-bold transition flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    <span>Cancel</span>
                </button>
            </div>
        `;
    }

    // Register countdown timer
    if (child.seconds_remaining > 0) {
        activeTimers[`child_${child.id}`] = {
            seconds: child.seconds_remaining,
            label: child.status === 'Reserved' ? 'Buyer Pay Time' : 'Verification SLA'
        };
    }

    // Sell Timer (Part 5, Item 5)
    let elapsedSecs = 0;
    if (child.created_at) {
        const createdUtc = new Date(child.created_at.replace(' ', 'T') + 'Z').getTime();
        elapsedSecs = Math.max(0, Math.floor((Date.now() - createdUtc) / 1000));
        activeElapsedTimers[`child_${child.id}`] = elapsedSecs;
    }

    return `
        <div class="bg-slate-950/70 border border-slate-800/80 rounded-xl p-3 text-xs">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-mono font-bold text-slate-400">${formatOrderId(child.id)}</span>
                    <span class="font-bold text-white font-mono">₹${parseFloat(child.amount).toLocaleString('en-IN')}</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded ${statusBadges[child.status] || 'bg-slate-800 text-slate-400'}">${child.status}</span>
                </div>
                <div class="text-right">
                    ${child.seconds_remaining > 0 ? `
                        <span class="font-mono font-bold text-amber-400" id="timer_child_${child.id}">
                            ${formatSeconds(child.seconds_remaining)}
                        </span>
                    ` : `
                        <span class="text-[10px] text-slate-500 font-mono" id="elapsed_child_${child.id}">
                            On Sale: ${formatElapsed(elapsedSecs)}
                        </span>
                    `}
                </div>
            </div>

            ${(child.buyer_id || !['Available', 'Cancelled'].includes(child.status)) ? `
                <div class="text-[11px] text-slate-400 mt-1 flex items-center justify-between">
                    <span>Buyer: <strong class="text-indigo-400 font-mono">Order ${formatOrderId(child.id)}</strong></span>
                    ${child.utr_number ? `<span>UTR: <strong class="text-slate-200 font-mono">${escapeHtml(child.utr_number)}</strong></span>` : ''}
                </div>
            ` : ''}

            ${actions}
        </div>
    `;
}

async function cancelParentSellOrder(parentId) {
    if (!confirm(`Are you sure you want to cancel Sell Listing #${parentId}?\n\nAll unsold tokens will be immediately unlocked and credited back directly to your Available Wallet Balance.`)) {
        return;
    }

    try {
        const res = await fetch('/api/sell.php?action=cancel_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ parent_order_id: parentId })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || `Listing #${parentId} cancelled. Tokens refunded to your Available Balance.`);
            fetchSession();
            loadWalletStats();
            loadSellerDashboard();
            loadBuyPool();
        } else {
            alert(data.error || 'Failed to cancel sell listing.');
        }
    } catch (e) {
        alert('Network error cancelling sell listing.');
    }
}

async function cancelChildSellPortion(childId) {
    if (!confirm(`Are you sure you want to cancel this unsold portion (#${childId})?\n\nIts tokens will be released back to your Available Wallet Balance.`)) {
        return;
    }

    try {
        const res = await fetch('/api/sell.php?action=cancel_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ child_order_id: childId })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || `Portion #${childId} cancelled. Tokens refunded to your Available Balance.`);
            fetchSession();
            loadWalletStats();
            loadSellerDashboard();
            loadBuyPool();
        } else {
            alert(data.error || 'Failed to cancel sell portion.');
        }
    } catch (e) {
        alert('Network error cancelling sell portion.');
    }
}

async function confirmPayment(orderId) {
    if (!confirm(`Are you sure you have received payment in your bank for order #${orderId}? This will immediately release the tokens to the buyer.`)) {
        return;
    }

    // Idempotency key to prevent double clicks (FR-015, QA-007)
    const idempotencyKey = `PAY_${orderId}_${Date.now()}`;

    try {
        const res = await fetch('/api/sell.php?action=confirm_payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, idempotency_key: idempotencyKey })
        });
        const data = await res.json();
        if (data.success) {
            alert('Settlement confirmed! Reserved tokens transferred and buyer credited successfully.');
            fetchSession();
            loadWalletStats();
            loadSellerDashboard();
        } else {
            alert(data.error || 'Settlement failed.');
        }
    } catch (e) {
        alert('Network error confirming payment.');
    }
}

// -------------------------------------------------------------
// TAB 3: BUYER PURCHASES & PROOFS (FR-009, FR-010)
// -------------------------------------------------------------

async function loadMyPurchases(silent = false) {
    if (!currentUser) return;

    try {
        const res = await fetch('/api/buy.php?action=my_purchases');
        const data = await res.json();
        const list = document.getElementById('myPurchasesList');
        if (!list) return;

        if (!data.success || !data.purchases || data.purchases.length === 0) {
            list.innerHTML = `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <p class="font-bold text-white">No Purchases Found</p>
                    <p class="text-xs text-slate-500 mt-1">Pick a portion from the Buy Pool to initiate a token purchase.</p>
                </div>
            `;
            return;
        }

        list.innerHTML = data.purchases.map(p => {
            if (p.seconds_remaining > 0) {
                activeTimers[`order_${p.id}`] = {
                    seconds: p.seconds_remaining,
                    label: p.status === 'Reserved' ? 'Payment Window' : 'Seller Verification'
                };
            }

            return `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-3">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-mono font-bold text-indigo-400">Order #${p.id}</span>
                            <span class="text-xs px-2.5 py-0.5 rounded-full font-bold uppercase tracking-wider ${p.status === 'Completed' ? 'bg-emerald-500/10 text-emerald-400' : (p.status === 'Disputed' ? 'bg-red-500/10 text-red-400' : 'bg-amber-500/10 text-amber-400')}">
                                ${p.status}
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="text-xl font-black text-white font-mono">₹${parseFloat(p.amount).toLocaleString('en-IN')}</span>
                            <span class="text-xs text-emerald-400 font-semibold block">+9% Reward Credit: ₹${parseFloat(p.total_credit).toFixed(2)}</span>
                        </div>
                    </div>

                    <!-- Payment instructions & UPI display -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-slate-950 border border-slate-800/80 rounded-xl p-4 text-xs">
                        <div class="space-y-1">
                            <span class="text-slate-400 font-semibold block">Seller Payment Details:</span>
                            <div class="text-slate-200">Name: <strong class="text-white">${escapeHtml(p.snapshotted_account_holder)}</strong></div>
                            <div class="text-slate-200 flex items-center gap-2">
                                <span>UPI ID: <strong class="text-emerald-400 font-mono select-all">${escapeHtml(p.snapshotted_upi_id)}</strong></span>
                                <button onclick="copyToClipboard('${escapeHtml(p.snapshotted_upi_id)}')" class="text-[10px] bg-slate-800 hover:bg-slate-700 px-1.5 py-0.5 rounded text-slate-300">Copy</button>
                            </div>
                        </div>

                        <div class="flex flex-col justify-center sm:items-end">
                            ${p.status === 'Reserved' ? `
                                <div class="text-right mb-2">
                                    <span class="text-slate-400 text-[11px] block">Payment Deadline (10m):</span>
                                    <span class="text-base font-bold font-mono text-amber-400" id="timer_order_${p.id}">
                                        ${formatSeconds(p.seconds_remaining)}
                                    </span>
                                </div>
                                <button onclick="openProofModal(${p.id}, ${p.amount}, '${escapeHtml(p.snapshotted_upi_id)}', '${escapeHtml(p.snapshotted_account_holder)}', ${p.seconds_remaining || 600})" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-emerald-600/30">
                                    Submit UPI Proof
                                </button>
                            ` : (p.status === 'Seller Confirmation Pending' ? `
                                <div class="text-right space-y-1">
                                    <div class="flex items-center gap-1.5 sm:justify-end text-xs font-bold text-indigo-400">
                                        <span class="inline-block w-2 h-2 rounded-full bg-indigo-400 animate-ping"></span>
                                        <span>Already Verified / Verifying</span>
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        Seller SLA: <span class="font-mono font-bold text-indigo-300 ml-1" id="timer_order_${p.id}">${formatSeconds(p.seconds_remaining)}</span>
                                    </div>
                                    <p class="text-[10px] text-slate-500 max-w-xs sm:text-right">
                                        Auto-escalates to Appeal/Dispute if seller does not verify within 15 mins.
                                    </p>
                                    ${p.utr_number ? `<span class="text-[11px] text-slate-400 block font-mono">UTR: ${escapeHtml(p.utr_number)}</span>` : ''}
                                </div>
                            ` : (p.status === 'Disputed' ? `
                                <div class="text-right space-y-1">
                                    <div class="text-red-400 font-bold text-xs flex items-center gap-1 sm:justify-end">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span>Under Appeal / Arbitration</span>
                                    </div>
                                    <p class="text-[10px] text-slate-400 max-w-xs sm:text-right">Escalated to Admin Console. Tokens safely locked in escrow.</p>
                                </div>
                            ` : (p.status === 'Completed' ? `
                                <div class="text-emerald-400 font-bold text-xs flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>Settled to Wallet</span>
                                </div>
                            ` : `
                                <span class="text-slate-500 text-xs">Status: ${p.status}</span>
                            `)))}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load purchases:', e);
    }
}

function openProofModal(orderId, amount, sellerUpi, sellerName, secondsRemaining = 600) {
    document.getElementById('proofModalOrderId').innerText = orderId;
    document.getElementById('proofOrderIdInput').value = orderId;
    document.getElementById('proofModalAmount').innerText = `₹${parseFloat(amount).toLocaleString('en-IN')}`;
    document.getElementById('proofModalSellerUpi').innerText = sellerUpi;
    document.getElementById('proofModalSellerName').innerText = sellerName;
    document.getElementById('proofUtrInput').value = '';
    document.getElementById('proofScreenshotPath').value = '';
    const fileInput = document.getElementById('proofFileInput');
    if (fileInput) fileInput.value = '';
    document.getElementById('uploadStatusText').innerText = 'Select clear screenshots showing UTR and success status';
    document.getElementById('proofError').classList.add('hidden');

    proofModalSeconds = secondsRemaining > 0 ? secondsRemaining : 600;
    const cd = document.getElementById('proofModalCountdown');
    if (cd) cd.innerText = formatSeconds(proofModalSeconds);

    document.getElementById('proofModal').classList.remove('hidden');
}

function closeProofModal() {
    document.getElementById('proofModal').classList.add('hidden');
}

async function cancelCurrentReservation() {
    const orderId = document.getElementById('proofOrderIdInput').value;
    if (!orderId) {
        closeProofModal();
        return;
    }
    if (!confirm(`Are you sure you want to cancel order reservation #${orderId}? Tokens will be released back to the market pool.`)) {
        return;
    }
    try {
        const res = await fetch('/api/buy.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: parseInt(orderId, 10) })
        });
        const data = await res.json();
        if (data.success) {
            alert('Reservation cancelled. The order portion is back in the market pool.');
            closeProofModal();
            loadMyPurchases();
            loadBuyPool();
            loadBuyPageHistory();
            syncHomeActiveOrder();
            fetchSession();
        } else {
            alert(data.error || 'Failed to cancel reservation.');
        }
    } catch (e) {
        alert('Network error cancelling reservation.');
    }
}

async function handleFileUpload(fileInput, type) {
    if (!fileInput.files || fileInput.files.length === 0) return;

    const formData = new FormData();
    for (let i = 0; i < fileInput.files.length; i++) {
        formData.append('files[]', fileInput.files[i]);
    }
    formData.append('file', fileInput.files[0]);
    formData.append('type', type);

    const statusEl = (type === 'evidence') ? document.getElementById('appealUploadStatusText') : document.getElementById('uploadStatusText');
    if (statusEl) statusEl.innerHTML = `<span class="text-indigo-400 font-semibold">Uploading ${fileInput.files.length} encrypted file(s)...</span>`;

    try {
        const res = await fetch('/api/uploads.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            const finalPath = data.file_path || (data.file_paths ? data.file_paths.join(',') : '');
            if (type === 'evidence') {
                document.getElementById('appealEvidencePath').value = finalPath;
            } else {
                document.getElementById('proofScreenshotPath').value = finalPath;
            }
            if (statusEl) statusEl.innerHTML = `<span class="text-emerald-400 font-semibold">✓ ${fileInput.files.length} file(s) uploaded successfully</span>`;
        } else {
            alert(data.error || 'Upload failed.');
            if (statusEl) statusEl.innerText = 'Upload failed. Please try again.';
        }
    } catch (e) {
        alert('Network upload error.');
    }
}

async function handleProofSubmit(e) {
    e.preventDefault();
    const orderId = document.getElementById('proofOrderIdInput').value;
    const utr = document.getElementById('proofUtrInput').value.trim();
    const screenshotPath = document.getElementById('proofScreenshotPath').value;
    const errEl = document.getElementById('proofError');

    if (!screenshotPath) {
        errEl.innerText = 'Please select and upload a payment screenshot.';
        errEl.classList.remove('hidden');
        return;
    }

    try {
        const res = await fetch('/api/buy.php?action=submit_proof', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, utr, screenshot_path: screenshotPath })
        });
        const data = await res.json();
        if (data.success) {
            alert('Payment proof submitted successfully! The seller has been notified for verification.');
            closeProofModal();
            loadMyPurchases();
            loadBuyPageHistory();
            syncHomeActiveOrder();
        } else {
            errEl.innerText = data.error || 'Submission failed.';
            errEl.classList.remove('hidden');
        }
    } catch (e) {
        errEl.innerText = 'Network error during submission.';
        errEl.classList.remove('hidden');
    }
}

// -------------------------------------------------------------
// DISPUTE / APPEAL MODAL (FR-016)
// -------------------------------------------------------------

function openAppealModal(orderId) {
    document.getElementById('appealOrderId').innerText = orderId;
    document.getElementById('appealOrderIdInput').value = orderId;
    document.getElementById('appealReasonInput').value = '';
    document.getElementById('appealEvidencePath').value = '';
    document.getElementById('appealError').classList.add('hidden');
    document.getElementById('appealModal').classList.remove('hidden');
}

function closeAppealModal() {
    document.getElementById('appealModal').classList.add('hidden');
}

async function handleAppealSubmit(e) {
    e.preventDefault();
    const orderId = document.getElementById('appealOrderIdInput').value;
    const reason = document.getElementById('appealReasonInput').value.trim();
    const evidencePath = document.getElementById('appealEvidencePath').value;
    const errEl = document.getElementById('appealError');

    if (!reason) {
        errEl.innerText = 'Please provide a clear reason for the dispute.';
        errEl.classList.remove('hidden');
        return;
    }

    try {
        const res = await fetch('/api/sell.php?action=raise_appeal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId, reason, evidence_path: evidencePath })
        });
        const data = await res.json();
        if (data.success) {
            alert('Appeal filed! Order is now frozen and forwarded to Admin Arbitration Console.');
            closeAppealModal();
            loadSellerDashboard();
        } else {
            errEl.innerText = data.error || 'Failed to file appeal.';
            errEl.classList.remove('hidden');
        }
    } catch (e) {
        errEl.innerText = 'Network error filing appeal.';
        errEl.classList.remove('hidden');
    }
}

// -------------------------------------------------------------
// TAB: WALLET STATS, LEDGER & TARGETS (FR-003, FR-004, FR-005)
// -------------------------------------------------------------

async function loadWalletStats(silent = false) {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/wallet.php?action=summary');
        const data = await res.json();
        if (!data.success || !data.wallet) return;

        const w = data.wallet;
        const availFormatted = `₹${parseFloat(w.available_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        const resvFormatted = `₹${parseFloat(w.reserved_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        const rewFormatted = `₹${parseFloat(w.total_rewards).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        const commFormatted = `₹${parseFloat(w.total_commissions).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        const volFormatted = `₹${parseFloat(w.completed_sell_volume).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        const pctFormatted = `${parseFloat(w.weekly_progress_percent).toFixed(1)}%`;
        const pctVal = `${Math.min(100, parseFloat(w.weekly_progress_percent))}%`;

        // Home View elements
        const hAvail = document.getElementById('homeStatAvailable');
        if (hAvail) {
            hAvail.innerText = availFormatted;
            hAvail.title = availFormatted;
        }

        const hResv = document.getElementById('homeStatReserved');
        if (hResv) {
            hResv.innerText = resvFormatted;
            hResv.title = resvFormatted;
        }

        const hRew = document.getElementById('homeStatRewards');
        if (hRew) {
            hRew.innerText = rewFormatted;
            hRew.title = rewFormatted;
        }

        const hComm = document.getElementById('homeStatCommissions');
        if (hComm) {
            hComm.innerText = commFormatted;
            hComm.title = commFormatted;
        }

        const hPct = document.getElementById('homeWeeklyProgressPct');
        if (hPct) hPct.innerText = pctFormatted;

        const hBar = document.getElementById('homeWeeklyProgressBar');
        if (hBar) hBar.style.width = pctVal;

        const hVol = document.getElementById('homeStatCompletedVol');
        if (hVol) hVol.innerText = volFormatted;

        // Sell View available balance indicator
        const sAvail = document.getElementById('sellFormAvailableBalance');
        if (sAvail) sAvail.innerText = availFormatted;

        // Legacy / Generic IDs if present
        if (document.getElementById('statAvailable')) document.getElementById('statAvailable').innerText = availFormatted;
        if (document.getElementById('statReserved')) document.getElementById('statReserved').innerText = resvFormatted;
        if (document.getElementById('statRewards')) document.getElementById('statRewards').innerText = rewFormatted;
        if (document.getElementById('statCommissions')) document.getElementById('statCommissions').innerText = commFormatted;
        if (document.getElementById('weeklyProgressPct')) document.getElementById('weeklyProgressPct').innerText = pctFormatted;
        if (document.getElementById('weeklyProgressBar')) document.getElementById('weeklyProgressBar').style.width = pctVal;
        if (document.getElementById('statCompletedVol')) document.getElementById('statCompletedVol').innerText = volFormatted;

        updateHeaderBalances(w);
    } catch (e) {
        console.error('Failed to load wallet stats:', e);
    }
}

async function loadLedger() {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/wallet.php?action=ledger&limit=50');
        const data = await res.json();
        const homeBody = document.getElementById('homeLedgerTableBody');
        const profileBody = document.getElementById('profileLedgerTableBody');
        const modalBody = document.getElementById('modalLedgerTableBody');
        const fallbackBody = document.getElementById('ledgerTableBody');

        if (!data.success || !data.ledger || data.ledger.length === 0) {
            const emptyRowHome = `<tr><td colspan="6" class="p-6 text-center text-slate-500 font-sans">No wallet transactions recorded yet.</td></tr>`;
            const emptyRowProfile = `<tr><td colspan="7" class="p-6 text-center text-slate-500 font-sans">No wallet audit records found.</td></tr>`;
            if (homeBody) homeBody.innerHTML = emptyRowHome;
            if (profileBody) profileBody.innerHTML = emptyRowProfile;
            if (modalBody) modalBody.innerHTML = emptyRowHome;
            if (fallbackBody) fallbackBody.innerHTML = emptyRowHome;
            return;
        }

        const typeColors = {
            'BUY_CREDIT': 'text-emerald-400 bg-emerald-500/10',
            'BUY_REWARD': 'text-emerald-300 bg-emerald-500/10',
            'SELL_RESERVE': 'text-amber-400 bg-amber-500/10',
            'SELL_SETTLE': 'text-slate-300 bg-slate-800',
            'SELL_RELEASE': 'text-indigo-400 bg-indigo-500/10',
            'REFERRAL_L1': 'text-violet-400 bg-violet-500/10',
            'REFERRAL_L2': 'text-violet-300 bg-violet-500/10',
            'ADMIN_CREDIT': 'text-blue-400 bg-blue-500/10',
            'ADMIN_DEBIT': 'text-red-400 bg-red-500/10',
            'INITIAL_DEPOSIT': 'text-slate-400 bg-slate-800'
        };

        const homeHtml = data.ledger.map(row => `
            <tr class="hover:bg-slate-900/60">
                <td class="p-3 text-slate-500">#${row.id}</td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold ${typeColors[row.transaction_type] || 'text-slate-400 bg-slate-800'}">
                        ${row.transaction_type}
                    </span>
                </td>
                <td class="p-3 font-bold text-white">₹${parseFloat(row.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                <td class="p-3 text-slate-400">
                    ₹${parseFloat(row.previous_balance).toFixed(2)} &rarr; <span class="text-slate-200">₹${parseFloat(row.new_balance).toFixed(2)}</span>
                </td>
                <td class="p-3 text-slate-400 truncate max-w-xs font-sans text-[11px]">${escapeHtml(row.notes || row.reference_id || '-')}</td>
                <td class="p-3 text-slate-500 text-[11px]">${row.created_at}</td>
            </tr>
        `).join('');

        const profileHtml = data.ledger.map(row => `
            <tr class="hover:bg-slate-900/60">
                <td class="p-3 text-slate-500">#${row.id}</td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold ${typeColors[row.transaction_type] || 'text-slate-400 bg-slate-800'}">
                        ${row.transaction_type}
                    </span>
                </td>
                <td class="p-3 font-bold text-white">₹${parseFloat(row.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                <td class="p-3 text-slate-400 font-mono">₹${parseFloat(row.previous_balance).toFixed(2)}</td>
                <td class="p-3 text-emerald-400 font-mono font-bold">₹${parseFloat(row.new_balance).toFixed(2)}</td>
                <td class="p-3 text-slate-400 truncate max-w-xs font-sans text-[11px]">${escapeHtml(row.notes || row.reference_id || '-')}</td>
                <td class="p-3 text-slate-500 text-[11px]">${row.created_at}</td>
            </tr>
        `).join('');

        if (homeBody) homeBody.innerHTML = homeHtml;
        if (profileBody) profileBody.innerHTML = profileHtml;
        if (modalBody) modalBody.innerHTML = homeHtml;
        if (fallbackBody) fallbackBody.innerHTML = homeHtml;
    } catch (e) {
        console.error('Failed to load ledger:', e);
    }
}

function openHistoryModal(defaultTab = 'buy') {
    const modal = document.getElementById('historyModal');
    if (modal) modal.classList.remove('hidden');
    switchHistoryTab(defaultTab);
}

function closeHistoryModal() {
    const modal = document.getElementById('historyModal');
    if (modal) modal.classList.add('hidden');
}

function switchHistoryTab(tab) {
    currentHistoryTab = tab;

    const tabs = ['buy', 'sell', 'ledger'];
    tabs.forEach(t => {
        const btn = document.getElementById(`historyTabBtn-${t}`);
        const view = document.getElementById(`historyView-${t}`);
        if (btn) {
            if (t === tab) {
                btn.className = 'px-3 sm:px-4 py-2 text-xs font-bold border-b-2 border-indigo-500 text-white transition flex items-center gap-1.5';
            } else {
                btn.className = 'px-3 sm:px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-white transition flex items-center gap-1.5';
            }
        }
        if (view) {
            if (t === tab) view.classList.remove('hidden');
            else view.classList.add('hidden');
        }
    });

    refreshCurrentHistoryTab();
}

function refreshCurrentHistoryTab() {
    if (currentHistoryTab === 'buy') loadBuyHistory();
    else if (currentHistoryTab === 'sell') loadSellHistory();
    else if (currentHistoryTab === 'ledger') loadLedger();
}

async function loadBuyHistory() {
    try {
        const res = await fetch('/api/wallet.php?action=buy_history');
        const data = await res.json();
        const container = document.getElementById('historyView-buy');
        if (!container) return;

        if (!data.success || !data.buy_history || data.buy_history.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <p class="font-bold text-white">No Buy Purchases Found</p>
                    <p class="text-xs text-slate-500 mt-1">Pick an order in the Buy section to start purchasing tokens with +9% rewards.</p>
                </div>
            `;
            return;
        }

        const statusBadges = {
            'Completed': 'bg-emerald-500/10 text-emerald-400',
            'Reserved': 'bg-amber-500/10 text-amber-400',
            'Payment Proof Submitted': 'bg-indigo-500/10 text-indigo-400',
            'Seller Confirmation Pending': 'bg-indigo-500/10 text-indigo-400',
            'Disputed': 'bg-red-500/10 text-red-400',
            'Expired': 'bg-slate-800 text-slate-500',
            'Cancelled': 'bg-red-500/10 text-red-400'
        };

        container.innerHTML = `
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800 sticky top-0">
                    <tr>
                        <th class="p-3">Order</th>
                        <th class="p-3">Purchase</th>
                        <th class="p-3">Reward (+9%)</th>
                        <th class="p-3">Total Credit</th>
                        <th class="p-3">Seller Details</th>
                        <th class="p-3">UTR / Proof</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
                    ${data.buy_history.map(row => `
                        <tr class="hover:bg-slate-900/60">
                            <td class="p-3 text-indigo-400 font-bold">${formatOrderId(row.id)}</td>
                            <td class="p-3 text-white font-bold">₹${parseFloat(row.amount).toLocaleString('en-IN')}</td>
                            <td class="p-3 text-emerald-400">+₹${parseFloat(row.reward_amount).toFixed(2)}</td>
                            <td class="p-3 text-white">₹${parseFloat(row.total_credit).toFixed(2)}</td>
                            <td class="p-3 font-sans text-slate-300">${escapeHtml(row.seller_name_masked || 'Peer')} <span class="text-slate-500 text-[11px] block font-mono">${escapeHtml(row.snapshotted_upi_id)}</span></td>
                            <td class="p-3">
                                ${row.utr_number ? `<span class="text-slate-200 font-mono text-[11px] block">${escapeHtml(row.utr_number)}</span>` : '<span class="text-slate-600">-</span>'}
                                ${row.screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(row.screenshot_path)}', 'Payment Proof - Order ${formatOrderId(row.id)}')" class="text-[11px] text-indigo-400 hover:text-indigo-300 underline font-sans cursor-pointer">View Proof</button>` : ''}
                            </td>
                            <td class="p-3 font-sans">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${statusBadges[row.status] || 'bg-slate-800 text-slate-400'}">
                                    ${row.status}
                                </span>
                            </td>
                            <td class="p-3 text-slate-500 text-[11px]">${row.created_at}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    } catch (e) {
        console.error('Failed to load buy history:', e);
    }
}

async function loadSellHistory() {
    try {
        const res = await fetch('/api/wallet.php?action=sell_history');
        const data = await res.json();
        const container = document.getElementById('historyView-sell');
        if (!container) return;

        if (!data.success || !data.sell_history || data.sell_history.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <p class="font-bold text-white">No Sell History Found</p>
                    <p class="text-xs text-slate-500 mt-1">Tokens listed for sale will appear here once created.</p>
                </div>
            `;
            return;
        }

        const statusBadges = {
            'Completed': 'bg-emerald-500/10 text-emerald-400',
            'Available': 'bg-slate-800 text-slate-400',
            'Reserved': 'bg-amber-500/10 text-amber-400',
            'Payment Proof Submitted': 'bg-indigo-500/10 text-indigo-400',
            'Seller Confirmation Pending': 'bg-indigo-500/10 text-indigo-400',
            'Disputed': 'bg-red-500/10 text-red-400',
            'Expired': 'bg-slate-800 text-slate-500',
            'Cancelled': 'bg-red-500/10 text-red-400'
        };

        container.innerHTML = `
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase tracking-wider font-semibold border-b border-slate-800 sticky top-0">
                    <tr>
                        <th class="p-3">Portion</th>
                        <th class="p-3">Parent Listing</th>
                        <th class="p-3">Amount</th>
                        <th class="p-3">Buyer Order</th>
                        <th class="p-3">UTR / Receipt</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
                    ${data.sell_history.map(row => `
                        <tr class="hover:bg-slate-900/60">
                            <td class="p-3 text-indigo-400 font-bold">${formatOrderId(row.id)}</td>
                            <td class="p-3 text-slate-400">Parent ${formatOrderId(row.parent_order_id)}</td>
                            <td class="p-3 text-white font-bold">₹${parseFloat(row.amount).toLocaleString('en-IN')}</td>
                            <td class="p-3 font-mono text-indigo-300">${row.status !== 'Available' ? formatOrderId(row.id) : '<span class="text-slate-500 font-sans">Unsold</span>'}</td>
                            <td class="p-3">
                                ${row.utr_number ? `<span class="text-slate-200 font-mono text-[11px] block">${escapeHtml(row.utr_number)}</span>` : '<span class="text-slate-600">-</span>'}
                                ${row.screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(row.screenshot_path)}', 'Payment Proof - Order ${formatOrderId(row.id)}')" class="text-[11px] text-indigo-400 hover:text-indigo-300 underline font-sans cursor-pointer">View Proof</button>` : ''}
                            </td>
                            <td class="p-3 font-sans">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${statusBadges[row.status] || 'bg-slate-800 text-slate-400'}">
                                    ${row.status}
                                </span>
                            </td>
                            <td class="p-3 text-slate-500 text-[11px]">${row.created_at}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    } catch (e) {
        console.error('Failed to load sell history:', e);
    }
}

// -------------------------------------------------------------
// DEDICATED DISPUTE & APPEAL CENTER (Part 5, Item 1)
// -------------------------------------------------------------

async function loadDisputesCenter(silent = false) {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/disputes.php?action=list');
        const data = await res.json();
        const container = document.getElementById('appealsCenterList');
        const badge = document.getElementById('appealsBadge');
        const dot = document.getElementById('bottomAppealsDot');

        if (!data.success || !data.disputes || data.disputes.length === 0) {
            if (badge) badge.classList.add('hidden');
            if (dot) dot.classList.add('hidden');
            if (container) {
                container.innerHTML = `
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                        <svg class="w-12 h-12 mx-auto text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <p class="font-bold text-white text-base">No Active Disputes or Appeals</p>
                        <p class="text-xs text-slate-500 mt-1">All your orders are running smoothly. If an appeal or dispute arises, it will appear here with live admin arbitration.</p>
                    </div>
                `;
            }
            return;
        }

        const activeCount = data.disputes.filter(d => !['resolved_seller', 'resolved_buyer', 'cancelled'].includes(d.status)).length;
        if (badge) {
            if (activeCount > 0) {
                badge.innerText = activeCount;
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        if (dot) {
            if (activeCount > 0) dot.classList.remove('hidden');
            else dot.classList.add('hidden');
        }

        if (!container) return;

        const statusBadges = {
            'pending': 'bg-amber-500/10 text-amber-400 border border-amber-500/20',
            'under_review': 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20',
            'resolved_buyer': 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
            'resolved_seller': 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20',
            'cancelled': 'bg-slate-800 text-slate-400'
        };

        const statusLabels = {
            'pending': 'Pending Admin Review',
            'under_review': 'Under Review / Evidence Requested',
            'resolved_buyer': 'Resolved in Favor of Buyer',
            'resolved_seller': 'Resolved in Favor of Seller',
            'cancelled': 'Closed'
        };

        container.innerHTML = data.disputes.map(disp => {
            const isResolved = ['resolved_seller', 'resolved_buyer', 'cancelled'].includes(disp.status);
            const evidenceFiles = disp.evidence_screenshot_path ? disp.evidence_screenshot_path.split(',').map(s => s.trim()).filter(Boolean) : [];

            return `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4 shadow-sm">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-3 border-b border-slate-800 gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-mono font-bold text-indigo-400">Order ${formatOrderId(disp.child_order_id)}</span>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded ${disp.user_role === 'seller' ? 'bg-violet-500/20 text-violet-300' : 'bg-emerald-500/20 text-emerald-300'}">You are ${disp.user_role === 'seller' ? 'Seller' : 'Buyer'}</span>
                            <span class="text-[10px] px-2 py-0.5 rounded font-bold uppercase tracking-wider ${statusBadges[disp.status] || 'bg-slate-800 text-slate-400'}">
                                ${statusLabels[disp.status] || disp.status}
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="text-lg font-black text-white font-mono">₹${parseFloat(disp.amount).toLocaleString('en-IN')}</span>
                            <span class="text-[11px] text-slate-500 block">Filed on ${disp.created_at}</span>
                        </div>
                    </div>

                    <!-- Dispute Details -->
                    <div class="bg-slate-950 border border-slate-800/80 rounded-xl p-4 text-xs space-y-2">
                        <div class="flex items-start justify-between">
                            <span class="text-slate-400 font-semibold">Claim Reason:</span>
                            <span class="text-slate-200 text-right max-w-md">${escapeHtml(disp.reason)}</span>
                        </div>
                        ${evidenceFiles.length > 0 ? `
                            <div class="pt-2 border-t border-slate-800/80 flex items-center gap-2 flex-wrap">
                                <span class="text-slate-400 font-semibold">Attached Proofs:</span>
                                ${evidenceFiles.map((path, idx) => `
                                    <button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(path)}', 'Dispute Evidence Proof #${idx + 1}')" class="px-2.5 py-1 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/20 rounded-lg text-xs font-semibold flex items-center gap-1 transition cursor-pointer">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <span>Proof #${idx + 1}</span>
                                    </button>
                                `).join('')}
                            </div>
                        ` : ''}
                    </div>

                    <!-- Admin Communication Box (Requirement 1) -->
                    <div class="bg-indigo-950/30 border border-indigo-500/30 rounded-xl p-4 space-y-2">
                        <div class="flex items-center gap-1.5 text-xs font-bold text-indigo-300">
                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>Administrative Decision & Guidance:</span>
                        </div>
                        <p class="text-xs text-slate-300 leading-relaxed font-mono whitespace-pre-wrap">${escapeHtml(disp.resolution_notes || 'Case assigned to Admin arbitration desk. Both parties have evidence rights. Tokens remain securely locked in double-entry escrow.')}</p>
                    </div>

                    ${(disp.additional_evidence_requested == 1 || disp.status === 'under_review') ? `
                        <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-3.5 flex items-start gap-3">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-ping flex-shrink-0 mt-1"></span>
                            <div>
                                <span class="text-amber-400 font-bold uppercase tracking-wider text-xs block">Action Required: Admin Requested Evidence</span>
                                <p class="text-xs text-slate-300 mt-0.5">The administrator has requested evidence before arbitrating this dispute. Please upload your video, photo, or statement proof below.</p>
                            </div>
                        </div>
                    ` : ''}

                    ${!isResolved ? `
                        <!-- Upload Additional Evidence / Reply Box -->
                        <div class="bg-slate-950/60 border border-slate-800 rounded-xl p-4 space-y-3">
                            <span class="text-xs font-semibold text-slate-300 block">Submit Additional Photo / Video Evidence & Statement</span>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[11px] text-slate-400 block mb-1">Select Files (Images, Video, PDF):</label>
                                    <input type="file" id="disputeFile_${disp.id}" multiple accept="image/*,video/*,application/pdf" class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500 cursor-pointer">
                                </div>
                                <div>
                                    <label class="text-[11px] text-slate-400 block mb-1">Statement / Note:</label>
                                    <input type="text" id="disputeNote_${disp.id}" placeholder="Explain proof (e.g. Bank statement timestamp)" class="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                                </div>
                            </div>
                            <div class="flex items-center justify-between pt-1">
                                <span class="text-[11px] text-slate-500" id="disputeUploadStatus_${disp.id}">Encrypted bank-grade upload</span>
                                <button onclick="submitDisputeAdditionalEvidence(${disp.id})" class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-indigo-600/20">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                                    <span>Upload Evidence</span>
                                </button>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load disputes center:', e);
    }
}

async function submitDisputeAdditionalEvidence(disputeId) {
    const fileInput = document.getElementById(`disputeFile_${disputeId}`);
    const noteInput = document.getElementById(`disputeNote_${disputeId}`);
    const statusEl = document.getElementById(`disputeUploadStatus_${disputeId}`);

    let evidencePath = '';
    const statement = noteInput ? noteInput.value.trim() : '';

    if (fileInput && fileInput.files && fileInput.files.length > 0) {
        if (statusEl) statusEl.innerHTML = `<span class="text-indigo-400 font-semibold">Uploading ${fileInput.files.length} file(s)...</span>`;
        const formData = new FormData();
        for (let i = 0; i < fileInput.files.length; i++) {
            formData.append('files[]', fileInput.files[i]);
        }
        formData.append('file', fileInput.files[0]);
        formData.append('type', 'evidence');

        try {
            const upRes = await fetch('/api/uploads.php', { method: 'POST', body: formData });
            const upData = await upRes.json();
            if (upData.success) {
                evidencePath = upData.file_path || (upData.file_paths ? upData.file_paths.join(',') : '');
            } else {
                alert(upData.error || 'Upload failed.');
                if (statusEl) statusEl.innerText = 'Upload failed.';
                return;
            }
        } catch (e) {
            alert('Network upload error.');
            return;
        }
    }

    if (!evidencePath && !statement) {
        alert('Please select an evidence file or enter an explanation statement.');
        return;
    }

    try {
        const res = await fetch('/api/disputes.php?action=upload_evidence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                dispute_id: disputeId,
                evidence_path: evidencePath,
                statement: statement
            })
        });
        const data = await res.json();
        if (data.success) {
            alert('Evidence submitted successfully! Admin will review shortly.');
            loadDisputesCenter();
        } else {
            alert(data.error || 'Failed to submit evidence.');
        }
    } catch (e) {
        alert('Network error submitting dispute evidence.');
    }
}

// -------------------------------------------------------------
// TAB 5: REFERRALS (FR-019)
// -------------------------------------------------------------

async function loadReferrals() {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/referrals.php?action=stats');
        const data = await res.json();
        if (!data.success) return;

        document.getElementById('inviteLinkInput').value = data.invite_url;
        document.getElementById('l1Count').innerText = `${data.tree.level_1_count} Users`;
        document.getElementById('l2Count').innerText = `${data.tree.level_2_count} Users`;

        // Render L1
        const l1El = document.getElementById('l1NetworkList');
        if (data.tree.level_1_network.length === 0) {
            l1El.innerHTML = `<p class="text-xs text-slate-500 p-4 text-center">No Level 1 direct referrals yet.</p>`;
        } else {
            l1El.innerHTML = data.tree.level_1_network.map(m => `
                <div class="bg-slate-950 p-3 rounded-xl flex items-center justify-between text-xs font-mono">
                    <div>
                        <span class="text-white font-bold">${m.phone_masked}</span>
                        <span class="text-slate-500 block text-[10px]">Joined: ${m.joined_at}</span>
                    </div>
                    <span class="text-emerald-400 font-bold">+₹${parseFloat(m.earned).toFixed(2)}</span>
                </div>
            `).join('');
        }

        // Render L2
        const l2El = document.getElementById('l2NetworkList');
        if (data.tree.level_2_network.length === 0) {
            l2El.innerHTML = `<p class="text-xs text-slate-500 p-4 text-center">No Level 2 indirect referrals yet.</p>`;
        } else {
            l2El.innerHTML = data.tree.level_2_network.map(m => `
                <div class="bg-slate-950 p-3 rounded-xl flex items-center justify-between text-xs font-mono">
                    <div>
                        <span class="text-white font-bold">${m.phone_masked}</span>
                        <span class="text-slate-500 block text-[10px]">Joined: ${m.joined_at}</span>
                    </div>
                    <span class="text-violet-400 text-[10px] font-sans font-semibold">0.5% Tier</span>
                </div>
            `).join('');
        }
    } catch (e) {
        console.error('Failed to load referrals:', e);
    }
}

function copyInviteLink() {
    const input = document.getElementById('inviteLinkInput');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value);
    alert('Referral invite link copied to clipboard!');
}

// -------------------------------------------------------------
// TAB 6: ADMIN CONSOLE (FR-018, FR-020, FR-021, FR-022, FR-023)
// -------------------------------------------------------------

function switchAdminSection(sectionId) {
    document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.admin-sec-btn').forEach(btn => {
        btn.classList.remove('bg-amber-500/20', 'text-amber-400', 'border', 'border-amber-500/30');
        btn.classList.add('text-slate-400');
    });

    const activeSec = document.getElementById(`adminSec-${sectionId}`);
    if (activeSec) activeSec.classList.remove('hidden');

    const activeBtn = document.getElementById(`adminSecBtn-${sectionId}`);
    if (activeBtn) {
        activeBtn.classList.remove('text-slate-400');
        activeBtn.classList.add('bg-amber-500/20', 'text-amber-400', 'border', 'border-amber-500/30');
    }

    if (sectionId === 'disputes') loadAdminDisputes();
    if (sectionId === 'users') loadAdminUsers();
    if (sectionId === 'settings') loadAdminSettings();
    if (sectionId === 'audit') loadAdminAudit();
}

async function loadAdminDashboard() {
    try {
        const res = await fetch('/api/admin.php?action=stats');
        const data = await res.json();
        if (!data.success || !data.stats) return;

        const s = data.stats;
        document.getElementById('adminTotalUsers').innerText = s.total_users;
        document.getElementById('adminCirculating').innerText = `₹${parseFloat(s.total_tokens_in_circulation).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('adminTradingVol').innerText = `₹${parseFloat(s.total_completed_trading_volume).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
        document.getElementById('adminPendingAppeals').innerText = s.pending_appeals_count;

        loadAdminDisputes();
    } catch (e) {
        console.error('Failed to load admin dashboard:', e);
    }
}

async function loadAdminDisputes() {
    try {
        const res = await fetch('/api/admin.php?action=disputes');
        const data = await res.json();
        const list = document.getElementById('adminDisputesList');
        if (!list) return;

        if (!data.success || !data.disputes || data.disputes.length === 0) {
            list.innerHTML = `<div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 text-center text-slate-500">No disputes pending arbitration.</div>`;
            return;
        }

        list.innerHTML = data.disputes.map(d => `
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div>
                        <span class="text-xs font-mono font-bold text-amber-400">Dispute #${d.id} &bull; Order #${d.child_order_id}</span>
                        <span class="text-xs px-2 py-0.5 rounded uppercase font-bold ml-2 ${d.status === 'resolved_buyer' ? 'bg-emerald-500/10 text-emerald-400' : (d.status === 'resolved_seller' ? 'bg-indigo-500/10 text-indigo-400' : 'bg-red-500/10 text-red-400')}">
                            ${d.status}
                        </span>
                    </div>
                    <span class="text-base font-black text-white font-mono">Amount: ₹${parseFloat(d.amount).toLocaleString('en-IN')}</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <!-- Seller Appeal Box -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <span class="text-red-400 font-bold uppercase tracking-wider block">Seller Appeal Notice</span>
                        <p class="text-slate-300 italic">"${escapeHtml(d.reason)}"</p>
                        <div class="text-[11px] text-slate-400">
                            Seller: <strong class="text-white">${escapeHtml(d.seller_name || '')} (${escapeHtml(d.seller_phone || '')})</strong>
                        </div>
                        ${d.evidence_screenshot_path ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(d.evidence_screenshot_path)}', 'Seller Evidence - Dispute #${d.id}')" class="text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold block mt-1 text-left cursor-pointer">View Seller Evidence</button>` : ''}
                    </div>

                    <!-- Buyer Proof Box -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <span class="text-emerald-400 font-bold uppercase tracking-wider block">Buyer Payment Submission</span>
                        <div class="text-slate-300 font-mono">UTR: <strong class="text-white">${escapeHtml(d.utr_number || 'N/A')}</strong></div>
                        <div class="text-[11px] text-slate-400">
                            Buyer: <strong class="text-white">${escapeHtml(d.buyer_name || '')} (${escapeHtml(d.buyer_phone || '')})</strong>
                        </div>
                        ${d.buyer_screenshot ? `<button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(d.buyer_screenshot)}', 'Buyer Payment Screenshot - Dispute #${d.id}')" class="text-xs text-indigo-400 hover:text-indigo-300 underline font-semibold block mt-1 text-left cursor-pointer">View Buyer Payment Screenshot</button>` : ''}
                    </div>
                </div>

                ${d.status === 'pending' || d.status === 'under_review' ? `
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 pt-2 border-t border-slate-800">
                        <input type="text" id="dispNotes_${d.id}" placeholder="Mandatory resolution notes for audit trail..." class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <button onclick="requestMoreEvidenceAdmin(${d.id})" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-lg text-xs uppercase tracking-wider transition whitespace-nowrap">
                            Request Evidence
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'force_settle')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-lg text-xs uppercase tracking-wider transition whitespace-nowrap">
                            Force Settle
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'cancel_reverse')" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-lg text-xs uppercase tracking-wider transition whitespace-nowrap">
                            Cancel & Return
                        </button>
                    </div>
                ` : `
                    <div class="text-xs text-slate-400 bg-slate-950 p-3 rounded-lg font-mono">
                        Arbitrated: <span class="text-slate-200">${escapeHtml(d.resolution_notes || 'Resolved')}</span>
                    </div>
                `}
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load admin disputes:', e);
    }
}

async function requestMoreEvidenceAdmin(disputeId) {
    const instructions = prompt(`Enter specific instructions for photo/video/statement evidence needed for Dispute #${disputeId}:`);
    if (!instructions || !instructions.trim()) return;

    try {
        const res = await fetch('/api/admin.php?action=request_evidence', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ dispute_id: disputeId, instructions: instructions.trim() })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message || 'Evidence request dispatched. Both buyer and seller have been notified.');
            loadAdminDashboard();
        } else {
            alert(data.error || 'Failed to dispatch evidence request.');
        }
    } catch (e) {
        alert('Network error requesting evidence.');
    }
}

async function arbitrateDispute(disputeId, decision) {
    const notesInput = document.getElementById(`dispNotes_${disputeId}`);
    const notes = notesInput ? notesInput.value.trim() : '';

    if (!notes) {
        alert('Please enter mandatory resolution notes for audit tracking before arbitrating.');
        return;
    }

    if (!confirm(`Are you sure you want to execute decision '${decision}' on Dispute #${disputeId}? This action will immediately adjust user balances and be permanently audited.`)) {
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
            loadAdminDashboard();
        } else {
            alert(data.error || 'Arbitration failed.');
        }
    } catch (e) {
        alert('Network error arbitrating dispute.');
    }
}

async function loadAdminUsers() {
    try {
        const res = await fetch('/api/admin.php?action=users');
        const data = await res.json();
        const tbody = document.getElementById('adminUsersTableBody');
        if (!tbody) return;

        tbody.innerHTML = data.users.map(u => `
            <tr class="hover:bg-slate-900/60">
                <td class="p-3 text-slate-500">#${u.id}</td>
                <td class="p-3 font-bold text-white">${escapeHtml(u.phone)}</td>
                <td class="p-3 font-sans text-[11px] text-slate-300">
                    ${escapeHtml(u.account_holder_name || '-')} &bull; <span class="font-mono text-slate-400">${escapeHtml(u.upi_id || '-')}</span>
                </td>
                <td class="p-3 font-bold text-emerald-400">₹${parseFloat(u.available_balance || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                <td class="p-3 text-amber-400">₹${parseFloat(u.reserved_balance || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold ${u.account_status === 'active' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'}">
                        ${u.account_status}
                    </span>
                </td>
                <td class="p-3">
                    <button onclick="startImpersonation(${u.id})" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-indigo-400 rounded text-[11px] font-sans font-semibold transition" title="Inspect user dashboard in read-only mode">
                        Support Inspect
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load admin users:', e);
    }
}

async function startImpersonation(userId) {
    if (!confirm(`Enter Read-Only Admin Support Mode for User #${userId}? You will view their dashboard without seeing or bypassing their password or TPIN.`)) {
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
            window.location.reload();
        } else {
            alert(data.error || 'Failed to start support mode.');
        }
    } catch (e) {
        alert('Network error.');
    }
}

async function exitImpersonation() {
    try {
        await fetch('/api/auth.php?action=exit_impersonation', { method: 'POST' });
        window.location.reload();
    } catch (e) {
        alert('Network error exiting impersonation.');
    }
}

async function submitManualAdjustment() {
    const userId = parseInt(document.getElementById('adjUserId').value);
    const type = document.getElementById('adjType').value;
    const amount = parseFloat(document.getElementById('adjAmount').value);
    const reason = document.getElementById('adjReason').value.trim();

    if (isNaN(userId) || isNaN(amount) || amount <= 0) {
        alert('Please enter a valid user ID and positive amount.');
        return;
    }

    if (!reason) {
        alert('A mandatory audit reason is required for manual balance adjustments.');
        return;
    }

    if (!confirm(`Confirm manual ${type.toUpperCase()} of ₹${amount} to User #${userId}?`)) {
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
            alert(`Adjustment executed! New available balance for User #${userId}: ₹${data.new_available}`);
            document.getElementById('adjAmount').value = '';
            document.getElementById('adjReason').value = '';
            loadAdminDashboard();
        } else {
            alert(data.error || 'Adjustment failed.');
        }
    } catch (e) {
        alert('Network error executing adjustment.');
    }
}

async function loadAdminSettings() {
    try {
        const res = await fetch('/api/admin.php?action=settings');
        const data = await res.json();
        const grid = document.getElementById('adminSettingsGrid');
        if (!grid || !data.settings) return;

        grid.innerHTML = data.settings.map(s => `
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-mono font-bold text-indigo-400">${escapeHtml(s.setting_key)}</span>
                    <span class="text-[11px] text-slate-500">Updated: ${s.updated_at || 'Default'}</span>
                </div>
                <p class="text-[11px] text-slate-400">${escapeHtml(s.description || '')}</p>
                <div class="flex items-center gap-2 pt-2">
                    <input type="text" id="settingInput_${s.setting_key}" value="${escapeHtml(s.setting_value)}" class="bg-slate-950 border border-slate-800 rounded px-3 py-1.5 text-xs text-white font-mono flex-1 focus:outline-none focus:border-indigo-500">
                    <button onclick="saveAdminSetting('${s.setting_key}')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded transition">
                        Update
                    </button>
                </div>
            </div>
        `).join('');
    } catch (e) {
        console.error('Failed to load admin settings:', e);
    }
}

async function saveAdminSetting(key) {
    const input = document.getElementById(`settingInput_${key}`);
    const value = input ? input.value.trim() : '';

    const reason = prompt(`Enter reason for updating setting '${key}':`, 'Operational parameter adjustment');
    if (!reason) return;

    try {
        const res = await fetch('/api/admin.php?action=settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ setting_key: key, setting_value: value, reason })
        });
        const data = await res.json();
        if (data.success) {
            alert(`Setting '${key}' updated successfully!`);
            loadAdminSettings();
        } else {
            alert(data.error || 'Failed to update setting.');
        }
    } catch (e) {
        alert('Network error updating setting.');
    }
}

async function loadAdminAudit() {
    try {
        const res = await fetch('/api/admin.php?action=audit_logs');
        const data = await res.json();
        const tbody = document.getElementById('adminAuditTableBody');
        if (!tbody || !data.audit_logs) return;

        tbody.innerHTML = data.audit_logs.map(log => `
            <tr class="hover:bg-slate-900/60">
                <td class="p-3 text-slate-500">#${log.id}</td>
                <td class="p-3 text-slate-300">${escapeHtml(log.admin_phone || 'Admin')}</td>
                <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] bg-slate-800 font-bold text-amber-400">${escapeHtml(log.action_type)}</span></td>
                <td class="p-3 font-mono text-slate-400">${escapeHtml(log.target_entity)} #${escapeHtml(log.target_id)}</td>
                <td class="p-3 text-slate-300 font-sans text-[11px] truncate max-w-xs">${escapeHtml(log.reason)}</td>
                <td class="p-3 text-slate-500 text-[11px]">${log.created_at}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load audit logs:', e);
    }
}

// -------------------------------------------------------------
// TIMER FORMATTING & TICKER
// -------------------------------------------------------------

function updateLiveCountdowns() {
    for (const [key, obj] of Object.entries(activeTimers)) {
        if (obj.seconds > 0) {
            obj.seconds--;
            const el = document.getElementById(`timer_${key}`);
            if (el) {
                el.innerText = formatSeconds(obj.seconds);
                if (obj.seconds <= 60) {
                    el.classList.add('text-red-400', 'animate-pulse');
                    el.classList.remove('text-amber-400', 'text-indigo-400');
                }
            }
        } else {
            const el = document.getElementById(`timer_${key}`);
            if (el) el.innerText = '00:00 (Expired)';
        }
    }

    // Live Elapsed Timers for Sell Listings (Part 5, Item 5)
    for (const [key, secs] of Object.entries(activeElapsedTimers)) {
        activeElapsedTimers[key] = secs + 1;
        const el = document.getElementById(`elapsed_${key}`);
        if (el) {
            el.innerText = `On Sale: ${formatElapsed(activeElapsedTimers[key])}`;
        }
    }

    // Modal Specific Live Countdowns (Item 1 & Item 4)
    if (pendingConfirmSeconds > 0) {
        pendingConfirmSeconds--;
        const el = document.getElementById('confirmModalTimer');
        if (el) el.innerText = formatSeconds(pendingConfirmSeconds);
    }
    if (proofModalSeconds > 0) {
        proofModalSeconds--;
        const el = document.getElementById('proofModalCountdown');
        if (el) el.innerText = formatSeconds(proofModalSeconds);
        if (proofModalSeconds === 0) {
            closeProofModal();
            alert('Payment deadline expired. The reservation has been automatically cancelled and tokens returned to the live sell queue.');
            loadBuyPool(true);
            loadBuyPageHistory(true);
            syncHomeActiveOrder(true);
        }
    }
}

function formatSeconds(secs) {
    if (secs <= 0) return '00:00';
    const m = Math.floor(secs / 60);
    const s = Math.floor(secs % 60);
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

// -------------------------------------------------------------
// AUTH MODAL & PROFILE HANDLERS
// -------------------------------------------------------------

function openAuthModal(mode = 'login') {
    const modal = document.getElementById('authModal');
    const modeInput = document.getElementById('authMode');
    const title = document.getElementById('authModalTitle');
    const sub = document.getElementById('authModalSubtitle');
    const regFields = document.getElementById('registerFields');
    const toggleText = document.getElementById('authToggleText');
    const toggleBtn = document.getElementById('authToggleBtn');
    const submitBtn = document.getElementById('authSubmitBtn');

    modeInput.value = mode;
    document.getElementById('authError').classList.add('hidden');

    if (mode === 'register') {
        title.innerText = 'Create Account';
        sub.innerText = 'Join the Peer-to-Peer token exchange';
        regFields.classList.remove('hidden');
        toggleText.innerText = 'Already have an account?';
        toggleBtn.innerText = 'Sign In';
        submitBtn.innerText = 'Create Account';
    } else {
        title.innerText = 'Sign In';
        sub.innerText = 'Access your P2P token wallet';
        regFields.classList.add('hidden');
        toggleText.innerText = "Don't have an account?";
        toggleBtn.innerText = 'Create Account';
        submitBtn.innerText = 'Continue';
    }

    modal.classList.remove('hidden');
}

function closeAuthModal() {
    document.getElementById('authModal').classList.add('hidden');
}

function toggleAuthMode() {
    const cur = document.getElementById('authMode').value;
    openAuthModal(cur === 'login' ? 'register' : 'login');
}

async function handleAuthSubmit(e) {
    e.preventDefault();
    const mode = document.getElementById('authMode').value;
    const phone = document.getElementById('authPhone').value.trim();
    const password = document.getElementById('authPassword').value;
    const errEl = document.getElementById('authError');

    const payload = { identifier: phone, phone, password };

    if (mode === 'register') {
        const refEl = document.getElementById('authReferralCode');
        if (refEl && refEl.value.trim()) payload.referral_code = refEl.value.trim();
    }

    try {
        const res = await fetch(`/api/auth.php?action=${mode}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            errEl.innerText = data.error || 'Authentication error.';
            errEl.classList.remove('hidden');
        }
    } catch (e) {
        errEl.innerText = 'Network error during authentication.';
        errEl.classList.remove('hidden');
    }
}

async function logout() {
    await fetch('/api/auth.php?action=logout', { method: 'POST' });
    window.location.reload();
}

function openProfileModal() {
    const modal = document.getElementById('profileModal');
    if (modal) modal.classList.remove('hidden');
}

function closeProfileModal() {
    const modal = document.getElementById('profileModal');
    if (modal) modal.classList.add('hidden');
}

async function handleProfileUpdate(e) {
    if (e && e.preventDefault) e.preventDefault();
    const holderInput = document.getElementById('profHolderName');
    const upiInput = document.getElementById('profUpiId');
    const emailInput = document.getElementById('profEmail');
    const statusEl = document.getElementById('profUpdateStatus');
    const submitBtn = document.getElementById('profUpdateBtn');

    const holderName = holderInput ? holderInput.value.trim() : '';
    const upiId = upiInput ? upiInput.value.trim() : '';
    const email = emailInput ? emailInput.value.trim() : '';

    if (!holderName) {
        if (statusEl) {
            statusEl.innerText = 'Account holder name is required.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        } else {
            alert('Account holder name is required.');
        }
        if (holderInput) holderInput.focus();
        return;
    }

    if (!upiId || !upiId.includes('@')) {
        if (statusEl) {
            statusEl.innerText = 'Please provide a valid UPI ID (e.g. user@bank).';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        } else {
            alert('Please provide a valid UPI ID (e.g. user@bank).');
        }
        if (upiInput) upiInput.focus();
        return;
    }

    if (email && (!email.includes('@') || !email.includes('.'))) {
        if (statusEl) {
            statusEl.innerText = 'Please provide a valid email address.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        } else {
            alert('Please provide a valid email address.');
        }
        if (emailInput) emailInput.focus();
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Saving Details...';
    }
    if (statusEl) {
        statusEl.innerText = 'Saving payout details...';
        statusEl.className = 'text-xs font-semibold text-slate-400 block';
        statusEl.classList.remove('hidden');
    }

    try {
        const payload = { account_holder_name: holderName, upi_id: upiId };
        if (emailInput) {
            payload.email = email;
        }
        const res = await fetch('/api/auth.php?action=update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            if (statusEl) {
                statusEl.innerText = 'Banking and payout details updated successfully!';
                statusEl.className = 'text-xs font-semibold text-emerald-400 block';
                statusEl.classList.remove('hidden');
            }
            alert('Banking and payout details updated successfully!');
            // Prefill sell page inputs
            const sellHolder = document.getElementById('sellAccountHolderName');
            const sellUpi = document.getElementById('sellUpiId');
            if (sellHolder) sellHolder.value = holderName;
            if (sellUpi) sellUpi.value = upiId;
            closeProfileModal();
            await fetchSession();
        } else {
            if (statusEl) {
                statusEl.innerText = data.error || 'Failed to update details.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
            alert(data.error || 'Failed to update details.');
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error updating profile. Please retry.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        alert('Network error updating profile.');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Save Payout Details';
        }
    }
}

async function handleTpinUpdate(e) {
    if (e) {
        if (e.preventDefault) e.preventDefault();
        if (e.stopPropagation) e.stopPropagation();
    }
    const tpinInput = document.getElementById('profNewTpin');
    const statusEl = document.getElementById('profTpinStatus');
    const tpinBtn = document.getElementById('profileTpinBtn');
    const tpin = tpinInput ? tpinInput.value.trim() : '';

    if (!tpin || tpin.length < 4 || tpin.length > 6 || isNaN(tpin)) {
        if (statusEl) {
            statusEl.innerText = 'TPIN must be a 4 to 6 digit numeric PIN.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        alert('TPIN must be a 4 to 6 digit numeric PIN.');
        if (tpinInput) tpinInput.focus();
        return;
    }

    if (tpinBtn) tpinBtn.disabled = true;
    if (statusEl) {
        statusEl.innerText = 'Saving Selling TPIN...';
        statusEl.className = 'text-xs font-semibold text-slate-400 block';
        statusEl.classList.remove('hidden');
    }

    let data;
    try {
        const res = await fetch('/api/auth.php?action=set_tpin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tpin })
        });
        data = await res.json();
    } catch (netErr) {
        console.error('Network error configuring TPIN:', netErr);
        if (statusEl) {
            statusEl.innerText = 'Network error configuring TPIN. Please check connection.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        alert('Network error configuring TPIN. Please check your connection and retry.');
        if (tpinBtn) tpinBtn.disabled = false;
        return;
    }

    if (data && data.success) {
        if (tpinInput) tpinInput.value = '';
        if (statusEl) {
            statusEl.innerText = 'Selling TPIN configured successfully!';
            statusEl.className = 'text-xs font-semibold text-emerald-400 block';
            statusEl.classList.remove('hidden');
        }
        alert('Selling TPIN configured successfully! You can now authorize sell listings.');
        try {
            await fetchSession();
        } catch (syncErr) {
            console.warn('Session refresh after TPIN update skipped:', syncErr);
        }
    } else {
        const err = (data && data.error) ? data.error : 'Failed to configure TPIN.';
        if (statusEl) {
            statusEl.innerText = err;
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        alert(err);
    }
    if (tpinBtn) tpinBtn.disabled = false;
}

function copyReferralCode() {
    let code = '';
    const refCodeEl = document.getElementById('profileRefCodeDisplay');
    if (refCodeEl && refCodeEl.innerText && refCodeEl.innerText !== '-') {
        code = refCodeEl.innerText.trim();
    } else if (currentUser && currentUser.referral_code) {
        code = currentUser.referral_code;
    }

    if (!code) {
        alert('Referral code not available yet.');
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(() => {
            alert(`Referral code "${code}" copied to clipboard!`);
        }).catch(() => {
            copyToClipboard(code);
        });
    } else {
        copyToClipboard(code);
    }
}

function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            alert('Copied to clipboard: ' + text);
        }).catch(() => {
            const temp = document.createElement('textarea');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            alert('Copied to clipboard: ' + text);
        });
    } else {
        const temp = document.createElement('textarea');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        alert('Copied to clipboard: ' + text);
    }
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
// CHANGE PASSWORD HANDLER (Item 7)
// -------------------------------------------------------------

async function handlePasswordChange(e) {
    if (e && e.preventDefault) e.preventDefault();

    const curInput = document.getElementById('currentPasswordInput');
    const newInput = document.getElementById('newPasswordInput');
    const confirmInput = document.getElementById('confirmPasswordInput');
    const totpInput = document.getElementById('pwdTotpCode');
    const pwdTotpContainer = document.getElementById('pwdTotpContainer');
    const statusEl = document.getElementById('changePasswordStatus');
    const submitBtn = document.getElementById('changePasswordBtn');

    const current_password = curInput ? curInput.value : '';
    const new_password = newInput ? newInput.value : '';
    const confirm_password = confirmInput ? confirmInput.value : '';
    const totp_code = totpInput ? totpInput.value.trim() : '';

    if (!current_password || !new_password) {
        if (statusEl) {
            statusEl.innerText = 'Both current and new passwords are required.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        return;
    }
    if (new_password.length < 6) {
        if (statusEl) {
            statusEl.innerText = 'New password must be at least 6 characters long.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        return;
    }
    if (confirmInput && new_password !== confirm_password) {
        if (statusEl) {
            statusEl.innerText = 'New passwords do not match. Please re-enter.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        confirmInput.focus();
        return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (statusEl) {
        statusEl.innerText = 'Updating password...';
        statusEl.className = 'text-xs font-semibold text-slate-400 block';
        statusEl.classList.remove('hidden');
    }

    try {
        const payload = { current_password, new_password };
        if (totp_code) {
            payload.totp_code = totp_code;
        }

        const res = await fetch('/api/auth.php?action=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            if (statusEl) {
                statusEl.innerText = 'Password updated successfully!';
                statusEl.className = 'text-xs font-semibold text-emerald-400 block';
                statusEl.classList.remove('hidden');
            }
            if (curInput) curInput.value = '';
            if (newInput) newInput.value = '';
            if (confirmInput) confirmInput.value = '';
            if (totpInput) totpInput.value = '';
            alert('Account password updated successfully!');
        } else {
            if (data.requires_totp) {
                if (pwdTotpContainer) pwdTotpContainer.classList.remove('hidden');
                if (totpInput) totpInput.focus();
            }
            if (statusEl) {
                statusEl.innerText = data.error || 'Failed to update password.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error changing password.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

// -------------------------------------------------------------
// TWO-FACTOR AUTHENTICATION (TOTP) CONTROLLER
// -------------------------------------------------------------

window._totpTempSecret = '';
window._currentRecoveryCodes = [];
window._regenRecoveryCodes = [];

function updateTotpUi(user) {
    const isTotpEnabled = !!(user && user.totp_enabled);
    const badge = document.getElementById('totpStatusBadge');
    const enableBtn = document.getElementById('totpEnableBtn');
    const disableBtn = document.getElementById('totpDisableBtn');
    const regenBtn = document.getElementById('totpRegenBtn');
    const pwdTotpContainer = document.getElementById('pwdTotpContainer');
    const recoveryStatusText = document.getElementById('totpRecoveryStatusText');

    if (isTotpEnabled) {
        if (badge) {
            badge.innerText = 'Active & Protected';
            badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
        }
        if (enableBtn) enableBtn.classList.add('hidden');
        if (disableBtn) disableBtn.classList.remove('hidden');
        if (regenBtn) regenBtn.classList.remove('hidden');
        if (pwdTotpContainer) pwdTotpContainer.classList.remove('hidden');

        fetch('/api/auth.php?action=totp_status')
            .then(r => r.json())
            .then(data => {
                if (data.success && recoveryStatusText) {
                    const rem = data.remaining_recovery_codes ?? 0;
                    const tot = data.total_recovery_codes ?? 8;
                    recoveryStatusText.innerText = `${rem} of ${tot} single-use backup recovery codes remaining. Regenerate if running low.`;
                }
            })
            .catch(() => {});
    } else {
        if (badge) {
            badge.innerText = 'Not Configured';
            badge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 border border-slate-700';
        }
        if (enableBtn) enableBtn.classList.remove('hidden');
        if (disableBtn) disableBtn.classList.add('hidden');
        if (regenBtn) regenBtn.classList.add('hidden');
        if (pwdTotpContainer) pwdTotpContainer.classList.add('hidden');
        if (recoveryStatusText) {
            recoveryStatusText.innerText = '8 single-use backup recovery codes will be generated upon setup to prevent accidental lockouts.';
        }
    }
}

async function openTotpSetupModal() {
    const modal = document.getElementById('totpSetupModal');
    const enrollView = document.getElementById('totpEnrollView');
    const successView = document.getElementById('totpSuccessView');
    const qrContainer = document.getElementById('totpQrContainer');
    const keyInput = document.getElementById('totpManualKeyInput');
    const codeInput = document.getElementById('totpEnrollCodeInput');
    const statusEl = document.getElementById('totpEnrollStatus');
    const copyBtnText = document.getElementById('copyKeyBtnText');

    if (!modal) return;

    if (enrollView) enrollView.classList.remove('hidden');
    if (successView) successView.classList.add('hidden');
    if (codeInput) codeInput.value = '';
    if (statusEl) {
        statusEl.className = 'text-xs font-semibold hidden';
        statusEl.innerText = '';
    }
    if (copyBtnText) copyBtnText.innerText = 'Copy';
    if (qrContainer) {
        qrContainer.innerHTML = '<div class="animate-pulse bg-slate-800 w-48 h-48 rounded-2xl flex items-center justify-center text-slate-400 text-xs font-mono">Generating QR Code...</div>';
    }

    modal.classList.remove('hidden');

    try {
        const res = await fetch('/api/auth.php?action=totp_setup');
        const data = await res.json();
        if (data.success) {
            window._totpTempSecret = data.secret;
            if (qrContainer && data.qr_svg) {
                qrContainer.innerHTML = data.qr_svg;
            }
            if (keyInput) {
                keyInput.value = data.secret_formatted || data.secret;
            }
            if (codeInput) codeInput.focus();
        } else {
            if (statusEl) {
                statusEl.innerText = data.error || 'Failed to initiate 2FA setup.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error initiating 2FA setup.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
    }
}

function closeTotpSetupModal() {
    const modal = document.getElementById('totpSetupModal');
    if (modal) modal.classList.add('hidden');
}

function copyTotpManualKey() {
    const textToCopy = window._totpTempSecret || document.getElementById('totpManualKeyInput')?.value?.replace(/\s+/g, '') || '';
    if (!textToCopy) return;

    navigator.clipboard.writeText(textToCopy).then(() => {
        const copyBtnText = document.getElementById('copyKeyBtnText');
        if (copyBtnText) {
            copyBtnText.innerText = 'Copied!';
            setTimeout(() => { copyBtnText.innerText = 'Copy'; }, 2500);
        }
    }).catch(() => {
        const keyInput = document.getElementById('totpManualKeyInput');
        if (keyInput) {
            keyInput.select();
            document.execCommand('copy');
        }
    });
}

async function submitTotpEnrollment(e) {
    if (e && e.preventDefault) e.preventDefault();

    const codeInput = document.getElementById('totpEnrollCodeInput');
    const statusEl = document.getElementById('totpEnrollStatus');
    const submitBtn = document.getElementById('totpEnrollSubmitBtn');
    const code = codeInput ? codeInput.value.trim() : '';

    if (!code || code.length !== 6) {
        if (statusEl) {
            statusEl.innerText = 'Please enter a valid 6-digit code from your authenticator app.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (statusEl) {
        statusEl.innerText = 'Verifying code...';
        statusEl.className = 'text-xs font-semibold text-cyan-400 block';
        statusEl.classList.remove('hidden');
    }

    try {
        const res = await fetch('/api/auth.php?action=totp_verify_enrollment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code })
        });
        const data = await res.json();
        if (data.success) {
            window._currentRecoveryCodes = data.recovery_codes || [];
            if (currentUser) currentUser.totp_enabled = true;
            updateTotpUi(currentUser);

            // Render recovery codes
            const grid = document.getElementById('totpRecoveryGrid');
            if (grid && Array.isArray(data.recovery_codes)) {
                grid.innerHTML = data.recovery_codes.map((c, i) => `
                    <div class="p-2 bg-slate-900/90 rounded-xl border border-slate-800 text-cyan-300 font-mono tracking-widest text-center select-all flex items-center justify-between px-3">
                        <span class="text-[10px] text-slate-500">#${i + 1}</span>
                        <span>${c}</span>
                    </div>
                `).join('');
            }

            const enrollView = document.getElementById('totpEnrollView');
            const successView = document.getElementById('totpSuccessView');
            if (enrollView) enrollView.classList.add('hidden');
            if (successView) successView.classList.remove('hidden');
        } else {
            if (statusEl) {
                statusEl.innerText = data.error || 'Verification failed. Please check your authenticator code.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error verifying authenticator code.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

function copyAllRecoveryCodes() {
    if (!window._currentRecoveryCodes || !window._currentRecoveryCodes.length) return;
    const text = "JeliPay 2FA Emergency Backup Recovery Codes\n" +
                 "Keep these codes safe and private. Each code can be used only once.\n\n" +
                 window._currentRecoveryCodes.map((c, i) => `${i + 1}. ${c}`).join('\n');

    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyRecoveryBtnText');
        if (btn) {
            btn.innerText = 'Copied!';
            setTimeout(() => { btn.innerText = 'Copy All Codes'; }, 2500);
        }
    });
}

function downloadRecoveryCodes() {
    if (!window._currentRecoveryCodes || !window._currentRecoveryCodes.length) return;
    const text = "====================================================\n" +
                 "JeliPay Two-Factor Authentication Backup Recovery Codes\n" +
                 "Account: " + (currentUser?.phone || currentUser?.email || 'User') + "\n" +
                 "Generated: " + new Date().toUTCString() + "\n" +
                 "====================================================\n\n" +
                 "INSTRUCTIONS:\n" +
                 "- Each code below can be used exactly ONCE to sign in or change your password if you lose access to your authenticator app.\n" +
                 "- Cross out each code after you use it.\n\n" +
                 window._currentRecoveryCodes.map((c, i) => `[ ] ${i + 1}. ${c}`).join('\n') +
                 "\n\nStore this file in a secure location.";

    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `jelipay-2fa-recovery-codes-${Date.now()}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function finishTotpSetup() {
    closeTotpSetupModal();
    if (currentUser) updateTotpUi(currentUser);
}

function openTotpDisableModal() {
    const modal = document.getElementById('totpDisableModal');
    const pwdInput = document.getElementById('totpDisablePasswordInput');
    const codeInput = document.getElementById('totpDisableCodeInput');
    const statusEl = document.getElementById('totpDisableStatus');

    if (!modal) return;
    if (pwdInput) pwdInput.value = '';
    if (codeInput) codeInput.value = '';
    if (statusEl) {
        statusEl.className = 'text-xs font-semibold hidden';
        statusEl.innerText = '';
    }

    modal.classList.remove('hidden');
    if (pwdInput) pwdInput.focus();
}

function closeTotpDisableModal() {
    const modal = document.getElementById('totpDisableModal');
    if (modal) modal.classList.add('hidden');
}

async function submitTotpDisable(e) {
    if (e && e.preventDefault) e.preventDefault();

    const pwdInput = document.getElementById('totpDisablePasswordInput');
    const codeInput = document.getElementById('totpDisableCodeInput');
    const statusEl = document.getElementById('totpDisableStatus');
    const submitBtn = document.getElementById('totpDisableSubmitBtn');

    const password = pwdInput ? pwdInput.value : '';
    const code = codeInput ? codeInput.value.trim() : '';

    if (!password || !code) {
        if (statusEl) {
            statusEl.innerText = 'Both account password and authenticator/recovery code are required.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (statusEl) {
        statusEl.innerText = 'Verifying and disabling 2FA...';
        statusEl.className = 'text-xs font-semibold text-slate-400 block';
        statusEl.classList.remove('hidden');
    }

    try {
        const res = await fetch('/api/auth.php?action=totp_disable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password, code })
        });
        const data = await res.json();
        if (data.success) {
            if (currentUser) currentUser.totp_enabled = false;
            updateTotpUi(currentUser);
            closeTotpDisableModal();
            alert('Two-Factor Authentication has been successfully disabled.');
        } else {
            if (statusEl) {
                statusEl.innerText = data.error || 'Failed to disable 2FA.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error disabling 2FA.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

function openTotpRegenModal() {
    const modal = document.getElementById('totpRegenModal');
    const form = document.getElementById('totpRegenForm');
    const resView = document.getElementById('totpRegenResultView');
    const pwdInput = document.getElementById('totpRegenPasswordInput');
    const codeInput = document.getElementById('totpRegenCodeInput');
    const statusEl = document.getElementById('totpRegenStatus');

    if (!modal) return;
    if (form) form.classList.remove('hidden');
    if (resView) resView.classList.add('hidden');
    if (pwdInput) pwdInput.value = '';
    if (codeInput) codeInput.value = '';
    if (statusEl) {
        statusEl.className = 'text-xs font-semibold hidden';
        statusEl.innerText = '';
    }

    modal.classList.remove('hidden');
    if (pwdInput) pwdInput.focus();
}

function closeTotpRegenModal() {
    const modal = document.getElementById('totpRegenModal');
    if (modal) modal.classList.add('hidden');
}

async function submitTotpRegen(e) {
    if (e && e.preventDefault) e.preventDefault();

    const pwdInput = document.getElementById('totpRegenPasswordInput');
    const codeInput = document.getElementById('totpRegenCodeInput');
    const statusEl = document.getElementById('totpRegenStatus');
    const submitBtn = document.getElementById('totpRegenSubmitBtn');

    const password = pwdInput ? pwdInput.value : '';
    const code = codeInput ? codeInput.value.trim() : '';

    if (!password || !code) {
        if (statusEl) {
            statusEl.innerText = 'Password and 6-digit authenticator code are required.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
        return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (statusEl) {
        statusEl.innerText = 'Regenerating recovery codes...';
        statusEl.className = 'text-xs font-semibold text-slate-400 block';
        statusEl.classList.remove('hidden');
    }

    try {
        const res = await fetch('/api/auth.php?action=totp_regenerate_recovery_codes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password, code })
        });
        const data = await res.json();
        if (data.success) {
            window._regenRecoveryCodes = data.recovery_codes || [];
            const grid = document.getElementById('totpRegenGrid');
            if (grid && Array.isArray(data.recovery_codes)) {
                grid.innerHTML = data.recovery_codes.map((c, i) => `
                    <div class="p-2 bg-slate-900 rounded-xl border border-slate-800 text-amber-300 font-mono tracking-widest text-center select-all flex items-center justify-between px-3">
                        <span class="text-[10px] text-slate-500">#${i + 1}</span>
                        <span>${c}</span>
                    </div>
                `).join('');
            }

            const form = document.getElementById('totpRegenForm');
            const resView = document.getElementById('totpRegenResultView');
            if (form) form.classList.add('hidden');
            if (resView) resView.classList.remove('hidden');

            if (currentUser) updateTotpUi(currentUser);
        } else {
            if (statusEl) {
                statusEl.innerText = data.error || 'Failed to regenerate recovery codes.';
                statusEl.className = 'text-xs font-semibold text-red-400 block';
                statusEl.classList.remove('hidden');
            }
        }
    } catch (e) {
        if (statusEl) {
            statusEl.innerText = 'Network error regenerating recovery codes.';
            statusEl.className = 'text-xs font-semibold text-red-400 block';
            statusEl.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

function copyAllRegenRecoveryCodes() {
    if (!window._regenRecoveryCodes || !window._regenRecoveryCodes.length) return;
    const text = "JeliPay 2FA Regenerated Emergency Backup Recovery Codes\n" +
                 "Keep these codes safe and private. Each code can be used only once.\n\n" +
                 window._regenRecoveryCodes.map((c, i) => `${i + 1}. ${c}`).join('\n');

    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyRegenBtnText');
        if (btn) {
            btn.innerText = 'Copied!';
            setTimeout(() => { btn.innerText = 'Copy All'; }, 2500);
        }
    });
}

function downloadRegenRecoveryCodes() {
    if (!window._regenRecoveryCodes || !window._regenRecoveryCodes.length) return;
    const text = "====================================================\n" +
                 "JeliPay Two-Factor Authentication Regenerated Recovery Codes\n" +
                 "Account: " + (currentUser?.phone || currentUser?.email || 'User') + "\n" +
                 "Generated: " + new Date().toUTCString() + "\n" +
                 "====================================================\n\n" +
                 window._regenRecoveryCodes.map((c, i) => `[ ] ${i + 1}. ${c}`).join('\n') +
                 "\n\nAll prior recovery codes are invalid.";

    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `jelipay-2fa-new-recovery-codes-${Date.now()}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// -------------------------------------------------------------
// SELLER CONFIRMATION POPUP SYSTEM ("Confirmation Please" - Item 1)
// -------------------------------------------------------------

async function checkPendingSellerConfirmations() {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/sell.php?action=tracking');
        const data = await res.json();
        if (!data.success || !data.parent_orders) return;

        let targetChild = null;
        for (const parent of data.parent_orders) {
            for (const child of (parent.children || [])) {
                if (['Seller Confirmation Pending', 'Payment Proof Submitted'].includes(child.status)) {
                    targetChild = child;
                    break;
                }
            }
            if (targetChild) break;
        }

        const modal = document.getElementById('confirmationModal');
        if (!modal) return;

        if (targetChild) {
            pendingConfirmOrderId = targetChild.id;
            pendingConfirmSeconds = targetChild.seconds_remaining > 0 ? targetChild.seconds_remaining : 900;

            const orderIdEl = document.getElementById('confirmModalOrderId');
            const amountEl = document.getElementById('confirmModalAmount');
            const utrEl = document.getElementById('confirmModalUtr');
            const proofsContainer = document.getElementById('confirmModalProofsContainer');
            const timerEl = document.getElementById('confirmModalTimer');

            if (orderIdEl) orderIdEl.innerText = `#${targetChild.id}`;
            if (amountEl) amountEl.innerText = `₹${parseFloat(targetChild.amount).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
            if (utrEl) utrEl.innerText = targetChild.utr_number || 'N/A';
            if (timerEl) timerEl.innerText = formatSeconds(pendingConfirmSeconds);

            if (proofsContainer) {
                if (targetChild.screenshot_path) {
                    const paths = targetChild.screenshot_path.split(',').map(s => s.trim()).filter(Boolean);
                    proofsContainer.innerHTML = paths.map((path, idx) => `
                        <button type="button" onclick="openUserEvidenceLightbox('${escapeHtml(targetChild.screenshot_path)}', 'Payment Proof - Order #${targetChild.id}', ${idx})" class="px-2.5 py-1 bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 border border-indigo-500/30 rounded-lg text-xs font-semibold flex items-center gap-1 transition cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <span>Proof #${idx + 1}</span>
                        </button>
                    `).join('');
                } else {
                    proofsContainer.innerHTML = '<span class="text-slate-500 text-xs">No screenshot path provided</span>';
                }
            }

            modal.classList.remove('hidden');
        } else {
            if (pendingConfirmOrderId) {
                modal.classList.add('hidden');
                pendingConfirmOrderId = null;
            }
        }
    } catch (e) {
        console.warn('Error checking pending seller confirmations:', e);
    }
}

async function handleConfirmationReceipt() {
    if (!pendingConfirmOrderId) return;
    const orderId = pendingConfirmOrderId;
    const modal = document.getElementById('confirmationModal');
    if (modal) modal.classList.add('hidden');
    await confirmPayment(orderId);
}

function handleConfirmationAppeal() {
    if (!pendingConfirmOrderId) return;
    const orderId = pendingConfirmOrderId;
    const modal = document.getElementById('confirmationModal');
    if (modal) modal.classList.add('hidden');
    openAppealModal(orderId);
}

// -------------------------------------------------------------
// NOTIFICATIONS SYSTEM (Item 6)
// -------------------------------------------------------------

function toggleNotifications() {
    const pop = document.getElementById('notificationsPopover');
    if (!pop) return;
    notificationsOpen = !notificationsOpen;
    if (notificationsOpen) {
        pop.classList.remove('hidden');
        loadNotifications();
    } else {
        pop.classList.add('hidden');
    }
}

document.addEventListener('click', (e) => {
    const pop = document.getElementById('notificationsPopover');
    const btn = document.getElementById('notificationBellBtn');
    if (notificationsOpen && pop && btn && !pop.contains(e.target) && !btn.contains(e.target)) {
        pop.classList.add('hidden');
        notificationsOpen = false;
    }
});

async function loadNotifications() {
    if (!currentUser) return;
    try {
        const res = await fetch('/api/auth.php?action=notifications');
        const data = await res.json();
        if (!data.success) return;

        const badge = document.getElementById('notifBadge');
        const countText = document.getElementById('notifUnreadCountText');
        const list = document.getElementById('notificationsList');

        const unreadCount = data.unread_count || 0;
        if (badge) {
            badge.innerText = unreadCount > 9 ? '9+' : unreadCount;
            if (unreadCount > 0) {
                badge.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
            }
        }
        if (countText) {
            countText.innerText = `${unreadCount} new`;
        }

        if (list) {
            if (!data.notifications || data.notifications.length === 0) {
                list.innerHTML = '<div class="text-slate-500 text-center py-4">No notifications yet</div>';
            } else {
                list.innerHTML = data.notifications.map(n => `
                    <div class="p-2.5 rounded-xl ${n.is_read ? 'bg-slate-950/60' : 'bg-indigo-950/40 border border-indigo-500/30'} space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-white text-xs">${escapeHtml(n.title)}</span>
                            <span class="text-[10px] text-slate-500">${n.created_at ? n.created_at.slice(11, 16) : ''}</span>
                        </div>
                        <p class="text-[11px] text-slate-300 leading-tight">${escapeHtml(n.message)}</p>
                    </div>
                `).join('');
            }
        }
    } catch (e) {
        console.warn('Failed to load notifications:', e);
    }
}

async function markNotificationsRead() {
    try {
        await fetch('/api/auth.php?action=mark_notifications_read', { method: 'POST' });
        loadNotifications();
    } catch (e) {
        console.warn('Failed to mark notifications read:', e);
    }
}

// -------------------------------------------------------------
// PROGRESSIVE WEB APP (PWA) INSTALL & SERVICE WORKER CONTROLLER
// -------------------------------------------------------------

let deferredPwaPrompt = null;

// Register Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            console.log('[PWA] Service Worker registered with scope:', reg.scope);
        }).catch((err) => {
            console.warn('[PWA] Service Worker registration failed:', err);
        });
    });
}

// Capture BeforeInstallPrompt
window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent default mini-infobar on mobile Chrome
    e.preventDefault();
    deferredPwaPrompt = e;

    // Show custom smart install banner unless dismissed in this session
    if (!sessionStorage.getItem('pwaBannerDismissed')) {
        const banner = document.getElementById('pwaInstallBanner');
        if (banner) banner.classList.remove('hidden');
    }

    // Ensure Profile Install Button is visible
    const profileCard = document.getElementById('pwaProfileCard');
    if (profileCard) profileCard.classList.remove('hidden');
});

// App Installed Event
window.addEventListener('appinstalled', () => {
    deferredPwaPrompt = null;
    dismissPwaBanner();
    const profileCard = document.getElementById('pwaProfileCard');
    if (profileCard) {
        profileCard.innerHTML = `
            <div class="flex items-center gap-3.5">
                <div class="w-11 h-11 rounded-2xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-white">JeliPay App Installed</h3>
                    <p class="text-xs text-emerald-400">Running as native Web App on your device</p>
                </div>
            </div>
            <span class="text-xs font-bold text-emerald-400 font-mono px-3 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20">Active</span>
        `;
    }
});

function triggerPwaInstall() {
    if (deferredPwaPrompt) {
        deferredPwaPrompt.prompt();
        deferredPwaPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('[PWA] User accepted the install prompt');
            } else {
                console.log('[PWA] User dismissed the install prompt');
            }
            deferredPwaPrompt = null;
            dismissPwaBanner();
        });
        return;
    }

    // Check if on iOS
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

    if (isStandalone) {
        alert('JeliPay is already installed and running as a native app on your device!');
        return;
    }

    if (isIos) {
        const modal = document.getElementById('iosInstallModal');
        if (modal) modal.classList.remove('hidden');
    } else {
        alert("To install JeliPay:\n1. Tap your browser menu (⋮) in the top right.\n2. Tap 'Install app' or 'Add to Home screen'.");
    }
}

function dismissPwaBanner() {
    const banner = document.getElementById('pwaInstallBanner');
    if (banner) banner.classList.add('hidden');
    sessionStorage.setItem('pwaBannerDismissed', '1');
}

function closeIosInstallModal() {
    const modal = document.getElementById('iosInstallModal');
    if (modal) modal.classList.add('hidden');
}

// ==========================================
// USER EVIDENCE INSPECTION LIGHTBOX (Part 12, Item 2)
// ==========================================
let userLightboxImages = [];
let userLightboxIndex = 0;

function openUserEvidenceLightbox(images, title = 'Payment Screenshot Evidence', startIndex = 0) {
    if (!images) return;
    if (typeof images === 'string') {
        userLightboxImages = images.split(',').map(s => s.trim()).filter(Boolean);
    } else if (Array.isArray(images)) {
        userLightboxImages = images.filter(Boolean);
    } else {
        userLightboxImages = [];
    }
    if (userLightboxImages.length === 0) return;

    userLightboxImages = userLightboxImages.map(img => {
        if (img.startsWith('http://') || img.startsWith('https://') || img.startsWith('/serve_file.php')) {
            return img;
        }
        return `/serve_file.php?file=${encodeURIComponent(img)}`;
    });

    userLightboxIndex = Math.max(0, Math.min(startIndex, userLightboxImages.length - 1));

    const modal = document.getElementById('userEvidenceLightboxModal');
    if (!modal) return;

    const titleEl = document.getElementById('userLightboxTitle');
    if (titleEl) titleEl.innerText = title;

    updateUserLightboxView();
    modal.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

function closeUserEvidenceLightbox() {
    const modal = document.getElementById('userEvidenceLightboxModal');
    if (modal) modal.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

function updateUserLightboxView() {
    if (userLightboxImages.length === 0) return;
    const currentSrc = userLightboxImages[userLightboxIndex];
    const imgEl = document.getElementById('userLightboxImage');
    if (imgEl) imgEl.src = currentSrc;

    const subEl = document.getElementById('userLightboxSubtitle');
    if (subEl) {
        subEl.innerText = `Image ${userLightboxIndex + 1} of ${userLightboxImages.length}`;
    }

    const prevBtn = document.getElementById('userLightboxPrevBtn');
    const nextBtn = document.getElementById('userLightboxNextBtn');
    if (prevBtn && nextBtn) {
        if (userLightboxImages.length > 1) {
            prevBtn.classList.remove('hidden');
            nextBtn.classList.remove('hidden');
        } else {
            prevBtn.classList.add('hidden');
            nextBtn.classList.add('hidden');
        }
    }
}

function navigateUserLightbox(direction) {
    if (userLightboxImages.length <= 1) return;
    userLightboxIndex = (userLightboxIndex + direction + userLightboxImages.length) % userLightboxImages.length;
    updateUserLightboxView();
}

// Global keyboard accessibility for user lightbox modal
document.addEventListener('keydown', (e) => {
    const modal = document.getElementById('userEvidenceLightboxModal');
    if (modal && !modal.classList.contains('hidden')) {
        if (e.key === 'Escape') closeUserEvidenceLightbox();
        if (e.key === 'ArrowLeft') navigateUserLightbox(-1);
        if (e.key === 'ArrowRight') navigateUserLightbox(1);
    }
});
