<?php
declare(strict_types=1);

/**
 * Unit tests for Vehicle, VehicleValidator and RentalRules (no database needed).
 * Run:  php tests/rental_rules_tests.php
 * Each case: ID, scenario, expected, actual, status.
 */
require_once __DIR__ . '/../src/Vehicle.php';

$results = [];
function check(array &$results, string $id, string $scenario, string $expected, string $actual): void
{
    $results[] = [$id, $scenario, $expected, $actual, $expected === $actual ? 'Pass' : 'Fail'];
}
/** Runs a callable and returns its result as text, or the exception class name. */
function attempt(callable $fn): string
{
    try { return (string)$fn(); } catch (Throwable $e) { return get_class($e); }
}
function outcome(array $errors): string { return count($errors) === 0 ? 'valid' : array_key_first($errors); }
function yn(bool $b): string { return $b ? 'yes' : 'no'; }
function day(string $date): DateTimeImmutable { return new DateTimeImmutable($date); }

// ---------- 1. Which vehicle may use which garage type ----------
$vehicles = [
    'bicycle'      => Vehicle::fromInput(['vehicle_type' => 'bicycle', 'brand' => 'Polygon', 'model' => 'Xtrada']),
    'motorbike'    => Vehicle::fromInput(['vehicle_type' => 'motorbike', 'plate_number' => 'L 1234 AB', 'brand' => 'Honda', 'model' => 'Beat']),
    'car'          => Vehicle::fromInput(['vehicle_type' => 'car', 'plate_number' => 'L 5678 CD', 'brand' => 'Toyota', 'model' => 'Avanza']),
    'electric_car' => Vehicle::fromInput(['vehicle_type' => 'electric_car', 'plate_number' => 'L 9012 EF', 'brand' => 'Hyundai', 'model' => 'Ioniq 5', 'battery_kwh' => '72.6']),
];
$garages = ['bicycle', 'car', 'electric car'];
$expected = [
    'bicycle'      => ['yes', 'no',  'no'],
    'motorbike'    => ['yes', 'no',  'no'],
    'car'          => ['no',  'yes', 'no'],
    'electric_car' => ['no',  'yes', 'yes'],
];
$n = 1;
foreach ($vehicles as $type => $vehicle) {
    foreach ($garages as $i => $garage) {
        check($results, sprintf('RT-%02d', $n++), "$type in $garage garage", $expected[$type][$i], yn($vehicle->canUse($garage)));
    }
}

// ---------- 2. Factory and inheritance ----------
check($results, 'RT-13', 'Factory builds a Bicycle',            'Bicycle',      get_class($vehicles['bicycle']));
check($results, 'RT-14', 'Factory builds an ElectricCar',       'ElectricCar',  get_class($vehicles['electric_car']));
check($results, 'RT-15', 'ElectricCar is a PlatedVehicle',      'yes',          yn($vehicles['electric_car'] instanceof PlatedVehicle));
check($results, 'RT-16', 'Bicycle is NOT a PlatedVehicle',      'no',           yn($vehicles['bicycle'] instanceof PlatedVehicle));
check($results, 'RT-17', 'Plate is cleaned: "l  1234 ab"',      'L 1234 AB',    Vehicle::fromInput(['vehicle_type' => 'car', 'plate_number' => 'l  1234 ab', 'brand' => 'a', 'model' => 'b'])->plateNumber());
check($results, 'RT-18', 'Bicycle ignores a typed plate',       'none',         Vehicle::fromInput(['vehicle_type' => 'bicycle', 'plate_number' => 'B 1', 'brand' => 'a', 'model' => 'b'])->plateNumber() ?? 'none');
check($results, 'RT-19', 'Unknown type in the factory',         'InvalidArgumentException', attempt(fn() => Vehicle::fromInput(['vehicle_type' => 'truck'])));

