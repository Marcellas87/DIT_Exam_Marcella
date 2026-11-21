<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/helpers.php';

if (currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$old = ['nik' => '', 'full_name' => '', 'phone' => '', 'email' => ''];
$errors = [];
$fatal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = [
        'nik'              => (string)($_POST['nik'] ?? ''),
        'full_name'        => (string)($_POST['full_name'] ?? ''),
        'phone'            => (string)($_POST['phone'] ?? ''),
        'email'            => (string)($_POST['email'] ?? ''),
        'password'         => (string)($_POST['password'] ?? ''),
        'password_confirm' => (string)($_POST['password_confirm'] ?? ''),
    ];
    // Keep what the person typed, but never send the password back to the page.
    $old = array_intersect_key($input, $old);

    try {
        $errors = (new AuthService(Database::connect()))->register($input);
        if (count($errors) === 0) {
            header('Location: login.php?registered=1');
            exit;
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $fatal = $e instanceof RuntimeException ? $e->getMessage() : 'Registration failed. Please try again.';
    }
}

/**
 * Prints the error text under a form field, if there is one.
 *
 * @param array<string, string> $errors error messages by field name
 * @param string $field the field to print the error for
 * @return void
 */
function fieldError(array $errors, string $field): void
{
    if (isset($errors[$field])) {
        echo '<p class="error-text">' . e($errors[$field]) . '</p>';
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register as tenant</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <h1>Register as tenant</h1>
    <?php if ($fatal !== ''): ?><p class="flash error" role="alert"><?= e($fatal) ?></p><?php endif; ?>

    <form method="post" class="card" novalidate>
        <div class="field">
            <label for="nik">NIK (KTP number)</label>
            <input type="text" id="nik" name="nik" value="<?= e($old['nik']) ?>" inputmode="numeric" maxlength="16" required>
            <?php fieldError($errors, 'nik'); ?>
        </div>
        <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" maxlength="100" required>
            <?php fieldError($errors, 'full_name'); ?>
        </div>
        <div class="field">
            <label for="phone">Phone number</label>
            <input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>" inputmode="tel" maxlength="16" required>
            <?php fieldError($errors, 'phone'); ?>
        </div>
        <div class="field">
            <label for="email">Email (your username)</label>
            <input type="text" id="email" name="email" value="<?= e($old['email']) ?>" maxlength="100" autocomplete="username" required>
            <?php fieldError($errors, 'email'); ?>
        </div>
        <div class="field">
            <label for="password">Password (at least 8 characters)</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required>
            <?php fieldError($errors, 'password'); ?>
        </div>
        <div class="field">
            <label for="password_confirm">Repeat password</label>
            <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            <?php fieldError($errors, 'password_confirm'); ?>
        </div>
        <button type="submit">Register</button>
        <a class="btn btn-plain" href="login.php">I already have an account</a>
    </form>
</main>
</body>
</html>