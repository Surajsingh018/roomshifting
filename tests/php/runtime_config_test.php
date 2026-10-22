<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
function expectConfig(bool $expected, string $label): void {
    try { roomshift_validate_runtime_config(); $valid = true; }
    catch (RuntimeException) { $valid = false; }
    if ($valid !== $expected) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    fwrite(STDOUT, "PASS: {$label}\n");
}
putenv('ROOMSHIFT_APP_ENV=prod');
expectConfig(false, 'unknown environment names fail closed');
putenv('ROOMSHIFT_APP_ENV=production');
putenv('ROOMSHIFT_DEBUG=1');
putenv('ROOMSHIFT_SESSION_SECURE=0');
putenv('ROOMSHIFT_BASE_URL=http://example.invalid');
putenv('ROOMSHIFT_RATE_LIMIT_KEY=replace_with_a_random_32_byte_secret');
putenv('ROOMSHIFT_PUBLIC_TOKEN_KEY=replace_with_a_different_random_32_byte_secret');
putenv('ROOMSHIFT_DB_PASS=');
expectConfig(false, 'production rejects unsafe/incomplete configuration');
putenv('ROOMSHIFT_DEBUG=0');
putenv('ROOMSHIFT_SESSION_SECURE=1');
putenv('ROOMSHIFT_BASE_URL=https://roomshift.example');
putenv('ROOMSHIFT_RATE_LIMIT_KEY=0123456789abcdef0123456789abcdef');
putenv('ROOMSHIFT_PUBLIC_TOKEN_KEY=abcdef0123456789abcdef0123456789');
putenv('ROOMSHIFT_DB_PASS=database-secret');
expectConfig(true, 'production accepts complete secure configuration');

if (roomshift_password_allowed_for_environment('Admin@123')) { fwrite(STDERR, "FAIL: production permits short password
"); exit(1); }
fwrite(STDOUT, "PASS: production login policy rejects short passwords
");
if (!roomshift_password_allowed_for_environment('long-enough-secret')) { fwrite(STDERR, "FAIL: production rejects 12+ character password
"); exit(1); }
fwrite(STDOUT, "PASS: production login policy accepts 12+ character password
");
putenv('ROOMSHIFT_APP_ENV=local');
if (!roomshift_password_allowed_for_environment('Admin@123')) { fwrite(STDERR, "FAIL: local demo login policy changed
"); exit(1); }
fwrite(STDOUT, "PASS: local demo login remains available
");
