<?php
declare(strict_types=1);

/**
 * Integration tests for RentalService and PaymentProofStorage (real database, temporary files).
 * Run in a terminal:  php tests/rental_flow_tests.php
 *
 * The test creates its own tenants, garages, vehicles and rentals, and deletes ONLY those at the end.
 *
 * @author Marcella
 * @version 1.1
 */
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/TenantService.php';
require_once __DIR__ . '/../src/GarageService.php';
require_once __DIR__ . '/../src/RentalService.php';
require_once __DIR__ . '/test_helpers.php';

if (PHP_SAPI !== 'cli') { exit('Run this file from the terminal.'); }

$pdo = Database::connect();
$auth = new AuthService($pdo);
$tenantService = new TenantService($pdo);
$garageService = new GarageService($pdo);

$uniqueTag = createUniqueTag();
$storageDirectory = sys_get_temp_dir() . '/proofs_' . $uniqueTag;
mkdir($storageDirectory, 0777, true);
$storage = new PaymentProofStorage($storageDirectory, false);   // false = test files, not HTTP uploads
$rentalService = new RentalService($pdo, $storage);
$today = new DateTimeImmutable('today');
$todayText = $today->format('Y-m-d');

/**
 * A text file pretending to be a PNG, in the shape of one $_FILES entry.
 *
 * @return array{name:string,tmp_name:string,error:int,size:int,type:string}
 */
function createFakeImageUpload(): array
{
    $path = (string)tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, '<?php echo "not an image"; ?>');
    return ['name' => 'evil.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($path), 'type' => 'image/png'];
}

/**
 * Builds rental form data: a 3-day bicycle rental, with some fields changed.
 *
 * @param array<string, string> $changes the fields to replace
 * @return array<string, string> the form fields
 */
function createRentalForm(array $changes): array
{
    return $changes + ['vehicle_type' => 'bicycle', 'plate_number' => '', 'brand' => 'Polygon', 'model' => 'Heist',
                       'battery_kwh' => '', 'rental_type' => 'daily', 'days' => '3', 'garage_id' => ''];
}

/**
 * Counts the files in the storage folder.
 *
 * @param string $directory the folder
 * @return int the number of files
 */
function countFiles(string $directory): int
{
    return count(glob($directory . '/*') ?: []);
}

