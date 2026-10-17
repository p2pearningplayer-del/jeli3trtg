<?php
/**
 * Referral System API Controller
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/ReferralEngine.php';
require_once __DIR__ . '/response.php';

$user = Auth::getCurrentUser();
if (!$user) {
    jsonError('Authentication required.', 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';

switch ($action) {
    case 'stats':
        $tree = ReferralEngine::getReferralTree($user['id']);
        $inviteUrl = APP_URL . '/?action=register&ref=' . urlencode($user['referral_code']);

        jsonResponse(true, [
            'referral_code' => $user['referral_code'],
            'invite_url' => $inviteUrl,
            'tree' => $tree
        ]);
        break;

    default:
        jsonError("Unknown action '{$action}'.", 404);
}
