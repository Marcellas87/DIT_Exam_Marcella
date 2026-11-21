<?php
declare(strict_types=1);

require_once __DIR__ . '/VehicleValidator.php';
require_once __DIR__ . '/RentalRules.php';

/**
 * Vehicles and the garage types they may use.
 *
 * Class tree (inheritance):
 *
 *   Vehicle (abstract)
 *   |-- Bicycle                    no plate number
 *   `-- PlatedVehicle (abstract)   has a plate number
 *       |-- Motorbike
 *       |-- Car
 *       `-- ElectricCar            also has a battery capacity
 *
 * Polymorphism: every subclass answers allowedGarageTypes() in its own way,
 * so the rest of the program just calls $vehicle->canUse($garageType)
 * and never needs an if/else on the vehicle type.
 *
 * All classes live in this one file to keep the project small.
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
    public const GARAGE_ELECTRIC = 'electric car';

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
    abstract public function typeCode(): string;

    /**
     * Garage type names this vehicle may be parked in.
     *
     * @return string[] names as stored in garage_types.name
     */
    abstract public function allowedGarageTypes(): array;

    /**
     * @return string the brand
     */
    public function brand(): string
    {
        return $this->brand;
    }

    /**
     * @return string the model
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * Plate number. Bicycles have none, so this base version returns null.
     *
     * @return string|null the plate number, or null
     */
    public function plateNumber(): ?string
    {
        return null;
    }

    /**
     * Battery capacity in kWh as text like "40.50". Only electric cars have one.
     *
     * @return string|null the capacity, or null
     */
    public function batteryKwh(): ?string
    {
        return null;
    }

    /**
     * Battery capacity in hundredths of a kWh (40.50 kWh gives 4050).
     * Whole numbers are used for money math, so there are no rounding surprises.
     *
     * @return int|null the capacity in hundredths, or null if there is no battery
     */
    public function batteryHundredths(): ?int
    {
        $kwh = $this->batteryKwh();
        return $kwh === null ? null : RentalRules::toCents($kwh);
    }

    /**
     * True if this vehicle may be parked in the garage type.
     *
     * @param string $garageTypeName "bicycle", "car" or "electric car"
     * @return bool true if allowed
     */
    public function canUse(string $garageTypeName): bool
    {
        return in_array($garageTypeName, $this->allowedGarageTypes(), true);
    }

    /**
     * Factory: builds the right subclass from VALIDATED form data
     * (run VehicleValidator::validate() first).
     *
     * @param array<string, mixed> $data vehicle_type, plate_number, brand, model, battery_kwh
     * @return Vehicle a Bicycle, Motorbike, Car or ElectricCar
     * @throws InvalidArgumentException for an unknown vehicle type
     */
    public static function fromInput(array $data): Vehicle
    {
        $brand = trim((string)($data['brand'] ?? ''));
        $model = trim((string)($data['model'] ?? ''));
        $plate = VehicleValidator::normalizePlate((string)($data['plate_number'] ?? ''));

        switch ((string)($data['vehicle_type'] ?? '')) {
            case self::TYPE_BICYCLE:
                return new Bicycle($brand, $model);
            case self::TYPE_MOTORBIKE:
                return new Motorbike($brand, $model, $plate);
            case self::TYPE_CAR:
                return new Car($brand, $model, $plate);
            case self::TYPE_ELECTRIC_CAR:
                return new ElectricCar($brand, $model, $plate, trim((string)($data['battery_kwh'] ?? '')));
        }
        throw new InvalidArgumentException('Unknown vehicle type.');
    }
}

/**
 * A bicycle: no plate number, only bicycle garages.
 */
final class Bicycle extends Vehicle
{
    /**
     * @return string "bicycle"
     */
    public function typeCode(): string
    {
        return self::TYPE_BICYCLE;
    }

    /**
     * @return string[] bicycle garages only
     */
    public function allowedGarageTypes(): array
    {
        return [self::GARAGE_BICYCLE];
    }
}

/**
 * A vehicle that has a plate number.
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
    public function plateNumber(): ?string
    {
        return $this->plateNumber;
    }
}

/**
 * A motorbike is parked in bicycle garages.
 */
final class Motorbike extends PlatedVehicle
{
    /**
     * @return string "motorbike"
     */
    public function typeCode(): string
    {
        return self::TYPE_MOTORBIKE;
    }

    /**
     * @return string[] bicycle garages only
     */
    public function allowedGarageTypes(): array
    {
        return [self::GARAGE_BICYCLE];
    }
}

/**
 * A petrol car: car garages only.
 */
final class Car extends PlatedVehicle
{
    /**
     * @return string "car"
     */
    public function typeCode(): string
    {
        return self::TYPE_CAR;
    }

    /**
     * @return string[] car garages only
     */
    public function allowedGarageTypes(): array
    {
        return [self::GARAGE_CAR];
    }
}

/**
 * An electric car: car garages or electric car garages.
 */
final class ElectricCar extends PlatedVehicle
{
    private string $batteryKwh;

    /**
     * @param string $brand the brand
     * @param string $model the model
     * @param string $plateNumber the cleaned plate number
     * @param string $batteryKwh the battery capacity in kWh, for example "72.6"
     */
    public function __construct(string $brand, string $model, string $plateNumber, string $batteryKwh)
    {
        parent::__construct($brand, $model, $plateNumber);
        $this->batteryKwh = $batteryKwh;
    }

    /**
     * @return string "electric_car"
     */
    public function typeCode(): string
    {
        return self::TYPE_ELECTRIC_CAR;
    }

    /**
     * @return string[] car garages and electric car garages
     */
    public function allowedGarageTypes(): array
    {
        return [self::GARAGE_CAR, self::GARAGE_ELECTRIC];
    }

    /**
     * @return string|null the battery capacity in kWh
     */
    public function batteryKwh(): ?string
    {
        return $this->batteryKwh;
    }
}