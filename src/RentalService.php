<?php
declare(strict_types=1);

require_once __DIR__ . '/Vehicle.php';
require_once __DIR__ . '/VehicleValidator.php';
require_once __DIR__ . '/RentalRules.php';
require_once __DIR__ . '/PaymentProofStorage.php';

/**
 * The tenant's "new rental" feature and "my rentals" list.
 *
 * Rules enforced here (the form only helps the user, the server always re-checks):
 * - only an ACCEPTED tenant can rent;
 * - the start date is today, the end date is calculated by RentalRules;
 * - the garage type must be allowed for the vehicle (Vehicle::canUse);
 * - a garage is free if no rental covers today (start <= today < end);
 * - the same vehicle (same plate) cannot be in two garages at the same time;
 * - a plate that already exists is REUSED (the stored vehicle row is not changed);
 * - every query that reads rentals is filtered by the tenant id from the session.
 *
 * All SQL uses prepared statements with positional "?" placeholders.
 *
 * Relations: uses VehicleValidator and Vehicle (vehicle data and garage rule),
 * RentalRules (dates and prices) and PaymentProofStorage (proof image).
 *
 * @author Marcella
 * @version 1.0
 */
final class RentalService
{
    private PDO $pdo;
    private PaymentProofStorage $storage;

    /**
     * @param PDO $pdo an open database connection
     * @param PaymentProofStorage $storage where payment proof images are saved
     */
    public function __construct(PDO $pdo, PaymentProofStorage $storage)
    {
        $this->pdo = $pdo;
        $this->storage = $storage;
    }

    /**
     * Garages the tenant could choose for a vehicle type, free today.
     * The list is already filtered by the vehicle type and by availability.
     *
     * @param string $vehicleType bicycle, motorbike, car or electric_car ('' returns an empty list)
     * @param DateTimeImmutable $today today's date
     * @return array<int, array<string, mixed>> rows: id, type_name, daily_price, kwh_price
     */
    public function findAvailableGarages(string $vehicleType, DateTimeImmutable $today): array
    {
        $typeNames = $this->getGarageTypesFor($vehicleType);
        if ($typeNames === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($typeNames), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT g.id, gt.name AS type_name, g.daily_price, g.kwh_price
             FROM garages g
             JOIN garage_types gt ON gt.id = g.garage_type_id
             WHERE gt.name IN ($placeholders)
               AND NOT EXISTS (
                   SELECT 1 FROM rentals r
                   WHERE r.garage_id = g.id AND r.start_date <= ? AND r.end_date > ?
               )
             ORDER BY gt.id, g.id"
        );
        $day = $today->format('Y-m-d');
        $stmt->execute(array_merge($typeNames, [$day, $day]));
        return $stmt->fetchAll();
    }

    /**
     * Calculates the end date and the amounts for the form, without saving anything.
     *
     * @param array<string, mixed> $input the form fields (vehicle_*, rental_type, days, garage_id)
     * @param DateTimeImmutable $today today's date
     * @return array{errors:array<string,string>,end_date:?string,totals:?array{rental:string,charging:string,total:string}}
     */
    public function previewRental(array $input, DateTimeImmutable $today): array
    {
        $checked = $this->validateInput($input, $today);
        if ($checked['errors'] !== []) {
            return ['errors' => $checked['errors'], 'end_date' => null, 'totals' => null];
        }
        $garage = $this->findGarage((string)$input['garage_id'], false);
        if ($garage === null) {
            return ['errors' => ['garage_id' => 'Please choose a garage.'], 'end_date' => null, 'totals' => null];
        }
        $vehicle = $this->findVehicleForPricing($checked['vehicle']);
        $error = $this->checkGarageType($vehicle, $garage);
        if ($error !== null) {
            return ['errors' => ['garage_id' => $error], 'end_date' => null, 'totals' => null];
        }
        return [
            'errors'   => [],
            'end_date' => $checked['end_date']->format('Y-m-d'),
            'totals'   => $this->calculateTotalsFor($vehicle, $garage, $checked),
        ];
    }

