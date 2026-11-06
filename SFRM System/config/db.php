<?php
declare(strict_types=1);

/**
 * Database connection (PDO + prepared statements).
 * On your web host, change these values to the hosting database details.
 * Never commit real credentials to a public repository.
 */
const DB_HOST = '127.0.0.1';   // 127.0.0.1 forces a TCP connection (avoids Mac socket problems)
const DB_PORT = '3306';
const DB_NAME = 'sfrms';
const DB_USER = 'root';
const DB_PASS = 'kiambii27';          // XAMPP default is empty; use a strong password online

date_default_timezone_set('Asia/Manila');   // change if needed

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]);
        // keep MySQL timestamps in the same time zone as PHP
        // (works on every PHP version, unlike the old init-command constant)
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Service temporarily unavailable. Please try again later.');
    }
    return $pdo;
}