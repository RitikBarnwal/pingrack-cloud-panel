<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/mailer_invoice.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/os_icons.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();

// ── Load providers ─────────────────────────────────────────
$providers    = get_all_providers();
$active_provs = array_filter($providers, fn($p) => $p['is_active']);

// ── Load regions from DB — all providers, keyed by slug ─────
$all_regions        = [];
$country_regions    = [];
$region_provider    = [];

foreach ($active_provs as $prov) {
    $st = db()->prepare('SELECT * FROM region_catalog WHERE provider_id=? AND is_active=1 ORDER BY country,city');
    $st->execute([$prov['id']]);
    foreach ($st->fetchAll() ?: [] as $r) {
        $slug = $r['slug'];
        $cc   = strtolower($r['country_code'] ?? 'xx');

        if (!isset($all_regions[$slug])) {
            $all_regions[$slug] = array_merge($r, [
                'provider_id'   => $prov['id'],
                'provider_type' => $prov['provider_type'],
                'provider_name' => $prov['display_name'],
                'country_full'  => country_name($r['country'] ?? $r['country_code'] ?? ''),
            ]);
        }
        $region_provider[$slug] = $prov['id'];

        if (!isset($country_regions[$cc])) {
            $country_regions[$cc] = [
                'country_code' => $cc,
                'country_name' => country_name($r['country'] ?? $cc),
                'flag'         => $cc,
                'regions'      => [],
            ];
        }
        if (!in_array($slug, array_column($country_regions[$cc]['regions'], 'slug'))) {
            $country_regions[$cc]['regions'][] = [
                'slug'          => $slug,
                'city'          => $r['city'],
                'provider_id'   => $prov['id'],
                'provider_name' => $prov['display_name'],
                'provider_type' => $prov['provider_type'],
            ];
        }
    }
}
uasort($country_regions, fn($a,$b) => strcmp($a['country_name'], $b['country_name']));

// ── Load ALL plan_region_prices ──────────────────────────────
$region_plan_map = [];
$st = db()->query(
    'SELECT prp.*, pp.vcpu, pp.ram_gb, pp.disk_gb, pp.cpu_type,
            COALESCE(pvp.display_name, pp.label, pp.slug) AS display_name,
            p.display_name AS provider_name, p.provider_type
     FROM plan_region_prices prp
     JOIN plan_pricing pp ON pp.provider_id=prp.provider_id AND pp.slug=prp.plan_slug
     JOIN providers p      ON p.id=prp.provider_id AND p.is_active=1
     LEFT JOIN provider_plans pvp ON pvp.provider_id=prp.provider_id AND pvp.plan_api_id=prp.plan_slug
     WHERE pp.is_active=1
     ORDER BY pp.vcpu, pp.ram_gb'
);
foreach ($st->fetchAll() ?: [] as $row) {
    $rslug = $row['region_slug'];
    if (!isset($region_plan_map[$rslug])) $region_plan_map[$rslug] = [];
    $region_plan_map[$rslug][] = [
        'slug'          => $row['plan_slug'],
        'label'         => $row['display_name'],
        'vcpu'          => (int)$row['vcpu'],
        'ram_gb'        => (float)$row['ram_gb'],
        'disk_gb'       => (int)$row['disk_gb'],
        'cpu_type'      => $row['cpu_type'] ?? 'shared',
        'price_usd'     => (float)$row['price_usd'],
        'price_inr'     => (float)$row['price_inr'],
        'provider_id'   => (int)$row['provider_id'],
        'provider_name' => $row['provider_name'],
        'provider_type' => $row['provider_type'],
    ];
}

// ── Load OS images ─────────────────────────────────────────
$db_images = [];
$st = db()->query("SELECT * FROM image_catalog WHERE is_active=1 ORDER BY os_name, os_version DESC");
foreach ($st->fetchAll() ?: [] as $img) {
    $db_images[] = $img;
}

// ── BUILD JS IMAGE LIST (provider-aware) ────────────────────
// Each image exported with its provider_id (0 = universal/all providers)
$js_images = [];
foreach ($db_images as $img) {
    $js_images[] = [
        'slug'        => $img['slug'],
        'os_name'     => strtolower($img['os_name'] ?? ''),
        'os_version'  => $img['os_version'] ?? '',
        'label'       => $img['label'] ?: (($img['os_name'] ?? '') . ' ' . ($img['os_version'] ?? '')),
        'image_type'  => $img['image_type'] ?? 'system',
        // provider_id=0 means "universal" — show for ALL providers
        'provider_id' => isset($img['provider_id']) ? (int)$img['provider_id'] : 0,
    ];
}

// Fallback if DB has no images at all
if (empty($js_images)) {
    $js_images = [
        ['slug'=>'ubuntu-24.04', 'os_name'=>'ubuntu',   'os_version'=>'24.04',    'label'=>'Ubuntu 24.04',     'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'ubuntu-22.04', 'os_name'=>'ubuntu',   'os_version'=>'22.04',    'label'=>'Ubuntu 22.04',     'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'debian-12',    'os_name'=>'debian',   'os_version'=>'12',       'label'=>'Debian 12',        'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'centos-stream-9','os_name'=>'centos', 'os_version'=>'Stream 9', 'label'=>'CentOS Stream 9',  'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'fedora-40',    'os_name'=>'fedora',   'os_version'=>'40',       'label'=>'Fedora 40',        'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'rocky-linux-9','os_name'=>'rocky',    'os_version'=>'9',        'label'=>'Rocky Linux 9',    'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'almalinux-9',  'os_name'=>'alma',     'os_version'=>'9',        'label'=>'AlmaLinux 9',      'image_type'=>'system', 'provider_id'=>0],
        ['slug'=>'opensuse-15.5','os_name'=>'opensuse', 'os_version'=>'15.5',     'label'=>'openSUSE 15.5',    'image_type'=>'system', 'provider_id'=>0],
    ];
}

// ── Build OS icon map for JS ────────────────────────────────
$js_icon_map = [];
foreach ($js_images as $img) {
    $key = strtolower($img['os_name']);
    if ($key && !isset($js_icon_map[$key])) {
        $js_icon_map[$key] = get_os_icon_url($key);
    }
}

function country_name(string $code): string {
    $map = [
        'DE' => 'Germany',     'FI' => 'Finland',     'US' => 'United States',
        'SG' => 'Singapore',   'IN' => 'India',        'GB' => 'United Kingdom',
        'FR' => 'France',      'NL' => 'Netherlands',  'AU' => 'Australia',
        'JP' => 'Japan',       'CA' => 'Canada',       'BR' => 'Brazil',
        'PL' => 'Poland',      'CZ' => 'Czech Republic','AT' => 'Austria',
        'CH' => 'Switzerland', 'SE' => 'Sweden',       'NO' => 'Norway',
        'DK' => 'Denmark',     'ES' => 'Spain',        'IT' => 'Italy',
        'PT' => 'Portugal',    'HK' => 'Hong Kong',    'KR' => 'South Korea',
        'ZA' => 'South Africa','AE' => 'UAE',
    ];
    $code = strtoupper(trim($code));
    return $map[$code] ?? $code;
}

