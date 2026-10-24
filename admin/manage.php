<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/ui.php';
$catalog = [
  'services' => ['title' => 'Services', 'table' => 'services', 'search' => ['name', 'slug', 'description'], 'soft' => ['status', 'inactive'], 'fields' => ['name' => ['Name', 'text', true], 'slug' => ['Slug', 'text', true], 'description' => ['Description', 'textarea', false], 'base_price' => ['Base price (₹)', 'number', true], 'pricing_type' => ['Pricing type', 'select:fixed,quote', true], 'status' => ['Status', 'select:active,inactive', true], 'seo_title' => ['SEO title', 'text', false], 'seo_desc' => ['SEO description', 'text', false], 'canonical_url' => ['Canonical URL', 'url', false], 'og_image' => ['Open Graph image path', 'text', false]]],
  'service_areas' => ['title' => 'Service areas', 'table' => 'service_areas', 'search' => ['name', 'city', 'pincode', 'slug'], 'soft' => ['status', 'inactive'], 'fields' => ['name' => ['Name', 'text', true], 'slug' => ['Slug', 'text', true], 'pincode' => ['Pincode', 'text', false], 'city' => ['City', 'text', true], 'region' => ['Region', 'text', false], 'description' => ['Description', 'textarea', false], 'status' => ['Status', 'select:active,inactive', true], 'seo_title' => ['SEO title', 'text', false], 'seo_desc' => ['SEO description', 'text', false], 'canonical_url' => ['Canonical URL', 'url', false]]],
  'pricing_rules' => ['title' => 'Pricing rules', 'table' => 'pricing_rules', 'search' => ['service_id'], 'soft' => ['is_active', '0'], 'admin_only' => true, 'fields' => ['service_id' => ['Service', 'service', true], 'base_price' => ['Base price', 'number', true], 'per_km' => ['Per kilometer', 'number', true], 'per_floor' => ['Per floor', 'number', true], 'packing_charges' => ['Packing charge', 'number', true], 'gst_percent' => ['GST percent', 'number', true], 'min_charge' => ['Minimum charge', 'number', true], 'is_active' => ['Active', 'checkbox', false]]],
  'pages' => ['title' => 'Pages', 'table' => 'pages', 'search' => ['title', 'slug', 'body'], 'soft' => ['status', 'draft'], 'fields' => ['title' => ['Title', 'text', true], 'slug' => ['Slug', 'text', true], 'body' => ['Page content', 'textarea', true], 'status' => ['Status', 'select:draft,published', true], 'seo_title' => ['SEO title', 'text', false], 'seo_desc' => ['SEO description', 'text', false], 'canonical_url' => ['Canonical URL', 'url', false], 'og_title' => ['Open Graph title', 'text', false], 'og_description' => ['Open Graph description', 'text', false], 'og_image' => ['Open Graph image', 'text', false], 'published_at' => ['Publish at (optional)', 'datetime-local', false]]],
  'faqs' => ['title' => 'FAQs', 'table' => 'faqs', 'search' => ['question', 'answer'], 'soft' => ['status', 'inactive'], 'fields' => ['question' => ['Question', 'text', true], 'answer' => ['Answer', 'textarea', true], 'sort_order' => ['Sort order', 'number', true], 'status' => ['Status', 'select:active,inactive', true]]],
  'banners' => ['title' => 'Banners', 'table' => 'banners', 'search' => ['title', 'body'], 'soft' => ['status', 'inactive'], 'fields' => ['title' => ['Title', 'text', true], 'body' => ['Message', 'textarea', false], 'image_path' => ['Image path', 'text', false], 'link_url' => ['Link URL', 'url', false], 'starts_at' => ['Starts at', 'datetime-local', false], 'ends_at' => ['Expires at', 'datetime-local', false], 'sort_order' => ['Sort order', 'number', true], 'status' => ['Status', 'select:active,inactive', true]]],
  'offers' => ['title' => 'Offers', 'table' => 'offers', 'search' => ['title', 'code', 'description'], 'soft' => ['status', 'inactive'], 'fields' => ['title' => ['Title', 'text', true], 'code' => ['Coupon code', 'text', false], 'description' => ['Description', 'textarea', false], 'discount_type' => ['Discount type', 'select:fixed,percent', true], 'discount_value' => ['Discount value', 'number', true], 'starts_at' => ['Starts at', 'datetime-local', false], 'ends_at' => ['Expires at', 'datetime-local', false], 'status' => ['Status', 'select:active,inactive', true]]],
  'staff' => ['title' => 'Staff', 'table' => 'staff', 'search' => ['name', 'email', 'phone'], 'soft' => ['status', 'inactive'], 'fields' => ['user_id' => ['Linked login user ID (optional)', 'number', false], 'name' => ['Name', 'text', true], 'email' => ['Email', 'email', false], 'phone' => ['Phone', 'text', true], 'status' => ['Status', 'select:active,inactive', true]]],
  'partners' => ['title' => 'Partners', 'table' => 'partners', 'search' => ['name', 'email', 'phone'], 'soft' => ['status', 'inactive'], 'fields' => ['name' => ['Name', 'text', true], 'email' => ['Email', 'email', false], 'phone' => ['Phone', 'text', true], 'service_area_id' => ['Service area ID (optional)', 'number', false], 'capacity_per_day' => ['Capacity per day', 'number', true], 'verification_status' => ['Verification', 'select:pending,verified,rejected', true], 'status' => ['Status', 'select:active,inactive', true]]],
];
$type = (string)($_GET['type'] ?? 'services');
if (!isset($catalog[$type])) {
  http_response_code(404);
  exit('Unknown catalog type.');
}
$cfg = $catalog[$type];
if (!empty($cfg['admin_only'])) admin_require_role(['admin']);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$record = null;
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  admin_verify_csrf();
  $action = (string)($_POST['action'] ?? 'save');
  if ($action === 'deactivate') {
    admin_require_role(['admin']);
    $rowId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$rowId || empty($cfg['soft'])) {
      http_response_code(422);
      exit('Invalid action.');
    }
    [$column, $value] = $cfg['soft'];
    $stmt = $pdo->prepare("UPDATE {$cfg['table']} SET {$column}=? WHERE id=?");
    $stmt->execute([$value, $rowId]);
    header('Location: manage.php?type=' . rawurlencode($type) . '&saved=1');
    exit;
  }
  $rowId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
  $values = [];
  foreach ($cfg['fields'] as $field => $meta) {
    [$label, $kind, $required] = $meta;
    $value = $_POST[$field] ?? ($kind === 'checkbox' ? 0 : null);
    if ($kind === 'checkbox') {
      $values[$field] = isset($_POST[$field]) ? 1 : 0;
      continue;
    }
    if ($value === null || is_array($value)) {
      if ($required) {
        $notice = "$label is required.";
        break;
      }
      continue;
    }
    $value = trim((string)$value);
    if ($required && $value === '') {
      $notice = "$label is required.";
      break;
    }
    if ($value === '' && !$required) {
      $values[$field] = null;
      continue;
    }
    if ($kind === 'number') {
      if (!is_numeric($value) || (float)$value < 0) {
        $notice = "$label must be a non-negative number.";
        break;
      }
      $value = number_format((float)$value, 2, '.', '');
    }
    if ($kind === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
      $notice = 'Enter a valid email.';
      break;
    }
    if ($kind === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
      $notice = 'Enter a valid URL.';
      break;
    }
    if ($kind === 'datetime-local' && $value !== '') {
      $value = str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
    }
    if (str_contains($kind, 'select:') && !in_array($value, explode(',', substr($kind, 7)), true)) {
      $notice = "Invalid $label.";
      break;
    }
    $values[$field] = $value;
  }
  if ($notice === '') {
    try {
      if ($rowId) {
        $sets = array_map(static fn($field) => "$field=?", array_keys($values));
        $stmt = $pdo->prepare('UPDATE ' . $cfg['table'] . ' SET ' . implode(',', $sets) . ' WHERE id=?');
        $stmt->execute([...array_values($values), $rowId]);
      } else {
        $cols = array_keys($values);
        $stmt = $pdo->prepare('INSERT INTO ' . $cfg['table'] . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $stmt->execute(array_values($values));
      }
      header('Location: manage.php?type=' . rawurlencode($type) . '&saved=1');
      exit;
    } catch (Throwable $error) {
      error_log('[RoomShift admin CRUD] ' . $error->getMessage());
      $notice = 'Could not save this record. Check required fields and duplicate slugs/codes.';
    }
  }
}
if ($id) {
  $stmt = $pdo->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE id=?');
  $stmt->execute([$id]);
  $record = $stmt->fetch();
}
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '' && $cfg['search']) {
  $where = ' WHERE ' . implode(' OR ', array_map(static fn($field) => "$field LIKE ?", $cfg['search']));
  foreach ($cfg['search'] as $_) $params[] = '%' . $search . '%';
}
$count = $pdo->prepare('SELECT COUNT(*) FROM ' . $cfg['table'] . $where);
$count->execute($params);
$total = (int)$count->fetchColumn();
$stmt = $pdo->prepare('SELECT * FROM ' . $cfg['table'] . $where . ' ORDER BY id DESC LIMIT ? OFFSET ?');
foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
$stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, ($page - 1) * $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$services = $type === 'pricing_rules' ? $pdo->query("SELECT id,name FROM services ORDER BY name")->fetchAll() : [];
admin_page_start($cfg['title']); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <p class="small text-uppercase fw-semibold mb-1">Content management</p>
    <h1 class="h2 mb-0"><?= e($cfg['title']) ?></h1>
  </div><a class="btn btn-outline-dark" href="manage.php?type=<?= e($type) ?>">Add new</a>
