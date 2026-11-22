<?php
declare(strict_types=1);

require_once __DIR__ . '/VehicleValidator.php';
require_once __DIR__ . '/RentalRules.php';

/**
 * Base class of every vehicle, and the garage types a vehicle may use.
 *
 * Class tree (inheritance), one class per file:
 *
 *   Vehicle (abstract)                 Vehicle.php
 *   |-- Bicycle                        Bicycle.php        no plate number
 *   `-- PlatedVehicle (abstract)       PlatedVehicle.php  has a plate number
 *       |-- Motorbike                  Motorbike.php
 *       |-- Car                        Car.php
 *       `-- ElectricCar                ElectricCar.php    also has a battery capacity
 *
 * Polymorphism: every subclass answers getAllowedGarageTypes() in its own way,
 * so the rest of the program just calls $vehicle->canUse($garageType)
 * and never needs an if/else on the vehicle type.
 *
 * Relations: built from form data checked by VehicleValidator; used by RentalService
 * (garage choice) and RentalRules (charging fee).
 *
 * @author Marcella
 * @version 1.0
 */
abstract class Vehicle
{
    /** Values stored in vehicles.vehicle_type. */
    public const TYPE_BICYCLE = 'bicycle';
    public const TYPE_MOTORBIKE = 'motorbike';
    public const TYPE_CAR = 'car';
    public const TYPE_ELECTRIC_CAR = 'electric_car';

    /** Garage type names, as stored in garage_types.name. */
    public const GARAGE_BICYCLE = 'bicycle';
    public const GARAGE_CAR = 'car';
    public const GARAGE_ELECTRIC_CAR = 'electric car';

    private string $brand;
    private string $model;

    /**
     * @param string $brand the brand, for example "Toyota"
     * @param string $model the model, for example "Avanza"
     */
    public function __construct(string $brand, string $model)
    {
        $this->brand = $brand;
        $this->model = $model;
    }

    /**
     * The value stored in vehicles.vehicle_type.
     *
     * @return string "bicycle", "motorbike", "car" or "electric_car"
     */
    abstract public function getTypeCode(): string;

    /**
     * Garage type names this vehicle may be parked in.
     *
     * @return string[] names as stored in garage_types.name
     */
    abstract public function getAllowedGarageTypes(): array;

    /**
     * @return string the brand
     */
    public function getBrand(): string
    {
        return $this->brand;
    }

    /**
     * @return string the model
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Plate number. Bicycles have none, so this base version returns null.
     *
     * @return string|null the plate number, or null
     */
    public function getPlateNumber(): ?string
    {
        return null;
    }

    /**
     * Battery capacity in kWh as text like "40.50". Only electric cars have one.
     *
     * @return string|null the capacity, or null
     */
    public function getBatteryKwh(): ?string
    {
        return null;
    }

    /**
     * Battery capacity in hundredths of a kWh (40.50 kWh gives 4050).
     * Whole numbers are used for money math, so there are no rounding surprises.
     *
     * @return int|null the capacity in hundredths, or null if there is no battery
     */
    public function getBatteryHundredths(): ?int
    {
        $batteryKwh = $this->getBatteryKwh();
        return $batteryKwh === null ? null : RentalRules::convertToCents($batteryKwh);
    }

    /**
     * True if this vehicle may be parked in the garage type.
     *
     * @param string $garageTypeName "bicycle", "car" or "electric car"
     * @return bool true if allowed
     */
    public function canUse(string $garageTypeName): bool
    {
        return in_array($garageTypeName, $this->getAllowedGarageTypes(), true);
    }

    /**
     * Factory: builds the right subclass from VALIDATED form data
     * (run VehicleValidator::validate() first).
     *
     * @param array<string, mixed> $data vehicle_type, plate_number, brand, model, battery_kwh
     * @return Vehicle a Bicycle, Motorbike, Car or ElectricCar
     * @throws InvalidArgumentException for an unknown vehicle type
     */
    public static function createFromInput(array $data): Vehicle
    {
        $brand = trim((string)($data['brand'] ?? ''));
        $model = trim((string)($data['model'] ?? ''));
        $plateNumber = VehicleValidator::normalizePlate((string)($data['plate_number'] ?? ''));

        switch ((string)($data['vehicle_type'] ?? '')) {
            case self::TYPE_BICYCLE:
                return new Bicycle($brand, $model);
            case self::TYPE_MOTORBIKE:
                return new Motorbike($brand, $model, $plateNumber);
            case self::TYPE_CAR:
                return new Car($brand, $model, $plateNumber);
            case self::TYPE_ELECTRIC_CAR:
                return new ElectricCar($brand, $model, $plateNumber, trim((string)($data['battery_kwh'] ?? '')));
        }
        throw new InvalidArgumentException('Unknown vehicle type.');
    }
}

// Load the subclasses, so requiring Vehicle.php gives the whole class tree.
require_once __DIR__ . '/Bicycle.php';
require_once __DIR__ . '/PlatedVehicle.php';
require_once __DIR__ . '/Motorbike.php';
require_once __DIR__ . '/Car.php';
require_once __DIR__ . '/ElectricCar.php';