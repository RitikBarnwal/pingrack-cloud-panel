<?php
/**
 * includes/admin.php  (v4)
 * Admin-only helpers.
 * NOTE: get_setting() / set_setting() are in bootstrap.php — NOT redefined here.
 */
declare(strict_types=1);

require_once __DIR__ . '/currency.php';

/* ── Provider CRUD ─────────────────────────────────────────── */

function get_all_providers(): array {
    return db()->query('SELECT * FROM providers ORDER BY id')->fetchAll() ?: [];
}
function get_provider(int $id): ?array {
    $s = db()->prepare('SELECT * FROM providers WHERE id=? LIMIT 1');
    $s->execute([$id]); return $s->fetch() ?: null;
}
function save_provider(array $d, ?int $id=null): int {
    if ($id) {
        db()->prepare('UPDATE providers SET display_name=?,api_key=?,margin_pct=?,is_active=?,currency_base=?,provider_type=? WHERE id=?')
           ->execute([$d['display_name'],$d['api_key'],$d['margin_pct'],$d['is_active']??1,strtoupper($d['currency_base']??'EUR'),$d['provider_type']??'hetzner',$id]);
        return $id;
    }
    db()->prepare('INSERT INTO providers (slug,display_name,api_key,margin_pct,currency_base,is_active,provider_type) VALUES(?,?,?,?,?,?,?)')
       ->execute([$d['slug'],$d['display_name'],$d['api_key'],$d['margin_pct']??0,strtoupper($d['currency_base']??'EUR'),$d['is_active']??1,$d['provider_type']??'hetzner']);
    return (int)db()->lastInsertId();
}
function mark_provider_synced(int $id, bool $ok, string $note=''): void {
    db()->prepare('UPDATE providers SET last_synced=NOW(),sync_status=?,sync_note=? WHERE id=?')->execute([$ok?'success':'error',$note,$id]);
}

/* ── provider_plans CRUD ───────────────────────────────────── */

function get_provider_plans(int $providerId): array {
    $s = db()->prepare('SELECT * FROM provider_plans WHERE provider_id=? ORDER BY id');
    $s->execute([$providerId]);
    $rows = $s->fetchAll() ?: [];
    foreach ($rows as &$r) {
        $r['locations'] = json_decode($r['locations'] ?? '[]', true) ?: [];
    }
    return $rows;
}

function save_provider_plan(int $providerId, string $planApiId, string $displayName, array $locations, bool $active = true, ?int $id = null): int {
    $planApiId = strtolower(trim($planApiId));
    $locJson   = json_encode(array_values(array_filter($locations)));
    if ($id) {
        db()->prepare('UPDATE provider_plans SET plan_api_id=?,display_name=?,locations=?,is_active=? WHERE id=? AND provider_id=?')
           ->execute([$planApiId,$displayName,$locJson,$active?1:0,$id,$providerId]);
        return $id;
    }
    db()->prepare('INSERT INTO provider_plans (provider_id,plan_api_id,display_name,locations,is_active) VALUES (?,?,?,?,?)')
       ->execute([$providerId,$planApiId,$displayName,$locJson,$active?1:0]);
    return (int)db()->lastInsertId();
}

