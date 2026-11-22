<?php
declare(strict_types=1);

require_once __DIR__ . '/Vehicle.php';

/**
 * Validates the "create garages" form. No database code.
 *
 * Relations: uses Vehicle::GARAGE_ELECTRIC_CAR; called by GarageService.
 *
 * @author Marcella
 * @version 1.0
 */
final class GarageBatchValidator
{
    public const MAX_COUNT = 999;

    /**
     * Rules:
     * - count: whole number from 1 to 999
     * - daily_price: positive amount, at most 2 decimals (example: 50000 or 50000.50)
     * - kwh_price: same rule, but ONLY required for the electric car type.
     *   For other types it is ignored (it will be stored as empty).
     *
     * @param array<string, mixed> $data count, daily_price, kwh_price
     * @param string $typeName the chosen garage type name
     * @return array<string, string> error message per field; empty array if valid
     */
    public static function validate(array $data, string $typeName): array
    {
        $errors = [];

        $count = trim((string)($data['count'] ?? ''));
        if (filter_var($count, FILTER_VALIDATE_INT) === false
            || (int)$count < 1 || (int)$count > self::MAX_COUNT) {
            $errors['count'] = 'Number of garages must be a whole number from 1 to ' . self::MAX_COUNT . '.';
        }

        if (!self::isMoney(trim((string)($data['daily_price'] ?? '')))) {
            $errors['daily_price'] = 'Daily price must be a positive amount with at most 2 decimals (example: 50000).';
        }

        if ($typeName === Vehicle::GARAGE_ELECTRIC_CAR && !self::isMoney(trim((string)($data['kwh_price'] ?? '')))) {
            $errors['kwh_price'] = 'Electricity price per kWh is required for electric car garages (positive amount).';
        }

        return $errors;
    }

    /**
     * True for a positive number with up to 10 digits and up to 2 decimals
     * (fits the DECIMAL(12,2) column).
     *
     * @param string $value the text to check, already trimmed
     * @return bool true if it is a valid positive amount
     */
    private static function isMoney(string $value): bool
    {
        return preg_match('/^[0-9]{1,10}(\.[0-9]{1,2})?$/', $value) === 1 && (float)$value > 0;
    }
}