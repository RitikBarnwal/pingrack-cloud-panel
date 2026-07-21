<?php
/**
 * providers/virtualizor/fetch-regions.php
 * Virtualizor plan sync — reads from Virtualizor panel plans list.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function virtualizor_sync_single_plan(int $providerId, string $planId, array $locations, float $marginPct, object $cloudCatalog): array
{
    // Fetch plans from Virtualizor panel
    $found = null;
    try {
        $raw   = $cloudCatalog->http_get('listplans');
        $plans = $raw['plans'] ?? $raw['planlist'] ?? [];
        foreach ($plans as $id => $p) {
            $pid = (string)($p['plid'] ?? $p['id'] ?? $id);
            if ($pid === $planId) { $found = $p; break; }
        }
    } catch (Throwable $e) {}

    if (!$found) {
        return ['ok' => false, 'error' => "Plan '{$planId}' not found in Virtualizor. Check plan ID in Admin → Server Plans."];
    }

    $vcpu    = (int)($found['num_cores']  ?? $found['cores'] ?? $found['cpu'] ?? 1);
    $ram_mb  = (int)($found['ram']        ?? 0);
    $disk_mb = (int)($found['disk_space'] ?? $found['disk']  ?? 0);
    $ram_gb  = $ram_mb  ? round($ram_mb  / 1024, 1) : 0;
    $disk_gb = $disk_mb ? (int)round($disk_mb / 1024) : 0;
    $label   = $found['plan_name'] ?? $found['name'] ?? 'Plan ' . $planId;

    // Virtualizor price from panel (INR/month typically)
    $monthly_inr = (float)($found['price'] ?? 0);
    $base_currency = get_setting('virtualizor_currency_' . $providerId, 'INR');

    // If no price set in Virtualizor, use 0 (admin sets margin manually)
    $base_hourly = $monthly_inr > 0 ? round($monthly_inr / 730, 6) : 0;

    $inr_to_usd = 1 / (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
    $multiplier = 1 + ($marginPct / 100);

    // Determine prices based on base currency
    $prov_row = db()->prepare('SELECT currency_base FROM providers WHERE id=? LIMIT 1');
    $prov_row->execute([$providerId]);
    $prov = $prov_row->fetch();
    $curr = strtoupper($prov['currency_base'] ?? 'INR');

    if ($curr === 'INR') {
        $hourly_inr = round($base_hourly * $multiplier, 6);
        $hourly_usd = round($hourly_inr * $inr_to_usd, 8);
        $hourly_eur = round($hourly_usd * $usd_to_eur, 8);
    } elseif ($curr === 'USD') {
        $hourly_usd = round($base_hourly * $multiplier, 8);
        $hourly_inr = round($hourly_usd / $inr_to_usd, 6);
        $hourly_eur = round($hourly_usd * $usd_to_eur, 8);
    } else { // EUR
        $hourly_eur = round($base_hourly * $multiplier, 8);
        $hourly_usd = round($hourly_eur / $usd_to_eur, 8);
        $hourly_inr = round($hourly_usd / $inr_to_usd, 6);
    }

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
        $base_hourly, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, $curr,
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

    return ['ok'=>true,'vcpu'=>$vcpu,'ram_gb'=>$ram_gb,'disk_gb'=>$disk_gb,'cpu_type'=>'shared','loc_results'=>$loc_results];
}