// ---------- 3. Vehicle form validation ----------
$ok = ['vehicle_type' => 'car', 'plate_number' => 'L 1234 AB', 'brand' => 'Toyota', 'model' => 'Avanza', 'battery_kwh' => ''];
$vv = fn(array $c) => outcome(VehicleValidator::validate(array_merge($ok, $c)));
check($results, 'RT-20', 'Valid car',                               'valid',        $vv([]));
check($results, 'RT-21', 'No vehicle type chosen',                  'vehicle_type', $vv(['vehicle_type' => '']));
check($results, 'RT-22', 'Car without plate',                       'plate_number', $vv(['plate_number' => '']));
check($results, 'RT-23', 'Motorbike with symbols in the plate',     'plate_number', $vv(['vehicle_type' => 'motorbike', 'plate_number' => 'L-12*']));
check($results, 'RT-24', 'Bicycle without plate is fine',           'valid',        $vv(['vehicle_type' => 'bicycle', 'plate_number' => '']));
check($results, 'RT-25', 'Brand empty',                             'brand',        $vv(['brand' => '  ']));
check($results, 'RT-26', 'Model 51 characters',                     'model',        $vv(['model' => str_repeat('m', 51)]));
check($results, 'RT-27', 'Electric car without battery',            'battery_kwh',  $vv(['vehicle_type' => 'electric_car', 'battery_kwh' => '']));
check($results, 'RT-28', 'Electric car battery 0',                  'battery_kwh',  $vv(['vehicle_type' => 'electric_car', 'battery_kwh' => '0']));
check($results, 'RT-29', 'Electric car battery 72.6',               'valid',        $vv(['vehicle_type' => 'electric_car', 'battery_kwh' => '72.6']));
check($results, 'RT-30', 'Normal car ignores a battery value',      'valid',        $vv(['vehicle_type' => 'car', 'battery_kwh' => 'abc']));

// ---------- 4. Day limit (days in the month minus 1) ----------
check($results, 'RT-31', 'Max days, start 21 Nov 2026 (30-day month)',  '29', (string)RentalRules::maxDailyDays(day('2026-11-21')));
check($results, 'RT-32', 'Max days, start 10 Feb 2027 (28-day month)',  '27', (string)RentalRules::maxDailyDays(day('2027-02-10')));
check($results, 'RT-33', 'Max days, start 10 Feb 2028 (leap year)',     '28', (string)RentalRules::maxDailyDays(day('2028-02-10')));
check($results, 'RT-34', 'Max days, start 5 Jan 2027 (31-day month)',   '30', (string)RentalRules::maxDailyDays(day('2027-01-05')));

$rv = fn(array $d, string $date = '2026-11-21') => outcome(RentalRules::validate($d, day($date)));
check($results, 'RT-35', 'Daily, 29 days in November (maximum)',        'valid', $rv(['rental_type' => 'daily', 'days' => '29']));
check($results, 'RT-36', 'Daily, 30 days in November (one too many)',   'days',  $rv(['rental_type' => 'daily', 'days' => '30']));
check($results, 'RT-37', 'Daily, 0 days',                               'days',  $rv(['rental_type' => 'daily', 'days' => '0']));
check($results, 'RT-38', 'Daily, 1 day (minimum)',                      'valid', $rv(['rental_type' => 'daily', 'days' => '1']));
check($results, 'RT-39', 'Daily without number of days',                'days',  $rv(['rental_type' => 'daily', 'days' => '']));
check($results, 'RT-40', 'Daily, 27 days in February 2027',             'valid', $rv(['rental_type' => 'daily', 'days' => '27'], '2027-02-10'));
check($results, 'RT-41', 'Daily, 28 days in February 2027',             'days',  $rv(['rental_type' => 'daily', 'days' => '28'], '2027-02-10'));
check($results, 'RT-42', 'Monthly ignores the days field',              'valid', $rv(['rental_type' => 'monthly', 'days' => '99']));
check($results, 'RT-43', 'Unknown rental type',                         'rental_type', $rv(['rental_type' => 'weekly']));

