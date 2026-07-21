<?php
/**
 * providers/linode/fetch-regions.php
 *
 * Syncs Linode regions, images, and plan prices to our DB.
 * Called by admin sync button (same pattern as hetzner/fetch-regions.php).
 *
 * Returns JSON: {ok, message, stats}
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * @param int    $providerId  DB providers.id
 * @param float  $marginPct   Markup percentage (e.g. 30.0)
 * @param string $currency    User-facing currency ('INR' or 'USD')
 */
function linode_sync_provider(int $providerId, float $marginPct, string $currency = 'INR'): array
{
    try {
        $prov = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
        $prov->execute([$providerId]);
        $provider = $prov->fetch();
        if (!$provider || empty($provider['api_key'])) {
            return ['ok' => false, 'error' => 'Provider not found or missing API key.'];
        }

        CloudProvider::reset();
        $cloud = new CloudProvider($provider['api_key']);

        $stats = ['regions' => 0, 'images' => 0, 'plans' => 0];

        // ── 1. Regions ────────────────────────────────────────────
        $regions = $cloud->catalog->regions();
        foreach ($regions as $r) {
            $existing = db()->prepare('SELECT id FROM region_catalog WHERE provider_id=? AND slug=? LIMIT 1');
            $existing->execute([$providerId, $r['slug']]);
            if ($existing->fetch()) {
                db()->prepare('UPDATE region_catalog SET city=?,country=?,country_code=?,is_active=1 WHERE provider_id=? AND slug=?')
                   ->execute([$r['city'], $r['country'], $r['country_code'], $providerId, $r['slug']]);
            } else {
                db()->prepare('INSERT INTO region_catalog (provider_id,slug,city,country,country_code,is_active) VALUES (?,?,?,?,?,1)')
                   ->execute([$providerId, $r['slug'], $r['city'], $r['country'], $r['country_code']]);
            }
            $stats['regions']++;
        }

        // ── 2. Images ─────────────────────────────────────────────
        $images = $cloud->catalog->images();
        foreach ($images as $img) {
            $existing = db()->prepare('SELECT id FROM image_catalog WHERE provider_id=? AND slug=? LIMIT 1');
            $existing->execute([$providerId, $img['slug']]);
            if ($existing->fetch()) {
                db()->prepare('UPDATE image_catalog SET os_name=?,os_version=?,label=?,is_active=1 WHERE provider_id=? AND slug=?')
                   ->execute([$img['os'], $img['version'], $img['label'], $providerId, $img['slug']]);
            } else {
                db()->prepare('INSERT INTO image_catalog (provider_id,slug,os_name,os_version,label,is_active) VALUES (?,?,?,?,?,1)')
                   ->execute([$providerId, $img['slug'], $img['os'], $img['version'], $img['label']]);
            }
            $stats['images']++;
        }

        // ── 3. Plans (Linode Types) ───────────────────────────────
        // Get current FX rates from DB settings
        $usd_to_inr = (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
        $usd_to_eur = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
        $multiplier = 1 + ($marginPct / 100);

        $plans = $cloud->catalog->plans();
        foreach ($plans as $plan) {
            $base_hourly_usd  = $plan['price_hourly_usd'];
            $hourly_usd_after = round($base_hourly_usd * $multiplier, 8);
            $hourly_inr_after = round($hourly_usd_after * $usd_to_inr, 6);
            $hourly_eur_after = round($hourly_usd_after * $usd_to_eur, 6);

            // plan_pricing uses EUR as base currency field — for Linode we store USD as base
            $existing = db()->prepare('SELECT id FROM plan_pricing WHERE provider_id=? AND slug=? LIMIT 1');
            $existing->execute([$providerId, $plan['slug']]);
            if ($existing->fetch()) {
                db()->prepare(
                    'UPDATE plan_pricing SET label=?,vcpu=?,ram_gb=?,disk_gb=?,cpu_type=?,
                     price_hourly_eur=?,price_usd=?,price_inr=?,base_currency=?,
                     price_hourly_base=?,margin_pct=?,is_active=1,updated_at=NOW()
                     WHERE provider_id=? AND slug=?'
                )->execute([
                    $plan['label'], $plan['vcpu'], $plan['ram_gb'], $plan['disk_gb'],
                    str_contains($plan['class'], 'dedicated') ? 'dedicated' : 'shared',
                    $hourly_eur_after, $hourly_usd_after, $hourly_inr_after,
                    'USD', $base_hourly_usd, $marginPct,
                    $providerId, $plan['slug'],
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO plan_pricing
                     (provider_id,slug,label,vcpu,ram_gb,disk_gb,cpu_type,
                      price_hourly_eur,price_usd,price_inr,base_currency,price_hourly_base,margin_pct,is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)'
                )->execute([
                    $providerId, $plan['slug'], $plan['label'],
                    $plan['vcpu'], $plan['ram_gb'], $plan['disk_gb'],
                    str_contains($plan['class'], 'dedicated') ? 'dedicated' : 'shared',
                    $hourly_eur_after, $hourly_usd_after, $hourly_inr_after,
                    'USD', $base_hourly_usd, $marginPct,
                ]);
            }
            $stats['plans']++;
        }

        // ── 4. Update provider sync status ────────────────────────
        $note = "{$stats['plans']} plans, {$stats['regions']} regions, {$stats['images']} images";
        db()->prepare('UPDATE providers SET last_synced=NOW(), sync_status=?, sync_note=? WHERE id=?')
           ->execute(['success', $note, $providerId]);

        return ['ok' => true, 'message' => "Synced: $note", 'stats' => $stats];

    } catch (Throwable $e) {
        db()->prepare('UPDATE providers SET sync_status=?, sync_note=? WHERE id=?')
           ->execute(['error', $e->getMessage(), $providerId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
