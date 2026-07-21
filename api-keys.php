<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$csrf     = csrf_token();

$msg = ''; $err = '';
$new_token = null;

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_token') {
        $name    = trim($_POST['token_name'] ?? '');
        $expires = trim($_POST['expires_at'] ?? '');

        if (!$name) { $err = 'Token name is required.'; }
        else {
            // Count existing tokens
            $count = (int)db()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id=?')
                ->execute([$user['id']]) ? (function() use ($user){ $s=db()->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id=?');$s->execute([$user['id']]);return (int)$s->fetchColumn();}
            )() : 0;

            if ($count >= 10) {
                $err = 'Maximum 10 API tokens per account. Delete an existing token first.';
            } else {
                $token   = bin2hex(random_bytes(32)); // 64-char hex
                $exp_val = $expires ? date('Y-m-d H:i:s', strtotime($expires)) : null;

                db()->prepare(
                    'INSERT INTO api_tokens (user_id,name,token,expires_at) VALUES (?,?,?,?)'
                )->execute([$user['id'], $name, $token, $exp_val]);

                $new_token = $token;
                $msg = "Token \"{$name}\" created. Copy it now — it won't be shown again.";
            }
        }
    }

    if ($action === 'delete_token') {
        $tid = (int)($_POST['token_id'] ?? 0);
        $tk  = db()->prepare('SELECT * FROM api_tokens WHERE id=? AND user_id=? LIMIT 1');
        $tk->execute([$tid, $user['id']]);
        $tk = $tk->fetch();
        if ($tk) {
            db()->prepare('DELETE FROM api_tokens WHERE id=? AND user_id=?')
               ->execute([$tid, $user['id']]);
            $msg = "Token \"{$tk['name']}\" deleted.";
        }
    }
}

