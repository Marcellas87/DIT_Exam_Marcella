<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/helpers.php';

if (getCurrentUser() !== null) {
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
        error_log((string)$e);   // message + file + stack trace, for debugging
        $fatal = $e instanceof RuntimeException ? $e->getMessage() : 'Registration failed. Please try again.';
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
    <?php if ($fatal !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($fatal) ?></p><?php endif; ?>

    <form method="post" class="card" novalidate>
        <div class="field">
            <label for="nik">NIK (KTP number)</label>
            <input type="text" id="nik" name="nik" value="<?= escapeHtml($old['nik']) ?>" inputmode="numeric" maxlength="16" required>
            <?php printFieldError($errors, 'nik'); ?>
        </div>
        <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= escapeHtml($old['full_name']) ?>" maxlength="100" required>
            <?php printFieldError($errors, 'full_name'); ?>
        </div>
        <div class="field">
            <label for="phone">Phone number</label>
            <input type="text" id="phone" name="phone" value="<?= escapeHtml($old['phone']) ?>" inputmode="tel" maxlength="16" required>
            <?php printFieldError($errors, 'phone'); ?>
        </div>
        <div class="field">
            <label for="email">Email (your username)</label>
            <input type="text" id="email" name="email" value="<?= escapeHtml($old['email']) ?>" maxlength="100" autocomplete="username" required>
            <?php printFieldError($errors, 'email'); ?>
        </div>
        <div class="field">
            <label for="password">Password (at least 8 characters)</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required>
            <?php printFieldError($errors, 'password'); ?>
        </div>
        <div class="field">
            <label for="password_confirm">Repeat password</label>
            <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
            <?php printFieldError($errors, 'password_confirm'); ?>
        </div>
        <button type="submit">Register</button>
        <a class="btn btn-plain" href="login.php">I already have an account</a>
    </form>
</main>
</body>
</html>