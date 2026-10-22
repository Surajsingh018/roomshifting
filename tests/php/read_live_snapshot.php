<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
$pdo = roomshift_db();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'roomshift_db') {
    fwrite(STDERR, "Expected the configured live database roomshift_db; got {$database}.\n");
    exit(2);
}
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$counts = [];
foreach (['services', 'enquiries', 'quotes', 'bookings'] as $table) {
    $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
$versions = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['database' => $database, 'table_count' => count($tables), 'tables' => $tables, 'counts' => $counts, 'migration_versions' => $versions], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
