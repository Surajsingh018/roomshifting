<?php

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/backend/repositories/CmsRepository.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
$slug = (string) ($_GET['slug'] ?? '');
$service = null;
$failed = false;
try {
    $service = (new CmsRepository(roomshift_db()))->findService($slug);
} catch (Throwable $error) {
    $failed = true;
    error_log('[RoomShift service page] ' . $error->getMessage());
}
if (!$service) {
    http_response_code($failed ? 503 : 404);
    $pageTitle = $failed ? 'Service unavailable | RoomShift' : 'Service not found | RoomShift';
    $pageMeta = 'The requested service could not be loaded.';
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/navbar.php';
    echo
        '<main class="container section-space"><h1>'
            . ($failed ? 'Service unavailable' : 'Service not found')
            . '</h1><p>'
            . ($failed ? e($t('error')) : 'This service is unavailable or has not been published.')
            . '</p><a class="btn btn-outline-dark" href="services.php">Browse services</a></main>'
    ;
    include __DIR__ . '/includes/footer.php';
    exit();
}
$pageTitle = $service['seo_title'] ?: $service['name'] . ' | RoomShift';
$pageMeta = $service['seo_desc']
?: 'Plan your ' . $service['name'] . ' with a quote calculated from current service pricing.';
$pageCanonical = $service['canonical_url'] ?: roomshift_base_url() . '/service.php?slug=' . rawurlencode($slug);
include __DIR__ . '/includes/header.php';
include
    __DIR__
        . '/includes/navbar.php'; ?><main class="container section-space"><p class="eyebrow">RoomShift service</p><h1><?= e(
    $service['name'],
) ?></h1><p class="lead"><?= e(
    $service['description'],
) ?></p><p>Pricing is calculated from the current server-side rule for your move details.</p><a class="btn btn-gold btn-lg" href="quote.php?service_id=<?= (int) $service['id'] ?>">Get a quote</a></main><?php include
    __DIR__ . '/includes/footer.php';
