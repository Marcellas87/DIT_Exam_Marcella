<?php
declare(strict_types=1);

require_once __DIR__ . '/Vehicle.php';

/**
 * Garage ID rules: one letter per garage type + a 3-digit number (S001, C010, E999).
 * Pure PHP (no database), so it is easy to unit test.
 *
 * Relations: uses the garage type constants of Vehicle; used by GarageService.
 *
 * @author Marcella
 * @version 1.0
 */
final class GarageId
{
    /** The 3-digit number cannot go above 999. */
    public const MAX_NUMBER = 999;

    /** Garage type name (as stored in garage_types) => ID letter. */
    private const PREFIXES = [
        Vehicle::GARAGE_BICYCLE      => 'S',
        Vehicle::GARAGE_CAR          => 'C',
        Vehicle::GARAGE_ELECTRIC_CAR => 'E',
    ];

    /**
     * Returns the ID letter of a garage type.
     *
     * @param string $typeName "bicycle", "car" or "electric car"
     * @return string "S", "C" or "E"
     * @throws InvalidArgumentException for any other name
     */
    public static function getPrefixFor(string $typeName): string
    {
        if (!isset(self::PREFIXES[$typeName])) {
            throw new InvalidArgumentException('Unknown garage type: ' . $typeName);
        }
        return self::PREFIXES[$typeName];
    }

    /**
     * Builds an ID, for example formatId('C', 10) gives "C010".
     *
     * @param string $prefix the type letter ("S", "C" or "E")
     * @param int $number the running number, from 1 to 999
     * @return string the garage ID, such as "S001"
     * @throws InvalidArgumentException if the number is not from 1 to 999
     */
    public static function formatId(string $prefix, int $number): string
    {
        if ($number < 1 || $number > self::MAX_NUMBER) {
            throw new InvalidArgumentException('Garage number must be from 1 to ' . self::MAX_NUMBER . '.');
        }
        return sprintf('%s%03d', $prefix, $number);
    }

    /**
     * Reads the number of an ID, for example getNumberOf("C010") gives 10.
     *
     * @param string $id a garage ID such as "C010"
     * @return int the running number (10)
     * @throws InvalidArgumentException if the ID has the wrong shape
     */
    public static function getNumberOf(string $id): int
    {
        if (preg_match('/^[SCE][0-9]{3}$/', $id) !== 1) {
            throw new InvalidArgumentException('Not a garage ID: ' . $id);
        }
        return (int)substr($id, 1);
    }
}