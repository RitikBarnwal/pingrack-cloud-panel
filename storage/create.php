<?php
/**
 * storage/create.php — Create a new S3 bucket
 * UI matches servers/create.php exactly — flagcdn, rcard, same structure
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = user_currency_symbol($currency);
$app_name = APP_NAME;
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();
$error    = '';

// Check MinIO configured
$minio_ok = storage_is_configured();

// Load active plans
try {
    $plans = db()->query("SELECT * FROM storage_plans WHERE is_active=1 ORDER BY sort_order,id")->fetchAll() ?: [];
} catch (Throwable $e) { $plans = []; }

// ── POST handler ─────────────────────────────────────────────
if ($minio_ok && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $bucket_name = strtolower(trim($_POST['bucket_name'] ?? ''));
    $plan_id     = (int)($_POST['plan_id'] ?? 0);
    $region      = trim($_POST['region'] ?? 'ap-south-1');

    if (!$bucket_name)
        $error = 'Bucket name is required.';
    elseif (!storage_valid_bucket_name($bucket_name))
        $error = 'Invalid name. 3–63 lowercase letters, numbers, hyphens. Must start and end with a letter or number.';
    elseif (!$plan_id)
        $error = 'Select a storage plan.';
    else {
        $ex = db()->prepare('SELECT id FROM storage_buckets WHERE name=? AND deleted_at IS NULL LIMIT 1');
        $ex->execute([$bucket_name]);
        if ($ex->fetchColumn()) $error = 'Bucket name already taken — choose a different name.';
    }
    if (!$error) {
        $plan = null;
        foreach ($plans as $p) { if ((int)$p['id'] === $plan_id) { $plan = $p; break; } }
        if (!$plan) $error = 'Invalid plan selected.';
    }
    if (!$error) {
        $price_hr  = $currency === 'INR'
            ? round((float)$plan['price_inr'] / 730, 8)
            : round((float)$plan['price_usd'] / 730, 8);
        $min_bal = $price_hr * 5;
        if ($min_bal > 0 && (float)$user['wallet_balance'] < $min_bal) {
            $error = 'Add minimum 5 hours billing balance (' . $curr_sym . number_format($min_bal, 2) . ') to create this bucket.';
        }
    }
    if (!$error) {
        try {
            $result = storage_create_bucket($uid, $plan_id, $bucket_name, $region, $currency);

$price_hr = $currency === 'INR'
    ? round((float)$plan['price_inr'] / 730, 8)
    : round((float)$plan['price_usd'] / 730, 8);

$charge = $price_hr * 5;

db()->prepare("
    UPDATE users
    SET wallet_balance = wallet_balance - ?
    WHERE id = ?
")->execute([$charge, $uid]);

header('Location: ' . BASE_URL . '/storage/credentials.php?id=' . $result['id'] . '&new=1');
exit;
        } catch (Throwable $e) {
            $error = 'Could not create bucket: ' . $e->getMessage();
        }
    }
}

// Regions from DB (admin-managed)
$db_regions  = storage_get_regions();
$sel_region  = $_POST['region'] ?? ($db_regions[0]['slug'] ?? '');
$sel_plan_id = (int)($_POST['plan_id'] ?? ($plans[0]['id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Create Bucket — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── Page layout — mirrors servers/create.php ─────────── */
    .cv-wrap{max-width:860px;margin:0 auto}

    /* Step sections */
    .cv-sec{background:white;border:1.5px solid var(--border);border-radius:13px;padding:20px;margin-bottom:14px}
    .cv-sec-hd{display:flex;align-items:center;gap:10px;margin-bottom:16px}
    .cv-num{width:26px;height:26px;border-radius:7px;background:var(--primary);color:white;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .cv-sec-title{font-size:14.5px;font-weight:800;color:var(--gray-900)}
    .cv-sec-badge{margin-left:auto;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;background:#ede9fe;color:#6d28d9}

    /* Region cards — exact same as servers/create.php */
    .rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:8px}
    .rcard{border:1.5px solid var(--border);border-radius:12px;padding:12px 13px;cursor:pointer;transition:all .17s;background:white;display:flex;align-items:center;gap:11px;user-select:none;position:relative;overflow:hidden}
    .rcard:hover{border-color:#a5b4fc;box-shadow:0 2px 10px rgba(79,70,229,.08)}
    .rcard.sel{border-color:#4f46e5;background:#f5f3ff;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
    .rflag{width:28px;height:21px;border-radius:4px;overflow:hidden;flex-shrink:0;box-shadow:0 0 0 1px rgba(0,0,0,.08)}
    .rflag img{width:100%;height:100%;object-fit:cover;display:block}
    .rcountry{font-size:12.5px;font-weight:700;color:var(--gray-900);line-height:1.2}
    .rcard.sel .rcountry{color:#4338ca}
    .rcity{font-size:10.5px;color:var(--gray-400);margin-top:2px;font-weight:500}
    .rcard.sel .rcity{color:#6366f1}

    /* Plan table — mirrors .ptbl */
    .ptbl{width:100%;border-collapse:collapse}
    .ptbl thead th{background:var(--gray-50);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-500);padding:9px 14px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
    .ptbl thead th.ar{text-align:right}
    .ptbl tbody tr{border-bottom:1px solid var(--gray-100);cursor:pointer;transition:background .12s}
    .ptbl tbody tr:last-child{border:none}
    .ptbl tbody tr:hover{background:var(--gray-50)}
    .ptbl tbody tr.sel{background:#f5f3ff}
    .ptbl td{padding:10px 14px;font-size:13px;color:var(--gray-700);vertical-align:middle}
    .ptbl td.ar{text-align:right;font-family:var(--mono);font-weight:700;color:var(--gray-900);white-space:nowrap}
    .plan-radio{width:16px;height:16px;accent-color:var(--primary);cursor:pointer}
    .plan-name-bold{font-weight:700;color:var(--gray-900)}
    .spec-cell{font-family:var(--mono);font-size:12px;color:var(--gray-600)}

    /* Bucket name input */
    .name-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .big-input{padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-family:var(--mono);font-size:13.5px;color:var(--gray-900);outline:none;transition:all .14s;width:100%}
    .big-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .big-input.ok{border-color:var(--success)}
    .big-input.bad{border-color:var(--danger)}
    .ep-preview{padding:10px 13px;background:var(--gray-50);border:1.5px solid var(--border);border-radius:9px;font-family:var(--mono);font-size:12px;color:var(--gray-500);word-break:break-all;line-height:1.6}
    .ep-name{color:var(--primary);font-weight:700}

    /* Billing info bar */
    .billing-bar{background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px 14px;font-size:12.5px;color:#1d4ed8;display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}

    /* Bottom action row */
    .action-row{display:flex;align-items:center;gap:10px;margin-top:4px}

    /* Error */
    .err-banner{background:var(--danger-bg);border:1px solid #fca5a5;border-radius:9px;padding:11px 14px;font-size:13px;color:var(--danger);font-weight:600;display:flex;gap:8px;align-items:flex-start;margin-bottom:16px}
    .err-banner svg{width:15px;height:15px;flex-shrink:0;margin-top:1px}

    /* btn-deploy */
    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:11px 24px;border-radius:9px;font-size:14px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:var(--font);text-decoration:none;transition:all .15s;box-shadow:0 2px 8px rgba(103,61,230,.22)}
    .btn-deploy:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-deploy svg{width:14px;height:14px}

    @media(max-width:640px){.name-row,.rgrid{grid-template-columns:1fr!important}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">Create Bucket</span>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <a href="<?= BASE_URL ?>/storage.php" style="color:var(--gray-400);text-decoration:none;font-size:13px;font-weight:500">← Object Storage</a>
      <span style="color:var(--gray-300);margin:0 8px">/</span>
      <span class="topbar-title">Create Bucket</span>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . $balance ?>
        </a>
      </div>
    </div>

    <div style="padding:24px">
      <div class="cv-wrap">

        <?php if ($error): ?>
        <div class="err-banner">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if (!$minio_ok): ?>
        <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:11px;padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div>
            <div style="font-size:13.5px;font-weight:800;color:#c2410c;margin-bottom:4px">Storage not configured</div>
            <div style="font-size:13px;color:#9a3412;line-height:1.6">MinIO credentials not set. Ask your admin to configure them in <strong>Admin → Settings → Storage</strong>.</div>
          </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="create-form">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="region"  id="region_val"  value="<?= htmlspecialchars($sel_region) ?>">
          <input type="hidden" name="plan_id" id="plan_id_val" value="<?= $sel_plan_id ?>">

          <!-- ── Step 1: Bucket Name ──────────────────────── -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">1</div>
              <div class="cv-sec-title">Bucket Name</div>
              <div class="cv-sec-badge" id="name-badge">Enter a name</div>
            </div>
            <div class="name-row">
              <div>
                <input name="bucket_name" id="bucket_name" class="big-input" autocomplete="off"
                       placeholder="my-bucket-name"
                       value="<?= htmlspecialchars($_POST['bucket_name'] ?? '') ?>"
                       oninput="onNameInput(this)" required autofocus>
                <div class="field-hint hint-info" id="name-hint">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                  3–63 chars · lowercase · letters, numbers, hyphens
                </div>
              </div>
              <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin-bottom:6px">S3 Endpoint Preview</div>
                <div class="ep-preview" id="ep-base-disp"><?= !empty($db_regions) ? htmlspecialchars(rtrim($db_regions[0]['s3_public_endpoint'],'/')) : (BASE_URL.'/s3') ?>/<span class="ep-name" id="ep-name"><?= htmlspecialchars($_POST['bucket_name'] ?? 'your-bucket-name') ?></span></div>
                <div class="field-hint hint-info" style="margin-top:5px">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                  Use with boto3, aws-cli, rclone
                </div>
              </div>
            </div>
          </div>

          <!-- ── Step 2: Region ───────────────────────────── -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">2</div>
              <div class="cv-sec-title">Location</div>
              <div class="cv-sec-badge" id="region-badge">
                <?php
                  $sr = null;
                  foreach ($db_regions as $_r) if ($_r['slug']===$sel_region) { $sr=$_r; break; }
                  echo $sr ? htmlspecialchars($sr['country'] . ' — ' . $sr['city']) : 'Choose a location';
                ?>
              </div>
            </div>
            <?php if (empty($db_regions)): ?>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:9px;padding:14px 16px;font-size:13px;color:#c2410c;display:flex;gap:9px;align-items:flex-start">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              No storage regions configured yet. Ask your admin to add a region in <strong>Admin → Storage → Regions</strong>.
            </div>
            <?php else: ?>
            <div class="rgrid">
              <?php foreach ($db_regions as $r): ?>
              <div class="rcard <?= $r['slug']===$sel_region?'sel':'' ?>"
                   onclick="selRegion('<?= htmlspecialchars($r['slug'],ENT_QUOTES) ?>', '<?= addslashes($r['country']) ?>', '<?= addslashes($r['city']) ?>', this)">
                <div class="rflag">
                  <img src="https://flagcdn.com/w40/<?= htmlspecialchars($r['flag_code']) ?>.png"
                       srcset="https://flagcdn.com/w80/<?= htmlspecialchars($r['flag_code']) ?>.png 2x"
                       alt="" onerror="this.style.display='none'">
                </div>
                <div>
                  <div class="rcountry"><?= htmlspecialchars($r['country']) ?></div>
                  <div class="rcity"><?= htmlspecialchars($r['city']) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>

          <!-- ── Step 3: Plan ─────────────────────────────── -->
          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">3</div>
              <div class="cv-sec-title">Storage Plan</div>
              <div class="cv-sec-badge" id="plan-badge">
                <?php
                  $sp = null;
                  foreach ($plans as $p) if ((int)$p['id']===$sel_plan_id) {$sp=$p;break;}
                  echo $sp ? htmlspecialchars($sp['name']) : 'Select a plan';
                ?>
              </div>
            </div>

            <?php if (empty($plans)): ?>
            <div style="text-align:center;padding:24px;color:var(--gray-400);font-size:13px">No plans available. Contact admin.</div>
            <?php else: ?>
            <div style="overflow-x:auto">
              <table class="ptbl">
                <thead>
                  <tr>
                    <th style="width:34px"></th>
                    <th>Plan</th>
                    <th>Storage</th>
                    <th>Bandwidth</th>
                    <th class="ar"><?= $currency === 'INR' ? 'Price (₹/mo)' : 'Price ($/mo)' ?></th>
                    <th class="ar">Per Hour</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($plans as $p):
                    $price_mo = $currency === 'INR' ? (float)$p['price_inr'] : (float)$p['price_usd'];
                    $price_hr = round($price_mo / 730, 4);
                    $issel    = (int)$p['id'] === $sel_plan_id;
                  ?>
                  <tr class="<?= $issel ? 'sel' : '' ?>" onclick="selPlan(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', this)">
                    <td><input type="radio" class="plan-radio" name="_plan" value="<?= $p['id'] ?>" <?= $issel?'checked':'' ?>></td>
                    <td><span class="plan-name-bold"><?= htmlspecialchars($p['name']) ?></span></td>
                    <td class="spec-cell"><?= number_format($p['storage_gb']) ?> GB</td>
                    <td class="spec-cell"><?= $p['bandwidth_gb'] >= 1000 ? round($p['bandwidth_gb']/1000,1).' TB' : number_format($p['bandwidth_gb']).' GB' ?> /mo</td>
                    <td class="ar"><?= $currency==='INR' ? '₹'.number_format($price_mo,0) : '$'.number_format($price_mo,2) ?></td>
                    <td class="ar" style="color:var(--gray-500)"><?= $curr_sym . number_format($price_hr, 4) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

            <!-- Billing info bar -->
            <div class="billing-bar" id="billing-bar">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              <?php if ($sp): $pm0=$currency==='INR'?(float)$sp['price_inr']:(float)$sp['price_usd']; $ph0=round($pm0/730,4); ?>
              Billed <strong><?= $curr_sym . $ph0 ?>/hr</strong>
              &nbsp;·&nbsp; Min 5h required: <strong><?= $curr_sym . number_format($ph0*5, 2) ?></strong>
              &nbsp;·&nbsp; Your balance: <strong><?= $curr_sym . $balance ?></strong>
              <?php else: ?>Select a plan to see billing info<?php endif; ?>
            </div>
          </div>

          <!-- ── Action row ───────────────────────────────── -->
          <div class="action-row">
            <button type="submit" class="btn-deploy" <?= !$minio_ok || empty($plans) ? 'disabled style="opacity:.5;cursor:not-allowed"' : '' ?>>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg>
              Create Bucket
            </button>
            <a href="<?= BASE_URL ?>/storage.php" class="btn btn-secondary">Cancel</a>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>

<script>
var PLANS     = <?= json_encode(array_map(fn($p) => ['id'=>(int)$p['id'],'name'=>$p['name'],'price_inr'=>$p['price_inr'],'price_usd'=>$p['price_usd']], $plans)) ?>;
var CURRENCY  = '<?= $currency ?>';
var SYM       = '<?= addslashes($curr_sym) ?>';
var BALANCE   = <?= (float)$user['wallet_balance'] ?>;
var EP_BASE   = '<?= addslashes(rtrim(!empty($db_regions) ? $db_regions[0]["s3_public_endpoint"] : BASE_URL."/s3", "/")) ?>';

function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); }

var REGIONS_EP = <?= json_encode(array_column(array_map(fn($r)=>['slug'=>$r['slug'],'ep'=>rtrim($r['s3_public_endpoint'],'/')], $db_regions), 'ep', 'slug')) ?>;

function selRegion(slug, country, city, el) {
    document.querySelectorAll('.rcard').forEach(function(c){ c.classList.remove('sel'); });
    el.classList.add('sel');
    document.getElementById('region_val').value = slug;
    document.getElementById('region-badge').textContent = country + ' — ' + city;
    // Update endpoint preview base
    var ep = REGIONS_EP[slug] || EP_BASE;
    var nameEl = document.getElementById('ep-name');
    var dispEl = document.getElementById('ep-base-disp');
    if (dispEl) {
        var name = nameEl ? nameEl.textContent : 'your-bucket-name';
        dispEl.innerHTML = ep + '/<span class="ep-name" id="ep-name">' + name + '</span>';
    }
    EP_BASE = ep;
}

function selPlan(id, name, rowEl) {
    document.querySelectorAll('.ptbl tbody tr').forEach(function(r){ r.classList.remove('sel'); });
    rowEl.classList.add('sel');
    rowEl.querySelector('input[type=radio]').checked = true;
    document.getElementById('plan_id_val').value = id;
    document.getElementById('plan-badge').textContent = name;

    var p = PLANS.find(function(x){ return x.id == id; });
    if (p) {
        var pm  = CURRENCY === 'INR' ? p.price_inr : p.price_usd;
        var ph  = (pm / 730).toFixed(4);
        var min5 = (ph * 5).toFixed(2);
        document.getElementById('billing-bar').innerHTML =
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> ' +
            'Billed <strong>' + SYM + ph + '/hr</strong>' +
            ' &nbsp;·&nbsp; Min 5h: <strong>' + SYM + min5 + '</strong>' +
            ' &nbsp;·&nbsp; Balance: <strong>' + SYM + parseFloat(BALANCE).toFixed(2) + '</strong>';
    }
}

function onNameInput(inp) {
    var v = inp.value.trim();
    var ok = /^[a-z0-9][a-z0-9\-]{1,61}[a-z0-9]$/.test(v);
    inp.classList.toggle('ok',  v.length >= 3 &&  ok);
    inp.classList.toggle('bad', v.length >= 3 && !ok);
    document.getElementById('ep-name').textContent = v || 'your-bucket-name';
    document.getElementById('name-badge').textContent = ok ? v : (v.length ? 'Invalid name' : 'Enter a name');
    var hint = document.getElementById('name-hint');
    if (v.length >= 3) {
        hint.className = 'field-hint ' + (ok ? 'hint-ok' : 'hint-err');
        hint.innerHTML = ok
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Looks good!'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Lowercase letters, numbers, hyphens only';
    } else {
        hint.className = 'field-hint hint-info';
        hint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg> 3–63 chars · lowercase · letters, numbers, hyphens';
    }
}

// Init name validation if value pre-filled (error redirect)
(function(){ var inp = document.getElementById('bucket_name'); if (inp.value) onNameInput(inp); })();
document.getElementById('create-form').addEventListener('submit', function () {
    const btn = this.querySelector('.btn-deploy');

    btn.disabled = true;

    btn.innerHTML = `
        <span class="spinner"></span>
        Creating...
    `;
});
</script>
</body>
</html>
