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

/* ── POST ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    /* ── Create firewall ─────────────────────────────────────── */
    if ($action === 'create_firewall') {
        $name       = trim($_POST['fw_name'] ?? '');
        $directions = $_POST['rule_direction'] ?? [];
        $protocols  = $_POST['rule_protocol']  ?? [];
        $ports      = $_POST['rule_port']      ?? [];
        $sources    = $_POST['rule_source']    ?? [];

        $rules = [];
        foreach ($directions as $i => $dir) {
            $proto = $protocols[$i] ?? '';
            if (!$proto) continue;

            // Hetzner: direction must be "in" or "out"
            $dir = ($dir === 'out') ? 'out' : 'in';

            // Build rule base
            $rule = [
                'direction' => $dir,
                'protocol'  => $proto,
            ];

            // Port — NOT included for icmp/esp/gre
            if (!in_array($proto, ['icmp', 'esp', 'gre'])) {
                $port = trim($ports[$i] ?? '');
                if ($port !== '') $rule['port'] = $port;
            }

            // source_ips for inbound, destination_ips for outbound
            $raw_ips = trim($sources[$i] ?? '');
            $ip_list = $raw_ips !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $raw_ips))))
                : ['0.0.0.0/0', '::/0'];

            if ($dir === 'in') {
                $rule['source_ips'] = $ip_list;
            } else {
                $rule['destination_ips'] = $ip_list;
            }

            $rules[] = $rule;
        }

        if (!$name) {
            $err = 'Firewall name is required.';
        } elseif (empty($rules)) {
            $err = 'Add at least one rule.';
        } else {
            try {
                // Store in DB — applied to servers at deploy/assign time
                // No provider API needed for panel-side management
                db()->prepare(
                    'INSERT INTO firewalls (user_id, provider_id, name, rules) VALUES (?, NULL, ?, ?)'
                )->execute([
                    $user['id'],
                    $name,
                    json_encode($rules),
                ]);
                $msg = "Firewall {$name} created successfully.";
            } catch (Throwable $e) {
                $err = 'Could not save firewall: ' . $e->getMessage();
            }
        }
    }

    /* ── Delete firewall ─────────────────────────────────────── */
    if ($action === 'delete_firewall') {
        $fwid = (int)($_POST['fw_id'] ?? 0);
        $fw   = db()->prepare('SELECT * FROM firewalls WHERE id=? AND user_id=? LIMIT 1');
        $fw->execute([$fwid, $user['id']]);
        $fw = $fw->fetch();

        if (!$fw) {
            $err = 'Firewall not found.';
        } else {
            db()->prepare('DELETE FROM firewalls WHERE id=? AND user_id=?')->execute([$fwid, $user['id']]);
            $msg = "Firewall {$fw['name']} deleted.";
        }
    }
}

// Load firewalls
$firewalls = db()->prepare('SELECT * FROM firewalls WHERE user_id=? ORDER BY created_at DESC');
$firewalls->execute([$user['id']]);
$firewalls = $firewalls->fetchAll() ?: [];

