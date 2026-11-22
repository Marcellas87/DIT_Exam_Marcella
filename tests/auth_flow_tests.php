<?php
declare(strict_types=1);

/**
 * Integration tests for AuthService.php (uses the real database).
 * Run in a terminal:  php tests/auth_flow_tests.php
 *
 * It creates temporary users and deletes them at the end,
 * so your real data is not touched.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$auth = new AuthService($pdo);

$uniqueTag = createUniqueTag();
$email = "test_$uniqueTag@example.com";
$otherEmail = "other_$uniqueTag@example.com";
$adminEmail = "admin_$uniqueTag@example.com";
$nik = createRandomNik('9');
$form = [
    'nik' => $nik, 'full_name' => 'Test Tenant', 'phone' => '081234567890',
    'email' => $email, 'password' => 'secret123', 'password_confirm' => 'secret123',
];

$results = [];
try {
    addResult($results, 'AT-01', 'Register a valid tenant', 'no errors', listErrorFields($auth->register($form)));

    $query = $pdo->prepare('SELECT t.registration_status, u.role, u.password_hash FROM users u JOIN tenants t ON t.user_id = u.id WHERE u.email = ?');
    $query->execute([$email]);
    $savedRow = $query->fetch();
    addResult($results, 'AT-02', 'New tenant status is pending, role is tenant', 'pending/tenant', ($savedRow['registration_status'] ?? '?') . '/' . ($savedRow['role'] ?? '?'));
    addResult($results, 'AT-03', 'Password is stored as a hash, not plain text', 'hashed',
        ($savedRow && $savedRow['password_hash'] !== 'secret123' && password_verify('secret123', $savedRow['password_hash'])) ? 'hashed' : 'plain');

    addResult($results, 'AT-04', 'Same email registered again', 'email', listErrorFields($auth->register(array_merge($form, ['nik' => '8' . substr($nik, 1)]))));
    addResult($results, 'AT-05', 'Same NIK with another email', 'nik', listErrorFields($auth->register(array_merge($form, ['email' => $otherEmail]))));
    addResult($results, 'AT-06', 'Invalid form is rejected before the database', 'nik', listErrorFields($auth->register(array_merge($form, ['nik' => '123']))));

    addResult($results, 'AT-07', 'Login while pending', 'pending', $auth->login($email, 'secret123')['status']);

    $pdo->prepare("UPDATE tenants t JOIN users u ON u.id = t.user_id SET t.registration_status = 'accepted' WHERE u.email = ?")->execute([$email]);
    addResult($results, 'AT-08', 'Login after admin accepts', 'ok', $auth->login($email, 'secret123')['status']);
    addResult($results, 'AT-09', 'Login with email in different letter case', 'ok', $auth->login(strtoupper($email), 'secret123')['status']);
    addResult($results, 'AT-10', 'Wrong password', 'invalid', $auth->login($email, 'wrongpass1')['status']);
    addResult($results, 'AT-11', 'Unknown email (same answer as wrong password)', 'invalid', $auth->login("nobody_$uniqueTag@example.com", 'secret123')['status']);

    $pdo->prepare("UPDATE tenants t JOIN users u ON u.id = t.user_id SET t.registration_status = 'rejected' WHERE u.email = ?")->execute([$email]);
    addResult($results, 'AT-12', 'Login after admin rejects', 'rejected', $auth->login($email, 'secret123')['status']);

    $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (?, ?, 'admin')")->execute([$adminEmail, password_hash('adminpass1', PASSWORD_DEFAULT)]);
    $adminLogin = $auth->login($adminEmail, 'adminpass1');
    addResult($results, 'AT-13', 'Admin can log in without a tenant row', 'ok/admin', $adminLogin['status'] . '/' . ($adminLogin['user']['role'] ?? '?'));
} finally {
    // Clean up the temporary rows even if a test crashed.
    $pdo->prepare('DELETE FROM tenants WHERE user_id IN (SELECT id FROM users WHERE email IN (?, ?))')->execute([$email, $otherEmail]);
    $pdo->prepare('DELETE FROM users WHERE email IN (?, ?, ?)')->execute([$email, $otherEmail, $adminEmail]);
}

printResultsAndExit($results, 52, 14);