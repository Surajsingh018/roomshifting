<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
api_method(['POST']);
$input = api_input();
$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if ($username === '' || $password === '' || strlen($username) > 50 || strlen($password) > 4096) {
    api_error(422, 'VALIDATION_ERROR', 'Username and password are required.', [
        'username' => 'Required; maximum 50 characters.',
        'password' => 'Required.'
    ]);
}
$db = api_db();
// Basic persistent throttle: max 10 attempts per client IP hash in 15 minutes.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateKey = getenv('ROOMSHIFT_RATE_LIMIT_KEY') ?: hash('sha256', __DIR__ . php_uname());
$ipHash = hash_hmac('sha256', $ip, $rateKey);
$cleanup = $db->prepare('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
$cleanup->execute();
$limit = $db->prepare('SELECT COUNT(*) AS attempts FROM login_attempts WHERE ip_hash=? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
$limit->execute([$ipHash]);
if ((int)$limit->fetchColumn() >= 10) {
    api_error(429, 'RATE_LIMITED', 'Too many login attempts. Please try again later.');
}
$stmt = $db->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username=? LIMIT 1');
$stmt->execute([$username]);
$admin = $stmt->fetch();
if (!$admin || !(int)$admin['is_active'] || !roomshift_password_allowed_for_environment($password) || !password_verify($password, $admin['password_hash'])) {
    // Same response avoids revealing whether a username exists.
    $attempt = $db->prepare('INSERT INTO login_attempts (ip_hash) VALUES (?)');
    $attempt->execute([$ipHash]);
    api_error(401, 'INVALID_CREDENTIALS', 'Username or password is incorrect.');
}
$clear = $db->prepare('DELETE FROM login_attempts WHERE ip_hash=?');
$clear->execute([$ipHash]);
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = (new DateTimeImmutable('+8 hours'))->format('Y-m-d H:i:s');
$insert = $db->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
$insert->execute([(int)$admin['id'], $tokenHash, $expiresAt]);
$lastLogin = $db->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?');
$lastLogin->execute([(int)$admin['id']]);
api_success([
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_at' => $expiresAt,
    'admin' => ['id' => (int)$admin['id'], 'username' => $admin['username'], 'role' => $admin['role']]
]);
