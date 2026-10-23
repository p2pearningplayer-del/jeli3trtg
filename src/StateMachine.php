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
require_once __DIR__ . '/AgentManager.php';

class StateMachine {
    /**
     * Atomically pick and reserve an available child order (FR-007, QA-004)
     * Enforces One Active Buy Order limit (Part 5, Item 2)
     */
    public static function pickOrder(int $childOrderId, int $buyerId): array {
        $pdo = Database::getConnection();

        // Enforce "One Active Buy Order" limit (Part 5, Item 2)
        $activeBuyStmt = $pdo->prepare("
            SELECT id, status FROM child_orders
            WHERE buyer_id = ? AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending', 'Disputed')
            LIMIT 1
        ");
        $activeBuyStmt->execute([$buyerId]);
        $activeBuy = $activeBuyStmt->fetch();
        if ($activeBuy) {
            $formattedId = sprintf('#%04d', (int)$activeBuy['id']);
            return [
                'success' => false,
                'active_order_id' => (int)$activeBuy['id'],
                'error' => "You already have an active buy order in progress ({$formattedId}). Please complete or resolve it before initiating a new order."
            ];
        }

        $chk = $pdo->prepare("SELECT seller_id, amount, status, snapshotted_upi_id, snapshotted_account_holder FROM child_orders WHERE id = ?");
        $chk->execute([$childOrderId]);
        $order = $chk->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        if ((int)$order['seller_id'] === $buyerId) {
            return ['success' => false, 'error' => 'You cannot purchase your own sell order.'];
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
                'formatted_order_id' => sprintf('#%04d', $childOrderId),
                'amount' => (float)$order['amount'],
                'seller_upi' => $order['snapshotted_upi_id'],
                'seller_name' => $order['snapshotted_account_holder'],
                'payment_deadline' => $deadlineUtc,
                'seconds_remaining' => BUYER_PAYMENT_WINDOW_SECONDS
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to pick order: ' . $e->getMessage()];
        }
    }

    /**
     * Buyer cancels active reservation before payment proof submission (Requirement 4)
     */
    public static function cancelReservation(int $childOrderId, int $buyerId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ?");
        $stmt->execute([$childOrderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['success' => false, 'error' => 'Order not found.'];
        }

        $currentStatus = strtolower(trim($order['status']));

        // Idempotent cancellation: if the order is already cancelled, expired, or available in the market
        if (in_array($currentStatus, ['available', 'expired', 'cancelled'])) {
            return [
                'success' => true,
                'already_cancelled' => true,
                'message' => 'Reservation already cancelled. Tokens returned to buy pool.'
            ];
        }

        // Prevent cancelling orders with payment proofs or settlements
        if (in_array($currentStatus, ['completed', 'payment proof submitted', 'seller confirmation pending', 'disputed'])) {
            return [
                'success' => false,
                'error' => 'Order payment has already been submitted and cannot be cancelled.'
            ];
        }

        if ($currentStatus !== 'reserved' || (int)$order['buyer_id'] !== $buyerId) {
            return ['success' => false, 'error' => 'Active reservation not found or order already processed.'];
        }

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Available',
                    buyer_id = NULL,
                    picked_at = NULL,
                    payment_deadline = NULL
                WHERE id = ? AND status = 'Reserved'
            ");
            $upd->execute([$childOrderId]);

            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return [
                    'success' => true,
                    'already_cancelled' => true,
                    'message' => 'Reservation already cancelled. Tokens returned to buy pool.'
                ];
            }

            // Adjust parent order reserved/remaining amount back
            $amount = (float)$order['amount'];
            $parentUpd = $pdo->prepare("
                UPDATE parent_sell_orders
                SET reserved_amount = CASE WHEN (reserved_amount - ?) > 0 THEN (reserved_amount - ?) ELSE 0 END,
                    remaining_amount = remaining_amount + ?
                WHERE id = ?
            ");
            $parentUpd->execute([$amount, $amount, $amount, $order['parent_order_id']]);

            $pdo->commit();
            return ['success' => true, 'message' => 'Reservation cancelled. Tokens returned to buy pool.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to cancel reservation: ' . $e->getMessage()];
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

        // 1. Order ownership and state validation
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

        // 2. Validate 10-minute server UTC deadline
        $nowTimestamp = time();
        $deadlineTimestamp = strtotime($order['payment_deadline'] . ' UTC');

        if ($nowTimestamp > $deadlineTimestamp) {
            self::expireSingleOrder((int)$order['id']);
            return [
                'success' => false,
                'error' => 'The 10-minute payment window has expired. If payment was actually deducted from your bank, please contact support for manual reconciliation.'
            ];
        }

        // 3. AI & Rule-Based Fraud Detection Pipeline
        $fraudAssessment = \Fraud\FraudDecisionEngine::evaluatePaymentProof(
            $pdo,
            $childOrderId,
            $buyerId,
            $cleanUtr,
            $screenshotPath,
            $notes
        );

        if (!$fraudAssessment['can_proceed'] || $fraudAssessment['decision'] === 'REJECTED') {
            return [
                'success' => false,
                'error' => $fraudAssessment['error'] ?: 'Payment proof was rejected by the automated fraud detection system.',
                'decision' => $fraudAssessment['decision'],
                'risk_score' => $fraudAssessment['risk_score'] ?? 100,
                'reason_codes' => $fraudAssessment['reason_codes'] ?? ['AUTO_REJECTED']
            ];
        }

        if ($fraudAssessment['decision'] === 'FAILED') {
            return [
                'success' => false,
                'error' => $fraudAssessment['error'] ?: 'Failed to process screenshot file verification.'
            ];
        }

        // Use normalized UTR for consistent database storage
        $normalizedUtr = !empty($fraudAssessment['utr_normalized']) ? $fraudAssessment['utr_normalized'] : $cleanUtr;
        $proofSubmittedUtc = gmdate('Y-m-d H:i:s');
        $verifyDeadlineUtc = gmdate('Y-m-d H:i:s', time() + SELLER_VERIFY_WINDOW_SECONDS);

        $pdo->beginTransaction();
        try {
            $insertProof = $pdo->prepare("
                INSERT INTO payment_proofs (child_order_id, buyer_id, utr_number, screenshot_path, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertProof->execute([$childOrderId, $buyerId, $normalizedUtr, $screenshotPath, $notes, $proofSubmittedUtc]);

            $updateOrder = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Seller Confirmation Pending',
                    proof_submitted_at = ?,
                    verification_deadline = ?
                WHERE id = ? AND status = 'Reserved'
            ");
            $updateOrder->execute([$proofSubmittedUtc, $verifyDeadlineUtc, $childOrderId]);

            $isFlaggedReview = ($fraudAssessment['decision'] === 'PENDING_REVIEW' && ($fraudAssessment['risk_score'] ?? 0) >= 25);
            $sellerNotice = $isFlaggedReview
                ? "Buyer submitted UPI proof (UTR: {$normalizedUtr}) for order #{$childOrderId}. Note: Visual verification suggested. Please verify in your bank account."
                : "Buyer submitted UPI proof (UTR: {$normalizedUtr}) for order #{$childOrderId}. Please verify in your bank account within 15 minutes.";

            $notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id, created_at) VALUES (?, ?, ?, 'order', ?, ?)");
            $notif->execute([
                $order['seller_id'],
                'Payment Proof Received',
                $sellerNotice,
                $childOrderId,
                $proofSubmittedUtc
            ]);

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Payment proof submitted successfully. Waiting for seller confirmation.',
                'verification_deadline' => $verifyDeadlineUtc,
                'seconds_remaining' => SELLER_VERIFY_WINDOW_SECONDS,
                'fraud_decision' => $fraudAssessment['decision'],
                'risk_score' => $fraudAssessment['risk_score'] ?? 0,
                'risk_level' => $fraudAssessment['risk_level'] ?? 'low'
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

            // 3b. Snapshot Agent Commissions for eligible referral hierarchy levels A-Z
            AgentManager::onOrderCompleted($childOrderId, $buyerId, $amount, $completedUtc);

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

            // Reward Health Score points for legitimate trade completion (Requirement 7)
            require_once __DIR__ . '/HealthScoreService.php';
            HealthScoreService::adjustScore($buyerId, 2, "Successful trade completion - Buyer on Order #{$childOrderId}", 'TRADE_SUCCESS');
            HealthScoreService::adjustScore($sellerId, 2, "Successful trade completion - Seller on Order #{$childOrderId}", 'TRADE_SUCCESS');

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

            $amount = (float)$order['amount'];

            // Check parent order state (Part 8, Item 2)
            $parentStmt = $pdo->prepare("SELECT id, status FROM parent_sell_orders WHERE id = ?");
            $parentStmt->execute([$order['parent_order_id']]);
            $parentOrder = $parentStmt->fetch();

            if ($parentOrder && $parentOrder['status'] === 'active') {
                // Buyer timeout keeps seller's listing active and automatically relists for sale (re-sell)
                $parentUpd = $pdo->prepare("
                    UPDATE parent_sell_orders
                    SET reserved_amount = reserved_amount - ?,
                        remaining_amount = remaining_amount + ?
                    WHERE id = ?
                ");
                $parentUpd->execute([$amount, $amount, $order['parent_order_id']]);

                // Re-list portion as Available in buy pool for other buyers to purchase
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

                // Notify seller that tokens returned to active sell queue
                Auth::createNotification(
                    (int)$order['seller_id'],
                    'Order Expired & Relisted',
                    sprintf('Order #%04d (₹%s) timed out. Tokens have been automatically placed back into the live sell queue.', (int)$order['id'], number_format($amount, 2)),
                    'order',
                    (int)$order['id']
                );
            } else {
                // If parent sell order was cancelled, immediately release tokens back to seller's available balance
                $relStmt = $pdo->prepare("
                    UPDATE wallets
                    SET reserved_balance = MAX(0, reserved_balance - ?),
                        available_balance = available_balance + ?
                    WHERE user_id = ?
                ");
                $relStmt->execute([$amount, $amount, $order['seller_id']]);

                Auth::createNotification(
                    (int)$order['seller_id'],
                    'Order Expired & Refunded',
                    sprintf('Order #%04d (₹%s) timed out. Unsold tokens have been returned to your Available Balance.', (int)$order['id'], number_format($amount, 2)),
                    'order',
                    (int)$order['id']
                );
            }

            // Notify buyer about timeout cancellation
            if (!empty($order['buyer_id'])) {
                Auth::createNotification(
                    (int)$order['buyer_id'],
                    'Reservation Expired',
                    sprintf('Your reservation for Order #%04d (₹%s) expired and was automatically cancelled.', (int)$order['id'], number_format($amount, 2)),
                    'order',
                    (int)$order['id']
                );
            }

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

        // 2. Transition seller verification timeout past 15 minutes to Appeal state (Requirement 4 & 5)
        $timeoutStmt = $pdo->prepare("
            SELECT id, seller_id, buyer_id, amount
            FROM child_orders
            WHERE status IN ('Payment Proof Submitted', 'Seller Confirmation Pending')
              AND verification_deadline < ?
        ");
        $timeoutStmt->execute([$nowUtc]);
        $timeouts = $timeoutStmt->fetchAll();

        foreach ($timeouts as $to) {
            $childOrderId = (int)$to['id'];
            $sellerId = (int)$to['seller_id'];
            $buyerId = (int)$to['buyer_id'];

            // Automatically transition to Disputed / Appeal state
            $upd = $pdo->prepare("UPDATE child_orders SET status = 'Disputed' WHERE id = ?");
            $upd->execute([$childOrderId]);

            // Auto-create arbitration dispute if not already exists
            $dispChk = $pdo->prepare("SELECT id FROM disputes WHERE child_order_id = ?");
            $dispChk->execute([$childOrderId]);
            if (!$dispChk->fetch()) {
                $insDisp = $pdo->prepare("
                    INSERT INTO disputes (child_order_id, raised_by_user_id, raised_by_role, reason, status, created_at)
                    VALUES (?, ?, 'buyer', 'Auto-escalated to arbitration: Seller did not confirm receipt within 15-minute verification SLA.', 'pending', ?)
                ");
                $insDisp->execute([$childOrderId, $buyerId, $nowUtc]);
            }

            // Deduct Health Score from seller for delayed confirmation (-10 points)
            Auth::adjustHealthScore($sellerId, -10, "Delayed confirmation past 15-minute SLA on Order #{$childOrderId}");

            // Dispatch notifications to both parties
            Auth::createNotification($sellerId, 'Order Escalated to Appeal', "Order #{$childOrderId} exceeded the 15-minute verification window and has transitioned to Appeal arbitration. 10 points deducted from your Health Score.", 'warning', (string)$childOrderId);
            Auth::createNotification($buyerId, 'Order Escalated to Appeal', "Seller did not confirm Order #{$childOrderId} within 15 minutes. It has been escalated to Admin Arbitration.", 'info', (string)$childOrderId);

            $timeoutCount++;
        }

        return [
            'expired_buyer_reservations' => $expiredCount,
            'seller_response_timeouts' => $timeoutCount
        ];
    }
}
