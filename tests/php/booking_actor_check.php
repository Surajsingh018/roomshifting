<?php
declare(strict_types=1);
$database = (string)(getenv('ROOMSHIFT_DB_NAME') ?: '');
if (!str_starts_with($database, 'roomshift_restore_test_')) exit(2);
require_once __DIR__ . '/../../config/db.php';
$fixturePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'roomshift_actor_audit.json';
$fixture = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$stmt = roomshift_db()->prepare('SELECT COUNT(*) FROM booking_status_history WHERE booking_id=? AND changed_by=? AND from_status=\'quote\' AND to_status=\'confirmed\'');
$stmt->execute([(int)$fixture['booking_id'], (int)$fixture['admin_user_id']]);
$matches = (int)$stmt->fetchColumn();
if ($matches !== 1) {
    fwrite(STDERR, "Expected exactly one admin-attributed quote-to-confirmed history row; found {$matches}.\n");
    exit(1);
}
echo "PASS: booking status history records the authenticated admin user ID.\n";
