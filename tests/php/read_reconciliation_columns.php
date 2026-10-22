<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
$pdo = roomshift_db();
$sql = "SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,DATA_TYPE,COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME,COLUMN_NAME) IN
          (('services','id'),('enquiries','id'),('enquiries','service_id'),
           ('pricing_rules','service_id'),('quotes','enquiry_id'),
           ('bookings','quote_id'),('bookings','enquiry_id'),
           ('partners','service_area_id'),('api_tokens','user_id'))
        ORDER BY TABLE_NAME,ORDINAL_POSITION";
$columns = $pdo->query($sql)->fetchAll();
$foreignKeys = $pdo->query("SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll();
echo json_encode(['columns' => $columns, 'foreign_keys' => $foreignKeys], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
