<?php
/**
 * storage/credentials.php
 *
 * Shows all S3 credentials for a bucket — exactly what user needs
 * to connect from boto3, aws-cli, rclone, etc.
 *
 * URL: /storage/credentials.php?id=BUCKET_ID
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/storage.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = user_currency_symbol($currency);
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'
            ?($user['company_name']?:$user['username'])
            :($user['full_name']?:$user['username']));
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();

$bid    = (int)($_GET['id'] ?? 0);
$bucket = storage_get_bucket($bid, $uid);
if (!$bucket) { header('Location: ' . BASE_URL . '/storage.php'); exit; }

$endpoint   = storage_endpoint($bucket['name'], $bucket['region']);
$public_ep = $endpoint;

// Fetch all API keys for this bucket
$api_keys_st = db()->prepare(
    'SELECT * FROM storage_api_keys WHERE bucket_id=? AND is_active=1 ORDER BY created_at ASC'
);
$api_keys_st->execute([$bid]);
$api_keys = $api_keys_st->fetchAll() ?: [];

// Default key = bucket's own key (always exists)
$default_key = [
    'label'      => 'Default',
    'access_key' => $bucket['access_key'],
    'secret_key' => $bucket['secret_key'],
    'permissions'=> 'read,write,delete',
];

$msg = '';
// Handle new key creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $act = $_POST['action'] ?? '';
    if ($act === 'create_key') {
        $label = trim($_POST['label'] ?? 'Key ' . date('d M Y'));
        $perms = implode(',', array_filter(['read','write','delete'], fn($p) => isset($_POST['perm_'.$p])));
        if (!$perms) $perms = 'read';

        // Generate new key pair
        $new_access = strtoupper(bin2hex(random_bytes(10)));
        $new_secret = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');

        try {
            // On MinIO: create service account with limited permissions
            if (storage_is_configured()) {
                $minio = storage_minio();
                // Create MinIO service account for this bucket
                $minio_keys = $minio->createServiceAccount($bucket['name']);
                $new_access  = $minio_keys['access_key'];
                $new_secret  = $minio_keys['secret_key'];
            }

            db()->prepare(
                'INSERT INTO storage_api_keys (bucket_id, label, access_key, secret_key, permissions)
                 VALUES (?,?,?,?,?)'
            )->execute([$bid, $label, $new_access, $new_secret, $perms]);

            $msg = 'new_key:' . $new_access . ':' . $new_secret . ':' . $label;
        } catch (Throwable $e) {
            $msg = 'error:' . $e->getMessage();
        }

        // Reload keys
        $api_keys_st->execute([$bid]);
        $api_keys = $api_keys_st->fetchAll() ?: [];
    }

    if ($act === 'revoke_key') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $key_row = db()->prepare('SELECT * FROM storage_api_keys WHERE id=? AND bucket_id=? LIMIT 1');
        $key_row->execute([$kid, $bid]);
        $kr = $key_row->fetch();
        if ($kr) {
            try {
                if (storage_is_configured()) {
                    $minio = storage_minio();
                    $minio->deleteServiceAccount($kr['access_key']);
                }
            } catch (Throwable $e) {}
            db()->prepare('DELETE FROM storage_api_keys WHERE id=?')->execute([$kid]);
        }
        $api_keys_st->execute([$bid]);
        $api_keys = $api_keys_st->fetchAll() ?: [];
    }
}

// Parse msg
$new_key_shown = null;
if (str_starts_with($msg, 'new_key:')) {
    $parts = explode(':', $msg, 4);
    $new_key_shown = ['access_key' => $parts[1], 'secret_key' => $parts[2], 'label' => $parts[3]];
}
$err_msg = str_starts_with($msg, 'error:') ? substr($msg, 6) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>S3 Credentials — <?= htmlspecialchars($bucket['name']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* Code block */
    .code-block{background:#0d1117;border-radius:10px;padding:16px 18px;font-family:'JetBrains Mono',monospace;font-size:12.5px;line-height:2;overflow-x:auto;position:relative}
    .code-line{display:flex;gap:12px;align-items:flex-start}
    .c-cmt{color:#6e7681}.c-key{color:#79c0ff}.c-val{color:#a5d6ff}.c-str{color:#a5d6ff}.c-fn{color:#d2a8ff}.c-num{color:#f2cc60}.c-op{color:#ff7b72}.c-text{color:#c9d1d9}
    .code-copy-btn{position:absolute;top:10px;right:10px;padding:4px 10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:6px;font-size:11.5px;font-weight:700;color:#8b949e;cursor:pointer;transition:all .13s;font-family:inherit}
    .code-copy-btn:hover{background:rgba(255,255,255,.14);color:white}

    /* Credential card */
    .cred-card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:16px}
    .cred-head{padding:13px 18px;border-bottom:1px solid var(--gray-100);background:#fafbfd;display:flex;align-items:center;gap:9px}
    .cred-title{font-size:13.5px;font-weight:800;color:var(--gray-900)}
    .cred-body{padding:18px}

    /* Key row */
    .krow{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .klabel{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);min-width:110px;flex-shrink:0}
    .kval{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--gray-800);background:var(--gray-50);border:1px solid var(--border);border-radius:7px;padding:7px 11px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .kval.blur-val{filter:blur(5px);cursor:pointer;user-select:none}
    .kcopy{padding:6px 13px;background:white;border:1.5px solid var(--border);border-radius:7px;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .13s;white-space:nowrap;flex-shrink:0}
    .kcopy:hover{background:var(--gray-50)}
    .keye{padding:6px 10px;background:white;border:1.5px solid var(--border);border-radius:7px;font-size:12px;cursor:pointer;transition:all .13s;flex-shrink:0;color:var(--gray-500)}
    .keye:hover{background:var(--gray-50)}

    /* New key alert */
    .new-key-box{background:#f0fdf4;border:2px solid #22c55e;border-radius:12px;padding:18px;margin-bottom:16px}
    .new-key-warning{background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:10px 13px;font-size:12.5px;color:#78350f;margin-top:12px;display:flex;gap:8px}

    /* API keys table */
    .key-table{width:100%;border-collapse:collapse}
    .key-table th{padding:8px 13px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-400);border-bottom:1px solid var(--gray-100);text-align:left}
    .key-table td{padding:10px 13px;border-bottom:1px solid var(--gray-50);font-size:13px;vertical-align:middle}
    .key-table tr:last-child td{border:none}
    .ptag{display:inline-block;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:700;margin-right:2px}
    .pr{background:#eff6ff;color:#2563eb}.pw{background:#f0fdf4;color:#16a34a}.pd{background:#fef2f2;color:#dc2626}

    /* SDK tabs */
    .sdk-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
    .sdk-tab{padding:6px 14px;border-radius:8px;font-size:12.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--border);background:white;color:var(--gray-600);transition:all .13s;font-family:inherit}
    .sdk-tab.active{background:#0f172a;border-color:#0f172a;color:white}
    .sdk-pane{display:none}.sdk-pane.active{display:block}
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
      <span style="font-weight:800;font-size:14px">S3 Credentials</span>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <a href="<?= BASE_URL ?>/storage/view.php?id=<?= $bid ?>" style="color:var(--gray-400);text-decoration:none;font-size:13px">← <?= htmlspecialchars($bucket['name']) ?></a>
      <span style="color:var(--gray-300);margin:0 7px">/</span>
      <span class="topbar-title">S3 Credentials</span>
    </div>

    <div class="page-body">

      <?php if ($err_msg): ?>
      <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 16px;margin-bottom:16px;font-size:13px;font-weight:600;color:#dc2626">✗ <?= htmlspecialchars($err_msg) ?></div>
      <?php endif; ?>

      <!-- Newly created key — show ONCE prominently -->
      <?php if ($new_key_shown): ?>
      <div class="new-key-box">
        <div style="font-size:14px;font-weight:800;color:#15803d;margin-bottom:14px">
          ✓ New API Key Created — "<?= htmlspecialchars($new_key_shown['label']) ?>"
        </div>
        <div class="krow">
          <span class="klabel">Access Key</span>
          <div class="kval"><?= htmlspecialchars($new_key_shown['access_key']) ?></div>
          <button class="kcopy" onclick="copyText('<?= addslashes($new_key_shown['access_key']) ?>', this)">Copy</button>
        </div>
        <div class="krow">
          <span class="klabel">Secret Key</span>
          <div class="kval"><?= htmlspecialchars($new_key_shown['secret_key']) ?></div>
          <button class="kcopy" onclick="copyText('<?= addslashes($new_key_shown['secret_key']) ?>', this)">Copy</button>
        </div>
        <div class="new-key-warning">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <strong>Save the Secret Key NOW.</strong> It will not be shown again after you leave this page. Store it in a password manager or your app's environment variables.
        </div>
      </div>
      <?php endif; ?>

      <!-- ═══ SECTION 1: Main Credentials ═══════════════════════ -->
      <div class="cred-card">
        <div class="cred-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <span class="cred-title">Your S3 Credentials</span>
          <span style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--gray-400)"><?= htmlspecialchars($bucket['name']) ?></span>
        </div>
        <div class="cred-body">

          <!-- Endpoint -->
          <div class="krow">
            <span class="klabel">Endpoint URL</span>
            <div class="kval" id="v-endpoint"><?= rtrim(str_replace('://.', '://', storage_endpoint('', $bucket['region'])), '/'); ?></div>
            <button class="kcopy" onclick="copyText('<?= addslashes(rtrim(str_replace('://.', '://', storage_endpoint('', $bucket['region'])), '/')) ?>', this)">Copy</button>
          </div>

          <!-- API URL -->
          <div class="krow">
            <span class="klabel">API URL / Path URL</span>
            <div class="kval" id="v-endpoint"><?= htmlspecialchars($endpoint) ?></div>
            <button class="kcopy" onclick="copyText('<?= addslashes($endpoint) ?>', this)">Copy</button>
          </div>

          <!-- Bucket Name -->
          <div class="krow">
            <span class="klabel">Bucket Name</span>
            <div class="kval" id="v-bucket"><?= htmlspecialchars($bucket['name']) ?></div>
            <button class="kcopy" onclick="copyText('<?= addslashes($bucket['name']) ?>', this)">Copy</button>
          </div>

          <!-- Region -->
          <div class="krow">
            <span class="klabel">Region</span>
            <div class="kval" id="v-region"><?= htmlspecialchars($bucket['region']) ?></div>
            <button class="kcopy" onclick="copyText('<?= addslashes($bucket['region']) ?>', this)">Copy</button>
          </div>

          <hr style="border:none;border-top:1px solid var(--gray-100);margin:14px 0">

          <!-- Access Key -->
          <div class="krow">
            <span class="klabel">Access Key</span>
            <div class="kval" id="v-access"><?= htmlspecialchars($bucket['access_key']) ?></div>
            <button class="kcopy" onclick="copyText('<?= addslashes($bucket['access_key']) ?>', this)">Copy</button>
          </div>

          <!-- Secret Key -->
          <div class="krow">
            <span class="klabel">Secret Key</span>
            <div class="kval blur-val" id="v-secret" onclick="toggleSecret(this)" title="Click to reveal">click to reveal</div>
            <button class="keye" onclick="toggleSecret(document.getElementById('v-secret'))" title="Show/Hide">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="eye-icon"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
            <button class="kcopy" onclick="copyText(SECRET_KEY, this)">Copy</button>
          </div>

          <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:9px 13px;font-size:12px;color:#78350f;margin-top:4px;display:flex;gap:8px">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Keep Secret Key private. Never put it in frontend code, git repos, or public files. Use environment variables.</span>
          </div>
        </div>
      </div>

      <!-- ═══ SECTION 2: Additional API Keys ══════════════════════ -->
      <div class="cred-card">
        <div class="cred-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
          <span class="cred-title">Additional API Keys</span>
          <span style="margin-left:auto;font-size:12px;color:var(--gray-400)">Create separate keys per app with different permissions</span>
          <button onclick="document.getElementById('create-key-modal').style.display='flex'"
                  style="margin-left:12px;padding:5px 13px;background:var(--primary);color:white;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer">
            + New Key
          </button>
        </div>

        <?php if (empty($api_keys)): ?>
        <div style="padding:18px 20px;font-size:13px;color:var(--gray-400)">
          No additional keys yet. The default credentials above are sufficient for most use cases.
          Create additional keys to separate access by app or permission level.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="key-table">
            <thead>
              <tr>
                <th>Label</th>
                <th>Access Key</th>
                <th>Permissions</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($api_keys as $k): ?>
              <tr>
                <td style="font-weight:700"><?= htmlspecialchars($k['label']) ?></td>
                <td>
                  <span style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= htmlspecialchars($k['access_key']) ?></span>
                  <button onclick="copyText('<?= addslashes($k['access_key']) ?>', this)"
                          style="margin-left:8px;padding:2px 7px;background:white;border:1px solid var(--border);border-radius:5px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit">Copy</button>
                </td>
                <td>
                  <?php foreach (explode(',', $k['permissions']) as $p): ?>
                  <span class="ptag p<?= $p[0] ?>"><?= htmlspecialchars($p) ?></span>
                  <?php endforeach; ?>
                </td>
                <td style="color:var(--gray-400);font-size:12px"><?= date('d M Y', strtotime($k['created_at'])) ?></td>
                <td>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Revoke this key? Apps using it will lose access.')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="revoke_key">
                    <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
                    <button class="btn btn-danger btn-sm" data-loading="Revoking..." type="submit" style="padding:4px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:12px;font-weight:700;color:#dc2626;cursor:pointer;font-family:inherit">Revoke</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- ═══ SECTION 3: SDK Code Snippets ════════════════════ -->
      <div class="cred-card">
        <div class="cred-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0ea5e9" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          <span class="cred-title">Connect Your App</span>
          <span style="margin-left:auto;font-size:12px;color:var(--gray-400)">Copy-paste code — works immediately</span>
        </div>
        <div class="cred-body">

          <!-- SDK tabs -->
          <div class="sdk-tabs">
            <button class="sdk-tab active" onclick="switchTab('python', this)">🐍 Python</button>
            <button class="sdk-tab" onclick="switchTab('nodejs', this)">🟢 Node.js</button>
            <button class="sdk-tab" onclick="switchTab('php', this)">🐘 PHP</button>
            <button class="sdk-tab" onclick="switchTab('cli', this)">💻 AWS CLI</button>
            <button class="sdk-tab" onclick="switchTab('rclone', this)">🔄 rclone</button>
            <button class="sdk-tab" onclick="switchTab('env', this)">📄 .env</button>
            <button class="sdk-tab" onclick="switchTab('s3browser', this)">🪟 S3 Browser</button>
          </div>

          <?php
          $ep  = htmlspecialchars($endpoint);
          $bkt = htmlspecialchars($bucket['name']);
          $ak  = htmlspecialchars($bucket['access_key']);
          $rg  = htmlspecialchars($bucket['region']);
          ?>

          <!-- Python -->
          <div class="sdk-pane active" id="pane-python">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('python-code')">Copy</button>
              <div id="python-code">
<div class="code-line"><span class="c-cmt"># pip install boto3</span></div>
<div class="code-line"><span class="c-key">import</span> <span class="c-text">boto3</span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-text">s3</span> <span class="c-op">=</span> <span class="c-text">boto3</span>.<span class="c-fn">client</span>(</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-str">'s3'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-key">endpoint_url</span><span class="c-op">=</span><span class="c-str">'<?= $ep ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-key">aws_access_key_id</span><span class="c-op">=</span><span class="c-str">'<?= $ak ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-key">aws_secret_access_key</span><span class="c-op">=</span><span class="c-str">'YOUR_SECRET_KEY'</span>,&nbsp;<span class="c-cmt"># replace with your secret</span></div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-key">region_name</span><span class="c-op">=</span><span class="c-str">'<?= $rg ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-cmt"># Required for MinIO path-style URLs</span></div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-key">config</span><span class="c-op">=</span><span class="c-text">boto3</span>.<span class="c-fn">session</span>.<span class="c-text">Config</span>(<span class="c-key">signature_version</span><span class="c-op">=</span><span class="c-str">'s3v4'</span>),</div>
<div class="code-line">)</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Upload a file</span></div>
<div class="code-line"><span class="c-text">s3</span>.<span class="c-fn">upload_file</span>(<span class="c-str">'local_file.txt'</span>, <span class="c-str">'<?= $bkt ?>'</span>, <span class="c-str">'remote_name.txt'</span>)</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Download a file</span></div>
<div class="code-line"><span class="c-text">s3</span>.<span class="c-fn">download_file</span>(<span class="c-str">'<?= $bkt ?>'</span>, <span class="c-str">'remote_name.txt'</span>, <span class="c-str">'local_file.txt'</span>)</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># List files</span></div>
<div class="code-line"><span class="c-text">resp</span> <span class="c-op">=</span> <span class="c-text">s3</span>.<span class="c-fn">list_objects_v2</span>(<span class="c-key">Bucket</span><span class="c-op">=</span><span class="c-str">'<?= $bkt ?>'</span>)</div>
<div class="code-line"><span class="c-key">for</span> <span class="c-text">obj</span> <span class="c-key">in</span> <span class="c-text">resp</span>.<span class="c-fn">get</span>(<span class="c-str">'Contents'</span>, []):</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-fn">print</span>(<span class="c-text">obj</span>[<span class="c-str">'Key'</span>], <span class="c-text">obj</span>[<span class="c-str">'Size'</span>])</div>
              </div>
            </div>
          </div>

          <!-- Node.js -->
          <div class="sdk-pane" id="pane-nodejs">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('nodejs-code')">Copy</button>
              <div id="nodejs-code">
<div class="code-line"><span class="c-cmt">// npm install @aws-sdk/client-s3</span></div>
<div class="code-line"><span class="c-key">const</span> { <span class="c-text">S3Client</span>, <span class="c-text">PutObjectCommand</span>, <span class="c-text">GetObjectCommand</span>, <span class="c-text">ListObjectsV2Command</span> } <span class="c-op">=</span> <span class="c-fn">require</span>(<span class="c-str">'@aws-sdk/client-s3'</span>);</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-key">const</span> <span class="c-text">s3</span> <span class="c-op">=</span> <span class="c-key">new</span> <span class="c-fn">S3Client</span>({</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-text">endpoint</span>: <span class="c-str">'<?= $ep ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-text">region</span>: <span class="c-str">'<?= $rg ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-text">credentials</span>: {</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-text">accessKeyId</span>: <span class="c-str">'<?= $ak ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-text">secretAccessKey</span>: <span class="c-str">'YOUR_SECRET_KEY'</span>&nbsp;<span class="c-cmt">// replace this</span></div>
<div class="code-line">&nbsp;&nbsp;},</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-text">forcePathStyle</span>: <span class="c-key">true</span>&nbsp;<span class="c-cmt">// required for MinIO</span></div>
<div class="code-line">});</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt">// Upload</span></div>
<div class="code-line"><span class="c-key">await</span> <span class="c-text">s3</span>.<span class="c-fn">send</span>(<span class="c-key">new</span> <span class="c-fn">PutObjectCommand</span>({ <span class="c-text">Bucket</span>: <span class="c-str">'<?= $bkt ?>'</span>, <span class="c-text">Key</span>: <span class="c-str">'file.txt'</span>, <span class="c-text">Body</span>: <span class="c-str">'hello world'</span> }));</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt">// List files</span></div>
<div class="code-line"><span class="c-key">const</span> <span class="c-text">list</span> <span class="c-op">=</span> <span class="c-key">await</span> <span class="c-text">s3</span>.<span class="c-fn">send</span>(<span class="c-key">new</span> <span class="c-fn">ListObjectsV2Command</span>({ <span class="c-text">Bucket</span>: <span class="c-str">'<?= $bkt ?>'</span> }));</div>
<div class="code-line"><span class="c-text">console</span>.<span class="c-fn">log</span>(<span class="c-text">list</span>.<span class="c-text">Contents</span>);</div>
              </div>
            </div>
          </div>

          <!-- PHP -->
          <div class="sdk-pane" id="pane-php">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('php-code')">Copy</button>
              <div id="php-code">
