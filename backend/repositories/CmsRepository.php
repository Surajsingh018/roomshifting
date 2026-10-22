<?php
declare(strict_types=1);

/** Read-only CMS/catalog queries. All caller-supplied values are bound. */
final class CmsRepository
{
    public function __construct(private PDO $pdo) {}

    public function listServices(array $filters): array
    {
        $where = [];
        $params = [];
        $status = $this->status($filters['status'] ?? 'active');
        if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['name', 'description', 'slug']);
        return $this->paginate(
            'SELECT id, name, slug, description, base_price, pricing_type, status, seo_title, seo_desc, canonical_url, og_image, created_at, updated_at FROM services',
            $where, $params, ' ORDER BY name ASC, id ASC', $filters
        );
    }

    public function findService(string $slug): ?array
    {
        $slug = $this->slug($slug);
        $stmt = $this->pdo->prepare("SELECT id, name, slug, description, base_price, pricing_type, status, seo_title, seo_desc, canonical_url, og_image, created_at, updated_at FROM services WHERE slug=? AND status='active' LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function listServiceAreas(array $filters): array
    {
        $where = [];
        $params = [];
        $status = $this->status($filters['status'] ?? 'active');
        if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
        $city = $this->optionalText($filters['city'] ?? null, 'city', 120);
        if ($city !== null) { $where[] = 'city = ?'; $params[] = $city; }
        $pincode = $this->optionalText($filters['pincode'] ?? null, 'pincode', 20);
        if ($pincode !== null) { $where[] = 'pincode = ?'; $params[] = $pincode; }
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['name', 'city', 'region', 'pincode', 'description']);
        return $this->paginate(
            'SELECT id, name, slug, pincode, city, region, description, status, seo_title, seo_desc, canonical_url FROM service_areas',
            $where, $params, ' ORDER BY city ASC, name ASC, id ASC', $filters
        );
    }

    public function findServiceArea(string $slug): ?array
    {
        $slug = $this->slug($slug);
        $stmt = $this->pdo->prepare("SELECT id,name,slug,pincode,city,region,description,seo_title,seo_desc,canonical_url FROM service_areas WHERE slug=? AND status='active' LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findPage(string $slug): ?array
    {
        $slug = $this->slug($slug);
        $stmt = $this->pdo->prepare("SELECT id, title, slug, body, seo_title, seo_desc, canonical_url, og_title, og_description, og_image, published_at, updated_at FROM pages WHERE slug=? AND status='published' AND (published_at IS NULL OR published_at<=NOW()) LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function listPublishedPages(array $filters = []): array
    {
        $where = ["status='published'", '(published_at IS NULL OR published_at<=NOW())'];
        $params = [];
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['title', 'slug', 'body']);
        return $this->paginate(
            'SELECT id,title,slug,seo_title,seo_desc,canonical_url,og_title,og_description,og_image,published_at,updated_at FROM pages',
            $where, $params, ' ORDER BY title ASC, id ASC', $filters
        );
    }

    public function listFaqs(array $filters): array
    {
        $where = ["status='active'"];
        $params = [];
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['question', 'answer']);
        return $this->paginate(
            'SELECT id, question, answer, sort_order, created_at, updated_at FROM faqs',
            $where, $params, ' ORDER BY sort_order ASC, id ASC', $filters
        );
    }

    public function listBanners(array $filters): array
    {
        $where = ["status='active'", '(starts_at IS NULL OR starts_at<=NOW())', '(ends_at IS NULL OR ends_at>NOW())'];
        $params = [];
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['title', 'body']);
        return $this->paginate(
            'SELECT id, title, body, image_path, link_url, starts_at, ends_at, status, sort_order FROM banners',
            $where, $params, ' ORDER BY sort_order ASC, id DESC', $filters
        );
    }

    public function listOffers(array $filters): array
    {
        $where = ["status='active'", '(starts_at IS NULL OR starts_at<=NOW())', '(ends_at IS NULL OR ends_at>NOW())'];
        $params = [];
        $this->addSearch($where, $params, $filters['search'] ?? $filters['q'] ?? null, ['title', 'code', 'description']);
        return $this->paginate(
            'SELECT id, title, code, description, discount_type, discount_value, starts_at, ends_at, status FROM offers',
            $where, $params, ' ORDER BY starts_at ASC, id DESC', $filters
        );
    }

    private function paginate(string $select, array $where, array $params, string $orderBy, array $filters): array
    {
        [$page, $limit] = $this->pageAndLimit($filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . substr($select, strpos($select, ' FROM ') + 6) . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $this->pdo->prepare($select . $whereSql . $orderBy . ' LIMIT ? OFFSET ?');
        foreach ($params as $index => $value) $stmt->bindValue($index + 1, $value, PDO::PARAM_STR);
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        return [
            'items' => $stmt->fetchAll(),
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max(1, $limit))],
        ];
    }

    private function pageAndLimit(array $filters): array
    {
        $page = $this->positiveInt($filters['page'] ?? null, 'page', 1, 100000);
        $limit = $this->positiveInt($filters['limit'] ?? null, 'limit', 20, 100);
        return [$page, $limit];
    }

    private function positiveInt(mixed $value, string $field, int $default, int $max): int
    {
        if ($value === null || $value === '') return $default;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1 || $parsed > $max) {
            api_error(422, 'VALIDATION_ERROR', "{$field} must be an integer between 1 and {$max}.", [$field => 'Out of range.']);
        }
        return (int)$parsed;
    }

    private function status(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['active', 'inactive', 'all'], true)) {
            api_error(422, 'VALIDATION_ERROR', 'Status must be active, inactive, or all.', ['status' => 'Invalid status.']);
        }
        return $value;
    }

    private function slug(string $value): string
    {
        if (strlen($value) > 180 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            api_error(422, 'VALIDATION_ERROR', 'Slug must use lowercase letters, numbers, and single hyphens.', ['slug' => 'Invalid slug.']);
        }
        return $value;
    }

    private function optionalText(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || mb_strlen($value) > $maxLength) {
            api_error(422, 'VALIDATION_ERROR', "{$field} must be at most {$maxLength} characters.", [$field => 'Invalid value.']);
        }
        return trim($value);
    }

    private function addSearch(array &$where, array &$params, mixed $search, array $columns): void
    {
        $term = $this->optionalText($search, 'search', 120);
        if ($term === null) return;
        $where[] = '(' . implode(' OR ', array_map(static fn(string $column): string => "{$column} LIKE ?", $columns)) . ')';
        foreach ($columns as $_) $params[] = '%' . $term . '%';
    }
}
