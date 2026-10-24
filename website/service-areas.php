<?php $pageTitle = 'Service areas | RoomShift';
$pageMeta = 'View the cities and postal areas currently served by RoomShift.';
include __DIR__ . '/includes/header.php';
$result = web_catalog(static fn() => web_cms()->listServiceAreas(['limit' => 100]));
include __DIR__ . '/includes/navbar.php';
$areas = $result['items']
?? []; ?><main class="container section-space"><header class="page-heading"><p class="eyebrow">Where we move</p><h1>Service areas</h1><p>Availability shown here is managed by the RoomShift team.</p></header><?php if (
    $GLOBALS['webDataError']
): ?><div class="alert alert-warning"><?= e($t('error')) ?></div><?php elseif (
        !$areas
    ): ?><div class="empty-panel"><?= e($t(
    'empty',
)) ?></div><?php else: ?><div class="row g-3"><?php foreach ($areas as $area): ?><div class="col-sm-6 col-lg-4"><article class="area-card h-100"><h2 class="h5"><a href="service-area.php?slug=<?= rawurlencode(
    $area['slug'],
) ?>"><?= e($area['city']) ?></a></h2><?php if ($area['name'] !== $area['city']): ?><p><?= e(
    $area['name'],
) ?></p><?php endif; ?><?php if ($area['region']): ?><p class="small text-secondary"><?= e(
    $area['region'],
) ?></p><?php endif; ?><?php if ($area['pincode']): ?><p class="small mb-0">PIN <?= e(
    $area['pincode'],
) ?></p><?php endif; ?></article></div><?php endforeach; ?></div><?php endif; ?></main><?php include
    __DIR__ . '/includes/footer.php';
