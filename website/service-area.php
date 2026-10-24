<?php

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/backend/repositories/CmsRepository.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
$slug = (string) ($_GET['slug'] ?? '');
$area = null;
$failed = false;
try {
  $area = (new CmsRepository(roomshift_db()))->findServiceArea($slug);
} catch (Throwable $error) {
  $failed = true;
  error_log('[RoomShift area page] ' . $error->getMessage());
}
if (!$area) {
  http_response_code($failed ? 503 : 404);
  $pageTitle = $failed ? 'Service areas unavailable | RoomShift' : 'Service area not found | RoomShift';
  $pageMeta = 'The requested service area could not be loaded.';
  include __DIR__ . '/includes/header.php';
  include __DIR__ . '/includes/navbar.php'; ?><main class="container section-space">
    <h1><?= $failed
          ? 'Service areas unavailable'
          : 'Service area not found' ?></h1>
    <p><?= $failed
          ? e($t('error'))
          : 'This area is unavailable or has not been published.' ?></p><a href="service-areas.php" class="btn btn-outline-dark">View service areas</a>
  </main><?php include
            __DIR__ . '/includes/footer.php';
          exit();
        }
        $pageTitle = $area['seo_title'] ?: $area['city'] . ' moving services | RoomShift';
        $pageMeta = $area['seo_desc'] ?: 'See moving availability in ' . ($area['city'] ?? 'this area') . '.';
        $pageCanonical = $area['canonical_url'] ?: roomshift_base_url() . '/service-area.php?slug=' . rawurlencode($slug);
        include __DIR__ . '/includes/header.php';
        include
          __DIR__
          . '/includes/navbar.php'; ?><main class="container section-space">
  <p class="eyebrow">Service area</p>
  <h1><?= e(
        $area['city'],
      ) ?></h1>
  <h2 class="h4"><?= e($area['name']) ?></h2><?php if ($area['region']): ?><p><?= e(
                                                                                $area['region'],
                                                                              ) ?></p><?php endif; ?><?php if ($area['pincode']): ?><p>PIN <?= e($area['pincode']) ?></p><?php endif; ?><?php if (
                                                                                                            $area['description']
                                                                                                          ): ?><p><?= nl2br(e(
                                                                                                              $area['description'],
                                                                                                            )) ?></p><?php endif; ?><a href="quote.php" class="btn btn-gold">Request a quote</a>
</main><?php include
          __DIR__ . '/includes/footer.php';
