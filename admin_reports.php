<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ReportInput.php';
require_once __DIR__ . '/src/ReportService.php';
require_once __DIR__ . '/src/helpers.php';

requireLogin('admin');

$now = new DateTimeImmutable('today');
$report = ReportInput::readReport($_GET['report'] ?? 'month');
$year = ReportInput::readYear($_GET['year'] ?? null, (int)$now->format('Y'));
$month = ReportInput::readMonth($_GET['month'] ?? null, (int)$now->format('n'));

// Which filters each report uses: the year for "per month" and "per day", the month only for "per day".
$reportsUsingYear = ['month', 'day'];
$reportsUsingMonth = ['day'];
$showYear = in_array($report, $reportsUsingYear, true);
$showMonth = in_array($report, $reportsUsingMonth, true);

$rows = [];
$error = '';
$titles = [
    'month' => 'Per month in ' . $year,
    'day' => 'Per day in ' . date('F', mktime(0, 0, 0, $month, 1, 2000)) . ' ' . $year,
    'type' => 'Per garage type',
    'tenant' => 'Per tenant',
];
$firstColumn = ['month' => 'Month', 'day' => 'Day', 'type' => 'Garage type', 'tenant' => 'Tenant'][$report];

try {
    $service = new ReportService(Database::connect());
    switch ($report) {
        case 'day':
            $rows = $service->buildDailyReport($year, $month);
            break;
        case 'type':
            $rows = $service->buildGarageTypeReport();
            break;
        case 'tenant':
            $rows = $service->buildTenantReport();
            break;
        default:
            $rows = $service->buildMonthlyReport($year);
    }
} catch (Throwable $e) {
    error_log((string)$e);   // message + file + stack trace, for debugging
    $error = 'Something went wrong. Please try again.';
}
$total = $rows === [] ? null : ReportService::sumRows($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rental reports</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav"><a href="index.php">&larr; Back to menu</a></div>
    <h1>Rental reports</h1>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>

    <form method="get" class="toolbar">
        <select id="report" name="report" aria-label="Report">
            <?php foreach (['month' => 'Per month in a year', 'day' => 'Per day in a month', 'type' => 'Per garage type', 'tenant' => 'Per tenant'] as $value => $label): ?>
                <option value="<?= escapeHtml($value) ?>" <?= $report === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="number" id="year" name="year" value="<?= (int)$year ?>" min="2000" max="2100" aria-label="Year"
            <?= $showYear ? '' : 'style="display:none" disabled' ?>>
        <select id="month" name="month" aria-label="Month" <?= $showMonth ? '' : 'style="display:none" disabled' ?>>
            <?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?>
                <option value="<?= $monthNumber ?>" <?= $month === $monthNumber ? 'selected' : '' ?>><?= escapeHtml(date('F', mktime(0, 0, 0, $monthNumber, 1, 2000))) ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Show</button>
    </form>
    <p class="muted">A rental counts in the period of its start date; extensions count with their rental.</p>

    <h2><?= escapeHtml($titles[$report]) ?></h2>
    <div class="table-wrap"><table>
        <thead><tr><th><?= escapeHtml($firstColumn) ?></th><th>Rentals</th><th>Rental amount</th><th>Extension amount</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= escapeHtml($row['label']) ?></td>
                <td><?= (int)$row['rentals'] ?></td>
                <td><?= escapeHtml($row['rental_amount']) ?></td>
                <td><?= escapeHtml($row['extension_amount']) ?></td>
                <td><?= escapeHtml($row['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($total !== null): ?>
            <tr><th><?= escapeHtml($total['label']) ?></th><th><?= (int)$total['rentals'] ?></th><th><?= escapeHtml($total['rental_amount']) ?></th><th><?= escapeHtml($total['extension_amount']) ?></th><th><?= escapeHtml($total['total']) ?></th></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</main>
<script>
// Shows only the filters the chosen report uses, as soon as the report is changed.
// A hidden filter is also disabled, so it is not sent and the server uses its default.
(function () {
    const reportsUsingYear = <?= json_encode($reportsUsingYear) ?>;
    const reportsUsingMonth = <?= json_encode($reportsUsingMonth) ?>;
    const reportSelect = document.getElementById('report');

    // Shows or hides one filter.
    function showFilter(filterId, isVisible) {
        const filter = document.getElementById(filterId);
        filter.style.display = isVisible ? '' : 'none';
        filter.disabled = !isVisible;
    }

    reportSelect.addEventListener('change', function () {
        showFilter('year', reportsUsingYear.includes(reportSelect.value));
        showFilter('month', reportsUsingMonth.includes(reportSelect.value));
    });
})();
</script>
</body>
</html>