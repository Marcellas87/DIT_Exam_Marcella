<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit tests for the Vehicle class tree (inheritance and polymorphism)
 * and for VehicleValidator.
 * Test names follow method_condition_expectedResult.
 *
 * @author Marcella
 * @version 1.0
 */
final class VehicleTest extends TestCase
{
    /**
     * Builds a vehicle of a type with valid data.
     *
     * @param string $type the vehicle type code
     * @return Vehicle the new vehicle
     */
    private function createVehicle(string $type): Vehicle
    {
        return Vehicle::createFromInput(['vehicle_type' => $type, 'plate_number' => 'l 1234  ab', 'brand' => 'X', 'model' => 'Y', 'battery_kwh' => '50']);
    }

    /** The factory returns the right subclass. */
    public function testCreateFromInput_validType_returnsMatchingSubclass(): void
    {
        $this->assertInstanceOf(Bicycle::class, $this->createVehicle('bicycle'));
        $this->assertInstanceOf(Motorbike::class, $this->createVehicle('motorbike'));
        $this->assertInstanceOf(Car::class, $this->createVehicle('car'));
        $this->assertInstanceOf(ElectricCar::class, $this->createVehicle('electric_car'));
    }

    /** Bicycles and motorbikes may use bicycle garages only. */
    public function testCanUse_bicycleOrMotorbike_onlyBicycleGarage(): void
    {
        foreach (['bicycle', 'motorbike'] as $type) {
            $vehicle = $this->createVehicle($type);
            $this->assertTrue($vehicle->canUse('bicycle'));
            $this->assertFalse($vehicle->canUse('car'));
            $this->assertFalse($vehicle->canUse('electric car'));
        }
    }

    /** A car may use car garages only. */
    public function testCanUse_car_onlyCarGarage(): void
    {
        $vehicle = $this->createVehicle('car');
        $this->assertTrue($vehicle->canUse('car'));
        $this->assertFalse($vehicle->canUse('electric car'));
        $this->assertFalse($vehicle->canUse('bicycle'));
    }

    /** An electric car may use car and electric garages. */
    public function testCanUse_electricCar_carAndElectricGarage(): void
    {
        $vehicle = $this->createVehicle('electric_car');
        $this->assertTrue($vehicle->canUse('car'));
        $this->assertTrue($vehicle->canUse('electric car'));
        $this->assertFalse($vehicle->canUse('bicycle'));
    }

    /** A bicycle has no plate; plates of other vehicles are cleaned. */
    public function testGetPlateNumber_messyInput_returnsCleanedPlateOrNull(): void
    {
        $this->assertNull($this->createVehicle('bicycle')->getPlateNumber());
        $this->assertSame('L 1234 AB', $this->createVehicle('car')->getPlateNumber());
        $this->assertSame('L 1234 AB', VehicleValidator::normalizePlate("  l   1234 ab "));
    }

    /** An unknown vehicle type is refused by the factory. */
    public function testCreateFromInput_unknownType_throwsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Vehicle::createFromInput(['vehicle_type' => 'boat', 'brand' => 'X', 'model' => 'Y']);
    }

    /** The validator asks for a plate (not for bicycles) and a battery (electric cars only). */
    public function testValidate_missingFields_returnsFieldErrors(): void
    {
        $this->assertSame([], VehicleValidator::validate(['vehicle_type' => 'bicycle', 'brand' => 'X', 'model' => 'Y']));
        $this->assertArrayHasKey('plate_number', VehicleValidator::validate(['vehicle_type' => 'car', 'brand' => 'X', 'model' => 'Y']));
        $this->assertArrayHasKey('battery_kwh', VehicleValidator::validate(['vehicle_type' => 'electric_car', 'plate_number' => 'B 1 XY', 'brand' => 'X', 'model' => 'Y']));
        $this->assertArrayHasKey('vehicle_type', VehicleValidator::validate(['vehicle_type' => '', 'brand' => 'X', 'model' => 'Y']));
    }
}