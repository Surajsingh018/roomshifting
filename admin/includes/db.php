<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
roomshift_start_session();
try { $pdo = roomshift_db(); }
catch (Throwable) {
    http_response_code(503);
    exit('RoomShift is temporarily unavailable.');
}
function admin_require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    global $pdo;
    $stmt = $pdo->prepare('SELECT username,role,is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(int)$user['is_active'] || !in_array($user['role'], ['admin','staff'], true)) {
        $_SESSION=[];
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $_SESSION['username']=$user['username'];
    $_SESSION['role']=$user['role'];
}
function admin_require_role(array $roles): void
{
    admin_require_login();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}
function admin_csrf_token(): string { return roomshift_csrf_token(); }
function admin_verify_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_csrf_token'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Reload and try again.');
    }
}