    /**
     * Saves a new rental in ONE transaction:
     * lock the garage, check it is free, find or create the vehicle,
     * check the vehicle is not already parked, save the image, save the rental,
     * mark the garage as used.
     *
     * @param int $tenantId the logged-in tenant (from the session, never from the form)
     * @param array<string, mixed> $input the form fields
     * @param mixed $file the payment proof entry of $_FILES
     * @param DateTimeImmutable $today today's date
     * @return array{errors:array<string,string>,rental_id:?int} errors per field, or the new rental id
     * @throws PDOException if the database fails (the transaction is rolled back)
     */
    public function createRental(int $tenantId, array $input, $file, DateTimeImmutable $today): array
    {
        $fail = static fn(string $field, string $message): array => ['errors' => [$field => $message], 'rental_id' => null];

        if (!$this->isAcceptedTenant($tenantId)) {
            return $fail('form', 'Your registration has not been accepted yet.');
        }

        $checked = $this->validateInput($input, $today);
        $errors = $checked['errors'];
        if (!isset($errors['garage_id']) && trim((string)($input['garage_id'] ?? '')) === '') {
            $errors['garage_id'] = 'Please choose a garage.';
        }
        $fileError = $this->storage->validate($file);
        if ($fileError !== null) {
            $errors['proof'] = $fileError;
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'rental_id' => null];
        }

