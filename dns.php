<?php
/**
 * dns.php — DNS Zone Management (user-facing list)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/dns.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = user_currency_symbol($currency);
$app_name = APP_NAME;
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();

// Load user zones
try {
    $st = db()->prepare('SELECT * FROM dns_zones WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC');
    $st->execute([$uid]);
    $zones = $st->fetchAll() ?: [];
} catch (Throwable $e) { $zones = []; }

$total  = count($zones);
$active = count(array_filter($zones, fn($z) => $z['status'] === 'active'));

function zone_badge(string $s): string {
    return match($s) {
        'active'  => '<span class="badge badge-green"><span style="width:5px;height:5px;border-radius:50%;background:#16a34a;display:inline-block"></span> Active</span>',
        'pending' => '<span class="badge badge-yellow">⏳ Pending NS</span>',
        'error'   => '<span class="badge badge-red">✗ Error</span>',
        default   => '<span class="badge badge-gray">'.ucfirst($s).'</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>DNS Management — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .zone-cards{display:flex;flex-direction:column;gap:10px}
    .zone-card{background:white;border:1.5px solid var(--border);border-radius:13px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:all .16s}
    .zone-card:hover{border-color:var(--gray-300);box-shadow:0 4px 16px rgba(0,0,0,.06);transform:translateY(-1px)}
    .zone-icon{width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#e0f2fe,#bae6fd);border:1px solid #7dd3fc;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .zone-info{flex:1;min-width:0}
    .zone-name{font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:3px;display:flex;align-items:center;gap:8px}
    .zone-name a{color:inherit;text-decoration:none}
    .zone-name a:hover{color:var(--primary)}
    .zone-sub{font-size:12px;color:var(--gray-400);display:flex;align-items:center;gap:6px}
    .info-sep{color:var(--gray-300)}
    .zone-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
    .act-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border);background:white;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray-500);transition:all .13s;text-decoration:none;flex-shrink:0}
    .act-btn:hover{background:var(--gray-100);color:var(--gray-900)}
    .act-btn.danger:hover{background:#fef2f2;color:var(--danger);border-color:#fca5a5}
    .act-btn svg{width:14px;height:14px}
    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:9px;font-size:13.5px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:var(--font);text-decoration:none;transition:all .15s;box-shadow:0 2px 8px rgba(103,61,230,.22)}
    .btn-deploy:hover{background:var(--primary-hover);transform:translateY(-1px)}
    .btn-deploy svg{width:14px;height:14px}
    .empty-wrap{background:white;border:1.5px solid var(--border);border-radius:13px;padding:52px 20px;text-align:center}
    .page-topstrip{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap}
    .page-heading{font-size:20px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px}
    @media(max-width:640px){.zone-sub{display:none}.act-btn{width:28px;height:28px}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div style="margin-left:auto">
        <a href="<?= BASE_URL ?>/dns/create.php" class="btn-deploy" style="padding:7px 12px;font-size:12px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Domain
        </a>
      </div>
    </div>

    <div class="topbar">
      <span class="topbar-title">DNS Management</span>
      <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?= $curr_sym . $balance ?>
        </a>
        <a href="<?= BASE_URL ?>/dns/create.php" class="btn-deploy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Domain
        </a>
      </div>
    </div>

    <div style="padding:24px">

      <!-- Stats -->
      <div class="stats-row" style="margin-bottom:20px">
        <div class="stat-card"><div class="stat-label">Total Zones</div><div class="stat-value"><?= $total ?></div></div>
        <div class="stat-card"><div class="stat-label">Active</div><div class="stat-value" style="color:<?= $active>0?'var(--success)':'var(--gray-900)' ?>"><?= $active ?></div></div>
        <div class="stat-card"><div class="stat-label">Pending</div><div class="stat-value"><?= count(array_filter($zones,fn($z)=>$z['status']==='pending')) ?></div></div>
        <div class="stat-card"><div class="stat-label">Records</div><div class="stat-value"><?php try{echo (int)db()->query("SELECT COUNT(*) FROM dns_records WHERE user_id=$uid")->fetchColumn();}catch(Throwable $e){echo 0;} ?></div></div>
      </div>

      <div class="page-topstrip">
        <div>
          <div class="page-heading">My Domains <span style="font-size:14px;font-weight:500;color:var(--gray-400)">(<?= $total ?>)</span></div>
          <div style="font-size:13px;color:var(--gray-500);margin-top:2px">Powered by Cloudflare DNS</div>
        </div>
      </div>

      <?php if (empty($zones)): ?>
      <div class="empty-wrap">
        <div style="width:52px;height:52px;background:linear-gradient(135deg,#e0f2fe,#bae6fd);border-radius:13px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        </div>
        <div style="font-size:17px;font-weight:800;color:var(--gray-900);margin-bottom:7px">No domains yet</div>
        <div style="font-size:13.5px;color:var(--gray-500);max-width:360px;margin:0 auto 22px;line-height:1.65">
          Add your first domain and manage A, CNAME, MX, TXT records — all powered by Cloudflare.
        </div>
        <a href="<?= BASE_URL ?>/dns/create.php" class="btn-deploy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add First Domain
        </a>
      </div>

      <?php else: ?>
      <div class="zone-cards">
        <?php foreach ($zones as $z): ?>
        <div class="zone-card">
          <div class="zone-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          </div>
          <div class="zone-info">
            <div class="zone-name">
              <a href="<?= BASE_URL ?>/dns/manage.php?id=<?= $z['id'] ?>"><?= htmlspecialchars($z['domain']) ?></a>
              <?= zone_badge($z['status']) ?>
            </div>
            <div class="zone-sub">
              <?php try { $rc = (int)db()->query("SELECT COUNT(*) FROM dns_records WHERE zone_id=".(int)$z['id'])->fetchColumn(); echo "<span>{$rc} records</span><span class='info-sep'>·</span>"; } catch(Throwable $e){} ?>
              <span>Added <?= date('d M Y', strtotime($z['created_at'])) ?></span>
              <?php if ($z['status'] === 'pending'): ?>
              <span class="info-sep">·</span>
              <span style="color:#d97706;font-weight:600">Update nameservers at registrar</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="zone-actions">
            <a class="act-btn" title="Manage DNS Records" href="<?= BASE_URL ?>/dns/manage.php?id=<?= $z['id'] ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </a>
            <?php if ($z['status'] === 'pending'): ?>
            <a class="act-btn" title="Check NS Status" href="<?= BASE_URL ?>/dns/manage.php?id=<?= $z['id'] ?>&check=1">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            </a>
            <?php endif; ?>
            <a class="act-btn danger" title="Delete Zone"
               href="<?= BASE_URL ?>/dns/delete.php?id=<?= $z['id'] ?>"
               onclick="return confirm('Delete <?= htmlspecialchars($z['domain'],ENT_QUOTES) ?>? All DNS records will be removed.')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}</script>
</body>
</html>
