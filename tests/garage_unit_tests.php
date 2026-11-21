<?php
declare(strict_types=1);

/**
 * Unit tests for GarageId and GarageBatchValidator (no database needed).
 * Run:  php tests/garage_unit_tests.php
 * Each case: ID, scenario, expected, actual, status.
 */
require_once __DIR__ . '/../src/GarageId.php';
require_once __DIR__ . '/../src/GarageBatchValidator.php';

$results = [];
function check(array &$results, string $id, string $scenario, string $expected, string $actual): void
{
    $results[] = [$id, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}
/** Runs a callable and returns its result, or the exception class name. */
function attempt(callable $fn): string
{
    try { return (string)$fn(); } catch (Throwable $e) { return get_class($e); }
}
/** "valid" or the first field with an error. */
function outcome(array $errors): string { return count($errors) === 0 ? 'valid' : array_key_first($errors); }

// ---- GarageId ----
check($results, 'GU-01', 'Prefix of bicycle',                'S', attempt(fn() => GarageId::prefixFor('bicycle')));
check($results, 'GU-02', 'Prefix of car',                    'C', attempt(fn() => GarageId::prefixFor('car')));
check($results, 'GU-03', 'Prefix of electric car',           'E', attempt(fn() => GarageId::prefixFor('electric car')));
check($results, 'GU-04', 'Unknown type name',                'InvalidArgumentException', attempt(fn() => GarageId::prefixFor('truck')));
check($results, 'GU-05', 'First bicycle garage',             'S001', attempt(fn() => GarageId::format('S', 1)));
check($results, 'GU-06', 'Tenth car garage',                 'C010', attempt(fn() => GarageId::format('C', 10)));
check($results, 'GU-07', 'Last number allowed (999)',        'E999', attempt(fn() => GarageId::format('E', 999)));
check($results, 'GU-08', 'Number 1000 is too big',           'InvalidArgumentException', attempt(fn() => GarageId::format('E', 1000)));
check($results, 'GU-09', 'Number 0 is too small',            'InvalidArgumentException', attempt(fn() => GarageId::format('S', 0)));
check($results, 'GU-10', 'Read number of C010',              '10', attempt(fn() => GarageId::numberOf('C010')));
check($results, 'GU-11', 'Read number of a bad ID',          'InvalidArgumentException', attempt(fn() => GarageId::numberOf('X010')));

// ---- GarageBatchValidator ----
$ok = ['count' => '10', 'daily_price' => '50000', 'kwh_price' => ''];
$v = fn(array $changes, string $type = 'car') => outcome(GarageBatchValidator::validate(array_merge($ok, $changes), $type));

check($results, 'GU-12', 'Valid car batch',                         'valid', $v([]));
check($results, 'GU-13', 'Count 0',                                 'count', $v(['count' => '0']));
check($results, 'GU-14', 'Count 1 (minimum)',                       'valid', $v(['count' => '1']));
check($results, 'GU-15', 'Count 999 (maximum)',                     'valid', $v(['count' => '999']));
check($results, 'GU-16', 'Count 1000 (one too many)',               'count', $v(['count' => '1000']));
check($results, 'GU-17', 'Count is text',                           'count', $v(['count' => 'ten']));
check($results, 'GU-18', 'Daily price 0',                           'daily_price', $v(['daily_price' => '0']));
check($results, 'GU-19', 'Daily price negative',                    'daily_price', $v(['daily_price' => '-5000']));
check($results, 'GU-20', 'Daily price with 2 decimals',             'valid', $v(['daily_price' => '50000.50']));
check($results, 'GU-21', 'Daily price with 3 decimals',             'daily_price', $v(['daily_price' => '50000.505']));
check($results, 'GU-22', 'Daily price is text',                     'daily_price', $v(['daily_price' => 'abc']));
check($results, 'GU-23', 'Electric batch without kWh price',        'kwh_price', $v(['kwh_price' => ''], 'electric car'));
check($results, 'GU-24', 'Electric batch with kWh price',           'valid', $v(['kwh_price' => '2500'], 'electric car'));
check($results, 'GU-25', 'Car batch ignores an empty kWh price',    'valid', $v(['kwh_price' => ''], 'car'));
check($results, 'GU-26', 'Bicycle batch ignores a bad kWh price',   'valid', $v(['kwh_price' => 'abc'], 'bicycle'));

$passed = 0; $failed = 0;
foreach ($results as $r) {
    $passed += $r[4] === 'Pass' ? 1 : 0;
    $failed += $r[4] === 'Fail' ? 1 : 0;
    printf("%-6s %-44s expected=%-26s actual=%-26s %s\n", ...$r);
}
echo "\nTotal: " . count($results) . " | Pass: $passed | Fail: $failed\n";
exit($failed === 0 ? 0 : 1);