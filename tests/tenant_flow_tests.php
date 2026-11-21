<?php
declare(strict_types=1);

/**
 * Integration tests for TenantService (admin accepts / rejects registrations).
 * Run in a terminal:  php tests/tenant_flow_tests.php
 * Temporary users are deleted at the end.
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/TenantService.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$auth = new AuthService($pdo);
$tenants = new TenantService($pdo);

$tag = substr(md5((string)microtime(true)), 0, 8);
$emails = ["va_$tag@example.com", "vb_$tag@example.com"];

/** Registers a temporary tenant and returns its user id. */
function makeTenant(AuthService $auth, PDO $pdo, string $email, string $nik): int
{
    $errors = $auth->register([
        'nik' => $nik, 'full_name' => 'Temp ' . $email, 'phone' => '081234567890',
        'email' => $email, 'password' => 'secret123', 'password_confirm' => 'secret123',
    ]);
    if (count($errors) > 0) { throw new RuntimeException('Setup failed: ' . implode(',', array_keys($errors))); }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn();
}
function statusOf(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT registration_status FROM tenants WHERE user_id = ?');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: 'none');
}
function pendingIds(TenantService $s): array { return array_map('intval', array_column($s->findPending(), 'user_id')); }

$results = [];
function check(array &$results, string $id, string $scenario, string $expected, string $actual): void
{
    $results[] = [$id, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}
function yn(bool $b): string { return $b ? 'yes' : 'no'; }

$ids = [];
try {
    $a = makeTenant($auth, $pdo, $emails[0], '7' . str_pad((string)random_int(0, 999999999999999), 15, '0', STR_PAD_LEFT));
    $b = makeTenant($auth, $pdo, $emails[1], '6' . str_pad((string)random_int(0, 999999999999999), 15, '0', STR_PAD_LEFT));
    $ids = [$a, $b];

    check($results, 'TV-01', 'New tenant appears in the pending list', 'yes', yn(in_array($a, pendingIds($tenants), true)));
    check($results, 'TV-02', 'Accept a pending tenant', 'true/accepted', yn($tenants->decide($a, 'accepted')) === 'yes' ? 'true/' . statusOf($pdo, $a) : 'false');
    check($results, 'TV-03', 'Accepted tenant leaves the pending list', 'no', yn(in_array($a, pendingIds($tenants), true)));
    check($results, 'TV-04', 'Accepted tenant can now log in', 'ok', $auth->login($emails[0], 'secret123')['status']);

    check($results, 'TV-05', 'Reject a pending tenant', 'true/rejected', yn($tenants->decide($b, 'rejected')) === 'yes' ? 'true/' . statusOf($pdo, $b) : 'false');
    check($results, 'TV-06', 'Rejected tenant cannot log in', 'rejected', $auth->login($emails[1], 'secret123')['status']);

    check($results, 'TV-07', 'Deciding twice is refused (status stays accepted)', 'false/accepted', yn($tenants->decide($a, 'rejected')) === 'yes' ? 'true' : 'false/' . statusOf($pdo, $a));
    check($results, 'TV-08', 'Unknown user id changes nothing', 'false', yn($tenants->decide(999999999, 'accepted')) === 'yes' ? 'true' : 'false');

    $adminId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
    check($results, 'TV-09', 'Admin user id (no tenant row) changes nothing', 'false', yn($tenants->decide($adminId, 'accepted')) === 'yes' ? 'true' : 'false');

    try {
        $tenants->decide($a, 'approved');
        $actual = 'no exception';
    } catch (InvalidArgumentException $e) {
        $actual = 'InvalidArgumentException';
    }
    check($results, 'TV-10', 'Invalid decision word is refused', 'InvalidArgumentException', $actual);
} finally {
    foreach ($ids as $id) { $pdo->prepare('DELETE FROM tenants WHERE user_id = ?')->execute([$id]); }
    $pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute($emails);
}

$passed = 0; $failed = 0;
foreach ($results as $r) {
    $passed += $r[4] === 'Pass' ? 1 : 0;
    $failed += $r[4] === 'Fail' ? 1 : 0;
    printf("%-6s %-52s expected=%-26s actual=%-26s %s\n", ...$r);
}
echo "\nTotal: " . count($results) . " | Pass: $passed | Fail: $failed\n";
exit($failed === 0 ? 0 : 1);