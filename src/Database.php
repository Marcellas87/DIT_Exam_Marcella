<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Creates the PDO connection to MySQL/MariaDB.
 */
final class Database
{
    /**
     * Opens a connection.
     *
     * - ERRMODE_EXCEPTION: SQL errors become exceptions we can catch.
     * - EMULATE_PREPARES false: the database itself prepares statements (safer against SQL injection).
     *
     * @return PDO an open connection
     * @throws RuntimeException if the connection fails
     */
    public static function connect(): PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

        try {
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('Cannot connect to the database. Check config.php and that MySQL is running.');
        }
    }
}
