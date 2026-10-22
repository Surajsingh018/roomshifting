<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
api_method(['POST']);
$db = api_db();
$admin = api_require_admin($db);
$stmt = $db->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=?');
$stmt->execute([(int)$admin['token_id']]);
api_success(['logged_out' => true]);