function delete_provider_plan(int $id): void {
    $s = db()->prepare('SELECT * FROM provider_plans WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $plan = $s->fetch();
    if (!$plan) return;
    // Remove pricing rows
    db()->prepare('DELETE FROM plan_region_prices WHERE provider_id=? AND plan_slug=?')->execute([$plan['provider_id'],$plan['plan_api_id']]);
    db()->prepare('DELETE FROM plan_pricing WHERE provider_id=? AND slug=?')->execute([$plan['provider_id'],$plan['plan_api_id']]);
    db()->prepare('DELETE FROM provider_plans WHERE id=?')->execute([$id]);
}

/* ── Sync one plan from API ────────────────────────────────── */

/**
 * Fetches specs + prices for $planApiId from provider API.
 * Saves to plan_pricing (summary) + plan_region_prices (per location).
 * Only uses the locations admin specified — no extras.
 */
function sync_single_plan(int $providerId, string $planApiId, array $locations, float $marginPct, string $baseCurrency, object $cloudCatalog): array
{
    // Fetch server type list filtered by name
    $raw_list = $cloudCatalog->http_get('/server_types', ['name' => $planApiId]);
    $types    = $raw_list['server_types'] ?? [];

    if (empty($types)) {
        return ['ok'=>false,'error'=>"Plan '{$planApiId}' not found in Hetzner API."];
    }

    $t = $types[0];

    // Build API price map: location_slug => EUR hourly gross
    $api_prices = [];
    foreach ($t['prices'] ?? [] as $p) {
        $loc   = $p['location']  ?? null;
        $price = (float)($p['price_hourly']['gross'] ?? 0);
        if ($loc && $price > 0) $api_prices[$loc] = $price;
    }

    $vcpu     = (int)($t['cores']   ?? 0);
    $ram_gb   = (float)($t['memory']?? 0);
    $disk_gb  = (int)($t['disk']    ?? 0);
    $cpu_type = $t['cpu_type']       ?? 'shared';

    // Cheapest price across all API locations (for summary row)
    $min_eur = !empty($api_prices) ? min($api_prices) : 0.0;
    $summary = compute_price($min_eur, $baseCurrency, $marginPct);

    db()->prepare(
        'INSERT INTO plan_pricing
         (provider_id,slug,label,vcpu,ram_gb,disk_gb,cpu_type,
          price_hourly_base,margin_pct,price_hourly_eur,price_usd,price_inr,base_currency,is_active)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
         ON DUPLICATE KEY UPDATE
           vcpu=VALUES(vcpu),ram_gb=VALUES(ram_gb),disk_gb=VALUES(disk_gb),
           cpu_type=VALUES(cpu_type),price_hourly_base=VALUES(price_hourly_base),
           margin_pct=VALUES(margin_pct),price_hourly_eur=VALUES(price_hourly_eur),
           price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),
           base_currency=VALUES(base_currency),is_active=1'
    )->execute([
        $providerId,$planApiId,strtoupper($planApiId),
        $vcpu,$ram_gb,$disk_gb,$cpu_type,
        $summary['base_original'],$marginPct,$summary['with_margin'],
        $summary['price_usd'],$summary['price_inr'],$baseCurrency,
    ]);

    // Per-location rows — ONLY admin-chosen locations
    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id,plan_slug,region_slug,price_eur,price_usd,price_inr,margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur),price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),margin_pct=VALUES(margin_pct)'
    );

    foreach ($locations as $loc) {
        if (!isset($api_prices[$loc])) {
            $loc_results[$loc] = ['ok'=>false,'error'=>"API has no price for location '$loc'"];
            continue;
        }
        $lp = compute_price($api_prices[$loc], $baseCurrency, $marginPct);
        $st->execute([$providerId,$planApiId,$loc,$lp['base_original'],$lp['price_usd'],$lp['price_inr'],$marginPct]);
        $loc_results[$loc] = ['ok'=>true,'usd'=>$lp['price_usd'],'inr'=>$lp['price_inr']];
    }

    return [
        'ok'          => true,
        'vcpu'        => $vcpu,
        'ram_gb'      => $ram_gb,
        'disk_gb'     => $disk_gb,
        'cpu_type'    => $cpu_type,
        'loc_results' => $loc_results,
    ];
}



/**
 * Sync a single Vultr plan to plan_pricing + plan_region_prices.
 * Vultr prices are in USD (monthly → hourly = monthly/730).
 */
function vultr_sync_single_plan(int $providerId, string $planApiId, array $locations, float $marginPct, object $cloudCatalog, array $planData = []): array
{
    // Use pre-fetched plan data if available, otherwise fetch
    if (!empty($planData)) {
        $plan = $planData;
    } else {
        $all_plans = $cloudCatalog->plans();
        $plan = null;
        foreach ($all_plans as $p) {
            if (($p['slug'] ?? $p['id'] ?? '') === $planApiId) { $plan = $p; break; }
        }
        if (!$plan) {
            return ['ok'=>false,'error'=>"Plan '{$planApiId}' not found in Vultr API."];
        }
    }

    $vcpu         = (int)($plan['vcpu']         ?? $plan['vcpu_count']    ?? 0);
    $ram_mb       = (int)($plan['ram_mb']       ?? $plan['ram']           ?? 0);
    $ram_gb       = $ram_mb ? round($ram_mb / 1024, 1) : (float)($plan['ram_gb'] ?? 0);
    $disk_gb      = (int)($plan['disk_gb']      ?? $plan['disk']          ?? 0);
    $bw_gb        = (int)($plan['bandwidth_gb'] ?? $plan['bandwidth']     ?? 0);
    $price_mo_usd = (float)($plan['price_monthly'] ?? $plan['monthly_cost'] ?? 0);
    $cpu_type     = $plan['cpu_type'] ?? (str_contains($planApiId, 'vhf') ? 'dedicated' : 'shared');
    $label        = $plan['label'] ?? "{$vcpu}vCPU / {$ram_gb}GB RAM / {$disk_gb}GB SSD";

    if ($price_mo_usd <= 0) {
        return ['ok'=>false,'error'=>"Plan '{$planApiId}' has no price."];
    }

    // Hourly = monthly / 730
    $base_hourly_usd = round($price_mo_usd / 730, 8);

    // Apply margin + convert
    $usd_to_inr  = (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur  = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
    $multiplier  = 1 + ($marginPct / 100);

    $hourly_usd  = round($base_hourly_usd * $multiplier, 8);
    $hourly_inr  = round($hourly_usd * $usd_to_inr, 6);
    $hourly_eur  = round($hourly_usd * $usd_to_eur, 6);

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
        $providerId, $planApiId, $label,
        $vcpu, $ram_gb, $disk_gb, $cpu_type,
        $base_hourly_usd, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, 'USD',
    ]);

    // Per-location pricing — Vultr same price for all regions
    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id,plan_slug,region_slug,price_eur,price_usd,price_inr,margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur),price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),margin_pct=VALUES(margin_pct)'
    );
    foreach ($locations as $loc) {
        $st->execute([$providerId, $planApiId, $loc, $hourly_eur, $hourly_usd, $hourly_inr, $marginPct]);
        $loc_results[$loc] = ['ok'=>true,'usd'=>$hourly_usd,'inr'=>$hourly_inr];
    }

    return [
        'ok'          => true,
        'vcpu'        => $vcpu,
        'ram_gb'      => $ram_gb,
        'disk_gb'     => $disk_gb,
        'cpu_type'    => $cpu_type,
        'loc_results' => $loc_results,
    ];
}

