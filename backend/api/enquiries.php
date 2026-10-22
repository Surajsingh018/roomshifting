<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
api_method(['GET', 'POST']);
$db = api_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_rate_limit('public-enquiry', 10, 900);
    $input = api_input();
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) api_error(422, 'VALIDATION_ERROR', 'Name is required and must be at most 100 characters.', ['name' => 'Required; max 100 characters.']);
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) api_error(422, 'VALIDATION_ERROR', 'Enter a valid email address.', ['email' => 'Invalid email address.']);
    if (!preg_match('/^[0-9+().\-\s]{7,20}$/', $phone)) api_error(422, 'VALIDATION_ERROR', 'Enter a valid contact phone number.', ['phone' => 'Invalid phone number.']);
    if ($message === '' || mb_strlen($message) > 3000) api_error(422, 'VALIDATION_ERROR', 'Message is required and must be at most 3000 characters.', ['message' => 'Required; max 3000 characters.']);
    $stmt = $db->prepare('INSERT INTO enquiries (name, email, phone, message) VALUES (?, NULLIF(?, \'\'), ?, ?)');
    $stmt->execute([$name, $email, $phone, $message]);
    api_success(['id' => (int)$db->lastInsertId(), 'status' => 'received'], 201);
}
$admin = api_require_admin($db);
api_require_role($admin, ['admin', 'staff']);
$page = api_int_query('page', 1, 1, 100000);
$limit = api_int_query('limit', 20, 1, 100);
$offset = ($page - 1) * $limit;
$count = (int)$db->query('SELECT COUNT(*) AS total FROM enquiries')->fetchColumn();
$stmt = $db->prepare('SELECT id, name, phone, message, created_at FROM enquiries ORDER BY id DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
api_success($stmt->fetchAll(), 200, ['page' => $page, 'limit' => $limit, 'total' => $count, 'pages' => (int)ceil($count / max(1, $limit))]);
