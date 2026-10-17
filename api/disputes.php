<?php
/**
 * Dedicated Disputes & Appeals API Controller (Part 5, Item 1)
 * Allows buyers and sellers to track appeals, view admin responses,
 * and submit additional evidence/statements.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Validator.php';
require_once __DIR__ . '/response.php';

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$input = getJsonInput();

$pdo = Database::getConnection();

switch ($action) {
    case 'list':
        $stmt = $pdo->prepare("
            SELECT d.*, c.amount, c.seller_id, c.buyer_id, c.status as order_status,
                   u_buyer.account_holder_name as buyer_name, u_buyer.phone as buyer_phone,
                   u_seller.account_holder_name as seller_name, u_seller.phone as seller_phone
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            LEFT JOIN users u_buyer ON c.buyer_id = u_buyer.id
            LEFT JOIN users u_seller ON c.seller_id = u_seller.id
            WHERE c.buyer_id = ? OR c.seller_id = ? OR d.raised_by_user_id = ?
            ORDER BY d.id DESC
            LIMIT 50
        ");
        $stmt->execute([$user['id'], $user['id'], $user['id']]);
        $disputes = $stmt->fetchAll();

        foreach ($disputes as &$disp) {
            $disp['formatted_id'] = sprintf('#%04d', (int)$disp['id']);
            $disp['formatted_order_id'] = sprintf('#%04d', (int)$disp['child_order_id']);
            $disp['amount'] = (float)$disp['amount'];
            $disp['user_role'] = ((int)$user['id'] === (int)$disp['seller_id']) ? 'seller' : 'buyer';
            $disp['is_initiator'] = ((int)$user['id'] === (int)$disp['raised_by_user_id']);
            if ($disp['user_role'] === 'seller') {
                // Buyer Privacy (Part 6): Seller only sees Order ID, never buyer personal details
                unset($disp['buyer_name'], $disp['buyer_phone'], $disp['buyer_name_masked'], $disp['buyer_phone_masked']);
            } else {
                if (!empty($disp['buyer_name'])) {
                    $disp['buyer_name_masked'] = Validator::maskName($disp['buyer_name']);
                }
            }
            if (!empty($disp['seller_name'])) {
                $disp['seller_name_masked'] = Validator::maskName($disp['seller_name']);
            }
        }

        jsonResponse(true, ['disputes' => $disputes]);
        break;

    case 'upload_evidence':
        if ($user['is_impersonating']) {
            jsonError('Dispute modification disabled in Support Mode.', 403);
        }

        $disputeId = (int)($input['dispute_id'] ?? 0);
        $evidencePath = trim($input['evidence_path'] ?? '');
        $statement = trim($input['statement'] ?? '');

        if ($disputeId <= 0) {
            jsonError('Invalid dispute ID.');
        }

        if (empty($evidencePath) && empty($statement)) {
            jsonError('Please provide evidence file or statement text.');
        }

        // Verify user is authorized party in this dispute
        $chk = $pdo->prepare("
            SELECT d.*, c.seller_id, c.buyer_id
            FROM disputes d
            JOIN child_orders c ON d.child_order_id = c.id
            WHERE d.id = ?
        ");
        $chk->execute([$disputeId]);
        $disp = $chk->fetch();

        if (!$disp) {
            jsonError('Dispute not found.', 404);
        }

        $isParty = ((int)$user['id'] === (int)$disp['seller_id'] || (int)$user['id'] === (int)$disp['buyer_id'] || (int)$user['id'] === (int)$disp['raised_by_user_id']);
        if (!$isParty) {
            jsonError('Unauthorized. You are not a party to this dispute.', 403);
        }

        if (in_array($disp['status'], ['resolved_seller', 'resolved_buyer', 'cancelled'], true)) {
            jsonError('This dispute has already been finalized by administration.', 400);
        }

        // Append evidence path if provided
        $currentEvidence = $disp['evidence_screenshot_path'] ?? '';
        $newEvidence = $currentEvidence;
        if (!empty($evidencePath)) {
            $newEvidence = empty($currentEvidence) ? $evidencePath : ($currentEvidence . ',' . $evidencePath);
        }

        // Append statement to resolution notes or reason
        $nowUtc = gmdate('Y-m-d H:i:s');
        $userRole = ((int)$user['id'] === (int)$disp['seller_id']) ? 'Seller' : 'Buyer';
        $additionalNote = "";
        if (!empty($statement)) {
            $additionalNote = "\n[{$nowUtc} - {$userRole} Additional Note]: " . $statement;
        }

        $updatedNotes = trim(($disp['resolution_notes'] ?? '') . $additionalNote);

        $upd = $pdo->prepare("
            UPDATE disputes
            SET evidence_screenshot_path = ?,
                resolution_notes = ?,
                additional_evidence_requested = 0,
                status = 'under_review',
                updated_at = ?
            WHERE id = ?
        ");
        $upd->execute([
            $newEvidence,
            $updatedNotes,
            $nowUtc,
            $disputeId
        ]);

        jsonResponse(true, [
            'message' => 'Evidence and statement submitted successfully. Admin review is in progress.',
            'dispute_id' => $disputeId
        ]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
