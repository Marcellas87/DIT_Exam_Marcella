<?php
declare(strict_types=1);

// All dates ("today" = rental start date) use Indonesian western time.
date_default_timezone_set('Asia/Jakarta');

// Library   : vlucas/phpdotenv ^5.6 (license: BSD-3-Clause; exact version is frozen in composer.lock)
// Function  : reads settings (DB_HOST, DB_NAME, DB_USER, DB_PASS) from a ".env" file into $_ENV
// Reason    : the database password stays out of the source code and out of GitHub
// Install   : composer install   (the library is listed under "require" in composer.json)
// Docs      : https://github.com/vlucas/phpdotenv
// Example   : Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();   then   $_ENV['DB_USER']
// Note      : optional on purpose. If vendor/ or .env is missing, the defaults below are used,
//             so the app still runs on a plain XAMPP setup.
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
    if (is_file(__DIR__ . '/.env')) {
        Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    }
}

/**
 * Reads a setting from the environment (.env file or server), or returns the default.
 *
 * @param string $key the setting name, like "DB_PASS"
 * @param string $default the value to use when the setting is not set
 * @return string the setting value
 */
function getEnvValue(string $key, string $default): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null) ? $default : (string)$value;
}

/**
 * Database settings.
 *
 * The database itself is created by garage_rental.sql (import it in phpMyAdmin).
 * XAMPP default: user root with an empty password (fine for local practice).
 * To change a value, copy .env.example to .env and edit it (the .env file is not committed to GitHub).
 */
define('DB_HOST', getEnvValue('DB_HOST', 'localhost'));
define('DB_NAME', getEnvValue('DB_NAME', 'garage_rental'));
define('DB_USER', getEnvValue('DB_USER', 'root'));
define('DB_PASS', getEnvValue('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');