<?php
declare(strict_types=1);

/**
 * Tests for ReportInput (no database) and ReportService (real database).
 * Run in a terminal:  php tests/report_tests.php
 * The test dates its own rentals in 2030 to 2032 and deletes only what it created.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/TenantService.php';
require_once __DIR__ . '/../src/GarageService.php';
require_once __DIR__ . '/../src/RentalService.php';
require_once __DIR__ . '/../src/ReportInput.php';
require_once __DIR__ . '/../src/ReportService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$uniqueTag = createUniqueTag();
$storageDirectory = sys_get_temp_dir() . '/rep_' . $uniqueTag;
mkdir($storageDirectory, 0777, true);
$rentalService = new RentalService($pdo, new PaymentProofStorage($storageDirectory, false));
$reportService = new ReportService($pdo);
$today = new DateTimeImmutable('today');

/**
 * Describes one report row as "count/rental/extension/total".
 *
 * @param array<string, mixed> $reportRow one row of a report
 * @return string for example "3/180000.00/40000.00/220000.00"
 */
function describeReportRow(array $reportRow): string
{
    return $reportRow['rentals'] . '/' . $reportRow['rental_amount'] . '/' . $reportRow['extension_amount'] . '/' . $reportRow['total'];
}

/**
 * Finds a report row by its label; a missing label gives a row with rentals = -1.
 *
 * @param array<int, array<string, mixed>> $reportRows the rows of a report
 * @param string $label the label to look for, like "March"
 * @return array<string, mixed> the row
 */
function findRowByLabel(array $reportRows, string $label): array
{
    foreach ($reportRows as $reportRow) {
        if ($reportRow['label'] === $label) {
            return $reportRow;
        }
    }
    return ['rentals' => -1, 'rental_amount' => '?', 'extension_amount' => '?', 'total' => '?'];
}

$results = [];

// ---- ReportInput (pure) ----
addResult($results, 'RP-01', 'Valid year is kept', '2026', (string)ReportInput::readYear('2026', 2000));
addResult($results, 'RP-02', 'Text year becomes the default', '2026', (string)ReportInput::readYear('abc', 2026));
addResult($results, 'RP-03', 'Year 1999 (out of range) becomes the default', '2026', (string)ReportInput::readYear('1999', 2026));
addResult($results, 'RP-04', 'SQL text as year becomes the default', '2026', (string)ReportInput::readYear('2026 OR 1=1', 2026));
addResult($results, 'RP-05', 'Month 13 becomes the default', '11', (string)ReportInput::readMonth('13', 11));
addResult($results, 'RP-06', 'Valid month is kept', '2', (string)ReportInput::readMonth('2', 11));
addResult($results, 'RP-07', 'Unknown report name becomes "month"', 'month', ReportInput::readReport('drop table'));
addResult($results, 'RP-08', 'Report "tenant" is accepted', 'tenant', ReportInput::readReport('tenant'));

