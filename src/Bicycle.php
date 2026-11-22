<?php
declare(strict_types=1);

require_once __DIR__ . '/Vehicle.php';

/**
 * A bicycle: no plate number, only bicycle garages.
 *
 * Relations: extends Vehicle.
 *
 * @author Marcella
 * @version 1.0
 */
final class Bicycle extends Vehicle
{
    /**
     * @return string "bicycle"
     */
    public function getTypeCode(): string
    {
        return self::TYPE_BICYCLE;
    }

    /**
     * @return string[] bicycle garages only
     */
    public function getAllowedGarageTypes(): array
    {
        return [self::GARAGE_BICYCLE];
    }
}