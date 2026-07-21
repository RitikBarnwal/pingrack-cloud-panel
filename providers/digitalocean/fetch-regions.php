<?php
/**
 * providers/digitalocean/fetch-regions.php
 * DO plan sync — USD native pricing. Size-to-region mapping aware.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function digitalocean_sync_single_plan(int $providerId, string $planSlug, array $locations, float $marginPct, object $cloudCatalog): array
{
    $raw   = $cloudCatalog->http_get('/sizes', ['per_page' => 200]);
    $sizes = $raw['sizes'] ?? [];

    $found = null;
    foreach ($sizes as $s) {
        if ($s['slug'] === $planSlug) { $found = $s; break; }
    }
    if (!$found) return ['ok' => false, 'error' => "Size '{$planSlug}' not found in DigitalOcean API."];

    $vcpu           = (int)$found['vcpus'];
    $ram_gb         = round($found['memory'] / 1024, 1);
    $disk_gb        = (int)$found['disk'];
    $label          = $found['description'] ?? strtoupper($planSlug);
    $base_hourly_usd= (float)$found['price_hourly'];
    $cpu_type       = str_starts_with($planSlug, 's-') ? 'shared' : 'dedicated';

    if ($base_hourly_usd <= 0) return ['ok' => false, 'error' => "Size '{$planSlug}' has no hourly price."];

    $usd_to_inr  = (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur  = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
    $multiplier  = 1 + ($marginPct / 100);

    $hourly_usd = round($base_hourly_usd * $multiplier, 8);
    $hourly_inr = round($hourly_usd * $usd_to_inr, 6);
    $hourly_eur = round($hourly_usd * $usd_to_eur, 8);

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
        $providerId, $planSlug, $label,
        $vcpu, $ram_gb, $disk_gb, $cpu_type,
        $base_hourly_usd, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, 'USD',
    ]);

    // Only add to regions where this size is available
    $available_regions = $found['regions'] ?? [];
    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id,plan_slug,region_slug,price_eur,price_usd,price_inr,margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur),price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),margin_pct=VALUES(margin_pct)'
    );
    foreach ($locations as $loc) {
        if (!empty($available_regions) && !in_array($loc, $available_regions)) {
            $loc_results[$loc] = ['ok' => false, 'error' => "Size not available in region '{$loc}'"];
            continue;
        }
        $st->execute([$providerId, $planSlug, $loc, $hourly_eur, $hourly_usd, $hourly_inr, $marginPct]);
        $loc_results[$loc] = ['ok' => true, 'usd' => $hourly_usd, 'inr' => $hourly_inr];
    }

    return ['ok'=>true,'vcpu'=>$vcpu,'ram_gb'=>$ram_gb,'disk_gb'=>$disk_gb,'cpu_type'=>$cpu_type,'loc_results'=>$loc_results];
}
