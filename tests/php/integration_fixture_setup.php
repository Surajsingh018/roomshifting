<?php
declare(strict_types=1);

// Integration fixtures are permitted only in the named restored audit clone.
$database = (string)(getenv('ROOMSHIFT_DB_NAME') ?: '');
if (!str_starts_with($database, 'roomshift_restore_test_')) {
    fwrite(STDERR, "Refusing fixture setup outside the isolated restore database.\n");
    exit(2);
}
require_once __DIR__ . '/../../config/db.php';
$pdo = roomshift_db();
$pdo->exec('DELETE FROM api_rate_limits');
$pdo->exec('DELETE FROM login_attempts');
$seed = bin2hex(random_bytes(8));
$users = [];
foreach (['admin', 'staff', 'partner'] as $role) {
    $username = 'audit_' . $role . '_' . $seed;
    $password = 'Audit-Only-' . $seed . '-Pass!';
    $stmt = $pdo->prepare('INSERT INTO users (username,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
    $stmt->execute([$username, $username . '@example.invalid', password_hash($password, PASSWORD_DEFAULT), $role]);
    $id = (int)$pdo->lastInsertId();
    $token = bin2hex(random_bytes(32));
    $tokenStmt = $pdo->prepare('INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))');
    $tokenStmt->execute([$id, hash('sha256', $token)]);
    $users[$role] = ['id' => $id, 'token' => $token, 'username' => $username, 'password' => $password];
}
$staff = $pdo->prepare("INSERT INTO staff (user_id,name,phone,status) VALUES (?, 'Audit Staff', '5550100', 'active')");
$staff->execute([$users['staff']['id']]);
$service = $pdo->prepare('SELECT id FROM services WHERE status=\'active\' ORDER BY id LIMIT 1');
$service->execute();
$serviceId = (int)$service->fetchColumn();
$rules = $pdo->prepare('SELECT COUNT(*) FROM pricing_rules WHERE service_id=? AND is_active=1');
$rules->execute([$serviceId]);
if (!(int)$rules->fetchColumn()) {
    $pdo->prepare('INSERT INTO pricing_rules (service_id,base_price,per_km,per_floor,packing_charges,gst_percent,min_charge,is_active) VALUES (?,100,2.50,10,20,18,200,1)')->execute([$serviceId]);
}
$xssSlug = 'audit-xss-' . $seed;
$pdo->prepare("INSERT INTO services (name,description,slug,pricing_type,status) VALUES (?, 'Synthetic escaping check', ?, 'quote', 'active')")
    ->execute(['<script>alert(1)</script>', $xssSlug]);
echo json_encode(['users' => $users, 'service_id' => $serviceId, 'xss_slug' => $xssSlug], JSON_THROW_ON_ERROR);
