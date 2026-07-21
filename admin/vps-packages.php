<?php
/**
 * admin/vps-packages.php
 * WHMCS-style VPS package manager. Each package maps a Virtualizor
 * plan (plid) + node (serid) + default OS (osid) to a sellable product
 * with a monthly price. Users order → server auto-provisions.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user = current_user();
$csrf = csrf_token();
$msg = ''; $err = '';

// Virtualizor providers available to attach packages to
$virt_providers = db()->query(
    "SELECT id, display_name, slug FROM providers
     WHERE provider_type='virtualizor' AND is_active=1 ORDER BY display_name"
)->fetchAll();

// ── AJAX: load plans / nodes / OS from a Virtualizor provider ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load') {
    header('Content-Type: application/json');
    $pid  = (int)($_GET['provider_id'] ?? 0);
    $prov = db()->prepare("SELECT * FROM providers WHERE id=? AND provider_type='virtualizor' LIMIT 1");
    $prov->execute([$pid]);
    $prov = $prov->fetch();
    if (!$prov) { echo json_encode(['ok' => false, 'error' => 'Virtualizor provider not found']); exit; }
    try {
        require_once __DIR__ . '/../providers/virtualizor/client.php';
        require_once __DIR__ . '/../providers/virtualizor/catalog.php';
        $client = new VirtualizorClient($prov['api_key']);
        $cat    = new VirtualizorCatalog($client);
        echo json_encode([
            'ok'    => true,
            'plans' => array_values($cat->plans()),
            'nodes' => array_values($cat->regions()),
            'os'    => array_values($cat->images()),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: create / update / delete ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        db()->prepare('DELETE FROM vps_packages WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        $msg = 'Package deleted.';
    } elseif ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $prov = (int)($_POST['provider_id'] ?? 0);
        $plid = trim($_POST['virt_plid'] ?? '');
        $ser  = trim($_POST['virt_serid'] ?? '');
        $osid = trim($_POST['virt_osid'] ?? '');

        if ($name === '' || !$prov || $plid === '' || $ser === '' || $osid === '') {
            $err = 'Name, provider, plan, node and OS are all required.';
        } else {
            $slug = trim($_POST['slug'] ?? '');
            if ($slug === '') $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $slug = trim($slug, '-') . ($id ? '' : '-' . substr((string)time(), -4));

            $cols = [
                'provider_id'  => $prov,
                'name'         => $name,
                'slug'         => $slug,
                'description'  => trim($_POST['description'] ?? ''),
                'virt_plid'    => $plid,
                'virt_serid'   => $ser,
                'virt_osid'    => $osid,
                'os_label'     => trim($_POST['os_label'] ?? ''),
                'vcpu'         => max(1, (int)($_POST['vcpu'] ?? 1)),
                'ram_gb'       => max(0.5, (float)($_POST['ram_gb'] ?? 1)),
                'disk_gb'      => max(1, (int)($_POST['disk_gb'] ?? 25)),
                'bandwidth_gb' => max(0, (int)($_POST['bandwidth_gb'] ?? 0)),
                'price_inr'    => max(0, (float)($_POST['price_inr'] ?? 0)),
                'price_usd'    => max(0, (float)($_POST['price_usd'] ?? 0)),
                'is_active'    => isset($_POST['is_active']) ? 1 : 0,
                'sort_order'   => (int)($_POST['sort_order'] ?? 0),
            ];

            if ($id) {
                $set = implode(',', array_map(fn($k) => "$k=?", array_keys($cols)));
                $vals = array_values($cols); $vals[] = $id;
                db()->prepare("UPDATE vps_packages SET $set WHERE id=?")->execute($vals);
                $msg = 'Package updated.';
            } else {
                $ph = implode(',', array_fill(0, count($cols), '?'));
                db()->prepare('INSERT INTO vps_packages (' . implode(',', array_keys($cols)) . ") VALUES ($ph)")
                    ->execute(array_values($cols));
                $msg = 'Package created.';
            }
        }
    }
}

// Existing packages
try {
    $packages = db()->query(
        "SELECT p.*, pr.display_name AS provider_name
         FROM vps_packages p LEFT JOIN providers pr ON pr.id=p.provider_id
         ORDER BY p.sort_order, p.name"
    )->fetchAll();
} catch (Throwable $e) {
    $packages = [];
    $err = 'Table vps_packages not found. Run install-db.php first.';
}

function h($v): string { return htmlspecialchars((string)$v); }
?><!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>VPS Packages — <?= APP_NAME ?> Admin</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    .pkg-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .pkg-form-grid .full{grid-column:1/-1}
    .spec-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
    @media(max-width:720px){.pkg-form-grid,.spec-grid{grid-template-columns:1fr 1fr}}
    .pkg-list td .muted{color:var(--gray-400);font-size:12px}
  </style>
</head>
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <span class="adm-mobile-title"><?= APP_NAME ?> <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;margin-left:4px">Admin</span></span>
</div>
<body>
<div class="adm-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <div class="adm-main">
    <div class="adm-topbar"><span class="adm-topbar-title">📦 VPS Packages</span></div>
    <div class="adm-content">

      <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

      <?php if (!$virt_providers): ?>
      <div class="alert alert-warn">
        No active <strong>Virtualizor</strong> provider found. Add one under
        <a href="<?= BASE_URL ?>/admin/index.php?tab=providers">Infrastructure → Cloud Providers</a>
        first (hostname + API key + API pass), then create packages here.
      </div>
      <?php endif; ?>

      <!-- ── Create / edit form ─────────────────────────────── -->
      <div class="card">
        <div class="card-head"><span class="card-title">Create a Package</span></div>
        <div class="card-body">
          <form method="POST" id="pkgForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id" value="">
            <input type="hidden" name="os_label" id="f_os_label" value="">

            <div class="pkg-form-grid">
              <div>
                <label class="flabel">Virtualizor Provider</label>
                <select name="provider_id" id="f_provider" required onchange="loadCatalog()">
                  <option value="">Select provider…</option>
                  <?php foreach ($virt_providers as $vp): ?>
                  <option value="<?= (int)$vp['id'] ?>"><?= h($vp['display_name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="fnote" id="loadNote"></div>
              </div>
              <div>
                <label class="flabel">Package Name</label>
                <input name="name" id="f_name" placeholder="e.g. Cloud VPS 2 vCPU / 4 GB" required>
              </div>

              <div>
                <label class="flabel">Virtualizor Plan</label>
                <select name="virt_plid" id="f_plan" required disabled onchange="applyPlan()">
                  <option value="">Load provider first…</option>
                </select>
              </div>
              <div>
                <label class="flabel">Node / Location</label>
                <select name="virt_serid" id="f_node" required disabled>
                  <option value="">Load provider first…</option>
                </select>
              </div>
              <div>
                <label class="flabel">Default OS Template</label>
                <select name="virt_osid" id="f_os" required disabled onchange="captureOs()">
                  <option value="">Load provider first…</option>
                </select>
              </div>
              <div>
                <label class="flabel">Sort Order</label>
                <input type="number" name="sort_order" id="f_sort" value="0">
              </div>

              <div class="full">
                <label class="flabel">Resources (auto-filled from plan, editable)</label>
                <div class="spec-grid">
                  <div><label class="fnote">vCPU</label><input type="number" name="vcpu" id="f_vcpu" value="1" min="1"></div>
                  <div><label class="fnote">RAM (GB)</label><input type="number" step="0.5" name="ram_gb" id="f_ram" value="1"></div>
                  <div><label class="fnote">Disk (GB)</label><input type="number" name="disk_gb" id="f_disk" value="25"></div>
                  <div><label class="fnote">Bandwidth (GB)</label><input type="number" name="bandwidth_gb" id="f_bw" value="0"></div>
                </div>
              </div>

              <div>
                <label class="flabel">Price — INR / month (₹)</label>
                <input type="number" step="0.01" name="price_inr" id="f_inr" value="0">
              </div>
              <div>
                <label class="flabel">Price — USD / month ($)</label>
                <input type="number" step="0.01" name="price_usd" id="f_usd" value="0">
              </div>

              <div class="full">
                <label class="flabel">Description <span class="fnote">(optional)</span></label>
                <textarea name="description" id="f_desc" rows="2" placeholder="Shown to customers on the order page"></textarea>
              </div>
              <div class="full">
                <label class="check-row"><input type="checkbox" name="is_active" id="f_active" checked> Active (visible to customers)</label>
              </div>
            </div>

            <div class="btn-row" style="margin-top:16px">
              <button type="submit" class="btn btn-primary">Save Package</button>
              <button type="button" class="btn btn-cancel" onclick="resetForm()">Clear</button>
            </div>
          </form>
        </div>
      </div>

      <!-- ── Existing packages ──────────────────────────────── -->
      <div class="card">
        <div class="card-head"><span class="card-title">Packages (<?= count($packages) ?>)</span></div>
        <div class="tbl-wrap"><table class="tbl pkg-list">
          <thead><tr><th>Name</th><th>Provider</th><th>Specs</th><th>Plan / Node</th><th>Price /mo</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$packages): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--gray-400);padding:22px">No packages yet.</td></tr>
          <?php else: foreach ($packages as $p): ?>
            <tr>
              <td style="font-weight:700"><?= h($p['name']) ?><div class="muted"><?= h($p['slug']) ?></div></td>
              <td><?= h($p['provider_name'] ?? '—') ?></td>
              <td><?= (int)$p['vcpu'] ?> vCPU · <?= h($p['ram_gb']) ?> GB · <?= (int)$p['disk_gb'] ?> GB</td>
              <td class="muted">plid <?= h($p['virt_plid']) ?> · node <?= h($p['virt_serid']) ?></td>
              <td style="font-family:var(--mono)">₹<?= number_format((float)$p['price_inr'],0) ?> · $<?= number_format((float)$p['price_usd'],2) ?></td>
              <td><span class="badge <?= $p['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $p['is_active'] ? 'Active' : 'Hidden' ?></span></td>
              <td style="white-space:nowrap">
                <button class="btn btn-ghost btn-sm" onclick='editPkg(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this package?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>

    </div>
  </div>
</div>

<script>
var CATALOG = { plans: [], nodes: [], os: [] };
var BASEP = '<?= BASE_URL ?>/admin/vps-packages.php';

function loadCatalog(cb) {
  var pid = document.getElementById('f_provider').value;
  var note = document.getElementById('loadNote');
  ['f_plan','f_node','f_os'].forEach(function(id){ document.getElementById(id).disabled = true; });
  if (!pid) { note.textContent = ''; return; }
  note.textContent = 'Loading plans / nodes / OS from Virtualizor…';
  fetch(BASEP + '?ajax=load&provider_id=' + encodeURIComponent(pid))
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d.ok) { note.innerHTML = '<span style="color:var(--danger)">'+ (d.error||'Failed to load') +'</span>'; return; }
      CATALOG = d;
      fillSelect('f_plan', d.plans, function(p){ return [p.slug, (p.label||p.name||('Plan '+p.slug)) + ' — ' + (p.vcpu||'?') + 'C/' + (p.ram_gb||'?') + 'G/' + (p.disk_gb||'?') + 'G']; });
      fillSelect('f_node', d.nodes, function(n){ return [n.slug, (n.label||n.name||('Node '+n.slug))]; });
      fillSelect('f_os',   d.os,    function(o){ return [o.slug, (o.name||o.label||o.slug)]; });
      ['f_plan','f_node','f_os'].forEach(function(id){ document.getElementById(id).disabled = false; });
      note.innerHTML = '<span style="color:var(--success)">Loaded '+ d.plans.length +' plans, '+ d.nodes.length +' nodes, '+ d.os.length +' OS.</span>';
      if (cb) cb();
    })
    .catch(function(){ note.innerHTML = '<span style="color:var(--danger)">Network error.</span>'; });
}

function fillSelect(id, arr, mapper) {
  var sel = document.getElementById(id);
  sel.innerHTML = '<option value="">Select…</option>';
  (arr||[]).forEach(function(item){
    var kv = mapper(item);
    var o = document.createElement('option');
    o.value = kv[0]; o.textContent = kv[1];
    sel.appendChild(o);
  });
}

function applyPlan() {
  var plid = document.getElementById('f_plan').value;
  var p = (CATALOG.plans||[]).find(function(x){ return String(x.slug) === String(plid); });
  if (!p) return;
  if (p.vcpu)    document.getElementById('f_vcpu').value = p.vcpu;
  if (p.ram_gb)  document.getElementById('f_ram').value  = p.ram_gb;
  if (p.disk_gb) document.getElementById('f_disk').value = p.disk_gb;
}
function captureOs() {
  var sel = document.getElementById('f_os');
  document.getElementById('f_os_label').value = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
}

function resetForm() {
  document.getElementById('pkgForm').reset();
  document.getElementById('f_id').value = '';
}

function editPkg(p) {
  document.getElementById('f_id').value       = p.id;
  document.getElementById('f_name').value     = p.name;
  document.getElementById('f_provider').value = p.provider_id;
  document.getElementById('f_sort').value     = p.sort_order;
  document.getElementById('f_vcpu').value     = p.vcpu;
  document.getElementById('f_ram').value      = p.ram_gb;
  document.getElementById('f_disk').value     = p.disk_gb;
  document.getElementById('f_bw').value       = p.bandwidth_gb;
  document.getElementById('f_inr').value      = p.price_inr;
  document.getElementById('f_usd').value      = p.price_usd;
  document.getElementById('f_desc').value     = p.description || '';
  document.getElementById('f_os_label').value = p.os_label || '';
  document.getElementById('f_active').checked = p.is_active == 1;
  // Load catalog then preselect plan/node/os
  loadCatalog(function(){
    document.getElementById('f_plan').value = p.virt_plid;
    document.getElementById('f_node').value = p.virt_serid;
    document.getElementById('f_os').value   = p.virt_osid;
  });
  window.scrollTo({top:0, behavior:'smooth'});
}
</script>
</body>
</html>
