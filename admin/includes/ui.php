<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
admin_require_login();
function admin_page_start(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> | RoomShift Admin</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="../website/css/style.css" rel="stylesheet"><link href="css/admin.css" rel="stylesheet"></head><body class="admin-shell"><?php include __DIR__ . '/nav.php'; ?><main class="container-fluid px-3 px-lg-4 py-4"><?php }
function admin_page_end(): void { ?></main><script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html><?php }
