<?php
declare(strict_types=1);

/** Deterministic quote math. Pricing inputs come only from persisted rules/enquiry. */
final class PricingEngine
{
    public function calculate(array $rule, array $enquiry): array
    {
        $base = $this->money($rule['base_price']);
        $perKm = $this->money($rule['per_km']);
        $perFloor = $this->money($rule['per_floor']);
        $packingRate = $this->money($rule['packing_charges']);
        $gstPercent = (float)$rule['gst_percent'];
        $minimum = $this->money($rule['min_charge']);
        if (!is_finite($gstPercent) || $gstPercent < 0 || $gstPercent > 100) {
            throw new DomainException('Stored pricing rule has an invalid GST percentage.');
        }

        $distance = (float)$enquiry['distance_km'];
        $floors = (int)$enquiry['floors'];
        $distanceCharge = round($distance * $perKm, 2, PHP_ROUND_HALF_UP);
        $floorCharge = round($floors * $perFloor, 2, PHP_ROUND_HALF_UP);
        $packing = (bool)$enquiry['packing_required'] ? $packingRate : 0.00;
        $calculatedSubtotal = round($base + $distanceCharge + $floorCharge + $packing, 2, PHP_ROUND_HALF_UP);
        $subtotal = max($calculatedSubtotal, $minimum);
        $gst = round($subtotal * $gstPercent / 100, 2, PHP_ROUND_HALF_UP);
        $total = round($subtotal + $gst, 2, PHP_ROUND_HALF_UP);

        return [
            'currency' => 'INR',
            'base_price' => number_format($base, 2, '.', ''),
            'distance_km' => number_format($distance, 2, '.', ''),
            'per_km' => number_format($perKm, 2, '.', ''),
            'distance_charge' => number_format($distanceCharge, 2, '.', ''),
            'floors' => $floors,
            'per_floor' => number_format($perFloor, 2, '.', ''),
            'floor_charge' => number_format($floorCharge, 2, '.', ''),
            'packing_required' => (bool)$enquiry['packing_required'],
            'packing_charges' => number_format($packing, 2, '.', ''),
            'calculated_subtotal' => number_format($calculatedSubtotal, 2, '.', ''),
            'minimum_charge' => number_format($minimum, 2, '.', ''),
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'gst_percent' => number_format($gstPercent, 2, '.', ''),
            'gst' => number_format($gst, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }

    public function validateEnquiry(array $input): array
    {
        foreach (['total', 'subtotal', 'gst', 'base_price', 'per_km', 'per_floor', 'packing_charges', 'gst_percent', 'min_charge'] as $untrustedPrice) {
            if (array_key_exists($untrustedPrice, $input)) {
                api_error(422, 'CLIENT_PRICING_FORBIDDEN', 'Quote totals and pricing rates are calculated by the server.', [$untrustedPrice => 'Do not submit pricing values.']);
            }
        }
        $allowed = ['idempotency_key', 'name', 'phone', 'email', 'service_id', 'from_address', 'to_address', 'floors', 'distance_km', 'packing_required', 'inventory', 'message'];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowed, true)) {
                api_error(422, 'VALIDATION_ERROR', 'The enquiry contains an unsupported field.', [$field => 'Unexpected field.']);
            }
        }

