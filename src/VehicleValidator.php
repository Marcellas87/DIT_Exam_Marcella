<?php
declare(strict_types=1);

/**
 * Validates the vehicle part of the rental form. No database code.
 */
final class VehicleValidator
{
    private const TYPES = [
        Vehicle::TYPE_BICYCLE, Vehicle::TYPE_MOTORBIKE, Vehicle::TYPE_CAR, Vehicle::TYPE_ELECTRIC_CAR,
    ];

    /**
     * Rules:
     * - vehicle_type: bicycle, motorbike, car or electric_car
     * - plate_number: required for every type except bicycle (3 to 15 letters, digits or spaces).
     *   For a bicycle it is ignored.
     * - brand and model: required, at most 50 characters each
     * - battery_kwh: required for an electric car (positive, up to 4 digits and 2 decimals).
     *   For other types it is ignored.
     *
     * @param array<string, mixed> $data vehicle_type, plate_number, brand, model, battery_kwh
     * @return array<string, string> error message per field; empty array if valid
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $type = (string)($data['vehicle_type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            $errors['vehicle_type'] = 'Please choose a vehicle type.';
            return $errors; // the other rules depend on the type
        }

        if ($type !== Vehicle::TYPE_BICYCLE) {
            $plate = self::normalizePlate((string)($data['plate_number'] ?? ''));
            if (preg_match('/^[A-Z0-9 ]{3,15}$/', $plate) !== 1) {
                $errors['plate_number'] = 'Plate number is required (3 to 15 letters or digits).';
            }
        }

        foreach (['brand' => 'Brand', 'model' => 'Model'] as $field => $label) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value === '') {
                $errors[$field] = $label . ' is required.';
            } elseif (mb_strlen($value) > 50) {
                $errors[$field] = $label . ' must be at most 50 characters.';
            }
        }

        if ($type === Vehicle::TYPE_ELECTRIC_CAR) {
            $battery = trim((string)($data['battery_kwh'] ?? ''));
            if (preg_match('/^[0-9]{1,4}(\.[0-9]{1,2})?$/', $battery) !== 1 || (float)$battery <= 0) {
                $errors['battery_kwh'] = 'Battery capacity (kWh) is required for an electric car, for example 40 or 40.5.';
            }
        }

        return $errors;
    }

    /**
     * Makes plate numbers comparable: upper case, one space between parts.
     * "l  1234 ab" and "L 1234 AB" become the same plate.
     *
     * @param string $plate the plate as typed
     * @return string the cleaned plate
     */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(trim((string)preg_replace('/\s+/', ' ', $plate)));
    }
}