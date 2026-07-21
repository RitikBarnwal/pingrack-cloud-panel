<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/dns.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$app_name = APP_NAME;
$uname    = htmlspecialchars($user['username']);
$csrf     = csrf_token();
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = number_format((float)$user['wallet_balance'], 2);
$curr_sym = user_currency_symbol(strtoupper($user['currency'] ?? 'INR'));
$error = '';

if (!dns_is_configured()) {
    $error = 'DNS service not configured. Please contact support.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $domain = strtolower(trim($_POST['domain'] ?? ''));
    // Validate domain
    if (!$domain) {
        $error = 'Domain name is required.';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]?\.[a-z]{2,}$/i', $domain)) {
        $error = 'Invalid domain name. Example: example.com';
    } else {
        // Check duplicate
        $ex = db()->prepare('SELECT id FROM dns_zones WHERE user_id=? AND domain=? AND deleted_at IS NULL LIMIT 1');
        $ex->execute([$uid, $domain]);
        if ($ex->fetchColumn()) $error = 'This domain is already added to your account.';
    }
    if (!$error) {
        try {
            $zone_data = dns_add_zone($domain);
            db()->prepare(
                'INSERT INTO dns_zones (user_id, domain, cf_zone_id, status, nameservers, created_at)
                 VALUES (?,?,?,?,?,NOW())'
            )->execute([
                $uid, $domain,
                $zone_data['cf_zone_id'],
                $zone_data['status'],
                $zone_data['nameservers'],
            ]);
            $new_id = (int)db()->lastInsertId();
            header('Location: ' . BASE_URL . '/dns/manage.php?id=' . $new_id . '&new=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Failed to add domain: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Add Domain — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .cv-wrap{max-width:600px;margin:0 auto}
    .cv-sec{background:white;border:1.5px solid var(--border);border-radius:13px;padding:20px;margin-bottom:14px}
    .cv-sec-hd{display:flex;align-items:center;gap:10px;margin-bottom:16px}
    .cv-num{width:26px;height:26px;border-radius:7px;background:var(--primary);color:white;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .cv-sec-title{font-size:14.5px;font-weight:800;color:var(--gray-900)}
    .big-input{padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;color:var(--gray-900);outline:none;transition:all .14s;width:100%;font-family:var(--mono)}
    .big-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .big-input.ok{border-color:var(--success)}
    .big-input.bad{border-color:var(--danger)}
    .field-hint{font-size:12px;margin-top:5px;display:flex;align-items:center;gap:4px;color:var(--gray-400)}
    .field-hint svg{width:12px;height:12px;flex-shrink:0}
    .btn-deploy{display:inline-flex;align-items:center;gap:6px;padding:11px 24px;border-radius:9px;font-size:14px;font-weight:700;background:var(--primary);color:white;border:none;cursor:pointer;font-family:var(--font);text-decoration:none;transition:all .15s;box-shadow:0 2px 8px rgba(103,61,230,.22)}
    .btn-deploy:hover{background:var(--primary-hover)}
    .err-banner{background:#fef2f2;border:1px solid #fca5a5;border-radius:9px;padding:11px 14px;font-size:13px;color:#dc2626;font-weight:600;margin-bottom:16px;display:flex;gap:8px}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
    </div>
    <div class="topbar">
      <a href="<?= BASE_URL ?>/dns.php" style="color:var(--gray-400);text-decoration:none;font-size:13px">← DNS</a>
      <span style="color:var(--gray-300);margin:0 8px">/</span>
      <span class="topbar-title">Add Domain</span>
      <div style="margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm"><?= $curr_sym . $balance ?></a>
      </div>
    </div>
    <div style="padding:24px">
      <div class="cv-wrap">

        <?php if ($error): ?>
        <div class="err-banner">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Info -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:16px;display:flex;gap:10px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          <div style="font-size:13px;color:#1d4ed8;line-height:1.65">
            Add your domain here, then <strong>change nameservers</strong> at your registrar (GoDaddy, Namecheap etc.) to the ones we provide. Full DNS management powered by Cloudflare.
          </div>
        </div>

        <form method="POST" id="addDomainForm">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">1</div>
              <div class="cv-sec-title">Enter Domain Name</div>
            </div>
            <input name="domain" id="domain_inp" class="big-input" placeholder="example.com"
                   value="<?= htmlspecialchars($_POST['domain'] ?? '') ?>"
                   oninput="validateDomain(this)" autofocus required>
            <div class="field-hint" id="domain-hint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              Root domain only — example.com (not www.example.com)
            </div>
          </div>

          <div class="cv-sec">
            <div class="cv-sec-hd">
              <div class="cv-num">2</div>
              <div class="cv-sec-title">What happens next?</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px">
              <?php foreach ([
                ['🌐','Domain added to Cloudflare','Your zone is created instantly'],
                ['📋','Nameservers provided','2 NS records to update at your registrar'],
                ['⏱','Propagation time','DNS updates in 5–30 minutes usually'],
                ['✅','Manage records','Add A, CNAME, MX, TXT records from here'],
              ] as [$icon,$title,$sub]): ?>
              <div style="display:flex;align-items:flex-start;gap:11px">
                <div style="width:32px;height:32px;background:var(--gray-100);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0"><?= $icon ?></div>
                <div>
                  <div style="font-size:13px;font-weight:700;color:var(--gray-900)"><?= $title ?></div>
                  <div style="font-size:12px;color:var(--gray-500);margin-top:1px"><?= $sub ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="display:flex;gap:10px">
            <button type="submit" class="btn-deploy" <?= !dns_is_configured() ? 'disabled' : '' ?>>
              <svg style="width: 18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add Domain
            </button>
            <a href="<?= BASE_URL ?>/dns.php" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}
function validateDomain(inp) {
    var v = inp.value.trim();
    var ok = /^[a-z0-9][a-z0-9\-]{0,61}[a-z0-9]?\.[a-z]{2,}$/i.test(v);
    inp.className = 'big-input' + (v.length > 3 ? (ok ? ' ok' : ' bad') : '');
    var h = document.getElementById('domain-hint');
    h.style.color = v.length > 3 ? (ok ? '#16a34a' : '#dc2626') : '';
    h.childNodes[1].textContent = v.length > 3 ? (ok ? ' Looks good!' : ' Enter root domain only, e.g. example.com') : ' Root domain only — example.com (not www.example.com)';
}

document.getElementById('addDomainForm').addEventListener('submit', function () {
    const btn = this.querySelector('.btn-deploy');

    btn.disabled = true;
    btn.innerHTML = `
        <span class="spinner"></span>
        Adding...
    `;
});
</script>
</body>
</html>
