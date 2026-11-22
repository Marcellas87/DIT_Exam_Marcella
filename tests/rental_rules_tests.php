<?php
declare(strict_types=1);

/**
 * Unit tests for Vehicle, VehicleValidator and RentalRules (no database needed).
 * Run in a terminal:  php tests/rental_rules_tests.php
 * Each case: ID, scenario, expected, actual, status.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Vehicle.php';
require_once __DIR__ . '/test_helpers.php';

/**
 * Builds a date from text, to keep the test lines short.
 *
 * @param string $dateText a date like "2026-11-21"
 * @return DateTimeImmutable the date
 */
function createDate(string $dateText): DateTimeImmutable
{
    return new DateTimeImmutable($dateText);
}

/**
 * Checks a new end date and returns "valid" or "refused".
 *
 * @param string $newEnd the date typed by the admin
 * @param DateTimeImmutable $oldEnd the current end date
 * @return string "valid" or "refused"
 */
function checkNewEnd(string $newEnd, DateTimeImmutable $oldEnd): string
{
    return RentalRules::validateNewEnd($newEnd, $oldEnd) === null ? 'valid' : 'refused';
}

$results = [];

// ---------- 1. Which vehicle may use which garage type ----------
$vehicles = [
    'bicycle'      => Vehicle::createFromInput(['vehicle_type' => 'bicycle', 'brand' => 'Polygon', 'model' => 'Xtrada']),
    'motorbike'    => Vehicle::createFromInput(['vehicle_type' => 'motorbike', 'plate_number' => 'L 1234 AB', 'brand' => 'Honda', 'model' => 'Beat']),
    'car'          => Vehicle::createFromInput(['vehicle_type' => 'car', 'plate_number' => 'L 5678 CD', 'brand' => 'Toyota', 'model' => 'Avanza']),
    'electric_car' => Vehicle::createFromInput(['vehicle_type' => 'electric_car', 'plate_number' => 'L 9012 EF', 'brand' => 'Hyundai', 'model' => 'Ioniq 5', 'battery_kwh' => '72.6']),
];
$garageTypes = ['bicycle', 'car', 'electric car'];
$expectedAnswers = [
    'bicycle'      => ['yes', 'no',  'no'],
    'motorbike'    => ['yes', 'no',  'no'],
    'car'          => ['no',  'yes', 'no'],
    'electric_car' => ['no',  'yes', 'yes'],
];
$testNumber = 1;
foreach ($vehicles as $vehicleType => $vehicle) {
    foreach ($garageTypes as $garageIndex => $garageType) {
        addResult($results, sprintf('RT-%02d', $testNumber++), "$vehicleType in $garageType garage",
            $expectedAnswers[$vehicleType][$garageIndex], formatYesNo($vehicle->canUse($garageType)));
    }
}

// ---------- 2. Factory and inheritance ----------
addResult($results, 'RT-13', 'Factory builds a Bicycle',            'Bicycle',      get_class($vehicles['bicycle']));
addResult($results, 'RT-14', 'Factory builds an ElectricCar',       'ElectricCar',  get_class($vehicles['electric_car']));
addResult($results, 'RT-15', 'ElectricCar is a PlatedVehicle',      'yes',          formatYesNo($vehicles['electric_car'] instanceof PlatedVehicle));
addResult($results, 'RT-16', 'Bicycle is NOT a PlatedVehicle',      'no',           formatYesNo($vehicles['bicycle'] instanceof PlatedVehicle));
addResult($results, 'RT-17', 'Plate is cleaned: "l  1234 ab"',      'L 1234 AB',    Vehicle::createFromInput(['vehicle_type' => 'car', 'plate_number' => 'l  1234 ab', 'brand' => 'a', 'model' => 'b'])->getPlateNumber());
addResult($results, 'RT-18', 'Bicycle ignores a typed plate',       'none',         Vehicle::createFromInput(['vehicle_type' => 'bicycle', 'plate_number' => 'B 1', 'brand' => 'a', 'model' => 'b'])->getPlateNumber() ?? 'none');
addResult($results, 'RT-19', 'Unknown type in the factory',         'InvalidArgumentException', getResultOrException(fn() => Vehicle::createFromInput(['vehicle_type' => 'truck'])));

