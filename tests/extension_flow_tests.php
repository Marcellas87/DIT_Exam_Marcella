<?php
declare(strict_types=1);

/**
 * Integration tests for ExtensionService (admin extends a running rental).
 * Run in a terminal:  php tests/extension_flow_tests.php
 * It creates its own tenant, garages and rentals and deletes only those.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/TenantService.php';
require_once __DIR__ . '/../src/GarageService.php';
require_once __DIR__ . '/../src/RentalService.php';
require_once __DIR__ . '/../src/ExtensionService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$uniqueTag = createUniqueTag();
$storageDirectory = sys_get_temp_dir() . '/ext_' . $uniqueTag;
mkdir($storageDirectory, 0777, true);
$storage = new PaymentProofStorage($storageDirectory, false);
$rentalService = new RentalService($pdo, $storage);
$extensionService = new ExtensionService($pdo, $storage);
$today = new DateTimeImmutable('today');
$formatDate = fn(DateTimeImmutable $date): string => $date->format('Y-m-d');

/**
 * Describes a "calculate" answer as "refused" (an error) or "ok".
 *
 * @param array<string, mixed> $calculation the result of calculateExtraAmount()
 * @return string "refused" or "ok"
 */
function describeCalculation(array $calculation): string
{
    return $calculation['error'] === null ? 'ok' : 'refused';
}

