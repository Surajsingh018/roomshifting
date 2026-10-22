<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

/** Return the shared PDO connection. Credentials come from .env/process env. */
function roomshift_db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) return $connection;

    $host = getenv('ROOMSHIFT_DB_HOST') ?: 'localhost';
    $name = getenv('ROOMSHIFT_DB_NAME') ?: 'roomshift_db';
    $user = getenv('ROOMSHIFT_DB_USER') ?: 'root';
    $pass = getenv('ROOMSHIFT_DB_PASS') ?: '';
    $port = (int)(getenv('ROOMSHIFT_DB_PORT') ?: 3306);
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        return $connection;
    } catch (PDOException $e) {
        error_log('[RoomShift] Database connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database service unavailable.', 0, $e);
    }
}

/** Run a database operation atomically and roll back on any failure. */
function roomshift_transaction(callable $operation): mixed
{
    $pdo = roomshift_db();
    $pdo->beginTransaction();
    try {
        $result = $operation($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
