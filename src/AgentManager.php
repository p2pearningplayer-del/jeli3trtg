<?php
/**
 * Agent Management System Core Service
 * Manages Agent account lifecycle, multi-tier referral hierarchy resolution (Levels A through Z),
 * idempotent base token purchase commission snapshotting, Monday-to-Sunday weekly accounting,
 * Google Authenticator TOTP verification, and administrative commission payouts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Security/TotpService.php';
require_once __DIR__ . '/Security/QRCodeGenerator.php';
require_once __DIR__ . '/Validator.php';

class AgentManager {

    /**
     * All 26 referral level letters
     */
    public const ALL_LEVELS = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'
    ];

    // ====================================================================
    // 1. HIERARCHY RESOLUTION (Levels A to Z: depths 1 to 26)
    // ====================================================================

    /**
     * Resolve the complete downstream hierarchy under a root user up to maxDepth (1-26).
     * Depth 1 = Level A, Depth 2 = Level B, ..., Depth 26 = Level Z.
     * Uses BFS with cycle detection.
     */
    public static function resolveDownstreamHierarchy(int $rootUserId, int $maxDepth = 26): array {
        $pdo = Database::getConnection();
        $descendants = [];
        $byUserId = [];
        $countByLevel = array_fill_keys(self::ALL_LEVELS, 0);

        $visited = [$rootUserId => true];
        $currentLevelIds = [$rootUserId];

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            if (empty($currentLevelIds)) {
                break;
            }

            $inPlaceholders = implode(',', array_fill(0, count($currentLevelIds), '?'));
            $stmt = $pdo->prepare("
                SELECT id, phone, account_holder_name, created_at, referred_by_id
                FROM users
                WHERE referred_by_id IN ({$inPlaceholders})
                ORDER BY id ASC
            ");
            $stmt->execute($currentLevelIds);
            $children = $stmt->fetchAll();

            $nextLevelIds = [];
            $levelLetter = chr(64 + $depth); // 1 => 'A', 2 => 'B', etc.

            foreach ($children as $child) {
                $childId = (int)$child['id'];
                if (isset($visited[$childId])) {
                    // Prevent circular referral loops
                    continue;
                }
                $visited[$childId] = true;
                $nextLevelIds[] = $childId;

                $record = [
                    'user_id' => $childId,
                    'phone' => $child['phone'],
                    'phone_masked' => Validator::maskPhone($child['phone']),
                    'name' => $child['account_holder_name'] ?: 'Trader #' . $childId,
                    'name_masked' => self::maskName($child['account_holder_name'] ?: 'Trader #' . $childId),
                    'parent_id' => (int)$child['referred_by_id'],
                    'depth' => $depth,
                    'level' => $levelLetter,
                    'joined_at' => $child['created_at']
                ];

                $descendants[] = $record;
                $byUserId[$childId] = [
                    'depth' => $depth,
                    'level' => $levelLetter
                ];
                $countByLevel[$levelLetter]++;
            }

            $currentLevelIds = $nextLevelIds;
        }

        return [
            'root_user_id' => $rootUserId,
            'total_users' => count($descendants),
            'descendants' => $descendants,
            'by_user_id' => $byUserId,
            'count_by_level' => $countByLevel
        ];
    }

    /**
     * Efficient reverse hierarchy lookup: climb up to 26 ancestor levels from a buyer
     * to find all active agents eligible for commission on this purchase.
     */
    public static function findEligibleAgentsForBuyer(int $buyerId, int $maxDepth = 26): array {
        $pdo = Database::getConnection();
        $eligibleAgents = [];

        $currentUserId = $buyerId;
        $visited = [$buyerId => true];

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $stmt = $pdo->prepare("SELECT referred_by_id FROM users WHERE id = ?");
            $stmt->execute([$currentUserId]);
            $parent = $stmt->fetch();

            if (!$parent || empty($parent['referred_by_id'])) {
                break;
            }

            $parentId = (int)$parent['referred_by_id'];
            if (isset($visited[$parentId])) {
                // Prevent circular loops
                break;
            }
            $visited[$parentId] = true;

            $levelLetter = chr(64 + $depth);

            // Find active agents monitoring this root user
            $agentStmt = $pdo->prepare("
                SELECT id, agent_code, name, root_user_id, commission_rate, eligible_levels, status
                FROM agents
                WHERE root_user_id = ? AND status = 'active'
            ");
            $agentStmt->execute([$parentId]);
            $agents = $agentStmt->fetchAll();

            foreach ($agents as $agent) {
                $allowedLevels = array_map('trim', explode(',', strtoupper($agent['eligible_levels'])));
                if (in_array($levelLetter, $allowedLevels, true)) {
                    $eligibleAgents[] = [
                        'agent_id' => (int)$agent['id'],
                        'agent_code' => $agent['agent_code'],
                        'agent_name' => $agent['name'],
                        'commission_rate' => (float)$agent['commission_rate'],
                        'referral_level' => $levelLetter,
                        'referral_depth' => $depth
                    ];
                }
            }

            $currentUserId = $parentId;
        }

        return $eligibleAgents;
    }

    // ====================================================================
    // 2. ORDER COMPLETION HOOK & IDEMPOTENT COMMISSION SNAPSHOTTING
    // ====================================================================

    /**
     * Hook called on order completion (StateMachine::confirmPayment / AdminManager::resolveDispute).
     * Calculates and snapshots commission strictly on base purchase amount (child_orders.amount).
     * Strictly idempotent per (agent_id, child_order_id).
     */
    public static function onOrderCompleted(int $childOrderId, int $buyerId, float $baseAmount, ?string $completedAt = null): array {
        $pdo = Database::getConnection();
        $completedUtc = $completedAt ?: gmdate('Y-m-d H:i:s');
        $commissionsAwarded = [];

        try {
            $eligibleAgents = self::findEligibleAgentsForBuyer($buyerId);
            if (empty($eligibleAgents)) {
                return ['success' => true, 'count' => 0, 'commissions' => []];
            }

            $checkStmt = $pdo->prepare("SELECT id FROM agent_commissions WHERE agent_id = ? AND child_order_id = ?");
            $insertStmt = $pdo->prepare("
                INSERT INTO agent_commissions (
                    agent_id, child_order_id, buyer_id, purchase_amount, commission_rate,
                    commission_amount, referral_level, referral_depth, status, order_completed_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'earned', ?, ?)
            ");

            foreach ($eligibleAgents as $ea) {
                $agentId = $ea['agent_id'];
                $checkStmt->execute([$agentId, $childOrderId]);
                if ($checkStmt->fetch()) {
                    // Already processed idempotently
                    continue;
                }

                $rate = $ea['commission_rate'];
                $commissionAmount = round($baseAmount * ($rate / 100.0), 2);

                if ($commissionAmount < 0) {
                    $commissionAmount = 0.0;
                }

                $insertStmt->execute([
                    $agentId,
                    $childOrderId,
                    $buyerId,
                    $baseAmount,
                    $rate,
                    $commissionAmount,
                    $ea['referral_level'],
                    $ea['referral_depth'],
                    $completedUtc,
                    $completedUtc
                ]);

                $commissionsAwarded[] = [
                    'agent_id' => $agentId,
                    'agent_code' => $ea['agent_code'],
                    'commission_amount' => $commissionAmount,
                    'level' => $ea['referral_level'],
                    'depth' => $ea['referral_depth']
                ];
            }

            return [
                'success' => true,
                'count' => count($commissionsAwarded),
                'commissions' => $commissionsAwarded
            ];
        } catch (Exception $e) {
            // Log but don't break main order settlement
            error_log("AgentManager::onOrderCompleted error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Hook called if an order is cancelled or reversed after completion.
     */
    public static function onOrderReversed(int $childOrderId): array {
        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare("
                UPDATE agent_commissions
                SET status = 'reversed'
                WHERE child_order_id = ? AND status = 'earned'
            ");
            $stmt->execute([$childOrderId]);
            return ['success' => true, 'updated' => $stmt->rowCount()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ====================================================================
    // 3. AGENT ACCOUNT MANAGEMENT (ADMIN OPERATIONS)
    // ====================================================================

    /**
     * List all agents with aggregated metrics for the admin panel.
     */
    public static function listAgents(array $filters = []): array {
        $pdo = Database::getConnection();

        $query = $_GET['query'] ?? '';
        $status = $filters['status'] ?? 'all';

        $where = ["1=1"];
        $params = [];

        if (!empty(trim($query))) {
            $q = '%' . trim($query) . '%';
            $where[] = "(a.agent_code LIKE ? OR a.name LIKE ? OR a.phone LIKE ? OR a.email LIKE ?)";
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        if ($status !== 'all') {
            $where[] = "a.status = ?";
            $params[] = $status;
        }

        $whereClause = implode(" AND ", $where);

        try {
            $stmt = $pdo->prepare("
                SELECT a.*, u.referral_code as root_referral_code, u.phone as root_phone, u.account_holder_name as root_name,
                       (SELECT COUNT(*) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_commission_orders,
                       (SELECT COALESCE(SUM(purchase_amount), 0) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_volume,
                       (SELECT COALESCE(SUM(commission_amount), 0) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_commission_earned,
                       (SELECT COALESCE(SUM(net_commission), 0) FROM agent_weekly_statements WHERE agent_id = a.id AND payout_status = 'paid') as total_commission_paid,
                       (SELECT COALESCE(SUM(net_commission), 0) FROM agent_weekly_statements WHERE agent_id = a.id AND payout_status IN ('pending_review', 'approved')) as total_commission_pending
                FROM agents a
                LEFT JOIN users u ON a.root_user_id = u.id
                WHERE {$whereClause}
                ORDER BY a.id DESC
            ");
            $stmt->execute($params);
            $agents = $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Auto self-heal schema migration if tables not yet created
            require_once __DIR__ . '/../config/schema_installer.php';
            SchemaInstaller::ensureSchema();
            try {
                $stmt = $pdo->prepare("
                    SELECT a.*, u.referral_code as root_referral_code, u.phone as root_phone, u.account_holder_name as root_name,
                           (SELECT COUNT(*) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_commission_orders,
                           (SELECT COALESCE(SUM(purchase_amount), 0) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_volume,
                           (SELECT COALESCE(SUM(commission_amount), 0) FROM agent_commissions WHERE agent_id = a.id AND status = 'earned') as total_commission_earned,
                           (SELECT COALESCE(SUM(net_commission), 0) FROM agent_weekly_statements WHERE agent_id = a.id AND payout_status = 'paid') as total_commission_paid,
                           (SELECT COALESCE(SUM(net_commission), 0) FROM agent_weekly_statements WHERE agent_id = a.id AND payout_status IN ('pending_review', 'approved')) as total_commission_pending
                    FROM agents a
                    LEFT JOIN users u ON a.root_user_id = u.id
                    WHERE {$whereClause}
                    ORDER BY a.id DESC
                ");
                $stmt->execute($params);
                $agents = $stmt->fetchAll();
            } catch (\PDOException $e2) {
                error_log("AgentManager::listAgents error: " . $e2->getMessage());
                return [];
            }
        }

        $currentWeek = self::getCurrentUtcWeek();
        $startOfWeek = $currentWeek['start_date'];
        $endOfWeek = $currentWeek['end_date'];

        $result = [];
        foreach ($agents as $agent) {
            $agentId = (int)$agent['id'];

            // Current week metrics
            $weekStmt = $pdo->prepare("
                SELECT COALESCE(SUM(purchase_amount), 0) as week_volume,
                       COALESCE(SUM(commission_amount), 0) as week_commission,
                       COUNT(*) as week_orders
                FROM agent_commissions
                WHERE agent_id = ? AND status = 'earned'
                  AND order_completed_at >= ? AND order_completed_at <= ?
            ");
            $weekStmt->execute([$agentId, $startOfWeek, $endOfWeek]);
            $weekStats = $weekStmt->fetch() ?: ['week_volume' => 0, 'week_commission' => 0, 'week_orders' => 0];

            // Resolve total referred users count
            $tree = self::resolveDownstreamHierarchy((int)$agent['root_user_id'], 26);

            $result[] = [
                'id' => $agentId,
                'agent_code' => $agent['agent_code'],
                'name' => $agent['name'],
                'phone' => $agent['phone'],
                'email' => $agent['email'],
                'root_user_id' => (int)$agent['root_user_id'],
                'root_referral_code' => $agent['root_referral_code'] ?? 'N/A',
                'root_name' => $agent['root_name'] ?? 'N/A',
                'commission_rate' => (float)$agent['commission_rate'],
                'eligible_levels' => $agent['eligible_levels'],
                'status' => $agent['status'],
                'must_change_password' => (int)$agent['must_change_password'],
                'total_referred_users' => $tree['total_users'],
                'total_volume' => (float)$agent['total_volume'],
                'total_commission_orders' => (int)$agent['total_commission_orders'],
                'total_commission_earned' => (float)$agent['total_commission_earned'],
                'total_commission_paid' => (float)$agent['total_commission_paid'],
                'total_commission_pending' => (float)$agent['total_commission_pending'],
                'current_week_volume' => (float)$weekStats['week_volume'],
                'current_week_commission' => (float)$weekStats['week_commission'],
                'current_week_orders' => (int)$weekStats['week_orders'],
                'last_login_at' => $agent['last_login_at'],
                'created_at' => $agent['created_at']
            ];
        }

        return $result;
    }

    /**
     * Get detailed agent profile with hierarchy stats and recent activity.
     */
    public static function getAgentDetail(int $agentId): ?array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT a.*, u.referral_code as root_referral_code, u.phone as root_phone, u.account_holder_name as root_name
            FROM agents a
            LEFT JOIN users u ON a.root_user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return null;
        }

        $hierarchy = self::resolveDownstreamHierarchy((int)$agent['root_user_id'], 26);
        $dashboardStats = self::getAgentDashboardStats($agentId);

        return [
            'agent' => [
                'id' => (int)$agent['id'],
                'agent_code' => $agent['agent_code'],
                'name' => $agent['name'],
                'phone' => $agent['phone'],
                'email' => $agent['email'],
                'root_user_id' => (int)$agent['root_user_id'],
                'root_referral_code' => $agent['root_referral_code'] ?? 'N/A',
                'root_name' => $agent['root_name'] ?? 'N/A',
                'commission_rate' => (float)$agent['commission_rate'],
                'eligible_levels' => $agent['eligible_levels'],
                'status' => $agent['status'],
                'must_change_password' => (int)$agent['must_change_password'],
                'last_login_at' => $agent['last_login_at'],
                'created_at' => $agent['created_at']
            ],
            'hierarchy_summary' => [
                'total_users' => $hierarchy['total_users'],
                'count_by_level' => $hierarchy['count_by_level']
            ],
            'stats' => $dashboardStats
        ];
    }

    /**
     * Create a new Agent account with credentials and Google Authenticator TOTP setup key.
     * Returns one-time credentials for the admin confirmation screen.
     */
    public static function createAgent(array $data, int $adminId): array {
        $pdo = Database::getConnection();

        $name = trim($data['name'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $email = trim($data['email'] ?? '');
        $rootUserIdOrCode = trim((string)($data['root_user_id'] ?? $data['root_referral_code'] ?? ''));
        $commissionRate = (float)($data['commission_rate'] ?? 0.0);
        $eligibleLevelsInput = $data['eligible_levels'] ?? self::ALL_LEVELS;

        if (empty($name)) {
            return ['success' => false, 'error' => 'Agent Name is required.'];
        }

        if (empty($rootUserIdOrCode)) {
            return ['success' => false, 'error' => 'Root Referral User ID or Referral Code is required.'];
        }

        if ($commissionRate < 0.0 || $commissionRate > 100.0) {
            return ['success' => false, 'error' => 'Commission rate must be between 0.00% and 100.00%.'];
        }

        // Resolve root user
        $rootUser = null;
        if (is_numeric($rootUserIdOrCode)) {
            $uStmt = $pdo->prepare("SELECT id, referral_code, account_holder_name FROM users WHERE id = ?");
            $uStmt->execute([(int)$rootUserIdOrCode]);
            $rootUser = $uStmt->fetch();
        }
        if (!$rootUser) {
            $uStmt = $pdo->prepare("SELECT id, referral_code, account_holder_name FROM users WHERE referral_code = ?");
            $uStmt->execute([strtoupper($rootUserIdOrCode)]);
            $rootUser = $uStmt->fetch();
        }

        if (!$rootUser) {
            return ['success' => false, 'error' => "Root user '{$rootUserIdOrCode}' not found. Please provide a valid user ID or referral code."];
        }

        $rootUserId = (int)$rootUser['id'];

        // Normalize eligible levels
        if (is_array($eligibleLevelsInput)) {
            $levelsArr = array_intersect(self::ALL_LEVELS, array_map('strtoupper', array_map('trim', $eligibleLevelsInput)));
        } else {
            $levelsArr = array_intersect(self::ALL_LEVELS, array_map('strtoupper', array_map('trim', explode(',', (string)$eligibleLevelsInput))));
        }
        if (empty($levelsArr)) {
            $levelsArr = self::ALL_LEVELS;
        }
        $eligibleLevelsStr = implode(',', $levelsArr);

        // Generate or validate agent_code (Agent User ID)
        $agentCode = trim($data['agent_code'] ?? '');
        if (empty($agentCode)) {
            $agentCode = self::generateNextAgentCode();
        } else {
            $agentCode = strtoupper($agentCode);
            $dupStmt = $pdo->prepare("SELECT id FROM agents WHERE agent_code = ?");
            $dupStmt->execute([$agentCode]);
            if ($dupStmt->fetch()) {
                return ['success' => false, 'error' => "Agent User ID '{$agentCode}' is already taken."];
            }
        }

        // Generate secure temporary password
        $plainPassword = !empty($data['password']) ? trim($data['password']) : self::generateSecurePassword();
        $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        // Generate TOTP Secret (160-bit Base32) and encrypt at rest
        $totpSecret = \Security\TotpService::generateSecret(20);
        $encryptedSecret = \Security\TotpService::encryptSecret($totpSecret);
        $provisioningUri = \Security\TotpService::getProvisioningUri($totpSecret, $agentCode, 'JeliPay Agent');
        $qrSvg = \Security\QRCodeGenerator::generateSvg($provisioningUri, 220, '#0f172a', '#ffffff');

        $now = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO agents (
                agent_code, name, phone, email, password_hash, root_user_id,
                commission_rate, eligible_levels, totp_secret, totp_last_timestamp,
                status, must_change_password, created_by_admin_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active', 1, ?, ?, ?)
        ");
        $stmt->execute([
            $agentCode,
            $name,
            $phone ?: null,
            $email ?: null,
            $passwordHash,
            $rootUserId,
            $commissionRate,
            $eligibleLevelsStr,
            $encryptedSecret,
            $adminId,
            $now,
            $now
        ]);

        $agentId = (int)$pdo->lastInsertId();

        self::logAudit(
            'admin',
            $adminId,
            $agentId,
            'agent_created',
            json_encode([
                'agent_code' => $agentCode,
                'name' => $name,
                'root_user_id' => $rootUserId,
                'commission_rate' => $commissionRate,
                'eligible_levels' => $eligibleLevelsStr
            ])
        );

        return [
            'success' => true,
            'agent_id' => $agentId,
            'agent_code' => $agentCode,
            'name' => $name,
            'root_user_id' => $rootUserId,
            'root_referral_code' => $rootUser['referral_code'],
            'commission_rate' => $commissionRate,
            'eligible_levels' => $eligibleLevelsStr,
            'plain_password' => $plainPassword,
            'totp_secret' => $totpSecret,
            'provisioning_uri' => $provisioningUri,
            'qr_svg' => $qrSvg,
            'login_url' => '/agent/login'
        ];
    }

    /**
     * Update agent settings (commission rate, eligible levels, name, phone, email).
     */
    public static function updateAgent(int $agentId, array $data, int $adminId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found.'];
        }

        $name = trim($data['name'] ?? $agent['name']);
        $phone = isset($data['phone']) ? trim($data['phone']) : $agent['phone'];
        $email = isset($data['email']) ? trim($data['email']) : $agent['email'];
        $commissionRate = isset($data['commission_rate']) ? (float)$data['commission_rate'] : (float)$agent['commission_rate'];

        if ($commissionRate < 0.0 || $commissionRate > 100.0) {
            return ['success' => false, 'error' => 'Commission rate must be between 0.00% and 100.00%.'];
        }

        $eligibleLevelsStr = $agent['eligible_levels'];
        if (isset($data['eligible_levels'])) {
            $levelsArr = is_array($data['eligible_levels'])
                ? array_intersect(self::ALL_LEVELS, array_map('strtoupper', array_map('trim', $data['eligible_levels'])))
                : array_intersect(self::ALL_LEVELS, array_map('strtoupper', array_map('trim', explode(',', (string)$data['eligible_levels']))));
            if (!empty($levelsArr)) {
                $eligibleLevelsStr = implode(',', $levelsArr);
            }
        }

        $upd = $pdo->prepare("
            UPDATE agents
            SET name = ?, phone = ?, email = ?, commission_rate = ?, eligible_levels = ?, updated_at = ?
            WHERE id = ?
        ");
        $now = gmdate('Y-m-d H:i:s');
        $upd->execute([$name, $phone ?: null, $email ?: null, $commissionRate, $eligibleLevelsStr, $now, $agentId]);

        self::logAudit(
            'admin',
            $adminId,
            $agentId,
            'settings_updated',
            json_encode([
                'before' => [
                    'name' => $agent['name'],
                    'commission_rate' => $agent['commission_rate'],
                    'eligible_levels' => $agent['eligible_levels']
                ],
                'after' => [
                    'name' => $name,
                    'commission_rate' => $commissionRate,
                    'eligible_levels' => $eligibleLevelsStr
                ]
            ])
        );

        return ['success' => true, 'message' => 'Agent settings updated successfully.'];
    }

    /**
     * Reset Agent password and generate a new temporary password.
     */
    public static function resetPassword(int $agentId, int $adminId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found.'];
        }

        $newPassword = self::generateSecurePassword();
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $now = gmdate('Y-m-d H:i:s');

        $upd = $pdo->prepare("UPDATE agents SET password_hash = ?, must_change_password = 1, updated_at = ? WHERE id = ?");
        $upd->execute([$passwordHash, $now, $agentId]);

        self::logAudit('admin', $adminId, $agentId, 'password_reset', 'Admin initiated password reset.');

        return [
            'success' => true,
            'agent_code' => $agent['agent_code'],
            'new_password' => $newPassword,
            'message' => 'Password reset successfully. Please share the temporary password with the agent.'
        ];
    }

    /**
     * Reset Agent Google Authenticator TOTP setup key and generate a fresh QR code.
     */
    public static function resetTotp(int $agentId, int $adminId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found.'];
        }

        $newSecret = \Security\TotpService::generateSecret(20);
        $encryptedSecret = \Security\TotpService::encryptSecret($newSecret);
        $provisioningUri = \Security\TotpService::getProvisioningUri($newSecret, $agent['agent_code'], 'JeliPay Agent');
        $qrSvg = \Security\QRCodeGenerator::generateSvg($provisioningUri, 220, '#0f172a', '#ffffff');
        $now = gmdate('Y-m-d H:i:s');

        $upd = $pdo->prepare("UPDATE agents SET totp_secret = ?, totp_last_timestamp = 0, updated_at = ? WHERE id = ?");
        $upd->execute([$encryptedSecret, $now, $agentId]);

        self::logAudit('admin', $adminId, $agentId, 'totp_reset', 'Admin initiated Google Authenticator reset.');

        return [
            'success' => true,
            'agent_code' => $agent['agent_code'],
            'totp_secret' => $newSecret,
            'provisioning_uri' => $provisioningUri,
            'qr_svg' => $qrSvg,
            'message' => 'Google Authenticator reset successfully. Please have the agent re-scan the QR code.'
        ];
    }

    /**
     * Toggle Agent account status (active <-> suspended).
     */
    public static function toggleStatus(int $agentId, int $adminId, ?string $reason = null): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found.'];
        }

        $newStatus = ($agent['status'] === 'active') ? 'suspended' : 'active';
        $now = gmdate('Y-m-d H:i:s');

        $upd = $pdo->prepare("UPDATE agents SET status = ?, updated_at = ? WHERE id = ?");
        $upd->execute([$newStatus, $now, $agentId]);

        self::logAudit('admin', $adminId, $agentId, 'status_changed', json_encode([
            'from' => $agent['status'],
            'to' => $newStatus,
            'reason' => $reason ?: 'Admin status toggle'
        ]));

        return [
            'success' => true,
            'new_status' => $newStatus,
            'message' => "Agent account is now {$newStatus}."
        ];
    }

    // ====================================================================
    // 4. AGENT AUTHENTICATION & SESSION MANAGEMENT
    // ====================================================================

    /**
     * Authenticate Agent with Agent User ID + Password + 6-digit Google Authenticator TOTP.
     */
    public static function authenticate(string $agentCode, string $password, string $totpCode, ?int $timestamp = null): array {
        $pdo = Database::getConnection();
        $agentCode = trim($agentCode);

        if (empty($agentCode) || empty($password) || empty($totpCode)) {
            return ['success' => false, 'error' => 'Agent User ID, Password, and 6-digit Authenticator Code are required.'];
        }

        // Clean TOTP code
        $totpClean = preg_replace('/\s+/', '', $totpCode);
        if (!preg_match('/^\d{6}$/', $totpClean)) {
            return ['success' => false, 'error' => 'Authenticator code must be exactly 6 digits.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE agent_code = ? OR phone = ? OR email = ?");
        $stmt->execute([strtoupper($agentCode), $agentCode, $agentCode]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Invalid Agent credentials.'];
        }

        if ($agent['status'] !== 'active') {
            return ['success' => false, 'error' => 'Your Agent account is currently suspended. Please contact platform administration.'];
        }

        if (!password_verify($password, $agent['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid Agent credentials.'];
        }

        // Decrypt TOTP secret
        $plainSecret = \Security\TotpService::decryptSecret($agent['totp_secret']);
        if (!$plainSecret) {
            return ['success' => false, 'error' => 'Agent 2FA configuration error. Please contact administrator to reset your authenticator.'];
        }

        // Verify live TOTP code with clock drift and replay protection
        $verifyRes = \Security\TotpService::verifyCode(
            $plainSecret,
            $totpClean,
            (int)$agent['totp_last_timestamp'],
            1, // +/- 1 step (30s) drift tolerance
            $timestamp
        );

        if (!$verifyRes['valid']) {
            return ['success' => false, 'error' => $verifyRes['error'] ?: 'Invalid or expired Google Authenticator code. Check your device clock sync.'];
        }

        $matchedStep = (int)$verifyRes['step'];

        // Update last login and timestamp replay guard
        $now = gmdate('Y-m-d H:i:s');
        $upd = $pdo->prepare("UPDATE agents SET last_login_at = ?, totp_last_timestamp = ? WHERE id = ?");
        $upd->execute([$now, $matchedStep, $agent['id']]);

        // Segregated Agent Session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['agent_id'] = (int)$agent['id'];
        $_SESSION['agent_code'] = $agent['agent_code'];
        $_SESSION['agent_name'] = $agent['name'];
        $_SESSION['agent_logged_in'] = true;
        $_SESSION['agent_last_activity'] = time();

        self::logAudit('agent', (int)$agent['id'], (int)$agent['id'], 'agent_login', 'Agent logged in successfully.');

        return [
            'success' => true,
            'agent' => [
                'id' => (int)$agent['id'],
                'agent_code' => $agent['agent_code'],
                'name' => $agent['name'],
                'must_change_password' => (int)$agent['must_change_password']
            ]
        ];
    }

    /**
     * Get current authenticated agent from session.
     */
    public static function getCurrentAgent(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['agent_id']) || empty($_SESSION['agent_logged_in'])) {
            return null;
        }

        $agentId = (int)$_SESSION['agent_id'];
        $pdo = Database::getConnection();
        $statusCondition = !empty($_SESSION['is_agent_impersonating']) ? "" : " AND status = 'active'";
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?" . $statusCondition);
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            self::agentLogout();
            return null;
        }

        return $agent;
    }

    /**
     * Start Admin Impersonation Support Session for Agent Dashboard.
     * Allows authenticated administrator to inspect the Agent's portal in read-only mode.
     */
    public static function startAgentImpersonation(int $adminId, int $targetAgentId): array {
        if ($adminId <= 0) {
            return ['success' => false, 'error' => 'Administrator authentication required.'];
        }

        if ($targetAgentId <= 0) {
            return ['success' => false, 'error' => 'Target Agent ID required.'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$targetAgentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Target Agent not found.'];
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Preserve real administrator identity
        if (empty($_SESSION['real_admin_id'])) {
            $_SESSION['real_admin_id'] = $adminId;
        }

        // Unset any user impersonation flags if active
        unset($_SESSION['is_impersonating']);

        // Set agent impersonation session flags
        $_SESSION['is_agent_impersonating'] = true;
        $_SESSION['agent_id'] = (int)$agent['id'];
        $_SESSION['agent_code'] = $agent['agent_code'];
        $_SESSION['agent_name'] = $agent['name'];
        $_SESSION['agent_logged_in'] = true;
        $_SESSION['agent_last_activity'] = time();

        // Audit logging in agent audit ledger
        self::logAudit(
            'admin',
            $adminId,
            $targetAgentId,
            'start_agent_impersonation',
            "Support inspection mode activated for agent {$agent['agent_code']}"
        );

        // Audit logging in platform admin ledger
        if (class_exists('AdminManager')) {
            AdminManager::logAudit(
                $adminId,
                'IMPERSONATE_AGENT',
                'agents',
                (string)$targetAgentId,
                null,
                ['impersonating_agent_code' => $agent['agent_code'], 'agent_id' => $targetAgentId],
                'Support inspection mode activated for agent'
            );
        }

        return [
            'success' => true,
            'agent' => $agent,
            'redirect' => '/agent/dashboard'
        ];
    }

    /**
     * Exit Admin Impersonation Support Session for Agent Dashboard.
     * Restores the administrator session and redirects to Admin Panel agents section.
     */
    public static function exitAgentImpersonation(): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $adminId = $_SESSION['real_admin_id'] ?? null;
        $targetAgentId = $_SESSION['agent_id'] ?? null;

        if ($adminId && $targetAgentId) {
            self::logAudit(
                'admin',
                (int)$adminId,
                (int)$targetAgentId,
                'exit_agent_impersonation',
                'Support inspection mode exited'
            );
            if (class_exists('AdminManager')) {
                AdminManager::logAudit(
                    (int)$adminId,
                    'EXIT_IMPERSONATE_AGENT',
                    'agents',
                    (string)$targetAgentId,
                    null,
                    ['agent_id' => $targetAgentId],
                    'Support inspection mode exited for agent'
                );
            }
        }

        // Clear agent session variables
        unset(
            $_SESSION['agent_id'],
            $_SESSION['agent_code'],
            $_SESSION['agent_name'],
            $_SESSION['agent_logged_in'],
            $_SESSION['agent_last_activity'],
            $_SESSION['is_agent_impersonating']
        );

        // Restore administrator session
        if ($adminId) {
            $_SESSION['user_id'] = (int)$adminId;
            $_SESSION['role'] = 'admin';
            unset($_SESSION['real_admin_id']);
        }

        return [
            'success' => true,
            'redirect' => '/admin.php#agents'
        ];
    }

    /**
     * Change Agent Password from Agent Dashboard.
     */
    public static function agentChangePassword(int $agentId, string $currentPassword, string $newPassword, string $totpCode, ?int $timestamp = null): array {
        if (!empty($_SESSION['is_agent_impersonating'])) {
            return ['success' => false, 'error' => 'Password modifications are strictly disabled in Admin Support Mode.'];
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ?");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return ['success' => false, 'error' => 'Agent not found.'];
        }

        if (!password_verify($currentPassword, $agent['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'New password must be at least 8 characters long.'];
        }

        // Verify TOTP
        $totpClean = preg_replace('/\s+/', '', $totpCode);
        $plainSecret = \Security\TotpService::decryptSecret($agent['totp_secret']);
        $verifyRes = \Security\TotpService::verifyCode(
            $plainSecret,
            $totpClean,
            (int)$agent['totp_last_timestamp'],
            1,
            $timestamp
        );

        if (!$verifyRes['valid']) {
            return ['success' => false, 'error' => $verifyRes['error'] ?: 'Invalid or expired Authenticator code.'];
        }

        $matchedStep = (int)$verifyRes['step'];
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);
        $now = gmdate('Y-m-d H:i:s');

        $upd = $pdo->prepare("UPDATE agents SET password_hash = ?, must_change_password = 0, totp_last_timestamp = ?, updated_at = ? WHERE id = ?");
        $upd->execute([$newHash, $matchedStep, $now, $agentId]);

        self::logAudit('agent', $agentId, $agentId, 'password_changed', 'Agent updated password with 2FA verification.');

        return ['success' => true, 'message' => 'Password updated successfully.'];
    }

    /**
     * Terminate Agent session.
     */
    public static function agentLogout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset(
            $_SESSION['agent_id'],
            $_SESSION['agent_code'],
            $_SESSION['agent_name'],
            $_SESSION['agent_logged_in'],
            $_SESSION['agent_last_activity'],
            $_SESSION['is_agent_impersonating']
        );
    }

    // ====================================================================
    // 5. AGENT DASHBOARD ANALYTICS & PURCHASE HISTORY
    // ====================================================================

    /**
     * Aggregated summary metrics for Agent Dashboard.
     */
    public static function getAgentDashboardStats(int $agentId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT a.*, u.referral_code as root_referral_code, u.account_holder_name as root_name
            FROM agents a
            LEFT JOIN users u ON a.root_user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$agentId]);
        $agent = $stmt->fetch();

        if (!$agent) {
            return [];
        }

        // Downstream tree user counts
        $hierarchy = self::resolveDownstreamHierarchy((int)$agent['root_user_id'], 26);

        // Date bounds
        $nowUtc = gmdate('Y-m-d H:i:s');
        $todayStart = gmdate('Y-m-d 00:00:00');
        $rolling7dStart = gmdate('Y-m-d H:i:s', strtotime('-7 days'));

        $weekBounds = self::getCurrentUtcWeek();
        $weekStart = $weekBounds['start_date'];
        $weekEnd = $weekBounds['end_date'];

        // All-Time volume & commission
        $allTimeStmt = $pdo->prepare("
            SELECT COALESCE(SUM(purchase_amount), 0) as total_volume,
                   COALESCE(SUM(commission_amount), 0) as total_commission,
                   COUNT(*) as total_orders
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'earned'
        ");
        $allTimeStmt->execute([$agentId]);
        $allTime = $allTimeStmt->fetch() ?: ['total_volume' => 0, 'total_commission' => 0, 'total_orders' => 0];

        // Today's volume
        $todayStmt = $pdo->prepare("
            SELECT COALESCE(SUM(purchase_amount), 0) as today_volume,
                   COALESCE(SUM(commission_amount), 0) as today_commission,
                   COUNT(*) as today_orders
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'earned' AND order_completed_at >= ?
        ");
        $todayStmt->execute([$agentId, $todayStart]);
        $todayStats = $todayStmt->fetch() ?: ['today_volume' => 0, 'today_commission' => 0, 'today_orders' => 0];

        // Rolling 7-Day volume
        $rollingStmt = $pdo->prepare("
            SELECT COALESCE(SUM(purchase_amount), 0) as rolling_7d_volume,
                   COALESCE(SUM(commission_amount), 0) as rolling_7d_commission,
                   COUNT(*) as rolling_7d_orders
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'earned' AND order_completed_at >= ?
        ");
        $rollingStmt->execute([$agentId, $rolling7dStart]);
        $rollingStats = $rollingStmt->fetch() ?: ['rolling_7d_volume' => 0, 'rolling_7d_commission' => 0, 'rolling_7d_orders' => 0];

        // Current Monday-Sunday week volume & commission
        $weekStmt = $pdo->prepare("
            SELECT COALESCE(SUM(purchase_amount), 0) as week_volume,
                   COALESCE(SUM(commission_amount), 0) as week_commission,
                   COUNT(*) as week_orders
            FROM agent_commissions
            WHERE agent_id = ? AND status = 'earned'
              AND order_completed_at >= ? AND order_completed_at <= ?
        ");
        $weekStmt->execute([$agentId, $weekStart, $weekEnd]);
        $weekStats = $weekStmt->fetch() ?: ['week_volume' => 0, 'week_commission' => 0, 'week_orders' => 0];

        // Statement commission aggregations (Paid / Pending / Approved)
        $stmtSum = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN payout_status = 'paid' THEN net_commission ELSE 0 END), 0) as paid_commission,
                COALESCE(SUM(CASE WHEN payout_status IN ('pending_review', 'approved') THEN net_commission ELSE 0 END), 0) as pending_commission,
                COALESCE(SUM(CASE WHEN payout_status = 'approved' THEN net_commission ELSE 0 END), 0) as approved_commission
            FROM agent_weekly_statements
            WHERE agent_id = ?
        ");
        $stmtSum->execute([$agentId]);
        $statementSums = $stmtSum->fetch() ?: ['paid_commission' => 0, 'pending_commission' => 0, 'approved_commission' => 0];

        // Last payout
        $lastPayoutStmt = $pdo->prepare("
            SELECT net_commission, payout_reference, paid_at, statement_code
            FROM agent_weekly_statements
            WHERE agent_id = ? AND payout_status = 'paid'
            ORDER BY paid_at DESC, id DESC
            LIMIT 1
        ");
        $lastPayoutStmt->execute([$agentId]);
        $lastPayout = $lastPayoutStmt->fetch();

        return [
            'agent_code' => $agent['agent_code'],
            'agent_name' => $agent['name'],
            'root_referral_code' => $agent['root_referral_code'] ?? 'N/A',
            'commission_rate' => (float)$agent['commission_rate'],
            'eligible_levels' => $agent['eligible_levels'],
            'total_referred_users' => $hierarchy['total_users'],
            'users_by_level' => $hierarchy['count_by_level'],
            'total_volume' => (float)$allTime['total_volume'],
            'total_orders' => (int)$allTime['total_orders'],
            'total_commission_earned' => (float)$allTime['total_commission'],
            'today_volume' => (float)$todayStats['today_volume'],
            'today_commission' => (float)$todayStats['today_commission'],
            'today_orders' => (int)$todayStats['today_orders'],
            'rolling_7d_volume' => (float)$rollingStats['rolling_7d_volume'],
            'rolling_7d_commission' => (float)$rollingStats['rolling_7d_commission'],
            'rolling_7d_orders' => (int)$rollingStats['rolling_7d_orders'],
            'current_week_volume' => (float)$weekStats['week_volume'],
            'current_week_commission' => (float)$weekStats['week_commission'],
            'current_week_orders' => (int)$weekStats['week_orders'],
            'current_week_number' => $weekBounds['week_number'],
            'current_week_year' => $weekBounds['year'],
            'current_week_range' => date('M d', strtotime($weekStart)) . ' - ' . date('M d, Y', strtotime($weekEnd)),
            'paid_commission' => (float)$statementSums['paid_commission'],
            'pending_commission' => (float)$statementSums['pending_commission'],
            'approved_commission' => (float)$statementSums['approved_commission'],
            'last_payout' => $lastPayout ? [
                'amount' => (float)$lastPayout['net_commission'],
                'reference' => $lastPayout['payout_reference'],
                'paid_at' => $lastPayout['paid_at'],
                'statement_code' => $lastPayout['statement_code']
            ] : null
        ];
    }

    /**
     * Filterable purchase history with privacy-preserving masked user PII.
     */
    public static function getAgentPurchases(int $agentId, array $filters = []): array {
        $pdo = Database::getConnection();

        $where = ["ac.agent_id = ?"];
        $params = [$agentId];

        if (!empty($filters['status'])) {
            $where[] = "ac.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $where[] = "ac.referral_level = ?";
            $params[] = strtoupper(trim($filters['level']));
        }

        if (!empty($filters['date_from'])) {
            $where[] = "ac.order_completed_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "ac.order_completed_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['order_id'])) {
            $where[] = "ac.child_order_id = ?";
            $params[] = (int)$filters['order_id'];
        }

        $whereClause = implode(" AND ", $where);

        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM agent_commissions ac WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT ac.*, u.phone as buyer_phone, u.account_holder_name as buyer_name, u.referral_code as buyer_code
            FROM agent_commissions ac
            LEFT JOIN users u ON ac.buyer_id = u.id
            WHERE {$whereClause}
            ORDER BY ac.order_completed_at DESC, ac.id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $purchases = [];
        foreach ($rows as $r) {
            $purchases[] = [
                'id' => (int)$r['id'],
                'child_order_id' => (int)$r['child_order_id'],
                'buyer_id' => (int)$r['buyer_id'],
                'buyer_masked_phone' => Validator::maskPhone($r['buyer_phone'] ?? ''),
                'buyer_masked_name' => self::maskName($r['buyer_name'] ?: 'Trader #' . $r['buyer_id']),
                'purchase_amount' => (float)$r['purchase_amount'],
                'commission_rate' => (float)$r['commission_rate'],
                'commission_amount' => (float)$r['commission_amount'],
                'referral_level' => $r['referral_level'],
                'referral_depth' => (int)$r['referral_depth'],
                'status' => $r['status'],
                'completed_at' => $r['order_completed_at']
            ];
        }

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'purchases' => $purchases
        ];
    }

    /**
     * Get weekly statements for an agent.
     */
    public static function getAgentStatements(int $agentId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT * FROM agent_weekly_statements
            WHERE agent_id = ?
            ORDER BY week_year DESC, week_number DESC
        ");
        $stmt->execute([$agentId]);
        return $stmt->fetchAll();
    }

    // ====================================================================
    // 6. WEEKLY ACCOUNTING & ADMIN PAYOUTS (Monday - Sunday UTC)
    // ====================================================================

    /**
     * Calculate start and end UTC bounds for an ISO week (Monday 00:00:00 to Sunday 23:59:59).
     */
    public static function calculateWeekBounds(int $year, int $weekNumber): array {
        $dto = new DateTime();
        $dto->setTimezone(new DateTimeZone('UTC'));
        $dto->setISODate($year, $weekNumber, 1); // Monday
        $startDate = $dto->format('Y-m-d 00:00:00');
        $dto->setISODate($year, $weekNumber, 7); // Sunday
        $endDate = $dto->format('Y-m-d 23:59:59');

        return [
            'year' => $year,
            'week_number' => $weekNumber,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'label' => date('M d', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . " (Week {$weekNumber}, {$year})"
        ];
    }

    /**
     * Get current UTC week information.
     */
    public static function getCurrentUtcWeek(): array {
        $year = (int)gmdate('o'); // ISO-8601 year
        $week = (int)gmdate('W'); // ISO-8601 week
        return self::calculateWeekBounds($year, $week);
    }

    /**
     * Calculate and generate weekly statements for all agents or a specific agent/week.
     */
    public static function generateWeeklyStatements(?int $targetYear = null, ?int $targetWeek = null, ?int $targetAgentId = null): array {
        $pdo = Database::getConnection();

        $currentWeek = self::getCurrentUtcWeek();
        $year = $targetYear ?: $currentWeek['year'];
        $week = $targetWeek ?: $currentWeek['week_number'];
        $bounds = self::calculateWeekBounds($year, $week);

        $agentsQuery = "SELECT id, agent_code FROM agents WHERE status = 'active'";
        $agentParams = [];
        if ($targetAgentId) {
            $agentsQuery .= " AND id = ?";
            $agentParams[] = $targetAgentId;
        }

        $agentStmt = $pdo->prepare($agentsQuery);
        $agentStmt->execute($agentParams);
        $agents = $agentStmt->fetchAll();

        $generated = [];

        foreach ($agents as $agent) {
            $agentId = (int)$agent['id'];
            $agentCode = $agent['agent_code'];
            $statementCode = sprintf('STMT-%04d-W%02d-%s', $year, $week, $agentCode);

            // Fetch aggregate for this agent and week
            $commStmt = $pdo->prepare("
                SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(purchase_amount), 0) as total_volume,
                       COALESCE(SUM(commission_amount), 0) as gross_commission
                FROM agent_commissions
                WHERE agent_id = ? AND status = 'earned'
                  AND order_completed_at >= ? AND order_completed_at <= ?
            ");
            $commStmt->execute([$agentId, $bounds['start_date'], $bounds['end_date']]);
            $comm = $commStmt->fetch();

            $totalOrders = (int)($comm['total_orders'] ?? 0);
            $totalVolume = (float)($comm['total_volume'] ?? 0.0);
            $grossCommission = (float)($comm['gross_commission'] ?? 0.0);

            // Check if statement already exists
            $existStmt = $pdo->prepare("SELECT * FROM agent_weekly_statements WHERE agent_id = ? AND week_year = ? AND week_number = ?");
            $existStmt->execute([$agentId, $year, $week]);
            $existing = $existStmt->fetch();

            if ($existing) {
                // If not already paid, update aggregates
                if ($existing['payout_status'] !== 'paid') {
                    $adj = (float)$existing['adjustment_amount'];
                    $net = round($grossCommission + $adj, 2);
                    $upd = $pdo->prepare("
                        UPDATE agent_weekly_statements
                        SET total_volume = ?, total_orders = ?, gross_commission = ?, net_commission = ?, updated_at = ?
                        WHERE id = ?
                    ");
                    $now = gmdate('Y-m-d H:i:s');
                    $upd->execute([$totalVolume, $totalOrders, $grossCommission, $net, $now, $existing['id']]);
                }
                $generated[] = [
                    'agent_id' => $agentId,
                    'statement_code' => $existing['statement_code'],
                    'status' => 'updated'
                ];
            } else {
                // Only create if there is volume or active tracking
                $netCommission = $grossCommission;
                $ins = $pdo->prepare("
                    INSERT INTO agent_weekly_statements (
                        statement_code, agent_id, week_number, week_year, start_date, end_date,
                        total_volume, total_orders, gross_commission, adjustment_amount,
                        net_commission, payout_status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'pending_review', ?, ?)
                ");
                $now = gmdate('Y-m-d H:i:s');
                $ins->execute([
                    $statementCode,
                    $agentId,
                    $week,
                    $year,
                    $bounds['start_date'],
                    $bounds['end_date'],
                    $totalVolume,
                    $totalOrders,
                    $grossCommission,
                    $netCommission,
                    $now,
                    $now
                ]);
                $generated[] = [
                    'agent_id' => $agentId,
                    'statement_code' => $statementCode,
                    'status' => 'created'
                ];
            }
        }

        return [
            'success' => true,
            'year' => $year,
            'week' => $week,
            'week_range' => $bounds['label'],
            'generated_count' => count($generated),
            'results' => $generated
        ];
    }

    /**
     * List all weekly statements across all agents for administrative review.
     */
    public static function listAdminStatements(array $filters = []): array {
        $pdo = Database::getConnection();

        $where = ["1=1"];
        $params = [];

        if (!empty($filters['agent_id'])) {
            $where[] = "s.agent_id = ?";
            $params[] = (int)$filters['agent_id'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "s.payout_status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['year'])) {
            $where[] = "s.week_year = ?";
            $params[] = (int)$filters['year'];
        }

        if (!empty($filters['week'])) {
            $where[] = "s.week_number = ?";
            $params[] = (int)$filters['week'];
        }

        $whereClause = implode(" AND ", $where);

        try {
            $stmt = $pdo->prepare("
                SELECT s.*, a.agent_code, a.name as agent_name, a.commission_rate,
                       u.referral_code as root_referral_code,
                       admin_p.account_holder_name as paid_by_name,
                       admin_a.account_holder_name as approved_by_name
                FROM agent_weekly_statements s
                JOIN agents a ON s.agent_id = a.id
                LEFT JOIN users u ON a.root_user_id = u.id
                LEFT JOIN users admin_p ON s.paid_by_admin_id = admin_p.id
                LEFT JOIN users admin_a ON s.approved_by_admin_id = admin_a.id
                WHERE {$whereClause}
                ORDER BY s.week_year DESC, s.week_number DESC, s.id DESC
            ");
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("AgentManager::listAdminStatements error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add an administrative adjustment (bonus or deduction) to a weekly statement.
     */
    public static function adjustStatement(int $statementId, float $adjustmentAmount, string $reason, int $adminId): array {
        $pdo = Database::getConnection();

        if (empty(trim($reason))) {
            return ['success' => false, 'error' => 'A mandatory reason must be provided for statement adjustments.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM agent_weekly_statements WHERE id = ?");
        $stmt->execute([$statementId]);
        $statement = $stmt->fetch();

        if (!$statement) {
            return ['success' => false, 'error' => 'Weekly statement not found.'];
        }

        if ($statement['payout_status'] === 'paid') {
            return ['success' => false, 'error' => 'Cannot adjust a statement that has already been marked as Paid.'];
        }

        $gross = (float)$statement['gross_commission'];
        $netCommission = round($gross + $adjustmentAmount, 2);
        if ($netCommission < 0) {
            $netCommission = 0.0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $upd = $pdo->prepare("
            UPDATE agent_weekly_statements
            SET adjustment_amount = ?, adjustment_reason = ?, net_commission = ?, updated_at = ?
            WHERE id = ?
        ");
        $upd->execute([$adjustmentAmount, trim($reason), $netCommission, $now, $statementId]);

        self::logAudit('admin', $adminId, (int)$statement['agent_id'], 'statement_adjusted', json_encode([
            'statement_id' => $statementId,
            'statement_code' => $statement['statement_code'],
            'adjustment_amount' => $adjustmentAmount,
            'reason' => $reason,
            'new_net_commission' => $netCommission
        ]));

        return [
            'success' => true,
            'adjustment_amount' => $adjustmentAmount,
            'net_commission' => $netCommission,
            'message' => 'Adjustment saved successfully.'
        ];
    }

    /**
     * Approve a weekly statement for payout.
     */
    public static function approveStatement(int $statementId, int $adminId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM agent_weekly_statements WHERE id = ?");
        $stmt->execute([$statementId]);
        $statement = $stmt->fetch();

        if (!$statement) {
            return ['success' => false, 'error' => 'Weekly statement not found.'];
        }

        if ($statement['payout_status'] === 'paid') {
            return ['success' => false, 'error' => 'Statement is already Paid.'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $upd = $pdo->prepare("
            UPDATE agent_weekly_statements
            SET payout_status = 'approved', approved_at = ?, approved_by_admin_id = ?, updated_at = ?
            WHERE id = ?
        ");
        $upd->execute([$now, $adminId, $now, $statementId]);

        self::logAudit('admin', $adminId, (int)$statement['agent_id'], 'statement_approved', json_encode([
            'statement_id' => $statementId,
            'statement_code' => $statement['statement_code'],
            'net_commission' => $statement['net_commission']
        ]));

        return ['success' => true, 'message' => "Statement {$statement['statement_code']} approved for payout."];
    }

    /**
     * Record actual payout (UTR / Bank transfer reference, payment date, notes).
     */
    public static function recordPayout(int $statementId, string $payoutReference, string $paidDate, int $adminId, ?string $notes = null): array {
        $pdo = Database::getConnection();

        $payoutRefClean = trim($payoutReference);
        if (empty($payoutRefClean)) {
            return ['success' => false, 'error' => 'Payment Reference (UTR / Transaction ID) is mandatory.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM agent_weekly_statements WHERE id = ?");
        $stmt->execute([$statementId]);
        $statement = $stmt->fetch();

        if (!$statement) {
            return ['success' => false, 'error' => 'Weekly statement not found.'];
        }

        if ($statement['payout_status'] === 'paid') {
            return ['success' => false, 'error' => 'This statement has already been marked as Paid.'];
        }

        $paidTimestamp = !empty($paidDate) ? date('Y-m-d H:i:s', strtotime($paidDate)) : gmdate('Y-m-d H:i:s');
        $now = gmdate('Y-m-d H:i:s');

        $upd = $pdo->prepare("
            UPDATE agent_weekly_statements
            SET payout_status = 'paid', payout_reference = ?, paid_at = ?, paid_by_admin_id = ?, notes = ?, updated_at = ?
            WHERE id = ?
        ");
        $upd->execute([$payoutRefClean, $paidTimestamp, $adminId, $notes ? trim($notes) : null, $now, $statementId]);

        self::logAudit('admin', $adminId, (int)$statement['agent_id'], 'statement_paid', json_encode([
            'statement_id' => $statementId,
            'statement_code' => $statement['statement_code'],
            'net_commission' => $statement['net_commission'],
            'payout_reference' => $payoutRefClean,
            'paid_at' => $paidTimestamp
        ]));

        return [
            'success' => true,
            'statement_code' => $statement['statement_code'],
            'payout_reference' => $payoutRefClean,
            'paid_at' => $paidTimestamp,
            'message' => "Payout recorded successfully for {$statement['statement_code']}."
        ];
    }

    // ====================================================================
    // 7. AUDIT LOGGING & UTILITIES
    // ====================================================================

    /**
     * Record audit entry for all Agent operations.
     */
    public static function logAudit(string $actorType, int $actorId, int $agentId, string $action, ?string $details = null): void {
        try {
            $pdo = Database::getConnection();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $now = gmdate('Y-m-d H:i:s');

            $stmt = $pdo->prepare("
                INSERT INTO agent_audit_logs (actor_type, actor_id, agent_id, action, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$actorType, $actorId, $agentId, $action, $details, $ip, $now]);
        } catch (Exception $e) {
            error_log("Failed to log agent audit: " . $e->getMessage());
        }
    }

    /**
     * Fetch audit logs for an agent or all agents.
     */
    public static function getAuditLogs(?int $agentId = null, int $limit = 50): array {
        $pdo = Database::getConnection();
        $limit = max(1, min(200, $limit));

        if ($agentId) {
            $stmt = $pdo->prepare("
                SELECT l.*, a.agent_code, a.name as agent_name
                FROM agent_audit_logs l
                JOIN agents a ON l.agent_id = a.id
                WHERE l.agent_id = ?
                ORDER BY l.id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $agentId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $pdo->prepare("
                SELECT l.*, a.agent_code, a.name as agent_name
                FROM agent_audit_logs l
                JOIN agents a ON l.agent_id = a.id
                ORDER BY l.id DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Mask user personal name for privacy: e.g. "Rohan Sharma" -> "R***n S****a".
     */
    public static function maskName(string $name): string {
        $parts = preg_split('/\s+/', trim($name));
        $maskedParts = [];
        foreach ($parts as $p) {
            $len = mb_strlen($p);
            if ($len <= 2) {
                $maskedParts[] = $p;
            } elseif ($len <= 4) {
                $maskedParts[] = mb_substr($p, 0, 1) . str_repeat('*', $len - 1);
            } else {
                $maskedParts[] = mb_substr($p, 0, 1) . str_repeat('*', $len - 2) . mb_substr($p, -1, 1);
            }
        }
        return implode(' ', $maskedParts);
    }

    /**
     * Generate next incremental Agent User ID: e.g. AGT1001, AGT1002, etc.
     */
    public static function generateNextAgentCode(): string {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT MAX(id) FROM agents");
        $maxId = (int)($stmt->fetchColumn() ?: 0);
        return sprintf('AGT%04d', 1000 + $maxId + 1);
    }

    /**
     * Generate secure temporary password: e.g. "Agt#9k2P!7xQ"
     */
    public static function generateSecurePassword(): string {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%^&*';

        $pw = $upper[random_int(0, strlen($upper) - 1)] .
              $lower[random_int(0, strlen($lower) - 1)] .
              $digits[random_int(0, strlen($digits) - 1)] .
              $special[random_int(0, strlen($special) - 1)];

        $all = $upper . $lower . $digits . $special;
        for ($i = 0; $i < 8; $i++) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($pw);
    }
}
