<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

$user = requireLogin();               // any logged-in user
$name = $user['full_name'] ?? $user['email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Garage Rental</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav">
        <strong>Garage Rental</strong>
        <form method="post" action="logout.php"><button type="submit" class="btn-plain">Log out</button></form>
    </div>

    <h1>Hello, <?= escapeHtml($name) ?></h1>
    <p class="muted">You are logged in as <strong><?= escapeHtml($user['role']) ?></strong>.</p>

    <?php if ($user['role'] === 'admin'): ?>
        <ul>
            <li><a href="admin_tenants.php">Verify new tenants</a></li>
            <li><a href="admin_garage.php">Create garages</a></li>
            <li><a href="admin_extend.php">Extend a rental</a></li>
            <li><a href="admin_reports.php">Rental reports</a></li>
        </ul>
    <?php else: ?>
        <ul>
            <li><a href="rent.php">Rent a garage</a></li>
            <li><a href="my_rentals.php">My rentals</a></li>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>