</div>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Record saved.</div><?php endif; ?><?php if ($notice): ?><div class="alert alert-danger"><?= e($notice) ?></div><?php endif; ?>
<div class="row g-4">
  <div class="col-12 col-xxl-4">
    <section class="card">
      <div class="card-body">
        <h2 class="h5 mb-3"><?= $record ? 'Edit record #' . (int)$record['id'] : 'Create record' ?></h2>
        <form method="post"><input type="hidden" name="_csrf_token" value="<?= e(admin_csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($record['id'] ?? 0) ?>">
          <?php foreach ($cfg['fields'] as $field => $meta): [$label, $kind, $required] = $meta;
            $value = $record[$field] ?? '';
            $control = str_starts_with($kind, 'select:') ? 'select' : $kind; ?><div class="mb-3"><label class="form-label" for="f_<?= e($field) ?>"><?= e($label) ?></label><?php if ($control === 'textarea'): ?><textarea class="form-control" id="f_<?= e($field) ?>" name="<?= e($field) ?>" rows="4" <?= $required ? 'required' : '' ?>><?= e($value) ?></textarea><?php elseif ($control === 'select'): ?><select class="form-select" id="f_<?= e($field) ?>" name="<?= e($field) ?>" <?= $required ? 'required' : '' ?>><?php foreach (explode(',', substr($kind, 7)) as $option): ?><option value="<?= e($option) ?>" <?= (string)$value === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select><?php elseif ($control === 'service'): ?><select class="form-select" id="f_<?= e($field) ?>" name="<?= e($field) ?>" required>
                  <option value="">Choose service</option><?php foreach ($services as $service): ?><option value="<?= (int)$service['id'] ?>" <?= (string)$value === (string)$service['id'] ? 'selected' : '' ?>><?= e($service['name']) ?></option><?php endforeach; ?>
                </select><?php elseif ($control === 'checkbox'): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="f_<?= e($field) ?>" name="<?= e($field) ?>" value="1" <?= $value ? 'checked' : '' ?>><label class="form-check-label" for="f_<?= e($field) ?>">Enabled</label></div><?php else: ?><input class="form-control" id="f_<?= e($field) ?>" type="<?= e($control) ?>" name="<?= e($field) ?>" value="<?= e($value) ?>" <?= $required ? 'required' : '' ?> <?= $control === 'number' ? 'step="0.01" min="0"' : '' ?>><?php endif; ?></div><?php endforeach; ?>
          <button class="btn btn-gold" type="submit">Save</button>
        </form><?php if ($record && $cfg['soft']): ?><form method="post" class="mt-3" onsubmit="return confirm('Deactivate this record?')"><input type="hidden" name="_csrf_token" value="<?= e(admin_csrf_token()) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><button class="btn btn-outline-danger btn-sm">Deactivate</button></form><?php endif; ?>
      </div>
    </section>
    <?php if (in_array($type, ['banners', 'offers'], true)): ?><section class="card mt-3">
        <div class="card-body">
          <h2 class="h6">Upload image</h2>
          <form method="post" action="upload.php" enctype="multipart/form-data"><input type="hidden" name="_csrf_token" value="<?= e(admin_csrf_token()) ?>"><input class="form-control mb-2" type="file" name="media" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-outline-dark btn-sm">Upload validated image</button></form>
        </div>
      </section><?php endif; ?>
  </div>
  <div class="col-12 col-xxl-8">
    <section class="card">
      <div class="card-body">
        <form class="row g-2 mb-3"><input type="hidden" name="type" value="<?= e($type) ?>">
          <div class="col"><label class="visually-hidden" for="q">Search records</label><input id="q" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search <?= e(strtolower($cfg['title'])) ?>"></div>
          <div class="col-auto"><button class="btn btn-outline-dark">Search</button></div>
        </form>
        <p class="small text-secondary">Showing <?= count($rows) ?> of <?= $total ?> records. Campaigns outside their active schedule are hidden from the public catalog automatically.</p>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>ID</th><?php foreach (array_slice(array_keys($cfg['fields']), 0, 4) as $field): ?><th><?= e($cfg['fields'][$field][0]) ?></th><?php endforeach; ?><th>Action</th>
              </tr>
            </thead>
            <tbody><?php foreach ($rows as $row): ?><tr>
                  <td><?= (int)$row['id'] ?></td><?php foreach (array_slice(array_keys($cfg['fields']), 0, 4) as $field): ?><td><?= e(mb_strimwidth((string)($row[$field] ?? ''), 0, 75, '…')) ?></td><?php endforeach; ?><td><a class="btn btn-sm btn-outline-primary" href="manage.php?type=<?= e($type) ?>&id=<?= (int)$row['id'] ?>">Edit</a></td>
                </tr><?php endforeach; ?><?php if (!$rows): ?><tr>
                  <td colspan="6" class="text-center text-secondary py-4">No records yet. Add content to publish it.</td>
                </tr><?php endif; ?></tbody>
          </table>
        </div>
        <nav aria-label="Pagination">
          <ul class="pagination pagination-sm mb-0"><?php for ($p = 1; $p <= max(1, (int)ceil($total / $limit)); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?type=<?= e($type) ?>&q=<?= rawurlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a></li><?php endfor; ?></ul>
        </nav>
      </div>
    </section>
  </div>
</div>
<?php admin_page_end();
