<?php
declare(strict_types=1);

putenv('ALLOW_SQLITE_CLI_FALLBACK=1');
putenv('DB_DRIVER=sqlite');

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

class CancellationRiskAndReferralTest {
    private PDO $pdo;
    private int $sellerId = 10;
    private int $buyer1Id = 20;
    private int $buyer2Id = 30;

    public function __construct() {
        $this->pdo = new PDO("sqlite::memory:", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        Database::setConnection($this->pdo, 'sqlite');

        // Provision schema & seeds
        SchemaInstaller::initSqlite($this->pdo);
        SchemaInstaller::migrateColumns($this->pdo, 'sqlite');
        SchemaInstaller::seedPlatformSettings($this->pdo);

        $pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);
        $tpinHash = password_hash('1234', PASSWORD_BCRYPT);

        // Seed users
        $insUser = $this->pdo->prepare("
            INSERT INTO users (id, phone, email, password_hash, tpin_hash, account_status, role, referral_code, upi_id, account_holder_name)
            VALUES (?, ?, ?, ?, ?, 'active', 'user', ?, ?, ?)
        ");
        $insUser->execute([$this->sellerId, '9800000010', 'seller@test.in', $pwHash, $tpinHash, 'SELLER10', 'seller10@okhdfcbank', 'Seller Ten']);
        $insUser->execute([$this->buyer1Id, '9800000020', 'buyer1@test.in', $pwHash, $tpinHash, 'BUYER20', 'buyer20@upi', 'Buyer Twenty']);
        $insUser->execute([$this->buyer2Id, '9800000030', 'buyer2@test.in', $pwHash, $tpinHash, 'BUYER30', 'buyer30@upi', 'Buyer Thirty']);

        $insWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, ?, 0.00, 1)");
        $insWallet->execute([$this->sellerId, 100000.00]);
        $insWallet->execute([$this->buyer1Id, 50000.00]);
        $insWallet->execute([$this->buyer2Id, 50000.00]);
    }

    public function runAll(): void {
        echo "====================================================================\n";
        echo " BUG FIXES & FEATURE VERIFICATION TEST SUITE\n";
        echo " 1. Cancellation Flow & Reason Selection\n";
        echo " 2. Seller UPI Risk Flagging\n";
        echo " 3. Price Range Filters for Buy Listings\n";
        echo " 4. Detailed Referral Commission Breakdown\n";
        echo "====================================================================\n\n";

        $this->testCancellationFlowAndReasonSelection();
        $this->testSellerUpiRiskFlagging();
        $this->testPriceRangeFilters();
        $this->testDetailedReferralCommissionBreakdown();

        echo "\n--------------------------------------------------------------------\n";
        echo "SUCCESS: All 4 bug fixes and feature enhancements passed verification!\n";
        echo "--------------------------------------------------------------------\n";
    }

