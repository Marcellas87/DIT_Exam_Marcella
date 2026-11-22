<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatedVehicle.php';

/**
 * A petrol car: car garages only.
 *
 * Relations: extends PlatedVehicle.
 *
 * @author Marcella
 * @version 1.0
 */
final class Car extends PlatedVehicle
{
    /**
     * @return string "car"
     */
    public function getTypeCode(): string
    {
        return self::TYPE_CAR;
    }

    /**
     * @return string[] car garages only
     */
    public function getAllowedGarageTypes(): array
    {
        return [self::GARAGE_CAR];
    }
}