<?php
declare(strict_types=1);

require_once __DIR__ . '/src/helpers.php';

// Logs out. POST only, so a random link cannot log someone out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

startSession();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;