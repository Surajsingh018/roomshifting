<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/ui.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$selected = null;
if ($id) {
  $stmt = $pdo->prepare('SELECT q.*,e.name,e.phone,e.email FROM quotes q LEFT JOIN enquiries e ON e.id=q.enquiry_id WHERE q.id=?');
  $stmt->execute([$id]);
  $selected = $stmt->fetch();
  if ($selected && is_string($selected['calculation_breakdown'])) $selected['calculation_breakdown'] = json_decode($selected['calculation_breakdown'], true);
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$count = (int)$pdo->query('SELECT COUNT(*) FROM quotes')->fetchColumn();
$stmt = $pdo->prepare('SELECT q.id,q.enquiry_id,q.subtotal,q.gst,q.total,q.status,q.valid_until,q.created_at,e.name,e.phone FROM quotes q LEFT JOIN enquiries e ON e.id=q.enquiry_id ORDER BY q.id DESC LIMIT ? OFFSET ?');
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, ($page - 1) * $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
admin_page_start('Quotes'); ?><h1 class="h2 mb-4">Quotes</h1><?php if ($selected): ?><section class="card mb-4">
    <div class="card-body">
      <h2 class="h5">Quote #<?= (int)$selected['id'] ?></h2>
      <p><?= e($selected['name']) ?> · <?= e($selected['phone']) ?> · Total <strong>₹<?= e($selected['total']) ?></strong></p>
      <h3 class="h6">Server calculation breakdown</h3>
      <pre class="bg-light p-3 rounded"><?= e(json_encode($selected['calculation_breakdown'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
  </section><?php endif; ?><section class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Quote</th>
            <th>Enquiry</th>
            <th>Customer</th>
            <th>Subtotal</th>
            <th>GST</th>
            <th>Total</th>
            <th>Status</th>
            <th>Valid until</th>
          </tr>
        </thead>
        <tbody><?php foreach ($rows as $r): ?><tr>
              <td><a href="?id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td>
              <td><?= e($r['enquiry_id']) ?></td>
              <td><?= e($r['name']) ?><br><small><?= e($r['phone']) ?></small></td>
              <td>₹<?= e($r['subtotal']) ?></td>
              <td>₹<?= e($r['gst']) ?></td>
              <td>₹<?= e($r['total']) ?></td>
              <td><?= e($r['status']) ?></td>
              <td><?= e($r['valid_until']) ?></td>
            </tr><?php endforeach; ?><?php if (!$rows): ?><tr>
              <td colspan="8" class="text-center text-secondary py-4">No quotes recorded yet.</td>
            </tr><?php endif; ?></tbody>
      </table>
    </div>
    <p class="small text-secondary"><?= $count ?> quotes · page <?= $page ?></p>
  </div>
</section><?php admin_page_end();
