<?php
/**
 * providers/contabo/fetch-regions.php
 * Contabo plan sync — EUR native pricing with hardcoded fallback.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function contabo_sync_single_plan(int $providerId, string $planId, array $locations, float $marginPct, object $cloudCatalog): array
{
    // Try to find plan via API first
    $found = null;

    foreach (['/products', '/v1/products', '/product/v1/vps'] as $endpoint) {
        try {
            $raw   = $cloudCatalog->http_get($endpoint, ['page' => 1, 'size' => 200]);
            $plans = $raw['data'] ?? [];
            foreach ($plans as $p) {
                $pid = $p['productId'] ?? $p['id'] ?? $p['slug'] ?? '';
                if ($pid === $planId) { $found = $p; break 2; }
            }
        } catch (Throwable $e) { continue; }
    }

    // Fallback to hardcoded plans if API doesn't return data
    if (!$found) {
        $hardcoded = [
            'V1' => ['vcpu'=>4,  'ram_gb'=>4,   'disk_gb'=>100,  'monthly_eur'=>4.50],
            'V2' => ['vcpu'=>4,  'ram_gb'=>8,   'disk_gb'=>200,  'monthly_eur'=>6.99],
            'V3' => ['vcpu'=>4,  'ram_gb'=>12,  'disk_gb'=>300,  'monthly_eur'=>9.99],
            'V4' => ['vcpu'=>6,  'ram_gb'=>16,  'disk_gb'=>400,  'monthly_eur'=>13.99],
            'V5' => ['vcpu'=>8,  'ram_gb'=>24,  'disk_gb'=>600,  'monthly_eur'=>19.99],
            'V6' => ['vcpu'=>10, 'ram_gb'=>30,  'disk_gb'=>800,  'monthly_eur'=>26.99],
            'V7' => ['vcpu'=>12, 'ram_gb'=>60,  'disk_gb'=>1600, 'monthly_eur'=>52.99],
            'V8' => ['vcpu'=>16, 'ram_gb'=>120, 'disk_gb'=>3200, 'monthly_eur'=>99.99],
        ];

        if (!isset($hardcoded[$planId])) {
            return ['ok' => false, 'error' => "Plan '{$planId}' not found. Valid IDs: V1, V2, V3, V4, V5, V6, V7, V8"];
        }

        $h = $hardcoded[$planId];
        return _contabo_save_plan($providerId, $planId, strtoupper($planId), $h['vcpu'], $h['ram_gb'], $h['disk_gb'], $h['monthly_eur'] / 730, $marginPct, $locations);
    }

    // Parse from API response
    $vcpu = (int)($found['cpuCores'] ?? $found['cpu'] ?? $found['vcpus'] ?? 0);
    $ram_raw = (int)($found['memoryMb'] ?? $found['memory'] ?? $found['ram'] ?? 0);
    $ram_gb  = $ram_raw >= 1024 ? round($ram_raw / 1024, 1) : (float)$ram_raw;
    $disk_raw= (int)($found['diskMb'] ?? ($found['storageGb'] ?? $found['disk'] ?? 0));
    $disk_gb = $disk_raw > 1024 ? (int)round($disk_raw / 1024) : (int)$disk_raw;
    $label   = $found['name'] ?? strtoupper($planId);

    $monthly_eur = 0.0;
    if (!empty($found['price']) && is_array($found['price'])) {
        $monthly_eur = (float)($found['price'][0]['price'] ?? $found['price']['amount'] ?? 0);
    }
    if (!$monthly_eur) {
        $monthly_eur = (float)($found['monthlyPrice'] ?? $found['monthly_price'] ?? 0);
    }

    if ($monthly_eur <= 0) {
        return ['ok' => false, 'error' => "Plan '{$planId}' has no EUR price in API response."];
    }

    return _contabo_save_plan($providerId, $planId, $label, $vcpu, $ram_gb, $disk_gb, $monthly_eur / 730, $marginPct, $locations);
}

function _contabo_save_plan(int $providerId, string $planId, string $label, int $vcpu, float $ram_gb, int $disk_gb, float $base_hourly_eur, float $marginPct, array $locations): array
{
    $eur_to_usd = (float)(get_setting('fx_rate_EUR_USD', '1.09') ?: 1.09);
    $usd_to_inr = (float)(get_setting('fx_rate_USD_INR', '84')   ?: 84);
    $multiplier = 1 + ($marginPct / 100);

    $hourly_eur = round($base_hourly_eur * $multiplier, 8);
    $hourly_usd = round($hourly_eur * $eur_to_usd, 8);
    $hourly_inr = round($hourly_usd * $usd_to_inr, 6);

    db()->prepare(
        'INSERT INTO plan_pricing
         (provider_id,slug,label,vcpu,ram_gb,disk_gb,cpu_type,
          price_hourly_base,margin_pct,price_hourly_eur,price_usd,price_inr,base_currency,is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           label=VALUES(label),vcpu=VALUES(vcpu),ram_gb=VALUES(ram_gb),disk_gb=VALUES(disk_gb),
           cpu_type=VALUES(cpu_type),price_hourly_base=VALUES(price_hourly_base),
           margin_pct=VALUES(margin_pct),price_hourly_eur=VALUES(price_hourly_eur),
           price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),
           base_currency=VALUES(base_currency),is_active=1'
    )->execute([
        $providerId, $planId, $label, $vcpu, $ram_gb, $disk_gb, 'shared',
        $base_hourly_eur, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, 'EUR',
    ]);

    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id,plan_slug,region_slug,price_eur,price_usd,price_inr,margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur),price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),margin_pct=VALUES(margin_pct)'
    );
    foreach ($locations as $loc) {
        $st->execute([$providerId, $planId, $loc, $hourly_eur, $hourly_usd, $hourly_inr, $marginPct]);
        $loc_results[$loc] = ['ok' => true, 'usd' => $hourly_usd, 'inr' => $hourly_inr];
    }

    return ['ok' => true, 'vcpu' => $vcpu, 'ram_gb' => $ram_gb, 'disk_gb' => $disk_gb, 'cpu_type' => 'shared', 'loc_results' => $loc_results];
}

