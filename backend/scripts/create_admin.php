<?php
declare(strict_types=1);
// CLI-only user provisioning. Usage: php backend/scripts/create_admin.php <username> <admin|staff>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../config/db.php';
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php backend/scripts/create_admin.php <username> <admin|staff>\nSet ROOMSHIFT_INITIAL_ADMIN_PASSWORD in the process environment; do not pass passwords as command arguments.\n");
    exit(2);
}
[, $username, $role] = $argv;
$password = getenv('ROOMSHIFT_INITIAL_ADMIN_PASSWORD') ?: '';
if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username) || strlen($password) < 12 || !in_array($role, ['admin','staff'], true)) {
    fwrite(STDERR, "Username must use 3-50 safe characters; set a password of at least 12 characters in ROOMSHIFT_INITIAL_ADMIN_PASSWORD; role must be admin or staff.\n");
    exit(2);
}
$db = roomshift_db();
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
$stmt->execute([$username, $hash, $role]);
fwrite(STDOUT, "User created. Store the password securely; it will not be shown again.\n");