// ---------- 3. Vehicle form validation ----------
$validVehicle = ['vehicle_type' => 'car', 'plate_number' => 'L 1234 AB', 'brand' => 'Toyota', 'model' => 'Avanza', 'battery_kwh' => ''];
$validateVehicle = fn(array $changes): string => getFirstErrorField(VehicleValidator::validate(array_merge($validVehicle, $changes)));
addResult($results, 'RT-20', 'Valid car',                               'valid',        $validateVehicle([]));
addResult($results, 'RT-21', 'No vehicle type chosen',                  'vehicle_type', $validateVehicle(['vehicle_type' => '']));
addResult($results, 'RT-22', 'Car without plate',                       'plate_number', $validateVehicle(['plate_number' => '']));
addResult($results, 'RT-23', 'Motorbike with symbols in the plate',     'plate_number', $validateVehicle(['vehicle_type' => 'motorbike', 'plate_number' => 'L-12*']));
addResult($results, 'RT-24', 'Bicycle without plate is fine',           'valid',        $validateVehicle(['vehicle_type' => 'bicycle', 'plate_number' => '']));
addResult($results, 'RT-25', 'Brand empty',                             'brand',        $validateVehicle(['brand' => '  ']));
addResult($results, 'RT-26', 'Model 51 characters',                     'model',        $validateVehicle(['model' => str_repeat('m', 51)]));
addResult($results, 'RT-27', 'Electric car without battery',            'battery_kwh',  $validateVehicle(['vehicle_type' => 'electric_car', 'battery_kwh' => '']));
addResult($results, 'RT-28', 'Electric car battery 0',                  'battery_kwh',  $validateVehicle(['vehicle_type' => 'electric_car', 'battery_kwh' => '0']));
addResult($results, 'RT-29', 'Electric car battery 72.6',               'valid',        $validateVehicle(['vehicle_type' => 'electric_car', 'battery_kwh' => '72.6']));
addResult($results, 'RT-30', 'Normal car ignores a battery value',      'valid',        $validateVehicle(['vehicle_type' => 'car', 'battery_kwh' => 'abc']));

// ---------- 4. Day limit (days in the month minus 1) ----------
addResult($results, 'RT-31', 'Max days, start 21 Nov 2026 (30-day month)',  '29', (string)RentalRules::getMaxDailyDays(createDate('2026-11-21')));
addResult($results, 'RT-32', 'Max days, start 10 Feb 2027 (28-day month)',  '27', (string)RentalRules::getMaxDailyDays(createDate('2027-02-10')));
addResult($results, 'RT-33', 'Max days, start 10 Feb 2028 (leap year)',     '28', (string)RentalRules::getMaxDailyDays(createDate('2028-02-10')));
addResult($results, 'RT-34', 'Max days, start 5 Jan 2027 (31-day month)',   '30', (string)RentalRules::getMaxDailyDays(createDate('2027-01-05')));

$validateRental = fn(array $fields, string $startText = '2026-11-21'): string
    => getFirstErrorField(RentalRules::validate($fields, createDate($startText)));