$userIds = [];
$garageIds = [];
try {
    $auth = new AuthService($pdo);
    $tenantName = 'Zz Report ' . $uniqueTag;
    $pendingName = 'Zz Pending ' . $uniqueTag;
    $tenantId = createTestTenant($auth, $pdo, "rp_$uniqueTag@example.com", '4', $tenantName);
    $pendingTenantId = createTestTenant($auth, $pdo, "rq_$uniqueTag@example.com", '3', $pendingName);
    $userIds = [$tenantId, $pendingTenantId];
    (new TenantService($pdo))->decideRegistration($tenantId, 'accepted');
    $adminId = (int)fetchScalar($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");

    $lastBicycle = findLastGarageNumber($pdo, 'bicycle');
    $lastCar = findLastGarageNumber($pdo, 'car');
    $garageService = new GarageService($pdo);
    $garageService->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'bicycle'), 'count' => '2', 'daily_price' => '20000', 'kwh_price' => '']);
    $garageService->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'car'), 'count' => '2', 'daily_price' => '80000', 'kwh_price' => '']);
    $bicycleGarageIds = [GarageId::formatId('S', $lastBicycle + 1), GarageId::formatId('S', $lastBicycle + 2)];
    $carGarageIds = [GarageId::formatId('C', $lastCar + 1), GarageId::formatId('C', $lastCar + 2)];
    $garageIds = array_merge($bicycleGarageIds, $carGarageIds);

    $typeReportBefore = $reportService->buildGarageTypeReport();

    // Saves a daily rental through RentalService, then moves it to $startText so it lands in a known period.
    $createDatedRental = function (string $garageId, array $vehicleFields, string $days, string $startText) use ($rentalService, $tenantId, $today, $pdo): int {
        $form = $vehicleFields + ['vehicle_type' => 'bicycle', 'plate_number' => '', 'brand' => 'B', 'model' => 'M', 'battery_kwh' => '',
                                  'rental_type' => 'daily', 'days' => $days, 'garage_id' => $garageId];
        $rental = $rentalService->createRental($tenantId, $form, createImageUpload(), $today);
        if ($rental['rental_id'] === null) {
            throw new RuntimeException('Setup failed: ' . json_encode($rental['errors']));
        }
        $endText = (new DateTimeImmutable($startText))->modify('+' . (int)$days . ' days')->format('Y-m-d');
        $pdo->prepare('UPDATE rentals SET start_date = ?, end_date = ? WHERE id = ?')->execute([$startText, $endText, $rental['rental_id']]);
        return (int)$rental['rental_id'];
    };
    $createDatedRental($bicycleGarageIds[0], [], '3', '2031-03-05');                                                          // 60000
    $createDatedRental($bicycleGarageIds[1], [], '2', '2031-03-05');                                                          // 40000
    $extendedRentalId = $createDatedRental($carGarageIds[0], ['vehicle_type' => 'car', 'plate_number' => 'ZZ 1' . strtoupper($uniqueTag)], '1', '2031-03-20');   // 80000
    $createDatedRental($carGarageIds[1], ['vehicle_type' => 'car', 'plate_number' => 'ZZ 2' . strtoupper($uniqueTag)], '1', '2030-12-31');                       // 80000, other year
    $insertExtension = $pdo->prepare('INSERT INTO rental_extensions (rental_id, old_end_date, new_end_date, extra_amount, payment_proof_path, admin_id) VALUES (?, ?, ?, ?, ?, ?)');
    $insertExtension->execute([$extendedRentalId, '2031-03-21', '2031-03-22', '10000.00', null, $adminId]);   // two extensions on one rental
    $insertExtension->execute([$extendedRentalId, '2031-03-22', '2031-03-25', '30000.00', null, $adminId]);

    // ---- per month ----
    $monthlyReport = $reportService->buildMonthlyReport(2031);
    addResult($results, 'RP-09', 'Per month always has 12 rows', '12', (string)count($monthlyReport));
    addResult($results, 'RP-10', 'March: 3 rentals, 180000 rental, 40000 extension, 220000 total', '3/180000.00/40000.00/220000.00', describeReportRow(findRowByLabel($monthlyReport, 'March')));
    addResult($results, 'RP-11', 'A month without rentals shows zeros', '0/0.00/0.00/0.00', describeReportRow(findRowByLabel($monthlyReport, 'April')));
    addResult($results, 'RP-12', 'Rental of Dec 2030 is not in 2031', '0/0.00/0.00/0.00', describeReportRow(findRowByLabel($monthlyReport, 'December')));
    addResult($results, 'RP-13', 'Dec 2030 shows in the 2030 report', '1/80000.00/0.00/80000.00', describeReportRow(findRowByLabel($reportService->buildMonthlyReport(2030), 'December')));
    addResult($results, 'RP-14', 'Sum row of 2031 equals March', '3/180000.00/40000.00/220000.00', describeReportRow(ReportService::sumRows($monthlyReport)));
    addResult($results, 'RP-15', 'Two extensions on one rental do not double-count the rental', '1',
        (string)findRowByLabel($reportService->buildDailyReport(2031, 3), '20 Mar 2031')['rentals']);

    // ---- per day ----
    $dailyReport = $reportService->buildDailyReport(2031, 3);
    addResult($results, 'RP-16', 'Per day: March has 31 rows', '31', (string)count($dailyReport));
    addResult($results, 'RP-17', '5 March: 2 rentals, 100000', '2/100000.00/0.00/100000.00', describeReportRow(findRowByLabel($dailyReport, '05 Mar 2031')));
    addResult($results, 'RP-18', '20 March: 1 rental + 40000 extension', '1/80000.00/40000.00/120000.00', describeReportRow(findRowByLabel($dailyReport, '20 Mar 2031')));
    addResult($results, 'RP-19', 'February 2031 has 28 rows', '28', (string)count($reportService->buildDailyReport(2031, 2)));
    addResult($results, 'RP-20', 'February 2032 (leap year) has 29 rows', '29', (string)count($reportService->buildDailyReport(2032, 2)));
    addResult($results, 'RP-21', 'Last day of month is included (31 Dec 2030)', '1/80000.00/0.00/80000.00',
        describeReportRow(findRowByLabel($reportService->buildDailyReport(2030, 12), '31 Dec 2030')));

    // ---- per garage type (difference to the numbers before the test data) ----
    $typeReportAfter = $reportService->buildGarageTypeReport();
    $describeGrowth = function (string $label) use ($typeReportBefore, $typeReportAfter): string {
        $rowBefore = findRowByLabel($typeReportBefore, $label);
        $rowAfter = findRowByLabel($typeReportAfter, $label);
        return ($rowAfter['rentals'] - $rowBefore['rentals']) . '/'
            . RentalRules::formatCents(RentalRules::convertToCents($rowAfter['rental_amount']) - RentalRules::convertToCents($rowBefore['rental_amount'])) . '/'
            . RentalRules::formatCents(RentalRules::convertToCents($rowAfter['extension_amount']) - RentalRules::convertToCents($rowBefore['extension_amount']));
    };
    addResult($results, 'RP-22', 'Per garage type has one row per type', '3', (string)count($typeReportAfter));
    addResult($results, 'RP-23', 'Bicycle type grew by 2 rentals / 100000', '2/100000.00/0.00', $describeGrowth('bicycle'));
    addResult($results, 'RP-24', 'Car type grew by 2 rentals / 160000 / extension 40000', '2/160000.00/40000.00', $describeGrowth('car'));
    addResult($results, 'RP-25', 'Electric type did not change', '0/0.00/0.00', $describeGrowth('electric car'));

    // ---- per tenant ----
    $tenantReport = $reportService->buildTenantReport();
    addResult($results, 'RP-26', 'Tenant row: 4 rentals, 260000 + 40000 extension', '4/260000.00/40000.00/300000.00', describeReportRow(findRowByLabel($tenantReport, $tenantName)));
    addResult($results, 'RP-27', 'Pending tenant is not in the report', '-1', (string)findRowByLabel($tenantReport, $pendingName)['rentals']);
    addResult($results, 'RP-28', 'One row per accepted tenant (also tenants without rentals)',
        fetchScalar($pdo, "SELECT COUNT(*) FROM tenants WHERE registration_status = 'accepted'"), (string)count($tenantReport));
    addResult($results, 'RP-29', 'Sum row of an empty list is zero', '0/0.00/0.00/0.00', describeReportRow(ReportService::sumRows([])));
} finally {
    deleteTestData($pdo, $userIds, $garageIds);
    deleteDirectory($storageDirectory);
}

printResultsAndExit($results, 66, 36);