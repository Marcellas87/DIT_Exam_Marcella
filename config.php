<?php
declare(strict_types=1);

/**
 * Database settings.
 *
 * Create an empty database named garage_rental in phpMyAdmin (collation utf8mb4_unicode_ci),
 * then import the SQL file (garage_rental.sql) into it.
 * XAMPP default: user root with an empty password (fine for local practice).
 */
const DB_HOST = 'localhost';
const DB_NAME = 'garage_rental';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';
