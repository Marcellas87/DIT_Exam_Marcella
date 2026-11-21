<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TenantService.php';
require_once __DIR__ . '/src/helpers.php';

$admin = requireLogin('admin');   // only admins; tenants get 403, visitors go to login

// Fixed flash texts: the URL can only pick a key, never inject text.
$messages = [
    'accepted' => 'Tenant accepted.',
    'rejected' => 'Tenant rejected.',
    'nochange' => 'That registration was already decided.',
];
$flash = $messages[$_GET['msg'] ?? ''] ?? '';
$error = '';
$pending = [];

try {
    $service = new TenantService(Database::connect());

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string)($_POST['decision'] ?? '');

        if (!isValidCsrf($_POST['csrf'] ?? null)) {
            http_response_code(400);
            $error = 'Invalid form token. Please reload the page and try again.';
        } elseif ($userId === false || $userId === null || $userId < 1
                  || !in_array($decision, TenantService::DECISIONS, true)) {
            http_response_code(400);
            $error = 'Invalid request.';
        } else {
            $changed = $service->decide($userId, $decision);
            // Post/Redirect/Get: reloading the page will not repeat the action.
            header('Location: admin_tenants.php?msg=' . ($changed ? $decision : 'nochange'));
            exit;
        }
    }

    $pending = $service->findPending();
} catch (Throwable $e) {
    error_log($e->getMessage());
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.';
}

$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify tenants</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <div class="nav">
        <a href="index.php">&larr; Back to menu</a>
        <form method="post" action="logout.php"><button type="submit" class="btn-plain">Log out</button></form>
    </div>

    <h1>Verify new tenants</h1>
    <p class="muted">Check each NIK against the KTP, then accept or reject the registration.</p>

    <?php if ($flash !== ''): ?><p class="flash" role="status"><?= e($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="table-wrap">
        <?php if (count($pending) === 0): ?>
            <p class="empty">No registrations are waiting.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Name</th><th>NIK</th><th>Phone</th><th>Email</th><th>Decision</th></tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $t): ?>
                    <tr>
                        <td><?= e($t['full_name']) ?></td>
                        <td><?= e($t['nik']) ?></td>
                        <td><?= e($t['phone']) ?></td>
                        <td><?= e($t['email']) ?></td>
                        <td class="actions">
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$t['user_id'] ?>">
                                <input type="hidden" name="decision" value="accepted">
                                <button type="submit">Accept</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Reject this registration?');">
                                <input type="hidden" name="csrf" value="<?= e($token) ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$t['user_id'] ?>">
                                <input type="hidden" name="decision" value="rejected">
                                <button type="submit" class="btn-danger">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>