// SSH keys
$st = db()->prepare('SELECT * FROM ssh_keys WHERE user_id=? ORDER BY created_at DESC');
$st->execute([$user['id']]);
$ssh_keys = $st->fetchAll() ?: [];

// ── KYC limit check for page load ───────────────────────────
$max_without_kyc = (int)get_setting('max_servers_without_kyc', '0');
$kyc_limit_active = false;
$kyc_limit_status = 'none'; // none, pending, under_review, rejected, approved
$active_srv_count = 0;

if ($max_without_kyc > 0) {
    $kyc_chk = db()->prepare(
        "SELECT status FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1"
    );
    $kyc_chk->execute([(int)$user['id']]);
    $kyc_limit_status = $kyc_chk->fetchColumn() ?: 'none';

    if ($kyc_limit_status !== 'approved') {
        $srv_chk = db()->prepare(
            "SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL AND status != 'deleted'"
        );
        $srv_chk->execute([(int)$user['id']]);
        $active_srv_count = (int)$srv_chk->fetchColumn();
        $kyc_limit_active = ($active_srv_count >= $max_without_kyc);
    }
}

// Pre-selects
$sel_region = $_GET['region'] ?? array_key_first($all_regions) ?? 'nbg1';
$sel_plan   = $_GET['plan']   ?? '';
$sel_image  = $_GET['image']  ?? 'ubuntu-24.04';
$sel_name   = htmlspecialchars($_GET['name'] ?? '');

// ── POST ───────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $name        = trim($_POST['name']   ?? '');
    $plan_slug   = trim($_POST['plan']   ?? '');
    $image_slug  = trim($_POST['image']  ?? '');
    $region_slug = trim($_POST['region'] ?? '');
    $ssh_key_ids = array_map('intval', (array)($_POST['ssh_keys'] ?? []));

    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,60}[a-z0-9]$/', $name)) {
        $error = 'Server name: lowercase letters, numbers, hyphens. Min 3 chars.';
    } elseif (!$plan_slug || !$image_slug || !$region_slug) {
        $error = 'Please select a location, image, and plan type.';
    }

    $plan_price_row = null;
    if (!$error) {
        $ps = db()->prepare(
            'SELECT prp.*, pp.vcpu, pp.ram_gb, pp.disk_gb, pp.cpu_type
             FROM plan_region_prices prp
             JOIN plan_pricing pp ON pp.provider_id=prp.provider_id AND pp.slug=prp.plan_slug
             WHERE prp.plan_slug=? AND prp.region_slug=? AND pp.is_active=1 LIMIT 1'
        );
        $ps->execute([$plan_slug, $region_slug]);
        $plan_price_row = $ps->fetch();
        if (!$plan_price_row) $error = 'Invalid plan or region combination.';
    }

    if (!$error) {
        $price_hourly  = $currency === 'INR'
            ? (float)$plan_price_row['price_inr']
            : (float)$plan_price_row['price_usd'];
        $min_required  = $price_hourly * 5;

        if ($balance < $min_required) {
            $error = 'Please add minimum 5 hours billing balance ('
                . $curr_sym . number_format($min_required, 2)
                . ') to activate this server. Current balance: '
                . $curr_sym . number_format($balance, 2) . '.';
        }
    }

    // ── KYC / Server limit check ───────────────────────────────
    if (!$error) {
        $max_without_kyc = (int)get_setting('max_servers_without_kyc', '0');

        if ($max_without_kyc > 0) {
            // Check user's KYC status
            $kyc_st = db()->prepare(
                "SELECT status FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1"
            );
            $kyc_st->execute([(int)$user['id']]);
            $kyc_status = $kyc_st->fetchColumn() ?: 'none';

            if ($kyc_status !== 'approved') {
                // Count active servers
                $srv_count_st = db()->prepare(
                    "SELECT COUNT(*) FROM servers WHERE user_id=? AND deleted_at IS NULL AND status != 'deleted'"
                );
                $srv_count_st->execute([(int)$user['id']]);
                $active_srv_count = (int)$srv_count_st->fetchColumn();

                if ($active_srv_count >= $max_without_kyc) {
                    $error = '__KYC_REQUIRED__'; // special marker for frontend popup
                }
            }
        }
    }

    if (!$error) {
        try {
            $region_info = $all_regions[$region_slug] ?? null;
            $prov_id_post = (int)($_POST['provider_id'] ?? 0);
            $prov_id      = $prov_id_post
                          ?: (int)($plan_price_row['provider_id'] ?? 0)
                          ?: (int)($region_info['provider_id'] ?? ($active_provs[array_key_first($active_provs)]['id'] ?? 0));
            $prov_row     = get_provider($prov_id);

            $prov_type_slug = strtolower($prov_row['provider_type'] ?? 'virtualizor');
            $bootstrap_file = __DIR__ . '/../providers/' . $prov_type_slug . '/bootstrap.php';
            if (!file_exists($bootstrap_file)) {
                throw new RuntimeException("No bootstrap found for provider type '{$prov_type_slug}'.");
            }
            require_once $bootstrap_file;
            CloudProvider::reset();
            $cloud = new CloudProvider($prov_row['api_key']);

            $provider_ssh_ids = [];
            foreach ($ssh_key_ids as $kid) {
                $ks = db()->prepare('SELECT provider_id, public_key FROM ssh_keys WHERE id=? AND user_id=? LIMIT 1');
                $ks->execute([$kid, $user['id']]);
                $row = $ks->fetch();
                if (!$row) continue;

                if ($prov_type_slug === 'linode') {
                    if (!empty($row['public_key'])) $provider_ssh_ids[] = $row['public_key'];
                } else {
                    if ($row['provider_id']) $provider_ssh_ids[] = (int)$row['provider_id'];
                }
            }

            $result    = $cloud->servers->create(['name'=>$name,'plan'=>$plan_slug,'image'=>$image_slug,'region'=>$region_slug,'ssh_key_ids'=>$provider_ssh_ids]);
            $srv       = $result['server'];
            $root_pass = $result['root_password'] ?? null;

            $os_lbl = '';
            foreach ($db_images as $img) { if ($img['slug']===$image_slug){ $os_lbl=$img['label']; break; } }
            if (!$os_lbl) $os_lbl = $image_slug;

            $price_usd = (float)$plan_price_row['price_usd'];
            $price_cur = $currency === 'INR' ? (float)$plan_price_row['price_inr'] : $price_usd;

            $server_id = db_create_server((int)$user['id'], [
                'provider_id'        => $srv['id'],
                'source_provider_id' => $prov_id,
                'name'             => $name,
                'status'           => 'provisioning',
                'plan_slug'        => $plan_slug,
                'image_slug'       => $image_slug,
                'region_slug'      => $region_slug,
                'vcpu'             => $srv['vcpu']    ?? $plan_price_row['vcpu'],
                'ram_gb'           => $srv['ram_gb']  ?? $plan_price_row['ram_gb'],
                'disk_gb'          => $srv['disk_gb'] ?? $plan_price_row['disk_gb'],
                'ipv4'             => $srv['ipv4']    ?? null,
                'ipv6'             => $srv['ipv6']    ?? null,
                'os_label'         => $os_lbl,
                'region_label'     => ($region_info['city'] ?? $region_slug).', '.($region_info['country'] ?? ''),
                'region_flag'      => strtolower($region_info['country_code'] ?? 'de'),
                'price_hourly'     => $price_cur,
                'price_hourly_usd' => $price_usd,
                'price_monthly'    => $price_cur * 730,
                'currency'         => $currency,
                'root_password'    => $root_pass ? base64_encode(openssl_encrypt($root_pass,'AES-128-ECB',substr(hash('sha256',$prov_row['api_key']),0,16))) : null,
            ]);

            log_server_action($server_id,(int)$user['id'],'create','success');
            send_server_order_email($user, get_server($server_id,(int)$user['id']) ?: [], $prov_row['api_key']);
            header('Location: '.BASE_URL.'/servers/view.php?id='.$server_id.'&new=1');
            exit;
        } catch (Throwable $e) {
            error_log('[deploy] '.$e->getMessage());
            $error = 'Deployment failed. Please try again.';
        }
    }
}

