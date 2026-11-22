<?php
declare(strict_types=1);

/**
 * Reads the year and month typed in the report filters. No database code.
 *
 * Relations: used by admin_reports.php before calling ReportService.
 *
 * @author Marcella
 * @version 1.0
 */
final class ReportInput
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;

    /** Report names accepted from the URL. */
    public const REPORTS = ['month', 'day', 'type', 'tenant'];

    /**
     * Turns text into a valid year; anything else becomes the default.
     *
     * @param mixed $value the value from the URL, like "2026"
     * @param int $default the year to use when the value is not valid
     * @return int a year from 2000 to 2100
     */
    public static function readYear($value, int $default): int
    {
        $year = filter_var($value, FILTER_VALIDATE_INT);
        return ($year !== false && $year >= self::MIN_YEAR && $year <= self::MAX_YEAR) ? $year : $default;
    }

    /**
     * Turns text into a valid month number; anything else becomes the default.
     *
     * @param mixed $value the value from the URL, like "11"
     * @param int $default the month to use when the value is not valid
     * @return int a month from 1 to 12
     */
    public static function readMonth($value, int $default): int
    {
        $month = filter_var($value, FILTER_VALIDATE_INT);
        return ($month !== false && $month >= 1 && $month <= 12) ? $month : $default;
    }

    /**
     * Chooses the report to show; an unknown name becomes "month".
     *
     * @param mixed $value the value from the URL
     * @return string one of: month, day, type, tenant
     */
    public static function readReport($value): string
    {
        return in_array($value, self::REPORTS, true) ? (string)$value : 'month';
    }
}