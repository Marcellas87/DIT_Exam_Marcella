<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RentalService.php';
require_once __DIR__ . '/src/helpers.php';

$user = requireLogin('tenant');

$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$rentals = [];
$error = '';
try {
    $service = new RentalService(Database::connect(), new PaymentProofStorage(__DIR__ . '/storage/payment_proofs'));
    $rentals = $service->findByTenant((int)$user['id']);   // only this tenant's rentals
} catch (Throwable $e) {
    error_log((string)$e);   // message + file + stack trace, for debugging
    $error = 'Something went wrong. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My rentals</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav"><a href="index.php">&larr; Back to menu</a></div>
    <h1>My rentals</h1>
    <?php if ($flash !== ''): ?><p class="flash" role="status"><?= escapeHtml($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>

    <?php if ($rentals === []): ?>
        <p class="muted">No rentals yet. <a href="rent.php">Rent a garage</a>.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead>
                <tr><th>Garage</th><th>Vehicle</th><th>Type</th><th>Start</th><th>End</th><th>Charging</th><th>Total</th><th>Proof</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rentals as $rental): ?>
                <tr>
                    <td><?= escapeHtml($rental['garage_id']) ?> (<?= escapeHtml($rental['garage_type']) ?>)</td>
                    <td><?= escapeHtml($rental['brand'] . ' ' . $rental['model']) ?><?= $rental['plate_number'] !== null ? ', ' . escapeHtml($rental['plate_number']) : '' ?></td>
                    <td><?= escapeHtml($rental['rental_type']) ?><?= $rental['days'] !== null ? ' (' . (int)$rental['days'] . ' days)' : '' ?></td>
                    <td><?= escapeHtml($rental['start_date']) ?></td>
                    <td><?= escapeHtml($rental['end_date']) ?></td>
                    <td><?= escapeHtml($rental['charging_fee']) ?></td>
                    <td><?= escapeHtml($rental['total_amount']) ?></td>
                    <td><a href="proof.php?id=<?= (int)$rental['id'] ?>">view</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</main>
</body>
</html>