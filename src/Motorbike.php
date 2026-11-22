<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatedVehicle.php';

/**
 * A motorbike is parked in bicycle garages.
 *
 * Relations: extends PlatedVehicle.
 *
 * @author Marcella
 * @version 1.0
 */
final class Motorbike extends PlatedVehicle
{
    /**
     * @return string "motorbike"
     */
    public function getTypeCode(): string
    {
        return self::TYPE_MOTORBIKE;
    }

    /**
     * @return string[] bicycle garages only
     */
    public function getAllowedGarageTypes(): array
    {
        return [self::GARAGE_BICYCLE];
    }
}