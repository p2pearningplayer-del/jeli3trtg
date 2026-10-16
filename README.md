# JeliPay — Peer-to-Peer (P2P) Token Trading & Order Management Platform
**Specification:** Product Requirements Document (PRD v1.0)  
**Production Domain:** [https://jelipay.in/](https://jelipay.in/)  
**Public Repository:** `https://github.com/p2pearningplayer-del/jeli3trtg.git`  
**Target Branch:** `main`

---

## 1. Executive Summary & Architectural Overview

JeliPay is an enterprise-grade, web-based Peer-to-Peer (P2P) Token Trading and Order Management Platform. It enables seamless, verifiable token exchange between registered users via manual UPI payment proofs, underpinned by a double-entry, append-only internal wallet ledger.

### Core Business Parameters
- **Token Baseline Valuation:** 1 Platform Token = ₹1.00.
- **Purchase Reward Engine:** Configured at **9%** of eligible token purchase amount (`Reward = Amount * 0.09`; `Total Credit = Amount + Reward`). *Promotional platform configuration; not an investment vehicle.*
- **Sell Limits & Modulo Validation:** Minimum sell amount ₹100. Strictly requires `Amount MOD 100 == 0`. (e.g., ₹100, ₹500, ₹3,000 are valid; ₹99, ₹123, ₹150, ₹454 are rejected).
- **Automated Order Splitting Engine:** Automatically divides large parent sell listings into up to 8 manageable micro-order child portions (e.g., ₹10,000 splits to [5k, 3k, 2k]) without token creation.
- **Multi-Tier Referral Engine:** 2-tier commission attribution (Level 1: 1.0%, Level 2: 0.5%) on completed buy transactions, with loop prevention and PII masking.
- **Weekly Volume Target:** ₹4,00,000 milestone progress tracking (`Progress % = (Completed Vol / 400000) * 100`, capped at 100%).

---

## 2. Technical Stack & Hosting Configuration

- **Platform Target:** Responsive Web Application only (Desktop, Tablet, Mobile). Zero mobile wrappers (No APK, No TWA/PWA).
- **Backend Runtime:** PHP 8.3+ (Native Hostinger LiteSpeed/Apache environment).
- **Frontend:** Responsive HTML5, Tailwind CSS, Vanilla Reactive State Machine Controller (`app.js`).
- **Database Engine:** MySQL 8.0+ / MariaDB with strict InnoDB foreign keys and transactional row-level locking.
- **Production Credentials:**
  - `DB_HOST`: `localhost`
  - `DB_NAME`: `u591469534_jelii`
  - `DB_USER`: `u591469534_jelii123`
  - `DB_PASS`: `Jelii@123@`
  - `DB_PORT`: `3306`

---

## 3. Order State Machine & Server Timers

```
[Available] ──> Buyer Picks ──> [Reserved] (10-minute UTC Countdown)
                                  │
                  ┌───────────────┴───────────────┐
                  ▼                               ▼
       Buyer Submits UTR & Proof           10m Window Expired
                  │                               │
                  ▼                               ▼
  [Seller Confirmation Pending]               [Expired]
  (15-minute Seller SLA Window)      (Tokens returned to Seller pool)
                  │
        ┌─────────┴─────────┐
        ▼                   ▼
 Seller Confirms      Seller Appeals / 15m Timeout
        │                   │
        ▼                   ▼
   [Completed]          [Disputed] / [Seller Response Timeout]
(Tokens + 9% Reward      (Frozen Tokens escalated to Admin Arbitration)
 Credited to Buyer;                 │
 Seller Settled)            ┌───────┴───────┐
                            ▼               ▼
                      Admin Approves   Admin Rejects
                            │               │
                            ▼               ▼
                       [Completed]  [Cancelled / Reversed]
```

### Server-Side UTC Timers
All deadlines are calculated and evaluated against authoritative server UTC timestamps (`gmdate('Y-m-d H:i:s')`). Frontend reboots, timezone differences, or client clock skew never compromise deadline evaluation.

---

## 4. Wallet Ledger Architecture & Segregation

The system implements strict balance segregation to prevent double-spending or negative balances:
- **Available Balance:** Free tokens ready for listing or transfer.
- **Reserved Balance:** Escrowed tokens committed to active sell orders.
- **Pending Credits:** Inflight incoming buyer token purchases.
- **Total Rewards Earned:** Cumulative 9% promotional rewards.
- **Total Commissions Earned:** Cumulative Level 1 and Level 2 referral commissions.

All financial state changes write append-only records to `wallet_ledger`:
- `BUY_CREDIT`
- `BUY_REWARD`
- `SELL_RESERVE`
- `SELL_SETTLE`
- `SELL_RELEASE`
- `REFERRAL_L1`
- `REFERRAL_L2`
- `ADMIN_CREDIT`
- `ADMIN_DEBIT`
- `DISPUTE_REVERSAL`

---

## 5. Security & Access Control

1. **Bcrypt Hashing:** Passwords and Selling TPINs are hashed using PHP `password_hash($val, PASSWORD_BCRYPT)`.
2. **Selling TPIN:** Secondary 4–6 digit transaction PIN required to authorize every sell order listing.
3. **Idempotency Keys:** Settlement operations employ idempotency keys to ensure rapid double-clicking never duplicates credits.
4. **Rate Limiting:** Sliding-window attempt throttling on login, TPIN validation, and order reservations.
5. **Private Upload Gateway:** Payment proofs and dispute evidence are validated for MIME type (`image/jpeg`, `image/png`, `image/webp`), stored with cryptographically random filenames in protected storage, and served via `serve_file.php` with authorization checks (Buyer, Seller, or Admin).
6. **PII Masking:** Referral trees and active transaction profiles mask mobile numbers (e.g., `98******01`) to protect user data.
7. **Admin Support Mode (FR-022):** Privileged read-only impersonation view with top warning banner; financial executions are locked.

---

## 6. Acceptance QA Scenarios (QA-001 through QA-008)

The platform passes all 8 PRD acceptance tests natively on backend logic:

| Test ID | Scenario | Acceptance Criteria | Result |
|---|---|---|---|
| **QA-001** | Reward Calculation | ₹1,000 eligible order @ 9% yields ₹90 reward and credits exactly 1,090 tokens | **PASSED** (100%) |
| **QA-002** | Sell Validation | ₹123 sell request is rejected; ₹100 request processes successfully (`mod 100 == 0`) | **PASSED** (100%) |
| **QA-003** | Order Splitting | ₹10,000 order splits into micro-orders; sum mathematically equals ₹10,000 | **PASSED** (100%) |
| **QA-004** | Race Condition | Concurrent pick attempts on identical order result in 1 success and 0 duplicates | **PASSED** (100%) |
| **QA-005** | Reallocation | Custom ₹5,000 request matched against ₹10,000 order leaves ₹5,000 in pool | **PASSED** (100%) |
| **QA-006** | Timeout Handling | Missed 10-minute payment deadline auto-expires order and unlocks seller tokens | **PASSED** (100%) |
| **QA-007** | Settlement & Idempotency | Confirm Payment triggers ledger updates once; double-clicks never duplicate credit | **PASSED** (100%) |
| **QA-008** | Dispute Freeze | Raised appeal permanently freezes order state, preventing auto-release | **PASSED** (100%) |

### Running the Test Suite
- **CLI Runner:**
  ```bash
  php tests/run_tests.php
  ```
- **Live Web Certification Report:**
  Navigate to [https://jelipay.in/test_qa.php](https://jelipay.in/test_qa.php) to inspect live execution results.

---

## 7. Default System Credentials & Seed Accounts

### Administrator Account
- **Phone:** `9999999999`
- **Password:** `Admin@123456`
- **Role:** `admin`
- **Selling TPIN:** `1234`
- **Referral Code:** `ADMIN001`
- **Initial Reserve:** `1,000,000.00 Tokens`

### Demo Seller Account
- **Phone:** `9876543210`
- **Password:** `User@123456`
- **Role:** `user`
- **Selling TPIN:** `1234`
- **Referral Code:** `USER001`
- **Initial Balance:** `50,000.00 Tokens`
- **UPI:** `user1@okhdfcbank`

### Demo Buyer Account (Level 2 Network Peer)
- **Phone:** `9811122233`
- **Password:** `User@123456`
- **Role:** `user`
- **Selling TPIN:** `1234`
- **Referral Code:** `USER002`
- **Initial Balance:** `20,000.00 Tokens`
- **UPI:** `user2@paytm`

---

## 8. RESTful API Endpoint Reference

| Endpoint | Method | Action | Description |
|---|---|---|---|
| `/api/auth.php` | `POST` | `login` | Authenticate with phone & password |
| `/api/auth.php` | `POST` | `register` | Register new account with optional referral code |
| `/api/auth.php` | `GET` | `me` | Retrieve authenticated user profile & balance |
| `/api/auth.php` | `POST` | `set_tpin` | Configure 4–6 digit Selling TPIN |
| `/api/auth.php` | `POST` | `update_profile` | Update UPI ID and account holder name |
| `/api/buy.php` | `GET` | `pool` | List available micro-order portions in buy pool |
| `/api/buy.php` | `POST` | `pick` | Atomically reserve order portion (starts 10m countdown) |
| `/api/buy.php` | `POST` | `custom_allocation` | Auto-match & split large order for custom buy amount |
| `/api/buy.php` | `POST` | `submit_proof` | Submit UPI UTR number and payment screenshot |
| `/api/buy.php` | `GET` | `my_purchases` | List active purchases with live deadlines |
| `/api/sell.php` | `POST` | `create_order` | Create sell listing with TPIN authorization & splitting |
| `/api/sell.php` | `GET` | `tracking` | Seller dashboard with parent-child micro-order tracking |
| `/api/sell.php` | `POST` | `confirm_payment` | Idempotent token settlement to buyer |
| `/api/sell.php` | `POST` | `raise_appeal` | Freeze order into Disputed state with statement evidence |
| `/api/wallet.php` | `GET` | `summary` | Authoritative database balance sums & weekly progress % |
| `/api/wallet.php` | `GET` | `ledger` | Paginated immutable double-entry ledger history |
| `/api/referrals.php`| `GET` | `stats` | Masked 2-tier referral tree and earnings |
| `/api/admin.php` | `GET` | `stats` | Macro platform health metrics |
| `/api/admin.php` | `GET` | `disputes` | List active disputes and payment evidence |
| `/api/admin.php` | `POST` | `arbitrate` | Force settlement or cancel/reverse with audit note |
| `/api/admin.php` | `POST` | `manual_adjustment`| Manual wallet debit/credit with audit reason |
| `/api/admin.php` | `POST` | `impersonate` | Activate Read-Only Admin Support Mode |
| `/api/admin.php` | `POST` | `settings` | Update global platform switches (reward %, timers, limits) |
| `/api/uploads.php`| `POST` | — | Upload encrypted screenshot proof / dispute evidence |
| `/serve_file.php` | `GET` | — | Protected evidence viewer with transaction authorization |
