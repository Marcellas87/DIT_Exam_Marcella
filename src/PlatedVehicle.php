<?php
declare(strict_types=1);

require_once __DIR__ . '/Vehicle.php';

/**
 * A vehicle that has a plate number.
 *
 * Relations: extends Vehicle; parent of Motorbike, Car and ElectricCar.
 *
 * @author Marcella
 * @version 1.0
 */
abstract class PlatedVehicle extends Vehicle
{
    private string $plateNumber;

    /**
     * @param string $brand the brand
     * @param string $model the model
     * @param string $plateNumber the cleaned plate number, for example "L 1234 AB"
     */
    public function __construct(string $brand, string $model, string $plateNumber)
    {
        parent::__construct($brand, $model);
        $this->plateNumber = $plateNumber;
    }

    /**
     * @return string|null the plate number
     */
    public function getPlateNumber(): ?string
    {
        return $this->plateNumber;
    }
}