addResult($results, 'RT-35', 'Daily, 29 days in November (maximum)',        'valid', $validateRental(['rental_type' => 'daily', 'days' => '29']));
addResult($results, 'RT-36', 'Daily, 30 days in November (one too many)',   'days',  $validateRental(['rental_type' => 'daily', 'days' => '30']));
addResult($results, 'RT-37', 'Daily, 0 days',                               'days',  $validateRental(['rental_type' => 'daily', 'days' => '0']));
addResult($results, 'RT-38', 'Daily, 1 day (minimum)',                      'valid', $validateRental(['rental_type' => 'daily', 'days' => '1']));
addResult($results, 'RT-39', 'Daily without number of days',                'days',  $validateRental(['rental_type' => 'daily', 'days' => '']));
addResult($results, 'RT-40', 'Daily, 27 days in February 2027',             'valid', $validateRental(['rental_type' => 'daily', 'days' => '27'], '2027-02-10'));
addResult($results, 'RT-41', 'Daily, 28 days in February 2027',             'days',  $validateRental(['rental_type' => 'daily', 'days' => '28'], '2027-02-10'));
addResult($results, 'RT-42', 'Monthly ignores the days field',              'valid', $validateRental(['rental_type' => 'monthly', 'days' => '99']));
addResult($results, 'RT-43', 'Unknown rental type',                         'rental_type', $validateRental(['rental_type' => 'weekly']));

// ---------- 5. End date ----------
$calculateEnd = fn(string $startText, string $rentalType, ?int $days): string
    => RentalRules::calculateEndDate(createDate($startText), $rentalType, $days)->format('Y-m-d');
addResult($results, 'RT-44', 'Daily 5 days from 21 Nov 2026',        '2026-11-26', $calculateEnd('2026-11-21', 'daily', 5));
addResult($results, 'RT-45', 'Daily 29 days from 21 Nov 2026',       '2026-12-20', $calculateEnd('2026-11-21', 'daily', 29));
addResult($results, 'RT-46', 'Monthly from 21 Nov 2026',             '2026-12-21', $calculateEnd('2026-11-21', 'monthly', null));
addResult($results, 'RT-47', 'Monthly from 31 Jan 2027 (31 days)',   '2027-03-03', $calculateEnd('2027-01-31', 'monthly', null));
addResult($results, 'RT-48', 'Monthly from 31 Jan 2028 (leap year)', '2028-03-02', $calculateEnd('2028-01-31', 'monthly', null));
addResult($results, 'RT-49', 'Monthly from 31 Dec 2026 (new year)',  '2027-01-31', $calculateEnd('2026-12-31', 'monthly', null));
addResult($results, 'RT-50', 'Daily without days',                   'InvalidArgumentException', getResultOrException(fn() => $calculateEnd('2026-11-21', 'daily', null)));

// ---------- 6. Prices ----------
$petrolCar = $vehicles['car'];
$electricCar = $vehicles['electric_car'];   // battery 72.6 kWh
$calculateTotals = fn(Vehicle $vehicle, string $garageType, string $dailyPrice, ?string $kwhPrice, string $rentalType, ?int $days): array
    => RentalRules::calculateTotals($vehicle, $garageType, $dailyPrice, $kwhPrice, $rentalType, $days);
$formatTotals = fn(array $totals): string => $totals['rental'] . '|' . $totals['charging'] . '|' . $totals['total'];

