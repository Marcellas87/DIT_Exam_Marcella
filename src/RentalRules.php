<?php
declare(strict_types=1);

/**
 * Rental rules: day limit, end date, price and charging fee.
 * Pure PHP (no database), so every rule can be unit tested.
 *
 * Money is calculated in whole cents (integers), never with float,
 * so 0.1 + 0.2 style rounding errors cannot happen.
 *
 * ASSUMPTIONS (confirm with the assessor, each is in ONE place so it is easy to change):
 *  1. Start date is today. The end date is the day the garage is free again:
 *     daily   = start + number of days;
 *     monthly = start + number of days in the START month
 *               (ex: 31 Jan 2027 gives 3 Mar, 31 Jan 2028 gives 2 Mar, 21 Nov gives 21 Dec).
 *  2. Daily limit = days in the start month minus 1, so a daily rental is always
 *     shorter than a monthly one (announced separately from the PDF).
 *  3. The full-charge fee is charged ONCE per rental:
 *     battery capacity (kWh) x price per kWh, only for an electric car in an electric garage.
 *  4. Monthly price = 25 x daily price, whatever the month length.
 */
final class RentalRules
{
    public const TYPE_DAILY = 'daily';
    public const TYPE_MONTHLY = 'monthly';
    public const MONTHLY_MULTIPLIER = 25;

    /**
     * Largest number of days for a daily rental starting on $start:
     * days in that month minus 1 (November 2026 gives 29, February 2027 gives 27).
     *
     * @param DateTimeImmutable $start the start date of the rental
     * @return int the largest allowed number of days
     */
    public static function maxDailyDays(DateTimeImmutable $start): int
    {
        return (int)$start->format('t') - 1;
    }

    /**
     * Rules:
     * - rental_type: "daily" or "monthly"
     * - days: required for daily, a whole number from 1 to maxDailyDays();
     *   for monthly it is ignored
     *
     * @param array<string, mixed> $data rental_type, days
     * @param DateTimeImmutable $start the start date (today)
     * @return array<string, string> error message per field; empty array if valid
     */
    public static function validate(array $data, DateTimeImmutable $start): array
    {
        $type = (string)($data['rental_type'] ?? '');
        if ($type !== self::TYPE_DAILY && $type !== self::TYPE_MONTHLY) {
            return ['rental_type' => 'Please choose daily or monthly.'];
        }

        if ($type === self::TYPE_DAILY) {
            $max = self::maxDailyDays($start);
            $days = trim((string)($data['days'] ?? ''));
            if (filter_var($days, FILTER_VALIDATE_INT) === false || (int)$days < 1 || (int)$days > $max) {
                return ['days' => 'Number of days must be a whole number from 1 to ' . $max . ' (this month has ' . $start->format('t') . ' days).'];
            }
        }

        return [];
    }

    /**
     * Calculates the end date (the day the garage is free again).
     *
     * - Daily: start date + number of days.
     * - Monthly: start date + the number of days of the START month
     *            (30 for November, 28 or 29 for February, 31 for January).
     *   Example: 31 January 2027 + 31 days = 3 March 2027.
     *
     * @param DateTimeImmutable $start the start date (time of day is ignored)
     * @param string $rentalType "daily" or "monthly"
     * @param int|null $days number of days (daily only)
     * @return DateTimeImmutable the end date at 00:00
     * @throws InvalidArgumentException if a daily rental has no days, or the type is unknown
     */
    public static function endDate(DateTimeImmutable $start, string $rentalType, ?int $days): DateTimeImmutable
    {
        $start = $start->setTime(0, 0);

        if ($rentalType === self::TYPE_DAILY) {
            if ($days === null || $days < 1) {
                throw new InvalidArgumentException('A daily rental needs a number of days.');
            }
            return $start->modify('+' . $days . ' days');
        }

        if ($rentalType === self::TYPE_MONTHLY) {
            return $start->modify('+' . (int)$start->format('t') . ' days');
        }

        throw new InvalidArgumentException('Unknown rental type.');
    }

    /**
     * Rental price in cents: daily = days x daily price; monthly = 25 x daily price.
     *
     * @param int $dailyCents the garage's daily price in cents
     * @param string $rentalType "daily" or "monthly"
     * @param int|null $days number of days (needed for daily)
     * @return int the rental price in cents (without charging fee)
     * @throws InvalidArgumentException if the type is unknown or a daily rental has no days
     */
    public static function rentalCents(int $dailyCents, string $rentalType, ?int $days): int
    {
        if ($rentalType === self::TYPE_MONTHLY) {
            return self::MONTHLY_MULTIPLIER * $dailyCents;
        }
        if ($rentalType === self::TYPE_DAILY && $days !== null && $days >= 1) {
            return $days * $dailyCents;
        }
        throw new InvalidArgumentException('Invalid rental type or number of days.');
    }

    /**
     * Full-charge fee in cents: battery kWh x price per kWh.
     * Only an electric car in an electric car garage is charged; anything else is 0.
     *
     * @param Vehicle $vehicle the vehicle being parked
     * @param string $garageTypeName "bicycle", "car" or "electric car"
     * @param int|null $kwhPriceCents the garage's price per kWh in cents
     * @return int the charging fee in cents (0 when nothing is charged)
     * @throws InvalidArgumentException if an electric garage has no kWh price
     */
    public static function chargingFeeCents(Vehicle $vehicle, string $garageTypeName, ?int $kwhPriceCents): int
    {
        $battery = $vehicle->batteryHundredths();
        if ($battery === null || $garageTypeName !== Vehicle::GARAGE_ELECTRIC) {
            return 0;
        }
        if ($kwhPriceCents === null) {
            throw new InvalidArgumentException('This electric garage has no kWh price.');
        }
        // battery is in hundredths of kWh, so divide by 100 and round half up.
        return intdiv($battery * $kwhPriceCents + 50, 100);
    }

    /**
     * All amounts of one rental, as text with 2 decimals.
     *
     * @param Vehicle $vehicle the vehicle being parked
     * @param string $garageTypeName "bicycle", "car" or "electric car"
     * @param string $dailyPrice the garage's daily price, like "50000" or "50000.50"
     * @param string|null $kwhPrice the garage's price per kWh (electric garages only)
     * @param string $rentalType "daily" or "monthly"
     * @param int|null $days number of days (daily only)
     * @return array{rental:string,charging:string,total:string}
     * @throws InvalidArgumentException if an amount, the type or the days are invalid
     */
    public static function totals(
        Vehicle $vehicle,
        string $garageTypeName,
        string $dailyPrice,
        ?string $kwhPrice,
        string $rentalType,
        ?int $days
    ): array {
        $rental = self::rentalCents(self::toCents($dailyPrice), $rentalType, $days);
        $charging = self::chargingFeeCents(
            $vehicle,
            $garageTypeName,
            $kwhPrice === null ? null : self::toCents($kwhPrice)
        );

        return [
            'rental'   => self::fromCents($rental),
            'charging' => self::fromCents($charging),
            'total'    => self::fromCents($rental + $charging),
        ];
    }

    /**
     * Turns "50000.50" into 5000050 by reading the text (no float).
     *
     * @param string $amount a plain amount with at most 2 decimals
     * @return int the amount in cents
     * @throws InvalidArgumentException if the text is not a plain amount
     */
    public static function toCents(string $amount): int
    {
        if (preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', trim($amount), $m) !== 1) {
            throw new InvalidArgumentException('Not an amount: ' . $amount);
        }
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }

    /**
     * Turns 5000050 into "50000.50".
     *
     * @param int $cents an amount in cents
     * @return string the amount with 2 decimals
     */
    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}