<div class="code-line"><span class="c-cmt">// composer require aws/aws-sdk-php</span></div>
<div class="code-line"><span class="c-key">require</span> <span class="c-str">'vendor/autoload.php'</span>;</div>
<div class="code-line"><span class="c-key">use</span> <span class="c-text">Aws\S3\S3Client</span>;</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-text">$s3</span> <span class="c-op">=</span> <span class="c-key">new</span> <span class="c-fn">S3Client</span>([</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-str">'version'</span>  <span class="c-op">=&gt;</span> <span class="c-str">'latest'</span>,</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-str">'region'</span>   <span class="c-op">=&gt;</span> <span class="c-str">'<?= $rg ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-str">'endpoint'</span> <span class="c-op">=&gt;</span> <span class="c-str">'<?= $ep ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-str">'credentials'</span> <span class="c-op">=&gt;</span> [</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-str">'key'</span>    <span class="c-op">=&gt;</span> <span class="c-str">'<?= $ak ?>'</span>,</div>
<div class="code-line">&nbsp;&nbsp;&nbsp;&nbsp;<span class="c-str">'secret'</span> <span class="c-op">=&gt;</span> <span class="c-str">'YOUR_SECRET_KEY'</span>, <span class="c-cmt">// replace</span></div>
<div class="code-line">&nbsp;&nbsp;],</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-str">'use_path_style_endpoint'</span> <span class="c-op">=&gt;</span> <span class="c-key">true</span>,</div>
<div class="code-line">]);</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt">// Upload</span></div>
<div class="code-line"><span class="c-text">$s3</span><span class="c-op">-&gt;</span><span class="c-fn">putObject</span>([<span class="c-str">'Bucket'</span> <span class="c-op">=&gt;</span> <span class="c-str">'<?= $bkt ?>'</span>, <span class="c-str">'Key'</span> <span class="c-op">=&gt;</span> <span class="c-str">'hello.txt'</span>, <span class="c-str">'Body'</span> <span class="c-op">=&gt;</span> <span class="c-str">'Hello!'</span>]);</div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt">// Get a pre-signed URL (1 hour expiry)</span></div>
<div class="code-line"><span class="c-text">$cmd</span> <span class="c-op">=</span> <span class="c-text">$s3</span><span class="c-op">-&gt;</span><span class="c-fn">getCommand</span>(<span class="c-str">'GetObject'</span>, [<span class="c-str">'Bucket'</span> <span class="c-op">=&gt;</span> <span class="c-str">'<?= $bkt ?>'</span>, <span class="c-str">'Key'</span> <span class="c-op">=&gt;</span> <span class="c-str">'hello.txt'</span>]);</div>
<div class="code-line"><span class="c-text">$url</span> <span class="c-op">=</span> <span class="c-text">$s3</span><span class="c-op">-&gt;</span><span class="c-fn">createPresignedRequest</span>(<span class="c-text">$cmd</span>, <span class="c-str">'+1 hour'</span>)<span class="c-op">-&gt;</span><span class="c-fn">getUri</span>();</div>
              </div>
            </div>
          </div>

          <!-- AWS CLI -->
          <div class="sdk-pane" id="pane-cli">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('cli-code')">Copy</button>
              <div id="cli-code">