$results = [];
$userIds = [];
$garageIds = [];
try {
    $tenantId = createTestTenant(new AuthService($pdo), $pdo, "ex_$uniqueTag@example.com", '5', 'Temp Ext');
    $userIds = [$tenantId];
    (new TenantService($pdo))->decideRegistration($tenantId, 'accepted');
    $adminId = (int)fetchScalar($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");

    $lastBicycle = findLastGarageNumber($pdo, 'bicycle');
    (new GarageService($pdo))->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'bicycle'), 'count' => '2', 'daily_price' => '20000', 'kwh_price' => '']);
    $runningGarageId = GarageId::formatId('S', $lastBicycle + 1);
    $finishedGarageId = GarageId::formatId('S', $lastBicycle + 2);
    $garageIds = [$runningGarageId, $finishedGarageId];

    $createBicycleForm = fn(string $garageId, string $days): array => [
        'vehicle_type' => 'bicycle', 'plate_number' => '', 'brand' => 'Polygon', 'model' => 'Heist',
        'battery_kwh' => '', 'rental_type' => 'daily', 'days' => $days, 'garage_id' => $garageId,
    ];
    $runningRental = $rentalService->createRental($tenantId, $createBicycleForm($runningGarageId, '3'), createImageUpload(), $today);
    $runningRentalId = (int)$runningRental['rental_id'];
    $oldEnd = $today->modify('+3 days');

    // ---- list ----
    $activeIds = array_map('intval', array_column($extensionService->findActiveRentals($today), 'id'));
    addResult($results, 'EF-01', 'Rental running today is in the list', 'yes', formatYesNo(in_array($runningRentalId, $activeIds, true)));
    $finishedRental = $rentalService->createRental($tenantId, $createBicycleForm($finishedGarageId, '2'), createImageUpload(), $today);
    $finishedRentalId = (int)$finishedRental['rental_id'];
    $pdo->prepare('UPDATE rentals SET start_date = ?, end_date = ? WHERE id = ?')
        ->execute([$formatDate($today->modify('-5 days')), $formatDate($today->modify('-1 day')), $finishedRentalId]);
    $activeIds = array_map('intval', array_column($extensionService->findActiveRentals($today), 'id'));
    addResult($results, 'EF-02', 'Finished rental is NOT in the list', 'no', formatYesNo(in_array($finishedRentalId, $activeIds, true)));

    // ---- calculation ----
    $calculation = $extensionService->calculateExtraAmount($runningRentalId, $formatDate($today->modify('+7 days')), $today);
    addResult($results, 'EF-03', '4 extra days x 20000 = 80000.00', '4/80000.00', ($calculation['extra_days'] ?? '?') . '/' . ($calculation['extra_amount'] ?? '?'));
    addResult($results, 'EF-04', 'Calculation saves nothing', '0', fetchScalar($pdo, 'SELECT COUNT(*) FROM rental_extensions WHERE rental_id = ?', [$runningRentalId]));
    addResult($results, 'EF-05', 'Same end date is refused', 'refused', describeCalculation($extensionService->calculateExtraAmount($runningRentalId, $formatDate($oldEnd), $today)));
    addResult($results, 'EF-06', 'Earlier end date is refused (no negative amount)', 'refused', describeCalculation($extensionService->calculateExtraAmount($runningRentalId, $formatDate($today->modify('+1 day')), $today)));
    addResult($results, 'EF-07', 'Finished rental cannot be calculated', 'refused', describeCalculation($extensionService->calculateExtraAmount($finishedRentalId, $formatDate($today->modify('+5 days')), $today)));

    // ---- refusals leave no trace ----
    $fileCountBefore = count(glob($storageDirectory . '/*') ?: []);
    addResult($results, 'EF-08', 'Missing image is refused', 'proof',
        listErrorFields($extensionService->extendRental($adminId, $runningRentalId, $formatDate($today->modify('+7 days')), null, $today)['errors']));
    addResult($results, 'EF-09', 'Bad date is refused', 'new_end_date',
        listErrorFields($extensionService->extendRental($adminId, $runningRentalId, 'not-a-date', createImageUpload(), $today)['errors']));
    addResult($results, 'EF-10', 'Earlier date is refused', 'new_end_date',
        listErrorFields($extensionService->extendRental($adminId, $runningRentalId, $formatDate($today->modify('+2 days')), createImageUpload(), $today)['errors']));
    addResult($results, 'EF-11', 'Finished rental is refused', 'form',
        listErrorFields($extensionService->extendRental($adminId, $finishedRentalId, $formatDate($today->modify('+9 days')), createImageUpload(), $today)['errors']));
    addResult($results, 'EF-12', 'Unknown rental id is refused', 'form',
        listErrorFields($extensionService->extendRental($adminId, 999999999, $formatDate($today->modify('+9 days')), createImageUpload(), $today)['errors']));
    addResult($results, 'EF-13', 'Refusals saved nothing and left no image', '0/yes',
        fetchScalar($pdo, 'SELECT COUNT(*) FROM rental_extensions WHERE rental_id = ?', [$runningRentalId]) . '/'
        . formatYesNo(count(glob($storageDirectory . '/*') ?: []) === $fileCountBefore));
    addResult($results, 'EF-14', 'Rental end date unchanged after refusals', $formatDate($oldEnd), fetchScalar($pdo, 'SELECT end_date FROM rentals WHERE id = ?', [$runningRentalId]));

    // ---- success ----
    $newEnd = $today->modify('+7 days');
    $firstExtension = $extensionService->extendRental($adminId, $runningRentalId, $formatDate($newEnd), createImageUpload(), $today);
    addResult($results, 'EF-15', 'Valid extension is saved', 'no errors', listErrorFields($firstExtension['errors']));
    addResult($results, 'EF-16', 'rentals.end_date is updated', $formatDate($newEnd), fetchScalar($pdo, 'SELECT end_date FROM rentals WHERE id = ?', [$runningRentalId]));
    $extensionRow = $pdo->query('SELECT * FROM rental_extensions WHERE id = ' . (int)($firstExtension['extension_id'] ?? 0))->fetch();
    addResult($results, 'EF-17', 'Extension row: old/new/amount/admin', $formatDate($oldEnd) . '/' . $formatDate($newEnd) . '/80000.00/' . $adminId,
        ($extensionRow['old_end_date'] ?? '?') . '/' . ($extensionRow['new_end_date'] ?? '?') . '/' . ($extensionRow['extra_amount'] ?? '?') . '/' . ($extensionRow['admin_id'] ?? '?'));
    addResult($results, 'EF-18', 'Original total_amount is unchanged', '60000.00', fetchScalar($pdo, 'SELECT total_amount FROM rentals WHERE id = ?', [$runningRentalId]));
    addResult($results, 'EF-19', 'Proof saved under a random name', 'yes', formatYesNo(preg_match('/^[0-9a-f]{32}\.png$/', (string)($extensionRow['payment_proof_path'] ?? '')) === 1));

    // ---- a second extension continues from the new end date ----
    $secondCalculation = $extensionService->calculateExtraAmount($runningRentalId, $formatDate($today->modify('+8 days')), $today);
    addResult($results, 'EF-20', 'Second extension counts from the NEW end date (1 day)', '1/20000.00',
        ($secondCalculation['extra_days'] ?? '?') . '/' . ($secondCalculation['extra_amount'] ?? '?'));
    $extensionService->extendRental($adminId, $runningRentalId, $formatDate($today->modify('+8 days')), createImageUpload(), $today);
    addResult($results, 'EF-21', 'Two extensions are stored for the rental', '2', (string)count($extensionService->findByRental($runningRentalId)));

    // ---- proof access ----
    $extensionId = (int)$firstExtension['extension_id'];
    addResult($results, 'EF-22', 'Owner tenant can open the extension proof', 'yes', formatYesNo($extensionService->findProofName($extensionId, $tenantId, 'tenant') !== null));
    addResult($results, 'EF-23', 'Another tenant cannot', 'NULL', (string)($extensionService->findProofName($extensionId, $tenantId + 100000, 'tenant') ?? 'NULL'));
    addResult($results, 'EF-24', 'Admin can open it', 'yes', formatYesNo($extensionService->findProofName($extensionId, $adminId, 'admin') !== null));

    $myRentals = array_column($rentalService->findByTenant($tenantId), 'end_date', 'id');
    addResult($results, 'EF-25', 'Tenant sees the new end date in my rentals', $formatDate($today->modify('+8 days')), (string)($myRentals[$runningRentalId] ?? '?'));
} finally {
    deleteTestData($pdo, $userIds, $garageIds);
    deleteDirectory($storageDirectory);
}

printResultsAndExit($results, 66, 34);