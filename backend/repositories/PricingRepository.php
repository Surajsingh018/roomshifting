<?php
declare(strict_types=1);

/** PDO persistence for enquiries, pricing rules, and server-calculated quotes. */
final class PricingRepository
{
    public function __construct(private PDO $pdo) {}

    public function findIdempotentEnquiry(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,name,email,phone,message,service_id,from_address,to_address,floors,distance_km,packing_required,inventory FROM enquiries WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function createEnquiry(array $enquiry): array
    {
        try {
            $this->pdo->beginTransaction();
            $existing = $this->findIdempotentEnquiry($enquiry['idempotency_key']);
            if ($existing !== null) {
                if (!$this->sameEnquiry($existing, $enquiry)) {
                    $this->pdo->rollBack();
                    api_error(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for different enquiry details.');
                }
                $this->pdo->commit();
                return ['id' => (int)$existing['id'], 'status' => 'new', 'duplicate' => true];
            }

            $service = $this->pdo->prepare("SELECT id FROM services WHERE id = ? AND status = 'active' LIMIT 1");
            $service->execute([$enquiry['service_id']]);
            if (!$service->fetchColumn()) {
                $this->pdo->rollBack();
                api_error(422, 'VALIDATION_ERROR', 'Choose an active service.', ['service_id' => 'Service is unavailable.']);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO enquiries (name, email, phone, message, idempotency_key, service_id, from_address, to_address, floors, distance_km, packing_required, inventory, status) VALUES (?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?, \'new\')'
            );
            $stmt->execute([
                $enquiry['name'], $enquiry['email'], $enquiry['phone'], $enquiry['message'],
                $enquiry['idempotency_key'], $enquiry['service_id'], $enquiry['from_address'],
                $enquiry['to_address'], $enquiry['floors'], $enquiry['distance_km'],
                $enquiry['packing_required'] ? 1 : 0, json_encode($enquiry['inventory'], JSON_THROW_ON_ERROR),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['id' => $id, 'status' => 'new', 'duplicate' => false];
        } catch (PDOException $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // Concurrent requests with one key converge on the first persisted enquiry.
            if (($error->errorInfo[1] ?? null) === 1062) {
                $existing = $this->findIdempotentEnquiry($enquiry['idempotency_key']);
                if ($existing !== null) {
                    if (!$this->sameEnquiry($existing, $enquiry)) api_error(409, 'IDEMPOTENCY_CONFLICT', 'This idempotency key was already used for different enquiry details.');
                    return ['id' => (int)$existing['id'], 'status' => 'new', 'duplicate' => true];
                }
            }
            throw $error;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function sameEnquiry(array $stored, array $incoming): bool
    {
        $storedInventory = json_decode((string)($stored['inventory'] ?? '[]'), true);
        if (!is_array($storedInventory)) return false;
        $emptyToNull = static fn(mixed $value): string => trim((string)($value ?? ''));
        return $emptyToNull($stored['name'] ?? null) === $incoming['name']
            && $emptyToNull($stored['email'] ?? null) === $incoming['email']
            && $emptyToNull($stored['phone'] ?? null) === $incoming['phone']
            && $emptyToNull($stored['message'] ?? null) === $incoming['message']
            && (int)$stored['service_id'] === (int)$incoming['service_id']
            && $emptyToNull($stored['from_address'] ?? null) === $incoming['from_address']
            && $emptyToNull($stored['to_address'] ?? null) === $incoming['to_address']
            && (int)$stored['floors'] === (int)$incoming['floors']
            && number_format((float)$stored['distance_km'], 2, '.', '') === $incoming['distance_km']
            && (int)$stored['packing_required'] === (int)$incoming['packing_required']
            && $storedInventory === $incoming['inventory'];
    }

    public function enquiryCapabilityMatches(int $enquiryId, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT idempotency_key FROM enquiries WHERE id=? LIMIT 1');
        $stmt->execute([$enquiryId]);
        $key = $stmt->fetchColumn();
        return $key !== false && hash_equals(api_public_capability('enquiry', $enquiryId, (string)$key), $token);
    }

    public function quoteCapabilityMatches(int $quoteId, string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT q.id,e.idempotency_key FROM quotes q JOIN enquiries e ON e.id=q.enquiry_id WHERE q.id=? LIMIT 1');
        $stmt->execute([$quoteId]);
        $row = $stmt->fetch();
        return $row !== false && hash_equals(api_public_capability('quote', $quoteId, (string)$row['idempotency_key']), $token);
    }

    public function enquiryIdempotencyKey(int $enquiryId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT idempotency_key FROM enquiries WHERE id=? LIMIT 1');
        $stmt->execute([$enquiryId]);
        $key = $stmt->fetchColumn();
        return $key === false ? null : (string)$key;
    }

    public function enquiryForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, service_id, floors, distance_km, packing_required FROM enquiries WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function pricingRule(int $serviceId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT r.base_price, r.per_km, r.per_floor, r.packing_charges, r.gst_percent, r.min_charge FROM pricing_rules r JOIN services s ON s.id = r.service_id WHERE r.service_id = ? AND r.is_active = 1 AND s.status = 'active' LIMIT 1");
        $stmt->execute([$serviceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function saveQuote(int $enquiryId, array $breakdown): array
    {
        $existing = $this->pdo->prepare('SELECT id FROM quotes WHERE enquiry_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $existing->execute([$enquiryId]);
        $quoteId = $existing->fetchColumn();
        $validUntil = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        $json = json_encode($breakdown, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($quoteId !== false) {
            $stmt = $this->pdo->prepare("UPDATE quotes SET calculation_breakdown = ?, subtotal = ?, gst = ?, total = ?, valid_until = ?, status = 'draft' WHERE id = ?");
            $stmt->execute([$json, $breakdown['subtotal'], $breakdown['gst'], $breakdown['total'], $validUntil, (int)$quoteId]);
            return ['id' => (int)$quoteId, 'valid_until' => $validUntil];
        }
        // service_id/customer fields are present in the foundation schema; the
        // nullable extras keep this insert compatible with the starter schema.
        $columns = $this->pdo->query('SHOW COLUMNS FROM quotes')->fetchAll(PDO::FETCH_COLUMN);
        $hasService = in_array('service_id', $columns, true);
        $hasCustomerName = in_array('customer_name', $columns, true);
        if ($hasService && $hasCustomerName) {
            $customer = $this->pdo->prepare('SELECT name, email, phone, service_id FROM enquiries WHERE id = ?');
            $customer->execute([$enquiryId]);
            $record = $customer->fetch();
            $stmt = $this->pdo->prepare("INSERT INTO quotes (enquiry_id, service_id, customer_name, customer_email, customer_phone, calculation_breakdown, subtotal, gst, total, valid_until, status) VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, 'draft')");
            $stmt->execute([$enquiryId, $record['service_id'], $record['name'], $record['email'] ?? '', $record['phone'], $json, $breakdown['subtotal'], $breakdown['gst'], $breakdown['total'], $validUntil]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO quotes (enquiry_id, calculation_breakdown, subtotal, gst, total, valid_until, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')");
            $stmt->execute([$enquiryId, $json, $breakdown['subtotal'], $breakdown['gst'], $breakdown['total'], $validUntil]);
        }
        return ['id' => (int)$this->pdo->lastInsertId(), 'valid_until' => $validUntil];
    }

    public function findQuote(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, enquiry_id, calculation_breakdown, subtotal, gst, total, valid_until, status, created_at FROM quotes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if ($quote === false) return null;
        if (is_string($quote['calculation_breakdown'] ?? null)) {
            $quote['calculation_breakdown'] = json_decode($quote['calculation_breakdown'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $quote;
    }
}
