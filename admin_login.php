<?php
/**
 * Administrator Secure Access Portal
 * Dedicated authentication page for platform administrators.
 * Production Domain: https://jelipay.in/admin_login.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/schema_installer.php';
require_once __DIR__ . '/src/Auth.php';

SchemaInstaller::ensureSchema();

$currentUser = Auth::getCurrentUser();
if ($currentUser && ($currentUser['role'] === 'admin' || !empty($currentUser['real_admin_id']))) {
    header('Location: /admin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Portal Login | JeliPay P2P Platform</title>
    <!-- Official Favicon & Icons -->
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
    </style>
</head>
<body class="h-full flex items-center justify-center p-4 bg-slate-950 text-slate-100 selection:bg-amber-500 selection:text-black">
    <div class="max-w-md w-full space-y-6">
        <!-- Brand & Shield -->
        <div class="text-center space-y-3">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-600 to-amber-400 flex items-center justify-center shadow-xl shadow-amber-500/20 border border-amber-400/30">
                <svg class="w-7 h-7 text-slate-950" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <div>
                <h1 class="text-2xl font-black text-white tracking-tight flex items-center justify-center gap-2">
                    JeliPay <span class="text-xs uppercase font-mono px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30">Admin Console</span>
                </h1>
                <p class="text-xs text-slate-400 mt-1">Authorized Management & Governance Access Only</p>
            </div>
        </div>

        <!-- Login Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-2xl space-y-5">
            <div id="loginAlert" class="hidden p-3 rounded-xl text-xs font-semibold bg-red-500/10 border border-red-500/20 text-red-400"></div>

            <form onsubmit="handleAdminLogin(event)" class="space-y-4">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-300 block mb-1.5">
                        Admin ID / Email / Phone
                    </label>
                    <input type="text" id="adminIdentifier" required placeholder="Admin ID, Email, or Phone"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm text-white focus:border-amber-500 focus:outline-none transition">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-300">
                            Administrator Password
                        </label>
                        <span class="text-[11px] text-slate-500 font-mono">Bcrypt Protected</span>
                    </div>
                    <input type="password" id="adminPassword" required placeholder="••••••••••••"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm text-white focus:border-amber-500 focus:outline-none transition">
                </div>

                <button type="submit" id="adminLoginBtn"
                        class="w-full bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 text-slate-950 font-black py-3.5 rounded-xl shadow-lg shadow-amber-500/20 text-xs uppercase tracking-widest transition flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                    <span>Authenticate Session</span>
                </button>
            </form>
        </div>

        <!-- Back to marketplace -->
        <div class="text-center">
            <a href="/" class="text-xs text-slate-500 hover:text-slate-300 transition">
                &larr; Return to Public Token Trading Marketplace
            </a>
        </div>
    </div>

    <script>
        async function handleAdminLogin(e) {
            e.preventDefault();
            const identifier = document.getElementById('adminIdentifier').value.trim();
            const password = document.getElementById('adminPassword').value;
            const alertBox = document.getElementById('loginAlert');
            const submitBtn = document.getElementById('adminLoginBtn');

            alertBox.classList.add('hidden');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="animate-spin mr-2">&#9696;</span> Verifying Security...';

            try {
                const res = await fetch('/api/auth.php?action=admin_login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ identifier, password })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.href = '/admin.php';
                } else {
                    alertBox.innerText = data.error || 'Authentication rejected.';
                    alertBox.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<span>Authenticate Session</span>';
                }
            } catch (err) {
                alertBox.innerText = 'Network error during administrative authentication.';
                alertBox.classList.remove('hidden');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>Authenticate Session</span>';
            }
        }
    </script>
</body>
</html>
