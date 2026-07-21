<?php
/**
 * providers/proxmox/fetch-regions.php
 *
 * Proxmox plan sync — Proxmox has no native plans API.
 * Plans are manually defined by admin in provider_plans table (like Hetzner).
 * This file handles syncing a single plan entry.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function proxmox_sync_single_plan(int $providerId, string $planId, array $locations, float $marginPct, object $cloudCatalog): array
{
    // Proxmox plan format: "vmid:vcpu:ram_gb:disk_gb" e.g. "0:2:4:50"
    // vmid=0 means "any new VM", or specific VMID
    // OR simply admin defines: "2:4:50" = 2vCPU, 4GB RAM, 50GB disk
    $parts = explode(':', $planId);

    $vcpu    = (int)($parts[0] ?? 1);
    $ram_gb  = (float)($parts[1] ?? 1);
    $disk_gb = (int)($parts[2] ?? 25);
    $label   = "{$vcpu}vCPU / {$ram_gb}GB RAM / {$disk_gb}GB";

    // Pricing from plan_pricing if already set, else 0
    $prov_row = db()->prepare('SELECT currency_base FROM providers WHERE id=? LIMIT 1');
    $prov_row->execute([$providerId]);
    $prov = $prov_row->fetch();
    $curr = strtoupper($prov['currency_base'] ?? 'INR');

    // Price from any existing record or 0
    $existing = db()->prepare('SELECT price_inr, price_usd, price_hourly_base FROM plan_pricing WHERE provider_id=? AND slug=? LIMIT 1');
    $existing->execute([$providerId, $planId]);
    $ex = $existing->fetch() ?: [];

    $base_hourly = (float)($ex['price_hourly_base'] ?? 0);
    $multiplier  = 1 + ($marginPct / 100);

    $inr_to_usd  = 1 / (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur  = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);

    if ($curr === 'INR') {
        $hourly_inr = round($base_hourly * $multiplier, 6);
        $hourly_usd = round($hourly_inr * $inr_to_usd, 8);
        $hourly_eur = round($hourly_usd * $usd_to_eur, 8);
    } elseif ($curr === 'USD') {
        $hourly_usd = round($base_hourly * $multiplier, 8);
        $hourly_inr = round($hourly_usd / $inr_to_usd, 6);
        $hourly_eur = round($hourly_usd * $usd_to_eur, 8);
    } else {
        $hourly_eur = round($base_hourly * $multiplier, 8);
        $hourly_usd = round($hourly_eur / $usd_to_eur, 8);
        $hourly_inr = round($hourly_usd / $inr_to_usd, 6);
    }

    db()->prepare(
        'INSERT INTO plan_pricing
         (provider_id, slug, label, vcpu, ram_gb, disk_gb, cpu_type,
          price_hourly_base, margin_pct, price_hourly_eur, price_usd, price_inr, base_currency, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           label=VALUES(label), vcpu=VALUES(vcpu), ram_gb=VALUES(ram_gb), disk_gb=VALUES(disk_gb),
           margin_pct=VALUES(margin_pct), price_hourly_eur=VALUES(price_hourly_eur),
           price_usd=VALUES(price_usd), price_inr=VALUES(price_inr), is_active=1'
    )->execute([
        $providerId, $planId, $label, $vcpu, $ram_gb, $disk_gb, 'shared',
        $base_hourly, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, $curr,
    ]);

    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id, plan_slug, region_slug, price_eur, price_usd, price_inr, margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur), price_usd=VALUES(price_usd),
           price_inr=VALUES(price_inr), margin_pct=VALUES(margin_pct)'
    );
    foreach ($locations as $loc) {
        $st->execute([$providerId, $planId, $loc, $hourly_eur, $hourly_usd, $hourly_inr, $marginPct]);
        $loc_results[$loc] = ['ok' => true, 'usd' => $hourly_usd, 'inr' => $hourly_inr];
    }

    return [
        'ok'          => true,
        'vcpu'        => $vcpu,
        'ram_gb'      => $ram_gb,
        'disk_gb'     => $disk_gb,
        'cpu_type'    => 'shared',
        'loc_results' => $loc_results,
    ];
}