<div class="code-line"><span class="c-cmt"># Step 1: Configure credentials</span></div>
<div class="code-line"><span class="c-text">aws configure --profile <?= $bkt ?></span></div>
<div class="code-line"><span class="c-cmt"># Enter when prompted:</span></div>
<div class="code-line"><span class="c-cmt">#   AWS Access Key ID:     <?= $ak ?></span></div>
<div class="code-line"><span class="c-cmt">#   AWS Secret Access Key: YOUR_SECRET_KEY</span></div>
<div class="code-line"><span class="c-cmt">#   Default region:        <?= $rg ?></span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Step 2: Use with --endpoint-url flag</span></div>
<div class="code-line"><span class="c-text">aws s3 ls s3://<?= $bkt ?>/ \</span></div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--endpoint-url</span> <?= $ep ?> \</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--profile</span> <?= $bkt ?></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Upload a file</span></div>
<div class="code-line"><span class="c-text">aws s3 cp myfile.txt s3://<?= $bkt ?>/myfile.txt \</span></div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--endpoint-url</span> <?= $ep ?> \</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--profile</span> <?= $bkt ?></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Sync entire folder</span></div>
<div class="code-line"><span class="c-text">aws s3 sync ./my-folder s3://<?= $bkt ?>/ \</span></div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--endpoint-url</span> <?= $ep ?> \</div>
<div class="code-line">&nbsp;&nbsp;<span class="c-key">--profile</span> <?= $bkt ?></div>
              </div>
            </div>
          </div>

          <!-- rclone -->
          <div class="sdk-pane" id="pane-rclone">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('rclone-code')">Copy</button>
              <div id="rclone-code">
