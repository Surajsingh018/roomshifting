<?php

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/backend/repositories/CmsRepository.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
$slug = (string) ($_GET['slug'] ?? '');
$page = null;
$failed = false;
try {
  $page = (new CmsRepository(roomshift_db()))->findPage($slug);
} catch (Throwable $error) {
  $failed = true;
  error_log('[RoomShift page CMS] ' . $error->getMessage());
}
if (!$page) {
  http_response_code($failed ? 503 : 404);
  $pageTitle = $failed ? 'Page unavailable | RoomShift' : 'Page not found | RoomShift';
  $pageMeta = 'The requested page could not be loaded.';
  include __DIR__ . '/includes/header.php';
  include __DIR__ . '/includes/navbar.php'; ?><main class="container section-space">
    <h1><?= $failed
          ? 'Page unavailable'
          : 'Page not found' ?></h1>
    <p><?= $failed
          ? e($t('error'))
          : 'This page has not been published.' ?></p><a href="index.php" class="btn btn-outline-dark">Return home</a>
  </main><?php include
            __DIR__ . '/includes/footer.php';
          exit();
        }
        $pageTitle = $page['seo_title'] ?: $page['title'] . ' | RoomShift';
        $pageMeta = $page['seo_desc'] ?: $page['title'] . ' information from RoomShift.';
        $pageCanonical = $page['canonical_url'] ?: roomshift_base_url() . '/page.php?slug=' . rawurlencode($slug);
        $pageImage = $page['og_image'] ?? '';
        include __DIR__ . '/includes/header.php';
        include
          __DIR__
          . '/includes/navbar.php'; ?><main class="container section-space cms-page">
  <p class="eyebrow">RoomShift information</p>
  <h1><?= e(
        $page['title'],
      ) ?></h1>
  <div class="cms-copy"><?= nl2br(e($page['body'])) ?></div>
</main><?php include
          __DIR__ . '/includes/footer.php';
