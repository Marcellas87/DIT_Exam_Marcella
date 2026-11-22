<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ExtensionService.php';
require_once __DIR__ . '/src/RentalService.php';
require_once __DIR__ . '/src/helpers.php';

$admin = requireLogin('admin');
$today = new DateTimeImmutable('today');

$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$error = '';
$errors = [];
$active = [];
$selected = null;
$preview = null;
$newEnd = trim((string)($_POST['new_end_date'] ?? ''));

try {
    $pdo = Database::connect();
    $storage = new PaymentProofStorage(__DIR__ . '/storage/payment_proofs');
    (new RentalService($pdo, $storage))->refreshGarageStatuses($today);
    $service = new ExtensionService($pdo, $storage);

    $rentalId = (int)($_POST['rental_id'] ?? $_GET['rental'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isValidCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $error = 'Invalid form token. Please reload the page and try again.';
        } elseif (($_POST['action'] ?? '') === 'save') {
            $result = $service->extendRental((int)$admin['id'], $rentalId, $newEnd, $_FILES['proof'] ?? null, $today);
            if ($result['errors'] === []) {
                $_SESSION['flash'] = 'Rental extended.';
                header('Location: admin_extend.php');
                exit;
            }
            $errors = $result['errors'];
        } else {
            $calc = $service->calculateExtraAmount($rentalId, $newEnd, $today);   // "Calculate" button
            if ($calc['error'] !== null) {
                $errors['new_end_date'] = $calc['error'];
            } else {
                $preview = $calc;
            }
        }
    }

    $active = $service->findActiveRentals($today);
    $selected = $rentalId > 0 ? $service->findActiveRental($rentalId, $today) : null;
} catch (Throwable $e) {
    error_log((string)$e);   // message + file + stack trace, for debugging
    $error = 'Something went wrong. Please try again.';
}

$token = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extend a rental</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav"><a href="index.php">&larr; Back to menu</a></div>
    <h1>Extend a rental</h1>
    <?php if ($flash !== ''): ?><p class="flash" role="status"><?= escapeHtml($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
    <?php if (isset($errors['form'])): ?><p class="flash error" role="alert"><?= escapeHtml($errors['form']) ?></p><?php endif; ?>

    <h2>Garages rented today (<?= escapeHtml($today->format('d M Y')) ?>)</h2>
    <?php if ($active === []): ?>
        <p class="muted">No garage is rented today.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Garage</th><th>Tenant</th><th>Vehicle</th><th>Start</th><th>End</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($active as $rental): ?>
                <tr>
                    <td><?= escapeHtml($rental['garage_id']) ?> (<?= escapeHtml($rental['garage_type']) ?>)</td>
                    <td><?= escapeHtml($rental['tenant_name']) ?></td>
                    <td><?= escapeHtml($rental['vehicle']) ?></td>
                    <td><?= escapeHtml($rental['start_date']) ?></td>
                    <td><?= escapeHtml($rental['end_date']) ?></td>
                    <td><a href="admin_extend.php?rental=<?= (int)$rental['id'] ?>">Select</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if ($selected !== null): ?>
        <h2>Extend garage <?= escapeHtml($selected['garage_id']) ?></h2>
        <p>Tenant: <?= escapeHtml($selected['tenant_name']) ?><br>
           Vehicle: <?= escapeHtml($selected['vehicle']) ?><br>
           Current end date: <strong><?= escapeHtml($selected['end_date']) ?></strong><br>
           Price per extra day: <?= escapeHtml($selected['daily_price_used']) ?></p>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= escapeHtml($token) ?>">
            <input type="hidden" name="rental_id" value="<?= (int)$selected['id'] ?>">

            <label for="new_end_date">New end date</label>
            <input type="date" id="new_end_date" name="new_end_date" value="<?= escapeHtml($newEnd) ?>">
            <?php if (isset($errors['new_end_date'])): ?><p class="error-text"><?= escapeHtml($errors['new_end_date']) ?></p><?php endif; ?>

            <?php if ($preview !== null): ?>
                <p>Extra days: <?= (int)$preview['extra_days'] ?><br>
                   <strong>Remaining amount to pay: <?= escapeHtml($preview['extra_amount']) ?></strong></p>
            <?php endif; ?>

            <label for="proof">Payment proof (JPG or PNG, maximum 2 MB)</label>
            <input type="file" id="proof" name="proof" accept="image/jpeg,image/png">
            <?php if (isset($errors['proof'])): ?><p class="error-text"><?= escapeHtml($errors['proof']) ?></p><?php endif; ?>

            <div class="toolbar">
                <button type="submit" name="action" value="preview">Calculate amount</button>
                <button type="submit" name="action" value="save">Save extension</button>
            </div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>