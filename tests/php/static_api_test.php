<?php
declare(strict_types=1);
require_once __DIR__ . '/../../backend/config/bootstrap.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "PASS: {$message}\n");
}
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token_123';
check(api_bearer_token() === 'test-token_123', 'Bearer token parser accepts valid token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic not-a-token';
check(api_bearer_token() === null, 'Bearer token parser rejects non-Bearer authorization');
check(is_file(__DIR__ . '/../../database/migrations/001_backend_auth_api.sql'), 'Additive API auth migration exists');
check(is_file(__DIR__ . '/../../docs/api/openapi.yaml'), 'OpenAPI contract exists');
check(is_file(__DIR__ . '/../../backend/api/auth/login.php'), 'Login endpoint exists');
check(is_file(__DIR__ . '/../../backend/api/auth/logout.php'), 'Logout endpoint exists');
check(is_file(__DIR__ . '/../../backend/api/enquiries.php'), 'Enquiries endpoint exists');
check(is_file(__DIR__ . '/../../backend/scripts/create_admin.php'), 'CLI-only admin provisioning script exists');
echo "Static API tests passed: {$checks}\n";
