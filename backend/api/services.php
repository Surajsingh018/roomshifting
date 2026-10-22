<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$db = api_db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $page = api_int_query('page', 1, 1, 100000);
    $limit = api_int_query('limit', 20, 1, 100);
    $status = trim((string)($_GET['status'] ?? 'active'));
    if (!in_array($status, ['active', 'inactive', 'all'], true)) {
        api_error(422, 'VALIDATION_ERROR', 'Status must be active, inactive, or all.');
    }
    if ($status !== 'active') {
        $admin = api_require_admin($db);
        api_require_role($admin, ['admin', 'staff']);
    }
    $where = $status === 'all' ? '' : ' WHERE status=?';
    $count = $db->prepare('SELECT COUNT(*) AS total FROM services' . $where);
    $count->execute($where !== '' ? [$status] : []);
    $total = (int)$count->fetchColumn();
    $offset = ($page - 1) * $limit;
    $sql = 'SELECT id, name, slug, description, base_price, status, created_at FROM services' . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?';
    $stmt = $db->prepare($sql);
    $position = 1;
    if ($where !== '') $stmt->bindValue($position++, $status, PDO::PARAM_STR);
    $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($position, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    api_success($rows, 200, ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max(1, $limit))]);
}

if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, PATCH, DELETE');
    api_error(405, 'METHOD_NOT_ALLOWED', 'This HTTP method is not supported.');
}
$admin = api_require_admin($db);
api_require_role($admin, ['admin', 'staff']);
$input = api_input();
$id = filter_var($_GET['id'] ?? ($input['id'] ?? null), FILTER_VALIDATE_INT);
if ($method === 'POST') {
    $name = trim((string)($input['name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $price = $input['base_price'] ?? null;
    $status = (string)($input['status'] ?? 'active');
    if ($name === '' || mb_strlen($name) > 100 || !is_numeric($price) || (float)$price < 0 || !in_array($status, ['active', 'inactive'], true)) {
        api_error(422, 'VALIDATION_ERROR', 'Provide a name, non-negative base_price, and valid status.');
    }
    $price = number_format((float)$price, 2, '.', '');
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-'));
    if ($slug === '') $slug = 'service';
    $slug .= '-' . bin2hex(random_bytes(3));
    $stmt = $db->prepare('INSERT INTO services (name, slug, description, base_price, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$name, $slug, $description, $price, $status]);
    api_success(['id' => (int)$db->lastInsertId(), 'name' => $name, 'slug' => $slug, 'description' => $description, 'base_price' => $price, 'status' => $status], 201);
}
if (!$id || $id < 1) api_error(422, 'VALIDATION_ERROR', 'A valid service id is required.');
if ($method === 'DELETE') {
    // Soft-disable instead of deleting a service referenced by historic quotes/bookings.
    $stmt = $db->prepare("UPDATE services SET status='inactive' WHERE id=?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) api_error(404, 'NOT_FOUND', 'Service was not found or was already inactive.');
    api_success(['id' => $id, 'status' => 'inactive']);
}
$existing = $db->prepare('SELECT id FROM services WHERE id=? LIMIT 1');
$existing->execute([$id]);
if (!$existing->fetch()) api_error(404, 'NOT_FOUND', 'Service not found.');
$name = array_key_exists('name', $input) ? trim((string)$input['name']) : null;
$description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
$price = array_key_exists('base_price', $input) ? $input['base_price'] : null;
$status = array_key_exists('status', $input) ? (string)$input['status'] : null;
if ($name !== null && ($name === '' || mb_strlen($name) > 100)) api_error(422, 'VALIDATION_ERROR', 'Name must be 1–100 characters.');
if ($price !== null && (!is_numeric($price) || (float)$price < 0)) api_error(422, 'VALIDATION_ERROR', 'Base price must be non-negative.');
if ($status !== null && !in_array($status, ['active', 'inactive'], true)) api_error(422, 'VALIDATION_ERROR', 'Invalid status.');
// Apply only supplied fields using a fixed allow-list; never interpolate user field names.
if ($name !== null) { $s=$db->prepare('UPDATE services SET name=? WHERE id=?'); $s->execute([$name,$id]); }
if ($description !== null) { $s=$db->prepare('UPDATE services SET description=? WHERE id=?'); $s->execute([$description,$id]); }
if ($price !== null) { $p=number_format((float)$price,2,'.',''); $s=$db->prepare('UPDATE services SET base_price=? WHERE id=?'); $s->execute([$p,$id]); }
if ($status !== null) { $s=$db->prepare('UPDATE services SET status=? WHERE id=?'); $s->execute([$status,$id]); }
api_success(['id' => $id, 'updated' => true]);