        $key = $this->text($input['idempotency_key'] ?? null, 'idempotency_key', 8, 128);
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $key)) api_error(422, 'VALIDATION_ERROR', 'Idempotency key contains unsupported characters.');
        $name = $this->text($input['name'] ?? null, 'name', 1, 120);
        $phone = $this->text($input['phone'] ?? null, 'phone', 7, 25);
        if (!preg_match('/^[0-9+().\-\s]{7,25}$/', $phone)) api_error(422, 'VALIDATION_ERROR', 'Enter a valid phone number.', ['phone' => 'Invalid phone number.']);
        $email = $this->optionalText($input['email'] ?? null, 'email', 190);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error(422, 'VALIDATION_ERROR', 'Enter a valid email address.', ['email' => 'Invalid email address.']);
        $serviceId = filter_var($input['service_id'] ?? null, FILTER_VALIDATE_INT);
        if ($serviceId === false || $serviceId < 1) api_error(422, 'VALIDATION_ERROR', 'Choose a valid service.', ['service_id' => 'Positive integer required.']);
        $from = $this->text($input['from_address'] ?? null, 'from_address', 1, 500);
        $to = $this->text($input['to_address'] ?? null, 'to_address', 1, 500);
        $floors = $this->integer($input['floors'] ?? 0, 'floors', 0, 100);
        $distance = $this->decimal($input['distance_km'] ?? null, 'distance_km', 0, 10000, 2);
        $packing = $input['packing_required'] ?? false;
        if (!is_bool($packing) && !in_array($packing, [0, 1, '0', '1'], true)) api_error(422, 'VALIDATION_ERROR', 'packing_required must be boolean.');
        $inventory = $input['inventory'] ?? [];
        if (!is_array($inventory) || !array_is_list($inventory) || count($inventory) > 100) api_error(422, 'VALIDATION_ERROR', 'Inventory must be a list of at most 100 items.');
        $cleanInventory = [];
        foreach ($inventory as $index => $item) {
            if (!is_array($item)) api_error(422, 'VALIDATION_ERROR', 'Each inventory item must contain item and quantity.');
            $label = $this->text($item['item'] ?? null, "inventory.{$index}.item", 1, 120);
            $quantity = $this->integer($item['quantity'] ?? null, "inventory.{$index}.quantity", 1, 10000);
            $cleanInventory[] = ['item' => $label, 'quantity' => $quantity];
        }
        $message = $this->optionalText($input['message'] ?? null, 'message', 3000);

        return [
            'idempotency_key' => $key, 'name' => $name, 'email' => $email,
            'phone' => $phone, 'message' => $message, 'service_id' => (int)$serviceId,
            'from_address' => $from, 'to_address' => $to, 'floors' => $floors,
            'distance_km' => number_format($distance, 2, '.', ''),
            'packing_required' => (bool)$packing, 'inventory' => $cleanInventory,
        ];
    }

    private function money(mixed $value): float
    {
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || $number > 1000000000) throw new DomainException('Stored pricing rule has an invalid amount.');
        return round($number, 2, PHP_ROUND_HALF_UP);
    }

    private function text(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)) api_error(422, 'VALIDATION_ERROR', "{$field} must be text.", [$field => 'Invalid value.']);
        $value = trim($value);
        if (mb_strlen($value) < $min || mb_strlen($value) > $max) api_error(422, 'VALIDATION_ERROR', "{$field} must be between {$min} and {$max} characters.", [$field => 'Out of range.']);
        return $value;
    }

    private function optionalText(mixed $value, string $field, int $max): string
    {
        if ($value === null || $value === '') return '';
        if (!is_string($value) || mb_strlen(trim($value)) > $max) api_error(422, 'VALIDATION_ERROR', "{$field} must be at most {$max} characters.", [$field => 'Invalid value.']);
        return trim($value);
    }

    private function integer(mixed $value, string $field, int $min, int $max): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $min || $integer > $max) api_error(422, 'VALIDATION_ERROR', "{$field} must be an integer between {$min} and {$max}.", [$field => 'Out of range.']);
        return (int)$integer;
    }

    private function decimal(mixed $value, string $field, float $min, float $max, int $scale): float
    {
        if (!is_numeric($value)) api_error(422, 'VALIDATION_ERROR', "{$field} must be numeric.", [$field => 'Invalid number.']);
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max || strlen(substr(strrchr((string)$value, '.') ?: '', 1)) > $scale) {
            api_error(422, 'VALIDATION_ERROR', "{$field} must be between {$min} and {$max} with at most {$scale} decimals.", [$field => 'Out of range.']);
        }
        return $number;
    }
}
