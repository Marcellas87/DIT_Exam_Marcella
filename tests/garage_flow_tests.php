<?php
declare(strict_types=1);

/**
 * Integration tests for GarageService (uses the real database).
 * Run in a terminal:  php tests/garage_flow_tests.php
 *
 * The test creates garages and deletes ONLY the ones it created
 * (numbers above what existed before), so your own garages stay.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/GarageService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$garageService = new GarageService($pdo);

/**
 * Counts the garages of one type.
 *
 * @param PDO $pdo an open database connection
 * @param int $typeId the garage type id
 * @return int the number of garages
 */
function countGarages(PDO $pdo, int $typeId): int
{
    return (int)fetchScalar($pdo, 'SELECT COUNT(*) FROM garages WHERE garage_type_id = ?', [$typeId]);
}

/**
 * Describes a batch result as "first-last", for example "S013-S015".
 *
 * @param array<string, mixed> $batchResult the result of createBatch()
 * @return string the ID range
 */
function describeIdRange(array $batchResult): string
{
    return ($batchResult['first'] ?? '?') . '-' . ($batchResult['last'] ?? '?');
}

$bicycleTypeId = findGarageTypeId($pdo, 'bicycle');
$carTypeId = findGarageTypeId($pdo, 'car');
$electricTypeId = findGarageTypeId($pdo, 'electric car');
$lastNumberBefore = [
    $bicycleTypeId  => findLastGarageNumber($pdo, 'bicycle'),
    $carTypeId      => findLastGarageNumber($pdo, 'car'),
    $electricTypeId => findLastGarageNumber($pdo, 'electric car'),
];

$results = [];
try {
    // --- numbering ---
    $lastBicycle = $lastNumberBefore[$bicycleTypeId];
    $firstBatch = $garageService->createBatch(['garage_type_id' => (string)$bicycleTypeId, 'count' => '3', 'daily_price' => '20000', 'kwh_price' => '9999']);
    addResult($results, 'GF-01', 'First batch of 3 bicycle garages',
        GarageId::formatId('S', $lastBicycle + 1) . '-' . GarageId::formatId('S', $lastBicycle + 3), describeIdRange($firstBatch));

    $secondBatch = $garageService->createBatch(['garage_type_id' => (string)$bicycleTypeId, 'count' => '2', 'daily_price' => '25000', 'kwh_price' => '']);
    addResult($results, 'GF-02', 'Second batch continues the numbering',
        GarageId::formatId('S', $lastBicycle + 4) . '-' . GarageId::formatId('S', $lastBicycle + 5), describeIdRange($secondBatch));

    $garageQuery = $pdo->prepare('SELECT status, daily_price, kwh_price FROM garages WHERE id = ?');
    $garageQuery->execute([GarageId::formatId('S', $lastBicycle + 1)]);
    $garageRow = $garageQuery->fetch();
    addResult($results, 'GF-03', 'New garage: status available, price saved, kWh empty for bicycle', 'available/20000.00/NULL',
        ($garageRow['status'] ?? '?') . '/' . ($garageRow['daily_price'] ?? '?') . '/' . ($garageRow['kwh_price'] ?? 'NULL'));

    // --- other types ---
    $lastCar = $lastNumberBefore[$carTypeId];
    $carBatch = $garageService->createBatch(['garage_type_id' => (string)$carTypeId, 'count' => '1', 'daily_price' => '80000', 'kwh_price' => '']);
    addResult($results, 'GF-04', 'Car garage gets the C prefix', GarageId::formatId('C', $lastCar + 1), $carBatch['first'] ?? '?');

    $electricWithoutKwh = $garageService->createBatch(['garage_type_id' => (string)$electricTypeId, 'count' => '2', 'daily_price' => '120000', 'kwh_price' => '']);
    addResult($results, 'GF-05', 'Electric batch without kWh price is refused', 'kwh_price', listErrorFields($electricWithoutKwh['errors']));

    $lastElectric = $lastNumberBefore[$electricTypeId];
    $electricBatch = $garageService->createBatch(['garage_type_id' => (string)$electricTypeId, 'count' => '2', 'daily_price' => '120000', 'kwh_price' => '2500.50']);
    addResult($results, 'GF-06', 'Electric garages get the E prefix',
        GarageId::formatId('E', $lastElectric + 1) . '-' . GarageId::formatId('E', $lastElectric + 2), describeIdRange($electricBatch));

    $garageQuery->execute([GarageId::formatId('E', $lastElectric + 1)]);
    $garageRow = $garageQuery->fetch();
    addResult($results, 'GF-07', 'Electric garage keeps its kWh price', '2500.50', (string)($garageRow['kwh_price'] ?? '?'));

    // --- refused requests ---
    addResult($results, 'GF-08', 'Unknown garage type id', 'garage_type_id',
        listErrorFields($garageService->createBatch(['garage_type_id' => '999', 'count' => '1', 'daily_price' => '1000', 'kwh_price' => ''])['errors']));
    addResult($results, 'GF-09', 'Count 1000 is refused', 'count',
        listErrorFields($garageService->createBatch(['garage_type_id' => (string)$carTypeId, 'count' => '1000', 'daily_price' => '1000', 'kwh_price' => ''])['errors']));

    // --- the 999 limit per type (numbers are only 3 digits) ---
    $roomLeft = GarageId::MAX_NUMBER - findLastGarageNumber($pdo, 'bicycle');
    $fillBatch = $garageService->createBatch(['garage_type_id' => (string)$bicycleTypeId, 'count' => (string)$roomLeft, 'daily_price' => '10000', 'kwh_price' => '']);
    addResult($results, 'GF-10', 'Fill the type up to S999', 'S999', $fillBatch['last'] ?? '?');

    $garageCountBefore = countGarages($pdo, $bicycleTypeId);
    $overLimitBatch = $garageService->createBatch(['garage_type_id' => (string)$bicycleTypeId, 'count' => '1', 'daily_price' => '10000', 'kwh_price' => '']);
    addResult($results, 'GF-11', 'One more after S999 is refused', 'count', listErrorFields($overLimitBatch['errors']));
    addResult($results, 'GF-12', 'Refused batch saves nothing', 'same', countGarages($pdo, $bicycleTypeId) === $garageCountBefore ? 'same' : 'changed');
} finally {
    // Remove only what this test created.
    foreach ($lastNumberBefore as $typeId => $lastNumber) {
        $pdo->prepare('DELETE FROM garages WHERE garage_type_id = ? AND CAST(SUBSTRING(id, 2) AS UNSIGNED) > ?')->execute([$typeId, $lastNumber]);
    }
}

printResultsAndExit($results, 66, 24);