$results = [];
$userIds = [];
$garageIds = [];
$tempFiles = [];
try {
    $tenantA = createTestTenant($auth, $pdo, "ra_$uniqueTag@example.com", (string)random_int(1, 9), "Temp ra_$uniqueTag");
    $tenantB = createTestTenant($auth, $pdo, "rb_$uniqueTag@example.com", (string)random_int(1, 9), "Temp rb_$uniqueTag");
    $pendingTenant = createTestTenant($auth, $pdo, "rp_$uniqueTag@example.com", (string)random_int(1, 9), "Temp rp_$uniqueTag");
    $userIds = [$tenantA, $tenantB, $pendingTenant];
    $tenantService->decideRegistration($tenantA, 'accepted');
    $tenantService->decideRegistration($tenantB, 'accepted');   // $pendingTenant stays pending

    // Garages: 4 bicycle, 3 car, 2 electric.
    $lastBicycle = findLastGarageNumber($pdo, 'bicycle');
    $lastCar = findLastGarageNumber($pdo, 'car');
    $lastElectric = findLastGarageNumber($pdo, 'electric car');
    $garageService->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'bicycle'), 'count' => '4', 'daily_price' => '20000', 'kwh_price' => '']);
    $garageService->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'car'), 'count' => '3', 'daily_price' => '80000', 'kwh_price' => '']);
    $garageService->createBatch(['garage_type_id' => (string)findGarageTypeId($pdo, 'electric car'), 'count' => '2', 'daily_price' => '120000', 'kwh_price' => '2500.50']);
    $bicycleGarage = fn(int $position): string => GarageId::formatId('S', $lastBicycle + $position);
    $carGarage = fn(int $position): string => GarageId::formatId('C', $lastCar + $position);
    $electricGarage = fn(int $position): string => GarageId::formatId('E', $lastElectric + $position);
    foreach ([['S', $lastBicycle, 4], ['C', $lastCar, 3], ['E', $lastElectric, 2]] as [$prefix, $lastNumber, $createdCount]) {
        for ($position = 1; $position <= $createdCount; $position++) {
            $garageIds[] = GarageId::formatId($prefix, $lastNumber + $position);
        }
    }
    $firstPlate = 'ZZ 1' . strtoupper($uniqueTag);
    $secondPlate = 'ZZ 2' . strtoupper($uniqueTag);
    $thirdPlate = 'ZZ 3' . strtoupper($uniqueTag);

    // Makes an image upload and remembers its temporary file for the clean-up.
    $createTrackedUpload = function (string $extension = 'png', int $extraBytes = 0) use (&$tempFiles): array {
        $upload = createImageUpload($extension, $extraBytes);
        $tempFiles[] = $upload['tmp_name'];
        return $upload;
    };

    // ---- garage list ----
    $listGarageIds = fn(string $vehicleType): array => array_column($rentalService->findAvailableGarages($vehicleType, $today), 'id');
    $bicycleList = $listGarageIds('bicycle');
    addResult($results, 'RF-01', 'Bicycle vehicle sees bicycle garages only', 'yes', formatYesNo(
        in_array($bicycleGarage(1), $bicycleList, true) && !in_array($carGarage(1), $bicycleList, true) && !in_array($electricGarage(1), $bicycleList, true)));
    addResult($results, 'RF-02', 'Motorbike sees bicycle garages only', 'yes', formatYesNo(
        in_array($bicycleGarage(2), $listGarageIds('motorbike'), true) && !in_array($carGarage(1), $listGarageIds('motorbike'), true)));
    addResult($results, 'RF-03', 'Car sees car garages only', 'yes', formatYesNo(
        in_array($carGarage(1), $listGarageIds('car'), true) && !in_array($electricGarage(1), $listGarageIds('car'), true) && !in_array($bicycleGarage(1), $listGarageIds('car'), true)));
    addResult($results, 'RF-04', 'Electric car sees car and electric garages', 'yes', formatYesNo(
        in_array($carGarage(1), $listGarageIds('electric_car'), true) && in_array($electricGarage(1), $listGarageIds('electric_car'), true) && !in_array($bicycleGarage(1), $listGarageIds('electric_car'), true)));
    addResult($results, 'RF-05', 'Unknown vehicle type gives an empty list', '0', (string)count($rentalService->findAvailableGarages('', $today)));

    // ---- preview (no saving) ----
    $preview = $rentalService->previewRental(createRentalForm(['garage_id' => $bicycleGarage(1), 'days' => '3']), $today);
    addResult($results, 'RF-06', 'Preview daily: 3 days x 20000 = 60000.00', '60000.00', $preview['totals']['total'] ?? '?');
    addResult($results, 'RF-07', 'Preview end date = today + 3 days', $today->modify('+3 days')->format('Y-m-d'), $preview['end_date'] ?? '?');
    $preview = $rentalService->previewRental(createRentalForm(['garage_id' => $bicycleGarage(1), 'rental_type' => 'monthly', 'days' => '']), $today);
    addResult($results, 'RF-08', 'Preview monthly = 25 x 20000 = 500000.00', '500000.00', $preview['totals']['total'] ?? '?');
    $electricForm = ['vehicle_type' => 'electric_car', 'plate_number' => $thirdPlate, 'brand' => 'BYD', 'model' => 'Atto 3', 'battery_kwh' => '40', 'days' => '2'];
    $preview = $rentalService->previewRental(createRentalForm($electricForm + ['garage_id' => $electricGarage(1)]), $today);
    addResult($results, 'RF-09', 'Preview EV in electric garage: 240000 + 40 x 2500.50 = 340020.00', '340020.00', $preview['totals']['total'] ?? '?');
    $preview = $rentalService->previewRental(createRentalForm($electricForm + ['garage_id' => $carGarage(1)]), $today);
    addResult($results, 'RF-10', 'Preview EV in car garage: no charging fee, 160000.00', '0.00/160000.00', ($preview['totals']['charging'] ?? '?') . '/' . ($preview['totals']['total'] ?? '?'));
    addResult($results, 'RF-11', 'Preview refuses a car in a bicycle garage', 'garage_id',
        listErrorFields($rentalService->previewRental(createRentalForm(['vehicle_type' => 'car', 'plate_number' => $firstPlate, 'garage_id' => $bicycleGarage(1)]), $today)['errors']));
    addResult($results, 'RF-12', 'Preview saves nothing', '0', fetchScalar($pdo, 'SELECT COUNT(*) FROM rentals WHERE tenant_id = ?', [$tenantA]));

    // ---- saving a bicycle rental ----
    $bicycleRental = $rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(1), 'days' => '3']), $createTrackedUpload(), $today);
    addResult($results, 'RF-13', 'Bicycle rental is saved', 'no errors', listErrorFields($bicycleRental['errors']));
    $rentalRow = $pdo->query('SELECT * FROM rentals WHERE id = ' . (int)$bicycleRental['rental_id'])->fetch();
    addResult($results, 'RF-14', 'Saved start/end/type/days/total', "$todayText/" . $today->modify('+3 days')->format('Y-m-d') . '/daily/3/60000.00/20000.00/0.00',
        $rentalRow['start_date'] . '/' . $rentalRow['end_date'] . '/' . $rentalRow['rental_type'] . '/' . $rentalRow['days'] . '/'
        . $rentalRow['total_amount'] . '/' . $rentalRow['daily_price_used'] . '/' . $rentalRow['charging_fee']);
    addResult($results, 'RF-15', 'Garage status becomes used', 'used', fetchScalar($pdo, 'SELECT status FROM garages WHERE id = ?', [$bicycleGarage(1)]));
    addResult($results, 'RF-16', 'Used garage disappears from the list', 'no', formatYesNo(in_array($bicycleGarage(1), $listGarageIds('bicycle'), true)));
    addResult($results, 'RF-17', 'Bicycle vehicle row has no plate', 'NULL', fetchScalar($pdo, 'SELECT plate_number FROM vehicles WHERE id = ?', [$rentalRow['vehicle_id']]));
    $proofName = (string)$rentalRow['payment_proof_path'];
    addResult($results, 'RF-18', 'Image saved under a random name, not the uploaded name', 'yes',
        formatYesNo(preg_match('/^[0-9a-f]{32}\.png$/', $proofName) === 1 && is_file($storageDirectory . '/' . $proofName)));

    // ---- refusals ----
    $fileCountBefore = countFiles($storageDirectory);
    $validUpload = $createTrackedUpload();
    addResult($results, 'RF-19', 'Garage already taken is refused', 'garage_id',
        listErrorFields($rentalService->createRental($tenantB, createRentalForm(['garage_id' => $bicycleGarage(1)]), $validUpload, $today)['errors']));
    addResult($results, 'RF-20', 'Refused rental leaves no image behind', 'yes', formatYesNo(countFiles($storageDirectory) === $fileCountBefore));
    addResult($results, 'RF-21', 'Pending tenant cannot rent', 'form',
        listErrorFields($rentalService->createRental($pendingTenant, createRentalForm(['garage_id' => $bicycleGarage(2)]), $validUpload, $today)['errors']));
    addResult($results, 'RF-22', 'Missing image is refused', 'proof',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(2)]), null, $today)['errors']));
    $fakeUpload = createFakeImageUpload();
    $tempFiles[] = $fakeUpload['tmp_name'];
    addResult($results, 'RF-23', 'Text file renamed to .png is refused', 'proof',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(2)]), $fakeUpload, $today)['errors']));
    addResult($results, 'RF-24', 'Image over 2 MB is refused', 'proof',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(2)]), $createTrackedUpload('png', 2 * 1024 * 1024), $today)['errors']));
    $validUpload = $createTrackedUpload();
    $tooManyDays = (string)(RentalRules::getMaxDailyDays($today) + 1);
    addResult($results, 'RF-25', 'Daily rental over the month limit is refused', 'days',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(2), 'days' => $tooManyDays]), $validUpload, $today)['errors']));
    addResult($results, 'RF-26', 'Car in a bicycle garage is refused', 'garage_id',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['vehicle_type' => 'car', 'plate_number' => $firstPlate, 'garage_id' => $bicycleGarage(2)]), $validUpload, $today)['errors']));
    addResult($results, 'RF-27', 'SQL injection text as garage id is refused safely', 'garage_id',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => "S001' OR '1'='1"]), $validUpload, $today)['errors']));
    addResult($results, 'RF-28', 'No garage chosen is refused', 'garage_id',
        listErrorFields($rentalService->createRental($tenantA, createRentalForm(['garage_id' => '']), $validUpload, $today)['errors']));
    addResult($results, 'RF-29', 'Nothing was saved by the refusals', '1', fetchScalar($pdo, 'SELECT COUNT(*) FROM rentals WHERE tenant_id IN (?, ?)', [$tenantA, $tenantB]));

    // ---- JPG accepted ----
    $jpgRental = $rentalService->createRental($tenantA, createRentalForm(['garage_id' => $bicycleGarage(2)]), $createTrackedUpload('jpg'), $today);
    addResult($results, 'RF-30', 'JPG image is accepted and stored as .jpg', 'no errors/jpg', listErrorFields($jpgRental['errors']) . '/'
        . pathinfo(fetchScalar($pdo, 'SELECT payment_proof_path FROM rentals WHERE id = ?', [$jpgRental['rental_id']]), PATHINFO_EXTENSION));

    // ---- plates: reuse and overlap ----
    $carForm = ['vehicle_type' => 'car', 'plate_number' => $firstPlate, 'brand' => 'Toyota', 'model' => 'Yaris'];
    $carRental = $rentalService->createRental($tenantA, createRentalForm(['plate_number' => strtolower($firstPlate), 'garage_id' => $carGarage(1)] + $carForm), $createTrackedUpload(), $today);
    addResult($results, 'RF-31', 'Car rental saved (plate typed in lower case)', 'no errors', listErrorFields($carRental['errors']));
    addResult($results, 'RF-32', 'Plate is stored in normalized form', $firstPlate,
        fetchScalar($pdo, 'SELECT v.plate_number FROM rentals r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ?', [$carRental['rental_id']]));

    addResult($results, 'RF-33', 'Same plate cannot be in a second garage at the same time', 'plate_number',
        listErrorFields($rentalService->createRental($tenantB, createRentalForm(['garage_id' => $carGarage(2)] + $carForm), $createTrackedUpload(), $today)['errors']));

    // End the first car rental in the past, so the plate is free again.
    $pdo->prepare('UPDATE rentals SET start_date = ?, end_date = ? WHERE id = ?')
        ->execute([$today->modify('-5 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $carRental['rental_id']]);
    $rentalService->refreshGarageStatuses($today);
    addResult($results, 'RF-34', 'refreshGarageStatuses frees a garage whose rental ended', 'available',
        fetchScalar($pdo, 'SELECT status FROM garages WHERE id = ?', [$carGarage(1)]));

    $reusedPlateRental = $rentalService->createRental($tenantB,
        createRentalForm(['vehicle_type' => 'car', 'plate_number' => $firstPlate, 'brand' => 'Other', 'model' => 'Name', 'garage_id' => $carGarage(2)]), $createTrackedUpload(), $today);
    addResult($results, 'RF-35', 'Another tenant can reuse the same plate later', 'no errors', listErrorFields($reusedPlateRental['errors']));
    addResult($results, 'RF-36', 'The vehicle row is reused (one row for the plate)', '1', fetchScalar($pdo, 'SELECT COUNT(*) FROM vehicles WHERE plate_number = ?', [$firstPlate]));
    addResult($results, 'RF-37', 'The stored brand/model is NOT overwritten', 'Toyota Yaris', fetchScalar($pdo, "SELECT CONCAT(brand, ' ', model) FROM vehicles WHERE plate_number = ?", [$firstPlate]));

    $motorbikeRental = $rentalService->createRental($tenantA,
        createRentalForm(['vehicle_type' => 'motorbike', 'plate_number' => $firstPlate, 'brand' => 'Honda', 'model' => 'Beat', 'garage_id' => $bicycleGarage(3)]), $createTrackedUpload(), $today);
    addResult($results, 'RF-38', 'Plate used with another vehicle type gives a generic error', 'plate_number/This plate number cannot be used with that vehicle type.',
        listErrorFields($motorbikeRental['errors']) . '/' . ($motorbikeRental['errors']['plate_number'] ?? ''));

    // ---- electric car charging fee ----
    $electricRental = $rentalService->createRental($tenantA,
        createRentalForm(['plate_number' => $secondPlate, 'garage_id' => $electricGarage(1)] + $electricForm), $createTrackedUpload(), $today);
    $rentalRow = $pdo->query('SELECT charging_fee, total_amount FROM rentals WHERE id = ' . (int)($electricRental['rental_id'] ?? 0))->fetch();
    addResult($results, 'RF-39', 'EV in electric garage saves charging fee and total', '100020.00/340020.00',
        ($rentalRow['charging_fee'] ?? '?') . '/' . ($rentalRow['total_amount'] ?? '?'));

    // ---- tenant isolation ----
    $rentalIdsOfA = array_column($rentalService->findByTenant($tenantA), 'id');
    $rentalIdsOfB = array_column($rentalService->findByTenant($tenantB), 'id');
    $firstRentalId = (int)$bicycleRental['rental_id'];
    $adminId = (int)fetchScalar($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    addResult($results, 'RF-40', "Tenant A's list has no rental of tenant B", 'no', formatYesNo((bool)array_intersect($rentalIdsOfA, $rentalIdsOfB)));
    addResult($results, 'RF-41', "Tenant B's list contains only B's rental", 'yes', formatYesNo($rentalIdsOfB === [$reusedPlateRental['rental_id']]));
    addResult($results, 'RF-42', "Tenant B cannot open A's payment proof", 'NULL', (string)($rentalService->findProofName($firstRentalId, $tenantB, 'tenant') ?? 'NULL'));
    addResult($results, 'RF-43', 'Tenant A can open own payment proof', 'yes', formatYesNo($rentalService->findProofName($firstRentalId, $tenantA, 'tenant') !== null));
    addResult($results, 'RF-44', "Admin can open any tenant's payment proof", 'yes', formatYesNo($rentalService->findProofName($firstRentalId, $adminId, 'admin') !== null));
    addResult($results, 'RF-45', 'Proof of a rental that does not exist', 'NULL', (string)($rentalService->findProofName(999999999, $tenantA, 'tenant') ?? 'NULL'));
    addResult($results, 'RF-46', 'findPath refuses a name with ../', 'NULL', (string)($storage->findPath('../../etc/passwd') ?? 'NULL'));
} finally {
    // Remove only what this test created.
    deleteTestData($pdo, $userIds, $garageIds);
    deleteDirectory($storageDirectory);
    foreach ($tempFiles as $tempFile) {
        if (is_file($tempFile)) {
            @unlink($tempFile);
        }
    }
}

printResultsAndExit($results, 66, 30);