<?php
declare(strict_types=1);
$database = (string)(getenv('ROOMSHIFT_DB_NAME') ?: '');
if (!str_starts_with($database, 'roomshift_restore_test_')) {
    fwrite(STDERR, "Refusing booking test outside isolated restore database.\n");
    exit(2);
}
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../backend/repositories/BookingRepository.php';
$quoteId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$key = (string)($argv[2] ?? '');
$token = (string)($argv[3] ?? '');
if ($quoteId === false || $quoteId < 1 || $key === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) exit(2);
try {
    $result = (new BookingRepository(roomshift_db()))->createFromQuote((int)$quoteId, $key, $token);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(1);
}
