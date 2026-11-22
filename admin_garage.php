<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/GarageService.php';
require_once __DIR__ . '/src/helpers.php';

$admin = requireLogin('admin');   // starts the session too

// One-time message saved by the previous request (Post/Redirect/Get).
$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);

$old = ['garage_type_id' => '', 'count' => '', 'daily_price' => '', 'kwh_price' => ''];
$errors = [];
$error = '';
$types = [];
$summary = [];

try {
    $service = new GarageService(Database::connect());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isValidCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $error = 'Invalid form token. Please reload the page and try again.';
        } else {
            $input = [];
            foreach (array_keys($old) as $field) {
                $input[$field] = (string)($_POST[$field] ?? '');
            }
            $old = $input;

            $result = $service->createBatch($input);
            if (count($result['errors']) === 0) {
                $_SESSION['flash'] = $result['count'] === 1
                    ? 'Created 1 garage: ' . $result['first'] . '.'
                    : 'Created ' . $result['count'] . ' garages: ' . $result['first'] . ' to ' . $result['last'] . '.';
                header('Location: admin_garage.php');
                exit;
            }
            $errors = $result['errors'];
        }
    }

    $types = $service->findTypes();
    $summary = $service->findSummary();
} catch (Throwable $e) {
    error_log((string)$e);   // message + file + stack trace, for debugging
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.';
}

$token = getCsrfToken();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create garages</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav">
        <a href="index.php">&larr; Back to menu</a>
        <form method="post" action="logout.php"><button type="submit" class="btn-plain">Log out</button></form>
    </div>

    <h1>Create garages</h1>
    <p class="muted">IDs are made automatically: S + 3 digits for bicycle, C for car, E for electric car. Numbering continues from the last garage of the same type.</p>

    <?php if ($flash !== ''): ?><p class="flash" role="status"><?= escapeHtml($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>

    <form method="post" class="card stack" novalidate>
        <input type="hidden" name="csrf" value="<?= escapeHtml($token) ?>">

        <div class="field">
            <label for="garage_type_id">Garage type</label>
            <select id="garage_type_id" name="garage_type_id" required>
                <option value="">Choose a type</option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= (int)$type['id'] ?>"
                            data-electric="<?= $type['name'] === Vehicle::GARAGE_ELECTRIC_CAR ? '1' : '0' ?>"
                            <?= (string)$old['garage_type_id'] === (string)$type['id'] ? 'selected' : '' ?>>
                        <?= escapeHtml(ucfirst($type['name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php printFieldError($errors, 'garage_type_id'); ?>
        </div>

        <div class="field">
            <label for="count">Number of garages (1 to 999)</label>
            <input type="number" id="count" name="count" min="1" max="999" value="<?= escapeHtml($old['count']) ?>" required>
            <?php printFieldError($errors, 'count'); ?>
        </div>

        <div class="field">
            <label for="daily_price">Daily rental price</label>
            <input type="text" id="daily_price" name="daily_price" inputmode="decimal" value="<?= escapeHtml($old['daily_price']) ?>" required>
            <p class="muted">The monthly price is always 25 &times; the daily price.</p>
            <?php printFieldError($errors, 'daily_price'); ?>
        </div>

        <div class="field" id="kwh-field">
            <label for="kwh_price">Electricity price per kWh (electric car garages only)</label>
            <input type="text" id="kwh_price" name="kwh_price" inputmode="decimal" value="<?= escapeHtml($old['kwh_price']) ?>">
            <?php printFieldError($errors, 'kwh_price'); ?>
        </div>

        <button type="submit">Create garages</button>
    </form>

    <h2>Existing garages</h2>
    <div class="table-wrap">
        <?php if (count($summary) === 0): ?>
            <p class="empty">No garages yet.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Type</th><th>Daily price</th><th>kWh price</th><th>Garages</th><th>IDs</th></tr></thead>
                <tbody>
                <?php foreach ($summary as $row): ?>
                    <tr>
                        <td><?= escapeHtml(ucfirst($row['type_name'])) ?></td>
                        <td><?= escapeHtml(number_format((float)$row['daily_price'], 2, '.', ',')) ?></td>
                        <td><?= $row['kwh_price'] === null ? '-' : escapeHtml(number_format((float)$row['kwh_price'], 2, '.', ',')) ?></td>
                        <td><?= (int)$row['total'] ?></td>
                        <td><?= escapeHtml($row['first_id']) ?><?= $row['first_id'] !== $row['last_id'] ? ' to ' . escapeHtml($row['last_id']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>

<script>
    // Show the kWh price field only for the electric car type.
    const typeSelect = document.getElementById('garage_type_id');
    const kwhField = document.getElementById('kwh-field');
    function toggleKwh() {
        const option = typeSelect.options[typeSelect.selectedIndex];
        kwhField.hidden = !option || option.dataset.electric !== '1';
    }
    typeSelect.addEventListener('change', toggleKwh);
    toggleKwh();
</script>
</body>
</html>