        $savedName = null;
        $this->pdo->beginTransaction();
        try {
            $garage = $this->findGarage((string)$input['garage_id'], true); // locks the garage row
            if ($garage === null) {
                $this->pdo->rollBack();
                return $fail('garage_id', 'Please choose a garage.');
            }

            $vehicleRow = $this->findOrCreateVehicle($checked['vehicle']);
            if ($vehicleRow === null) {
                $this->pdo->rollBack();
                return $fail('plate_number', 'This plate number cannot be used with that vehicle type.');
            }
            $vehicle = Vehicle::createFromInput($vehicleRow);

            $error = $this->checkGarageType($vehicle, $garage);
            if ($error === null) {
                $error = $this->checkGarageFree((string)$garage['id'], $today, $checked['end_date']);
            }
            if ($error !== null) {
                $this->pdo->rollBack();
                return $fail('garage_id', $error);
            }
            if ($this->isVehicleBusy((int)$vehicleRow['id'], $today, $checked['end_date'])) {
                $this->pdo->rollBack();
                return $fail('plate_number', 'This vehicle is already in a garage during that period.');
            }

            $totals = $this->calculateTotalsFor($vehicle, $garage, $checked);
            $savedName = $this->storage->save($file);

            $stmt = $this->pdo->prepare(
                'INSERT INTO rentals (tenant_id, garage_id, vehicle_id, rental_type, days, start_date, end_date,
                                      daily_price_used, charging_fee, total_amount, payment_proof_path)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $tenantId, $garage['id'], (int)$vehicleRow['id'], $checked['rental_type'], $checked['days'],
                $today->format('Y-m-d'), $checked['end_date']->format('Y-m-d'),
                $garage['daily_price'], $totals['charging'], $totals['total'], $savedName,
            ]);
            $rentalId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE garages SET status = 'used' WHERE id = ?")->execute([$garage['id']]);

            $this->pdo->commit();
            return ['errors' => [], 'rental_id' => $rentalId];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($savedName !== null) {
                $this->storage->delete($savedName);
            }
            throw $e;
        }
    }

    /**
     * Makes garages.status match the rentals: 'used' if a rental covers today, otherwise 'available'.
     * Call it when a page loads, so garages whose rental ended become available again.
     *
     * @param DateTimeImmutable $today today's date
     * @return void
     */
    public function refreshGarageStatuses(DateTimeImmutable $today): void
    {
        $day = $today->format('Y-m-d');
        $this->pdo->prepare(
            "UPDATE garages g SET g.status = CASE WHEN EXISTS (
                 SELECT 1 FROM rentals r WHERE r.garage_id = g.id AND r.start_date <= ? AND r.end_date > ?
             ) THEN 'used' ELSE 'available' END"
        )->execute([$day, $day]);
    }

    /**
     * The tenant's own rentals, newest first.
     * The tenant id always comes from the session, so nobody can read another tenant's rentals.
     *
     * @param int $tenantId the logged-in tenant
     * @return array<int, array<string, mixed>> rental rows with garage id, vehicle details and amounts
     */
    public function findByTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.garage_id, gt.name AS garage_type, v.vehicle_type, v.plate_number, v.brand, v.model,
                    r.rental_type, r.days, r.start_date, r.end_date, r.charging_fee, r.total_amount
             FROM rentals r
             JOIN garages g ON g.id = r.garage_id
             JOIN garage_types gt ON gt.id = g.garage_type_id
             JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.tenant_id = ?
             ORDER BY r.id DESC'
        );
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll();
    }

    /**
     * The payment proof file name of one rental, only if the viewer may see it.
     * An admin may see every proof; a tenant only their own.
     *
     * @param int $rentalId the rental
     * @param int $userId the logged-in user
     * @param string $role 'admin' or 'tenant'
     * @return string|null the stored file name, or null (no such rental, or not allowed)
     */
    public function findProofName(int $rentalId, int $userId, string $role): ?string
    {
        $stmt = $this->pdo->prepare('SELECT tenant_id, payment_proof_path FROM rentals WHERE id = ?');
        $stmt->execute([$rentalId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ($role !== 'admin' && (int)$row['tenant_id'] !== $userId) {
            return null;
        }
        return (string)$row['payment_proof_path'];
    }

    /**
     * Validates the vehicle and rental fields and calculates the end date.
     *
     * @param array<string, mixed> $input the form fields
     * @param DateTimeImmutable $today today's date
     * @return array{errors:array<string,string>,vehicle:?Vehicle,rental_type:string,days:?int,end_date:?DateTimeImmutable}
     */
    private function validateInput(array $input, DateTimeImmutable $today): array
    {
        $errors = VehicleValidator::validate($input) + RentalRules::validate($input, $today);
        $rentalType = (string)($input['rental_type'] ?? '');
        $days = $rentalType === RentalRules::TYPE_DAILY ? (int)trim((string)($input['days'] ?? '')) : null;

        $vehicle = null;
        $endDate = null;
        if ($errors === []) {
            $vehicle = Vehicle::createFromInput($input);
            $endDate = RentalRules::calculateEndDate($today, $rentalType, $days);
        }
        return ['errors' => $errors, 'vehicle' => $vehicle, 'rental_type' => $rentalType, 'days' => $days, 'end_date' => $endDate];
    }

    /**
     * Garage type names a vehicle type may use, found through a sample object of that type.
     *
     * @param string $vehicleType bicycle, motorbike, car or electric_car
     * @return array<int, string> garage type names (empty for an unknown vehicle type)
     */
    private function getGarageTypesFor(string $vehicleType): array
    {
        try {
            $sampleVehicle = Vehicle::createFromInput([
                'vehicle_type' => $vehicleType, 'brand' => 'x', 'model' => 'x',
                'plate_number' => 'X 1', 'battery_kwh' => '1',
            ]);
        } catch (InvalidArgumentException $e) {
            return [];
        }
        return $sampleVehicle->getAllowedGarageTypes();
    }

    /**
     * Loads a garage with its type name.
     *
     * @param string $garageId like "E005"
     * @param bool $lock true to lock the row (FOR UPDATE) inside a transaction
     * @return array<string, mixed>|null the garage row, or null if it does not exist
     */
    private function findGarage(string $garageId, bool $lock): ?array
    {
        $sql = 'SELECT g.id, g.daily_price, g.kwh_price, gt.name AS type_name
                FROM garages g JOIN garage_types gt ON gt.id = g.garage_type_id
                WHERE g.id = ?' . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([trim($garageId)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Checks that the garage type is allowed for the vehicle.
     *
     * @param Vehicle $vehicle the vehicle
     * @param array<string, mixed> $garage the garage row
     * @return string|null an error message, or null if allowed
     */
    private function checkGarageType(Vehicle $vehicle, array $garage): ?string
    {
        return $vehicle->canUse((string)$garage['type_name'])
            ? null
            : 'This vehicle cannot be parked in that type of garage.';
    }

    /**
     * Checks that no rental of the garage overlaps the new period.
     *
     * @param string $garageId the garage
     * @param DateTimeImmutable $start new start date
     * @param DateTimeImmutable $end new end date
     * @return string|null an error message, or null if the garage is free
     */
    private function checkGarageFree(string $garageId, DateTimeImmutable $start, DateTimeImmutable $end): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rentals WHERE garage_id = ? AND start_date < ? AND end_date > ?'
        );
        $stmt->execute([$garageId, $end->format('Y-m-d'), $start->format('Y-m-d')]);
        return (int)$stmt->fetchColumn() > 0 ? 'That garage is no longer available. Please choose another.' : null;
    }

    /**
     * True if the vehicle already has a rental that overlaps the new period.
     *
     * @param int $vehicleId the vehicle
     * @param DateTimeImmutable $start new start date
     * @param DateTimeImmutable $end new end date
     * @return bool true if the vehicle is already parked in that period
     */
    private function isVehicleBusy(int $vehicleId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rentals WHERE vehicle_id = ? AND start_date < ? AND end_date > ?'
        );
        $stmt->execute([$vehicleId, $end->format('Y-m-d'), $start->format('Y-m-d')]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Finds the vehicle by plate (reuse) or creates it. Bicycles have no plate, so they are always new rows.
     * An existing row is never changed. If the stored type differs from the typed type, null is returned.
     * The plate row is locked (FOR UPDATE) so two requests cannot both create it.
     *
     * @param Vehicle $vehicle the vehicle typed in the form
     * @return array<string, mixed>|null the vehicle row (id, vehicle_type, plate_number, brand, model, battery_kwh), or null on a type mismatch
     */
    private function findOrCreateVehicle(Vehicle $vehicle): ?array
    {
        $plateNumber = $vehicle->getPlateNumber();
        if ($plateNumber !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT id, vehicle_type, plate_number, brand, model, battery_kwh FROM vehicles WHERE plate_number = ? FOR UPDATE'
            );
            $stmt->execute([$plateNumber]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row['vehicle_type'] === $vehicle->getTypeCode() ? $row : null;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicles (vehicle_type, plate_number, brand, model, battery_kwh) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $vehicle->getTypeCode(), $plateNumber, $vehicle->getBrand(), $vehicle->getModel(), $vehicle->getBatteryKwh(),
        ]);
        return [
            'id' => (int)$this->pdo->lastInsertId(), 'vehicle_type' => $vehicle->getTypeCode(),
            'plate_number' => $plateNumber, 'brand' => $vehicle->getBrand(), 'model' => $vehicle->getModel(),
            'battery_kwh' => $vehicle->getBatteryKwh(),
        ];
    }

    /**
     * For the preview: if the plate already exists, price with the STORED vehicle
     * (its battery), because that is the row the rental will use. Otherwise the typed vehicle.
     *
     * @param Vehicle $typedVehicle the vehicle typed in the form
     * @return Vehicle the vehicle to price with
     */
    private function findVehicleForPricing(Vehicle $typedVehicle): Vehicle
    {
        $plateNumber = $typedVehicle->getPlateNumber();
        if ($plateNumber === null) {
            return $typedVehicle;
        }
        $stmt = $this->pdo->prepare(
            'SELECT vehicle_type, plate_number, brand, model, battery_kwh FROM vehicles WHERE plate_number = ?'
        );
        $stmt->execute([$plateNumber]);
        $row = $stmt->fetch();
        return ($row !== false && $row['vehicle_type'] === $typedVehicle->getTypeCode())
            ? Vehicle::createFromInput($row)
            : $typedVehicle;
    }

    /**
     * Rental amount, charging fee and total, as text with 2 decimals.
     *
     * @param Vehicle $vehicle the vehicle that will be parked
     * @param array<string, mixed> $garage the garage row
     * @param array{rental_type:string,days:?int} $checked the checked rental fields
     * @return array{rental:string,charging:string,total:string}
     */
    private function calculateTotalsFor(Vehicle $vehicle, array $garage, array $checked): array
    {
        return RentalRules::calculateTotals(
            $vehicle,
            (string)$garage['type_name'],
            (string)$garage['daily_price'],
            $garage['kwh_price'] === null ? null : (string)$garage['kwh_price'],
            $checked['rental_type'],
            $checked['days']
        );
    }

    /**
     * True if the user is a tenant whose registration was accepted.
     *
     * @param int $tenantId the user id
     * @return bool true if the tenant may rent
     */
    private function isAcceptedTenant(int $tenantId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tenants WHERE user_id = ? AND registration_status = 'accepted'");
        $stmt->execute([$tenantId]);
        return (int)$stmt->fetchColumn() === 1;
    }
}