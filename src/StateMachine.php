<?php
/**
 * Order State Machine & Timer Evaluator
 * Manages strict 10-state lifecycle, server-side UTC deadlines, race condition locks,
 * proof submission, idempotent settlements, and dispute transitions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/ReferralEngine.php';

class StateMachine {
    /**
     * Atomically pick and reserve an available child order (FR-007, QA-004)
     */
    public static function pickOrder(int $childOrderId, int $buyerId): array {
        $pdo = Database::getConnection();

        $chk = $pdo->prepare("SELECT seller_id, amount, status FROM child_orders WHERE id = ?");
        $chk->execute([$childOrderId]);
        $order = $chk->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        if ((int)$order['seller_id'] === $buyerId) {
            return ['success' => false, 'error' => 'You cannot purchase your own sell listing.'];
        }

        if ($order['status'] !== 'Available') {
            return ['success' => false, 'error' => 'Order is no longer available.'];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $deadlineUtc = gmdate('Y-m-d H:i:s', time() + BUYER_PAYMENT_WINDOW_SECONDS);

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Reserved',
                    buyer_id = ?,
                    picked_at = ?,
                    payment_deadline = ?
                WHERE id = ? AND status = 'Available'
            ");
            $update->execute([$buyerId, $nowUtc, $deadlineUtc, $childOrderId]);

            if ($update->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Order is no longer available. It was already picked by another buyer.'];
            }

            $parentUpdate = $pdo->prepare("
                UPDATE parent_sell_orders
                SET reserved_amount = reserved_amount + ?,
                    remaining_amount = remaining_amount - ?
                WHERE id = (SELECT parent_order_id FROM child_orders WHERE id = ?)
            ");
            $parentUpdate->execute([$order['amount'], $order['amount'], $childOrderId]);

            $pdo->commit();

            return [
                'success' => true,
                'order_id' => $childOrderId,
                'amount' => (float)$order['amount'],
                'payment_deadline' => $deadlineUtc,
                'seconds_remaining' => BUYER_PAYMENT_WINDOW_SECONDS
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to pick order: ' . $e->getMessage()];
        }
    }

    /**
     * Buyer submits UPI payment proof within 10-minute window (FR-009)
     */
    public static function submitPaymentProof(int $childOrderId, int $buyerId, string $utr, string $screenshotPath, ?string $notes = null): array {
        if (!Validator::validateUTR($utr)) {
            return ['success' => false, 'error' => 'Invalid UTR format. Please provide a valid 8-35 character bank reference number.'];
        }

        $cleanUtr = trim($utr);
        $pdo = Database::getConnection();

        // Check global UTR uniqueness (FR-009)
        $utrChk = $pdo->prepare("SELECT id FROM payment_proofs WHERE utr_number = ?");
        $utrChk->execute([$cleanUtr]);
        if ($utrChk->fetch()) {
            return ['success' => false, 'error' => 'This UTR number has already been submitted for another order. Duplicate payment proofs are strictly rejected.'];
        }

        $orderStmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ?");
        $orderStmt->execute([$childOrderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        if ((int)$order['buyer_id'] !== $buyerId) {
            return ['success' => false, 'error' => 'Unauthorized. This order does not belong to your account.'];
        }

        if ($order['status'] !== 'Reserved') {
            return ['success' => false, 'error' => "Cannot submit proof. Current order status is '{$order['status']}'."];
        }

        // Validate 10-minute server UTC deadline
        $nowTimestamp = time();
        $deadlineTimestamp = strtotime($order['payment_deadline'] . ' UTC');

        if ($nowTimestamp > $deadlineTimestamp) {
            self::expireSingleOrder((int)$order['id']);
            return [
                'success' => false,
                'error' => 'The 10-minute payment window has expired. If payment was actually deducted from your bank, please contact support for manual reconciliation.'
            ];
        }

        $proofSubmittedUtc = gmdate('Y-m-d H:i:s');
        $verifyDeadlineUtc = gmdate('Y-m-d H:i:s', time() + SELLER_VERIFY_WINDOW_SECONDS);

        $pdo->beginTransaction();
        try {
            $insertProof = $pdo->prepare("
                INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertProof->execute([$childOrderId, $buyerId, $cleanUtr, $screenshotPath, $notes, $proofSubmittedUtc]);

            $updateOrder = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Seller Confirmation Pending',
                    proof_submitted_at = ?,
                    verification_deadline = ?
                WHERE id = ? AND status = 'Reserved'
            ");
            $updateOrder->execute([$proofSubmittedUtc, $verifyDeadlineUtc, $childOrderId]);

            $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id, created_at) VALUES (?, ?, ?, 'order', ?, ?)");
            $notif->execute([
                $order['seller_id'],
                'Payment Proof Received',
                "Buyer submitted UPI proof (UTR: {$cleanUtr}) for order #{$childOrderId}. Please verify in your bank account within 15 minutes.",
                $childOrderId,
                $proofSubmittedUtc
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Payment proof submitted successfully. Waiting for seller confirmation.',
                'verification_deadline' => $verifyDeadlineUtc,
                'seconds_remaining' => SELLER_VERIFY_WINDOW_SECONDS
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to submit payment proof: ' . $e->getMessage()];
        }
    }

    /**
     * Seller confirms payment receipt -> Idempotent settlement (FR-015, QA-007)
     */
    public static function confirmPayment(int $childOrderId, int $sellerId, ?string $idempotencyKey = null): array {
        $pdo = Database::getConnection();

        $orderStmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ?");
        $orderStmt->execute([$childOrderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        if ($order['status'] === 'Completed') {
            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Order was already completed and settled. No duplicate action taken.',
                'status' => 'Completed'
            ];
        }

        if ((int)$order['seller_id'] !== $sellerId) {
            return ['success' => false, 'error' => 'Unauthorized. This sell order does not belong to your account.'];
        }

        $validPendingStates = ['Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout'];
        if (!in_array($order['status'], $validPendingStates, true)) {
            return ['success' => false, 'error' => "Cannot confirm payment. Current order status is '{$order['status']}'."];
        }

        $completedUtc = gmdate('Y-m-d H:i:s');
        $amount = (float)$order['amount'];
        $rewardAmount = (float)$order['snapshotted_reward_amount'];
        $buyerId = (int)$order['buyer_id'];

        $pdo->beginTransaction();
        try {
            $lockOrder = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Completed',
                    completed_at = ?,
                    idempotency_key = ?
                WHERE id = ? AND status IN ('Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout')
            ");
            $lockOrder->execute([$completedUtc, $idempotencyKey ?: uniqid('idem_', true), $childOrderId]);

            if ($lockOrder->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => true, 'idempotent' => true, 'message' => 'Order already completed by previous request.'];
            }

            // 1. Settle seller reserved balance
            Wallet::settleSellOrder($sellerId, $amount, (string)$childOrderId);

            // 2. Credit buyer: base tokens + 9% reward
            Wallet::creditBuyer($buyerId, $amount, $rewardAmount, (string)$childOrderId);

            // 3. Distribute multi-tier referral commissions (FR-019)
            ReferralEngine::distributeCommissions($buyerId, $amount, (string)$childOrderId);

            // 4. Update parent order stats
            $parentUpdate = $pdo->prepare("
                UPDATE parent_sell_orders
                SET completed_amount = completed_amount + ?,
                    reserved_amount = reserved_amount - ?,
                    status = CASE WHEN remaining_amount = 0 AND (reserved_amount - ?) <= 0 THEN 'completed' ELSE status END
                WHERE id = ?
            ");
            $parentUpdate->execute([$amount, $amount, $amount, $order['parent_order_id']]);

            // 5. Notify buyer
            $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id, created_at) VALUES (?, ?, ?, 'order', ?, ?)");
            $notif->execute([
                $buyerId,
                'Order Completed & Tokens Credited',
                "Seller confirmed your payment for order #{$childOrderId}. ₹{$amount} base tokens + ₹{$rewardAmount} reward have been credited to your wallet!",
                $childOrderId,
                $completedUtc
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'status' => 'Completed',
                'credited_buyer' => $amount + $rewardAmount,
                'message' => 'Payment confirmed. Tokens and rewards settled successfully.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Settlement failed: ' . $e->getMessage()];
        }
    }

    /**
     * Seller raises dispute / appeal if payment was not received (FR-016, QA-008)
     */
    public static function raiseAppeal(int $childOrderId, int $sellerId, string $reason, ?string $evidencePath = null): array {
        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'Please provide a clear reason for the dispute.'];
        }

        $pdo = Database::getConnection();

        $orderStmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ?");
        $orderStmt->execute([$childOrderId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        if ((int)$order['seller_id'] !== $sellerId) {
            return ['success' => false, 'error' => 'Unauthorized. This order does not belong to your account.'];
        }

        $validPendingStates = ['Payment Proof Submitted', 'Seller Confirmation Pending', 'Seller Response Timeout'];
        if (!in_array($order['status'], $validPendingStates, true)) {
            return ['success' => false, 'error' => "Cannot raise appeal for an order with status '{$order['status']}'."];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $updateOrder = $pdo->prepare("UPDATE child_orders SET status = 'Disputed' WHERE id = ?");
            $updateOrder->execute([$childOrderId]);

            $insertDispute = $pdo->prepare("
                INSERT INTO disputes (child_order_id, raised_by_user_id, raised_by_role, reason, evidence_screenshot_path, status, created_at)
                VALUES (?, ?, 'seller', ?, ?, 'pending', ?)
            ");
            $insertDispute->execute([$childOrderId, $sellerId, trim($reason), $evidencePath, $nowUtc]);
            $disputeId = (int)$pdo->lastInsertId();

            $pdo->commit();

            return [
                'success' => true,
                'dispute_id' => $disputeId,
                'message' => 'Appeal submitted. Order is now frozen and escalated to the Admin Arbitration Console.'
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to raise appeal: ' . $e->getMessage()];
        }
    }

    /**
     * Expire a single order that exceeded the 10-minute payment window (FR-010, QA-006)
     */
    public static function expireSingleOrder(int $childOrderId): bool {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ? AND status = 'Reserved'");
        $stmt->execute([$childOrderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return false;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE child_orders SET status = 'Expired' WHERE id = ? AND status = 'Reserved'");
            $upd->execute([$childOrderId]);

            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            // Release seller's reserved tokens back to available balance
            $amount = (float)$order['amount'];
            Wallet::releaseReserved((int)$order['seller_id'], $amount, (string)$childOrderId, 'Buyer missed 10-minute payment window');

            // Update parent order
            $parentUpd = $pdo->prepare("
                UPDATE parent_sell_orders
                SET reserved_amount = reserved_amount - ?,
                    remaining_amount = remaining_amount + ?
                WHERE id = ?
            ");
            $parentUpd->execute([$amount, $amount, $order['parent_order_id']]);

            // Re-list portion as Available in buy pool
            $relist = $pdo->prepare("
                INSERT INTO child_orders (
                    parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
                    snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
                    status, created_at
                ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'Available', ?)
            ");
            $relist->execute([
                $order['parent_order_id'],
                $order['seller_id'],
                $amount,
                $order['snapshotted_reward_rate'],
                $order['snapshotted_reward_amount'],
                $order['snapshotted_upi_id'],
                $order['snapshotted_account_holder'],
                $nowUtc
            ]);

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }

    /**
     * Check and expire orders using server-side UTC timestamps (FR-010, FR-017)
     */
    public static function checkAndExpireOrders(): array {
        $pdo = Database::getConnection();
        $nowUtc = gmdate('Y-m-d H:i:s');

        $expiredCount = 0;
        $timeoutCount = 0;

        // 1. Expire un-submitted buyer reservations past 10 minutes
        $stmt = $pdo->prepare("
            SELECT id FROM child_orders
            WHERE status = 'Reserved' AND payment_deadline < ?
        ");
        $stmt->execute([$nowUtc]);
        $toExpire = $stmt->fetchAll();

        foreach ($toExpire as $row) {
            if (self::expireSingleOrder((int)$row['id'])) {
                $expiredCount++;
            }
        }

        // 2. Transition seller verification timeout past 15 minutes (FR-017)
        $timeoutStmt = $pdo->prepare("
            UPDATE child_orders
            SET status = 'Seller Response Timeout'
            WHERE status IN ('Payment Proof Submitted', 'Seller Confirmation Pending')
              AND verification_deadline < ?
        ");
        $timeoutStmt->execute([$nowUtc]);
        $timeoutCount = $timeoutStmt->rowCount();

        return [
            'expired_buyer_reservations' => $expiredCount,
            'seller_response_timeouts' => $timeoutCount
        ];
    }
}