<div class="code-line"><span class="c-cmt"># Add to ~/.config/rclone/rclone.conf</span></div>
<div class="code-line">[<?= $bkt ?>]</div>
<div class="code-line"><span class="c-key">type</span> = s3</div>
<div class="code-line"><span class="c-key">provider</span> = Other</div>
<div class="code-line"><span class="c-key">env_auth</span> = false</div>
<div class="code-line"><span class="c-key">access_key_id</span> = <?= $ak ?></div>
<div class="code-line"><span class="c-key">secret_access_key</span> = YOUR_SECRET_KEY&nbsp;<span class="c-cmt"># replace</span></div>
<div class="code-line"><span class="c-key">endpoint</span> = <?= $ep ?></div>
<div class="code-line"><span class="c-key">region</span> = <?= $rg ?></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Then use:</span></div>
<div class="code-line"><span class="c-text">rclone ls <?= $bkt ?>:<?= $bkt ?></span></div>
<div class="code-line"><span class="c-text">rclone copy ./local-folder <?= $bkt ?>:<?= $bkt ?>/backup</span></div>
<div class="code-line"><span class="c-text">rclone sync ./local-folder <?= $bkt ?>:<?= $bkt ?>/sync</span></div>
              </div>
            </div>
          </div>

          <!-- S3 Browser -->
          <div class="sdk-pane" id="pane-s3browser">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('s3browser-code')">Copy</button>
              <div id="s3browser-code">