$common_rules = [
    ['in',  'tcp', '22',   'SSH (22)'],
    ['in',  'tcp', '80',   'HTTP (80)'],
    ['in',  'tcp', '443',  'HTTPS (443)'],
    ['in',  'tcp', '8080', 'HTTP Alt (8080)'],
    ['in',  'tcp', '3306', 'MySQL (3306)'],
    ['in',  'tcp', '5432', 'PostgreSQL (5432)'],
    ['in',  'tcp', '6379', 'Redis (6379)'],
    ['in',  'icmp','',     'Ping (ICMP)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Firewalls — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .page-wrap{padding:24px;max-width:880px}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:20px}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}

    /* Firewall list cards */
    .fw-card{background:white;border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:12px;transition:border-color .15s}
    .fw-card:hover{border-color:var(--gray-300)}
    .fw-card-head{padding:14px 18px;display:flex;align-items:center;gap:12px;cursor:pointer}
    .fw-icon{width:36px;height:36px;border-radius:9px;background:#fff7ed;border:1px solid #fed7aa;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .fw-icon svg{width:17px;height:17px;color:#d97706}
    .fw-name{font-size:14px;font-weight:800;color:var(--gray-900)}
    .fw-meta{font-size:12px;color:var(--gray-400);margin-top:2px}
    .fw-body{display:none;padding:0 18px 14px}
    .fw-body.open{display:block}

    /* Rule table */
    .rtbl{width:100%;border-collapse:collapse;font-size:12.5px;margin-bottom:14px}
    .rtbl thead th{padding:7px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .rtbl tbody td{padding:9px 10px;border-bottom:1px solid var(--gray-100);color:var(--gray-700);vertical-align:middle}
    .rtbl tbody tr:last-child td{border:none}
    .dir-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700}
    .dir-in{background:#f0fdf4;color:#16a34a}
    .dir-out{background:#eff6ff;color:#2563eb}
    .proto-badge{display:inline-block;padding:2px 7px;border-radius:4px;background:var(--gray-100);font-size:11px;font-weight:700;font-family:monospace;color:var(--gray-700);text-transform:uppercase}

    /* Rule builder */
    .rule-builder{border:1.5px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px}
    .rb-head{display:grid;grid-template-columns:80px 90px 110px 1fr 34px;gap:8px;padding:8px 12px;background:var(--gray-50);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400)}
    .rule-row{display:grid;grid-template-columns:80px 90px 110px 1fr 34px;gap:8px;padding:8px 12px;align-items:center;border-top:1px solid var(--gray-100)}
    .rs,.ri{width:100%;padding:6px 8px;border:1.5px solid var(--border);border-radius:7px;font-family:inherit;font-size:12.5px;color:var(--gray-900);outline:none;transition:border-color .13s;background:white}
    .rs:focus,.ri:focus{border-color:var(--primary)}
    .rm-btn{width:30px;height:30px;border:1px solid #fca5a5;background:white;border-radius:7px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--danger);flex-shrink:0;transition:all .13s}
    .rm-btn:hover{background:#fef2f2}
    .rm-btn svg{width:12px;height:12px}

    /* Quick rules */
    .qr-wrap{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
    .qr-btn{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;font-size:12px;font-weight:600;color:var(--gray-600);background:white;cursor:pointer;transition:all .13s;font-family:inherit}
    .qr-btn:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}

    /* Buttons */
    .btn-p{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-p:hover{background:var(--primary-hover)}
    .btn-g{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:white;color:var(--gray-700);border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .14s}
    .btn-g:hover{background:var(--gray-50)}
    .btn-d{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border:1px solid #fca5a5;background:white;color:var(--danger);border-radius:7px;font-size:12.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .13s}
    .btn-d:hover{background:#fef2f2}

    .empty-state{padding:40px 20px;text-align:center}

    /* Note box */
    .info-note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px 14px;font-size:12.5px;color:#1d4ed8;margin-bottom:16px}

    @media(max-width:640px){.rb-head,.rule-row{grid-template-columns:1fr 1fr;gap:6px}.rb-head div:nth-child(3),.rb-head div:nth-child(4),.rb-head div:nth-child(5){display:none}}
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
      <span style="font-weight:800;font-size:15px">Firewalls</span>
    </div>
    <div class="topbar"><span class="topbar-title">Firewalls</span></div>

    <div class="page-wrap">

      <?php if ($msg): ?><div class="alert alert-success" style="margin-bottom:16px"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error"  style="margin-bottom:16px"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <!-- Create firewall -->
      <div class="card">
        <div class="card-head"><span class="card-title">Create Firewall</span></div>
        <div class="card-body">

          <div class="info-note">
            <strong>Firewall Rules:</strong>
            Direction <code>IN</code> = inbound traffic (use Source IPs) · <code>OUT</code> = outbound (use Destination IPs).
            ICMP rules don't need a port. Leave Source/Dest blank for all IPs.
          </div>

          <form method="POST" id="fw-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create_firewall">

            <div style="margin-bottom:16px;max-width:340px">
              <label class="flabel">Firewall Name</label>
              <input type="text" name="fw_name" class="form-control" placeholder="my-web-server-fw" required>
            </div>

            <!-- Quick rules -->
            <div style="font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:7px">Quick Add</div>
            <div class="qr-wrap">
              <?php foreach ($common_rules as [$dir,$proto,$port,$label]): ?>
              <button type="button" class="qr-btn" onclick="addQuickRule('<?= $dir ?>','<?= $proto ?>','<?= $port ?>')">+ <?= $label ?></button>
              <?php endforeach; ?>
            </div>

            <!-- Rule builder -->
            <div class="rule-builder">
              <div class="rb-head">
                <div>Direction</div><div>Protocol</div><div>Port</div><div>Source / Dest IPs</div><div></div>
              </div>
              <div id="rules-body">
                <!-- Default: allow SSH inbound -->
                <div class="rule-row" id="rule-0">
                  <select name="rule_direction[]" class="rs" onchange="onDirChange(this)">
                    <option value="in"  selected>IN</option>
                    <option value="out">OUT</option>
                  </select>
                  <select name="rule_protocol[]" class="rs" onchange="onProtoChange(this)">
                    <option value="tcp"  selected>TCP</option>
                    <option value="udp">UDP</option>
                    <option value="icmp">ICMP</option>
                    <option value="esp">ESP</option>
                    <option value="gre">GRE</option>
                  </select>
                  <input type="text" name="rule_port[]" class="ri" value="22" placeholder="22 or 80-443">
                  <input type="text" name="rule_source[]" class="ri" placeholder="0.0.0.0/0, ::/0 (blank = all)">
                  <button type="button" class="rm-btn" onclick="removeRule(this)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </div>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button type="button" class="btn-g" onclick="addRule()">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Rule
              </button>
              <button type="submit" data-loading="Creating..." class="btn-p">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Create Firewall
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Firewall list -->
      <div style="font-size:14px;font-weight:800;color:var(--gray-900);margin-bottom:12px">
        Your Firewalls <span style="font-size:13px;font-weight:500;color:var(--gray-400)">(<?= count($firewalls) ?>)</span>
      </div>

      <?php if (empty($firewalls)): ?>
      <div class="card">
        <div class="empty-state">
          <div style="font-size:36px;margin-bottom:12px">🛡️</div>
          <div style="font-size:15px;font-weight:800;color:var(--gray-700);margin-bottom:5px">No firewalls yet</div>
          <div style="font-size:13px;color:var(--gray-500)">Create a firewall above to control traffic to your servers.</div>
        </div>
      </div>
      <?php else: ?>
      <?php foreach ($firewalls as $fw):
        $rules = json_decode($fw['rules'] ?? '[]', true) ?: [];
      ?>
      <div class="fw-card">
        <div class="fw-card-head" onclick="toggleFw(<?= $fw['id'] ?>)">
          <div class="fw-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <div style="flex:1">
            <div class="fw-name"><?= htmlspecialchars($fw['name']) ?></div>
            <div class="fw-meta"><?= count($rules) ?> rule<?= count($rules)!==1?'s':'' ?> · Created <?= date('d M Y', strtotime($fw['created_at'])) ?></div>
          </div>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"
               id="fw-arr-<?= $fw['id'] ?>" style="transition:transform .2s;flex-shrink:0">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <div class="fw-body" id="fw-body-<?= $fw['id'] ?>">
          <?php if ($rules): ?>
          <table class="rtbl">
            <thead><tr><th>Direction</th><th>Protocol</th><th>Port</th><th>Source / Dest</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $r):
              $dir     = $r['direction'] ?? 'in';
              $proto   = strtoupper($r['protocol'] ?? 'TCP');
              $port    = $r['port']        ?? null;
              $ips     = $r['source_ips']  ?? $r['destination_ips'] ?? [];
              $ips_str = implode(', ', (array)$ips);
            ?>
            <tr>
              <td><span class="dir-badge <?= $dir==='in'?'dir-in':'dir-out' ?>"><?= strtoupper($dir) ?></span></td>
              <td><span class="proto-badge"><?= htmlspecialchars($proto) ?></span></td>
              <td style="font-family:monospace;font-size:12.5px"><?= $port ? htmlspecialchars($port) : '<span style="color:var(--gray-400)">any</span>' ?></td>
              <td style="font-size:12px;font-family:monospace;color:var(--gray-600);word-break:break-all"><?= htmlspecialchars($ips_str ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div style="padding:10px 0;font-size:13px;color:var(--gray-400)">No rules defined.</div>
          <?php endif; ?>

          <form method="POST" onsubmit="return confirm('Delete firewall \'<?= htmlspecialchars(addslashes($fw['name'])) ?>\'? This will also remove it from Het.')">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_firewall">
            <input type="hidden" name="fw_id" value="<?= $fw['id'] ?>">
            <button type="submit" class="btn-d">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
              Delete Firewall
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</div>
<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<script>
var ruleCount = 1;

/* ── Add rule row ────────────────────────────────────────────── */
function addRule(dir, proto, port) {
  var idx = ruleCount++;
  var row = document.createElement('div');
  row.className = 'rule-row';
  row.id = 'rule-' + idx;

  var dirOpts = ['in','out'].map(function(v){
    return '<option value="'+v+'"'+(v===(dir||'in')?' selected':'')+'>'+v.toUpperCase()+'</option>';
  }).join('');

  var protoOpts = ['tcp','udp','icmp','esp','gre'].map(function(v){
    return '<option value="'+v+'"'+(v===(proto||'tcp')?' selected':'')+'>'+v.toUpperCase()+'</option>';
  }).join('');

  var portDisabled = (proto === 'icmp' || proto === 'esp' || proto === 'gre') ? ' disabled style="opacity:.4"' : '';

  row.innerHTML =
    '<select name="rule_direction[]" class="rs" onchange="onDirChange(this)">'+dirOpts+'</select>' +
    '<select name="rule_protocol[]" class="rs" onchange="onProtoChange(this)">'+protoOpts+'</select>' +
    '<input type="text" name="rule_port[]" class="ri" value="'+(port||'')+'" placeholder="22 or 80-443"'+portDisabled+'>' +
    '<input type="text" name="rule_source[]" class="ri" placeholder="0.0.0.0/0, ::/0 (blank = all)">' +
    '<button type="button" class="rm-btn" onclick="removeRule(this)">'+
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'+
    '</button>';

  document.getElementById('rules-body').appendChild(row);
}

function addQuickRule(dir, proto, port) { addRule(dir, proto, port); }

function removeRule(btn) {
  var rows = document.querySelectorAll('#rules-body .rule-row');
  if (rows.length > 1) btn.closest('.rule-row').remove();
}

/* ── Protocol change — disable port for ICMP/ESP/GRE ────────── */
function onProtoChange(sel) {
  var row      = sel.closest('.rule-row');
  var portInp  = row.querySelector('input[name="rule_port[]"]');
  var noPort   = ['icmp','esp','gre'].includes(sel.value);
  portInp.disabled = noPort;
  portInp.style.opacity = noPort ? '.35' : '1';
  if (noPort) portInp.value = '';
}

/* ── Direction change — update placeholder ───────────────────── */
function onDirChange(sel) {
  var row   = sel.closest('.rule-row');
  var srcIn = row.querySelector('input[name="rule_source[]"]');
  srcIn.placeholder = sel.value === 'in'
    ? '0.0.0.0/0, ::/0 (source IPs)'
    : '0.0.0.0/0, ::/0 (dest IPs)';
}

/* ── Toggle firewall card ────────────────────────────────────── */
function toggleFw(id) {
  var body  = document.getElementById('fw-body-' + id);
  var arrow = document.getElementById('fw-arr-'  + id);
  var open  = body.classList.toggle('open');
  arrow.style.transform = open ? 'rotate(180deg)' : '';
}

/* ── Apply onProtoChange to initial rows ─────────────────────── */
document.querySelectorAll('.rule-row select[name="rule_protocol[]"]').forEach(function(s){
  s.addEventListener('change', function(){ onProtoChange(this); });
});
document.querySelectorAll('.rule-row select[name="rule_direction[]"]').forEach(function(s){
  s.addEventListener('change', function(){ onDirChange(this); });
});
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