// ---------- 5. End date ----------
$end = fn(string $start, string $type, ?int $days) => RentalRules::endDate(day($start), $type, $days)->format('Y-m-d');
check($results, 'RT-44', 'Daily 5 days from 21 Nov 2026',        '2026-11-26', $end('2026-11-21', 'daily', 5));
check($results, 'RT-45', 'Daily 29 days from 21 Nov 2026',       '2026-12-20', $end('2026-11-21', 'daily', 29));
check($results, 'RT-46', 'Monthly from 21 Nov 2026',             '2026-12-21', $end('2026-11-21', 'monthly', null));
check($results, 'RT-47', 'Monthly from 31 Jan 2027 (31 days)',    '2027-03-03', $end('2027-01-31', 'monthly', null));
check($results, 'RT-48', 'Monthly from 31 Jan 2028 (leap year)',  '2028-03-02', $end('2028-01-31', 'monthly', null));
check($results, 'RT-49', 'Monthly from 31 Dec 2026 (new year)',  '2027-01-31', $end('2026-12-31', 'monthly', null));
check($results, 'RT-50', 'Daily without days',                   'InvalidArgumentException', attempt(fn() => $end('2026-11-21', 'daily', null)));
check($results, 'RT-63', 'Monthly from 10 Feb 2027 (28 days)',   '2027-03-10', $end('2027-02-10', 'monthly', null));
check($results, 'RT-64', 'Monthly from 15 Apr 2027 (30 days)',   '2027-05-15', $end('2027-04-15', 'monthly', null));
check($results, 'RT-65', 'Longest daily rental is shorter than monthly (Nov 2026)', 'yes', yn(RentalRules::endDate(day('2026-11-21'), 'daily', 29) < RentalRules::endDate(day('2026-11-21'), 'monthly', null)));

// ---------- 6. Prices ----------
$car = $vehicles['car'];
$ev = $vehicles['electric_car'];   // battery 72.6 kWh
$tot = fn(Vehicle $v, string $garage, string $daily, ?string $kwh, string $type, ?int $days) => RentalRules::totals($v, $garage, $daily, $kwh, $type, $days);
$fmt = fn(array $t) => $t['rental'] . '|' . $t['charging'] . '|' . $t['total'];

check($results, 'RT-51', 'Daily: 3 days x 50000',                    '150000.00|0.00|150000.00',   $fmt($tot($car, 'car', '50000', null, 'daily', 3)));
check($results, 'RT-52', 'Monthly: 25 x 50000',                      '1250000.00|0.00|1250000.00', $fmt($tot($car, 'car', '50000', null, 'monthly', null)));
check($results, 'RT-53', 'Daily with decimals: 2 x 50000.50',        '100001.00|0.00|100001.00',   $fmt($tot($car, 'car', '50000.50', null, 'daily', 2)));
check($results, 'RT-54', 'Electric car in electric garage: 72.6 kWh x 2500.50 = 181536.30', '300000.00|181536.30|481536.30', $fmt($tot($ev, 'electric car', '100000', '2500.50', 'daily', 3)));
check($results, 'RT-55', 'Electric car in a car garage: no charging fee', '300000.00|0.00|300000.00', $fmt($tot($ev, 'car', '100000', null, 'daily', 3)));
check($results, 'RT-56', 'Car in car garage: no charging fee',       '0.00', $tot($car, 'car', '50000', null, 'daily', 1)['charging']);
check($results, 'RT-57', 'Electric garage without kWh price',        'InvalidArgumentException', attempt(fn() => $tot($ev, 'electric car', '100000', null, 'daily', 1)));
check($results, 'RT-58', 'Monthly electric: 25 x 100000 + charging', '2500000.00|181536.30|2681536.30', $fmt($tot($ev, 'electric car', '100000', '2500.50', 'monthly', null)));
check($results, 'RT-59', 'Money text to cents: "50000.50"',          '5000050', (string)RentalRules::toCents('50000.50'));
check($results, 'RT-60', 'Money text to cents: "0.5"',               '50',      (string)RentalRules::toCents('0.5'));
check($results, 'RT-61', 'Cents to text: 5',                         '0.05',    RentalRules::fromCents(5));
check($results, 'RT-62', 'Bad amount text',                          'InvalidArgumentException', attempt(fn() => RentalRules::toCents('abc')));

$passed = 0; $failed = 0;
foreach ($results as $r) {
    $passed += $r[4] === 'Pass' ? 1 : 0;
    $failed += $r[4] === 'Fail' ? 1 : 0;
    printf("%-6s %-66s expected=%-30s actual=%-30s %s\n", ...$r);
}
echo "\nTotal: " . count($results) . " | Pass: $passed | Fail: $failed\n";
exit($failed === 0 ? 0 : 1);