<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

function roomshift_is_api_request(): bool
{
    return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/roomshift/api')
        || str_starts_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/roomshift/api');
}

function roomshift_api_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function roomshift_api_success(mixed $data = null, string $message = 'OK', int $status = 200): never
{
    roomshift_api_json($status, ['success' => true, 'data' => $data, 'message' => $message, 'error_code' => null]);
}

function roomshift_api_error(int $status, string $code, string $message, mixed $data = null): never
{
    roomshift_api_json($status, ['success' => false, 'data' => $data, 'message' => $message, 'error_code' => $code]);
}

set_exception_handler(static function (Throwable $error): void {
    error_log('[RoomShift] Unhandled exception: ' . $error);
    if (roomshift_is_api_request()) {
        roomshift_api_error(500, 'INTERNAL_ERROR', 'An unexpected error occurred.');
    }
    http_response_code(500);
    if (getenv('ROOMSHIFT_DEBUG') === '1') {
        echo 'Application error. See the PHP error log.';
    } else {
        require dirname(__DIR__) . '/public/500.php';
    }
    exit;
});

/** Reject unsafe configuration when this installation is exposed as production. */
function roomshift_validate_runtime_config(): void
{
    $environment = strtolower(trim((string)(getenv('ROOMSHIFT_APP_ENV') ?: 'local')));
    if (!in_array($environment, ['local', 'production'], true)) {
        error_log('[RoomShift] Unsupported application environment; use local or production.');
        throw new RuntimeException('RoomShift application environment is invalid.');
    }
    if ($environment === 'local') return;

    $errors = [];
    if (getenv('ROOMSHIFT_DEBUG') !== '0') $errors[] = 'ROOMSHIFT_DEBUG must be 0';
    if (getenv('ROOMSHIFT_SESSION_SECURE') !== '1') $errors[] = 'ROOMSHIFT_SESSION_SECURE must be 1';
    $baseUrl = (string)(getenv('ROOMSHIFT_BASE_URL') ?: '');
    if (!preg_match('~^https://~i', $baseUrl)) $errors[] = 'ROOMSHIFT_BASE_URL must use HTTPS';
    $rateKey = (string)(getenv('ROOMSHIFT_RATE_LIMIT_KEY') ?: '');
    if (strlen($rateKey) < 32 || str_contains($rateKey, 'replace_with_')) $errors[] = 'ROOMSHIFT_RATE_LIMIT_KEY must be a unique secret of at least 32 characters';
    $publicKey = (string)(getenv('ROOMSHIFT_PUBLIC_TOKEN_KEY') ?: '');
    if (strlen($publicKey) < 32 || str_contains($publicKey, 'replace_with_')) $errors[] = 'ROOMSHIFT_PUBLIC_TOKEN_KEY must be a unique secret of at least 32 characters';
    if (trim((string)(getenv('ROOMSHIFT_DB_PASS') ?: '')) === '') $errors[] = 'ROOMSHIFT_DB_PASS must be set';

    if ($errors !== []) {
        error_log('[RoomShift] Production configuration rejected: ' . implode('; ', $errors));
        throw new RuntimeException('RoomShift production configuration is incomplete.');
    }
}

roomshift_validate_runtime_config();

function roomshift_password_allowed_for_environment(string $password): bool
{
    return strtolower(trim((string)(getenv('ROOMSHIFT_APP_ENV') ?: 'local'))) !== 'production'
        || strlen($password) >= 12;
}

function api_public_capability(string $scope, int $resourceId, string $clientKey): string
{
    $secret = (string)(getenv('ROOMSHIFT_PUBLIC_TOKEN_KEY') ?: getenv('ROOMSHIFT_RATE_LIMIT_KEY') ?: '');
    if ($secret === '') $secret = hash('sha256', __DIR__ . php_uname());
    return hash_hmac('sha256', $scope . "\n" . $resourceId . "\n" . $clientKey, $secret);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function roomshift_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(getenv('ROOMSHIFT_SESSION_NAME') ?: 'ROOMSHIFTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/roomshift',
        'secure' => getenv('ROOMSHIFT_SESSION_SECURE') === '1',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function roomshift_csrf_token(): string
{
    roomshift_start_session();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function roomshift_require_csrf(): void
{
    roomshift_start_session();
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf_token'] ?? '');
    if (!is_string($provided) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $provided)) {
        roomshift_api_error(419, 'CSRF_INVALID', 'Your session expired. Reload the page and try again.');
    }
}

function api_json(int $status, array $body): never
{
    if (isset($body['success']) && $body['success'] === true) {
        roomshift_api_success($body['data'] ?? null, (string)($body['message'] ?? 'OK'), $status);
    }
    roomshift_api_error($status, (string)($body['error']['code'] ?? 'REQUEST_ERROR'), (string)($body['error']['message'] ?? 'The request could not be completed.'), $body['error']['details'] ?? null);
}
function api_success(mixed $data, int $status = 200, array $meta = []): never
{
    if ($meta !== []) $data = ['items' => $data, 'pagination' => $meta];
    roomshift_api_success($data, 'OK', $status);
}
function api_error(int $status, string $code, string $message, array $details = []): never
{
    roomshift_api_error($status, $code, $message, $details ?: null);
}
function api_method(array $allowed): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        api_error(405, 'METHOD_NOT_ALLOWED', 'This HTTP method is not supported.');
    }
}
function api_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) api_error(400, 'INVALID_JSON', 'Request body must be a valid JSON object.');
    return $data;
}
function api_db(): PDO
{
    try { return roomshift_db(); }
    catch (Throwable) { api_error(500, 'DATABASE_UNAVAILABLE', 'Service is temporarily unavailable.'); }
}
function api_bearer_token(): ?string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']
        ?? ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    return preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', $header, $m) ? $m[1] : null;
}
function api_require_admin(PDO $db): array
{
    $token = api_bearer_token();
    if (!$token) api_error(401, 'AUTH_REQUIRED', 'A valid bearer token is required.');
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT u.id, u.username, u.role, t.id AS token_id FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) api_error(401, 'INVALID_TOKEN', 'Token is invalid or expired.');
    return $row;
}
function api_require_role(array $user, array $roles): void
{
    if (!in_array($user['role'] ?? '', $roles, true)) api_error(403, 'FORBIDDEN', 'You do not have permission to perform this action.');
}
function api_int_query(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return ($value === null || $value === false) ? $default : max($min, min($max, (int)$value));
}
require_once __DIR__ . '/rate_limit.php';
