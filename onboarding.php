<?php
// CloudVault – User Onboarding (VPS Edition)
// Ek baar dikhega first login ke baad
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();

// Already onboarded? Dashboard pe bhejo
if ((int)$user['onboarded'] === 1) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

// Finish POST handle karo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finish'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $st = db()->prepare('UPDATE users SET onboarded = 1 WHERE id = ?');
        $st->execute([$user['id']]);
        $_SESSION['show_confetti'] = true;
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
}

$csrf      = csrf_token();
$app_name  = APP_NAME;
$fname     = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname     = htmlspecialchars($user['username']);
$avatar    = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$currency  = strtoupper($user['currency'] ?? 'USD');
$country   = strtoupper($user['country']  ?? 'US');

// Currency display
$curr_symbol = $currency === 'INR' ? '₹' : '$';
$curr_label  = $currency === 'INR' ? 'Indian Rupee (₹ INR)' : 'US Dollar ($ USD)';
$curr_flag   = $currency === 'INR' ? 'IN' : 'US';

// Flag CDN helper — flagcdn.com
function flag_url(string $code, int $w = 20): string {
    return 'https://flagcdn.com/w' . $w . '/' . strtolower($code) . '.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Welcome — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── Shell ───────────────────────────────── */
    .ob-shell {
      min-height: 100vh;
      background: var(--gray-50);
      display: flex; align-items: center; justify-content: center;
      padding: 24px; position: relative; overflow: hidden;
    }
    .ob-shell::before {
      content: '';
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image:
        linear-gradient(rgba(37,99,235,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(37,99,235,.03) 1px, transparent 1px);
      background-size: 48px 48px;
    }
    .ob-blob {
      position: fixed; pointer-events: none; z-index: 0; border-radius: 50%;
    }
    .ob-blob-1 {
      top: -100px; right: -100px; width: 440px; height: 440px;
      background: radial-gradient(circle, rgba(37,99,235,.09) 0%, transparent 70%);
    }
    .ob-blob-2 {
      bottom: -80px; left: -80px; width: 380px; height: 380px;
      background: radial-gradient(circle, rgba(6,182,212,.07) 0%, transparent 70%);
    }

    /* ── Card ────────────────────────────────── */
    .ob-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 40px 38px 34px;
      max-width: 520px; width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,.08), 0 2px 8px rgba(0,0,0,.04);
      position: relative; z-index: 1;
    }

    /* ── Logo ────────────────────────────────── */
    .ob-logo {
      display: flex; align-items: center; gap: 9px; margin-bottom: 28px;
    }
    .ob-logo-mark {
      width: 33px; height: 33px; border-radius: 8px;
      background: var(--primary); display: flex;
      align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ob-logo-mark svg { width: 17px; height: 17px; }
    .ob-logo-text { font-weight: 800; font-size: 16px; color: var(--gray-900); letter-spacing: -.3px; }

    /* ── Progress ────────────────────────────── */
    .ob-progress { margin-bottom: 30px; }
    .ob-dots { display: flex; gap: 6px; align-items: center; margin-bottom: 7px; }
    .ob-dot {
      height: 4px; border-radius: 99px; background: var(--gray-200);
      transition: all .4s cubic-bezier(0.34, 1.56, 0.64, 1); width: 22px;
    }
    .ob-dot.active { background: var(--primary); width: 38px; }
    .ob-dot.done   { background: #16a34a; }
    .ob-step-label {
      font-size: 10.5px; font-weight: 700; color: var(--gray-400);
      text-transform: uppercase; letter-spacing: .8px;
    }

    /* ── Steps ───────────────────────────────── */
    .ob-step { display: none; }
    .ob-step.active { display: block; animation: ob-in .32s ease both; }
    @keyframes ob-in {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .ob-title {
      font-size: 21px; color: var(--gray-900);
      letter-spacing: -.5px; line-height: 1.2; margin-bottom: 6px;
    }
    .ob-sub {
      font-size: 13.5px; color: var(--gray-500); line-height: 1.65; margin-bottom: 26px;
    }

    /* ── Feature list ────────────────────────── */
    .ob-list { list-style: none; margin-bottom: 26px; }
    .ob-list li {
      display: flex; align-items: flex-start; gap: 13px;
      padding: 12px 0; border-bottom: 1px solid var(--gray-100);
      transition: transform .18s;
    }
    .ob-list li:hover { transform: translateX(3px); }
    .ob-list li:last-child { border: none; }
    .ob-icon {
      width: 40px; height: 40px; border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
      font-size: 19px; flex-shrink: 0;
    }
    .ob-feat-name { font-size: 13.5px; font-weight: 700; color: var(--gray-900); margin-bottom: 3px; }
    .ob-feat-desc { font-size: 12.5px; color: var(--gray-500); line-height: 1.5; }

    /* ── Info cards (slide 2) ────────────────── */
    .ob-info-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 11px; margin-bottom: 22px;
    }
    .ob-info-card {
      background: var(--gray-50); border: 1.5px solid var(--border);
      border-radius: 12px; padding: 15px 14px; transition: all .18s;
    }
    .ob-info-card:hover { border-color: var(--primary); background: var(--primary-light); }
    .ob-info-label {
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .8px; color: var(--gray-400); margin-bottom: 5px;
    }
    .ob-info-val {
      font-size: 17px; font-weight: 900; color: var(--gray-900);
      letter-spacing: -.5px; font-family: 'JetBrains Mono', monospace;
      line-height: 1.2;
    }
    .ob-info-sub { font-size: 11px; color: var(--gray-400); margin-top: 3px; }

    /* ── Currency badge (slide 3) ────────────── */
    .ob-currency-box {
      display: flex; align-items: center; gap: 14px;
      background: var(--primary-light); border: 1.5px solid #bfdbfe;
      border-radius: 13px; padding: 16px 18px; margin-bottom: 18px;
    }
    .ob-currency-flag {
      width: 36px; height: 36px; border-radius: 8px; overflow: hidden;
      border: 1.5px solid rgba(37,99,235,.15); flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: white;
    }
    .ob-currency-flag img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .ob-currency-name { font-size: 14px; font-weight: 800; color: #1d4ed8; }
    .ob-currency-note { font-size: 12px; color: #3b82f6; margin-top: 2px; line-height: 1.45; }

    .ob-billing-steps { list-style: none; margin-bottom: 22px; }
    .ob-billing-steps li {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 10px 0; border-bottom: 1px solid var(--gray-100); font-size: 13px;
    }
    .ob-billing-steps li:last-child { border: none; }
    .ob-step-num {
      width: 22px; height: 22px; border-radius: 6px; background: var(--primary);
      color: white; font-size: 11px; font-weight: 800;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px;
    }
    .ob-billing-steps li strong { color: var(--gray-800); font-weight: 700; }
    .ob-billing-steps li span  { color: var(--gray-500); display: block; font-size: 12px; margin-top: 2px; }

    /* ── Checklist (slide 4) ──────────────────── */
    .ob-rocket-wrap { text-align: center; padding: 16px 0 24px; }
    .ob-rocket {
      font-size: 58px; line-height: 1; display: block; margin-bottom: 14px;
      animation: float 3s ease-in-out infinite;
      justify-items: anchor-center;
    }
    @keyframes float {
      0%,100% { transform: translateY(0); }
      50%      { transform: translateY(-9px); }
    }
    .ob-rocket-title { font-size: 24px; font-weight: 900; letter-spacing: -1px; color: var(--gray-900); margin-bottom: 7px; }
    .ob-rocket-sub   { font-size: 13.5px; color: var(--gray-500); line-height: 1.6; max-width: 320px; margin: 0 auto 22px; }
    .ob-checklist { list-style: none; text-align: left; max-width: 290px; margin: 0 auto 24px; }
    .ob-checklist li {
      font-size: 13px; color: var(--gray-600); padding: 6px 0;
      display: flex; align-items: center; gap: 10px; font-weight: 500;
    }
    .ob-check {
      width: 20px; height: 20px; border-radius: 50%;
      background: #16a34a; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ob-check svg { width: 11px; height: 11px; }

    /* ── Buttons ──────────────────────────────── */
    .ob-row { display: flex; gap: 9px; }
    .ob-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 7px;
      padding: 11px 18px; border-radius: 9px; font-size: 13.5px; font-weight: 700;
      font-family: inherit; cursor: pointer; border: none; transition: all .18s;
      text-decoration: none;
    }
    .ob-btn-primary { background: var(--primary); color: white; flex: 1; }
    .ob-btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 5px 14px rgba(37,99,235,.3); }
    .ob-btn-ghost { background: transparent; color: var(--gray-600); border: 1.5px solid var(--border); }
    .ob-btn-ghost:hover { background: var(--gray-100); color: var(--gray-900); }

    .ob-btn-launch {
      width: 100%; padding: 14px; font-size: 15px; font-weight: 800; letter-spacing: -.2px;
      background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover)100%);
      color: white; border-radius: 11px; font-family: inherit;
      cursor: pointer; border: none; transition: all .2s;
      box-shadow: 0 4px 16px var(--primary-hover);
      display: flex; align-items: center; justify-content: center; gap: 9px;
    }
    .ob-btn-launch:hover { transform: translateY(-2px); box-shadow: 0 4px 16px var(--primary-hover); }
    .ob-btn-launch:active { transform: translateY(0); }
    .ob-btn-back-sm {
      background: none; border: none; cursor: pointer; font-family: inherit;
      font-size: 13px; color: var(--gray-400); font-weight: 600;
      display: flex; align-items: center; gap: 5px; margin: 10px auto 0; padding: 6px 10px;
      border-radius: 7px; transition: all .15s;
    }
    .ob-btn-back-sm:hover { color: var(--gray-700); background: var(--gray-100); }

    @media (max-width: 540px) {
      .ob-card { padding: 28px 18px 24px; }
      .ob-info-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    }
  </style>
</head>
<body>
<div class="ob-shell">
  <div class="ob-blob ob-blob-1"></div>
  <div class="ob-blob ob-blob-2"></div>

  <div class="ob-card">

    <!-- Logo -->
    <div class="ob-logo">
      <div class="ob-logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
          <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>
        </svg>
      </div>
      <span class="ob-logo-text"><strong><?= $app_name ?></strong></span>
    </div>

    <!-- Progress dots -->
    <div class="ob-progress">
      <div class="ob-dots">
        <div class="ob-dot active" id="dot-1"></div>
        <div class="ob-dot"        id="dot-2"></div>
        <div class="ob-dot"        id="dot-3"></div>
        <div class="ob-dot"        id="dot-4"></div>
      </div>
      <div class="ob-step-label" id="ob-label">Step 1 of 4 — Welcome</div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:14px">
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════
         SLIDE 1 — Welcome
         ═══════════════════════════ -->
    <div class="ob-step active" id="step-1">
      <div class="ob-title">Hey <strong><?= $fname ?></strong>! 👋 Welcome aboard.</div>
      <div class="ob-sub">
        Your <strong><?= $app_name ?></strong> account is live. Let's take a quick 2-minute tour so you know exactly what's waiting for you.
      </div>
      <ul class="ob-list">
        <li>
          <div class="ob-icon" style="background:#eff6ff">🖥️</div>
          <div>
            <div class="ob-feat-name">Virtual Private Servers</div>
            <div class="ob-feat-desc">Get a fully isolated server with dedicated resources — ready in under 60 seconds, straight from your dashboard.</div>
          </div>
        </li>
        <li>
          <div class="ob-icon" style="background:#f0fdf4">🔒</div>
          <div>
            <div class="ob-feat-name">Full Root Access</div>
            <div class="ob-feat-desc">Your server, your rules. SSH in with your key, install anything, configure everything — total control.</div>
          </div>
        </li>
        <li>
          <div class="ob-icon" style="background:#faf5ff">💳</div>
          <div>
            <div class="ob-feat-name">Prepaid Wallet Billing</div>
            <div class="ob-feat-desc">Add balance once, servers run till it lasts. No surprise charges. Pause or delete servers anytime.</div>
          </div>
        </li>
      </ul>
      <button class="ob-btn ob-btn-primary" onclick="goStep(2)" style="width:100%">
        Let's Go
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>

    <!-- ═══════════════════════════
         SLIDE 2 — Server Power
         ═══════════════════════════ -->
    <div class="ob-step" id="step-2">
      <div class="ob-title">Serious power. Instant delivery.</div>
      <div class="ob-sub">
        Every <strong><?= $app_name ?></strong> server comes with enterprise-grade specs — no shared noisy neighbours, no throttling.
      </div>
      <div class="ob-info-grid">
        <div class="ob-info-card">
          <div class="ob-info-label">vCPU Options</div>
          <div class="ob-info-val">2 – 48</div>
          <div class="ob-info-sub">x86_64 Intel / AMD</div>
        </div>
        <div class="ob-info-card">
          <div class="ob-info-label">RAM Options</div>
          <div class="ob-info-val">2 – 192 GB</div>
          <div class="ob-info-sub">DDR4 ECC memory</div>
        </div>
        <div class="ob-info-card">
          <div class="ob-info-label">Storage</div>
          <div class="ob-info-val">40 – 960 GB</div>
          <div class="ob-info-sub">NVMe SSD · Local</div>
        </div>
        <div class="ob-info-card">
          <div class="ob-info-label">Network</div>
          <div class="ob-info-val">1 Gbps</div>
          <div class="ob-info-sub">Up to 20 TB/mo BW</div>
        </div>
      </div>
      <ul class="ob-list" style="margin-bottom:22px">
        <li>
          <div class="ob-icon" style="background:#fff7ed">🔥</div>
          <div>
            <div class="ob-feat-name">Cloud Firewall</div>
            <div class="ob-feat-desc">Protect your server with simple allow/deny rules — no terminal needed, one click from the dashboard.</div>
          </div>
        </li>
        <li>
          <div class="ob-icon" style="background:#f0fdf4">🔑</div>
          <div>
            <div class="ob-feat-name">SSH Key Management</div>
            <div class="ob-feat-desc">Add SSH keys from your dashboard and attach them to any server at deploy time — secure by default.</div>
          </div>
        </li>
      </ul>
      <div class="ob-row">
        <button class="ob-btn ob-btn-ghost" onclick="goStep(1)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
          Back
        </button>
        <button class="ob-btn ob-btn-primary" onclick="goStep(3)">
          Next
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>

    <!-- ═══════════════════════════
         SLIDE 3 — Billing & Currency
         ═══════════════════════════ -->
    <div class="ob-step" id="step-3">
      <div class="ob-title">Billing that's simple and honest.</div>
      <div class="ob-sub">
        <strong><?= $app_name ?></strong> uses a <strong>prepaid wallet</strong> — you're always in control. Servers are billed hourly, billed stops the moment you delete or power off.
      </div>

      <!-- Currency box -->
      <div class="ob-currency-box">
        <div class="ob-currency-flag">
          <img
            src="<?= flag_url($curr_flag, 40) ?>"
            srcset="<?= flag_url($curr_flag, 40) ?> 1x, <?= flag_url($curr_flag, 80) ?> 2x"
            alt="<?= $currency ?> flag"
            width="36" height="36"
            onerror="this.style.display='none'"
          >
        </div>
        <div>
          <div class="ob-currency-name">Your billing currency: <?= $curr_label ?></div>
          <div class="ob-currency-note">
            Set automatically based on your location at signup.
            <?php if ($currency === 'INR'): ?>
              All prices shown in Indian Rupees (₹). Pay via UPI, cards, or net banking.
            <?php else: ?>
              All prices shown in US Dollars ($). Pay via international cards, PayPal, Stripes or bank transfer.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <ul class="ob-billing-steps">
        <li>
          <div class="ob-step-num">1</div>
          <div>
            <strong>Add wallet balance</strong>
            <span>Deposit <strong><?php
    $md_inr = (float)get_setting('min_deposit', '100');
    if ($currency === 'INR') {
        echo $curr_symbol . number_format($md_inr, 0);
    } else {
        require_once __DIR__ . '/includes/currency.php';
        $md_usd = max(round($md_inr * get_rate('INR','USD'), 2), 1.0);
        if ($currency === 'USD') echo $curr_symbol . number_format($md_usd, 2);
        else echo $curr_symbol . number_format(max(round($md_usd * get_rate('USD',$currency),2),1.0), 2);
    }
?></strong> or more to get started — no minimums after that.</span>
          </div>
        </li>
        <li>
          <div class="ob-step-num">2</div>
          <div>
            <strong>Deploy your server</strong>
            <span>Hourly cost is deducted automatically from your balance while the server is running.</span>
          </div>
        </li>
        <li>
          <div class="ob-step-num">3</div>
          <div>
            <strong>Delete anytime, pay for what you used</strong>
            <span>Stop or delete a server — billing halts instantly. No monthly contracts.</span>
          </div>
        </li>
      </ul>

      <div class="ob-row">
        <button class="ob-btn ob-btn-ghost" onclick="goStep(2)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
          Back
        </button>
        <button class="ob-btn ob-btn-primary" onclick="goStep(4)">
          Almost there!
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </div>
    </div>

    <!-- ═══════════════════════════
         SLIDE 4 — Get Ready To Fly
         ═══════════════════════════ -->
    <div class="ob-step" id="step-4">
      <div class="ob-rocket-wrap">
        <span class="ob-rocket"><img src="https://em-content.zobj.net/source/animated-noto-color-emoji/427/rocket_1f680.gif" style="width: 20%;"></span>
        <div class="ob-rocket-title">Get Ready To Fly!</div>
        <div class="ob-rocket-sub">
          Your <strong><?= $app_name ?></strong> account is fully set up. Here's what to do next:
        </div>
        <ul class="ob-checklist">
          <li>
            <div class="ob-check"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
            Add wallet balance to get started
          </li>
          <li>
            <div class="ob-check"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
            Deploy your first server in 60 seconds
          </li>
          <li>
            <div class="ob-check"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
            SSH in and run your stack
          </li>
          <li>
            <div class="ob-check"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>
            Monitor usage live from your dashboard
          </li>
        </ul>
      </div>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="finish" value="1">
        <button type="submit" class="ob-btn-launch">
          🚀&nbsp; Get Ready To Fly
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      </form>
      <button class="ob-btn-back-sm" onclick="goStep(3)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Go back
      </button>
    </div>

  </div><!-- /.ob-card -->
</div><!-- /.ob-shell -->

<script>
var LABELS = [
  'Step 1 of 4 \u2014 Welcome',
  'Step 2 of 4 \u2014 Server Specs',
  'Step 3 of 4 \u2014 Billing',
  'Step 4 of 4 \u2014 Launch'
];

function goStep(n) {
  document.querySelectorAll('.ob-step').forEach(function(el, i) {
    el.classList.toggle('active', i + 1 === n);
  });
  document.querySelectorAll('.ob-dot').forEach(function(d, i) {
    d.classList.remove('active', 'done');
    if (i + 1 === n)      d.classList.add('active');
    else if (i + 1 < n)   d.classList.add('done');
  });
  document.getElementById('ob-label').textContent = LABELS[n - 1];
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>