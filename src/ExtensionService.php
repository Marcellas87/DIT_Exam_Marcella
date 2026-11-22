<?php
declare(strict_types=1);

require_once __DIR__ . '/RentalRules.php';
require_once __DIR__ . '/PaymentProofStorage.php';

/**
 * The admin feature "extend a rental" (change the end date of a rental that is running today).
 *
 * Rules:
 * - only rentals that cover today (start <= today < end) can be extended;
 * - the new end date must be LATER than the current end date (no shortening, so the extra amount is never negative);
 * - extra amount = extra days x the daily price saved with the rental (RentalRules::calculateExtensionCents);
 * - the admin must upload a payment proof image for the extra amount;
 * - the change is saved in ONE transaction: rental_extensions row + new rentals.end_date.
 *
 * The original rentals.total_amount is left unchanged; extensions are stored separately,
 * so reports can add them.
 *
 * Relations: uses RentalRules (date check, extra amount) and PaymentProofStorage (proof image);
 * reads rentals created by RentalService and writes rental_extensions.
 *
 * @author Marcella
 * @version 1.0
 */
final class ExtensionService
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
     * Rentals running today: the list the admin picks from.
     *
     * @param DateTimeImmutable $today today's date
     * @return array<int, array<string, mixed>> rows: id, garage_id, garage_type, tenant_name, vehicle, start_date, end_date
     */
    public function findActiveRentals(DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.garage_id, gt.name AS garage_type, t.full_name AS tenant_name,
                    CONCAT(v.brand, ' ', v.model, IFNULL(CONCAT(', ', v.plate_number), '')) AS vehicle,
                    r.start_date, r.end_date
             FROM rentals r
             JOIN garages g ON g.id = r.garage_id
             JOIN garage_types gt ON gt.id = g.garage_type_id
             JOIN tenants t ON t.user_id = r.tenant_id
             JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.start_date <= ? AND r.end_date > ?
             ORDER BY r.garage_id"
        );
        $day = $today->format('Y-m-d');
        $stmt->execute([$day, $day]);
        return $stmt->fetchAll();
    }

    /**
     * One running rental, for the extend form. Returns null if it does not exist or is not running today.
     *
     * @param int $rentalId the rental
     * @param DateTimeImmutable $today today's date
     * @return array<string, mixed>|null the same columns as findActiveRentals(), plus daily_price_used
     */
    public function findActiveRental(int $rentalId, DateTimeImmutable $today): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.garage_id, r.daily_price_used, r.start_date, r.end_date,
                    t.full_name AS tenant_name,
                    CONCAT(v.brand, ' ', v.model, IFNULL(CONCAT(', ', v.plate_number), '')) AS vehicle
             FROM rentals r
             JOIN tenants t ON t.user_id = r.tenant_id
             JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.id = ? AND r.start_date <= ? AND r.end_date > ?"
        );
        $day = $today->format('Y-m-d');
        $stmt->execute([$rentalId, $day, $day]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Calculates the extra amount without saving.
     *
     * @param int $rentalId the rental
     * @param string $newEnd the new end date typed by the admin (YYYY-MM-DD)
     * @param DateTimeImmutable $today today's date
     * @return array{error:?string,extra_days:?int,extra_amount:?string}
     */
    public function calculateExtraAmount(int $rentalId, string $newEnd, DateTimeImmutable $today): array
    {
        $rental = $this->findActiveRental($rentalId, $today);
        if ($rental === null) {
            return ['error' => 'This rental is not running today.', 'extra_days' => null, 'extra_amount' => null];
        }
        $oldEndDate = new DateTimeImmutable((string)$rental['end_date']);
        $error = RentalRules::validateNewEnd($newEnd, $oldEndDate);
        if ($error !== null) {
            return ['error' => $error, 'extra_days' => null, 'extra_amount' => null];
        }
        $newEndDate = new DateTimeImmutable(trim($newEnd));
        $extraCents = RentalRules::calculateExtensionCents(
            RentalRules::convertToCents((string)$rental['daily_price_used']),
            $oldEndDate,
            $newEndDate
        );
        return [
            'error'        => null,
            'extra_days'   => (int)$oldEndDate->diff($newEndDate)->format('%a'),
            'extra_amount' => RentalRules::formatCents($extraCents),
        ];
    }

    /**
     * Saves the extension.
     *
     * @param int $adminId the logged-in admin (from the session)
     * @param int $rentalId the rental to extend
     * @param string $newEnd the new end date (YYYY-MM-DD)
     * @param mixed $file the payment proof entry of $_FILES
     * @param DateTimeImmutable $today today's date
     * @return array{errors:array<string,string>,extension_id:?int} errors per field, or the new extension id
     * @throws PDOException if the database fails (the transaction is rolled back)
     */
    public function extendRental(int $adminId, int $rentalId, string $newEnd, $file, DateTimeImmutable $today): array
    {
        $fileError = $this->storage->validate($file);
        $errors = [];
        if ($fileError !== null) {
            $errors['proof'] = $fileError;
        }

        $savedName = null;
        $this->pdo->beginTransaction();
        try {
            // Lock the rental so two admins cannot extend it at the same moment.
            $stmt = $this->pdo->prepare(
                'SELECT id, garage_id, daily_price_used, end_date FROM rentals
                 WHERE id = ? AND start_date <= ? AND end_date > ? FOR UPDATE'
            );
            $day = $today->format('Y-m-d');
            $stmt->execute([$rentalId, $day, $day]);
            $rental = $stmt->fetch();
            if ($rental === false) {
                $this->pdo->rollBack();
                return ['errors' => ['form' => 'This rental is not running today.'], 'extension_id' => null];
            }

            $oldEndDate = new DateTimeImmutable((string)$rental['end_date']);
            $dateError = RentalRules::validateNewEnd($newEnd, $oldEndDate);
            if ($dateError !== null) {
                $errors['new_end_date'] = $dateError;
            }
            if ($errors !== []) {
                $this->pdo->rollBack();
                return ['errors' => $errors, 'extension_id' => null];
            }

            $newEndDate = new DateTimeImmutable(trim($newEnd));
            $extraCents = RentalRules::calculateExtensionCents(
                RentalRules::convertToCents((string)$rental['daily_price_used']),
                $oldEndDate,
                $newEndDate
            );

            $clash = $this->pdo->prepare(
                'SELECT COUNT(*) FROM rentals WHERE garage_id = ? AND id <> ? AND start_date < ? AND end_date > ?'
            );
            $clash->execute([$rental['garage_id'], $rentalId, $newEndDate->format('Y-m-d'), $oldEndDate->format('Y-m-d')]);
            if ((int)$clash->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['errors' => ['new_end_date' => 'The garage is booked by another rental in that period.'], 'extension_id' => null];
            }

            $savedName = $this->storage->save($file);
            $this->pdo->prepare(
                'INSERT INTO rental_extensions (rental_id, old_end_date, new_end_date, extra_amount, payment_proof_path, admin_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $rentalId, $oldEndDate->format('Y-m-d'), $newEndDate->format('Y-m-d'),
                RentalRules::formatCents($extraCents), $savedName, $adminId,
            ]);
            $extensionId = (int)$this->pdo->lastInsertId();

            $this->pdo->prepare('UPDATE rentals SET end_date = ? WHERE id = ?')->execute([$newEndDate->format('Y-m-d'), $rentalId]);

            $this->pdo->commit();
            return ['errors' => [], 'extension_id' => $extensionId];
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
     * The extension payment proof file name, only if the viewer may see it
     * (any admin, or the tenant who owns the rental).
     *
     * @param int $extensionId the extension
     * @param int $userId the logged-in user
     * @param string $role 'admin' or 'tenant'
     * @return string|null the stored file name, or null (missing or not allowed)
     */
    public function findProofName(int $extensionId, int $userId, string $role): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.tenant_id, e.payment_proof_path
             FROM rental_extensions e JOIN rentals r ON r.id = e.rental_id WHERE e.id = ?'
        );
        $stmt->execute([$extensionId]);
        $row = $stmt->fetch();
        if ($row === false || $row['payment_proof_path'] === null) {
            return null;
        }
        if ($role !== 'admin' && (int)$row['tenant_id'] !== $userId) {
            return null;
        }
        return (string)$row['payment_proof_path'];
    }

    /**
     * Extensions of one rental, oldest first.
     *
     * @param int $rentalId the rental
     * @return array<int, array<string, mixed>> rows: id, old_end_date, new_end_date, extra_amount
     */
    public function findByRental(int $rentalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, old_end_date, new_end_date, extra_amount FROM rental_extensions WHERE rental_id = ? ORDER BY id'
        );
        $stmt->execute([$rentalId]);
        return $stmt->fetchAll();
    }
}