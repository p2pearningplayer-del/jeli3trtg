<?php
/**
 * Order Splitting & Allocation Engine
 * Divides parent sell orders into manageable child micro-orders (FR-012, QA-003),
 * snapshots banking and reward rates (FR-013), and handles custom buy allocations (FR-008, QA-005).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/Auth.php';

class OrderEngine {
    /**
     * Compute child micro-order portion breakdown (FR-012, QA-003)
     * Sum of portions must mathematically equal the parent amount.
     */
    public static function calculateSplits(float $amount, int $maxSplits = MAX_CHILD_SPLITS): array {
        $amount = (float)$amount;

        // Special PRD-defined standard splits
        if ($amount == 10000) {
            return [5000.0, 3000.0, 2000.0];
        }
        if ($amount == 5000) {
            return [2500.0, 1500.0, 1000.0];
        }
        if ($amount == 1000) {
            return [500.0, 300.0, 200.0];
        }
        if ($amount == 500) {
            return [300.0, 200.0];
        }
        if ($amount <= 200) {
            if ($amount == 200) return [100.0, 100.0];
            return [$amount];
        }

        // Generic algorithm for arbitrary amounts
        $portions = [];
        $remaining = $amount;
        $standardDenominations = [50000, 25000, 10000, 5000, 3000, 2000, 1000, 500, 200, 100];

        while ($remaining > 0 && count($portions) < ($maxSplits - 1)) {
            $chosen = null;
            foreach ($standardDenominations as $denom) {
                // Must leave at least 100 for the remainder if remaining > denom
                if ($denom < $remaining && ($remaining - $denom) >= 100) {
                    $chosen = (float)$denom;
                    break;
                }
            }

            if ($chosen === null) {
                break;
            }

            $portions[] = $chosen;
            $remaining -= $chosen;
        }

        if ($remaining > 0) {
            $portions[] = (float)$remaining;
        }

        // Verify mathematical integrity
        $sum = array_sum($portions);
        if ($sum != $amount) {
            return [$amount];
        }

        return $portions;
    }

    /**
     * Create a new Parent Sell Order and automatically spawn child micro-orders (FR-011, FR-012, FR-013)
     */
    public static function createSellOrder(int $sellerId, float $amount, string $tpin, ?string $accountHolderName = null, ?string $upiId = null): array {
        // Step 1: Validate Amount (FR-011)
        $val = Validator::validateSellAmount($amount);
        if (!$val['valid']) {
            return ['success' => false, 'error' => $val['error']];
        }
        $amount = $val['amount'];

        // Step 2: Verify Selling TPIN (FR-002)
        $tpinCheck = Auth::verifyTPIN($sellerId, $tpin);
        if (!$tpinCheck['success']) {
            return ['success' => false, 'error' => $tpinCheck['error']];
        }

        $pdo = Database::getConnection();

        // Step 2b: Enforce Account Status & Backend Risk Controls (Requirement 7)
        $sellerStmt = $pdo->prepare("
            SELECT account_status, sell_restricted, sell_restriction_reason,
                   sell_order_limit, sell_cooldown_seconds, last_sell_order_at
            FROM users WHERE id = ?
        ");
        $sellerStmt->execute([$sellerId]);
        $sellerRisk = $sellerStmt->fetch();

        if ($sellerRisk) {
            if ($sellerRisk['account_status'] === 'suspended' || $sellerRisk['account_status'] === 'temporarily_blocked') {
                return [
                    'success' => false,
                    'error' => "Your account is currently {$sellerRisk['account_status']} pending review. You cannot list tokens for sale."
                ];
            }
            if ((int)($sellerRisk['sell_restricted'] ?? 0) === 1) {
                $reason = $sellerRisk['sell_restriction_reason'] ?: 'Selling is restricted on your account by risk administration.';
                return ['success' => false, 'error' => "Sell restriction active: {$reason}"];
            }
            $sellOrderLimit = (float)($sellerRisk['sell_order_limit'] ?? 0);
            if ($sellOrderLimit > 0 && $amount > $sellOrderLimit) {
                return [
                    'success' => false,
                    'error' => 'Order amount exceeds your configured maximum sell limit of ₹' . number_format($sellOrderLimit, 2) . '.'
                ];
            }
            $cooldownSec = (int)($sellerRisk['sell_cooldown_seconds'] ?? 0);
            if ($cooldownSec > 0 && !empty($sellerRisk['last_sell_order_at'])) {
                $lastSellTs = strtotime($sellerRisk['last_sell_order_at']);
                $elapsed = time() - $lastSellTs;
                if ($elapsed < $cooldownSec) {
                    $waitSec = $cooldownSec - $elapsed;
                    return [
                        'success' => false,
                        'error' => "Sell cooldown active. Please wait {$waitSec} more seconds before placing another sell order."
                    ];
                }
            }
        }

        // Step 3: Handle Account Holder Name and UPI ID (Sell Flow)
        if ($accountHolderName !== null || $upiId !== null) {
            $accountHolderName = trim((string)$accountHolderName);
            $upiId = trim((string)$upiId);

            if (empty($accountHolderName) || mb_strlen($accountHolderName) < 2) {
                return ['success' => false, 'error' => 'Please enter a valid Account Holder Name (minimum 2 characters).'];
            }

            if (!Validator::validateUpiId($upiId)) {
                return ['success' => false, 'error' => 'Invalid UPI ID format. Example: user@okhdfcbank or 9876543210@paytm.'];
            }

            // Persist valid banking details to user account for future reference
            $updateUser = $pdo->prepare("UPDATE users SET account_holder_name = ?, upi_id = ? WHERE id = ?");
            $updateUser->execute([$accountHolderName, $upiId, $sellerId]);

            $snapUpi = $upiId;
            $snapHolder = $accountHolderName;
        } else {
            // Fallback for existing automated tests or internal calls
            $userStmt = $pdo->prepare("SELECT upi_id, account_holder_name FROM users WHERE id = ?");
            $userStmt->execute([$sellerId]);
            $seller = $userStmt->fetch();

            if (empty($seller['upi_id']) || empty($seller['account_holder_name'])) {
                return ['success' => false, 'error' => 'Please provide your valid Account Holder Name and UPI ID when listing tokens for sale.'];
            }

            $snapUpi = $seller['upi_id'];
            $snapHolder = $seller['account_holder_name'];
        }

        // Step 4: Fetch active Purchase Reward Rate from platform settings
        $rateStmt = $pdo->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = 'purchase_reward_percent'");
        $rateStmt->execute();
        $rewardRate = (float)($rateStmt->fetchColumn() ?: 9.0);

        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Step 5: Reserve wallet balance atomically
            $refId = 'PARENT_' . time() . '_' . $sellerId;
            $res = Wallet::reserveForSell($sellerId, $amount, $refId);
            if (!$res['success']) {
                $pdo->rollBack();
                return ['success' => false, 'error' => $res['error']];
            }

            // Step 6: Insert Parent Sell Order
            $parentInsert = $pdo->prepare("
                INSERT INTO parent_sell_orders (
                    seller_id, original_amount, remaining_amount, reserved_amount, completed_amount,
                    status, snapshotted_upi_id, snapshotted_account_holder, created_at
                ) VALUES (?, ?, ?, 0.00, 0.00, 'active', ?, ?, ?)
            ");
            $parentInsert->execute([$sellerId, $amount, $amount, $snapUpi, $snapHolder, $nowUtc]);
            $parentId = (int)$pdo->lastInsertId();

            // Step 7: Split into Child Micro-Orders (FR-012)
            $splits = self::calculateSplits($amount);
            $childInsert = $pdo->prepare("
                INSERT INTO child_orders (
                    parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
                    snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
                    status, created_at
                ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'Available', ?)
            ");

            $createdChildOrders = [];
            foreach ($splits as $childAmount) {
                $calc = Validator::calculatePurchaseReward($childAmount, $rewardRate);
                $childInsert->execute([
                    $parentId,
                    $sellerId,
                    $childAmount,
                    $rewardRate,
                    $calc['reward_amount'],
                    $snapUpi,
                    $snapHolder,
                    $nowUtc
                ]);
                $createdChildOrders[] = [
                    'id' => (int)$pdo->lastInsertId(),
                    'amount' => $childAmount,
                    'reward' => $calc['reward_amount'],
                    'total_credit' => $calc['total_credit']
                ];
            }

            // Record last sell order timestamp for cooldown tracking
            $updLastSell = $pdo->prepare("UPDATE users SET last_sell_order_at = ? WHERE id = ?");
            $updLastSell->execute([$nowUtc, $sellerId]);

            $pdo->commit();

            return [
                'success' => true,
                'parent_order_id' => $parentId,
                'total_amount' => $amount,
                'splits_count' => count($splits),
                'children' => $createdChildOrders
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to create sell order: ' . $e->getMessage()];
        }
    }

    /**
     * Manual Buy Custom Allocation (FR-008, QA-005)
     * Reallocates/splits a larger unpicked order to cleanly fulfill the custom buy amount without duplicating quantity.
     */
    public static function allocateCustomBuy(int $buyerId, float $amount): array {
        $val = Validator::validateSellAmount($amount);
        if (!$val['valid']) {
            return ['success' => false, 'error' => $val['error']];
        }
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

        // Look for exact match first
        $exactStmt = $pdo->prepare("
            SELECT * FROM child_orders
            WHERE status = 'Available' AND amount = ? AND seller_id != ?
            ORDER BY id ASC LIMIT 1
        ");
        $exactStmt->execute([$amount, $buyerId]);
        $exact = $exactStmt->fetch();

        if ($exact) {
            return StateMachine::pickOrder((int)$exact['id'], $buyerId);
        }

        // Search for a larger unpicked child order that can be split
        $largerStmt = $pdo->prepare("
            SELECT * FROM child_orders
            WHERE status = 'Available' AND amount > ? AND (amount - ?) >= 100 AND seller_id != ?
            ORDER BY amount ASC, id ASC LIMIT 1
        ");
        $largerStmt->execute([$amount, $amount, $buyerId]);
        $candidate = $largerStmt->fetch();

        if (!$candidate) {
            return [
                'success' => false,
                'error' => "No compatible sell orders available for ₹{$amount}. Please pick an order from the buy pool or try another denomination."
            ];
        }

        $candidateId = (int)$candidate['id'];
        $originalChildAmount = (float)$candidate['amount'];
        $remainingChildAmount = round($originalChildAmount - $amount, 2);
        $rewardRate = (float)$candidate['snapshotted_reward_rate'];
        $nowUtc = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Reallocate candidate child order to custom amount
            $calc = Validator::calculatePurchaseReward($amount, $rewardRate);
            $updateCandidate = $pdo->prepare("
                UPDATE child_orders
                SET amount = ?, snapshotted_reward_amount = ?
                WHERE id = ? AND status = 'Available'
            ");
            $updateCandidate->execute([$amount, $calc['reward_amount'], $candidateId]);

            if ($updateCandidate->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Order was picked concurrently by another user. Please retry.'];
            }

            // Create remaining child order portion back in the pool
            $remCalc = Validator::calculatePurchaseReward($remainingChildAmount, $rewardRate);
            $newChild = $pdo->prepare("
                INSERT INTO child_orders (
                    parent_order_id, seller_id, buyer_id, amount, snapshotted_reward_rate,
                    snapshotted_reward_amount, snapshotted_upi_id, snapshotted_account_holder,
                    status, created_at
                ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'Available', ?)
            ");
            $newChild->execute([
                $candidate['parent_order_id'],
                $candidate['seller_id'],
                $remainingChildAmount,
                $rewardRate,
                $remCalc['reward_amount'],
                $candidate['snapshotted_upi_id'],
                $candidate['snapshotted_account_holder'],
                $nowUtc
            ]);

            $pdo->commit();

            // Pick newly reallocated candidate portion
            return StateMachine::pickOrder($candidateId, $buyerId);
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Allocation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Seller Tracking Dashboard Data (FR-014)
     */
    public static function getSellerTrackingData(int $sellerId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT p.*,
                (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id) as total_children,
                (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id AND status = 'Completed') as completed_children,
                (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id AND status IN ('Reserved', 'Payment Proof Submitted', 'Seller Confirmation Pending')) as matched_children,
                (SELECT COUNT(*) FROM child_orders WHERE parent_order_id = p.id AND status = 'Disputed') as disputed_children
            FROM parent_sell_orders p
            WHERE p.seller_id = ?
            ORDER BY p.id DESC
        ");
        $stmt->execute([$sellerId]);
        $parents = $stmt->fetchAll();

        foreach ($parents as &$parent) {
            $childStmt = $pdo->prepare("
                SELECT c.*,
                       p.utr_number, p.screenshot_path, p.created_at as proof_uploaded_at
                FROM child_orders c
                LEFT JOIN payment_proofs p ON c.id = p.child_order_id
                WHERE c.parent_order_id = ?
                ORDER BY c.id ASC
            ");
            $childStmt->execute([$parent['id']]);
            $children = $childStmt->fetchAll();

            foreach ($children as &$child) {
                $child['formatted_id'] = sprintf('#%04d', (int)$child['id']);
                // Strict Buyer Privacy (Part 6): Completely hide buyer name and phone
                unset($child['buyer_phone']);
                unset($child['buyer_name']);
                unset($child['buyer_phone_masked']);

                // Part 12, Item 3: Screenshot Privacy & History Security
                $isFinal = in_array(strtolower($child['status'] ?? ''), ['completed', 'cancelled', 'expired', 'cancelled / reversed']);
                $isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') || !empty($_SESSION['real_admin_id']);
                if ($isFinal && !$isAdmin) {
                    $child['screenshot_path'] = null;
                }
            }
            $parent['children'] = $children;
        }

        return $parents;
    }

    /**
     * Seller manually cancels active sell order (Requirement 1 & 2)
     * All unsold/available tokens are immediately released from locked/reserved state
     * directly back to the seller's Available Balance.
     */
    public static function cancelParentSellOrder(int $sellerId, int $parentOrderId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM parent_sell_orders WHERE id = ? AND seller_id = ?");
        $stmt->execute([$parentOrderId, $sellerId]);
        $parent = $stmt->fetch();

        if (!$parent) {
            return ['success' => false, 'error' => 'Parent sell order not found.'];
        }

        if (in_array($parent['status'], ['cancelled', 'completed'])) {
            return ['success' => false, 'error' => "Sell order is already {$parent['status']}."];
        }

        // Find all unsold/available child portions in the market
        $childStmt = $pdo->prepare("SELECT * FROM child_orders WHERE parent_order_id = ? AND LOWER(status) = 'available'");
        $childStmt->execute([$parentOrderId]);
        $availableChildren = $childStmt->fetchAll();

        // Check if any in-flight active buyer orders remain
        $activeChk = $pdo->prepare("
            SELECT COUNT(*) FROM child_orders
            WHERE parent_order_id = ?
              AND LOWER(status) IN ('reserved', 'payment proof submitted', 'seller confirmation pending', 'disputed')
        ");
        $activeChk->execute([$parentOrderId]);
        $activeCount = (int)$activeChk->fetchColumn();

        if (empty($availableChildren) && $activeCount > 0) {
            return [
                'success' => false,
                'error' => 'No unsold portions available to cancel. Any matched orders with active buyers must be settled, expired, or arbitrated.'
            ];
        }

        $parentRemaining = (float)($parent['remaining_amount'] ?? 0);
        $refundAmount = 0.0;
        foreach ($availableChildren as $c) {
            $refundAmount += (float)$c['amount'];
        }

        // Cap refund amount by parent remaining amount to prevent any double-counting
        if ($parentRemaining > 0) {
            $refundAmount = ($refundAmount > 0) ? min($parentRemaining, $refundAmount) : ($activeCount === 0 ? $parentRemaining : 0.0);
        }
        $refundAmount = round($refundAmount, 2);

        if ($refundAmount <= 0.0 && $activeCount > 0) {
            return [
                'success' => false,
                'error' => 'No available balance left to cancel on this listing.'
            ];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            // Cancel all available portions so they are removed from the Buy Pool
            if (!empty($availableChildren)) {
                $updChild = $pdo->prepare("
                    UPDATE child_orders
                    SET status = 'Cancelled',
                        cancelled_at = ?
                    WHERE parent_order_id = ? AND LOWER(status) = 'available'
                ");
                $updChild->execute([$nowUtc, $parentOrderId]);
            }

            // Release reserved tokens directly back to seller's Available Balance
            if ($refundAmount > 0) {
                Wallet::releaseReserved($sellerId, $refundAmount, (string)$parentOrderId, "Seller manually cancelled unsold sell listing #{$parentOrderId}");
            }

            $newParentStatus = $parent['status'];
            if ($activeCount === 0) {
                $completedAmount = (float)$parent['completed_amount'];
                $newParentStatus = ($completedAmount > 0) ? 'partially_cancelled' : 'cancelled';
            }

            $newRemaining = max(0.0, round($parentRemaining - $refundAmount, 2));

            $updParent = $pdo->prepare("
                UPDATE parent_sell_orders
                SET remaining_amount = ?,
                    status = ?
                WHERE id = ?
            ");
            $updParent->execute([$newRemaining, $newParentStatus, $parentOrderId]);

            $pdo->commit();

            return [
                'success' => true,
                'parent_order_id' => $parentOrderId,
                'refunded_amount' => $refundAmount,
                'message' => "Sell listing #{$parentOrderId} cancelled. ₹" . number_format($refundAmount, 2) . " tokens immediately credited back to your Available Balance."
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to cancel sell listing: ' . $e->getMessage()];
        }
    }

    /**
     * Seller manually cancels an individual available child micro-portion (Requirement 1 & 2)
     */
    public static function cancelChildSellPortion(int $sellerId, int $childOrderId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM child_orders WHERE id = ? AND seller_id = ?");
        $stmt->execute([$childOrderId, $sellerId]);
        $child = $stmt->fetch();

        if (!$child) {
            return ['success' => false, 'error' => 'Sell portion not found.'];
        }

        $cStatus = strtolower(trim($child['status']));

        if ($cStatus === 'cancelled') {
            return [
                'success' => true,
                'already_cancelled' => true,
                'child_order_id' => $childOrderId,
                'refunded_amount' => 0.0,
                'message' => "Sell portion #{$childOrderId} is already cancelled."
            ];
        }

        if (in_array($cStatus, ['completed', 'payment proof submitted', 'seller confirmation pending', 'disputed'])) {
            return [
                'success' => false,
                'error' => "Cannot cancel portion #{$childOrderId} while order status is '{$child['status']}'."
            ];
        }

        // If portion is reserved, check if buyer deadline has passed
        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($cStatus === 'reserved') {
            if (!empty($child['payment_deadline']) && strtotime($child['payment_deadline']) <= strtotime($nowUtc)) {
                // Expire reservation first
                StateMachine::expireSingleOrder($childOrderId);
                // Refresh child status
                $stmt->execute([$childOrderId, $sellerId]);
                $child = $stmt->fetch();
                $cStatus = strtolower(trim($child['status']));
            } else {
                return [
                    'success' => false,
                    'error' => "Portion #{$childOrderId} is currently reserved by a buyer. Wait for the buyer payment timer to expire or for buyer to release it."
                ];
            }
        }

        if ($cStatus === 'expired') {
            return [
                'success' => true,
                'already_cancelled' => true,
                'child_order_id' => $childOrderId,
                'refunded_amount' => 0.0,
                'message' => "Order reservation timed out. Tokens were already relisted or returned to your balance."
            ];
        }

        if ($cStatus !== 'available') {
            return ['success' => false, 'error' => "Sell portion status '{$child['status']}' cannot be cancelled."];
        }

        $amount = (float)$child['amount'];
        $parentOrderId = (int)$child['parent_order_id'];

        $pdo->beginTransaction();
        try {
            // Cancel this specific child portion
            $updChild = $pdo->prepare("
                UPDATE child_orders
                SET status = 'Cancelled',
                    cancelled_at = ?
                WHERE id = ? AND LOWER(status) = 'available'
            ");
            $updChild->execute([$nowUtc, $childOrderId]);

            if ($updChild->rowCount() === 0) {
                $pdo->rollBack();
                return [
                    'success' => true,
                    'already_cancelled' => true,
                    'child_order_id' => $childOrderId,
                    'refunded_amount' => 0.0,
                    'message' => "Sell portion #{$childOrderId} is already cancelled."
                ];
            }

            // Release reserved tokens back to seller Available Balance
            Wallet::releaseReserved($sellerId, $amount, (string)$childOrderId, "Seller manually cancelled sell portion #{$childOrderId}");

            // Update parent remaining amount
            $pStmt = $pdo->prepare("SELECT remaining_amount, completed_amount FROM parent_sell_orders WHERE id = ?");
            $pStmt->execute([$parentOrderId]);
            $parentRow = $pStmt->fetch();
            $newRemaining = max(0.0, round(((float)($parentRow['remaining_amount'] ?? 0)) - $amount, 2));

            $updParent = $pdo->prepare("
                UPDATE parent_sell_orders
                SET remaining_amount = ?
                WHERE id = ?
            ");
            $updParent->execute([$newRemaining, $parentOrderId]);

            // Check if any in-flight or available child orders remain for parent
            $activeChk = $pdo->prepare("
                SELECT COUNT(*) FROM child_orders
                WHERE parent_order_id = ?
                  AND LOWER(status) IN ('available', 'reserved', 'payment proof submitted', 'seller confirmation pending', 'disputed')
            ");
            $activeChk->execute([$parentOrderId]);
            if ((int)$activeChk->fetchColumn() === 0) {
                $compAmt = (float)($parentRow['completed_amount'] ?? 0);
                $pStatus = ($compAmt > 0) ? 'partially_cancelled' : 'cancelled';
                $pdo->prepare("UPDATE parent_sell_orders SET status = ? WHERE id = ?")->execute([$pStatus, $parentOrderId]);
            }

            // Record last sell order timestamp for cooldown tracking
            $updLastSell = $pdo->prepare("UPDATE users SET last_sell_order_at = ? WHERE id = ?");
            $updLastSell->execute([$nowUtc, $sellerId]);

            $pdo->commit();

            return [
                'success' => true,
                'child_order_id' => $childOrderId,
                'refunded_amount' => $amount,
                'message' => "Sell portion #{$childOrderId} cancelled. ₹" . number_format($amount, 2) . " tokens immediately credited back to your Available Balance."
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to cancel portion: ' . $e->getMessage()];
        }
    }
}
