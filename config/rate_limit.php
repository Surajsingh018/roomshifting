<?php

declare(strict_types=1);

function api_rate_limit(string $scope, int $maxHits, int $windowSeconds): void
{
    if ($scope === '' || $maxHits < 1 || $windowSeconds < 1) {
        api_error(500, 'RATE_LIMIT_CONFIG', 'Rate limiting is unavailable.');
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $secret = getenv('ROOMSHIFT_RATE_LIMIT_KEY') ?: hash('sha256', __DIR__ . php_uname());
    $bucket = hash_hmac('sha256', $scope . '|' . $ip, $secret);
    $seconds = max(1, $windowSeconds);
    $db = api_db();
    $stmt = $db->prepare(
        "INSERT INTO api_rate_limits (bucket_key, hits, window_started_at) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE hits=IF(window_started_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND), 1, hits+1), window_started_at=IF(window_started_at < DATE_SUB(NOW(), INTERVAL {$seconds} SECOND), NOW(), window_started_at)",
    );
    $stmt->execute([$bucket]);
    $read = $db->prepare('SELECT hits FROM api_rate_limits WHERE bucket_key=?');
    $read->execute([$bucket]);
    if ((int) $read->fetchColumn() > $maxHits)
        api_error(429, 'RATE_LIMITED', 'Too many requests. Please try again later.');
}
