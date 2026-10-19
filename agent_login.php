<?php
/**
 * Agent Portal Login View
 * Route: /agent/login (or /agent)
 * Provides isolated authentication for Agents requiring Agent User ID, Password, and 6-digit TOTP code.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/schema_installer.php';
require_once __DIR__ . '/src/AgentManager.php';

SchemaInstaller::ensureSchema();

// If already authenticated as agent, redirect to dashboard
$currentAgent = AgentManager::getCurrentAgent();
if ($currentAgent) {
    header('Location: /agent/dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Agent Portal Login | JeliPay</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta name="theme-color" content="#020617">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-tap-highlight-color: transparent; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="h-full text-slate-100 flex items-center justify-center p-4 sm:p-6 antialiased selection:bg-amber-500 selection:text-black bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-amber-950/20 via-slate-950 to-slate-950">

    <div class="w-full max-w-md">
        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center h-14 w-14 rounded-2xl bg-gradient-to-tr from-amber-600 to-amber-400 shadow-xl shadow-amber-500/20 text-slate-950 font-black mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-black tracking-tight text-white flex items-center justify-center gap-2">
                <span>JeliPay</span>
                <span class="text-xs font-mono font-bold uppercase px-2.5 py-0.5 rounded-full bg-amber-500/10 text-amber-400 border border-amber-500/30">Agent Portal</span>
            </h1>
            <p class="text-sm text-slate-400 mt-2">Authorized Agent Referral & Commission Intelligence Console</p>
        </div>

        <!-- Login Card -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl backdrop-blur-xl relative">
            <div id="errorAlert" class="hidden mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-start gap-3">
                <svg class="w-5 h-5 text-rose-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span id="errorMessage"></span>
            </div>

            <form id="agentLoginForm" class="space-y-5" onsubmit="handleAgentLogin(event)">
                <!-- Agent User ID -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Agent User ID</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </span>
                        <input type="text" id="agentCode" required placeholder="e.g. AGT1001" autocomplete="username"
                               class="w-full pl-11 pr-4 py-3 bg-slate-950/70 border border-slate-800 rounded-xl text-white font-mono uppercase placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition">
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider">Password</label>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </span>
                        <input type="password" id="agentPassword" required placeholder="••••••••••••" autocomplete="current-password"
                               class="w-full pl-11 pr-11 py-3 bg-slate-950/70 border border-slate-800 rounded-xl text-white placeholder:text-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-500 hover:text-slate-300 transition">
                            <svg id="eyeIcon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Google Authenticator 2FA Code -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            <span>Google Authenticator Code</span>
                        </label>
                        <span class="text-[11px] text-amber-400/90 font-mono">6 Digits Required</span>
                    </div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </span>
                        <input type="text" id="agentTotp" required maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="000000" autocomplete="one-time-code"
                               class="w-full pl-11 pr-4 py-3 bg-slate-950/70 border border-slate-800 rounded-xl text-white font-mono text-center tracking-[0.3em] text-lg placeholder:text-slate-700 placeholder:tracking-normal focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition">
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" id="submitBtn"
                        class="w-full py-3.5 px-4 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-slate-950 font-bold rounded-xl shadow-lg shadow-amber-500/20 transition-all flex items-center justify-center gap-2 group disabled:opacity-50">
                    <span id="btnText">Verify & Enter Dashboard</span>
                    <svg id="btnSpinner" class="hidden animate-spin h-5 w-5 text-slate-950" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                </button>
            </form>

            <!-- Security Badge Footer -->
            <div class="mt-6 pt-6 border-t border-slate-800/80 text-center">
                <div class="flex items-center justify-center gap-2 text-xs text-slate-500">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <span>AES-256-GCM Encrypted at Rest &bull; Zero Replay Tolerance</span>
                </div>
            </div>
        </div>

        <div class="text-center mt-6 text-xs text-slate-500">
            <a href="/" class="hover:text-amber-400 transition">&larr; Return to JeliPay Home</a>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const input = document.getElementById('agentPassword');
            const icon = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }

        async function handleAgentLogin(e) {
            e.preventDefault();
            const alertBox = document.getElementById('errorAlert');
            const errorMsg = document.getElementById('errorMessage');
            const btn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');

            alertBox.classList.add('hidden');
            btn.disabled = true;
            btnText.textContent = 'Verifying Credentials...';
            btnSpinner.classList.remove('hidden');

            const payload = {
                agent_code: document.getElementById('agentCode').value.trim(),
                password: document.getElementById('agentPassword').value,
                totp_code: document.getElementById('agentTotp').value.trim()
            };

            try {
                const res = await fetch('/api/agent?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.success) {
                    btnText.textContent = 'Redirecting...';
                    window.location.href = data.redirect || '/agent/dashboard';
                } else {
                    errorMsg.textContent = data.error || 'Login verification failed.';
                    alertBox.classList.remove('hidden');
                    document.getElementById('agentTotp').value = '';
                    document.getElementById('agentTotp').focus();
                    btn.disabled = false;
                    btnText.textContent = 'Verify & Enter Dashboard';
                    btnSpinner.classList.add('hidden');
                }
            } catch (err) {
                errorMsg.textContent = 'Network or server communication error. Please try again.';
                alertBox.classList.remove('hidden');
                btn.disabled = false;
                btnText.textContent = 'Verify & Enter Dashboard';
                btnSpinner.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
