<?php
/**
 * providers/utho/fetch-regions.php
 * Utho plan sync — INR native pricing with hardcoded fallback.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function utho_sync_single_plan(int $providerId, string $planId, array $locations, float $marginPct, object $cloudCatalog): array
{
    // Hardcoded fallback plans (Utho 2025 pricing in INR/month)
    $hardcoded = [
        '10030' => ['label'=>'Basic 1GB',  'vcpu'=>1, 'ram_gb'=>1,  'disk_gb'=>25,  'monthly_inr'=>225],
        '10060' => ['label'=>'Basic 2GB',  'vcpu'=>1, 'ram_gb'=>2,  'disk_gb'=>50,  'monthly_inr'=>450],
        '10090' => ['label'=>'Basic 4GB',  'vcpu'=>2, 'ram_gb'=>4,  'disk_gb'=>80,  'monthly_inr'=>900],
        '10120' => ['label'=>'Basic 8GB',  'vcpu'=>4, 'ram_gb'=>8,  'disk_gb'=>160, 'monthly_inr'=>1800],
        '10150' => ['label'=>'Basic 16GB', 'vcpu'=>6, 'ram_gb'=>16, 'disk_gb'=>320, 'monthly_inr'=>3600],
        '10180' => ['label'=>'Basic 32GB', 'vcpu'=>8, 'ram_gb'=>32, 'disk_gb'=>640, 'monthly_inr'=>7200],
    ];

    // Try to find from API first
    $found = null;
    foreach (['/plans', '/cloudplans', '/pricing', '/cloud/plans'] as $ep) {
        try {
            $raw   = $cloudCatalog->http_get($ep);
            $plans = $raw['plans'] ?? $raw['cloudplans'] ?? $raw['data'] ?? [];
            foreach ($plans as $p) {
                if ((string)($p['id'] ?? $p['planid'] ?? '') === $planId) {
                    $found = $p; break 2;
                }
            }
        } catch (Throwable $e) { continue; }
    }

    // Use API data if found, else hardcoded
    if ($found) {
        $vcpu    = (int)($found['cpu']  ?? $found['vcpu'] ?? 0);
        $ram_mb  = (int)($found['ram']  ?? 0);
        $disk_gb = (int)($found['disk'] ?? $found['storage'] ?? 0);
        $ram_gb  = $ram_mb >= 1024 ? round($ram_mb / 1024, 1) : (float)$ram_mb;
        $label   = $found['name'] ?? $found['planname'] ?? ($hardcoded[$planId]['label'] ?? strtoupper($planId));
        $monthly = (float)($found['price'] ?? $found['monthly_price'] ?? $found['cost'] ?? $hardcoded[$planId]['monthly_inr'] ?? 0);
    } elseif (isset($hardcoded[$planId])) {
        $h       = $hardcoded[$planId];
        $vcpu    = $h['vcpu'];
        $ram_gb  = $h['ram_gb'];
        $disk_gb = $h['disk_gb'];
        $label   = $h['label'];
        $monthly = $h['monthly_inr'];
    } else {
        return ['ok' => false, 'error' => "Plan '{$planId}' not found. Valid IDs: " . implode(', ', array_keys($hardcoded))];
    }

    if ($monthly <= 0) return ['ok' => false, 'error' => "Plan '{$planId}' has no price."];

    $base_hourly_inr = round($monthly / 730, 6);
    $inr_to_usd  = 1 / (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur  = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
    $multiplier  = 1 + ($marginPct / 100);

    $hourly_inr = round($base_hourly_inr * $multiplier, 6);
    $hourly_usd = round($hourly_inr * $inr_to_usd, 8);
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
        $providerId, $planId, $label,
        $vcpu, $ram_gb, $disk_gb, 'shared',
        $base_hourly_inr, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, 'INR',
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
