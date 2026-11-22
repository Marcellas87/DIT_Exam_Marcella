<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit tests for garage IDs and the report filter reader.
 * Test names follow method_condition_expectedResult.
 *
 * @author Marcella
 * @version 1.0
 */
final class GarageIdAndReportInputTest extends TestCase
{
    /** IDs are letter + 3 digits. */
    public function testFormatId_numberInRange_returnsLetterPlus3Digits(): void
    {
        $this->assertSame('S001', GarageId::formatId('S', 1));
        $this->assertSame('C010', GarageId::formatId('C', 10));
        $this->assertSame('E999', GarageId::formatId('E', 999));
        $this->assertSame(10, GarageId::getNumberOf('C010'));
    }

    /** Each garage type has its own letter. */
    public function testGetPrefixFor_eachType_returnsItsLetter(): void
    {
        $this->assertSame('S', GarageId::getPrefixFor('bicycle'));
        $this->assertSame('C', GarageId::getPrefixFor('car'));
        $this->assertSame('E', GarageId::getPrefixFor('electric car'));
    }

    /** Number 1000 does not fit in three digits. */
    public function testFormatId_number1000_throwsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GarageId::formatId('S', 1000);
    }

    /** Bad report filters fall back to defaults instead of reaching the SQL. */
    public function testReadYearMonthReport_invalidInput_returnsDefaults(): void
    {
        $this->assertSame(2026, ReportInput::readYear('abc', 2026));
        $this->assertSame(2026, ReportInput::readYear('2026 OR 1=1', 2026));
        $this->assertSame(2030, ReportInput::readYear('2030', 2026));
        $this->assertSame(11, ReportInput::readMonth('13', 11));
        $this->assertSame('month', ReportInput::readReport('drop table'));
    }
}