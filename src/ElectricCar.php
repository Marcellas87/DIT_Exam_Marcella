<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatedVehicle.php';

/**
 * An electric car: car garages or electric car garages.
 * In an electric car garage it is charged to full (see RentalRules::calculateChargingFeeCents).
 *
 * Relations: extends PlatedVehicle.
 *
 * @author Marcella
 * @version 1.0
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
    public function getTypeCode(): string
    {
        return self::TYPE_ELECTRIC_CAR;
    }

    /**
     * @return string[] car garages and electric car garages
     */
    public function getAllowedGarageTypes(): array
    {
        return [self::GARAGE_CAR, self::GARAGE_ELECTRIC_CAR];
    }

    /**
     * @return string|null the battery capacity in kWh
     */
    public function getBatteryKwh(): ?string
    {
        return $this->batteryKwh;
    }
}