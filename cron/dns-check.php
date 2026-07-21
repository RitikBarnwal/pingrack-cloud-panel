<?php
/**
 * cron/dns-check.php
 *
 * Does 2 things every 10 minutes:
 *
 * 1. NS Status Check — pending zones ko Cloudflare se check karo, active karo
 * 2. Record Sync    — ALL active zones ke records Cloudflare se fetch karo
 *                     aur DB ke saath sync karo (CF pe add hue records bhi aa jayenge)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/dns.php';

if (!function_exists('cron_log')) {
    function cron_log(string $msg): void {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        flush();
    }
}

if (!dns_is_configured()) {
    cron_log('DNS not configured — skipping');
    exit;
}

// ── Load ALL zones (pending + active) ────────────────────────
try {
    $zones = db()->query(
        "SELECT * FROM dns_zones WHERE deleted_at IS NULL AND cf_zone_id IS NOT NULL"
    )->fetchAll() ?: [];
} catch (Throwable $e) {
    cron_log('DB error: ' . $e->getMessage());
    exit;
}

if (empty($zones)) { cron_log('No zones to process.'); exit; }

cron_log('Processing ' . count($zones) . ' zone(s)...');

$activated = $synced = $added = $skipped = 0;

foreach ($zones as $zone) {
    $zid     = (int)$zone['id'];
    $uid     = (int)$zone['user_id'];
    $domain  = $zone['domain'];
    $cf_id   = $zone['cf_zone_id'];

    // ── PART 1: NS Status Check (only for pending zones) ─────
    if ($zone['status'] === 'pending') {
        try {
            $cf_status  = dns_check_zone($cf_id);
            $new_status = ($cf_status === 'active') ? 'active' : 'pending';

            db()->prepare("UPDATE dns_zones SET status=?, last_checked_at=NOW() WHERE id=?")
               ->execute([$new_status, $zid]);

            if ($new_status === 'active') {
                cron_log("  ✓ ACTIVATED: {$domain}");
                $activated++;
                // Fall through to sync records for newly activated zone
            } else {
                cron_log("  ⏳ Still pending: {$domain}");
                $skipped++;
                continue; // Don't sync records for pending zones
            }
        } catch (Throwable $e) {
            cron_log("  ✗ NS check failed for {$domain}: " . $e->getMessage());
            continue;
        }
    }

    // ── PART 2: Record Sync (for active zones) ────────────────
    try {
        // Fetch all records from Cloudflare
        $cf_records = dns_list_records($cf_id);

        if (empty($cf_records)) {
            db()->prepare("UPDATE dns_zones SET last_checked_at=NOW() WHERE id=?")
               ->execute([$zid]);
            continue;
        }

        // Get existing records from DB (keyed by cf_record_id)
        $db_stmt = db()->prepare('SELECT * FROM dns_records WHERE zone_id=?');
        $db_stmt->execute([$zid]);
        $db_records = $db_stmt->fetchAll();

        $db_by_cf_id = [];
        foreach ($db_records as $r) {
            if ($r['cf_record_id']) $db_by_cf_id[$r['cf_record_id']] = $r;
        }

        $zone_added = $zone_updated = $zone_deleted = 0;

        // CF records → DB (add new, update changed)
        foreach ($cf_records as $cr) {
            $cf_rid  = $cr['id']      ?? '';
            $type    = strtoupper($cr['type']    ?? '');
            $name    = $cr['name']    ?? '';
            $content = $cr['content'] ?? '';
            $ttl     = (int)($cr['ttl']  ?? 1);
            $proxied = (int)($cr['proxied'] ?? 0);
            $prio    = isset($cr['priority']) ? (int)$cr['priority'] : null;

            // Skip NS/SOA records that belong to Cloudflare itself
            if ($type === 'NS' && str_ends_with(strtolower($content), '.ns.cloudflare.com')) continue;
            if ($type === 'SOA') continue;

            // Normalize name: CF returns FQDN with trailing dot sometimes
            $name = rtrim($name, '.');

            if (isset($db_by_cf_id[$cf_rid])) {
                // Record exists in DB — check if changed
                $db_r = $db_by_cf_id[$cf_rid];
                if ($db_r['content'] !== $content || $db_r['ttl'] != $ttl ||
                    $db_r['proxied'] != $proxied || $db_r['priority'] != $prio) {
                    db()->prepare(
                        'UPDATE dns_records SET type=?,name=?,content=?,ttl=?,priority=?,proxied=?,updated_at=NOW()
                         WHERE id=?'
                    )->execute([$type,$name,$content,$ttl,$prio,$proxied,$db_r['id']]);
                    $zone_updated++;
                }
                unset($db_by_cf_id[$cf_rid]); // Mark as seen
            } else {
                // New record in CF — add to DB
                db()->prepare(
                    'INSERT INTO dns_records (zone_id,user_id,cf_record_id,type,name,content,ttl,priority,proxied)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$zid,$uid,$cf_rid,$type,$name,$content,$ttl,$prio,$proxied]);
                $zone_added++;
            }
        }

        // Records in DB but NOT in CF — they were deleted from CF side, remove from DB
        foreach ($db_by_cf_id as $stale_cf_id => $stale_r) {
            db()->prepare('DELETE FROM dns_records WHERE id=?')->execute([$stale_r['id']]);
            $zone_deleted++;
        }

        // Update zone last_checked_at
        db()->prepare("UPDATE dns_zones SET last_checked_at=NOW() WHERE id=?")->execute([$zid]);

        $total_cf = count($cf_records);
        cron_log("  ↻ SYNCED {$domain}: {$total_cf} CF records | +{$zone_added} added | {$zone_updated} updated | {$zone_deleted} removed");

        $synced++;
        $added += $zone_added;

    } catch (Throwable $e) {
        cron_log("  ✗ Sync failed for {$domain}: " . $e->getMessage());
    }
}

cron_log("Done. Activated:{$activated} | Synced:{$synced} | New records:{$added} | Skipped:{$skipped}");