<div class="code-line"><span class="c-cmt"># S3 Browser Settings (Tools → Accounts → Add Account)</span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-key">Account Type:</span>  <span class="c-str">S3 Compatible Storage</span></div>
<div class="code-line"><span class="c-key">REST Endpoint:</span> <span class="c-str"><?= htmlspecialchars($public_ep) ?></span>  <span class="c-cmt">(remove https://)</span></div>
<div class="code-line"><span class="c-key">Access Key ID:</span> <span class="c-str"><?= htmlspecialchars($ak) ?></span></div>
<div class="code-line"><span class="c-key">Secret Access Key:</span> <span class="c-str">YOUR_SECRET_KEY</span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Important checkboxes to ENABLE:</span></div>
<div class="code-line"><span class="c-str">✓ Use secure transfer (SSL)</span>  <span class="c-cmt">(if https)</span></div>
<div class="code-line"><span class="c-str">✓ Force path style</span>  <span class="c-cmt">(REQUIRED for MinIO)</span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Signature Version: AWS Signature V4</span></div>
<div class="code-line"><span class="c-cmt"># Region: <?= htmlspecialchars($rg) ?></span></div>
              </div>
            </div>
            <div style="background:#fef9c3;border:1px solid #fde047;border-radius:9px;padding:12px 15px;margin-top:12px;font-size:13px;color:#78350f;line-height:1.7">
              <strong>⚠ Common S3 Browser errors:</strong><br>
              <strong>InvalidAccessKeyId</strong> → Access Key wrong ya copy-paste error. Use the "Copy" button above.<br>
              <strong>SignatureDoesNotMatch</strong> → Force path style enable nahi hai, ya region mismatch.<br>
              <strong>Connection refused</strong> → REST Endpoint mein <code>https://</code> mat likho — sirf domain aur port likho.
            </div>
          </div>

          <!-- .env -->
          <div class="sdk-pane" id="pane-env">
            <div class="code-block">
              <button class="code-copy-btn" onclick="copyBlock('env-code')">Copy</button>
              <div id="env-code">
<div class="code-line"><span class="c-cmt"># Add to your .env file (Django, Laravel, Next.js, etc.)</span></div>
<div class="code-line"><span class="c-key">S3_ENDPOINT</span>=<?= $ep ?></div>
<div class="code-line"><span class="c-key">S3_BUCKET</span>=<?= $bkt ?></div>
<div class="code-line"><span class="c-key">S3_REGION</span>=<?= $rg ?></div>
<div class="code-line"><span class="c-key">S3_ACCESS_KEY</span>=<?= $ak ?></div>
<div class="code-line"><span class="c-key">S3_SECRET_KEY</span>=YOUR_SECRET_KEY&nbsp;<span class="c-cmt"># replace</span></div>
<div class="code-line">&nbsp;</div>
<div class="code-line"><span class="c-cmt"># Django settings.py (using django-storages)</span></div>
<div class="code-line"><span class="c-key">DEFAULT_FILE_STORAGE</span>=storages.backends.s3boto3.S3Boto3Storage</div>
<div class="code-line"><span class="c-key">AWS_S3_ENDPOINT_URL</span>=<?= $ep ?></div>
<div class="code-line"><span class="c-key">AWS_STORAGE_BUCKET_NAME</span>=<?= $bkt ?></div>
<div class="code-line"><span class="c-key">AWS_ACCESS_KEY_ID</span>=<?= $ak ?></div>
<div class="code-line"><span class="c-key">AWS_SECRET_ACCESS_KEY</span>=YOUR_SECRET_KEY</div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<!-- Create Key Modal -->
<div id="create-key-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;padding:20px"
     onclick="if(event.target===this)this.style.display='none'">
  <div style="background:white;border-radius:13px;width:100%;max-width:420px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <h3 style="font-size:15px;font-weight:800;margin-bottom:14px">Create New API Key</h3>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="create_key">
      <div style="margin-bottom:13px">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:var(--gray-700)">Label (e.g. "Backend App", "Read-only CDN")</label>
        <input name="label" class="finp" placeholder="e.g. Production Backend" style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;outline:none">
      </div>
      <div style="margin-bottom:18px">
        <label style="display:block;font-size:12px;font-weight:700;margin-bottom:8px;color:var(--gray-700)">Permissions</label>
        <div style="display:flex;gap:16px">
          <?php foreach (['read' => 'Read', 'write' => 'Write', 'delete' => 'Delete'] as $perm => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="perm_<?= $perm ?>" value="1"
                   <?= $perm !== 'delete' ? 'checked' : '' ?>
                   style="accent-color:var(--primary)">
            <?= $label ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div style="font-size:11.5px;color:var(--gray-400);margin-top:6px">
          Tip: Create a Read-only key for CDN/public access. Write key for your backend.
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" data-loading="Creating Key..." class="btn btn-primary" style="flex:1;justify-content:center"><svg style="width: 20px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> Create Key</button>
        <button type="button" onclick="document.getElementById('create-key-modal').style.display='none'"
                style="padding:10px 18px;background:white;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer">Cancel</button>
      </div>
    </form>
  </div>
</div>

<div id="toast" style="position:fixed;bottom:18px;right:18px;padding:10px 16px;background:#0f172a;color:white;border-radius:8px;font-size:13px;font-weight:700;z-index:9999;transform:translateY(60px);opacity:0;transition:all .3s"></div>

<script>
// Secret key stored safely in JS (not in DOM by default)
var SECRET_KEY = '<?= addslashes($bucket['secret_key']) ?>';
var secret_revealed = false;

function toggleSecret(el) {
  secret_revealed = !secret_revealed;
  if (secret_revealed) {
    el.textContent = SECRET_KEY;
    el.classList.remove('blur-val');
    document.getElementById('eye-icon').innerHTML =
      '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  } else {
    el.textContent = 'click to reveal';
    el.classList.add('blur-val');
    document.getElementById('eye-icon').innerHTML =
      '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }
}

function copyText(txt, btn) {
  navigator.clipboard.writeText(txt).then(function () {
    if (btn) {
      var orig = btn.textContent;
      btn.textContent = '✓ Copied';
      btn.style.color = '#16a34a';
      setTimeout(function () { btn.textContent = orig; btn.style.color = ''; }, 2000);
    }
    showToast('Copied!');
  });
}

function copyBlock(blockId) {
  var el = document.getElementById(blockId);
  var text = el.innerText || el.textContent;
  // Replace 'YOUR_SECRET_KEY' placeholder with actual secret
  text = text.replace(/YOUR_SECRET_KEY/g, SECRET_KEY);
  navigator.clipboard.writeText(text).then(function () { showToast('Code copied!'); });
}

function showToast(msg) {
  var t = document.getElementById('toast');
  t.textContent = msg;
  t.style.transform = 'translateY(0)';
  t.style.opacity = '1';
  setTimeout(function () { t.style.transform = 'translateY(60px)'; t.style.opacity = '0'; }, 2500);
}

function switchTab(name, btn) {
  document.querySelectorAll('.sdk-tab').forEach(function(b) { b.classList.remove('active'); });
  document.querySelectorAll('.sdk-pane').forEach(function(p) { p.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('pane-' + name).classList.add('active');
}

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function () {

        const btn = this.querySelector('button[type="submit"]');

        if (!btn) return;

        btn.disabled = true;

        const text = btn.dataset.loading || 'Loading...';

        btn.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;
    });
});
</script>
</body>
</html>
