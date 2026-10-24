<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/ui.php';
$metrics = [
    'Services' => (int) $pdo->query("SELECT COUNT(*) FROM services WHERE status='active'")->fetchColumn(),
    'Enquiries' => (int) $pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn(),
    'Quotes' => (int) $pdo->query('SELECT COUNT(*) FROM quotes')->fetchColumn(),
    'Bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'Active bookings' => (int) $pdo->query(
        "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','assigned','in_transit')",
    )->fetchColumn(),
    'Revenue collected' => (float) $pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='paid'",
    )->fetchColumn(),
];
$statuses = $pdo->query('SELECT status,COUNT(*) AS total FROM bookings GROUP BY status ORDER BY status')->fetchAll();
$recent = $pdo->query('SELECT id,name,phone,status,created_at FROM enquiries ORDER BY id DESC LIMIT 8')->fetchAll();
admin_page_start('Dashboard'); ?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <p class="text-uppercase small fw-semibold mb-1">Operations overview</p>
        <h1 class="h2 mb-0">Dashboard</h1>
    </div><a class="btn btn-gold" href="manage.php?type=services">Manage catalog</a>
</div>
<div class="row g-3 mb-4"><?php foreach ($metrics as $label => $value): ?><div class="col-6 col-xl-2">
            <section class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary"><?= e($label) ?></div>
                    <div class="fs-3 fw-bold"><?= $label === 'Revenue collected'
                        ? '₹' . number_format((float) $value, 2)
                        : number_format((int) $value) ?></div>
                </div>
            </section>
        </div><?php endforeach; ?></div>
<div class="row g-4">
    <div class="col-xl-5">
        <section class="card h-100">
            <div class="card-body">
                <h2 class="h5">Bookings by status</h2><?php if (
                    !$statuses
                ): ?><p class="text-secondary">No bookings recorded yet.</p><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($statuses as $row): ?><li class="list-group-item d-flex justify-content-between"><span><?= e(ucwords(str_replace(
                    '_',
                    ' ',
                    $row['status'],
                ))) ?></span><strong><?= (int) $row['total'] ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="card h-100">
            <div class="card-body">
                <h2 class="h5">Recent enquiries</h2><?php if (
                    !$recent
                ): ?><p class="text-secondary">No enquiries yet.</p><?php else: ?><div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($recent as $row): ?><tr>
                                        <td><?= e($row['name']) ?></td>
                                        <td><?= e($row['phone']) ?></td>
                                        <td><?= e($row['status']) ?></td>
                                        <td><?= e($row['created_at']) ?></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div><?php endif; ?>
            </div>
        </section>
    </div>
</div>
<?php admin_page_end();
