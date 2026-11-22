<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit tests for the money and date rules in RentalRules.
 * Test names follow method_condition_expectedResult.
 *
 * Relations: tests RentalRules; builds an ElectricCar through Vehicle::createFromInput().
 *
 * @author Marcella
 * @version 1.1
 */
final class RentalRulesTest extends TestCase
{
    /** Daily price is days x daily price, calculated in cents. */
    public function testCalculateRentalCents_daily3Days_returnsDaysTimesPrice(): void
    {
        $this->assertSame(6000000, RentalRules::calculateRentalCents(2000000, 'daily', 3));
    }

    /** Monthly price is 25 x the daily price. */
    public function testCalculateRentalCents_monthly_returns25TimesDaily(): void
    {
        $this->assertSame(50000000, RentalRules::calculateRentalCents(2000000, 'monthly', null));
    }

    /** A daily rental without days is a programming error. */
    public function testCalculateRentalCents_dailyWithoutDays_throwsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RentalRules::calculateRentalCents(2000000, 'daily', null);
    }

    /** Money text is read without float rounding, and written back with 2 decimals. */
    public function testConvertToCents_amountText_roundTripsWithFormatCents(): void
    {
        $this->assertSame(5000050, RentalRules::convertToCents('50000.50'));
        $this->assertSame('50000.50', RentalRules::formatCents(5000050));
        $this->assertSame('0.05', RentalRules::formatCents(5));
    }

    /** Text that is not an amount is refused. */
    public function testConvertToCents_commaDecimal_throwsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RentalRules::convertToCents('12,5');
    }

    /** The daily limit is the days of the start month minus 1. */
    public function testGetMaxDailyDays_startMonth_returnsMonthDaysMinusOne(): void
    {
        $this->assertSame(29, RentalRules::getMaxDailyDays(new DateTimeImmutable('2026-11-21')));
        $this->assertSame(27, RentalRules::getMaxDailyDays(new DateTimeImmutable('2027-02-10')));
        $this->assertSame(30, RentalRules::getMaxDailyDays(new DateTimeImmutable('2026-12-01')));
    }

    /** A daily end date is the start date plus the days. */
    public function testCalculateEndDate_daily5Days_returnsStartPlus5(): void
    {
        $end = RentalRules::calculateEndDate(new DateTimeImmutable('2026-11-21'), 'daily', 5);
        $this->assertSame('2026-11-26', $end->format('Y-m-d'));
    }

    /** A monthly end date is the start date plus the days of the start month. */
    public function testCalculateEndDate_monthly_addsDaysOfStartMonth(): void
    {
        $this->assertSame('2026-12-21', RentalRules::calculateEndDate(new DateTimeImmutable('2026-11-21'), 'monthly', null)->format('Y-m-d'));
        $this->assertSame('2027-03-03', RentalRules::calculateEndDate(new DateTimeImmutable('2027-01-31'), 'monthly', null)->format('Y-m-d'));
        $this->assertSame('2028-03-02', RentalRules::calculateEndDate(new DateTimeImmutable('2028-01-31'), 'monthly', null)->format('Y-m-d'));
    }

    /** A longer daily rental than the limit is refused with an error on "days". */
    public function testValidate_daysOverLimit_returnsDaysError(): void
    {
        $errors = RentalRules::validate(['rental_type' => 'daily', 'days' => '30'], new DateTimeImmutable('2026-11-21'));
        $this->assertArrayHasKey('days', $errors);
        $this->assertSame([], RentalRules::validate(['rental_type' => 'daily', 'days' => '29'], new DateTimeImmutable('2026-11-21')));
    }

    /** An extension must end later than the current end date. */
    public function testValidateNewEnd_sameOrImpossibleDate_returnsError(): void
    {
        $old = new DateTimeImmutable('2026-11-25');
        $this->assertNull(RentalRules::validateNewEnd('2026-11-26', $old));
        $this->assertNotNull(RentalRules::validateNewEnd('2026-11-25', $old));
        $this->assertNotNull(RentalRules::validateNewEnd('2026-02-30', $old));
    }

    /** Extra days are charged at the daily price. */
    public function testCalculateExtensionCents_4ExtraDays_returnsDaysTimesPrice(): void
    {
        $old = new DateTimeImmutable('2026-11-25');
        $this->assertSame(8000000, RentalRules::calculateExtensionCents(2000000, $old, new DateTimeImmutable('2026-11-29')));
    }

    /** An earlier end date would give a negative price, so it is refused. */
    public function testCalculateExtensionCents_earlierEndDate_throwsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RentalRules::calculateExtensionCents(2000000, new DateTimeImmutable('2026-11-25'), new DateTimeImmutable('2026-11-20'));
    }

    /** The charging fee applies only to an electric car in an electric garage. */
    public function testCalculateChargingFeeCents_electricCar_chargedOnlyInElectricGarage(): void
    {
        $electricCar = Vehicle::createFromInput([
            'vehicle_type' => 'electric_car', 'plate_number' => 'B 1 XY',
            'brand' => 'BYD', 'model' => 'Atto 3', 'battery_kwh' => '40',
        ]);
        $this->assertSame(10002000, RentalRules::calculateChargingFeeCents($electricCar, 'electric car', 250050));
        $this->assertSame(0, RentalRules::calculateChargingFeeCents($electricCar, 'car', 250050));
    }
}