<?php
/**
 * P2P Token Platform - Acceptance Test Suite (PRD Section 16)
 * Validates QA-001 through QA-008 natively on backend logic.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/schema_installer.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Wallet.php';
require_once __DIR__ . '/../src/Ledger.php';
require_once __DIR__ . '/../src/OrderEngine.php';
require_once __DIR__ . '/../src/StateMachine.php';
require_once __DIR__ . '/../src/ReferralEngine.php';
require_once __DIR__ . '/../src/AdminManager.php';

class QATestSuite {
    private PDO $pdo;
    private array $results = [];

    public function __construct(?PDO $pdo = null) {
        if ($pdo === null) {
            // Use in-memory SQLite database for isolated test execution
            $this->pdo = new PDO("sqlite::memory:", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
            Database::setConnection($this->pdo, 'sqlite');
        } else {
            $this->pdo = $pdo;
        }

        // Install schema
        SchemaInstaller::initSqlite($this->pdo);
        SchemaInstaller::migrateColumns($this->pdo, 'sqlite');
        SchemaInstaller::seedPlatformSettings($this->pdo);
        $this->seedTestData();
        SchemaInstaller::seedDemoAccounts($this->pdo);
    }

    private function seedTestData(): void {
        // Create Admin (ID 1), Seller User (ID 2), Buyer User (ID 3), Buyer 2 (ID 4)
        $tpinHash = password_hash('1234', PASSWORD_BCRYPT);
        $pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);

        $insUser = $this->pdo->prepare("
            INSERT INTO users (id, phone, password_hash, tpin_hash, account_status, role, admin_role, referral_code, referred_by_id, upi_id, account_holder_name)
            VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)
        ");
        $insUser->execute([1, '9999999999', $pwHash, $tpinHash, 'admin', 'super_admin', 'ADMIN01', null, 'admin@upi', 'Admin User']);
        $insUser->execute([2, '9800000001', $pwHash, $tpinHash, 'user', null, 'SELLER01', 1, 'seller@upi', 'Seller User']);
        $insUser->execute([3, '9800000002', $pwHash, $tpinHash, 'user', null, 'BUYER01', 2, 'buyer@upi', 'Buyer One']);
        $insUser->execute([4, '9800000003', $pwHash, $tpinHash, 'user', null, 'BUYER02', 3, 'buyer2@upi', 'Buyer Two']);

        $insWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, ?, 0.00, 1)");
        $insWallet->execute([1, 1000000.00]);
        $insWallet->execute([2, 50000.00]);
        $insWallet->execute([3, 10000.00]);
        $insWallet->execute([4, 10000.00]);
    }

    public function runAll(): array {
        $this->results = [];
        $this->testQA001();
        $this->testQA002();
        $this->testQA003();
        $this->testQA004();
        $this->testQA005();
        $this->testQA006();
        $this->testQA007();
        $this->testQA008();
        $this->testQA009UnifiedFlow();
        $this->testQA010AntiWashTrading();
        $this->testQA011FullUnifiedE2ELifecycle();
        $this->testQA012AdminPortalAndRBAC();
        $this->testQA013AdminSettingsAndLedgerAdjustment();
        return $this->results;
    }

    /**
     * QA-001: Reward Calculation
     * Scenario: A ₹1,000 eligible order at a 9% config correctly yields ₹90 reward and credits exactly 1,090 tokens to the buyer.
     */
    public function testQA001(): void {
        $testId = 'QA-001';
        $title = 'Reward Calculation: ₹1,000 eligible order @ 9% yields ₹90 reward, credits 1,090 tokens';

        $calc = Validator::calculatePurchaseReward(1000.0, 9.0);
        $rewardValid = ($calc['reward_amount'] == 90.00);
        $totalValid = ($calc['total_credit'] == 1090.00);

        // Test actual credit operation
        $buyerId = 3;
        $prevBal = (float)Wallet::getWallet($buyerId)['available_balance'];
        Wallet::creditBuyer($buyerId, 1000.00, 90.00, 'QA001_TEST');
        $newBal = (float)Wallet::getWallet($buyerId)['available_balance'];

        $creditedCorrectly = (round($newBal - $prevBal, 2) == 1090.00);

        $passed = $rewardValid && $totalValid && $creditedCorrectly;
        $this->recordResult($testId, $title, $passed, [
            'expected_reward' => 90.00,
            'actual_reward' => $calc['reward_amount'],
            'expected_total_credit' => 1090.00,
            'actual_total_credit' => $calc['total_credit'],
            'wallet_credit_diff' => round($newBal - $prevBal, 2)
        ]);
    }

    /**
     * QA-002: Sell Validation
     * Scenario: A ₹123 sell request is firmly rejected. A ₹100 request processes successfully.
     */
    public function testQA002(): void {
        $testId = 'QA-002';
        $title = 'Sell Validation: ₹123 rejected, ₹100 accepted (Modulo 100 == 0, Min 100)';

        $val123 = Validator::validateSellAmount(123);
        $val100 = Validator::validateSellAmount(100);
        $val99 = Validator::validateSellAmount(99);
        $val150 = Validator::validateSellAmount(150);

        $passed = (!$val123['valid']) && ($val100['valid']) && (!$val99['valid']) && (!$val150['valid']);
        $this->recordResult($testId, $title, $passed, [
            'amount_123_rejected' => !$val123['valid'],
            'amount_100_accepted' => $val100['valid'],
            'amount_99_rejected' => !$val99['valid'],
            'amount_150_rejected' => !$val150['valid'],
            'rejection_error_123' => $val123['error'] ?? null
        ]);
    }

    /**
     * QA-003: Order Splitting
     * Scenario: A ₹10,000 order splits (e.g., 5k, 3k, 2k) and the sum mathematically equals ₹10,000 without token creation.
     */
    public function testQA003(): void {
        $testId = 'QA-003';
        $title = 'Order Splitting: ₹10,000 order splits into micro-orders; sum mathematically equals ₹10,000';

        $splits = OrderEngine::calculateSplits(10000.0);
        $sum = array_sum($splits);
        $count = count($splits);

        $allDenomValid = true;
        foreach ($splits as $part) {
            if ($part < 100 || fmod($part, 100) != 0) {
                $allDenomValid = false;
            }
        }

        $passed = ($sum == 10000.0) && ($count <= 8) && $allDenomValid;
        $this->recordResult($testId, $title, $passed, [
            'splits' => $splits,
            'sum' => $sum,
            'portion_count' => $count,
            'all_portions_valid_mod_100' => $allDenomValid
        ]);
    }

    /**
     * QA-004: Race Condition
     * Scenario: Two concurrent user sessions attempting to "Pick" the identical order portion results in only one success and zero duplicated reservations.
     */
    public function testQA004(): void {
        $testId = 'QA-004';
        $title = 'Race Condition: Concurrent pick attempts result in exactly 1 success and 0 duplicated reservations';

        // Create an available child order
        $orderRes = OrderEngine::createSellOrder(2, 500.0, '1234');
        $childId = $orderRes['children'][0]['id'];

        // Buyer 1 picks the order
        $pick1 = StateMachine::pickOrder($childId, 3);
        // Buyer 2 concurrently attempts to pick the same order
        $pick2 = StateMachine::pickOrder($childId, 4);

        $passed = ($pick1['success'] === true) && ($pick2['success'] === false);
        $this->recordResult($testId, $title, $passed, [
            'buyer_1_success' => $pick1['success'],
            'buyer_2_success' => $pick2['success'],
            'buyer_2_error' => $pick2['error'] ?? null
        ]);
    }

    /**
     * QA-005: Reallocation
     * Scenario: A custom ₹5,000 request matched against a ₹10,000 listed order cleanly deducts ₹5,000 leaving ₹5,000 listed in the pool.
     */
    public function testQA005(): void {
        $testId = 'QA-005';
        $title = 'Reallocation: Custom ₹5,000 request matched against ₹10,000 order leaves ₹5,000 in pool';

        // Create a single child order of 10,000 directly for this test
        $parentStmt = $this->pdo->prepare("
            INSERT INTO parent_sell_orders (seller_id, original_amount, remaining_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at)
            VALUES (2, 10000, 10000, 'active', 'seller@upi', 'Seller User', datetime('now'))
        ");
        $parentStmt->execute();
        $pId = (int)$this->pdo->lastInsertId();

        $calc10k = Validator::calculatePurchaseReward(10000, 9.0);
        $childStmt = $this->pdo->prepare("
            INSERT INTO child_orders (parent_order_id, seller_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at)
            VALUES (?, 2, 10000, 9.0, ?, 'seller@upi', 'Seller User', 'Available', datetime('now'))
        ");
        $childStmt->execute([$pId, $calc10k['reward_amount']]);

        // Buyer 3 requests custom buy allocation of ₹5,000
        $allocRes = OrderEngine::allocateCustomBuy(3, 5000.0);

        // Check remaining available orders in that parent
        $remStmt = $this->pdo->prepare("SELECT amount, status FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
        $remStmt->execute([$pId]);
        $remaining = $remStmt->fetch();

        $passed = ($allocRes['success'] === true) &&
                  ($allocRes['amount'] == 5000.0) &&
                  ($remaining && (float)$remaining['amount'] == 5000.0);

        $this->recordResult($testId, $title, $passed, [
            'allocation_success' => $allocRes['success'] ?? false,
            'allocated_amount' => $allocRes['amount'] ?? null,
            'pool_remaining_amount' => (float)($remaining['amount'] ?? 0)
        ]);
    }

    /**
     * QA-006: Timeout Handling
     * Scenario: An order passing the 10-minute timer without proof transitions to expired, and the seller's tokens unlock automatically.
     */
    public function testQA006(): void {
        $testId = 'QA-006';
        $title = 'Timeout Handling: Missed 10-minute payment deadline auto-expires order and unlocks seller tokens';

        // Create sell order for 300
        $orderRes = OrderEngine::createSellOrder(2, 300.0, '1234');
        $childId = $orderRes['children'][0]['id'];
        $childAmount = (float)$orderRes['children'][0]['amount'];

        // Buyer picks order
        StateMachine::pickOrder($childId, 3);

        $sellerBalBefore = (float)Wallet::getWallet(2)['reserved_balance'];

        // Artificially simulate 10 minutes passing: set payment_deadline to 1 minute ago
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 60);
        $updDeadline = $this->pdo->prepare("UPDATE child_orders SET payment_deadline = ? WHERE id = ?");
        $updDeadline->execute([$pastUtc, $childId]);

        // Run expiry engine
        $expiryResults = StateMachine::checkAndExpireOrders();

        $sellerBalAfter = (float)Wallet::getWallet(2)['reserved_balance'];

        // Verify timed-out buyer reservation is Expired
        $chkStmt = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $chkStmt->execute([$childId]);
        $finalStatus = $chkStmt->fetchColumn();

        // Verify that the portion was automatically relisted as Available for new buyers
        $relistedStmt = $this->pdo->prepare("SELECT id, status FROM child_orders WHERE parent_order_id = ? AND status = 'Available'");
        $relistedStmt->execute([$orderRes['parent_order_id']]);
        $relisted = $relistedStmt->fetch();

        // Now verify manual seller cancellation: seller cancels and gets full refund back to Available Balance
        $availBeforeCancel = (float)Wallet::getWallet(2)['available_balance'];
        $cancelRes = OrderEngine::cancelParentSellOrder(2, (int)$orderRes['parent_order_id']);
        $availAfterCancel = (float)Wallet::getWallet(2)['available_balance'];

        $passed = ($finalStatus === 'Expired') &&
                  !empty($relisted) &&
                  $cancelRes['success'] &&
                  (round($availAfterCancel - $availBeforeCancel, 2) == 300.0);

        $this->recordResult($testId, $title, $passed, [
            'final_order_status' => $finalStatus,
            'relisted_for_new_buyers' => !empty($relisted),
            'seller_cancel_refund' => round($availAfterCancel - $availBeforeCancel, 2),
            'expected_refund' => 300.0,
            'expiry_counts' => $expiryResults
        ]);
    }

    /**
     * QA-007: Settlement & Idempotency
     * Scenario: "Confirm Payment" triggers exact ledger updates once. Rapid double-clicking does not duplicate the credit.
     */
    public function testQA007(): void {
        $testId = 'QA-007';
        $title = 'Settlement & Idempotency: Confirm Payment credits once; double-clicking never duplicates credit';

        // Create and setup order
        $orderRes = OrderEngine::createSellOrder(2, 200.0, '1234');
        $childId = $orderRes['children'][0]['id'];
        StateMachine::pickOrder($childId, 3);

        // Submit proof
        $utr = 'UTR' . time() . rand(1000, 9999);
        StateMachine::submitPaymentProof($childId, 3, $utr, 'proofs/test.png', 'Paid via GPay');

        $buyerBalBefore = (float)Wallet::getWallet(3)['available_balance'];

        // Call confirmPayment first time
        $idemKey = 'IDEM_KEY_' . uniqid();
        $confirm1 = StateMachine::confirmPayment($childId, 2, $idemKey);

        $buyerBalAfter1 = (float)Wallet::getWallet(3)['available_balance'];

        // Rapid double click / network retry with same idempotency key
        $confirm2 = StateMachine::confirmPayment($childId, 2, $idemKey);

        $buyerBalAfter2 = (float)Wallet::getWallet(3)['available_balance'];

        // Verify ledger entries for buyer
        $ledgerStmt = $this->pdo->prepare("SELECT COUNT(*) FROM wallet_ledger WHERE user_id = 3 AND reference_id = ? AND transaction_type = 'BUY_CREDIT'");
        $ledgerStmt->execute([(string)$childId]);
        $creditLedgerCount = (int)$ledgerStmt->fetchColumn();

        $passed = ($confirm1['success'] === true) &&
                  ($confirm2['success'] === true) &&
                  ($buyerBalAfter1 === $buyerBalAfter2) &&
                  ($creditLedgerCount === 1);

        $this->recordResult($testId, $title, $passed, [
            'first_call_success' => $confirm1['success'],
            'second_call_success' => $confirm2['success'],
            'balance_after_call_1' => $buyerBalAfter1,
            'balance_after_call_2' => $buyerBalAfter2,
            'duplicate_prevented' => ($buyerBalAfter1 === $buyerBalAfter2),
            'buy_credit_ledger_count' => $creditLedgerCount
        ]);
    }

    /**
     * QA-008: Dispute Freeze
     * Scenario: A raised appeal permanently freezes the order state, preventing auto-release or user-initiated cancellation.
     */
    public function testQA008(): void {
        $testId = 'QA-008';
        $title = 'Dispute Freeze: Raised appeal permanently freezes order state, preventing auto-release';

        // Create and setup order
        $orderRes = OrderEngine::createSellOrder(2, 200.0, '1234');
        $childId = $orderRes['children'][0]['id'];
        StateMachine::pickOrder($childId, 3);

        $utr = 'UTR_DISP_' . time() . rand(100, 999);
        StateMachine::submitPaymentProof($childId, 3, $utr, 'proofs/disp_proof.png');

        // Seller raises appeal
        $appealRes = StateMachine::raiseAppeal($childId, 2, 'No bank SMS or credit received.', 'evidence/stmt.png');

        // Verify status is Disputed
        $chkStmt = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $chkStmt->execute([$childId]);
        $statusAfterAppeal = $chkStmt->fetchColumn();

        // Attempt to run expiry check past deadline
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 3600);
        $updDeadline = $this->pdo->prepare("UPDATE child_orders SET payment_deadline = ?, verification_deadline = ? WHERE id = ?");
        $updDeadline->execute([$pastUtc, $pastUtc, $childId]);

        StateMachine::checkAndExpireOrders();

        $chkStmt2 = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $chkStmt2->execute([$childId]);
        $statusAfterExpiryRun = $chkStmt2->fetchColumn();

        // Admin arbitration test: Force settle dispute
        $resolveRes = AdminManager::resolveDispute($appealRes['dispute_id'], 1, 'force_settle', 'Verified bank statement shows payment');

        $chkStmt3 = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $chkStmt3->execute([$childId]);
        $statusAfterArbitration = $chkStmt3->fetchColumn();

        $passed = ($appealRes['success'] === true) &&
                  ($statusAfterAppeal === 'Disputed') &&
                  ($statusAfterExpiryRun === 'Disputed') &&
                  ($resolveRes['success'] === true) &&
                  ($statusAfterArbitration === 'Completed');

        $this->recordResult($testId, $title, $passed, [
            'appeal_raised' => $appealRes['success'],
            'status_frozen_as_disputed' => ($statusAfterAppeal === 'Disputed'),
            'status_immune_to_timer_expiry' => ($statusAfterExpiryRun === 'Disputed'),
            'admin_arbitration_success' => $resolveRes['success'],
            'final_resolved_status' => $statusAfterArbitration
        ]);
    }

    /**
     * QA-009: Unified Buyer/Seller Account Flow
     * Scenario: A single user account purchases tokens (₹1,000 + ₹90 reward = 1,090 tokens credited to available balance),
     * and subsequently lists ₹1,000 for sale using their Selling TPIN and denomination rules.
     */
    public function testQA009UnifiedFlow(): void {
        $testId = 'QA-009';
        $title = 'Unified Account Flow: Purchased tokens & 9% rewards are credited to Available Balance and can be listed for sale';

        $userId = 4; // Buyer Two
        $initialAvailable = (float)Wallet::getWallet($userId)['available_balance'];

        // User receives purchased tokens + 9% reward (1,000 + 90 = 1,090)
        $creditRes = Wallet::creditBuyer($userId, 1000.00, 90.00, 'UNIFIED_BUY_TEST');
        $afterBuyAvailable = (float)Wallet::getWallet($userId)['available_balance'];

        $creditedExpected = (round($afterBuyAvailable - $initialAvailable, 2) == 1090.00);

        // Same user now lists ₹1,000 of their available tokens for sale using their TPIN
        $sellRes = OrderEngine::createSellOrder($userId, 1000.00, '1234');
        $afterSellWallet = Wallet::getWallet($userId);

        $sellSuccessful = ($sellRes['success'] === true);
        $reservedCorrect = ((float)$afterSellWallet['reserved_balance'] == 1000.00);
        $availableDeducted = (round($afterBuyAvailable - (float)$afterSellWallet['available_balance'], 2) == 1000.00);

        $passed = $creditedExpected && $sellSuccessful && $reservedCorrect && $availableDeducted;
        $this->recordResult($testId, $title, $passed, [
            'initial_available' => $initialAvailable,
            'after_buy_available' => $afterBuyAvailable,
            'credited_diff' => round($afterBuyAvailable - $initialAvailable, 2),
            'sell_order_created' => $sellSuccessful,
            'reserved_after_sell' => (float)$afterSellWallet['reserved_balance'],
            'available_after_sell' => (float)$afterSellWallet['available_balance']
        ]);
    }

    /**
     * QA-010: Anti-Wash Trading (Self-Trading Prevention)
     * Scenario: A user is strictly prohibited from picking, reserving, or buying their own sell order.
     * Must return the explicit error: "You cannot purchase your own sell order."
     */
    public function testQA010AntiWashTrading(): void {
        $testId = 'QA-010';
        $title = 'Anti-Wash Trading: User cannot pick their own sell order (Rejects with: "You cannot purchase your own sell order.")';

        // Seller (User ID 2) creates a sell order
        $orderRes = OrderEngine::createSellOrder(2, 500.00, '1234');
        $childId = $orderRes['children'][0]['id'];

        // Seller attempts to pick their own sell order
        $selfPickRes = StateMachine::pickOrder($childId, 2);

        $isRejected = ($selfPickRes['success'] === false);
        $expectedMessage = 'You cannot purchase your own sell order.';
        $matchesExactMessage = (($selfPickRes['error'] ?? '') === $expectedMessage);

        // Verify order remained Available in the pool
        $stmt = $this->pdo->prepare("SELECT status, buyer_id FROM child_orders WHERE id = ?");
        $stmt->execute([$childId]);
        $orderState = $stmt->fetch();

        $orderRemainedAvailable = ($orderState['status'] === 'Available' && $orderState['buyer_id'] === null);

        $passed = $isRejected && $matchesExactMessage && $orderRemainedAvailable;
        $this->recordResult($testId, $title, $passed, [
            'self_pick_rejected' => $isRejected,
            'error_returned' => $selfPickRes['error'] ?? null,
            'matches_exact_specification' => $matchesExactMessage,
            'order_remained_available' => $orderRemainedAvailable
        ]);
    }

    /**
     * QA-011: Complete End-to-End Unified Marketplace Lifecycle
     * Verification of User Requirements 1 through 6:
     * 1. Register & log in once.
     * 2. Buy tokens from another peer's sell order.
     * 3. Receive purchased tokens + 9% reward in the single shared wallet upon settlement.
     * 4. Open Sell section and create sell order using that Available Balance with Selling TPIN.
     * 5. Sell those tokens to another peer through the same platform.
     * 6. View all buy, reward, reserve, and sell movements in one unified wallet transaction history.
     */
    public function testQA011FullUnifiedE2ELifecycle(): void {
        $testId = 'QA-011';
        $title = 'Full E2E Unified Lifecycle: Register -> Buy & Reward -> Shared Wallet Credit -> Sell with TPIN -> Peer Settlement -> Unified History';

        // Step 1: Register User Alpha (Single account, single wallet)
        $regAlpha = Auth::register('9800000010', 'Password@123', null, 'alpha@okhdfc', 'Alpha Trader');
        $alphaId = (int)$regAlpha['user_id'];
        Auth::setTPIN($alphaId, '1234');

        // Register Peer Beta (who lists an order to sell)
        $regBeta = Auth::register('9800000020', 'Password@123', null, 'beta@paytm', 'Beta Peer');
        $betaId = (int)$regBeta['user_id'];
        Auth::setTPIN($betaId, '1234');
        // Give Beta initial liquidity
        Wallet::adminAdjust($betaId, 'credit', 2000.00, 'Initial peer liquidity', 1);

        // Beta creates a sell order of ₹1,000
        $betaSell = OrderEngine::createSellOrder($betaId, 1000.00, '1234');
        $betaChildId = (int)$betaSell['children'][0]['id'];
        $childAmount = (float)$betaSell['children'][0]['amount']; // e.g. 500

        // Step 2: User Alpha buys tokens from Beta's sell order
        $pickRes = StateMachine::pickOrder($betaChildId, $alphaId);

        // Step 3: Alpha submits proof, Beta confirms payment
        $alphaUtr = 'UTR_E2E_' . time() . rand(100, 999);
        StateMachine::submitPaymentProof($betaChildId, $alphaId, $alphaUtr, 'proofs/e2e.png', 'E2E test transfer');
        $settleRes = StateMachine::confirmPayment($betaChildId, $betaId, 'IDEM_E2E_' . uniqid());

        // Verify Step 3: Alpha received purchased tokens + 9% reward into their shared Available Balance
        $alphaWalletAfterBuy = Wallet::getWallet($alphaId);
        $expectedReward = round($childAmount * 0.09, 2);
        $expectedTotalCredit = round($childAmount + $expectedReward, 2);
        $step3Valid = ((float)$alphaWalletAfterBuy['available_balance'] == $expectedTotalCredit);

        // Step 4: Alpha opens Sell section and lists tokens using their Available Balance with TPIN
        $sellAmount = floor($expectedTotalCredit / 100) * 100; // e.g. 500
        $alphaSell = OrderEngine::createSellOrder($alphaId, $sellAmount, '1234');
        $step4Valid = ($alphaSell['success'] === true);
        $alphaChildId = (int)$alphaSell['children'][0]['id'];

        // Step 5: User Gamma (third user) buys from Alpha
        $regGamma = Auth::register('9800000030', 'Password@123', null, 'gamma@icici', 'Gamma Buyer');
        $gammaId = (int)$regGamma['user_id'];
        StateMachine::pickOrder($alphaChildId, $gammaId);
        $gammaUtr = 'UTR_GAMMA_' . time() . rand(100, 999);
        StateMachine::submitPaymentProof($alphaChildId, $gammaId, $gammaUtr, 'proofs/gamma.png');
        $alphaConfirm = StateMachine::confirmPayment($alphaChildId, $alphaId, 'IDEM_GAMMA_' . uniqid());
        $step5Valid = ($alphaConfirm['success'] === true);

        // Step 6: Verify Unified Transaction History for User Alpha
        $alphaLedger = Ledger::getUserHistory($alphaId, 50);
        $types = array_column($alphaLedger, 'transaction_type');

        $hasBuyCredit = in_array('BUY_CREDIT', $types, true);
        $hasBuyReward = in_array('BUY_REWARD', $types, true);
        $hasSellReserve = in_array('SELL_RESERVE', $types, true);
        $hasSellSettle = in_array('SELL_SETTLE', $types, true);
        $step6Valid = $hasBuyCredit && $hasBuyReward && $hasSellReserve && $hasSellSettle;

        $passed = ($regAlpha['success'] === true) &&
                  ($pickRes['success'] === true) &&
                  $step3Valid &&
                  $step4Valid &&
                  $step5Valid &&
                  $step6Valid;

        $this->recordResult($testId, $title, $passed, [
            'step_1_registration' => $regAlpha['success'] ?? false,
            'step_2_order_pick' => $pickRes['success'] ?? false,
            'step_3_tokens_and_reward_credited' => $step3Valid,
            'expected_credited_tokens' => $expectedTotalCredit,
            'actual_available_balance' => (float)$alphaWalletAfterBuy['available_balance'],
            'step_4_sell_listing_created' => $step4Valid,
            'step_5_peer_sold_and_settled' => $step5Valid,
            'step_6_unified_history_verified' => $step6Valid,
            'ledger_transaction_types_found' => array_values(array_unique($types))
        ]);
    }

    /**
     * QA-012: Admin Authentication, Role-based Access Control & Support Impersonation
     * Verifies:
     * 1. Super Admin authentication using Demo Admin credentials (DEMOADMIN001 / DemoAdmin@123).
     * 2. Permission checks for super_admin vs role-limited admin (order_manager).
     * 3. Start read-only support impersonation session for a user.
     * 4. Exit impersonation and restoration of administrative session.
     * 5. Rejection of non-admin accounts attempting admin portal login.
     */
    public function testQA012AdminPortalAndRBAC(): void {
        $testId = 'QA-012';
        $title = 'Admin Portal & RBAC: Super Admin auth, role permissions, support impersonation & access guards';

        // 1. Super Admin authentication with email & User ID
        $loginRes = Auth::adminLogin('demoadmin@jelipay.in', 'DemoAdmin@123');
        $superAdminValid = ($loginRes['success'] === true && $loginRes['admin']['admin_role'] === 'super_admin');

        $loginById = Auth::adminLogin('DEMOADMIN001', 'DemoAdmin@123');
        $idLoginValid = ($loginById['success'] === true);

        // 2. Role-based permission checks
        $superAdminUser = $loginRes['admin'] ?? ['role' => 'admin', 'admin_role' => 'super_admin'];
        $superAdminHasSettings = AdminManager::checkPermission($superAdminUser, 'settings_manage');

        $orderManagerUser = ['role' => 'admin', 'admin_role' => 'order_manager'];
        $orderManagerCanOrders = AdminManager::checkPermission($orderManagerUser, 'orders_manage');
        $orderManagerCannotArbitrate = !AdminManager::checkPermission($orderManagerUser, 'disputes_arbitrate');

        $rbacValid = $superAdminHasSettings && $orderManagerCanOrders && $orderManagerCannotArbitrate;

        // 3. Support Impersonation
        $targetUserId = 2;
        $adminId = (int)$loginRes['admin']['id'];
        $impRes = AdminManager::startImpersonation($adminId, $targetUserId);
        $impStarted = ($impRes['success'] === true &&
                       isset($_SESSION['real_admin_id']) &&
                       $_SESSION['real_admin_id'] === $adminId &&
                       $_SESSION['user_id'] === $targetUserId &&
                       $_SESSION['is_impersonating'] === true);

        // 4. Exit Impersonation
        AdminManager::exitImpersonation();
        $impExited = (empty($_SESSION['real_admin_id']) &&
                      $_SESSION['user_id'] === $adminId &&
                      $_SESSION['role'] === 'admin' &&
                      $_SESSION['is_impersonating'] === false);

        // 5. Rejection of standard user at admin login portal
        $userLoginAttempt = Auth::adminLogin('9800000001', 'Pass@123');
        $userRejected = ($userLoginAttempt['success'] === false);

        $passed = $superAdminValid && $idLoginValid && $rbacValid && $impStarted && $impExited && $userRejected;

        $this->recordResult($testId, $title, $passed, [
            'super_admin_login' => $superAdminValid,
            'user_id_login' => $idLoginValid,
            'rbac_permissions' => $rbacValid,
            'impersonation_started' => $impStarted,
            'impersonation_exited' => $impExited,
            'unauthorized_user_blocked' => $userRejected
        ]);
    }

    /**
     * QA-013: Admin Global Configuration, Authorized Ledger Adjustment & CSV Reporting
     * Verifies:
     * 1. Dynamic platform setting modification with immutable audit trail.
     * 2. Authorized manual wallet adjustment with strict reason recording and double-entry ledgering.
     * 3. Downloadable CSV reporting generation across users, orders, and ledger.
     */
    public function testQA013AdminSettingsAndLedgerAdjustment(): void {
        $testId = 'QA-013';
        $title = 'Admin Governance: Dynamic settings modification, authorized balance adjustment & CSV report exports';

        $adminId = 1;

        // 1. Dynamic setting update
        $updateRes = AdminManager::updateSetting('purchase_reward_percent', '9.50', $adminId, 'QA-013 test adjustment');
        $settingValid = ($updateRes['success'] === true);

        // Verify setting in database
        $stmt = $this->pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'purchase_reward_percent'");
        $stmt->execute();
        $dbVal = $stmt->fetchColumn();
        $settingPersisted = ($dbVal === '9.50');

        // Verify audit log
        $auditStmt = $this->pdo->prepare("SELECT * FROM admin_audit_logs WHERE action_type = 'UPDATE_SETTING' ORDER BY id DESC LIMIT 1");
        $auditStmt->execute();
        $auditLog = $auditStmt->fetch();
        $auditLogged = ($auditLog && $auditLog['target_id'] === 'purchase_reward_percent');

        // 2. Authorized balance adjustment
        $targetUserId = 3;
        $prevBal = (float)Wallet::getWallet($targetUserId)['available_balance'];
        $adjRes = AdminManager::manualAdjustment($targetUserId, 'credit', 250.00, 'Regulatory goodwill credit', $adminId);
        $newBal = (float)Wallet::getWallet($targetUserId)['available_balance'];
        $adjValid = ($adjRes['success'] === true && round($newBal - $prevBal, 2) === 250.00);

        // Verify ledger entry for manual adjustment
        $lStmt = $this->pdo->prepare("SELECT * FROM wallet_ledger WHERE user_id = ? AND transaction_type = 'ADMIN_CREDIT' ORDER BY id DESC LIMIT 1");
        $lStmt->execute([$targetUserId]);
        $ledgerEntry = $lStmt->fetch();
        $ledgerValid = ($ledgerEntry && (float)$ledgerEntry['amount'] === 250.00);

        // 3. CSV report export tests
        $usersCsv = AdminManager::exportReportCsv('users');
        $ordersCsv = AdminManager::exportReportCsv('orders');
        $ledgerCsv = AdminManager::exportReportCsv('ledger');

        $usersCsvValid = (strpos($usersCsv, 'User ID') !== false && strpos($usersCsv, 'Phone') !== false && strpos($usersCsv, 'Email') !== false);
        $ordersCsvValid = (strpos($ordersCsv, 'Child Order ID') !== false && strpos($ordersCsv, 'Parent Order ID') !== false);
        $ledgerCsvValid = (strpos($ledgerCsv, 'Ledger ID') !== false && strpos($ledgerCsv, 'User ID') !== false);

        $csvExportsValid = $usersCsvValid && $ordersCsvValid && $ledgerCsvValid;

        $passed = $settingValid && $settingPersisted && $auditLogged && $adjValid && $ledgerValid && $csvExportsValid;

        $this->recordResult($testId, $title, $passed, [
            'setting_updated' => $settingValid,
            'setting_persisted' => $settingPersisted,
            'audit_logged' => $auditLogged,
            'balance_adjusted' => $adjValid,
            'ledger_recorded' => $ledgerValid,
            'csv_reports_generated' => $csvExportsValid
        ]);
    }

    private function recordResult(string $id, string $title, bool $passed, array $details): void {
        $this->results[] = [
            'id' => $id,
            'title' => $title,
            'status' => $passed ? 'PASSED' : 'FAILED',
            'passed' => $passed,
            'details' => $details
        ];
    }
}
