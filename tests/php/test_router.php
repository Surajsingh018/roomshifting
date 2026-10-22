<?php
declare(strict_types=1);
$prefix = '/roomshift';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($uriPath === $prefix || $uriPath === $prefix . '/') {
    $_SERVER['SCRIPT_NAME'] = $prefix . '/website/index.php';
    require __DIR__ . '/../../website/index.php';
    return true;
}
if (str_starts_with($uriPath, $prefix . '/api')) {
    $route = trim(substr($uriPath, strlen($prefix . '/api')), '/');
    $_GET['route'] = $route;
    $_SERVER['SCRIPT_NAME'] = $prefix . '/api/index.php';
    require __DIR__ . '/../../api/index.php';
    return true;
}
$relative = ltrim(substr($uriPath, strlen($prefix)), '/');
if ($relative === 'sitemap.xml') {
    $_SERVER['SCRIPT_NAME'] = $prefix . '/sitemap.php';
    require __DIR__ . '/../../sitemap.php';
    return true;
}
$file = realpath(__DIR__ . '/../../' . $relative);
$root = realpath(__DIR__ . '/../..');
if ($file !== false && $root !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) && str_ends_with($file, '.php')) {
    $_SERVER['SCRIPT_NAME'] = $prefix . '/' . $relative;
    require $file;
    return true;
}
http_response_code(404);
echo 'Not found';
return true;
