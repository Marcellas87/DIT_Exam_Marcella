<?php
declare(strict_types=1);

require_once __DIR__ . '/RentalRules.php';

/**
 * Rental reports for the admin (read-only). Every report is ONE grouped query (GROUP BY).
 *
 * ASSUMPTIONS:
 *  - A rental belongs to the period of its START date.
 *  - Extensions are added to the period of the rental they extend
 *    (rental_extensions has no date column of its own).
 *  - "Rental amount" = rentals.total_amount (rental price + charging fee);
 *    "Extension amount" = the sum of rental_extensions.extra_amount;
 *    "Total" = both together.
 *
 * Every row has the keys: label, rentals (count), rental_amount, extension_amount, total (texts with 2 decimals).
 *
 * Relations: reads rentals, rental_extensions, garages, garage_types and tenants;
 * uses RentalRules for cent-based money sums.
 *
 * @author Marcella
 * @version 1.0
 */
final class ReportService
{
    private PDO $pdo;

    /**
     * @param PDO $pdo an open database connection
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Report per month in a year: always 12 rows (months without rentals show 0).
     *
     * @param int $year the year, like 2026
     * @return array<int, array<string, string|int>> 12 rows, January first
     */
    public function buildMonthlyReport(int $year): array
    {
        $rows = $this->runGroupedQuery(
            'MONTH(r.start_date) AS group_key',
            'r.start_date >= ? AND r.start_date < ?',
            [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]
        );
        $labels = [];
        for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
            $labels[$monthNumber] = date('F', mktime(0, 0, 0, $monthNumber, 1, 2000));
        }
        return $this->fillEmptyGroups($labels, $rows);
    }

    /**
     * Report per day in a month: one row for every day of the month.
     *
     * @param int $year the year
     * @param int $month the month, 1 to 12
     * @return array<int, array<string, string|int>> one row per day
     */
    public function buildDailyReport(int $year, int $month): array
    {
        $firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $rows = $this->runGroupedQuery(
            'DAY(r.start_date) AS group_key',
            'r.start_date >= ? AND r.start_date < ?',
            [$firstDay->format('Y-m-d'), $firstDay->modify('+1 month')->format('Y-m-d')]
        );
        $labels = [];
        for ($dayNumber = 1; $dayNumber <= (int)$firstDay->format('t'); $dayNumber++) {
            $labels[$dayNumber] = $firstDay->setDate($year, $month, $dayNumber)->format('d M Y');
        }
        return $this->fillEmptyGroups($labels, $rows);
    }

    /**
     * Report per garage type (all time): one row per type, also types without rentals.
     *
     * @return array<int, array<string, string|int>> one row per garage type
     */
    public function buildGarageTypeReport(): array
    {
        $types = $this->pdo->query('SELECT id, name FROM garage_types ORDER BY id')->fetchAll();
        $labels = [];
        foreach ($types as $type) {
            $labels[(int)$type['id']] = (string)$type['name'];
        }
        return $this->fillEmptyGroups($labels, $this->runGroupedQuery('g.garage_type_id AS group_key', '1 = 1', []));
    }

    /**
     * Report per tenant (all time): one row per accepted tenant, also tenants without rentals.
     *
     * @return array<int, array<string, string|int>> one row per tenant, sorted by name
     */
    public function buildTenantReport(): array
    {
        $tenants = $this->pdo->query(
            "SELECT user_id, full_name FROM tenants WHERE registration_status = 'accepted' ORDER BY full_name, user_id"
        )->fetchAll();
        $labels = [];
        foreach ($tenants as $tenant) {
            $labels[(int)$tenant['user_id']] = (string)$tenant['full_name'];
        }
        return $this->fillEmptyGroups($labels, $this->runGroupedQuery('r.tenant_id AS group_key', '1 = 1', []));
    }

    /**
     * Column sums of a report, as one extra row.
     *
     * @param array<int, array<string, string|int>> $rows rows of any report
     * @return array<string, string|int> the keys label, rentals, rental_amount, extension_amount, total
     */
    public static function sumRows(array $rows): array
    {
        $rentalCount = 0;
        $rentalCents = 0;
        $extensionCents = 0;
        foreach ($rows as $row) {
            $rentalCount += (int)$row['rentals'];
            $rentalCents += RentalRules::convertToCents((string)$row['rental_amount']);
            $extensionCents += RentalRules::convertToCents((string)$row['extension_amount']);
        }
        return [
            'label' => 'Total', 'rentals' => $rentalCount,
            'rental_amount' => RentalRules::formatCents($rentalCents),
            'extension_amount' => RentalRules::formatCents($extensionCents),
            'total' => RentalRules::formatCents($rentalCents + $extensionCents),
        ];
    }

    /**
     * Runs the grouped query shared by all reports.
     * Extensions are summed per rental first, so a rental with several extensions is still counted once.
     *
     * @param string $groupExpr SQL expression aliased "group_key" (written by this class, never by the user)
     * @param string $where SQL condition with "?" placeholders (written by this class)
     * @param array<int, string> $args values for the placeholders
     * @return array<int, array<string, mixed>> rows: group_key, rentals, rental_amount, extension_amount
     */
    private function runGroupedQuery(string $groupExpr, string $where, array $args): array
    {
        $alias = explode(' AS ', $groupExpr)[1];
        $sql = "SELECT $groupExpr,
                       COUNT(r.id) AS rentals,
                       COALESCE(SUM(r.total_amount), 0) AS rental_amount,
                       COALESCE(SUM(ext.extra), 0) AS extension_amount
                FROM rentals r
                JOIN garages g ON g.id = r.garage_id
                LEFT JOIN (SELECT rental_id, SUM(extra_amount) AS extra FROM rental_extensions GROUP BY rental_id) ext
                       ON ext.rental_id = r.id
                WHERE $where
                GROUP BY $alias";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * Puts query rows under their labels, so empty groups show zeros.
     *
     * @param array<int, string> $labels group key => label, in display order
     * @param array<int, array<string, mixed>> $rows rows from runGroupedQuery()
     * @return array<int, array<string, string|int>> one row per label
     */
    private function fillEmptyGroups(array $labels, array $rows): array
    {
        $rowsByKey = [];
        foreach ($rows as $row) {
            $rowsByKey[(int)$row['group_key']] = $row;
        }
        $result = [];
        foreach ($labels as $key => $label) {
            $row = $rowsByKey[$key] ?? null;
            $rentalCents = RentalRules::convertToCents($row === null ? '0' : (string)$row['rental_amount']);
            $extensionCents = RentalRules::convertToCents($row === null ? '0' : (string)$row['extension_amount']);
            $result[] = [
                'label' => $label,
                'rentals' => $row === null ? 0 : (int)$row['rentals'],
                'rental_amount' => RentalRules::formatCents($rentalCents),
                'extension_amount' => RentalRules::formatCents($extensionCents),
                'total' => RentalRules::formatCents($rentalCents + $extensionCents),
            ];
        }
        return $result;
    }
}