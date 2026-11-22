<?php
declare(strict_types=1);

// Library   : phpunit/phpunit (installed 12.5.45; allowed range in composer.json; license: BSD-3-Clause)
// Function  : unit-testing framework (test classes, assertions, expected exceptions)
// Reason    : the standard PHP test tool, development only. The app does not need it to run
// Install   : composer install   (listed under "require-dev" in composer.json; PHPUnit 12 needs PHP 8.3 or newer)
// Docs      : https://docs.phpunit.de
// Example   : $this->assertSame(6000000, RentalRules::calculateRentalCents(2000000, 'daily', 3));
//             run with:  php vendor/bin/phpunit
//
// Loads the classes under test. The classes need no database, so no connection is made.
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../../src/Vehicle.php';   // also loads Bicycle, PlatedVehicle, Motorbike, Car, ElectricCar
require_once __DIR__ . '/../../src/VehicleValidator.php';
require_once __DIR__ . '/../../src/RentalRules.php';
require_once __DIR__ . '/../../src/GarageId.php';
require_once __DIR__ . '/../../src/ReportInput.php';