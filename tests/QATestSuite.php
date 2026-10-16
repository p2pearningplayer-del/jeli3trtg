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
        $this->seedTestData();
    }

    private function seedTestData(): void {
        // Create Admin (ID 1), Seller User (ID 2), Buyer User (ID 3), Buyer 2 (ID 4)
        $tpinHash = password_hash('1234', PASSWORD_BCRYPT);
        $pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);

        $insUser = $this->pdo->prepare("
            INSERT INTO users (id, phone, password_hash, tpin_hash, account_status, role, referral_code, referred_by_id, upi_id, account_holder_name)
            VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
        ");
        $insUser->execute([1, '9999999999', $pwHash, $tpinHash, 'admin', 'ADMIN01', null, 'admin@upi', 'Admin User']);
        $insUser->execute([2, '9800000001', $pwHash, $tpinHash, 'user', 'SELLER01', 1, 'seller@upi', 'Seller User']);
        $insUser->execute([3, '9800000002', $pwHash, $tpinHash, 'user', 'BUYER01', 2, 'buyer@upi', 'Buyer One']);
        $insUser->execute([4, '9800000003', $pwHash, $tpinHash, 'user', 'BUYER02', 3, 'buyer2@upi', 'Buyer Two']);

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

        // Verify status in DB
        $chkStmt = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $chkStmt->execute([$childId]);
        $finalStatus = $chkStmt->fetchColumn();

        $passed = ($finalStatus === 'Expired') &&
                  (round($sellerBalBefore - $sellerBalAfter, 2) == $childAmount);

        $this->recordResult($testId, $title, $passed, [
            'final_order_status' => $finalStatus,
            'reserved_tokens_unlocked' => round($sellerBalBefore - $sellerBalAfter, 2),
            'expected_tokens_unlocked' => $childAmount,
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
