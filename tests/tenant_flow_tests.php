<?php
declare(strict_types=1);

/**
 * Integration tests for TenantService (admin accepts / rejects registrations).
 * Run in a terminal:  php tests/tenant_flow_tests.php
 * Temporary users are deleted at the end.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/TenantService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$auth = new AuthService($pdo);
$tenantService = new TenantService($pdo);

$uniqueTag = createUniqueTag();
$acceptedEmail = "va_$uniqueTag@example.com";
$rejectedEmail = "vb_$uniqueTag@example.com";

/**
 * Reads the registration status of a tenant ("none" if there is no tenant row).
 *
 * @param PDO $pdo an open database connection
 * @param int $userId the tenant's user id
 * @return string pending, accepted, rejected or none
 */
function findRegistrationStatus(PDO $pdo, int $userId): string
{
    $status = fetchScalar($pdo, 'SELECT registration_status FROM tenants WHERE user_id = ?', [$userId]);
    return $status === 'NULL' ? 'none' : $status;
}

/**
 * Lists the user ids that are waiting for a decision.
 *
 * @param TenantService $tenantService the service under test
 * @return array<int, int> the pending user ids
 */
function findPendingIds(TenantService $tenantService): array
{
    return array_map('intval', array_column($tenantService->findPending(), 'user_id'));
}

/**
 * Makes a decision and describes the result as "true/<new status>" or "false/<status>".
 *
 * @param TenantService $tenantService the service under test
 * @param PDO $pdo an open database connection
 * @param int $userId the tenant's user id
 * @param string $decision "accepted" or "rejected"
 * @return string for example "true/accepted"
 */
function decideAndDescribe(TenantService $tenantService, PDO $pdo, int $userId, string $decision): string
{
    $changed = $tenantService->decideRegistration($userId, $decision);
    return ($changed ? 'true' : 'false') . '/' . findRegistrationStatus($pdo, $userId);
}

$results = [];
$userIds = [];
try {
    $acceptedTenantId = createTestTenant($auth, $pdo, $acceptedEmail, '7', 'Temp ' . $acceptedEmail);
    $rejectedTenantId = createTestTenant($auth, $pdo, $rejectedEmail, '6', 'Temp ' . $rejectedEmail);
    $userIds = [$acceptedTenantId, $rejectedTenantId];

    addResult($results, 'TV-01', 'New tenant appears in the pending list', 'yes', formatYesNo(in_array($acceptedTenantId, findPendingIds($tenantService), true)));
    addResult($results, 'TV-02', 'Accept a pending tenant', 'true/accepted', decideAndDescribe($tenantService, $pdo, $acceptedTenantId, 'accepted'));
    addResult($results, 'TV-03', 'Accepted tenant leaves the pending list', 'no', formatYesNo(in_array($acceptedTenantId, findPendingIds($tenantService), true)));
    addResult($results, 'TV-04', 'Accepted tenant can now log in', 'ok', $auth->login($acceptedEmail, 'secret123')['status']);

    addResult($results, 'TV-05', 'Reject a pending tenant', 'true/rejected', decideAndDescribe($tenantService, $pdo, $rejectedTenantId, 'rejected'));
    addResult($results, 'TV-06', 'Rejected tenant cannot log in', 'rejected', $auth->login($rejectedEmail, 'secret123')['status']);

    addResult($results, 'TV-07', 'Deciding twice is refused (status stays accepted)', 'false/accepted', decideAndDescribe($tenantService, $pdo, $acceptedTenantId, 'rejected'));
    addResult($results, 'TV-08', 'Unknown user id changes nothing', 'false', $tenantService->decideRegistration(999999999, 'accepted') ? 'true' : 'false');

    $adminId = (int)fetchScalar($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    addResult($results, 'TV-09', 'Admin user id (no tenant row) changes nothing', 'false', $tenantService->decideRegistration($adminId, 'accepted') ? 'true' : 'false');

    addResult($results, 'TV-10', 'Invalid decision word is refused', 'InvalidArgumentException',
        getResultOrException(fn() => $tenantService->decideRegistration($acceptedTenantId, 'approved') ? 'true' : 'false'));
} finally {
    deleteTestData($pdo, $userIds, []);
}

printResultsAndExit($results, 52, 26);