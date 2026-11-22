<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RentalService.php';
require_once __DIR__ . '/src/helpers.php';

$user = requireLogin('tenant');          // only tenants; the tenant id comes from the session
$today = new DateTimeImmutable('today'); // start date is ALWAYS today (not taken from the form)

$fields = ['vehicle_type', 'plate_number', 'brand', 'model', 'battery_kwh', 'rental_type', 'days', 'garage_id'];
$vehicleTypes = ['bicycle' => 'Bicycle', 'motorbike' => 'Motorbike', 'car' => 'Car', 'electric_car' => 'Electric car'];
$old = array_fill_keys($fields, '');
$errors = [];
$error = '';
$preview = null;
$garages = [];

try {
    $service = new RentalService(Database::connect(), new PaymentProofStorage(__DIR__ . '/storage/payment_proofs'));
    $service->refreshGarageStatuses($today);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isValidCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $error = 'Invalid form token. Please reload the page and try again.';
        } else {
            foreach ($fields as $field) {
                $old[$field] = trim((string)($_POST[$field] ?? ''));
            }

            if (($_POST['action'] ?? '') === 'save') {
                $result = $service->createRental((int)$user['id'], $old, $_FILES['proof'] ?? null, $today);
                if ($result['errors'] === []) {
                    $_SESSION['flash'] = 'Rental saved.';
                    header('Location: my_rentals.php');
                    exit;
                }
                $errors = $result['errors'];
            } else {
                $result = $service->previewRental($old, $today);   // "Calculate total" button
                $errors = $result['errors'];
                if ($errors === []) {
                    $preview = $result;
                }
            }
        }
    }

    // Every garage that is free today: bicycle garages, plus car and electric garages
    // (an electric car may use both, so its list covers them). The page script then shows
    // only the ones that match the chosen vehicle type; the server checks the type again on save.
    $garages = array_merge(
        $service->findAvailableGarages('bicycle', $today),
        $service->findAvailableGarages('electric_car', $today)
    );
} catch (Throwable $e) {
    error_log((string)$e);   // message + file + stack trace, for debugging
    $error = 'Something went wrong. Please try again.';
}

$token = getCsrfToken();
$maxDays = RentalRules::getMaxDailyDays($today);