addResult($results, 'RT-51', 'Daily: 3 days x 50000',                    '150000.00|0.00|150000.00',   $formatTotals($calculateTotals($petrolCar, 'car', '50000', null, 'daily', 3)));
addResult($results, 'RT-52', 'Monthly: 25 x 50000',                      '1250000.00|0.00|1250000.00', $formatTotals($calculateTotals($petrolCar, 'car', '50000', null, 'monthly', null)));
addResult($results, 'RT-53', 'Daily with decimals: 2 x 50000.50',        '100001.00|0.00|100001.00',   $formatTotals($calculateTotals($petrolCar, 'car', '50000.50', null, 'daily', 2)));
addResult($results, 'RT-54', 'Electric car in electric garage: 72.6 kWh x 2500.50 = 181536.30', '300000.00|181536.30|481536.30', $formatTotals($calculateTotals($electricCar, 'electric car', '100000', '2500.50', 'daily', 3)));
addResult($results, 'RT-55', 'Electric car in a car garage: no charging fee', '300000.00|0.00|300000.00', $formatTotals($calculateTotals($electricCar, 'car', '100000', null, 'daily', 3)));
addResult($results, 'RT-56', 'Car in car garage: no charging fee',       '0.00', $calculateTotals($petrolCar, 'car', '50000', null, 'daily', 1)['charging']);
addResult($results, 'RT-57', 'Electric garage without kWh price',        'InvalidArgumentException', getResultOrException(fn() => $calculateTotals($electricCar, 'electric car', '100000', null, 'daily', 1)));
addResult($results, 'RT-58', 'Monthly electric: 25 x 100000 + charging', '2500000.00|181536.30|2681536.30', $formatTotals($calculateTotals($electricCar, 'electric car', '100000', '2500.50', 'monthly', null)));
addResult($results, 'RT-59', 'Money text to cents: "50000.50"',          '5000050', (string)RentalRules::convertToCents('50000.50'));
addResult($results, 'RT-60', 'Money text to cents: "0.5"',               '50',      (string)RentalRules::convertToCents('0.5'));
addResult($results, 'RT-61', 'Cents to text: 5',                         '0.05',    RentalRules::formatCents(5));
addResult($results, 'RT-62', 'Bad amount text',                          'InvalidArgumentException', getResultOrException(fn() => RentalRules::convertToCents('abc')));

// ---------- 7. More end dates (added after Bug 1 in docs/DEBUGGING.md) ----------
addResult($results, 'RT-63', 'Monthly from 10 Feb 2027 (28 days)',   '2027-03-10', $calculateEnd('2027-02-10', 'monthly', null));
addResult($results, 'RT-64', 'Monthly from 15 Apr 2027 (30 days)',   '2027-05-15', $calculateEnd('2027-04-15', 'monthly', null));
addResult($results, 'RT-65', 'Longest daily rental is shorter than monthly (Nov 2026)', 'yes', formatYesNo(
    RentalRules::calculateEndDate(createDate('2026-11-21'), 'daily', 29) < RentalRules::calculateEndDate(createDate('2026-11-21'), 'monthly', null)
));

// ---------- 8. Extension rules ----------
$oldEnd = createDate('2026-11-25');
addResult($results, 'RT-66', 'New end date one day later is valid', 'valid', checkNewEnd('2026-11-26', $oldEnd));
addResult($results, 'RT-67', 'Same end date is refused', 'refused', checkNewEnd('2026-11-25', $oldEnd));
addResult($results, 'RT-68', 'Earlier end date is refused (no shortening)', 'refused', checkNewEnd('2026-11-24', $oldEnd));
addResult($results, 'RT-69', 'Impossible date 2026-02-30 is refused', 'refused', checkNewEnd('2026-02-30', $oldEnd));
addResult($results, 'RT-70', 'Text instead of a date is refused', 'refused', checkNewEnd('tomorrow', $oldEnd));
addResult($results, 'RT-71', 'Empty date is refused', 'refused', checkNewEnd('', $oldEnd));
addResult($results, 'RT-72', '4 extra days x 20000 = 8000000 cents', '8000000', (string)RentalRules::calculateExtensionCents(2000000, $oldEnd, createDate('2026-11-29')));
addResult($results, 'RT-73', 'Extension across a month change (25 Nov to 2 Dec = 7 days)', '14000000', (string)RentalRules::calculateExtensionCents(2000000, $oldEnd, createDate('2026-12-02')));
addResult($results, 'RT-74', 'Extension price is never negative: earlier date throws', 'InvalidArgumentException', getResultOrException(fn() => RentalRules::calculateExtensionCents(2000000, $oldEnd, createDate('2026-11-20'))));
addResult($results, 'RT-75', 'Same date throws', 'InvalidArgumentException', getResultOrException(fn() => RentalRules::calculateExtensionCents(2000000, $oldEnd, createDate('2026-11-25'))));

printResultsAndExit($results, 66, 30);