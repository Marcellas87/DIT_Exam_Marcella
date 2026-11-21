<?php
declare(strict_types=1);

/**
 * Integration tests for AuthService.php (uses the real database).
 * Run in a terminal:  php tests/auth_flow_tests.php
 *
 * It creates temporary users and deletes them at the end,
 * so your real data is not touched.
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$auth = new AuthService($pdo);

$tag = substr(md5((string)microtime(true)), 0, 8);
$email = "test_$tag@example.com";
$nik = '9' . str_pad((string)random_int(0, 999999999999999), 15, '0', STR_PAD_LEFT);
$form = [
    'nik' => $nik, 'full_name' => 'Test Tenant', 'phone' => '081234567890',
    'email' => $email, 'password' => 'secret123', 'password_confirm' => 'secret123',
];
$adminEmail = "admin_$tag@example.com";

$results = [];
function check(array &$results, string $id, string $scenario, string $expected, string $actual): void
{
    $results[] = [$id, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}
function errorKeys(array $errors): string { return count($errors) === 0 ? 'no errors' : implode(',', array_keys($errors)); }

try {
    check($results, 'AT-01', 'Register a valid tenant', 'no errors', errorKeys($auth->register($form)));

    $row = $pdo->prepare('SELECT t.registration_status, u.role, u.password_hash FROM users u JOIN tenants t ON t.user_id = u.id WHERE u.email = ?');
    $row->execute([$email]);
    $saved = $row->fetch();
    check($results, 'AT-02', 'New tenant status is pending, role is tenant', 'pending/tenant', ($saved['registration_status'] ?? '?') . '/' . ($saved['role'] ?? '?'));
    check($results, 'AT-03', 'Password is stored as a hash, not plain text', 'hashed', ($saved && $saved['password_hash'] !== 'secret123' && password_verify('secret123', $saved['password_hash'])) ? 'hashed' : 'plain');

    check($results, 'AT-04', 'Same email registered again', 'email', errorKeys($auth->register(array_merge($form, ['nik' => '8' . substr($nik, 1)]))));
    check($results, 'AT-05', 'Same NIK with another email', 'nik', errorKeys($auth->register(array_merge($form, ['email' => "other_$tag@example.com"]))));
    check($results, 'AT-06', 'Invalid form is rejected before the database', 'nik', errorKeys($auth->register(array_merge($form, ['nik' => '123']))));

    check($results, 'AT-07', 'Login while pending', 'pending', $auth->login($email, 'secret123')['status']);

    $pdo->prepare("UPDATE tenants t JOIN users u ON u.id = t.user_id SET t.registration_status = 'accepted' WHERE u.email = ?")->execute([$email]);
    check($results, 'AT-08', 'Login after admin accepts', 'ok', $auth->login($email, 'secret123')['status']);
    check($results, 'AT-09', 'Login with email in different letter case', 'ok', $auth->login(strtoupper($email), 'secret123')['status']);
    check($results, 'AT-10', 'Wrong password', 'invalid', $auth->login($email, 'wrongpass1')['status']);
    check($results, 'AT-11', 'Unknown email (same answer as wrong password)', 'invalid', $auth->login("nobody_$tag@example.com", 'secret123')['status']);

    $pdo->prepare("UPDATE tenants t JOIN users u ON u.id = t.user_id SET t.registration_status = 'rejected' WHERE u.email = ?")->execute([$email]);
    check($results, 'AT-12', 'Login after admin rejects', 'rejected', $auth->login($email, 'secret123')['status']);

    $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'admin')")->execute([$adminEmail, password_hash('adminpass1', PASSWORD_DEFAULT)]);
    $adminLogin = $auth->login($adminEmail, 'adminpass1');
    check($results, 'AT-13', 'Admin can log in without a tenant row', 'ok/admin', $adminLogin['status'] . '/' . ($adminLogin['user']['role'] ?? '?'));
    
} finally {
    // Clean up the temporary rows even if a test crashed.
    $pdo->prepare('DELETE FROM tenants WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))')->execute([$email, "other_$tag@example.com"]);
    $pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([$email, "other_$tag@example.com", $adminEmail]);
}

$passed = 0;
$failed = 0;
foreach ($results as $r) {
    $passed += $r[4] === 'Pass' ? 1 : 0;
    $failed += $r[4] === 'Fail' ? 1 : 0;
    printf("%-6s %-52s expected=%-14s actual=%-14s %s\n", ...$r);
}
$skipped = count($results) - $passed - $failed;
echo "\nTotal: " . count($results) . " | Pass: $passed | Fail: $failed | Skipped: $skipped\n";
exit($failed === 0 ? 0 : 1);