// Load tokens (don't show actual token values — only show on creation)
$tokens = db()->prepare(
    'SELECT id,name,last_used,expires_at,created_at FROM api_tokens WHERE user_id=? ORDER BY created_at DESC'
);
$tokens->execute([$user['id']]);
$tokens = $tokens->fetchAll() ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>API Access — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .page-wrap{padding:24px;max-width:760px}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:20px}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}
    .flabel span{font-weight:400;color:var(--gray-400)}

    /* Token reveal box */
    .token-box{background:#0d1117;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:6px}
    .token-text{font-family:'JetBrains Mono',monospace;font-size:13px;color:#3fb950;flex:1;word-break:break-all}
    .copy-btn{padding:6px 13px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:7px;font-size:12px;font-weight:700;color:white;cursor:pointer;white-space:nowrap;transition:background .13s;font-family:inherit}
    .copy-btn:hover{background:rgba(255,255,255,.2)}

    /* Token list */
    .token-item{display:flex;align-items:center;gap:14px;padding:13px 0;border-bottom:1px solid var(--gray-100)}
    .token-item:last-child{border:none}
    .token-icon{width:38px;height:38px;border-radius:9px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .token-name{font-size:14px;font-weight:800;color:var(--gray-900)}
    .token-meta{font-size:12px;color:var(--gray-400);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap}
    .del-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border:1px solid #fca5a5;background:white;color:var(--danger);border-radius:7px;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .13s;margin-left:auto;flex-shrink:0}
    .del-btn:hover{background:var(--danger-bg)}
    .create-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .create-btn:hover{background:var(--primary-hover)}
    .empty-state{padding:40px 20px;text-align:center}

    /* Endpoint examples */
    .code-block{background:#0d1117;border-radius:9px;padding:14px 16px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#e6edf3;overflow-x:auto;margin-bottom:10px}
    .code-comment{color:#8b949e}
    .lang-tab{padding:6px 16px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--border);background:white;color:var(--gray-500);font-family:inherit;transition:all .13s}
    .lang-tab:hover{border-color:#94a3b8;color:var(--gray-800)}
    .lang-tab.active{background:#0f172a;color:white;border-color:#0f172a}
    .code-key{color:#79c0ff}
    .code-val{color:#a5d6ff}
    .code-method{color:#ffa657}

    /* Service tabs */
    .svc-tab{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:7px;font-size:12px;font-weight:700;border:1.5px solid var(--border);background:white;color:var(--gray-500);cursor:pointer;transition:all .13s;font-family:inherit}
    .svc-tab:hover{border-color:var(--primary);color:var(--primary)}
    .svc-tab.active{background:var(--primary);color:white;border-color:var(--primary)}
    .svc-panel{display:none}
    .svc-panel.active{display:block}
    .svc-badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:5px;font-family:monospace;vertical-align:middle}
    .svc-endpoint{margin-bottom:18px;border:1.5px solid var(--border);border-radius:11px;overflow:hidden}
    .svc-ep-head{padding:10px 14px;background:var(--gray-50);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .method-get{background:#eff6ff;color:#2563eb}
    .method-post{background:#f0fdf4;color:#16a34a}
    .method-delete{background:#fef2f2;color:#dc2626}
    .method-put{background:#fff7ed;color:#c2410c}
    .ep-url{font-family:monospace;font-size:12.5px;color:var(--gray-700);flex:1}
    .ep-title{font-size:12px;font-weight:600;color:var(--gray-500)}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">API Access</span>
    </div>
    <div class="topbar"><span class="topbar-title">API Access</span></div>

    <div class="page-wrap">
      <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- New token reveal -->
      <?php if ($new_token): ?>
      <div class="card" style="border-color:var(--success)">
        <div class="card-head" style="background:#f0fdf4">
          <span class="card-title" style="color:#16a34a">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:5px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><polyline points="9 11 12 14 22 4"/></svg>
            Token created — copy it now!
          </span>
        </div>
        <div class="card-body">
          <p style="font-size:13px;color:var(--gray-600);margin-bottom:10px">
            This is the only time your token will be shown. Store it securely — we do not store it in plaintext.
          </p>
          <div class="token-box">
            <span class="token-text" id="new-token-val"><?= htmlspecialchars($new_token) ?></span>
            <button class="copy-btn" onclick="copyToken()">Copy</button>
          </div>
          <p style="font-size:11.5px;color:var(--gray-400);margin-top:6px">If you lose this token, delete it and create a new one.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Create token -->
      <div class="card">
        <div class="card-head"><span class="card-title">Create API Token</span></div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_token">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
              <div>
                <label class="flabel">Token Name</label>
                <input type="text" name="token_name" class="form-control" placeholder="my-app-token" required>
              </div>
              <div>
                <label class="flabel">Expiry <span>(optional)</span></label>
                <input type="date" name="expires_at" class="form-control"
                       min="<?= date('Y-m-d') ?>"
                       value="">
              </div>
            </div>
            <button type="submit" class="create-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Generate Token
            </button>
          </form>
        </div>
      </div>

      <!-- Existing tokens -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">Your Tokens</span>
          <span class="badge badge-blue"><?= count($tokens) ?> / 10</span>
        </div>
        <?php if (empty($tokens)): ?>
        <div class="empty-state">
          <div style="font-size:15px;font-weight:800;color:var(--gray-700);margin-bottom:5px">No API tokens yet</div>
          <div style="font-size:13px;color:var(--gray-500)">Create a token above to access the <?= $app_name ?> API.</div>
        </div>
        <?php else: ?>
        <div style="padding:0 20px">
          <?php foreach ($tokens as $tk):
            $is_expired = $tk['expires_at'] && strtotime($tk['expires_at']) < time();
          ?>
          <div class="token-item">
            <div class="token-icon">
              <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--gray-500)" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            </div>
            <div style="flex:1;min-width:0">
              <div style="display:flex;align-items:center;gap:8px">
                <span class="token-name"><?= htmlspecialchars($tk['name']) ?></span>
                <?php if ($is_expired): ?>
                <span class="badge badge-yellow">Expired</span>
                <?php endif; ?>
              </div>
              <div class="token-meta">
                <span>Created <?= date('d M Y', strtotime($tk['created_at'])) ?></span>
                <?php if ($tk['last_used']): ?>
                <span>Last used <?= date('d M Y, H:i', strtotime($tk['last_used'])) ?></span>
                <?php else: ?>
                <span style="color:var(--gray-300)">Never used</span>
                <?php endif; ?>
                <?php if ($tk['expires_at']): ?>
                <span style="color:<?= $is_expired ? 'var(--danger)' : 'var(--gray-400)' ?>">
                  Expires <?= date('d M Y', strtotime($tk['expires_at'])) ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
            <form method="POST" onsubmit="return confirm('Delete token \'<?= htmlspecialchars(addslashes($tk['name'])) ?>\'?')">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_token">
              <input type="hidden" name="token_id" value="<?= $tk['id'] ?>">
              <button type="submit" class="del-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                Delete
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- API Reference — Service Tabs -->
      <div class="card" style="overflow:visible">
        <div class="card-head" style="flex-wrap:wrap;gap:10px">
          <span class="card-title">API Reference</span>
          <div style="display:flex;gap:6px;flex-wrap:wrap" id="svc-tabs">
            <button class="svc-tab active" onclick="setSvc('vps',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/></svg>
              VPS
            </button>
            <button class="svc-tab" onclick="setSvc('dns',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/></svg>
              DNS
            </button>
            <button class="svc-tab" onclick="setSvc('proxy',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M4 10h16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
              Proxy
            </button>
            <button class="svc-tab" onclick="setSvc('smtp',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              SMTP
            </button>
            <button class="svc-tab" onclick="setSvc('storage',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/></svg>
              Storage
            </button>
            <button class="svc-tab" onclick="setSvc('billing',this)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              Billing
            </button>
          </div>
        </div>
        <div class="card-body">
          <p style="font-size:13px;color:var(--gray-600);margin-bottom:18px;line-height:1.7">
            All requests require your API token in the <code style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px">Authorization: Bearer TOKEN</code> header.
            Base URL: <code style="font-family:monospace;font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px"><?= BASE_URL ?>/api/v1</code>
          </p>

<?php
function _ep(string $m,string $t,string $u,string $c,string $p,string $r):array{return['method'=>$m,'title'=>$t,'url'=>$u,'curl'=>$c,'php'=>$p,'response'=>$r];}
function renderEndpoints(array $eps,string $pfx):void{
  foreach($eps as $i=>$ep):
    $id=$pfx.'-'.$i;
    $mc=match($ep['method']){'POST'=>'method-post','DELETE'=>'method-delete','PUT'=>'method-put',default=>'method-get'};
?>
<div class="svc-endpoint">
  <div class="svc-ep-head">
    <span class="svc-badge <?=$mc?>"><?=$ep['method']?></span>
    <code class="ep-url"><?=htmlspecialchars($ep['url'])?></code>
    <span class="ep-title"><?=htmlspecialchars($ep['title'])?></span>
  </div>
  <div style="position:relative">
    <pre class="code-block" id="c-<?=$id?>" style="margin:0;border-radius:0;border:none;padding:14px 16px;white-space:pre-wrap;word-break:break-all"><span style="color:#8b949e"># cURL</span>
<span style="color:#e6edf3"><?=htmlspecialchars($ep['curl'])?></span></pre>
    <pre class="code-block" id="p-<?=$id?>" style="display:none;margin:0;border-radius:0;border:none;padding:14px 16px;white-space:pre-wrap;word-break:break-all"><span style="color:#8b949e">// PHP</span>
<span style="color:#e6edf3"><?=htmlspecialchars($ep['php'])?></span></pre>
    <button onclick="copyCode(this,'c-<?=$id?>','p-<?=$id?>')"
      style="position:absolute;top:10px;right:10px;padding:4px 10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:5px;font-size:11px;font-weight:700;color:#8b949e;cursor:pointer;font-family:inherit">Copy</button>
  </div>
  <div style="border-top:1px solid #21262d;background:#010409;padding:10px 14px">
    <div style="font-size:10px;font-weight:700;color:#8b949e;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">Response</div>
    <pre style="margin:0;font-family:'JetBrains Mono',monospace;font-size:11.5px;color:#3fb950;white-space:pre-wrap;word-break:break-all"><?=htmlspecialchars($ep['response'])?></pre>
  </div>
</div>
<?php endforeach;}

$B=rtrim(BASE_URL,'/');
?>

<!-- Lang tabs -->
<div style="display:flex;gap:6px;margin-bottom:20px" id="lang-tabs">
  <button onclick="setLang('curl')" id="lt-curl" class="lang-tab active">cURL</button>
  <button onclick="setLang('php')"  id="lt-php"  class="lang-tab">PHP</button>
</div>

<!-- Base URL note -->
<p style="font-size:13px;color:var(--gray-600);margin-bottom:18px;line-height:1.7">
  All requests require: <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px">Authorization: Bearer YOUR_TOKEN</code><br>
  Base URL: <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px"><?= BASE_URL ?>/api/v1</code>
</p>

<!-- ══ VPS ══ -->
<div class="svc-panel active" id="svc-vps">
<?php renderEndpoints([
  _ep('GET','List Servers',$B.'/api/v1/servers',
    'curl -X GET "'.$B.'/api/v1/servers"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/servers",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"servers":[{"id":5,"name":"my-vps","status":"running","plan":"cx22","ipv4":"1.2.3.4","vcpu":2,"ram_gb":4,"disk_gb":40}],"meta":{"total":1}}'
  ),
  _ep('GET','Get Single Server',$B.'/api/v1/servers?id=5',
    'curl -X GET "'.$B.'/api/v1/servers?id=5"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/servers?id=5",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"server":{"id":5,"name":"my-vps","status":"running","ipv4":"1.2.3.4","vcpu":2,"ram_gb":4,"disk_gb":40,"region":"in-mum","os":"Ubuntu 24.04"}}'
  ),
  _ep('POST','Server Action',$B.'/api/v1/server-actions?id=5',
    'curl -X POST "'.$B.'/api/v1/server-actions?id=5"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"'."\n".'  -H "Content-Type: application/json"'."\n".'  -d \'{"action":"reboot"}\'',
    '$ch=curl_init("'.$B.'/api/v1/server-actions?id=5");'."\n".'curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,'."\n".'  CURLOPT_POSTFIELDS=>json_encode(["action"=>"reboot"]),'."\n".'  CURLOPT_HTTPHEADER=>["Authorization: Bearer YOUR_TOKEN","Content-Type: application/json"]]);'."\n".'print_r(json_decode(curl_exec($ch),true));',
    '{"ok":true,"message":"Action queued.","action":"reboot","server_id":5}'
  ),
  _ep('GET','Action History',$B.'/api/v1/server-actions?id=5',
    'curl -X GET "'.$B.'/api/v1/server-actions?id=5"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// GET returns action history for the server',
    '{"ok":true,"actions":[{"id":12,"action":"reboot","status":"success","created_at":"2025-05-01 10:00:00"}]}'
  ),
],'vps'); ?>
<div style="margin-top:6px;background:var(--gray-50);border:1px solid var(--border);border-radius:9px;padding:12px 16px">
  <div style="font-size:11px;font-weight:800;text-transform:uppercase;color:var(--gray-500);margin-bottom:8px">Valid action values</div>
  <div style="display:flex;flex-wrap:wrap;gap:7px">
    <?php foreach(['start','stop','reboot','shutdown','rebuild','reset_root_password','enable_rescue'] as $a): ?>
    <code style="background:#0d1117;color:#3fb950;font-family:monospace;font-size:12px;padding:3px 9px;border-radius:5px"><?=$a?></code>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- ══ DNS ══ -->
<div class="svc-panel" id="svc-dns">
<?php renderEndpoints([
  _ep('GET','List Zones',$B.'/api/v1/dns',
    'curl -X GET "'.$B.'/api/v1/dns"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/dns",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"zones":[{"id":1,"domain":"example.com","status":"active","nameservers":["ns1.cf.com","ns2.cf.com"]}],"meta":{"total":1}}'
  ),
  _ep('GET','Zone + Records',$B.'/api/v1/dns?id=1',
    'curl -X GET "'.$B.'/api/v1/dns?id=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Returns zone info + all DNS records',
    '{"ok":true,"zone":{"id":1,"domain":"example.com","status":"active"},"records":[{"id":10,"type":"A","name":"@","value":"1.2.3.4","ttl":3600}]}'
  ),
  _ep('POST','Create Zone',$B.'/api/v1/dns',
    'curl -X POST "'.$B.'/api/v1/dns"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"'."\n".'  -H "Content-Type: application/json"'."\n".'  -d \'{"domain":"example.com"}\'',
    '$ch=curl_init("'.$B.'/api/v1/dns");'."\n".'curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,'."\n".'  CURLOPT_POSTFIELDS=>json_encode(["domain"=>"example.com"]),'."\n".'  CURLOPT_HTTPHEADER=>["Authorization: Bearer YOUR_TOKEN","Content-Type: application/json"]]);'."\n".'print_r(json_decode(curl_exec($ch),true));',
    '{"ok":true,"zone":{"id":2,"domain":"example.com","status":"pending","nameservers":["ns1.cf.com","ns2.cf.com"]}}'
  ),
  _ep('GET','List Records',$B.'/api/v1/dns?id=1&records=1',
    'curl -X GET "'.$B.'/api/v1/dns?id=1&records=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Add &records=1 to work with DNS records sub-resource',
    '{"ok":true,"records":[{"id":10,"type":"A","name":"@","value":"1.2.3.4","ttl":3600},{"id":11,"type":"MX","name":"@","value":"mail.example.com","ttl":3600,"priority":10}]}'
  ),
  _ep('POST','Add Record',$B.'/api/v1/dns?id=1&records=1',
    'curl -X POST "'.$B.'/api/v1/dns?id=1&records=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"'."\n".'  -H "Content-Type: application/json"'."\n".'  -d \'{"type":"A","name":"www","value":"1.2.3.4","ttl":3600}\'',
    '$data=json_encode(["type"=>"A","name"=>"www","value"=>"1.2.3.4","ttl"=>3600]);'."\n".'// POST same way as Create Zone above',
    '{"ok":true,"record":{"id":12,"type":"A","name":"www","value":"1.2.3.4","ttl":3600}}'
  ),
  _ep('DELETE','Delete Record',$B.'/api/v1/dns?id=1&records=1&record_id=12',
    'curl -X DELETE "'.$B.'/api/v1/dns?id=1&records=1&record_id=12"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    'curl_setopt($ch,CURLOPT_CUSTOMREQUEST,"DELETE");',
    '{"ok":true,"message":"Record deleted."}'
  ),
  _ep('DELETE','Delete Zone',$B.'/api/v1/dns?id=1',
    'curl -X DELETE "'.$B.'/api/v1/dns?id=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    'curl_setopt($ch,CURLOPT_CUSTOMREQUEST,"DELETE");',
    '{"ok":true,"message":"Zone deleted."}'
  ),
],'dns'); ?>
<div style="margin-top:6px;background:var(--gray-50);border:1px solid var(--border);border-radius:9px;padding:12px 16px">
  <div style="font-size:11px;font-weight:800;text-transform:uppercase;color:var(--gray-500);margin-bottom:8px">Supported record types</div>
  <div style="display:flex;flex-wrap:wrap;gap:7px">
    <?php foreach(['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'] as $t): ?>
    <code style="background:#0d1117;color:#79c0ff;font-family:monospace;font-size:12px;padding:3px 9px;border-radius:5px"><?=$t?></code>
    <?php endforeach; ?>
  </div>
</div>
</div>

<!-- ══ PROXY ══ -->
<div class="svc-panel" id="svc-proxy">
<?php renderEndpoints([
  _ep('GET','List Proxies',$B.'/api/v1/proxy',
    'curl -X GET "'.$B.'/api/v1/proxy"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/proxy",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"proxies":[{"id":3,"plan":"Proxy Basic","status":"active","type":"http","gateway_host":"proxy.greathost.in","gateway_port":8080,"username":"user_abc","bandwidth_used_gb":1.2,"bandwidth_avail_gb":100}]}'
  ),
  _ep('GET','Get Single Proxy',$B.'/api/v1/proxy?id=3',
    'curl -X GET "'.$B.'/api/v1/proxy?id=3"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Returns credentials only if status=active',
    '{"ok":true,"proxy":{"id":3,"plan":"Proxy Basic","status":"active","gateway_host":"proxy.greathost.in","gateway_port":8080,"username":"user_abc","whitelist_ip":"1.2.3.4","expires_at":"2025-12-31"}}'
  ),
  _ep('GET','Bandwidth Stats',$B.'/api/v1/proxy?id=3&stats=1',
    'curl -X GET "'.$B.'/api/v1/proxy?id=3&stats=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Add &stats=1 for bandwidth usage',
    '{"ok":true,"stats":{"bandwidth_used_gb":1.2,"bandwidth_avail_gb":98.8,"status":"active","expires_at":"2025-12-31"}}'
  ),
  _ep('PUT','Rotate IP',$B.'/api/v1/proxy?id=3&rotate=1',
    'curl -X PUT "'.$B.'/api/v1/proxy?id=3&rotate=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$ch=curl_init("'.$B.'/api/v1/proxy?id=3&rotate=1");'."\n".'curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>"PUT",'."\n".'  CURLOPT_HTTPHEADER=>["Authorization: Bearer YOUR_TOKEN"]]);'."\n".'print_r(json_decode(curl_exec($ch),true));',
    '{"ok":true,"message":"IP rotation requested. New IPs will be assigned shortly.","proxy_id":3}'
  ),
  _ep('DELETE','Cancel Proxy',$B.'/api/v1/proxy?id=3',
    'curl -X DELETE "'.$B.'/api/v1/proxy?id=3"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    'curl_setopt($ch,CURLOPT_CUSTOMREQUEST,"DELETE");',
    '{"ok":true,"message":"Proxy order cancelled."}'
  ),
],'proxy'); ?>
</div>

<!-- ══ SMTP ══ -->
<div class="svc-panel" id="svc-smtp">
<?php renderEndpoints([
  _ep('GET','List SMTP Accounts',$B.'/api/v1/smtp',
    'curl -X GET "'.$B.'/api/v1/smtp"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/smtp",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"accounts":[{"id":1,"plan":"SMTP Starter","status":"active","domain":"mail.example.com","smtp_host":"email-smtp.ap-south-1.amazonaws.com","smtp_port":587,"smtp_username":"AKID...","emails_month":50000}]}'
  ),
  _ep('GET','Get Single Account',$B.'/api/v1/smtp?id=1',
    'curl -X GET "'.$B.'/api/v1/smtp?id=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// smtp_host/username shown only when status=active',
    '{"ok":true,"account":{"id":1,"plan":"SMTP Starter","status":"active","domain":"mail.example.com","domain_verified":true,"smtp_host":"email-smtp.ap-south-1.amazonaws.com","smtp_port":587}}'
  ),
  _ep('GET','DNS Auth Records',$B.'/api/v1/smtp?id=1&dns_records=1',
    'curl -X GET "'.$B.'/api/v1/smtp?id=1&dns_records=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Returns DKIM CNAME + SPF TXT + DMARC records to add to DNS',
    '{"ok":true,"records":[{"type":"CNAME","name":"abc._domainkey","value":"abc.dkim.amazonses.com"},{"type":"TXT","name":"@","value":"v=spf1 include:amazonses.com ~all"},{"type":"TXT","name":"_dmarc","value":"v=DMARC1; p=quarantine;"}],"domain":"mail.example.com"}'
  ),
  _ep('GET','Delivery Stats',$B.'/api/v1/smtp?id=1&stats=1',
    'curl -X GET "'.$B.'/api/v1/smtp?id=1&stats=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Daily send stats',
    '{"ok":true,"stats":{"sent_today":342,"emails_month_limit":50000,"domain":"mail.example.com","domain_verified":true,"status":"active"}}'
  ),
  _ep('POST','Send Test Email',$B.'/api/v1/smtp?id=1&test=1',
    'curl -X POST "'.$B.'/api/v1/smtp?id=1&test=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"'."\n".'  -H "Content-Type: application/json"'."\n".'  -d \'{"to":"test@example.com"}\'',
    '$ch=curl_init("'.$B.'/api/v1/smtp?id=1&test=1");'."\n".'curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,'."\n".'  CURLOPT_POSTFIELDS=>json_encode(["to"=>"test@example.com"]),'."\n".'  CURLOPT_HTTPHEADER=>["Authorization: Bearer YOUR_TOKEN","Content-Type: application/json"]]);'."\n".'print_r(json_decode(curl_exec($ch),true));',
    '{"ok":true,"message":"Test email sent to test@example.com."}'
  ),
],'smtp'); ?>
</div>

<!-- ══ OBJECT STORAGE ══ -->
<div class="svc-panel" id="svc-storage">
<?php renderEndpoints([
  _ep('GET','List Buckets',$B.'/api/v1/storage',
    'curl -X GET "'.$B.'/api/v1/storage"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/storage",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"buckets":[{"id":1,"name":"my-bucket","region":"in-mum","status":"active","endpoint_url":"https://s3.greathost.in/my-bucket","plan_gb":50,"used_gb":2.4}]}'
  ),
  _ep('GET','Get Single Bucket',$B.'/api/v1/storage?id=1',
    'curl -X GET "'.$B.'/api/v1/storage?id=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Returns access_key for S3-compatible client config',
    '{"ok":true,"bucket":{"id":1,"name":"my-bucket","region":"in-mum","status":"active","endpoint_url":"https://s3.greathost.in/my-bucket","access_key":"AKID...","plan_gb":50,"used_gb":2.4}}'
  ),
  _ep('GET','List Objects',$B.'/api/v1/storage?id=1&objects=1',
    'curl -X GET "'.$B.'/api/v1/storage?id=1&objects=1&prefix=images/"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Optional: &prefix=folder/ &limit=100',
    '{"ok":true,"objects":[{"key":"images/logo.png","size_bytes":24500,"last_modified":"2025-05-01T10:00:00Z"}],"prefix":"images/","bucket":"my-bucket"}'
  ),
  _ep('DELETE','Delete Object',$B.'/api/v1/storage?id=1&objects=1&key=images/logo.png',
    'curl -X DELETE "'.$B.'/api/v1/storage?id=1&objects=1&key=images%2Flogo.png"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    'curl_setopt($ch,CURLOPT_CUSTOMREQUEST,"DELETE"); // URL-encode the key',
    '{"ok":true,"message":"Object deleted.","key":"images/logo.png"}'
  ),
  _ep('GET','Get Presigned URL',$B.'/api/v1/storage?id=1&presign=1&key=file.pdf',
    'curl -X GET "'.$B.'/api/v1/storage?id=1&presign=1&key=file.pdf&expires=3600"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Optional: &expires=3600 (max 86400 = 24h)',
    '{"ok":true,"url":"https://s3.greathost.in/my-bucket/file.pdf?X-Amz-Expires=3600&...","expires_at":"2025-05-01T11:00:00Z","expires_in":3600}'
  ),
  _ep('GET','Usage Stats',$B.'/api/v1/storage?id=1&usage=1',
    'curl -X GET "'.$B.'/api/v1/storage?id=1&usage=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Syncs and returns live usage from MinIO',
    '{"ok":true,"usage":{"bucket":"my-bucket","used_gb":2.4,"plan_gb":50,"used_pct":4.8,"endpoint_url":"https://s3.greathost.in/my-bucket"}}'
  ),
  _ep('DELETE','Delete Bucket',$B.'/api/v1/storage?id=1',
    'curl -X DELETE "'.$B.'/api/v1/storage?id=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    'curl_setopt($ch,CURLOPT_CUSTOMREQUEST,"DELETE");',
    '{"ok":true,"message":"Bucket deleted."}'
  ),
],'storage'); ?>
</div>

<!-- ══ BILLING ══ -->
<div class="svc-panel" id="svc-billing">
<?php renderEndpoints([
  _ep('GET','Wallet Balance',$B.'/api/v1/billing',
    'curl -X GET "'.$B.'/api/v1/billing"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/billing",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"balance":4900.00,"currency":"INR","low_balance_alert":false,"unpaid_invoices":0,"unpaid_amount":0,"last_topup":{"amount":1000,"date":"2025-04-15 10:00:00"}}'
  ),
  _ep('GET','Transaction History',$B.'/api/v1/billing?type=transactions',
    'curl -X GET "'.$B.'/api/v1/billing?type=transactions&limit=20&page=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Optional: &from=2025-01-01&to=2025-05-31&limit=50&page=2',
    '{"ok":true,"transactions":[{"id":101,"type":"credit","amount":500,"currency":"INR","description":"Wallet top-up","balance_before":4400,"balance_after":4900,"created_at":"2025-04-15"}],"meta":{"total":45,"limit":20,"page":1,"pages":3}}'
  ),
  _ep('GET','Invoices',$B.'/api/v1/billing?type=invoices',
    'curl -X GET "'.$B.'/api/v1/billing?type=invoices&limit=10&page=1"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Optional: &limit=10&page=2',
    '{"ok":true,"invoices":[{"id":55,"invoice_no":"GH-2025-0055","amount":799,"currency":"INR","status":"paid","type":"service","period_start":"2025-04-01","period_end":"2025-04-30","download_url":"/invoices/55.pdf"}],"meta":{"total":12,"pages":2}}'
  ),
  _ep('GET','Usage by Service',$B.'/api/v1/billing?type=usage',
    'curl -X GET "'.$B.'/api/v1/billing?type=usage&month=2025-05"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '// Optional: &month=YYYY-MM (defaults to current month)',
    '{"ok":true,"usage":{"vps":799,"smtp":499,"proxy":399,"dns":0,"storage":49,"other":0,"total":1746,"currency":"INR"},"month":"2025-05","period":{"from":"2025-05-01","to":"2025-05-31"}}'
  ),
  _ep('GET','Account Info',$B.'/api/v1/account',
    'curl -X GET "'.$B.'/api/v1/account"'."\n".'  -H "Authorization: Bearer YOUR_TOKEN"',
    '$r=file_get_contents("'.$B.'/api/v1/account",false,'."\n".'  stream_context_create(["http"=>["header"=>"Authorization: Bearer YOUR_TOKEN"]]));'."\n".'print_r(json_decode($r,true));',
    '{"ok":true,"account":{"id":1,"username":"sagar7906","email":"sagar@greathost.in","wallet_balance":4900.00,"currency":"INR"}}'
  ),
],'billing'); ?>
</div>


        </div>
      </div>

    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<script>
var currentLang = 'curl';
var currentSvc  = 'vps';

function setSvc(svc, btn) {
  currentSvc = svc;
  document.querySelectorAll('.svc-tab').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('.svc-panel').forEach(function(p){ p.classList.remove('active'); });
  var panel = document.getElementById('svc-' + svc);
  if (panel) panel.classList.add('active');
}

function setLang(lang) {
  currentLang = lang;
  document.querySelectorAll('.lang-tab').forEach(function(b){ b.classList.remove('active'); });
  var lt = document.getElementById('lt-' + lang);
  if (lt) lt.classList.add('active');
  // Toggle all code blocks across all service panels
  document.querySelectorAll('[id^="c-"]').forEach(function(el){
    el.style.display = lang === 'curl' ? 'block' : 'none';
  });
  document.querySelectorAll('[id^="p-"]').forEach(function(el){
    el.style.display = lang === 'php' ? 'block' : 'none';
  });
}

function copyCode(btn, curlId, phpId) {
  var el = currentLang === 'php'
    ? document.getElementById(phpId)
    : document.getElementById(curlId);
  if (!el) return;
  var text = el.innerText.replace(/^# cURL\n|^\/\/ PHP\n/, '').trim();
  navigator.clipboard.writeText(text).then(function() {
    btn.textContent = '✓ Copied!';
    setTimeout(function(){ btn.textContent = 'Copy'; }, 2000);
  });
}

function copyToken() {
  var el = document.getElementById('new-token-val');
  if (el) {
    navigator.clipboard.writeText(el.textContent.trim()).then(function() {
      var btn = event.target;
      btn.textContent = 'Copied!';
      setTimeout(function(){ btn.textContent = 'Copy'; }, 2000);
    });
  }
}
</script>
</body>
</html>