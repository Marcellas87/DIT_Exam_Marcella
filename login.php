<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/helpers.php';

if (getCurrentUser() !== null) {
    header('Location: index.php');
    exit;
}

$email = '';
$error = '';
$info = isset($_GET['registered']) ? 'Registration received. You can log in after the admin accepts it.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    try {
        $result = (new AuthService(Database::connect()))->login($email, $password);

        switch ($result['status']) {
            case 'ok':
                // New session id after login: prevents session fixation attacks.
                session_regenerate_id(true);
                $_SESSION['user'] = $result['user'];
                header('Location: index.php');
                exit;
            case 'pending':
                $error = 'Your registration is still waiting for admin approval.';
                break;
            case 'rejected':
                $error = 'Your registration was rejected by the admin.';
                break;
            default:
                $error = 'Wrong email or password.';
        }
    } catch (Throwable $e) {
        error_log((string)$e);   // message + file + stack trace, for debugging
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Login failed. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <h1>Garage Rental: log in</h1>
    <?php if ($info !== ''): ?><p class="flash" role="status"><?= escapeHtml($info) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= escapeHtml($error) ?></p><?php endif; ?>

    <form method="post" class="card">
        <div class="field">
            <label for="email">Email</label>
            <input type="text" id="email" name="email" value="<?= escapeHtml($email) ?>" autocomplete="username" required>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit">Log in</button>
        <a class="btn btn-plain" href="register.php">Register as tenant</a>
    </form>
</main>
</body>
</html>