$region_plan_json    = json_encode($region_plan_map,  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$all_regions_json    = json_encode(array_values($all_regions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$country_regions_json= json_encode(array_values($country_regions), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$js_images_json      = json_encode($js_images, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$js_icon_map_json    = json_encode($js_icon_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$os_default_icon     = get_os_icon_url('linux'); // fallback icon
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Create Server — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ─── Base ─────────────────────────────────────────────── */
    body,.main-content{font-family:'Plus Jakarta Sans',sans-serif !important;background:#f5f7fa !important}

    /* ─── Layout ────────────────────────────────────────────── */
    .cv-shell{display:grid;grid-template-columns:1fr 288px;min-height:calc(100vh - 58px)}
    .cv-main{padding:28px 32px 80px;max-width:860px}
    .cv-rail{
      position:sticky;top:58px;
      background:#fff;border-left:1.5px solid #e8edf3;
      display:flex;flex-direction:column;overflow-y:auto;
      align-self:start;
    }

    /* ─── Page header ───────────────────────────────────────── */
    .cv-header{margin-bottom:28px}
    .cv-title{font-size:22px;font-weight:800;color:#0f172a;letter-spacing:-.4px}
    .cv-sub{font-size:13px;color:#94a3b8;margin-top:4px}

    /* ─── Error ─────────────────────────────────────────────── */
    .cv-err{
      display:flex;align-items:flex-start;gap:10px;
      padding:13px 16px;margin-bottom:22px;
      background:#fef2f2;border:1.5px solid #fca5a5;
      border-radius:11px;font-size:13px;color:#dc2626;
    }

    /* ─── Section ───────────────────────────────────────────── */
    .cv-sec{margin-bottom:28px}
    .cv-sec-hd{display:flex;align-items:center;gap:10px;margin-bottom:14px}
    .cv-num{
      width:24px;height:24px;border-radius:7px;
      background:linear-gradient(135deg,#4f46e5,#7c3aed);
      color:#fff;font-size:11.5px;font-weight:800;
      display:flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .cv-sec-title{font-size:14.5px;font-weight:800;color:#0f172a}
    .cv-sec-badge{
      margin-left:auto;font-size:11px;font-weight:700;
      padding:2px 9px;border-radius:99px;
      background:#ede9fe;color:#6d28d9;
    }

    /* ─── Region cards ──────────────────────────────────────── */
    .rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}
    .rcard{
      border:1.5px solid #e8edf3;border-radius:12px;
      padding:13px 14px;cursor:pointer;
      transition:all .17s;background:#fff;
      display:flex;align-items:center;gap:11px;
      user-select:none;position:relative;overflow:hidden;
    }
    .rcard::after{
      content:'';position:absolute;inset:0;
      background:linear-gradient(135deg,rgba(79,70,229,.04),transparent);
      opacity:0;transition:opacity .17s;pointer-events:none;
    }
    .rcard:hover{border-color:#a5b4fc;box-shadow:0 2px 10px rgba(79,70,229,.08)}
    .rcard:hover::after{opacity:1}
    .rcard.sel{
      border-color:#4f46e5;background:#f5f3ff;
      box-shadow:0 0 0 3px rgba(79,70,229,.1),0 2px 12px rgba(79,70,229,.1);
    }
    .rcard.sel::after{opacity:1}
    .rflag{width:28px;height:21px;border-radius:4px;overflow:hidden;flex-shrink:0;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
    .rflag img{width:100%;height:100%;object-fit:cover;display:block}
    .rcountry{font-size:13px;font-weight:700;color:#1e293b;line-height:1.2}
    .rcard.sel .rcountry{color:#4338ca}
    .rdcs{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
    .rdcpill{
      font-size:10px;font-weight:700;padding:2px 8px;
      border-radius:99px;background:#f1f5f9;
      border:1px solid #e2e8f0;color:#64748b;
      cursor:pointer;transition:all .13s;
    }
    .rdcpill:hover{border-color:#818cf8;color:#4338ca;background:#eef2ff}
    .rdcpill.on{background:#4f46e5;border-color:#4f46e5;color:#fff}

    /* ─── Plan table ────────────────────────────────────────── */
    .ptabs{
      display:flex;gap:2px;padding:3px;
      background:#f1f5f9;border-radius:10px;
      margin-bottom:14px;width:fit-content;
    }
    .ptab{
      padding:6px 16px;border-radius:8px;
      font-size:12.5px;font-weight:700;
      color:#64748b;border:none;background:transparent;
      cursor:pointer;font-family:inherit;transition:all .15s;
    }
    .ptab.on{background:#fff;color:#1e293b;box-shadow:0 1px 4px rgba(0,0,0,.1)}

    .pwrap{
      border:1.5px solid #e8edf3;border-radius:12px;
      overflow:hidden;background:#fff;
      overflow-x: auto;
    }
    .ptbl{width:100%;border-collapse:collapse}
    .ptbl thead tr{background:#f8fafc}
    .ptbl thead th{
      padding:9px 14px;text-align:left;
      font-size:10.5px;font-weight:700;text-transform:uppercase;
      letter-spacing:.8px;color:#94a3b8;
      border-bottom:1.5px solid #e8edf3;white-space:nowrap;
    }
    .ptbl thead th.ar{text-align:right}
    .prow{cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .12s}
    .prow:last-child{border:none}
    .prow:hover td{background:#f8fafc}
    .prow.on td{background:#f5f3ff}
    .prow.on{border-left:3px solid #4f46e5}
    .prow td{padding:11px 9px;font-size:13px;vertical-align:middle}
    .pradio{
      width:17px;height:17px;border:2px solid #cbd5e1;
      border-radius:50%;display:flex;align-items:center;justify-content:center;
      transition:all .15s;flex-shrink:0;
    }
    .prow.on .pradio{background:#4f46e5;border-color:#4f46e5}
    .prow.on .pradio::after{content:'';width:6px;height:6px;border-radius:50%;background:#fff}
    .pname{font-weight:800;color:#1e293b;font-family:'JetBrains Mono',monospace;font-size:12.5px}
    .pbadge{
      display:inline-block;font-size:9px;font-weight:700;
      padding:1px 6px;border-radius:3px;margin-left:5px;
      text-transform:uppercase;letter-spacing:.4px;vertical-align:middle;
    }
    .pbadge-shared{background:#dbeafe;color:#1d4ed8}
    .pbadge-dedicated{background:#d1fae5;color:#065f46}
    .pspec{font-size:12.5px;color:#475569;font-family:'JetBrains Mono',monospace}
    .pprice{text-align:right;white-space:nowrap}
    .pprice-hr{font-size:13px;font-weight:800;color:#1e293b}
    .prow.on .pprice-hr{color:#4f46e5}
    .pprice-mo{font-size:11px;color:#94a3b8;margin-top:2px}
    .no-plans td{padding:22px;text-align:center;color:#94a3b8;font-size:13px}

    /* ─── OS grid ───────────────────────────────────────────── */
    .itabs{
      display:flex;gap:2px;padding:3px;
      background:#f1f5f9;border-radius:10px;
      margin-bottom:14px;width:fit-content;
    }
    .itab{
      padding:6px 16px;border-radius:8px;
      font-size:12.5px;font-weight:700;
      color:#64748b;border:none;background:transparent;
      cursor:pointer;font-family:inherit;transition:all .15s;
    }
    .itab.on{background:#fff;color:#1e293b;box-shadow:0 1px 4px rgba(0,0,0,.1)}

    .ogrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(108px,1fr));gap:8px}
    .ocard{
      border:1.5px solid #e8edf3;border-radius:12px;
      padding:16px 10px 12px;cursor:pointer;
      transition:all .17s;background:#fff;
      display:flex;flex-direction:column;
      align-items:center;gap:8px;text-align:center;
      user-select:none;
    }
    .ocard:hover{border-color:#a5b4fc;background:#f8f7ff;box-shadow:0 2px 8px rgba(79,70,229,.07)}
    .ocard.on{
      border-color:#4f46e5;background:#f5f3ff;
      box-shadow:0 0 0 3px rgba(79,70,229,.1);
    }
    .oimg{width:40px;height:40px;display:flex;align-items:center;justify-content:center}
    .oimg img{width:38px;height:38px;object-fit:contain}
    .oname{font-size:12.5px;font-weight:700;color:#1e293b}
    .ocard.on .oname{color:#4338ca}
    .oversel{
      width:100%;padding:4px 6px;
      border:1.5px solid #e8edf3;border-radius:7px;
      font-size:11.5px;color:#475569;background:#fff;
      outline:none;cursor:pointer;font-family:'JetBrains Mono',monospace;
      transition:border-color .13s;
    }
    .oversel:focus{border-color:#4f46e5}
    .ocard.on .oversel{border-color:#a5b4fc;background:#ede9fe;color:#4338ca}
    .no-img-note{
      grid-column:1/-1;text-align:center;
      padding:24px;color:#94a3b8;font-size:13px;
      border:1.5px dashed #e2e8f0;border-radius:10px;
    }
    .img-loading{
      grid-column:1/-1;display:flex;align-items:center;
      justify-content:center;gap:8px;padding:28px;
      color:#94a3b8;font-size:13px;
    }
    @keyframes spin{to{transform:rotate(360deg)}}
    .spin{animation:spin .7s linear infinite}

    /* ─── Networking ────────────────────────────────────────── */
    .netrow{
      display:flex;align-items:center;gap:12px;
      padding:12px 15px;background:#fff;
      border:1.5px solid #e8edf3;border-radius:11px;margin-bottom:8px;
    }
    .netdot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
    .netlbl{font-size:13.5px;font-weight:700;color:#1e293b}
    .netsub{font-size:12px;color:#94a3b8;margin-top:2px}
    .netbadge{
      margin-left:auto;font-size:10.5px;font-weight:700;
      padding:2px 9px;border-radius:99px;
    }
    .netbadge-inc{background:#dcfce7;color:#15803d}
    .netbadge-free{background:#dbeafe;color:#1d4ed8}

    /* ─── SSH ───────────────────────────────────────────────── */
    .ssh-warn{
      background:#fefce8;border:1.5px solid #fde68a;
      border-radius:10px;padding:11px 14px;
      font-size:12.5px;color:#854d0e;margin-bottom:10px;
    }
    .sshlist{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
    .sshitem{
      display:flex;align-items:center;gap:12px;
      padding:10px 14px;background:#fff;
      border:1.5px solid #e8edf3;border-radius:10px;
      cursor:pointer;transition:border-color .13s;
    }
    .sshitem:hover,.sshitem.on{border-color:#818cf8;background:#f5f3ff}
    .sshitem input{width:15px;height:15px;accent-color:#4f46e5;flex-shrink:0}
    .sshname{font-size:13px;font-weight:700;color:#1e293b}
    .sshfp{font-size:11px;color:#94a3b8;font-family:'JetBrains Mono',monospace;margin-top:2px}

    /* ─── Name input ────────────────────────────────────────── */
    .namewrap{position:relative;max-width:400px}
    .nameico{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}
    .nameinp{
      width:100%;padding:11px 13px 11px 38px;
      background:#fff;border:1.5px solid #e8edf3;
      border-radius:10px;font-family:'JetBrains Mono',monospace;
      font-size:14px;color:#1e293b;outline:none;
      transition:border-color .15s,box-shadow .15s;
    }
    .nameinp:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
    .namehint{font-size:11.5px;color:#94a3b8;margin-top:5px}

    /* ─── Rail ──────────────────────────────────────────────── */
    .rail-body{padding:18px;display:flex;flex-direction:column;gap:0;flex:1}
    .rail-sec{padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid #f1f5f9}
    .rail-sec:last-child{border:none;padding:0;margin:0}
    .rail-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.9px;color:#94a3b8;margin-bottom:8px}
    .rail-row{display:flex;justify-content:space-between;align-items:flex-start;gap:6px;padding:3px 0;font-size:12px}
    .rail-k{color:#64748b;font-weight:500;flex-shrink:0}
    .rail-v{color:#1e293b;font-weight:700;font-family:'JetBrains Mono',monospace;font-size:11.5px;text-align:right;word-break:break-all}

    .price-big{font-size:27px;font-weight:900;color:#0f172a;letter-spacing:-1.2px;line-height:1}
    .price-unit{font-size:11.5px;color:#94a3b8;margin-top:3px}
    .price-mo{font-size:12.5px;color:#64748b;margin-top:6px;font-weight:600}

    .walletbar{
      display:flex;justify-content:space-between;align-items:center;
      padding:10px 13px;background:#f8fafc;
      border:1.5px solid #e8edf3;border-radius:9px;
    }
    .walletlbl{font-size:12.5px;color:#64748b;font-weight:500}
    .walletval{font-size:13px;font-weight:800;color:#1e293b;font-family:'JetBrains Mono',monospace}
    .wallet-low{color:#dc2626 !important}

    .deploy-btn{
      width:100%;padding:13px;
      background:linear-gradient(135deg,#4f46e5,#7c3aed);
      color:#fff;border:none;border-radius:11px;
      font-size:14.5px;font-weight:800;
      font-family:'Plus Jakarta Sans',sans-serif;
      cursor:pointer;transition:all .18s;
      display:flex;align-items:center;justify-content:center;gap:8px;
      box-shadow:0 4px 18px rgba(79,70,229,.3);
      letter-spacing:-.1px;
    }
    .deploy-btn:hover:not(:disabled){
      transform:translateY(-1px);
      box-shadow:0 6px 24px rgba(79,70,229,.42);
    }
    .deploy-btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}
    .terms{font-size:10.5px;color:#94a3b8;text-align:center;margin-top:8px;line-height:1.5}

    /* ─── Responsive ────────────────────────────────────────── */
    @media(max-width:720px){
      .cv-shell{display:block}
      .cv-main{padding:16px 14px 60px;max-width:100%}
      .cv-title{font-size:18px}
      .rgrid{grid-template-columns:1fr 1fr}
      .ogrid{grid-template-columns:repeat(3,1fr)}
    }
    @media(max-width:460px){
      .rgrid{grid-template-columns:1fr}
      .ogrid{grid-template-columns:1fr 1fr}
      .ptbl thead th:nth-child(6),
      .ptbl tbody td:nth-child(6){display:none}
    }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;background:#f5f7fa">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">Create Server</span>
    </div>

    <div class="topbar">
      <a href="<?= BASE_URL ?>/servers.php" style="display:flex;align-items:center;gap:6px;font-size:13px;color:#64748b;text-decoration:none;font-weight:600" onmouseover="this.style.color='#1e293b'" onmouseout="this.style.color='#64748b'">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Servers
      </a>
    </div>

    <form method="POST" id="cv-form">
      <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
      <input type="hidden" name="region"      id="f-region"   value="<?= htmlspecialchars($sel_region) ?>">
      <input type="hidden" name="plan"        id="f-plan"     value="<?= htmlspecialchars($sel_plan) ?>">
      <input type="hidden" name="provider_id" id="f-provider" value="">
      <input type="hidden" name="image"       id="f-image"    value="<?= htmlspecialchars($sel_image) ?>">

      <div class="cv-shell">

        <!-- ══════════ MAIN ══════════ -->
        <div class="cv-main">
          <div class="cv-header">
            <div class="cv-title">Create a Server</div>
            <div class="cv-sub">Select your configuration — billed per hour while running.</div>
          </div>

          <?php if ($error && $error !== '__KYC_REQUIRED__'): ?>
          <div class="cv-err">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($error) ?>
          </div>
          <?php endif; ?>

          <?php if (empty($active_provs)): ?>
          <div class="cv-err">No providers configured. Contact your administrator.</div>
          <?php endif; ?>

          <!-- ① Location -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">1</div>
              <div class="cv-sec-title">Location</div>
              <div class="cv-sec-badge" id="loc-badge">Choose a region</div>
            </div>
            <div class="rgrid">
              <?php foreach ($country_regions as $cc_key => $country): ?>
              <?php
                if (!($country['regions'][0] ?? null)) continue;
                $slugs = array_column($country['regions'], 'slug');
                $isSel = in_array($sel_region, $slugs);
              ?>
              <div class="rcard <?= $isSel?'sel':'' ?>" id="rcard-<?= htmlspecialchars($cc_key) ?>"
                   onclick="selCountry('<?= htmlspecialchars($cc_key) ?>')">
                <div class="rflag">
                  <img src="https://flagcdn.com/w40/<?= htmlspecialchars($country['flag']) ?>.png"
                       srcset="https://flagcdn.com/w80/<?= htmlspecialchars($country['flag']) ?>.png 2x"
                       alt="" onerror="this.style.display='none'">
                </div>
                <div style="flex:1;min-width:0">
                  <div class="rcountry"><?= htmlspecialchars($country['country_name']) ?></div>
                  <div class="rdcs">
                    <?php foreach ($country['regions'] as $dc): ?>
                    <span class="rdcpill <?= $sel_region===$dc['slug']?'on':'' ?>"
                          id="dcp-<?= htmlspecialchars($dc['slug']) ?>"
                          onclick="event.stopPropagation();selRegion('<?= htmlspecialchars($dc['slug']) ?>','<?= htmlspecialchars($cc_key) ?>')"
                          title="<?= htmlspecialchars($dc['provider_name']) ?>">
                      <?= htmlspecialchars($dc['city']) ?>
                    </span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- ② Plan -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">2</div>
              <div class="cv-sec-title">Plan</div>
              <div class="cv-sec-badge" id="plan-badge">Select specs</div>
            </div>
            <div class="ptabs">
              <button type="button" class="ptab on" onclick="switchPTab('shared',this)">Shared vCPU</button>
              <button type="button" class="ptab"    onclick="switchPTab('dedicated',this)">Dedicated vCPU</button>
            </div>
            <div id="pwrap-shared" class="pwrap">
              <table class="ptbl">
                <thead><tr>
                  <th style="width:36px"></th>
                  <th>Name</th><th>vCPU</th><th>RAM</th><th>Disk</th><th>Traffic</th>
                  <th class="ar">Price</th>
                </tr></thead>
                <tbody id="pb-shared"><tr class="no-plans"><td colspan="7">Select a location to load plans.</td></tr></tbody>
              </table>
            </div>
            <div id="pwrap-dedicated" class="pwrap" style="display:none;margin-top:10px">
              <table class="ptbl">
                <thead><tr>
                  <th style="width:36px"></th>
                  <th>Name</th><th>vCPU</th><th>RAM</th><th>Disk</th><th>Traffic</th>
                  <th class="ar">Price</th>
                </tr></thead>
                <tbody id="pb-dedicated"><tr class="no-plans"><td colspan="7">No dedicated plans for this location.</td></tr></tbody>
              </table>
            </div>
          </div>

          <!-- ③ Image -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">3</div>
              <div class="cv-sec-title">Image</div>
              <div class="cv-sec-badge" id="img-badge">Choose OS</div>
            </div>
            <div class="itabs">
              <button type="button" class="itab on" id="itab-os"  onclick="switchITab('os',this)">OS Images</button>
              <button type="button" class="itab"    id="itab-app" onclick="switchITab('app',this)">Apps</button>
            </div>
            <div id="ig-os"  class="ogrid"><div class="img-loading"><svg class="spin" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" width="14" height="14"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>Loading…</div></div>
            <div id="ig-app" class="ogrid" style="display:none"><div class="img-loading">Loading apps…</div></div>
          </div>

          <!-- ④ Networking -->
          <div class="cv-sec">
            <div class="cv-sec-hd"><div class="cv-num">4</div><div class="cv-sec-title">Networking</div></div>
            <div class="netrow">
              <div class="netdot" style="background:#22c55e"></div>
              <div><div class="netlbl">Public IPv4</div><div class="netsub">Primary IPv4 assigned automatically</div></div>
              <span class="netbadge netbadge-inc">Included</span>
            </div>
            <div class="netrow">
              <div class="netdot" style="background:#3b82f6"></div>
              <div><div class="netlbl">Public IPv6</div><div class="netsub">IPv6 at no extra charge</div></div>
              <span class="netbadge netbadge-free">Free</span>
            </div>
          </div>

          <!-- ⑤ SSH Keys -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">5</div>
              <div class="cv-sec-title">SSH Keys</div>
              <span style="font-size:11.5px;color:#94a3b8;margin-left:6px;font-weight:400">optional</span>
            </div>
            <?php if (empty($ssh_keys)): ?>
            <div class="ssh-warn">
              <strong>No SSH keys added.</strong> Root password will be emailed after deployment.
              <a href="<?= BASE_URL ?>/ssh-keys.php" style="color:#854d0e;font-weight:700;margin-left:6px">+ Add SSH Key →</a>
            </div>
            <?php else: ?>
            <div class="sshlist">
              <?php foreach ($ssh_keys as $k): ?>
              <label class="sshitem" onclick="this.classList.toggle('on')">
                <input type="checkbox" name="ssh_keys[]" value="<?= $k['id'] ?>">
                <div>
                  <div class="sshname"><?= htmlspecialchars($k['name']) ?></div>
                  <div class="sshfp"><?= htmlspecialchars($k['fingerprint'] ?? '') ?></div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
            <a href="<?= BASE_URL ?>/ssh-keys.php" style="font-size:12.5px;color:#4f46e5;font-weight:700;text-decoration:none">+ Add Another Key</a>
            <?php endif; ?>
          </div>

          <!-- ⑥ Name -->
          <div class="cv-sec">
            <div class="cv-sec-hd"><div class="cv-num">6</div><div class="cv-sec-title">Server Name</div></div>
            <div class="namewrap">
              <div class="nameico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
              </div>
              <input type="text" name="name" id="srv-name" class="nameinp"
                     value="<?= $sel_name ?>"
                     placeholder="my-web-server"
                     pattern="[a-z0-9][a-z0-9\-]{1,60}[a-z0-9]"
                     autocomplete="off" oninput="onName(this)" required>
            </div>
            <div class="namehint">Lowercase letters, numbers, hyphens only · 3–62 characters</div>
          </div>
          
          <!-- ══════════ RAIL ══════════ -->
          <div class="cv-sec-hd"><div class="cv-num">7</div><div class="cv-sec-title">Overall Summary</div></div>
        <div class="cv-rail">
          <div class="rail-body">

            <div class="rail-sec">
              <div class="rail-lbl">Summary</div>
              <div class="rail-row"><span class="rail-k">Name</span>    <span class="rail-v" id="r-name">—</span></div>
              <div class="rail-row"><span class="rail-k">Location</span><span class="rail-v" id="r-region">—</span></div>
              <div class="rail-row"><span class="rail-k">Image</span>   <span class="rail-v" id="r-image">—</span></div>
              <div class="rail-row"><span class="rail-k">Plan</span>    <span class="rail-v" id="r-plan">—</span></div>
              <div class="rail-row"><span class="rail-k">Specs</span>   <span class="rail-v" id="r-specs">—</span></div>
              <div class="rail-row"><span class="rail-k">Network</span> <span class="rail-v">IPv4 + IPv6</span></div>
            </div>

            <div class="rail-sec">
              <div class="rail-lbl">Price</div>
              <div class="price-big" id="r-hr">—</div>
              <div class="price-unit">per hour · billed while running</div>
              <div class="price-mo" id="r-mo"></div>
            </div>

            <div class="rail-sec">
              <div class="rail-lbl">Wallet</div>
              <div class="walletbar">
                <span class="walletlbl">Balance</span>
                <span class="walletval <?= $balance<5?'wallet-low':'' ?>"><?= $curr_sym.number_format($balance,2) ?></span>
              </div>
              <?php if ($balance<5): ?>
              <a href="<?= BASE_URL ?>/billing.php?action=topup"
                 style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:8px;padding:9px;background:#fef2f2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:9px;font-size:12px;font-weight:700;text-decoration:none">
                ⚠ Low balance — Add Funds →
              </a>
              <?php endif; ?>
            </div>

            <div class="rail-sec">
              <button type="submit" class="deploy-btn" id="deploy-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Create &amp; Deploy
              </button>
              <div class="terms">By creating a server you agree to our<br>Terms of Service &amp; Privacy Policy.</div>
            </div>

          </div>
        </div>

        </div><!-- /cv-main -->

      </div><!-- /cv-shell -->
    </form>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('open')"></div>

<script>
/* ── Data from PHP ─────────────────────────────────────────── */
var CUR        = '<?= $currency ?>';
var SYM        = '<?= addslashes($curr_sym) ?>';
var RPLANS     = <?= $region_plan_json ?>;
var REGIONS    = <?= $all_regions_json ?>;
var CREGIONS   = <?= $country_regions_json ?>;
var DEFICON    = '<?= addslashes($os_default_icon) ?>';
var IMGS       = <?= $js_images_json ?>;
var ICONMAP    = <?= $js_icon_map_json ?>;

/* ── State ─────────────────────────────────────────────────── */
var sRegion  = '<?= htmlspecialchars(addslashes($sel_region)) ?>';
var sPlan    = '<?= htmlspecialchars(addslashes($sel_plan)) ?>';
var sImage   = '<?= htmlspecialchars(addslashes($sel_image)) ?>';
var sPlanD   = null;
var sProvId  = 0;
var planReg  = {};
var activeIT = 'os';

/* ── Helpers ───────────────────────────────────────────────── */
function xe(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function osLabel(n){ var m={alma:'AlmaLinux',opensuse:'openSUSE',rocky:'Rocky Linux',ubuntu:'Ubuntu',debian:'Debian',centos:'CentOS',fedora:'Fedora',freebsd:'FreeBSD',windows:'Windows'}; return m[n]||(n.charAt(0).toUpperCase()+n.slice(1)); }
function iconUrl(n){ return ICONMAP[n]||ICONMAP['linux']||DEFICON; }

/* ── Image render ──────────────────────────────────────────── */
function filterImgs(provId){
  sProvId = provId||0;
  renderIG('os');
  renderIG('app');
}

function grouped(type){
  var g={};
  IMGS.forEach(function(img){
    if((img.image_type||'system')!==type) return;
    if(img.provider_id!==0 && sProvId!==0 && img.provider_id!==sProvId) return;
    var k=img.os_name||img.label.toLowerCase();
    if(!g[k]) g[k]=[];
    g[k].push(img);
  });
  return g;
}

function renderIG(tab){
  var type = tab==='app'?'app':'system';
  var el   = document.getElementById(tab==='app'?'ig-app':'ig-os');
  if(!el) return;
  var g=grouped(type), ks=Object.keys(g);
  if(!ks.length){ el.innerHTML='<div class="no-img-note">No '+(type==='app'?'apps':'OS images')+' available.</div>'; return; }

  var slugs=[];
  ks.forEach(function(k){g[k].forEach(function(i){slugs.push(i.slug);});});
  var curVis=slugs.indexOf(sImage)!==-1;

  el.innerHTML = ks.map(function(k){
    var vs=g[k], isApp=type==='app';
    var isSel=vs.some(function(v){return v.slug===sImage;});
    var opts=vs.map(function(v){
      var lbl=isApp?v.label:(v.os_version||v.label);
      return '<option value="'+xe(v.slug)+'"'+(v.slug===sImage?' selected':'')+'>'+xe(lbl)+'</option>';
    }).join('');
    var dispName = isApp?(vs[0].label||osLabel(k)):osLabel(k);
    return '<div class="ocard'+(isSel?' on':'')+'" id="oc-'+xe(k)+'" onclick="pickOS(\''+xe(k)+'\')">'
      +'<div class="oimg"><img src="'+iconUrl(k)+'" alt="'+xe(k)+'" onerror="this.src=\''+DEFICON+'\'"></div>'
      +'<div class="oname">'+xe(dispName)+'</div>'
      +'<select class="oversel" id="ov-'+xe(k)+'" onchange="onVer(\''+xe(k)+'\',this)" onclick="event.stopPropagation()">'+opts+'</select>'
      +'</div>';
  }).join('');

  if(!curVis && tab===activeIT){
    var fk=ks[0];
    if(fk&&g[fk].length){ sImage=g[fk][0].slug; document.getElementById('f-image').value=sImage; var c=document.getElementById('oc-'+fk); if(c) c.classList.add('on'); }
  }
}

function switchITab(type,btn){
  activeIT=type;
  document.querySelectorAll('.itab').forEach(function(b){b.classList.remove('on');});
  if(btn) btn.classList.add('on');
  document.getElementById('ig-os').style.display  = type==='os' ?'':'none';
  document.getElementById('ig-app').style.display = type==='app'?'':'none';
}

/* ── Region ────────────────────────────────────────────────── */
function selCountry(cc){
  var c=CREGIONS.find(function(x){return x.country_code===cc;});
  if(c&&c.regions.length) selRegion(c.regions[0].slug,cc);
}

function selRegion(slug,cc){
  document.querySelectorAll('.rcard').forEach(function(c){c.classList.remove('sel');});
  if(!cc){ CREGIONS.forEach(function(c){c.regions.forEach(function(r){if(r.slug===slug) cc=c.country_code;});}); }
  var card=document.getElementById('rcard-'+(cc||slug));
  if(card) card.classList.add('sel');
  document.querySelectorAll('.rdcpill').forEach(function(p){p.classList.remove('on');});
  var pill=document.getElementById('dcp-'+slug);
  if(pill) pill.classList.add('on');
  sRegion=slug;
  document.getElementById('f-region').value=slug;

  var ri=REGIONS.find(function(r){return r.slug===slug;});
  var badge=document.getElementById('loc-badge');
  if(badge&&ri) badge.textContent=ri.city+', '+(ri.country_full||ri.country||'');

  buildReg(slug); renderPlans(slug); updateRail();
}

/* ── Plans ─────────────────────────────────────────────────── */
function buildReg(slug){ planReg={}; (RPLANS[slug]||[]).forEach(function(p){planReg[p.slug]=p;}); }

function renderPlans(slug){
  var all=RPLANS[slug]||[];
  var sh =all.filter(function(p){return p.cpu_type!=='dedicated';});
  var de =all.filter(function(p){return p.cpu_type==='dedicated';});
  renderPB('shared',sh); renderPB('dedicated',de);
  if(sPlan&&!planReg[sPlan]){ sPlan=''; sPlanD=null; document.getElementById('f-plan').value=''; document.getElementById('f-provider').value=''; }
  if(!sPlan&&sh.length) pickPlan(sh[0].slug);
  else if(sPlan&&planReg[sPlan]) pickPlan(sPlan,false);
}

function renderPB(type,plans){
  var tb=document.getElementById('pb-'+type); if(!tb) return;
  if(!plans.length){ tb.innerHTML='<tr class="no-plans"><td colspan="7">No '+type+' plans for this location.</td></tr>'; return; }
  tb.innerHTML=plans.map(function(p){
    var hr=CUR==='INR'?p.price_inr:p.price_usd;
    var isSel=p.slug===sPlan;
    return '<tr class="prow'+(isSel?' on':'')+'" id="pr-'+xe(p.slug)+'" data-slug="'+xe(p.slug)+'" onclick="handleP(this)">'
      +'<td><div class="pradio"></div></td>'
      +'<td><span class="pname">'+xe(p.label)+'</span><span class="pbadge pbadge-'+xe(p.cpu_type)+'">'+xe(p.cpu_type)+'</span></td>'
      +'<td><span class="pspec">'+p.vcpu+' vCPU</span></td>'
      +'<td><span class="pspec">'+p.ram_gb+' GB</span></td>'
      +'<td><span class="pspec">'+p.disk_gb+' GB</span></td>'
      +'<td><span class="pspec" style="color:#94a3b8">20 TB</span></td>'
      +'<td><div class="pprice"><div class="pprice-hr">'+SYM+hr.toFixed(4)+'/hr</div><div class="pprice-mo">~'+SYM+(hr*730).toFixed(2)+'/mo</div></div></td>'
      +'</tr>';
  }).join('');
}

function handleP(row){ var s=row.getAttribute('data-slug'); if(s) pickPlan(s); }

function pickPlan(slug,doUp){
  if(doUp===undefined) doUp=true;
  var p=planReg[slug]; if(!p) return;
  sPlan=slug; sPlanD=p;
  document.getElementById('f-plan').value=slug;
  document.getElementById('f-provider').value=p.provider_id||'';
  filterImgs(p.provider_id||0);
  document.querySelectorAll('.prow').forEach(function(r){r.classList.toggle('on',r.getAttribute('data-slug')===slug);});
  var badge=document.getElementById('plan-badge');
  if(badge) badge.textContent=p.vcpu+'vCPU · '+p.ram_gb+'GB · '+p.disk_gb+'GB';
  if(doUp) updateRail();
}

function switchPTab(type,btn){
  document.querySelectorAll('.ptab').forEach(function(t){t.classList.remove('on');});
  btn.classList.add('on');
  document.getElementById('pwrap-shared').style.display    = type==='shared'   ?'':'none';
  document.getElementById('pwrap-dedicated').style.display = type==='dedicated'?'':'none';
}

/* ── OS ────────────────────────────────────────────────────── */
function pickOS(k){
  document.querySelectorAll('.ocard').forEach(function(c){c.classList.remove('on');});
  var card=document.getElementById('oc-'+k); if(card) card.classList.add('on');
  var sel=document.getElementById('ov-'+k);
  sImage=sel?sel.value:k;
  document.getElementById('f-image').value=sImage;
  var badge=document.getElementById('img-badge');
  if(badge&&sel) badge.textContent=sel.options[sel.selectedIndex]?sel.options[sel.selectedIndex].text:'';
  updateRail();
}

function onVer(k,sel){
  sImage=sel.value; document.getElementById('f-image').value=sImage;
  document.querySelectorAll('.ocard').forEach(function(c){c.classList.remove('on');});
  var card=document.getElementById('oc-'+k); if(card) card.classList.add('on');
  var badge=document.getElementById('img-badge');
  if(badge) badge.textContent=sel.options[sel.selectedIndex]?sel.options[sel.selectedIndex].text:'';
  updateRail();
}

/* ── Name ──────────────────────────────────────────────────── */
function onName(inp){ document.getElementById('r-name').textContent=inp.value.trim()||'—'; }

/* ── Rail ──────────────────────────────────────────────────── */
function updateRail(){
  var ri=REGIONS.find(function(r){return r.slug===sRegion;});
  document.getElementById('r-region').textContent=ri?(ri.city+', '+(ri.country_full||ri.country||'')):sRegion;

  var imgName=document.querySelector('.ocard.on .oname');
  var imgVer =document.querySelector('.ocard.on .oversel');
  var itxt=imgName?imgName.textContent.trim():'—';
  if(imgVer&&imgVer.selectedIndex>=0) itxt+=' '+imgVer.options[imgVer.selectedIndex].text;
  document.getElementById('r-image').textContent=itxt;

  if(sPlanD){
    document.getElementById('r-plan').textContent  = sPlan.toUpperCase();
    document.getElementById('r-specs').textContent = sPlanD.vcpu+'vCPU / '+sPlanD.ram_gb+'GB / '+sPlanD.disk_gb+'GB';
    var hr=CUR==='INR'?sPlanD.price_inr:sPlanD.price_usd;
    document.getElementById('r-hr').textContent = SYM+hr.toFixed(4)+'/hr';
    document.getElementById('r-mo').textContent = '~'+SYM+(hr*730).toFixed(2)+'/month';
  } else {
    document.getElementById('r-plan').textContent='—';
    document.getElementById('r-specs').textContent='—';
    document.getElementById('r-hr').textContent='—';
    document.getElementById('r-mo').textContent='';
  }
}

/* ── Submit ────────────────────────────────────────────────── */
document.getElementById('cv-form').addEventListener('submit',function(e){
  if(!sPlan){
    e.preventDefault();
    document.querySelector('.pwrap').scrollIntoView({behavior:'smooth',block:'center'});
    return;
  }
  var btn=document.getElementById('deploy-btn');
  btn.disabled=true;
  btn.innerHTML='<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="15" height="15"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Deploying…';
});

/* ── Init ──────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded',function(){
  buildReg(sRegion);
  renderPlans(sRegion);
  if(!sPlan) filterImgs(0);
  var ne=document.getElementById('srv-name');
  if(ne&&ne.value) document.getElementById('r-name').textContent=ne.value;
  updateRail();
});

document.getElementById('cv-form').addEventListener('submit',function(e){
  if(!sPlan){
    e.preventDefault();
    document.querySelector('.pwrap').scrollIntoView({behavior:'smooth',block:'center'});
    return;
  }
  var btn = document.getElementById('deploy-btn');
  btn.disabled = true;
  btn.innerHTML =
    '<svg class="spin" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="15" height="15">'
    + '<polyline points="1 4 1 10 7 10"/>'
    + '<path d="M3.51 15a9 9 0 1 0 .49-4.86"/>'
    + '</svg> Deploying…';
});
</script>
<!-- ═══ KYC REQUIRED MODAL ═══════════════════════════════════ -->
<div id="kyc-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)">
  <div style="background:white;border-radius:16px;max-width:460px;width:100%;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.2);animation:modalIn .2s ease">
    <!-- Header -->
    <div style="background:linear-gradient(135deg,#fef2f2,#fff7ed);padding:28px 28px 20px;text-align:center;border-bottom:1px solid #fecaca">
      <div style="width:60px;height:60px;background:white;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;box-shadow:0 4px 14px rgba(220,38,38,.15)">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h3 style="font-size:18px;font-weight:800;color:#dc2626;margin-bottom:6px">KYC Verification Required</h3>
      <p style="font-size:13px;color:#9a3412;line-height:1.5">You've reached the server limit for unverified accounts.</p>
    </div>
    <!-- Body -->
    <div style="padding:24px 28px">
      <div style="background:#fef9c3;border:1px solid #fde047;border-radius:10px;padding:14px 16px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-start">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div style="font-size:13px;color:#78350f;line-height:1.6">
          <strong>Server limit reached:</strong> You currently have
          <strong id="kyc-srv-count"><?= $active_srv_count ?></strong> active server(s).
          The limit for unverified accounts is
          <strong><?= $max_without_kyc ?></strong> server(s).
        </div>
      </div>

      <?php
      $kyc_msg_map = [
        'none'         => ['Submit your KYC documents to get verified and create unlimited servers.', '#2563eb', 'Submit KYC →', BASE_URL.'/kyc.php'],
        'pending'      => ['Your KYC is <strong>under review</strong>. Please wait for admin approval before creating more servers.', '#d97706', 'Check KYC Status →', BASE_URL.'/kyc.php'],
        'under_review' => ['Your KYC is <strong>under review</strong>. Please wait for admin approval before creating more servers.', '#d97706', 'Check KYC Status →', BASE_URL.'/kyc.php'],
        'rejected'     => ['Your KYC was <strong>rejected</strong>. Please re-submit with valid documents to get approved.', '#dc2626', 'Re-submit KYC →', BASE_URL.'/kyc.php'],
      ];
      [$kyc_info_msg, $kyc_info_color, $kyc_btn_label, $kyc_btn_url] = $kyc_msg_map[$kyc_limit_status] ?? $kyc_msg_map['none'];
      ?>

      <div style="display:flex;gap:10px;align-items:flex-start;padding:13px 15px;background:<?= $kyc_info_color ?>11;border:1px solid <?= $kyc_info_color ?>33;border-radius:9px;margin-bottom:20px">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="<?= $kyc_info_color ?>" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div style="font-size:13px;color:<?= $kyc_info_color ?>;line-height:1.6"><?= $kyc_info_msg ?></div>
      </div>

      <div style="display:flex;flex-direction:column;gap:9px">
        <a href="<?= $kyc_btn_url ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:#dc2626;color:white;border-radius:9px;font-size:14px;font-weight:700;text-decoration:none;transition:background .15s" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <?= $kyc_btn_label ?>
        </a>
        <button onclick="closeKycModal()" style="padding:11px;background:white;color:#374151;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .14s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
          Cancel
        </button>
      </div>
    </div>
  </div>
</div>

<style>
@keyframes modalIn { from { opacity:0; transform:scale(.95) translateY(8px); } to { opacity:1; transform:none; } }
</style>

<script>
var KYC_LIMIT_ACTIVE  = <?= $kyc_limit_active ? 'true' : 'false' ?>;
var KYC_ERROR_FROM_POST = <?= $error === '__KYC_REQUIRED__' ? 'true' : 'false' ?>;
var MAX_SERVERS_WITHOUT_KYC = <?= $max_without_kyc ?>;

function showKycModal() {
  var overlay = document.getElementById('kyc-modal-overlay');
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeKycModal() {
  document.getElementById('kyc-modal-overlay').style.display = 'none';
  document.body.style.overflow = '';
}

// Close on overlay click
document.getElementById('kyc-modal-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeKycModal();
});

// Show on page load if limit already reached
if (KYC_LIMIT_ACTIVE) {
  document.addEventListener('DOMContentLoaded', function() {
    showKycModal();
  });
}
// Show if redirected back from a POST that hit KYC limit
if (KYC_ERROR_FROM_POST) {
  document.addEventListener('DOMContentLoaded', function() {
    showKycModal();
  });
}

// Intercept deploy button click — check KYC limit before submitting
(function() {
  var deployBtn = document.getElementById('deploy-btn');
  if (!deployBtn || !KYC_LIMIT_ACTIVE) return;

  // Disable the button and intercept form submit
  var form = deployBtn.closest('form');
  if (form) {
    form.addEventListener('submit', function(e) {
      if (KYC_LIMIT_ACTIVE) {
        e.preventDefault();
        showKycModal();
      }
    });
  }
  deployBtn.addEventListener('click', function(e) {
    if (KYC_LIMIT_ACTIVE) {
      e.preventDefault();
      showKycModal();
    }
  });
})();
</script>
</body>
</html>
