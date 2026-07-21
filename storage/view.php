<?php
/**
 * storage/view.php — Bucket management
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$sym      = user_currency_symbol($currency);
$curr_sym = $sym;
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = number_format((float)$user['wallet_balance'],2);
$csrf     = csrf_token();

$bid    = (int)($_GET['id'] ?? 0);
$bucket = storage_get_bucket($bid, $uid);
if (!$bucket) { header('Location: ' . BASE_URL . '/storage.php'); exit; }

$is_new = !empty($_GET['new']);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';

    if ($act === 'rotate_keys') {
        $keys = storage_gen_keys();
        db()->prepare('UPDATE storage_buckets SET access_key=?, secret_key=? WHERE id=?')->execute([$keys['access_key'],$keys['secret_key'],$bid]);
        db()->prepare('UPDATE storage_api_keys SET access_key=?, secret_key=? WHERE bucket_id=? AND label=?')->execute([$keys['access_key'],$keys['secret_key'],$bid,'Default']);
        $msg = 'Access keys rotated.';
        $bucket = storage_get_bucket($bid, $uid);
    }
    if ($act === 'add_key') {
        $label = trim($_POST['key_label'] ?? 'Key '.date('Ymd'));
        $perms = implode(',', array_filter(['read','write','delete'], fn($p)=>isset($_POST['perm_'.$p]))) ?: 'read';
        $keys  = storage_gen_keys();
        db()->prepare('INSERT INTO storage_api_keys (bucket_id,label,access_key,secret_key,permissions) VALUES (?,?,?,?,?)')->execute([$bid,$label,$keys['access_key'],$keys['secret_key'],$perms]);
        $msg = 'API key created.';
    }
    if ($act === 'revoke_key') {
        db()->prepare('DELETE FROM storage_api_keys WHERE id=? AND bucket_id=?')->execute([(int)$_POST['key_id'],$bid]);
        $msg = 'Key revoked.';
    }
    if ($act === 'delete_bucket') {
        if (trim($_POST['confirm_name'] ?? '') !== $bucket['name']) { $err = 'Bucket name mismatch.'; }
        else { db()->prepare("UPDATE storage_buckets SET status='deleting',deleted_at=NOW() WHERE id=?")->execute([$bid]); header('Location:'.BASE_URL.'/storage.php?deleted=1'); exit; }
    }
}

$api_keys = db()->prepare('SELECT * FROM storage_api_keys WHERE bucket_id=? ORDER BY created_at DESC');
$api_keys->execute([$bid]);
$api_keys = $api_keys->fetchAll() ?: [];

$billing_hist = db()->prepare('SELECT * FROM storage_billing WHERE bucket_id=? ORDER BY billed_at DESC LIMIT 8');
$billing_hist->execute([$bid]);
$billing_hist = $billing_hist->fetchAll() ?: [];

$endpoint = storage_endpoint($bucket['name'], $bucket['region']);
$pct = storage_pct((float)$bucket['used_gb'], (int)$bucket['plan_gb']);
$is_suspended = $bucket['status'] === 'suspended';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars($bucket['name']) ?> — Storage</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .detail-grid{display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:14px}
    .card-head{padding:12px 18px;border-bottom:1px solid var(--gray-100);background:#fafbfd;display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:18px}
    .flbl{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin-bottom:5px}
    .key-wrap{display:flex;align-items:center;gap:8px;margin-bottom:8px}
    .key-box{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--gray-700);background:var(--gray-50);padding:7px 10px;border-radius:7px;border:1px solid var(--border);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .copy-btn{padding:5px 11px;background:white;border:1.5px solid var(--border);border-radius:7px;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .13s;white-space:nowrap;flex-shrink:0}
    .copy-btn:hover{background:var(--gray-50)}
    .usage-bar{height:6px;background:var(--gray-100);border-radius:99px;overflow:hidden;margin:8px 0}
    .uf{height:100%;border-radius:99px;background:linear-gradient(90deg,#0ea5e9,#0284c7)}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th{padding:8px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-400);border-bottom:1px solid var(--gray-100);text-align:left}
    .tbl td{padding:9px 12px;border-bottom:1px solid var(--gray-50);font-size:12.5px;vertical-align:middle}
    .tbl tr:last-child td{border:none}
    .ptag{display:inline-block;padding:2px 6px;border-radius:5px;font-size:10.5px;font-weight:700;margin-right:3px}
    .pr{background:#eff6ff;color:#2563eb}.pw{background:#f0fdf4;color:#16a34a}.pd{background:#fef2f2;color:#dc2626}
    .code-block{background:#0d1117;border-radius:9px;padding:14px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.9;color:#3fb950;overflow-x:auto;margin-top:12px}
    .act-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:7px;font-size:12px;font-weight:700;border:1.5px solid;cursor:pointer;font-family:inherit;transition:all .13s;text-decoration:none}
    .ab-edit{border-color:#bfdbfe;color:#2563eb;background:#eff6ff}.ab-del{border-color:#fca5a5;color:#dc2626;background:#fef2f2}
    @media(max-width:900px){.detail-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="app-shell">

  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="main-content">
    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($bucket['name']) ?></span>
    </div>
    <!-- Topbar -->
    <div class="topbar">
      <a href="<?= BASE_URL ?>/storage.php" style="color:var(--gray-400);text-decoration:none;font-size:13px">← Storage</a>
      <span style="color:var(--gray-300);margin:0 7px">/</span>
      <span class="topbar-title" style="font-family:'JetBrains Mono',monospace;font-size:13.5px"><?= htmlspecialchars($bucket['name']) ?></span>
      <?php if ($is_new): ?>
      <span style="background:#f0fdf4;color:#16a34a;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;margin-left:8px">✓ Created</span>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <a href="<?= BASE_URL ?>/storage/credentials.php?id=<?= $bid ?>" style="padding:7px 13px;background:#faf5ff;border:1.5px solid #e9d5ff;border-radius:8px;font-size:13px;font-weight:700;color:#7c3aed;text-decoration:none;transition:all .13s;display:inline-flex;align-items:center;gap:6px" onmouseover="this.style.background='#f3e8ff'" onmouseout="this.style.background='#faf5ff'">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          S3 Credentials
        </a>
        <a href="<?= BASE_URL ?>/storage/browser.php?id=<?= $bid ?>" style="padding:7px 13px;background:white;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:700;color:var(--gray-700);text-decoration:none;transition:all .13s;display:inline-flex;align-items:center;gap:6px" onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background='white'">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          Browse Files
        </a>
      </div>
    </div>

    <div class="page-body">

      <?php if ($msg): ?>
      <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:10px 15px;margin-bottom:14px;font-size:13px;font-weight:700;color:#15803d">✓ <?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:10px 15px;margin-bottom:14px;font-size:13px;font-weight:700;color:#dc2626">✗ <?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <?php if ($is_suspended): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:11px;padding:13px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <div style="font-size:13.5px;font-weight:800;color:#991b1b">⚠ Bucket Suspended — Insufficient Balance</div>
          <div style="font-size:12px;color:#9a3412;margin-top:2px">Add funds to reactivate. Keys are still valid.</div>
        </div>
        <a href="<?= BASE_URL ?>/billing.php?action=topup" style="padding:8px 16px;background:#dc2626;color:white;border-radius:8px;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap">Add Funds →</a>
      </div>
      <?php endif; ?>

      <div class="detail-grid">
        <div>
          <!-- Usage overview -->
          <div class="card">
            <div class="card-head">
              <svg enable-background="new 0 0 72 72" style="margin-top: 5px;width:25px" version="1.1" viewBox="0 0 72 72" xml:space="preserve" xmlns="http://www.w3.org/2000/svg"><path d="m40 1.5c-16 0-29 3.6-29 8v0.4l0.8 7c0.8-0.7 1.4-1.1 1.5-1.2-2 1.6-14 11.6-11.2 26.6 0.9 4.8 3.6 8 7.7 9 0.8 0.2 1.7 0.3 2.6 0.3 1 0 2-0.1 3.1-0.3l-0.5-4.9c-1.5 0.3-3 0.4-4.3 0.1-2.3-0.5-3.6-2.2-4.2-5.2-1.5-8 2.6-14.5 5.9-18.2l2.6 23.4c7.1-1.7 16-9.5 22-17.9-0.2-0.4-0.2-0.9-0.2-1.4 0-2.3 1.7-4.1 3.7-4.1s3.7 1.8 3.7 4.1c0 2.1-1.5 3.9-3.4 4.1-7.5 10.7-17.3 18.4-25.3 20.1l0.4 3.4v0.4c0.7 4.1 11 7.4 23.7 7.4 13.1 0 23.7-3.5 23.7-7.8l5.6-44.6c0-0.2 0.1-0.4 0.1-0.5 0-4.6-13-8.2-29-8.2zm0 13.8c-12.1 0-21.8-2.1-21.8-4.7s9.8-4.7 21.8-4.7c12.1 0 21.8 2.1 21.8 4.7 0 2.5-9.8 4.7-21.8 4.7z" fill="#C3C8CD"></path></svg> <?= htmlspecialchars($bucket['name']) ?>
              <span style="margin-left:auto;font-size:11.5px;color:var(--gray-400);font-weight:500"><?= $bucket['plan_name'] ?> · <?= $bucket['region'] ?></span>
            </div>
            <div class="card-body">
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
                <div>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray-400);margin-bottom:4px">Storage Used</div>
                  <div style="font-size:20px;font-weight:900;color:var(--gray-900)"><?= number_format((float)$bucket['used_gb'],2) ?> <span style="font-size:12px;color:var(--gray-400)">/ <?= $bucket['plan_gb'] ?> GB</span></div>
                  <div class="usage-bar"><div class="uf" style="width:<?= $pct ?>%"></div></div>
                  <div style="font-size:11.5px;color:var(--gray-400)"><?= $pct ?>% used</div>
                </div>
                <div>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray-400);margin-bottom:4px">Bandwidth</div>
                  <div style="font-size:20px;font-weight:900;color:var(--gray-900)"><?= $bucket['plan_bw'] ?? $bucket['bandwidth_gb'] ?> GB<span style="font-size:11px;color:var(--gray-400)">/mo</span></div>
                </div>
                <div>
                  <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray-400);margin-bottom:4px">Monthly Cost</div>
                  <div style="font-size:20px;font-weight:900;color:var(--primary)"><?= $sym ?><?= number_format($bucket['price_monthly'],$currency==='INR'?0:2) ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Connection details -->
          <div class="card">
            <div class="card-head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Connection Details
            </div>
            <div class="card-body">
              <div class="flbl" style="margin-bottom:6px">Endpoint URL</div>
              <div class="key-wrap">
                <div class="key-box"><?= htmlspecialchars($endpoint) ?></div>
                <button class="copy-btn" onclick="copyText('<?= addslashes($endpoint) ?>',this)">Copy</button>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
                <div>
                  <div class="flbl" style="margin-bottom:5px">Access Key</div>
                  <div class="key-wrap">
                    <div class="key-box"><?= htmlspecialchars($bucket['access_key']) ?></div>
                    <button class="copy-btn" onclick="copyText('<?= addslashes($bucket['access_key']) ?>',this)">Copy</button>
                  </div>
                </div>
                <div>
                  <div class="flbl" style="margin-bottom:5px">Secret Key</div>
                  <div class="key-wrap">
                    <div class="key-box" id="secret-val" style="filter:blur(4px);cursor:pointer" onclick="revealSecret()" title="Click to reveal">••••••••••••••••</div>
                    <button class="copy-btn" onclick="copyText('<?= addslashes($bucket['secret_key']) ?>',this)">Copy</button>
                  </div>
                </div>
              </div>
              <div style="background:#fef9c3;border:1px solid #fde047;border-radius:7px;padding:8px 11px;font-size:11.5px;color:#78350f;margin:12px 0">⚠ Keep your secret key private. Never expose it in client-side code.</div>
              <div class="code-block"><span style="color:#8b949e"># Python boto3</span>
<br>s3 = boto3.client(
<br>&nbsp;&nbsp;endpoint_url='<?= htmlspecialchars($endpoint) ?>',
<br>&nbsp;&nbsp;aws_access_key_id='<?= htmlspecialchars($bucket['access_key']) ?>',
<br>&nbsp;&nbsp;aws_secret_access_key='YOUR_SECRET_KEY'
<br>)</div>
              <form method="POST" style="margin-top:14px" onsubmit="return confirm('Rotate keys? Existing connections will stop working.')">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="rotate_keys">
                <button type="submit" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:white;border:1.5px solid #fca5a5;border-radius:7px;font-size:12.5px;font-weight:700;font-family:inherit;cursor:pointer;color:#dc2626;transition:all .13s" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                  Rotate Keys
                </button>
              </form>
            </div>
          </div>

          <!-- API Keys -->
          <div class="card">
            <div class="card-head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
              API Keys
              <button onclick="document.getElementById('add-key-modal').style.display='flex'" style="margin-left:auto;padding:4px 11px;background:var(--primary);color:white;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer">+ Add</button>
            </div>
            <?php if (empty($api_keys)): ?>
            <div style="padding:18px;text-align:center;font-size:12.5px;color:var(--gray-400)">Default key in Connection Details above.</div>
            <?php else: ?>
            <div style="overflow-x:auto"><table class="tbl">
              <thead><tr><th>Label</th><th>Key</th><th>Permissions</th><th>Created</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($api_keys as $k): ?>
              <tr>
                <td style="font-weight:700"><?= htmlspecialchars($k['label']) ?></td>
                <td style="font-family:'JetBrains Mono',monospace;font-size:11.5px"><?= htmlspecialchars(substr($k['access_key'],0,12)).'...' ?></td>
                <td><?php foreach(explode(',',$k['permissions']) as $perm): ?><span class="ptag p<?= $perm[0] ?>"><?= $perm ?></span><?php endforeach; ?></td>
                <td style="color:var(--gray-400);font-size:12px"><?= date('d M Y',strtotime($k['created_at'])) ?></td>
                <td><?php if($k['label']!=='Default'): ?><form method="POST" style="display:inline" onsubmit="return confirm('Revoke?')"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="revoke_key"><input type="hidden" name="key_id" value="<?= $k['id'] ?>"><button type="submit" class="act-btn ab-del" style="padding:3px 9px;font-size:11px">Revoke</button></form><?php endif; ?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right column -->
        <div>
          <div class="card">
            <div class="card-head">Recent Billing</div>
            <?php if (empty($billing_hist)): ?>
            <div style="padding:14px 16px;font-size:12px;color:var(--gray-400)">No records yet.</div>
            <?php else: foreach ($billing_hist as $bh): $bs=$bh['currency']==='INR'?'₹':'$'; ?>
            <div style="display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--gray-50);font-size:12px">
              <span style="color:var(--gray-500)"><?= date('d M, H:i',strtotime($bh['billed_at'])) ?></span>
              <span style="font-family:'JetBrains Mono',monospace;font-weight:700;color:<?= $bh['status']==='success'?'#dc2626':'var(--gray-400)' ?>"><?= $bs.number_format($bh['amount'],6) ?></span>
            </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- Danger zone -->
          <div class="card" style="border-color:#fca5a5">
            <div class="card-head" style="background:#fef2f2;color:#dc2626">Danger Zone</div>
            <div class="card-body">
              <p style="font-size:12.5px;color:var(--gray-600);margin-bottom:10px;line-height:1.6">Permanently delete this bucket and all objects.</p>
              <button onclick="document.getElementById('del-modal').style.display='flex'" style="display:flex;align-items:center;gap:5px;padding:7px 14px;background:white;border:1.5px solid #fca5a5;border-radius:7px;font-size:12.5px;font-weight:700;font-family:inherit;cursor:pointer;color:#dc2626;transition:all .13s" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='white'">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                Delete Bucket
              </button>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<!-- Add key modal -->
<div id="add-key-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:13px;width:100%;max-width:400px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <h3 style="font-size:15px;font-weight:800;margin-bottom:14px">Add API Key</h3>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_key">
      <div style="margin-bottom:12px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--gray-700)">Label</label><input name="key_label" placeholder="e.g. Backend API" style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;outline:none"></div>
      <div style="margin-bottom:16px"><label style="display:block;font-size:12px;font-weight:700;margin-bottom:7px;color:var(--gray-700)">Permissions</label>
        <div style="display:flex;gap:14px"><?php foreach(['read','write','delete'] as $p): ?><label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer"><input type="checkbox" name="perm_<?= $p ?>" value="1" <?= $p!=='delete'?'checked':'' ?> style="accent-color:var(--primary)"> <?= ucfirst($p) ?></label><?php endforeach; ?></div>
      </div>
      <div style="display:flex;gap:8px"><button type="submit" class="btn-deploy" style="flex:1">Create</button><button type="button" onclick="document.getElementById('add-key-modal').style.display='none'" style="padding:10px 16px;background:white;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer">Cancel</button></div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div id="del-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center;padding:20px" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:13px;width:100%;max-width:420px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <h3 style="font-size:15px;font-weight:800;color:#dc2626;margin-bottom:8px">Delete Bucket</h3>
    <p style="font-size:13px;color:var(--gray-600);margin-bottom:14px;line-height:1.6">Type <strong><?= htmlspecialchars($bucket['name']) ?></strong> to confirm permanent deletion:</p>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_bucket">
      <input name="confirm_name" placeholder="<?= htmlspecialchars($bucket['name']) ?>" style="width:100%;padding:8px 11px;border:1.5px solid #fca5a5;border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:13px;outline:none;margin-bottom:12px">
      <div style="display:flex;gap:8px"><button type="submit" style="flex:1;padding:10px;background:#dc2626;color:white;border:none;border-radius:8px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer">Delete</button><button type="button" onclick="document.getElementById('del-modal').style.display='none'" style="padding:10px 16px;background:white;border:1.5px solid var(--border);border-radius:8px;font-size:13.5px;font-weight:600;font-family:inherit;cursor:pointer">Cancel</button></div>
    </form>
  </div>
</div>

<script>
var SECRET='<?= addslashes($bucket['secret_key']) ?>';
function revealSecret(){var e=document.getElementById('secret-val');if(e.style.filter){e.style.filter='';e.textContent=SECRET;}else{e.style.filter='blur(4px)';e.textContent='••••••••••••••••';}}
function copyText(txt,btn){navigator.clipboard.writeText(txt).then(function(){var o=btn.textContent;btn.textContent='✓';btn.style.color='#16a34a';setTimeout(function(){btn.textContent=o;btn.style.color='';},2000);});}
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
</script>
</body>
</html>
