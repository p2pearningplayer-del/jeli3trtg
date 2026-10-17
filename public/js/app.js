/**
 * JeliPay P2P Platform - Frontend Reactive Application Controller
 * Handles order state machine UI, live timers, proof uploads,
 * double-entry ledger rendering, and administrative arbitration.
 */

let currentUser = null;
let currentTab = 'home';
let activeTimers = {};

document.addEventListener('DOMContentLoaded', () => {
    initApp();
    setInterval(updateLiveCountdowns, 1000);
    setInterval(periodicSync, 5000);
});

async function initApp() {
    await fetchSession();
    const urlParams = new URLSearchParams(window.location.search);
    if (!currentUser) {
        // Unauthenticated visitor stays on the clean intro landing page
        if (urlParams.get('action') === 'register') {
            openAuthModal('register');
        } else if (urlParams.get('action') === 'login' || urlParams.get('tab')) {
            openAuthModal('login');
        }
        return;
    }
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

async function fetchSession() {
    try {
        const res = await fetch('/api/auth.php?action=me');
        const data = await res.json();
        if (data.success && data.user) {
            currentUser = data.user;
            updateHeaderBalances(data.wallet);
            updateProfilePreviews(data.user);
        } else {
            currentUser = null;
        }
    } catch (e) {
        currentUser = null;
    }
}

function updateHeaderBalances(wallet) {
    if (!wallet) return;
    const availEl = document.getElementById('headerAvailableBalance');
    if (availEl) {
        availEl.innerText = `₹${parseFloat(wallet.available_balance).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`;
    }
}

function updateProfilePreviews(user) {
    const previewEl = document.getElementById('sellProfilePreview');
    if (previewEl) {
        if (user.upi_id && user.account_holder_name) {
            previewEl.innerHTML = `<span class="text-white font-semibold">${escapeHtml(user.account_holder_name)}</span> &bull; <span class="font-mono text-emerald-400">${escapeHtml(user.upi_id)}</span>`;
        } else {
            previewEl.innerHTML = `<span class="text-amber-400 font-semibold">Missing UPI info.</span> <button type="button" onclick="switchTab('profile')" class="text-indigo-400 underline ml-1">Configure in Profile</button>`;
        }
    }

    const holderInput = document.getElementById('profHolderName');
    const upiInput = document.getElementById('profUpiId');
    if (holderInput && user.account_holder_name) holderInput.value = user.account_holder_name;
    if (upiInput && user.upi_id) upiInput.value = user.upi_id;

    const phoneDisplay = document.getElementById('profilePhoneDisplay');
    const refCodeDisplay = document.getElementById('profileRefCodeDisplay');
    if (phoneDisplay && user.phone) phoneDisplay.innerText = user.phone;
    if (refCodeDisplay && user.referral_code) refCodeDisplay.innerText = user.referral_code;
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
        btn.classList.remove('text-indigo-400', 'bg-slate-800/90', 'border', 'border-slate-700');
        btn.classList.add('text-slate-400');
    });

    const activeView = document.getElementById(`view-${tabId}`);
    if (activeView) activeView.classList.remove('hidden');

    const activeBtn = document.getElementById(`tabBtn-${tabId}`);
    if (activeBtn) {
        activeBtn.classList.remove('text-slate-400');
        activeBtn.classList.add('text-indigo-400', 'bg-slate-800/90', 'border', 'border-slate-700');
    }

    // Trigger tab data load
    if (tabId === 'home') { loadWalletStats(); loadLedger(); }
    if (tabId === 'buy') { loadBuyPool(); loadMyPurchases(); }
    if (tabId === 'sell') { loadWalletStats(); loadSellerDashboard(); }
    if (tabId === 'refer') loadReferrals();
    if (tabId === 'profile') { loadWalletStats(); loadLedger(); }
    if (tabId === 'admin') loadAdminDashboard();
}

