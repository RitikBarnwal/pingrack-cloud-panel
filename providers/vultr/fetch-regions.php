<?php
/**
 * providers/vultr/fetch-regions.php
 *
 * Called by api/sync-provider.php to populate region_catalog.
 * Fetches all Vultr regions and upserts into region_catalog table.
 */
declare(strict_types=1);

if (!isset($cloud) || !isset($pid)) {
    die('fetch-regions.php must be called from sync-provider.php');
}

$regions = $cloud->catalog->regions();
$upserted = 0;

foreach ($regions as $r) {
    $slug  = $r['slug'] ?? '';
    $label = $r['label'] ?? $r['city'] ?? $slug;
    if (!$slug) continue;

    db()->prepare(
        'INSERT INTO region_catalog (provider_id, slug, label, country, country_code, country_flag, is_active)
         VALUES (?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE label=VALUES(label), country=VALUES(country),
           country_code=VALUES(country_code), country_flag=VALUES(country_flag), is_active=1'
    )->execute([
        $pid,
        $slug,
        $label,
        $r['country']      ?? '',
        $r['country_code'] ?? '',
        $r['country_flag'] ?? strtolower($r['country'] ?? ''),
    ]);
    $upserted++;
}

if (!isset($log)) $log = [];
$log[] = "Vultr: Synced {$upserted} region(s) into region_catalog.";
