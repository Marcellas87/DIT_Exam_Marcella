<?php
declare(strict_types=1);

/**
 * Shows a payment proof image. Files are stored outside public access,
 * so this script checks WHO is asking: an admin, or the tenant who owns the rental.
 */
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/RentalService.php';
require_once __DIR__ . '/src/ExtensionService.php';
require_once __DIR__ . '/src/helpers.php';

$user = requireLogin();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

$storage = new PaymentProofStorage(__DIR__ . '/storage/payment_proofs');
$path = null;
if ($id !== false) {
    try {
        $pdo = Database::connect();
        // ?kind=extension shows an extension proof; the default is the rental's own proof.
        $service = ($_GET['kind'] ?? '') === 'extension'
            ? new ExtensionService($pdo, $storage)
            : new RentalService($pdo, $storage);
        $name = $service->findProofName($id, (int)$user['id'], (string)$user['role']);
        $path = $name === null ? null : $storage->findPath($name);
    } catch (Throwable $e) {
        error_log((string)$e);   // message + file + stack trace, for debugging
    }
}

if ($path === null) {
    http_response_code(404);   // same answer for "missing" and "not yours"
    exit('Not found.');
}

$info = getimagesize($path);
header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);