/* ── Region / Image catalog ────────────────────────────────── */

/* ── Linode-specific plan sync ─────────────────────────────── */

/**
 * Sync a single Linode plan (type) to plan_pricing + plan_region_prices.
 * Linode prices are in USD (not EUR), so base_currency = USD.
 */
function linode_sync_single_plan(int $providerId, string $planApiId, array $locations, float $marginPct, object $cloudCatalog): array
{
    // Linode types endpoint
    $raw = $cloudCatalog->http_get('/linode/types/' . $planApiId);

    if (empty($raw['id'])) {
        // Try fetching from list
        $list = $cloudCatalog->http_get('/linode/types', ['page_size' => 100]);
        $found = null;
        foreach ($list['data'] ?? [] as $t) {
            if ($t['id'] === $planApiId) { $found = $t; break; }
        }
        if (!$found) return ['ok'=>false,'error'=>"Plan '{$planApiId}' not found in Linode API."];
        $raw = $found;
    }

    $vcpu     = (int)($raw['vcpus']  ?? 0);
    $ram_mb   = (int)($raw['memory'] ?? 0);
    $disk_mb  = (int)($raw['disk']   ?? 0);
    $ram_gb   = round($ram_mb / 1024, 1);
    $disk_gb  = (int)round($disk_mb / 1024);
    $cpu_type = str_contains($raw['class'] ?? '', 'dedicated') ? 'dedicated' : 'shared';
    $label    = $raw['label'] ?? strtoupper($planApiId);

    $base_hourly_usd = (float)($raw['price']['hourly'] ?? 0);
    if ($base_hourly_usd <= 0) return ['ok'=>false,'error'=>"Plan '{$planApiId}' has no hourly price."];

    // Convert USD → INR and EUR with margin
    $usd_to_inr  = (float)(get_setting('fx_rate_USD_INR', '84') ?: 84);
    $usd_to_eur  = (float)(get_setting('fx_rate_USD_EUR', '0.92') ?: 0.92);
    $multiplier  = 1 + ($marginPct / 100);

    $hourly_usd  = round($base_hourly_usd * $multiplier, 8);
    $hourly_inr  = round($hourly_usd * $usd_to_inr, 6);
    $hourly_eur  = round($hourly_usd * $usd_to_eur, 6);

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
        $providerId, $planApiId, $label,
        $vcpu, $ram_gb, $disk_gb, $cpu_type,
        $base_hourly_usd, $marginPct, $hourly_eur, $hourly_usd, $hourly_inr, 'USD',
    ]);

    // Per-location pricing — Linode has same price for all regions
    $loc_results = [];
    $st = db()->prepare(
        'INSERT INTO plan_region_prices (provider_id,plan_slug,region_slug,price_eur,price_usd,price_inr,margin_pct)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE price_eur=VALUES(price_eur),price_usd=VALUES(price_usd),price_inr=VALUES(price_inr),margin_pct=VALUES(margin_pct)'
    );
    foreach ($locations as $loc) {
        $st->execute([$providerId, $planApiId, $loc, $hourly_eur, $hourly_usd, $hourly_inr, $marginPct]);
        $loc_results[$loc] = ['ok'=>true,'usd'=>$hourly_usd,'inr'=>$hourly_inr];
    }

    return [
        'ok'          => true,
        'vcpu'        => $vcpu,
        'ram_gb'      => $ram_gb,
        'disk_gb'     => $disk_gb,
        'cpu_type'    => $cpu_type,
        'loc_results' => $loc_results,
    ];
}

