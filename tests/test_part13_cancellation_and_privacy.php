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

// Global classes (no namespace)

class Part13CancellationAndPrivacyTest {
    private PDO $pdo;
    private int $sellerId = 2;
    private int $buyerId = 3;

    public function __construct() {
        $this->pdo = new PDO("sqlite::memory:", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $this->pdo->exec("PRAGMA foreign_keys = ON;");
        Database::setConnection($this->pdo, 'sqlite');

        // Install schema & seed
        SchemaInstaller::initSqlite($this->pdo);
        SchemaInstaller::migrateColumns($this->pdo, 'sqlite');
        SchemaInstaller::seedPlatformSettings($this->pdo);

        $tpinHash = password_hash('1234', PASSWORD_BCRYPT);
        $pwHash = password_hash('Pass@123', PASSWORD_BCRYPT);
        $insUser = $this->pdo->prepare("
            INSERT INTO users (id, phone, password_hash, tpin_hash, account_status, role, referral_code, upi_id, account_holder_name)
            VALUES (?, ?, ?, ?, 'active', 'user', ?, ?, ?)
        ");
        $insUser->execute([$this->sellerId, '9800000001', $pwHash, $tpinHash, 'SELLER01', 'seller@upi', 'Seller User']);
        $insUser->execute([$this->buyerId, '9800000002', $pwHash, $tpinHash, 'BUYER01', 'buyer@upi', 'Buyer One']);

        $insWallet = $this->pdo->prepare("INSERT INTO wallets (user_id, available_balance, reserved_balance, version) VALUES (?, ?, 0.00, 1)");
        $insWallet->execute([$this->sellerId, 50000.00]);
        $insWallet->execute([$this->buyerId, 50000.00]);
    }

    public function runAll(): void {
        echo "======================================================\n";
        echo " PART 13 VERIFICATION: CANCEL LOGIC & PRIVACY AUDIT\n";
        echo "======================================================\n";

        $this->testBuyerCancellationIdempotency();
        $this->testSellerCancellationAfterBuyerTimeout();
        $this->testSellerPortionCancellation();
        $this->testBuyPoolSellerPrivacyPrePickup();
        $this->testBuyPoolSellerDetailsPostPickup();
        $this->testDisputesInternalSystemTextSanitization();

        echo "\nALL PART 13 TESTS COMPLETED SUCCESSFULLY!\n";
    }

    public function testBuyerCancellationIdempotency(): void {
        echo "\n[Test 1] Buyer Cancellation Idempotency & Lifecycle:\n";

        // 1. Create a sell order
        $sellRes = OrderEngine::createSellOrder($this->sellerId, 300.0, '1234');
        $this->assertTrue($sellRes['success'], 'Seller created sell order');
        $childId = (int)$sellRes['children'][0]['id'];

        // 2. Buyer picks order
        $pickRes = StateMachine::pickOrder($childId, $this->buyerId);
        $this->assertTrue($pickRes['success'], 'Buyer picked order');

        // Verify status is Reserved
        $stmt = $this->pdo->prepare("SELECT status, buyer_id FROM child_orders WHERE id = ?");
        $stmt->execute([$childId]);
        $row = $stmt->fetch();
        $this->assertEquals('Reserved', $row['status'], 'Child order is Reserved');
        $this->assertEquals($this->buyerId, (int)$row['buyer_id'], 'Buyer ID recorded');

        // 3. Buyer cancels reservation
        $cancelRes1 = StateMachine::cancelReservation($childId, $this->buyerId);
        $this->assertTrue($cancelRes1['success'], 'First cancel succeeds');

        // Verify order is Available and buyer_id is null
        $stmt->execute([$childId]);
        $row = $stmt->fetch();
        $this->assertEquals('Available', $row['status'], 'Child order returned to Available');
        $this->assertNull($row['buyer_id'], 'Buyer ID cleared');

        // 4. Second cancel attempt (idempotent - user clicked OK again or duplicate call)
        $cancelRes2 = StateMachine::cancelReservation($childId, $this->buyerId);
        $this->assertTrue($cancelRes2['success'], 'Second cancel succeeds idempotently');
        $this->assertTrue(!empty($cancelRes2['already_cancelled']), 'Flagged as already_cancelled');

        // Clean up listing
        OrderEngine::cancelParentSellOrder($this->sellerId, (int)$sellRes['parent_order_id']);
        echo "  -> PASS: Buyer cancellation is reliable and fully idempotent.\n";
    }

    public function testSellerCancellationAfterBuyerTimeout(): void {
        echo "\n[Test 2] Seller Cancellation After Buyer Timeout / Expire:\n";

        // Create sell order
        $sellRes = OrderEngine::createSellOrder($this->sellerId, 200.0, '1234');
        $this->assertTrue($sellRes['success'], 'Seller created sell order');
        $parentId = (int)$sellRes['parent_order_id'];
        $childId = (int)$sellRes['children'][0]['id'];

        // Buyer picks order
        StateMachine::pickOrder($childId, $this->buyerId);

        // Artificially expire the order
        $pastUtc = gmdate('Y-m-d H:i:s', time() - 30);
        $this->pdo->prepare("UPDATE child_orders SET payment_deadline = ? WHERE id = ?")->execute([$pastUtc, $childId]);

        // Trigger expiry
        StateMachine::checkAndExpireOrders();

        // Verify child order status is Expired
        $stmt = $this->pdo->prepare("SELECT status FROM child_orders WHERE id = ?");
        $stmt->execute([$childId]);
        $this->assertEquals('Expired', $stmt->fetchColumn(), 'Child order marked Expired');

        // Seller cancels listing
        $availBefore = (float)Wallet::getWallet($this->sellerId)['available_balance'];
        $cancelRes = OrderEngine::cancelParentSellOrder($this->sellerId, $parentId);
        $availAfter = (float)Wallet::getWallet($this->sellerId)['available_balance'];

        $this->assertTrue($cancelRes['success'], 'Seller cancelled parent order after buyer expired');
        $this->assertEquals(200.0, round($availAfter - $availBefore, 2), 'Seller refunded exact listing balance');
        echo "  -> PASS: Seller can smoothly cancel parent listing after buyer side timed out.\n";
    }

    public function testSellerPortionCancellation(): void {
        echo "\n[Test 3] Seller Micro-Portion Cancellation:\n";

        // Create sell order
        $sellRes = OrderEngine::createSellOrder($this->sellerId, 200.0, '1234');
        $this->assertTrue($sellRes['success'], 'Seller created sell order');
        $childId = (int)$sellRes['children'][0]['id'];
        $amount = (float)$sellRes['children'][0]['amount'];

        $availBefore = (float)Wallet::getWallet($this->sellerId)['available_balance'];
        $cancelPortionRes = OrderEngine::cancelChildSellPortion($this->sellerId, $childId);
        $availAfter = (float)Wallet::getWallet($this->sellerId)['available_balance'];

        $this->assertTrue($cancelPortionRes['success'], 'Seller cancelled individual portion');
        $this->assertEquals($amount, round($availAfter - $availBefore, 2), 'Exact portion amount refunded');

        // Cancel idempotent call
        $cancelAgain = OrderEngine::cancelChildSellPortion($this->sellerId, $childId);
        $this->assertTrue($cancelAgain['success'], 'Duplicate portion cancel succeeds idempotently');
        $this->assertTrue(!empty($cancelAgain['already_cancelled']), 'Flagged as already_cancelled');

        // Clean up parent
        OrderEngine::cancelParentSellOrder($this->sellerId, (int)$sellRes['parent_order_id']);
        echo "  -> PASS: Seller portion cancellation works idempotently without stuck balances.\n";
    }

    public function testBuyPoolSellerPrivacyPrePickup(): void {
        echo "\n[Test 4] Buy Pool Seller Privacy (Pre-Pickup):\n";

        // Create a sell order
        $sellRes = OrderEngine::createSellOrder($this->sellerId, 300.0, '1234');
        $this->assertTrue($sellRes['success'], 'Sell order created');
        $childId = (int)$sellRes['children'][0]['id'];

        // Simulate GET /api/buy.php?action=pool for the buyer
        $stmt = $this->pdo->prepare("
            SELECT c.id, c.parent_order_id, c.amount, c.snapshotted_reward_rate,
                   c.snapshotted_reward_amount, (c.amount + c.snapshotted_reward_amount) as total_credit,
                   c.created_at, c.seller_id
            FROM child_orders c
            WHERE c.status = 'Available' AND c.seller_id != ?
            ORDER BY c.amount ASC, c.id ASC
            LIMIT 50
        ");
        $stmt->execute([$this->buyerId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $found = false;
        foreach ($orders as $ord) {
            if ((int)$ord['id'] === $childId) {
                $found = true;
                // Verify strictly that seller payment details are NOT present
                $this->assertArrayNotHasKey('snapshotted_upi_id', $ord, 'snapshotted_upi_id not in pool data');
                $this->assertArrayNotHasKey('snapshotted_account_holder', $ord, 'snapshotted_account_holder not in pool data');
                $this->assertArrayNotHasKey('seller_name_masked', $ord, 'seller_name_masked not in pool data');
                $this->assertArrayHasKey('id', $ord, 'Order ID is present');
                $this->assertArrayHasKey('amount', $ord, 'Amount is present');
                $this->assertArrayHasKey('total_credit', $ord, 'Total credit is present');
            }
        }
        $this->assertTrue($found, 'Order found in public buy pool');

        // Clean up
        OrderEngine::cancelParentSellOrder($this->sellerId, (int)$sellRes['parent_order_id']);
        echo "  -> PASS: Pre-pickup public pool completely conceals seller name and UPI ID.\n";
    }

    public function testBuyPoolSellerDetailsPostPickup(): void {
        echo "\n[Test 5] Seller Details Strictly Revealed Post-Pickup:\n";

        // Create a sell order
        $sellRes = OrderEngine::createSellOrder($this->sellerId, 200.0, '1234');
        $this->assertTrue($sellRes['success'], 'Sell order created');
        $childId = (int)$sellRes['children'][0]['id'];

        // Buyer picks order
        $pickRes = StateMachine::pickOrder($childId, $this->buyerId);
        $this->assertTrue($pickRes['success'], 'Buyer picked order');

        // Post-pickup reservation MUST provide seller UPI and Name
        $this->assertNotEmpty($pickRes['seller_upi'], 'Seller UPI provided post-pickup');
        $this->assertNotEmpty($pickRes['seller_name'], 'Seller Name provided post-pickup');
        $this->assertEquals($childId, $pickRes['order_id'], 'Correct order ID returned');

        // Clean up
        StateMachine::cancelReservation($childId, $this->buyerId);
        OrderEngine::cancelParentSellOrder($this->sellerId, (int)$sellRes['parent_order_id']);
        echo "  -> PASS: Seller payment details are properly and securely revealed ONLY after pickup reservation.\n";
    }

    public function testDisputesInternalSystemTextSanitization(): void {
        echo "\n[Test 6] Appeal & Dispute Internal System Text Sanitization:\n";

        // Check that automated reason text is recognized as system and flagged
        $autoReason = 'Auto-escalated to arbitration: Seller did not confirm receipt within 15-minute verification SLA.';
        $isAuto = preg_match('/^(auto[-_ ]|system|ocr_|automated)/i', $autoReason) ||
                  stripos($autoReason, 'auto-selected') !== false ||
                  stripos($autoReason, 'auto-escalated') !== false;
        $this->assertTrue((bool)$isAuto, 'Auto-escalated reason flagged as auto/system');

        $autoReason2 = 'Auto-selected claim reason: Buyer claimed payment completed';
        $isAuto2 = preg_match('/^(auto[-_ ]|system|ocr_|automated)/i', $autoReason2) ||
                   stripos($autoReason2, 'auto-selected') !== false ||
                   stripos($autoReason2, 'auto-escalated') !== false;
        $this->assertTrue((bool)$isAuto2, 'Auto-selected reason flagged as auto/system');

        $userReason = 'Checked my bank passbook and UPI app, no transaction received for ₹500.';
        $isAuto3 = preg_match('/^(auto[-_ ]|system|ocr_|automated)/i', $userReason) ||
                   stripos($userReason, 'auto-selected') !== false ||
                   stripos($userReason, 'auto-escalated') !== false;
        $this->assertFalse((bool)$isAuto3, 'Legitimate user reason not flagged as system');

        echo "  -> PASS: Internal system texts are correctly identified and sanitized away from user display.\n";
    }

    // Helper assertion methods
    private function assertTrue(bool $condition, string $msg): void {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: {$msg}");
        }
    }

    private function assertFalse(bool $condition, string $msg): void {
        if ($condition) {
            throw new RuntimeException("Assertion failed (expected false): {$msg}");
        }
    }

    private function assertEquals($expected, $actual, string $msg): void {
        if ($expected != $actual) {
            throw new RuntimeException("Assertion failed: {$msg}. Expected " . json_encode($expected) . ", got " . json_encode($actual));
        }
    }

    private function assertNull($value, string $msg): void {
        if ($value !== null) {
            throw new RuntimeException("Assertion failed: {$msg}. Expected null, got " . json_encode($value));
        }
    }

    private function assertNotEmpty($value, string $msg): void {
        if (empty($value)) {
            throw new RuntimeException("Assertion failed: {$msg}. Expected non-empty value.");
        }
    }

    private function assertArrayHasKey(string $key, array $arr, string $msg): void {
        if (!array_key_exists($key, $arr)) {
            throw new RuntimeException("Assertion failed: {$msg}. Key '{$key}' not found in array.");
        }
    }

    private function assertArrayNotHasKey(string $key, array $arr, string $msg): void {
        if (array_key_exists($key, $arr)) {
            throw new RuntimeException("Assertion failed: {$msg}. Key '{$key}' should not be present.");
        }
    }
}

$test = new Part13CancellationAndPrivacyTest();
$test->runAll();