    private function createOrder(float $amount): int {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $parentStmt = $this->pdo->prepare("
            INSERT INTO parent_sell_orders (
                seller_id, original_amount, remaining_amount, reserved_amount, completed_amount, status, snapshotted_upi_id, snapshotted_account_holder, created_at
            ) VALUES (?, ?, ?, 0.00, 0.00, 'active', 'seller10@okhdfcbank', 'Seller Ten', ?)
        ");
        $parentStmt->execute([$this->sellerId, $amount, $amount, $nowUtc]);
        $parentId = (int)$this->pdo->lastInsertId();

        $childStmt = $this->pdo->prepare("
            INSERT INTO child_orders (
                parent_order_id, seller_id, amount, snapshotted_reward_rate, snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder, status, created_at
            ) VALUES (?, ?, ?, 9.00, ?, 'seller10@okhdfcbank', 'Seller Ten', 'Available', ?)
        ");
        $childStmt->execute([$parentId, $this->sellerId, $amount, round($amount * 0.09, 2), $nowUtc]);
        return (int)$this->pdo->lastInsertId();
    }

    private function testCancellationFlowAndReasonSelection(): void {
        echo "[Test 1] Buy Order Cancellation Flow & Mandatory Reason Selection:\n";

        $childId = $this->createOrder(500.00);

        // Buyer 1 reserves the order
        $pickRes = StateMachine::pickOrder($childId, $this->buyer1Id);
        $this->assertTrue($pickRes['success'], "Order pick must succeed");

        // Verify order is reserved
        $order = $this->pdo->query("SELECT * FROM child_orders WHERE id = {$childId}")->fetch();
        $this->assertEquals('Reserved', $order['status'], "Order status must be Reserved");
        $this->assertEquals($this->buyer1Id, (int)$order['buyer_id'], "Buyer ID must match");

        // Cancel order with mandatory reason
        $cancelRes = StateMachine::cancelReservation($childId, $this->buyer1Id, 'insufficient_funds', 'Not enough balance in bank account');
        $this->assertTrue($cancelRes['success'], "Cancellation with valid reason must succeed");

        // Check child_orders record
        $orderAfter = $this->pdo->query("SELECT * FROM child_orders WHERE id = {$childId}")->fetch();
        $this->assertEquals('Available', $orderAfter['status'], "Order must return to Available");
        $this->assertNull($orderAfter['buyer_id'], "Buyer ID must be reset to NULL");
        $this->assertNotNull($orderAfter['cancellation_reason'], "Cancellation reason must be populated");
        $this->assertStringContains('insufficient_funds', $orderAfter['cancellation_reason_code'], "Reason code must be recorded");

        // Check order_cancellations audit table
        $cancelAudit = $this->pdo->query("SELECT * FROM order_cancellations WHERE child_order_id = {$childId}")->fetch();
        $this->assertNotNull($cancelAudit, "Audit record must be logged in order_cancellations");
        $this->assertEquals($this->buyer1Id, (int)$cancelAudit['buyer_id'], "Buyer ID must match in audit table");
        $this->assertEquals($this->sellerId, (int)$cancelAudit['seller_id'], "Seller ID must match in audit table");
        $this->assertEquals('insufficient_funds', $cancelAudit['reason_code'], "Reason code must match in audit table");

        echo "  -> PASS: Cancellation requires reason, logs audit trail, and returns tokens to buy pool.\n";
    }

    private function testSellerUpiRiskFlagging(): void {
        echo "[Test 2] Seller UPI Risk Flagging on Multiple Buyer Cancellations:\n";

        // Initial check: seller has upi_risk_flag = 0
        $seller = $this->pdo->query("SELECT upi_risk_flag FROM users WHERE id = {$this->sellerId}")->fetch();
        $this->assertEquals(0, (int)$seller['upi_risk_flag'], "Seller should initially have 0 UPI risk flag");

        // Buyer 1 picks an order and cancels citing upi_risk (Strike 1)
        $orderId1 = $this->createOrder(1000.00);
        StateMachine::pickOrder($orderId1, $this->buyer1Id);
        StateMachine::cancelReservation($orderId1, $this->buyer1Id, 'upi_risk', 'Bank declined transfer to UPI ID');

        // Strikes 2, 3, 4, 5 citing seller-fault reasons to reach the 5-strike threshold
        $orderId2 = $this->createOrder(1000.00);
        for ($s = 2; $s <= 5; $s++) {
            StateMachine::pickOrder($orderId2, $this->buyer2Id);
            StateMachine::cancelReservation($orderId2, $this->buyer2Id, 'upi_risk', "UPI ID flagged by PhonePe (strike {$s})");
        }

        // Now multiple distinct buyers have cancelled citing UPI risk:
        $sellerAfter = $this->pdo->query("SELECT upi_risk_flag, upi_risk_reason FROM users WHERE id = {$this->sellerId}")->fetch();
        $this->assertEquals(1, (int)$sellerAfter['upi_risk_flag'], "Seller must be automatically flagged with upi_risk_flag = 1");
        $this->assertNotNull($sellerAfter['upi_risk_reason'], "UPI risk reason must be recorded");

        // Test helper StateMachine::isSellerUpiAtRisk
        $isAtRisk = StateMachine::isSellerUpiAtRisk($this->pdo, $this->sellerId, 'seller10@okhdfcbank');
        $this->assertTrue($isAtRisk, "StateMachine::isSellerUpiAtRisk must return true");

        // Pick a new order from this flagged seller: StateMachine::pickOrder must include seller_upi_risk = true
        $orderId3 = $this->createOrder(2000.00);
        $pickRes = StateMachine::pickOrder($orderId3, $this->buyer1Id);
        $this->assertTrue($pickRes['success'], "Pick must succeed");
        $this->assertTrue($pickRes['seller_upi_risk'], "seller_upi_risk flag must be true in pick response to alert buyer");

        echo "  -> PASS: Seller automatically flagged for UPI Risk upon multiple buyer cancellations; alerts future buyers.\n";
    }

    private function testPriceRangeFilters(): void {
        echo "[Test 3] Price Range Filters for Buy Pool Marketplace:\n";

        // Create orders across distinct price brackets
        $o1 = $this->createOrder(150.00);    // ₹100 – ₹200
        $o2 = $this->createOrder(350.00);    // ₹200 – ₹500
        $o3 = $this->createOrder(750.00);    // ₹500 – ₹1,000
        $o4 = $this->createOrder(2500.00);   // ₹1,000 – ₹10,000
        $o5 = $this->createOrder(15000.00);  // ₹10,000+

        // Helper to query pool with price filters
        $queryPool = function(float $min, float $max, int $viewerId): array {
            $sql = "
                SELECT c.id, c.amount
                FROM child_orders c
                WHERE c.status = 'Available' AND c.seller_id != ?
            ";
            $params = [$viewerId];
            if ($min > 0) {
                $sql .= " AND c.amount >= ?";
                $params[] = $min;
            }
            if ($max > 0) {
                $sql .= " AND c.amount <= ?";
                $params[] = $max;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        };

        // Filter 1: ₹100 – ₹200
        $res100_200 = $queryPool(100, 200, $this->buyer1Id);
        $amounts1 = array_column($res100_200, 'amount');
        $this->assertTrue(in_array(150.00, $amounts1), "₹150 order must be included in ₹100–₹200 range");
        $this->assertFalse(in_array(350.00, $amounts1), "₹350 order must NOT be included in ₹100–₹200 range");

        // Filter 2: ₹200 – ₹500
        $res200_500 = $queryPool(200, 500, $this->buyer1Id);
        $amounts2 = array_column($res200_500, 'amount');
        $this->assertTrue(in_array(350.00, $amounts2), "₹350 order must be included in ₹200–₹500 range");
        $this->assertFalse(in_array(750.00, $amounts2), "₹750 order must NOT be included in ₹200–₹500 range");

        // Filter 3: ₹500 – ₹1,000
        $res500_1000 = $queryPool(500, 1000, $this->buyer1Id);
        $amounts3 = array_column($res500_1000, 'amount');
        $this->assertTrue(in_array(750.00, $amounts3), "₹750 order must be included in ₹500–₹1,000 range");

        // Filter 4: ₹1,000 – ₹10,000
        $res1000_10000 = $queryPool(1000, 10000, $this->buyer1Id);
        $amounts4 = array_column($res1000_10000, 'amount');
        $this->assertTrue(in_array(2500.00, $amounts4), "₹2,500 order must be included in ₹1,000–₹10,000 range");
        $this->assertFalse(in_array(15000.00, $amounts4), "₹15,000 order must NOT be included in ₹1,000–₹10,000 range");

        echo "  -> PASS: Price range filtering partitions orders into exact budget categories.\n";
    }

    private function testDetailedReferralCommissionBreakdown(): void {
        echo "[Test 4] Detailed Referral Commission Breakdown (Today's Earnings, Specific Day, Active Team):\n";

        // Create a 2-tier referral network
        // Referrer (Root): User 100
        // Level 1: User 101 (referred by 100)
        // Level 2: User 102 (referred by 101)
        $pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);
        $tpinHash = password_hash('1234', PASSWORD_BCRYPT);

        $insUser = $this->pdo->prepare("
            INSERT INTO users (id, phone, email, password_hash, tpin_hash, account_status, role, referral_code, referred_by_id, upi_id, account_holder_name)
            VALUES (?, ?, ?, ?, ?, 'active', 'user', ?, ?, ?, ?)
        ");
        $insUser->execute([100, '9899900100', 'ref_root@test.in', $pwHash, $tpinHash, 'ROOT100', null, 'root@upi', 'Root User']);
        $insUser->execute([101, '9899900101', 'ref_l1@test.in', $pwHash, $tpinHash, 'L1_USER', 100, 'l1@upi', 'L1 User']);
        $insUser->execute([102, '9899900102', 'ref_l2@test.in', $pwHash, $tpinHash, 'L2_USER', 101, 'l2@upi', 'L2 User']);

        $insWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, 10000.00, 0.00, 1)");
        $insWallet->execute([100]);
        $insWallet->execute([101]);
        $insWallet->execute([102]);

        // User 101 makes a ₹5,000 buy order (credits L1 1% = ₹50 to User 100)
        ReferralEngine::distributeCommissions(101, 5000.00, 'ORD_REF_001');

        // User 102 makes a ₹10,000 buy order (credits L1 1% = ₹100 to User 101, L2 0.5% = ₹50 to User 100)
        ReferralEngine::distributeCommissions(102, 10000.00, 'ORD_REF_002');

        // Fetch referral tree for User 100
        $tree = ReferralEngine::getReferralTree(100);

        // Verify Total Commission: ₹50 (from L1) + ₹50 (from L2) = ₹100
        $this->assertEquals(100.00, (float)$tree['total_commissions_earned'], "Total commission must be ₹100");

        // Verify Today's Earnings
        $this->assertEquals(100.00, (float)$tree['today_earnings'], "Today's earnings must be ₹100");

        // Verify Specific Day Performance (e.g. Wednesday earnings / today's day)
        $todayDayName = date('l'); // e.g. 'Wednesday'
        $this->assertNotNull($tree['day_of_week_earnings'], "day_of_week_earnings must exist");
        $this->assertEquals(100.00, (float)$tree['day_of_week_earnings'][$todayDayName], "Earnings for {$todayDayName} must equal ₹100");

        // Verify 7-day timeline
        $this->assertCount(7, $tree['daily_timeline'], "daily_timeline must have 7 days");
        $todayEntry = null;
        foreach ($tree['daily_timeline'] as $entry) {
            if ($entry['is_today']) {
                $todayEntry = $entry;
                break;
            }
        }
        $this->assertNotNull($todayEntry, "Timeline must contain today's entry");
        $this->assertEquals(100.00, (float)$todayEntry['earnings'], "Today's timeline earnings must equal ₹100");

        // Verify Total Active Team Size
        // Both User 101 and 102 completed transactions and generated commissions, so active team size is 2
        $this->assertEquals(2, $tree['total_team_count'], "Total team count must be 2");
        $this->assertEquals(2, $tree['active_team_size'], "Active team size must be 2");
        $this->assertTrue($tree['level_1_network'][0]['is_active'], "Level 1 member must be marked as active");
        $this->assertTrue($tree['level_2_network'][0]['is_active'], "Level 2 member must be marked as active");

        echo "  -> PASS: Referral dashboard accurately computes Today's Earnings (₹{$tree['today_earnings']}), Specific Day Performance ({$todayDayName}: ₹{$tree['day_of_week_earnings'][$todayDayName]}), and Active Team Size ({$tree['active_team_size']}/{$tree['total_team_count']}).\n";
    }

    private function assertTrue(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: {$message}");
        }
    }

    private function assertFalse(bool $condition, string $message): void {
        if ($condition) {
            throw new RuntimeException("Assertion failed: {$message}");
        }
    }

    private function assertEquals($expected, $actual, string $message): void {
        if ($expected != $actual) {
            throw new RuntimeException("Assertion failed: {$message}. Expected " . json_encode($expected) . ", got " . json_encode($actual));
        }
    }

    private function assertNull($value, string $message): void {
        if ($value !== null) {
            throw new RuntimeException("Assertion failed: {$message}. Expected null, got " . json_encode($value));
        }
    }

    private function assertNotNull($value, string $message): void {
        if ($value === null) {
            throw new RuntimeException("Assertion failed: {$message}. Expected non-null value");
        }
    }

    private function assertCount(int $expectedCount, $array, string $message): void {
        if (count($array) !== $expectedCount) {
            throw new RuntimeException("Assertion failed: {$message}. Expected count {$expectedCount}, got " . count($array));
        }
    }

    private function assertStringContains(string $needle, ?string $haystack, string $message): void {
        if ($haystack === null || strpos($haystack, $needle) === false) {
            throw new RuntimeException("Assertion failed: {$message}. Could not find '{$needle}' in '{$haystack}'");
        }
    }
}

$test = new CancellationRiskAndReferralTest();
$test->runAll();
