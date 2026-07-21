<?php
/**
 * api/sync-provider.php  (v3)
 *
 * FLOW:
 * 1. Refresh exchange rates
 * 2. Load admin's manually-added plans from provider_plans table
 * 3. For each plan → fetch specs+price from API for its specified locations
 * 4. Sync regions + OS images
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'error'=>'POST required.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$pid  = (int)($body['provider_id'] ?? 0);
$csrf = $body['csrf_token'] ?? $body['csrf'] ?? '';

if (!verify_csrf($csrf)) { echo json_encode(['ok'=>false,'error'=>'Invalid token.']); exit; }

$prov = get_provider($pid);
if (!$prov) { echo json_encode(['ok'=>false,'error'=>'Provider not found.']); exit; }

$api_key = trim($prov['api_key'] ?? '');
if (!$api_key) { echo json_encode(['ok'=>false,'error'=>'No API key set. Edit provider first.']); exit; }

$log     = [];
$errors  = [];
$base_cur = strtoupper($prov['currency_base'] ?? 'EUR');
$margin   = (float)$prov['margin_pct'];

try {
    // Step 1: Exchange rates
    $log[] = 'Refreshing exchange rates...';
    $rate_results = refresh_all_rates();
    foreach ($rate_results as $r) {
        $log[] = ($r['ok']?'✓':'⚠') . " Rate {$r['pair']}" . ($r['rate'] ? ' = '.number_format($r['rate'],6) : ' (cached)');
    }

    // Step 2: Connect to correct provider
    $prov_type = strtolower($prov['provider_type'] ?? 'virtualizor');
    $bootstrap = __DIR__ . '/../providers/' . $prov_type . '/bootstrap.php';
    if (!file_exists($bootstrap)) {
        echo json_encode(['ok'=>false,'error'=>"No bootstrap for provider type '{$prov_type}'"]); exit;
    }
    require_once $bootstrap;
    CloudProvider::reset();
    $cloud = new CloudProvider($api_key);

    // ── Step 3: Plans ─────────────────────────────────────────
    // Hetzner → admin manually adds plan IDs → sync each one
    // All other providers → auto-fetch ALL plans from API

    $ok_count  = 0;
    $err_count = 0;
    $samples   = [];

    if ($prov_type === 'hetzner') {
        // ── HETZNER: Manual plans ─────────────────────────────
        $admin_plans = get_provider_plans($pid);

        if (empty($admin_plans)) {
            $log[] = '⚠ No plans configured. Go to Plans tab and add Hetzner plan IDs (e.g. cpx11) first.';
            mark_provider_synced($pid, true, '0 plans (none added yet)');
            echo json_encode(['ok'=>true,'log'=>$log,'summary'=>'No plans added yet.']);
            exit;
        }

        $log[] = 'Hetzner: Found '.count($admin_plans).' manually-configured plan(s). Fetching specs...';

        foreach ($admin_plans as $ap) {
            if (!$ap['is_active']) { $log[]='  SKIP '.$ap['plan_api_id'].' (inactive)'; continue; }
            $locations = $ap['locations'];
            $result = sync_single_plan($pid, $ap['plan_api_id'], $locations, $margin, $base_cur, $cloud->catalog);

        if (!$result['ok']) {
            $log[]   = "  ✗ {$ap['plan_api_id']}: {$result['error']}";
            $errors[]= $result['error'];
            $err_count++;
            continue;
        }

        $loc_ok   = array_filter($result['loc_results'], fn($r)=>$r['ok']);
        $loc_fail = array_filter($result['loc_results'], fn($r)=>!$r['ok']);

        $log[] = "  ✓ {$ap['plan_api_id']} ({$ap['display_name']}) — "
               . "{$result['vcpu']}vCPU/{$result['ram_gb']}GB/{$result['disk_gb']}GB — "
               . count($loc_ok).'/'.count($locations).' locations OK';

        foreach ($loc_fail as $loc => $lf) {
            $log[]   = "    ⚠ {$loc}: {$lf['error']}";
            $errors[]= $lf['error'];
        }

        $first_ok = current($loc_ok);
        if ($first_ok) {
            $samples[] = strtoupper($ap['plan_api_id']).' "'.$ap['display_name'].'" → $'.number_format($first_ok['usd'],4).'/hr · ₹'.number_format($first_ok['inr'],4).'/hr';
        }
        $ok_count++;
        } // end foreach hetzner plans

    } else {
        // ── NON-HETZNER: Auto-fetch ALL plans from provider API ─
        $log[] = $prov['display_name'].': Auto-fetching all plans from API...';

        $fetch_file = __DIR__ . '/../providers/' . $prov_type . '/fetch-regions.php';
        if (file_exists($fetch_file)) require_once $fetch_file;

        $all_plans = $cloud->catalog->plans();
        $log[] = '  Found '.count($all_plans).' plans from API.';

        // Get all synced regions for location assignment
        $region_rows = db()->prepare('SELECT slug FROM region_catalog WHERE provider_id=? AND is_active=1');
        $region_rows->execute([$pid]);
        $all_region_slugs = array_column($region_rows->fetchAll() ?: [], 'slug');

        foreach ($all_plans as $plan) {
            $plan_slug = $plan['slug'] ?? '';
            if (!$plan_slug) continue;

            // DO has per-size region availability; others use all regions
            $plan_regions = $plan['regions'] ?? $all_region_slugs;
            $locations = !empty($all_region_slugs)
                ? array_values(array_intersect($plan_regions, $all_region_slugs))
                : $plan_regions;
            if (empty($locations)) $locations = $all_region_slugs;

            try {
                $fn = $prov_type . '_sync_single_plan';
                if (function_exists($fn)) {
                    // Pass the already-fetched $plan data as 6th arg (optional)
                    $result = $fn($pid, $plan_slug, $locations, $margin, $cloud->catalog, $plan);
                } else {
                    $result = sync_single_plan($pid, $plan_slug, $locations, $margin, $base_cur, $cloud->catalog);
                }

                if ($result['ok'] ?? false) {
                    $ok_count++;
                    $vcpu = $result['vcpu'] ?? '?'; $ram = $result['ram_gb'] ?? '?'; $disk = $result['disk_gb'] ?? '?';
                    if ($ok_count <= 5) {
                        $log[] = '  ✓ '.$plan_slug.' → '.$vcpu.'vCPU / '.$ram.'GB / '.$disk.'GB';
                    } elseif ($ok_count === 6) {
                        $log[] = '  ... (remaining plans synced silently)';
                    }
                    if (count($samples) < 3) {
                        $loc_ok = array_filter($result['loc_results'] ?? [], fn($r) => $r['ok'] ?? false);
                        $fo = current($loc_ok);
                        if ($fo) $samples[] = $plan_slug.' ('.$vcpu.'v/'.$ram.'G) → $'.number_format($fo['usd']??0,4).'/hr · ₹'.number_format($fo['inr']??0,4).'/hr';
                    }
                } else {
                    $err_count++;
                    $log[] = '  ✗ '.$plan_slug.': '.($result['error'] ?? '?');
                }
            } catch (Throwable $pe) {
                $err_count++;
                $log[] = '  ✗ '.$plan_slug.': '.$pe->getMessage();
            }
        }
        $log[] = '  Plans synced: '.$ok_count.' OK, '.$err_count.' errors.';

    } // end provider type branch

    // Step 4: Regions + Images
    $log[] = 'Syncing regions...';
    $regions = $cloud->catalog->regions();
    upsert_region_catalog($pid, $regions);
    $log[] = '✓ '.count($regions).' regions';

    $log[] = 'Syncing OS images...';
    // Virtualizor ostemplate API needs a VPS ID — fetch any active VPS from this provider
    if ($prov_type === 'proxmox') {
        // Proxmox: images() scans storage for ISOs — no VPS ID needed
        $images = $cloud->catalog->images();
    } elseif ($prov_type === 'virtualizor') {
        $vpsIdForImages = 0;
        try {
            $sv = $cloud->catalog->http_get('listvs');
            $vsList = $sv['vs'] ?? $sv['vpslist'] ?? [];
            if (!empty($vsList)) {
                $first = reset($vsList);
                $vpsIdForImages = (int)($first['vpsid'] ?? $first['id'] ?? 0);
            }
        } catch (Throwable $e) {}
        $images = $cloud->catalog->images($vpsIdForImages);
    } else {
        $images = $cloud->catalog->images();
    }
    upsert_image_catalog($pid, $images);
    $log[] = '✓ ' . count($images) . ' OS images';

    // Sync apps (if provider supports it)
    if (method_exists($cloud->catalog, 'apps')) {
        $log[] = 'Syncing marketplace apps...';
        try {
            $apps = $cloud->catalog->apps();
            upsert_image_catalog($pid, $apps);
            $log[] = '✓ ' . count($apps) . ' apps';
        } catch (Throwable $ae) {
            $log[] = '⚠ Apps sync skipped: ' . $ae->getMessage();
        }
    }

    $total_plans = $prov_type === 'hetzner' ? count(get_provider_plans($pid)) : $ok_count;
    $note = "{$ok_count}/{$total_plans} plans, ".count($regions)." regions, ".count($images)." images";
    mark_provider_synced($pid, $err_count===0, $note.(!empty($errors)?' (some errors)':''));

    echo json_encode([
        'ok'      => true,
        'log'     => $log,
        'errors'  => $errors,
        'summary' => $note,
        'samples' => $samples,
    ]);

} catch (Throwable $e) {
    error_log('[sync] '.$e->getMessage());
    mark_provider_synced($pid, false, $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'log'=>$log]);
}