function periodicSync() {
    if (!currentUser) return;
    if (currentTab === 'home') { loadWalletStats(true); }
    if (currentTab === 'buy') { loadBuyPool(true); loadMyPurchases(true); }
    if (currentTab === 'sell') { loadSellerDashboard(true); loadWalletStats(true); }
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

        if (!data.success || !data.orders || data.orders.length === 0) {
            grid.innerHTML = `
                <div class="col-span-full bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <svg class="w-12 h-12 mx-auto text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    <p class="font-bold text-white text-base">No Peer Sell Orders Available</p>
                    <p class="text-xs text-slate-500 mt-1">Be the first to list tokens or specify a custom allocation above.</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = data.orders.map(order => `
            <div class="bg-slate-900 border border-slate-800 hover:border-slate-700/80 rounded-2xl p-5 flex flex-col justify-between transition-all duration-200 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-mono uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-400">Order #${order.id}</span>
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
            alert(`Order #${orderId} reserved successfully! You have 10 minutes to complete the UPI payment.`);
            switchTab('orders');
        } else {
            alert(data.error || 'Failed to pick order.');
            loadBuyPool();
        }
    } catch (e) {
        alert('Network error while reserving order.');
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
            alert(`Order portion for ₹${amount} allocated and reserved! You have 10 minutes to submit payment proof.`);
            input.value = '';
            switchTab('orders');
        } else {
            alert(data.error || 'Allocation failed.');
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
    const tpinInput = document.getElementById('sellTpinInput');

    const amount = parseFloat(amountInput.value);
    const tpin = tpinInput.value.trim();

    if (isNaN(amount) || amount < 100 || amount % 100 !== 0) {
        alert('Sell amount must be at least ₹100 and an exact multiple of ₹100 (e.g. ₹100, ₹500, ₹1000).');
        return;
    }

    if (!tpin) {
        alert('Please enter your Selling TPIN to authorize this listing.');
        return;
    }

    try {
        const res = await fetch('/api/sell.php?action=create_order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ amount, tpin })
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

async function loadSellerDashboard(silent = false) {
    if (!currentUser) return;

    try {
        const res = await fetch('/api/sell.php?action=tracking');
        const data = await res.json();
        const list = document.getElementById('sellerOrdersList');
        if (!list) return;

        if (!data.success || !data.parent_orders || data.parent_orders.length === 0) {
            list.innerHTML = `
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center text-slate-400">
                    <p class="font-bold text-white">No Active Sell Orders</p>
                    <p class="text-xs text-slate-500 mt-1">Your listed sell orders and their child portions will appear here.</p>
                </div>
            `;
            return;
        }

        list.innerHTML = data.parent_orders.map(parent => `
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-800 pb-3 gap-2">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-mono font-bold text-indigo-400">Parent #${parent.id}</span>
                            <span class="text-xs px-2 py-0.5 rounded uppercase font-bold ${parent.status === 'completed' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-slate-800 text-slate-300'}">${parent.status}</span>
                        </div>
                        <p class="text-xs text-slate-400 mt-0.5">Snapshotted UPI: <span class="font-mono text-slate-200">${escapeHtml(parent.snapshotted_upi_id)}</span></p>
                    </div>
                    <div class="flex items-center gap-4 text-xs font-mono">
                        <div><span class="text-slate-500 block">Total</span> <strong class="text-white">₹${parseFloat(parent.original_amount).toLocaleString('en-IN')}</strong></div>
                        <div><span class="text-slate-500 block">Completed</span> <strong class="text-emerald-400">₹${parseFloat(parent.completed_amount).toLocaleString('en-IN')}</strong></div>
                        <div><span class="text-slate-500 block">Remaining</span> <strong class="text-slate-300">₹${parseFloat(parent.remaining_amount).toLocaleString('en-IN')}</strong></div>
                    </div>
                </div>

                <!-- Child portions list -->
                <div class="space-y-2">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Micro-Order Portions (${parent.children.length})</span>
                    <div class="grid grid-cols-1 gap-2">
                        ${parent.children.map(child => renderSellerChildCard(child)).join('')}
                    </div>
                </div>
            </div>
        `).join('');
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
                ${child.screenshot_path ? `<a href="/serve_file.php?file=${encodeURIComponent(child.screenshot_path)}" target="_blank" class="text-xs text-indigo-400 underline font-semibold ml-auto">View Buyer Proof</a>` : ''}
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

    return `
        <div class="bg-slate-950/70 border border-slate-800/80 rounded-xl p-3 text-xs">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-white font-mono">₹${parseFloat(child.amount).toLocaleString('en-IN')}</span>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded ${statusBadges[child.status] || 'bg-slate-800 text-slate-400'}">${child.status}</span>
                </div>
                <div class="text-right">
                    ${child.seconds_remaining > 0 ? `
                        <span class="font-mono font-bold text-amber-400" id="timer_child_${child.id}">
                            ${formatSeconds(child.seconds_remaining)}
                        </span>
                    ` : ''}
                </div>
            </div>

            ${child.buyer_phone_masked ? `
                <div class="text-[11px] text-slate-400 mt-1 flex justify-between">
                    <span>Buyer: <strong>${escapeHtml(child.buyer_name || 'Buyer')} (${escapeHtml(child.buyer_phone_masked)})</strong></span>
                    ${child.utr_number ? `<span>UTR: <strong class="text-slate-200 font-mono">${escapeHtml(child.utr_number)}</strong></span>` : ''}
                </div>
            ` : ''}

            ${actions}
        </div>
    `;
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
                                <button onclick="openProofModal(${p.id}, ${p.amount}, '${escapeHtml(p.snapshotted_upi_id)}', '${escapeHtml(p.snapshotted_account_holder)}')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-md shadow-emerald-600/30">
                                    Submit UPI Proof
                                </button>
                            ` : (p.status === 'Seller Confirmation Pending' ? `
                                <div class="text-right">
                                    <span class="text-slate-400 text-[11px] block">Seller SLA Verification:</span>
                                    <span class="text-sm font-bold font-mono text-indigo-400" id="timer_order_${p.id}">
                                        ${formatSeconds(p.seconds_remaining)}
                                    </span>
                                    ${p.utr_number ? `<span class="text-[11px] text-slate-500 block mt-1 font-mono">UTR: ${escapeHtml(p.utr_number)}</span>` : ''}
                                </div>
                            ` : (p.status === 'Completed' ? `
                                <div class="text-emerald-400 font-bold text-xs flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>Settled to Wallet</span>
                                </div>
                            ` : `
                                <span class="text-slate-500 text-xs">Status: ${p.status}</span>
                            `))}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('Failed to load purchases:', e);
    }
}

function openProofModal(orderId, amount, sellerUpi, sellerName) {
    document.getElementById('proofModalOrderId').innerText = orderId;
    document.getElementById('proofOrderIdInput').value = orderId;
    document.getElementById('proofModalAmount').innerText = `₹${parseFloat(amount).toLocaleString('en-IN')}`;
    document.getElementById('proofModalSellerUpi').innerText = sellerUpi;
    document.getElementById('proofModalSellerName').innerText = sellerName;
    document.getElementById('proofUtrInput').value = '';
    document.getElementById('proofScreenshotPath').value = '';
    document.getElementById('uploadStatusText').innerText = 'Upload clear screenshot showing UTR and success status';
    document.getElementById('proofError').classList.add('hidden');
    document.getElementById('proofModal').classList.remove('hidden');
}

function closeProofModal() {
    document.getElementById('proofModal').classList.add('hidden');
}

async function handleFileUpload(fileInput, type) {
    const file = fileInput.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);

    const statusEl = (type === 'evidence') ? document.getElementById('appealUploadStatusText') : document.getElementById('uploadStatusText');
    if (statusEl) statusEl.innerHTML = '<span class="text-indigo-400 font-semibold">Uploading encrypted file...</span>';

    try {
        const res = await fetch('/api/uploads.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            if (type === 'evidence') {
                document.getElementById('appealEvidencePath').value = data.file_path;
            } else {
                document.getElementById('proofScreenshotPath').value = data.file_path;
            }
            if (statusEl) statusEl.innerHTML = '<span class="text-emerald-400 font-semibold">✓ Upload completed</span>';
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
        if (hAvail) hAvail.innerText = availFormatted;

        const hResv = document.getElementById('homeStatReserved');
        if (hResv) hResv.innerText = resvFormatted;

        const hRew = document.getElementById('homeStatRewards');
        if (hRew) hRew.innerText = rewFormatted;

        const hComm = document.getElementById('homeStatCommissions');
        if (hComm) hComm.innerText = commFormatted;

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
        const fallbackBody = document.getElementById('ledgerTableBody');

        if (!data.success || !data.ledger || data.ledger.length === 0) {
            const emptyRowHome = `<tr><td colspan="6" class="p-6 text-center text-slate-500 font-sans">No wallet transactions recorded yet.</td></tr>`;
            const emptyRowProfile = `<tr><td colspan="7" class="p-6 text-center text-slate-500 font-sans">No wallet audit records found.</td></tr>`;
            if (homeBody) homeBody.innerHTML = emptyRowHome;
            if (profileBody) profileBody.innerHTML = emptyRowProfile;
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
        if (fallbackBody) fallbackBody.innerHTML = homeHtml;
    } catch (e) {
        console.error('Failed to load ledger:', e);
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
                        ${d.evidence_screenshot_path ? `<a href="/serve_file.php?file=${encodeURIComponent(d.evidence_screenshot_path)}" target="_blank" class="text-xs text-indigo-400 underline font-semibold block mt-1">View Seller Evidence</a>` : ''}
                    </div>

                    <!-- Buyer Proof Box -->
                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800/80 space-y-2">
                        <span class="text-emerald-400 font-bold uppercase tracking-wider block">Buyer Payment Submission</span>
                        <div class="text-slate-300 font-mono">UTR: <strong class="text-white">${escapeHtml(d.utr_number || 'N/A')}</strong></div>
                        <div class="text-[11px] text-slate-400">
                            Buyer: <strong class="text-white">${escapeHtml(d.buyer_name || '')} (${escapeHtml(d.buyer_phone || '')})</strong>
                        </div>
                        ${d.buyer_screenshot ? `<a href="/serve_file.php?file=${encodeURIComponent(d.buyer_screenshot)}" target="_blank" class="text-xs text-indigo-400 underline font-semibold block mt-1">View Buyer Payment Screenshot</a>` : ''}
                    </div>
                </div>

                ${d.status === 'pending' || d.status === 'under_review' ? `
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 pt-2 border-t border-slate-800">
                        <input type="text" id="dispNotes_${d.id}" placeholder="Mandatory resolution notes for audit trail..." class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <button onclick="arbitrateDispute(${d.id}, 'force_settle')" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-lg text-xs uppercase tracking-wider transition">
                            Force Settle (Buyer)
                        </button>
                        <button onclick="arbitrateDispute(${d.id}, 'cancel_reverse')" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-bold rounded-lg text-xs uppercase tracking-wider transition">
                            Cancel & Return (Seller)
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
        const nameEl = document.getElementById('authName');
        const refEl = document.getElementById('authReferralCode');
        if (nameEl) payload.name = nameEl.value.trim();
        if (refEl) payload.referral_code = refEl.value.trim();
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
    document.getElementById('profileModal').classList.remove('hidden');
}

function closeProfileModal() {
    document.getElementById('profileModal').classList.add('hidden');
}

async function handleProfileUpdate(e) {
    e.preventDefault();
    const holderName = document.getElementById('profHolderName').value.trim();
    const upiId = document.getElementById('profUpiId').value.trim();

    try {
        const res = await fetch('/api/auth.php?action=update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ account_holder_name: holderName, upi_id: upiId })
        });
        const data = await res.json();
        if (data.success) {
            alert('Banking payout details updated!');
            closeProfileModal();
            fetchSession();
        } else {
            alert(data.error || 'Failed to update details.');
        }
    } catch (e) {
        alert('Network error updating profile.');
    }
}

async function handleTpinUpdate(e) {
    e.preventDefault();
    const tpin = document.getElementById('profNewTpin').value.trim();

    if (!tpin || tpin.length < 4 || tpin.length > 6 || isNaN(tpin)) {
        alert('TPIN must be a 4 to 6 digit numeric PIN.');
        return;
    }

    try {
        const res = await fetch('/api/auth.php?action=set_tpin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tpin })
        });
        const data = await res.json();
        if (data.success) {
            alert('Selling TPIN configured successfully! You can now authorize sell listings.');
            document.getElementById('profNewTpin').value = '';
            closeProfileModal();
            fetchSession();
        } else {
            alert(data.error || 'Failed to configure TPIN.');
        }
    } catch (e) {
        alert('Network error configuring TPIN.');
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    alert('Copied to clipboard: ' + text);
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