function upsert_region_catalog(int $providerId, array $regions): void {
    $st = db()->prepare(
        'INSERT INTO region_catalog (provider_id,slug,city,country,country_code)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE city=VALUES(city),country=VALUES(country),country_code=VALUES(country_code),is_active=1'
    );
    foreach ($regions as $r) {
        $st->execute([$providerId,$r['slug'],$r['city'],$r['country']??'',strtolower($r['country_flag']??$r['country_code']??'de')]);
    }
}

function upsert_image_catalog(int $providerId, array $images): void {
    $st = db()->prepare(
        'INSERT INTO image_catalog (provider_id,slug,os_name,os_version,label,image_type,app_description)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           os_name=VALUES(os_name),os_version=VALUES(os_version),
           label=VALUES(label),image_type=VALUES(image_type),
           app_description=VALUES(app_description),is_active=1'
    );
    foreach ($images as $img) {
        $st->execute([
            $providerId,
            $img['slug'],
            $img['os']             ?? '',
            $img['version']        ?? '',
            $img['label']          ?? $img['slug'],
            $img['image_type']     ?? 'system',
            $img['app_description']?? null,
        ]);
    }
}

function get_plans_for_provider(int $providerId): array {
    $s = db()->prepare('SELECT * FROM plan_pricing WHERE provider_id=? AND is_active=1 ORDER BY vcpu,ram_gb');
    $s->execute([$providerId]); return $s->fetchAll() ?: [];
}

function recalculate_provider_prices(int $providerId, float $newMarginPct): int {
    $prov    = get_provider($providerId);
    $baseCur = strtoupper($prov['currency_base']??'EUR');
    $plans   = db()->prepare('SELECT * FROM plan_pricing WHERE provider_id=?');
    $plans->execute([$providerId]);
    foreach ($plans->fetchAll() ?: [] as $p) {
        $pr = compute_price((float)$p['price_hourly_base'],$baseCur,$newMarginPct);
        db()->prepare('UPDATE plan_pricing SET margin_pct=?,price_hourly_eur=?,price_usd=?,price_inr=? WHERE id=?')
           ->execute([$newMarginPct,$pr['with_margin'],$pr['price_usd'],$pr['price_inr'],$p['id']]);
    }
    $rows = db()->prepare('SELECT * FROM plan_region_prices WHERE provider_id=?');
    $rows->execute([$providerId]);
    foreach ($rows->fetchAll() ?: [] as $r) {
        $pr = compute_price((float)$r['price_eur'],$baseCur,$newMarginPct);
        db()->prepare('UPDATE plan_region_prices SET margin_pct=?,price_usd=?,price_inr=? WHERE id=?')
           ->execute([$newMarginPct,$pr['price_usd'],$pr['price_inr'],$r['id']]);
    }
    return (int)db()->query("SELECT COUNT(*) FROM plan_pricing WHERE provider_id=$providerId")->fetchColumn();
}

/* ── Admin stats ───────────────────────────────────────────── */

function admin_stats(): array {
    $db = db();
    return [
        'total_users'    => (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'total_servers'  => (int)$db->query("SELECT COUNT(*) FROM servers WHERE deleted_at IS NULL")->fetchColumn(),
        'running_servers'=> (int)$db->query("SELECT COUNT(*) FROM servers WHERE status='running'")->fetchColumn(),
        'suspended'      => (int)$db->query("SELECT COUNT(*) FROM servers WHERE status='suspended'")->fetchColumn(),
        'total_revenue'  => (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='credit' AND ref_type='topup'")->fetchColumn(),
        'wallet_total'   => (float)$db->query("SELECT COALESCE(SUM(wallet_balance),0) FROM users")->fetchColumn(),
        'invoices_count' => (int)$db->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
    ];
}
function admin_recent_users(int $limit=10): array {
    return db()->query("SELECT * FROM users ORDER BY created_at DESC LIMIT $limit")->fetchAll() ?: [];
}
function admin_recent_transactions(int $limit=20): array {
    return db()->query("SELECT t.*,u.username,u.email FROM transactions t JOIN users u ON u.id=t.user_id ORDER BY t.created_at DESC LIMIT $limit")->fetchAll() ?: [];
}
