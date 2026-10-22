<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../backend/services/PricingEngine.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}; expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$label}\n");
}

$engine = new PricingEngine();
$breakdown = $engine->calculate(
    ['base_price' => '100.00', 'per_km' => '2.50', 'per_floor' => '10.00', 'packing_charges' => '20.00', 'gst_percent' => '18.00', 'min_charge' => '200.00'],
    ['distance_km' => '10.00', 'floors' => 3, 'packing_required' => true]
);
assertSameValue('175.00', $breakdown['calculated_subtotal'], 'subtotal components are calculated from persisted inputs');
assertSameValue('200.00', $breakdown['subtotal'], 'minimum charge is enforced before tax');
assertSameValue('36.00', $breakdown['gst'], 'GST is calculated on the enforced subtotal');
assertSameValue('236.00', $breakdown['total'], 'server total includes GST');
assertSameValue('25.00', $breakdown['distance_charge'], 'distance charge matches distance times rate');
assertSameValue('30.00', $breakdown['floor_charge'], 'floor charge matches floors times rate');
assertSameValue('20.00', $breakdown['packing_charges'], 'packing is charged only when selected');