// Garage types each vehicle type may use, taken from the Vehicle classes (one source of the rule).
$allowedGarageTypes = [];
foreach (array_keys($vehicleTypes) as $vehicleType) {
    $sampleVehicle = Vehicle::createFromInput([
        'vehicle_type' => $vehicleType, 'brand' => 'x', 'model' => 'x', 'plate_number' => 'X 1', 'battery_kwh' => '1',
    ]);
    $allowedGarageTypes[$vehicleType] = $sampleVehicle->getAllowedGarageTypes();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New rental</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav"><a href="index.php">&larr; Back to menu</a></div>
    <h1>New rental</h1>

    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>
    <?php printFieldError($errors, 'form'); ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= escapeHtml($token) ?>">

        <h2>Vehicle</h2>
        <label for="vehicle_type">Vehicle type</label>
        <select id="vehicle_type" name="vehicle_type">
            <option value="">Choose...</option>
            <?php foreach ($vehicleTypes as $value => $label): ?>
                <option value="<?= escapeHtml($value) ?>" <?= $old['vehicle_type'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?php printFieldError($errors, 'vehicle_type'); ?>

        <label for="plate_number">Plate number (not for bicycles)</label>
        <input type="text" id="plate_number" name="plate_number" maxlength="15" value="<?= escapeHtml($old['plate_number']) ?>">
        <?php printFieldError($errors, 'plate_number'); ?>

        <label for="brand">Brand</label>
        <input type="text" id="brand" name="brand" maxlength="50" value="<?= escapeHtml($old['brand']) ?>">
        <?php printFieldError($errors, 'brand'); ?>

        <label for="model">Model</label>
        <input type="text" id="model" name="model" maxlength="50" value="<?= escapeHtml($old['model']) ?>">
        <?php printFieldError($errors, 'model'); ?>

        <label for="battery_kwh">Battery capacity in kWh (electric car only)</label>
        <input type="text" id="battery_kwh" name="battery_kwh" value="<?= escapeHtml($old['battery_kwh']) ?>">
        <?php printFieldError($errors, 'battery_kwh'); ?>

        <h2>Rental</h2>
        <label for="rental_type">Rental type</label>
        <select id="rental_type" name="rental_type">
            <option value="">Choose...</option>
            <option value="daily" <?= $old['rental_type'] === 'daily' ? 'selected' : '' ?>>Daily</option>
            <option value="monthly" <?= $old['rental_type'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        </select>
        <?php printFieldError($errors, 'rental_type'); ?>

        <label for="days">Number of days (daily only, 1 to <?= (int)$maxDays ?>)</label>
        <input type="number" id="days" name="days" min="1" max="<?= (int)$maxDays ?>" value="<?= escapeHtml($old['days']) ?>">
        <?php printFieldError($errors, 'days'); ?>

        <p>Start date: <strong><?= escapeHtml($today->format('d M Y')) ?></strong> (always today)</p>

        <label for="garage_id">Garage (free today, matching the vehicle type)</label>
        <select id="garage_id" name="garage_id">
            <option value="">Choose...</option>
            <?php foreach ($garages as $garage): ?>
                <option value="<?= escapeHtml($garage['id']) ?>" data-garage-type="<?= escapeHtml($garage['type_name']) ?>" <?= $old['garage_id'] === $garage['id'] ? 'selected' : '' ?>>
                    <?= escapeHtml($garage['id']) ?> (<?= escapeHtml($garage['type_name']) ?>, <?= escapeHtml($garage['daily_price']) ?> per day<?= $garage['kwh_price'] !== null ? ', ' . escapeHtml($garage['kwh_price']) . ' per kWh' : '' ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="muted" id="garage_hint">Choose a vehicle type first; the list then shows only the garages that vehicle may use.</p>
        <?php printFieldError($errors, 'garage_id'); ?>

        <?php if ($preview !== null): ?>
            <h2>Total</h2>
            <p>End date: <strong><?= escapeHtml($preview['end_date']) ?></strong></p>
            <p>Rental: <?= escapeHtml($preview['totals']['rental']) ?><br>
               Charging fee: <?= escapeHtml($preview['totals']['charging']) ?><br>
               <strong>Total to pay: <?= escapeHtml($preview['totals']['total']) ?></strong></p>
        <?php endif; ?>

        <label for="proof">Payment proof (JPG or PNG, maximum 2 MB)</label>
        <input type="file" id="proof" name="proof" accept="image/jpeg,image/png">
        <?php printFieldError($errors, 'proof'); ?>

        <div class="toolbar">
            <button type="submit" name="action" value="preview">Calculate total</button>
            <button type="submit" name="action" value="save">Save rental</button>
        </div>
    </form>
</main>
<script>
// Form comfort only: shows the fields and garages that fit the chosen vehicle and rental type.
// The server still checks every rule when the form is sent, so nothing depends on this script.
(function () {
    const allowedGarageTypes = <?= json_encode($allowedGarageTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const vehicleType = document.getElementById('vehicle_type');
    const rentalType = document.getElementById('rental_type');
    const garageSelect = document.getElementById('garage_id');
    const garageHint = document.getElementById('garage_hint');

    // Shows or hides one input with its label; a disabled input is not sent with the form.
    function showField(fieldId, isVisible) {
        const input = document.getElementById(fieldId);
        const label = document.querySelector('label[for="' + fieldId + '"]');
        input.style.display = isVisible ? '' : 'none';
        label.style.display = isVisible ? '' : 'none';
        input.disabled = !isVisible;
    }

    // Keeps only the garages whose type the chosen vehicle may use.
    function filterGarages() {
        const allowed = allowedGarageTypes[vehicleType.value] || [];
        for (const option of garageSelect.options) {
            if (option.value === '') {
                continue;   // the "Choose..." line always stays
            }
            const fits = allowed.includes(option.dataset.garageType);
            option.hidden = !fits;
            option.disabled = !fits;
            if (!fits && option.selected) {
                garageSelect.value = '';   // a garage of the wrong type cannot stay selected
            }
        }
        garageHint.style.display = vehicleType.value === '' ? '' : 'none';
    }

    function updateForm() {
        showField('plate_number', vehicleType.value !== 'bicycle');
        showField('battery_kwh', vehicleType.value === 'electric_car');
        showField('days', rentalType.value !== 'monthly');
        filterGarages();
    }

    vehicleType.addEventListener('change', updateForm);
    rentalType.addEventListener('change', updateForm);
    updateForm();   // also after "Calculate total" reloads the page with the chosen values
})();
</script>
</body>
</html>