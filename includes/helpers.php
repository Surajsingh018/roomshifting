<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function roomshift_base_url(): string
{
    return rtrim((string)(getenv('ROOMSHIFT_BASE_URL') ?: 'http://localhost/roomshift'), '/');
}

function roomshift_public_path(): string
{
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    foreach (['/roomshift/roomshift/website/', '/roomshift/website/'] as $nestedPrefix) {
        if (str_starts_with($path, $nestedPrefix)) {
            $path = '/' . substr($path, strlen($nestedPrefix));
            break;
        }
    }
    if (in_array($path, ['/roomshift/roomshift', '/roomshift/roomshift/'], true)) $path = '/';
    elseif (str_starts_with($path, '/roomshift/roomshift/')) $path = '/' . substr($path, strlen('/roomshift/roomshift/'));
    elseif ($path === '/roomshift' || $path === '/roomshift/') $path = '/';
    elseif (str_starts_with($path, '/roomshift/')) $path = '/' . substr($path, strlen('/roomshift/'));
    if (str_ends_with($path, '/index.php')) $path = substr($path, 0, -strlen('index.php'));
    if ($path === '/index.php' || $path === '') $path = '/';
    return $path;
}
