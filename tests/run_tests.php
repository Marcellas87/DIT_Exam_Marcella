<?php
declare(strict_types=1);

/**
 * Unit tests for RegistrationValidator.php (no database needed).
 * Run:  php tests/run_tests.php      or open it in the browser.
 * Each case: ID, type, scenario, expected, actual, status.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/RegistrationValidator.php';
require_once __DIR__ . '/test_helpers.php';

$validForm = [
    'nik' => '3578012345678901', 'full_name' => 'Marcella', 'phone' => '081234567890',
    'email' => 'marcella@example.com', 'password' => 'secret123', 'password_confirm' => 'secret123',
];

/**
 * Returns a copy of the valid form with some fields changed.
 *
 * @param array<string, string> $baseForm the valid form
 * @param array<string, string> $changes the fields to replace
 * @return array<string, string> the changed form
 */
function changeFields(array $baseForm, array $changes): array
{
    return array_merge($baseForm, $changes);
}

// [ID, type, scenario, input, expected: "valid" or the field that must fail]
$cases = [
    ['TC-01', 'Positive', 'All fields valid',                   $validForm, 'valid'],
    ['TC-02', 'Boundary', 'NIK 15 digits (one too few)',        changeFields($validForm, ['nik' => '357801234567890']), 'nik'],
    ['TC-03', 'Boundary', 'NIK 16 digits (exact)',              $validForm, 'valid'],
    ['TC-04', 'Boundary', 'NIK 17 digits (one too many)',       changeFields($validForm, ['nik' => '35780123456789012']), 'nik'],
    ['TC-05', 'Negative', 'NIK contains letters',               changeFields($validForm, ['nik' => '35780123456789AB']), 'nik'],
    ['TC-06', 'Negative', 'Full name empty',                    changeFields($validForm, ['full_name' => '  ']), 'full_name'],
    ['TC-07', 'Negative', 'Email without @',                    changeFields($validForm, ['email' => 'marcella.example.com']), 'email'],
    ['TC-08', 'Boundary', 'Password 7 characters',              changeFields($validForm, ['password' => 'abcdefg', 'password_confirm' => 'abcdefg']), 'password'],
    ['TC-09', 'Boundary', 'Password 8 characters',              changeFields($validForm, ['password' => 'abcdefgh', 'password_confirm' => 'abcdefgh']), 'valid'],
    ['TC-10', 'Boundary', 'Password 72 characters',             changeFields($validForm, ['password' => str_repeat('a', 72), 'password_confirm' => str_repeat('a', 72)]), 'valid'],
    ['TC-11', 'Boundary', 'Password 73 characters',             changeFields($validForm, ['password' => str_repeat('a', 73), 'password_confirm' => str_repeat('a', 73)]), 'password'],
    ['TC-12', 'Negative', 'Password confirmation different',    changeFields($validForm, ['password_confirm' => 'different1']), 'password_confirm'],
    ['TC-13', 'Boundary', 'Phone 7 digits',                     changeFields($validForm, ['phone' => '0812345']), 'phone'],
    ['TC-14', 'Boundary', 'Phone 8 digits',                     changeFields($validForm, ['phone' => '08123456']), 'valid'],
    ['TC-15', 'Positive', 'Phone with leading +',               changeFields($validForm, ['phone' => '+6281234567890']), 'valid'],
    ['TC-16', 'Negative', 'Phone contains letters',             changeFields($validForm, ['phone' => '0812abc890']), 'phone'],
];

$results = [];
$passedCount = 0;
foreach ($cases as [$testId, $testType, $scenario, $input, $expected]) {
    $actual = getFirstErrorField(RegistrationValidator::validate($input));
    $status = $actual === $expected ? 'Pass' : 'Fail';
    $passedCount += $status === 'Pass' ? 1 : 0;
    $results[] = [$testId, $testType, $scenario, $expected, $actual, $status];
}
$totalCount = count($results);
$passRate = round($passedCount / $totalCount * 100, 1);

if (PHP_SAPI === 'cli') {
    foreach ($results as $resultRow) {
        printf("%-6s %-9s %-36s expected=%-17s actual=%-17s %s\n", ...$resultRow);
    }
    echo "\nTotal: $totalCount | Pass: $passedCount | Fail: " . ($totalCount - $passedCount) . " | Pass rate: $passRate%\n";
    exit($passedCount === $totalCount ? 0 : 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>RegistrationValidator tests</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<main>
    <h1>RegistrationValidator test results</h1>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>ID</th><th>Type</th><th>Scenario</th><th>Expected</th><th>Actual</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($results as $resultRow): ?>
                <tr>
                    <?php foreach ($resultRow as $cellText): ?>
                        <td><?= htmlspecialchars($cellText) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p>Total: <?= $totalCount ?> | Pass: <?= $passedCount ?> | Fail: <?= $totalCount - $passedCount ?> | Pass rate: <?= $passRate ?>%</p>
</main>
</body>
</html>