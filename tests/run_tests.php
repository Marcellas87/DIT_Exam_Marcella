<?php
declare(strict_types=1);

/**
 * Unit tests for RegistrationValidator.php (no database needed).
 * Run:  php tests/run_tests.php      or open it in the browser.
 * Each case: ID, type, scenario, expected, actual, status.
 */
require_once __DIR__ . '/../src/RegistrationValidator.php';

$valid = [
    'nik' => '3578012345678901', 'full_name' => 'Marcella', 'phone' => '081234567890',
    'email' => 'marcella@example.com', 'password' => 'secret123', 'password_confirm' => 'secret123',
];
function with(array $base, array $changes): array { return array_merge($base, $changes); }

// [ID, type, scenario, input, expected: "valid" or the field that must fail]
$cases = [
    ['TC-01', 'Positive', 'All fields valid',                   $valid, 'valid'],
    ['TC-02', 'Boundary', 'NIK 15 digits (one too few)',        with($valid, ['nik' => '357801234567890']), 'nik'],
    ['TC-03', 'Boundary', 'NIK 16 digits (exact)',              $valid, 'valid'],
    ['TC-04', 'Boundary', 'NIK 17 digits (one too many)',       with($valid, ['nik' => '35780123456789012']), 'nik'],
    ['TC-05', 'Negative', 'NIK contains letters',               with($valid, ['nik' => '35780123456789AB']), 'nik'],
    ['TC-06', 'Negative', 'Full name empty',                    with($valid, ['full_name' => '  ']), 'full_name'],
    ['TC-07', 'Negative', 'Email without @',                    with($valid, ['email' => 'marcella.example.com']), 'email'],
    ['TC-08', 'Boundary', 'Password 7 characters',              with($valid, ['password' => 'abcdefg', 'password_confirm' => 'abcdefg']), 'password'],
    ['TC-09', 'Boundary', 'Password 8 characters',              with($valid, ['password' => 'abcdefgh', 'password_confirm' => 'abcdefgh']), 'valid'],
    ['TC-10', 'Boundary', 'Password 72 characters',             with($valid, ['password' => str_repeat('a', 72), 'password_confirm' => str_repeat('a', 72)]), 'valid'],
    ['TC-11', 'Boundary', 'Password 73 characters',             with($valid, ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)]), 'password'],
    ['TC-12', 'Negative', 'Password confirmation different',    with($valid, ['password_confirm' => 'different1']), 'password_confirm'],
    ['TC-13', 'Boundary', 'Phone 7 digits',                     with($valid, ['phone' => '0812345']), 'phone'],
    ['TC-14', 'Boundary', 'Phone 8 digits',                     with($valid, ['phone' => '08123456']), 'valid'],
    ['TC-15', 'Positive', 'Phone with leading +',               with($valid, ['phone' => '+6281234567890']), 'valid'],
    ['TC-16', 'Negative', 'Phone contains letters',             with($valid, ['phone' => '0812abc890']), 'phone'],
];

$results = [];
$passed = 0;
foreach ($cases as [$id, $type, $scenario, $input, $expected]) {
    $errors = RegistrationValidator::validate($input);
    $actual = count($errors) === 0 ? 'valid' : array_key_first($errors);
    $status = $actual === $expected ? 'Pass' : 'Fail';
    $passed += $status === 'Pass' ? 1 : 0;
    $results[] = [$id, $type, $scenario, $expected, $actual, $status];
}
$total = count($results);
$rate = round($passed / $total * 100, 1);

if (PHP_SAPI === 'cli') {
    foreach ($results as $r) { printf("%-6s %-9s %-36s expected=%-17s actual=%-17s %s\n", ...$r); }
    echo "\nTotal: $total | Pass: $passed | Fail: " . ($total - $passed) . " | Pass rate: $rate%\n";
    exit($passed === $total ? 0 : 1);
}
?>

<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>RegistrationValidator tests</title>
    <link rel="stylesheet" href="../assets/style.css"></head>
<body>
    <main>
        <h1>RegistrationValidator test results</h1>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Scenario</th>
                    <th>Expected</th>
                    <th>Actual</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <?php foreach ($r as $c): ?>
                            <td><?= htmlspecialchars($c) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

        <p>Total: <?= $total ?> | Pass: <?= $passed ?> | Fail: <?= $total - $passed ?> | Pass rate: <?= $rate ?>%</p>

    </main>
</body>
</html>
