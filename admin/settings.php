<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/dns.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$csrf     = csrf_token();
$msg = ''; $err = '';

$tab = $_GET['tab'] ?? 'whatsapp';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $allowed = [
        'primary_color','primary_color_hover','site_name','company_name',
        'frontend_enabled',
        'organization_allowed','user_registration_allowed','company_email','company_phone',
        'company_address','company_city','company_state','company_country','company_pin',
        'company_gstin','gst_rate','gst_enabled','company_gst_state',
        'invoice_prefix','invoice_from_name','min_deposit','max_servers_per_user',
        'billing_cron_secret','suspend_on_zero','low_balance_warn','max_servers_without_kyc',
        'captcha_enabled','captcha_site_key','captcha_secret_key',
        'maintenance_mode','maintenance_message',
        'SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASS','SMTP_ENCRYPTION',
        'site_favicon','site_logo','site_logo_d','site_custom_css','site_custom_js',
        'razorpay_gateway_enabled','razorpay_gateway_name',
        'razorpay_key_id','razorpay_key_secret','razorpay_gateway_fee_pct',
        'stripe_enabled','stripe_publishable_key','stripe_secret_key','stripe_fee_pct','stripe_webhook_secret',
        'paypal_enabled','paypal_client_id','paypal_client_secret','paypal_mode','paypal_fee_pct',
        'wa_api','wa_token','wa_admin_number',
        'dns_cf_api_token','dns_cf_account_id','dns_enabled',
        'google_client_id','google_client_secret','google_signin_enabled',
        'github_client_id','github_client_secret','github_signin_enabled',
        'proxy_auto_activate','proxy_module_enabled',
        'proxy_hydraproxy_api_key','proxy_922proxy_api_key','proxy_iproyal_api_key',
        'proxy_proxyscrape_api_key','proxy_webshare_api_key',
        'proxy_brightdata_api_key','proxy_proxycheap_api_key',
    ];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) set_setting($k, $_POST[$k]);
    }
    $msg = 'Settings saved successfully.';
    header("refresh:3;url=/admin/settings.php");
}

function s(string $key, string $default = ''): string {
    return htmlspecialchars(get_setting($key, $default));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Settings — <?= $app_name ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    /* ── Admin layout (same as admin/index.php) ─────────────── */
    .adm-shell{display:flex;min-height:100vh}
    
    .adm-logo{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:8px}
    .adm-logo-mark{width:28px;height:28px;border-radius:6px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .adm-logo-text{font-weight:800;font-size:14px;color:white;letter-spacing:-.3px}
    .adm-badge{font-size:9px;font-weight:700;background:#dc2626;color:white;padding:1px 6px;border-radius:99px;margin-left:4px;text-transform:uppercase}
    .adm-nav{flex:1;padding:10px 8px;overflow-y:auto}
    .adm-nav-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:rgba(255,255,255,.3);padding:10px 8px 4px}
    .adm-link{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;font-size:13px;font-weight:500;color:rgba(255,255,255,.6);text-decoration:none;transition:all .14s;margin-bottom:1px}
    .adm-link:hover{background:rgba(255,255,255,.08);color:rgba(255,255,255,.9)}
    .adm-link.active{background:#22293b;color:white;font-weight:700}
    .adm-link svg{width:15px;height:15px;flex-shrink:0}
    .adm-footer-bar{padding:12px 10px;border-top:1px solid rgba(255,255,255,.08)}
    .adm-av{width:30px;height:30px;border-radius:7px;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:white;flex-shrink:0}
    .adm-main{margin-left:232px;flex:1;background:var(--gray-50);min-height:100vh}
    .adm-topbar{background:white;border-bottom:1px solid var(--border);height:56px;display:flex;align-items:center;padding:0 28px;position:sticky;top:0;z-index:30}
    .adm-topbar-title{font-size:15px;font-weight:800;color:var(--gray-900)}

    /* ── Settings page ─────────────────────────────────────── */
    .page{max-width:860px;padding:24px 28px}
    .card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px}
    .card-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900)}
    .card-body{padding:20px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .form-grid.full{grid-template-columns:1fr}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px}
    .flabel span{font-weight:400;color:var(--gray-400)}
    .form-control{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--gray-900);outline:none;transition:border-color .13s}
    .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .note{font-size:11.5px;color:var(--gray-400);margin-top:4px}
    .save-btn{display:inline-flex;align-items:center;gap:6px;padding:11px 24px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .save-btn:hover{background:var(--primary-hover)}

    /* Gateway status indicator */
    .gw-status{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;font-size:12px;font-weight:700;margin-left:auto}
    .gw-on{background:#f0fdf4;color:#16a34a}
    .gw-off{background:var(--gray-100);color:var(--gray-500)}


    .adm-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);backdrop-filter:blur(3px);z-index:45;opacity:0;pointer-events:none;transition:opacity .25s ease}
    .adm-overlay.open{opacity:1;pointer-events:auto}
    .adm-mobile-bar{display:none;background:white;border-bottom:1px solid var(--border);padding:10px 14px;align-items:center;gap:12px;position:sticky;top:0;z-index:60}
    .adm-ham{width:34px;height:34px;background:#f1f5f9;border:1px solid var(--border);border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#475569;flex-shrink:0}

    @media(max-width:900px){
      .adm-mobile-bar{display:flex}
      .adm-topbar{display:none}
      
      .adm-sidebar.open{transform:translateX(0)}
      .adm-main{margin-left:0 !important}
      .form-grid{grid-template-columns:1fr !important}
      .page{padding:16px}
    }
    @media(max-width:640px){
      .card-head{flex-wrap:wrap;gap:8px}
    }
    .stab-nav{display:flex;gap:6px;margin-bottom:22px;flex-wrap:wrap}
    .stab{padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:700;border:1.5px solid #e2e8f0;background:white;color:#64748b;cursor:pointer;transition:all .14s;text-decoration:none}
    .stab:hover{border-color:#94a3b8;color:#1e293b}
    .stab.on{background:#0f172a;border-color:#0f172a;color:white}
    .stab-pane{display:none}.stab-pane.on{display:block}
    .scard{background:white;border:1px solid #e2e8f0;border-radius:14px;margin-bottom:20px;overflow:hidden}
    .scard-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
    .scard-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
    .scard-title{font-size:13.5px;font-weight:800;color:#0f172a}
    .scard-body{padding:22px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
    .fg.full{grid-template-columns:1fr}.fg.tri{grid-template-columns:1fr 1fr 1fr}
    .flbl{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:6px}
    .finp{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:inherit;font-size:13px;color:#0f172a;outline:none;transition:border-color .13s;background:white}
    .finp:focus{border-color:#0f172a;box-shadow:0 0 0 3px rgba(15,23,42,.07)}
    .fnote{font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.5}
    .radio-row{display:flex;gap:20px;margin-top:6px}
    .radio-opt{display:flex;align-items:center;gap:7px;font-size:13px;color:#374151;cursor:pointer}
    .radio-opt input{accent-color:#0f172a}
    .status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;margin-left:auto}
    .pill-on{background:#f0fdf4;color:#16a34a}.pill-off{background:#f8fafc;color:#94a3b8}
    .btn-save{display:inline-flex;align-items:center;gap:7px;padding:10px 26px;background:#0f172a;color:white;border:none;border-radius:9px;font-size:13px;font-weight:800;font-family:inherit;cursor:pointer;transition:all .15s}
    .btn-save:hover{background:#1e293b;transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,23,42,.2)}
    .btn-save svg{width:13px;height:13px}
    .color-pair{display:flex;gap:8px;align-items:center}
    .color-swatch{width:38px;height:38px;border-radius:8px;border:1.5px solid #e2e8f0;cursor:pointer;padding:0;flex-shrink:0}
    .divider{border:none;border-top:1px solid #f1f5f9;margin:20px 0}
    .code-block{background:#0d1117;color:#3fb950;padding:16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.9;overflow-x:auto}
    .toast-bar{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:700;color:white;z-index:9999;background:#16a34a;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease}
    .toast-bar.show{transform:translateY(0);opacity:1}
    @media(max-width:900px){.fg,.fg.tri{grid-template-columns:1fr!important}}
  </style>
</head>
<!-- ── Mobile top bar ────────────────────────────────────── -->
<div class="adm-mobile-bar">
  <button class="adm-ham" onclick="admToggleSidebar()" aria-label="Menu">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6"  x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 130px;">
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
<?php else: ?>
    <span class="adm-mobile-title">
    <?= APP_NAME ?>
    <span style="font-size:9px;background:#dc2626;color:#fff;padding:2px 6px;border-radius:99px;font-weight:700;text-transform:uppercase;vertical-align:middle;margin-left:4px">Admin</span>
  </span>
<?php endif; ?>
</div>
<body>
<div class="adm-overlay" id="adm-overlay" onclick="admCloseSidebar()"></div>
<div class="adm-shell">

  <?php include 'sidebar.php'; ?>

  <!-- ── Main ────────────────────────────────────────────────── -->
  <div class="adm-main">
    <div class="adm-topbar">
      <span class="adm-topbar-title">System Settings</span>
      <div style="margin-left:auto;font-size:12px;color:var(--gray-400)"><?= date('d M Y, H:i') ?></div>
    </div>

    <div class="page">

      <?php if ($msg): ?>
      <div class="alert alert-success" style="margin-bottom:18px;border-radius:9px"><?= htmlspecialchars($msg) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <!-- ── PAYMENT GATEWAY ─────────────────────────────────── -->
        <div class="stab-pane on">

          <!-- ── Razorpay ──────────────────────────────────── -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#f0fdf4"><svg width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" id="razorpay"><path fill="#0C2451" d="M22.436 0l-11.91 7.773-1.174 4.276 6.625-4.297L11.65 24h4.391l6.395-24z"></path><path fill="#3A8FFF" d="M14.26 10.098L3.389 17.166 1.564 24h9.008l3.688-13.902Z"></path></svg></div>
              <span class="scard-title">Razorpay</span>
              <span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:500">Indian payments · UPI · Cards · Netbanking</span>
              <?php $rzp_ok = !empty(get_setting('razorpay_key_id')) && !empty(get_setting('razorpay_key_secret')); ?>
              <span class="status-pill <?= $rzp_ok?'pill-on':'pill-off' ?>" style="margin-left:auto">
                <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
                <?= $rzp_ok ? 'Active' : 'Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:11px 15px;margin-bottom:16px;font-size:12.5px;color:#1d4ed8">
                Get keys from <a href="https://dashboard.razorpay.com/app/keys" target="_blank" style="font-weight:700">Razorpay Dashboard →</a>
              </div>
              <div class="fg">
                <div><label class="flbl">Key ID <span style="font-weight:400;color:#94a3b8">(public)</span></label><input name="razorpay_key_id" class="finp" value="<?= s('razorpay_key_id') ?>" placeholder="rzp_live_xxxxxxxxxxxx" style="font-family:'JetBrains Mono',monospace"><div class="fnote">Starts with rzp_test_ or rzp_live_</div></div>
                <div><label class="flbl">Key Secret <span style="font-weight:400;color:#94a3b8">(private)</span></label><input name="razorpay_key_secret" type="password" class="finp" value="<?= s('razorpay_key_secret') ?>" placeholder="••••••••" autocomplete="new-password"></div>
              </div>
              <div class="fg">
                <div><label class="flbl">Gateway Fee %</label><input name="razorpay_gateway_fee_pct" type="number" step="0.1" min="0" max="10" class="finp" value="<?= s('razorpay_gateway_fee_pct','2') ?>"><div class="fnote">Added on top of deposit amount</div></div>
                <div><label class="flbl">Status</label><select name="razorpay_gateway_enabled" class="finp"><option value="1" <?= s('razorpay_gateway_enabled','1')==='1'?'selected':'' ?>>✓ Enabled</option><option value="0" <?= s('razorpay_gateway_enabled','1')==='0'?'selected':'' ?>>✗ Disabled</option></select></div>
              </div>
            </div>
          </div>

          <!-- ── Stripe ────────────────────────────────────── -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#f0f5ff">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#6772e5"><path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.32 2.489-3.296 2.489-5.752 0-4.128-2.524-5.851-6.555-7.285z"/></svg>
              </div>
              <span class="scard-title">Stripe</span>
              <span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:500">Global · Cards · USD/EUR</span>
              <?php $stripe_ok = !empty(get_setting('stripe_publishable_key')) && !empty(get_setting('stripe_secret_key')); ?>
              <span class="status-pill <?= $stripe_ok?'pill-on':'pill-off' ?>" style="margin-left:auto">
                <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
                <?= $stripe_ok ? 'Active' : 'Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#f0f5ff;border:1px solid #c7d2fe;border-radius:9px;padding:11px 15px;margin-bottom:16px;font-size:12.5px;color:#4338ca">
                Get keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank" style="font-weight:700;color:#4338ca">Stripe Dashboard →</a>
                · Webhook secret from <a href="https://dashboard.stripe.com/webhooks" target="_blank" style="font-weight:700;color:#4338ca">Webhooks →</a>
              </div>
              <div class="fg">
                <div><label class="flbl">Publishable Key <span style="font-weight:400;color:#94a3b8">(public)</span></label><input name="stripe_publishable_key" class="finp" value="<?= s('stripe_publishable_key') ?>" placeholder="pk_live_xxxxxxxxxxxx" style="font-family:'JetBrains Mono',monospace"><div class="fnote">Starts with pk_test_ or pk_live_</div></div>
                <div><label class="flbl">Secret Key <span style="font-weight:400;color:#94a3b8">(private)</span></label><input name="stripe_secret_key" type="password" class="finp" value="<?= s('stripe_secret_key') ?>" placeholder="sk_live_xxxxxxxxxxxx" autocomplete="new-password" style="font-family:'JetBrains Mono',monospace"><div class="fnote">Starts with sk_test_ or sk_live_</div></div>
              </div>
              <div class="fg">
                <div><label class="flbl">Webhook Secret <span style="font-weight:400;color:#94a3b8">(optional)</span></label><input name="stripe_webhook_secret" type="password" class="finp" value="<?= s('stripe_webhook_secret') ?>" placeholder="whsec_xxxxxxxxxxxx" autocomplete="new-password" style="font-family:'JetBrains Mono',monospace"><div class="fnote">For secure webhook verification. Get from Stripe Webhooks.</div></div>
                <div>
                  <label class="flbl">Gateway Fee %</label>
                  <input name="stripe_fee_pct" type="number" step="0.1" min="0" max="10" class="finp" value="<?= s('stripe_fee_pct','2.9') ?>">
                  <div class="fnote">Stripe charges 2.9%+30¢ — pass to user or absorb</div>
                </div>
              </div>
              <div class="fg">
                <div><label class="flbl">Status</label><select name="stripe_enabled" class="finp"><option value="1" <?= s('stripe_enabled','0')==='1'?'selected':'' ?>>✓ Enabled</option><option value="0" <?= s('stripe_enabled','0')==='0'?'selected':'' ?>>✗ Disabled</option></select></div>
              </div>
            </div>
          </div>

          <!-- ── PayPal ─────────────────────────────────────── -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#f0f9ff">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#003087"><path d="M20.067 8.478c.492.88.556 2.014.3 3.327-.74 3.806-3.276 5.12-6.514 5.12h-.5a.805.805 0 0 0-.794.68l-.04.22-.63 3.993-.032.17a.804.804 0 0 1-.794.679H7.72a.483.483 0 0 1-.477-.558L7.418 21h1.518l.95-6.02h1.385c4.678 0 7.933-2.522 8.796-7.502zM4.886 3.09c.42-.573 1.044-.862 1.875-.862h5.744c.717 0 1.396.04 2.032.12 1.79.24 3.012.878 3.694 1.937.566.873.727 2.027.48 3.435-.752 4.304-3.532 5.657-7.018 5.657H9.88a.838.838 0 0 0-.827.71l-.98 6.21a.498.498 0 0 1-.492.42H4.85a.5.5 0 0 1-.494-.576L5.9 4.4a3.02 3.02 0 0 1 .986-1.31z"/></svg>
              </div>
              <span class="scard-title">PayPal</span>
              <span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:500">Global · PayPal balance · Cards</span>
              <?php $pp_ok = !empty(get_setting('paypal_client_id')) && !empty(get_setting('paypal_client_secret')); ?>
              <span class="status-pill <?= $pp_ok?'pill-on':'pill-off' ?>" style="margin-left:auto">
                <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
                <?= $pp_ok ? 'Active' : 'Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:11px 15px;margin-bottom:16px;font-size:12.5px;color:#0369a1">
                Get keys from <a href="https://developer.paypal.com/developer/applications" target="_blank" style="font-weight:700;color:#0369a1">PayPal Developer →</a>
              </div>
              <div class="fg">
                <div><label class="flbl">Client ID <span style="font-weight:400;color:#94a3b8">(public)</span></label><input name="paypal_client_id" class="finp" value="<?= s('paypal_client_id') ?>" placeholder="AxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxCx" style="font-family:'JetBrains Mono',monospace;font-size:11.5px"></div>
                <div><label class="flbl">Client Secret <span style="font-weight:400;color:#94a3b8">(private)</span></label><input name="paypal_client_secret" type="password" class="finp" value="<?= s('paypal_client_secret') ?>" placeholder="••••••••" autocomplete="new-password"></div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Mode</label>
                  <select name="paypal_mode" class="finp">
                    <option value="live"    <?= s('paypal_mode','sandbox')==='live'?'selected':'' ?>>Live (Production)</option>
                    <option value="sandbox" <?= s('paypal_mode','sandbox')==='sandbox'?'selected':'' ?>>Sandbox (Testing)</option>
                  </select>
                </div>
                <div><label class="flbl">Gateway Fee %</label><input name="paypal_fee_pct" type="number" step="0.1" min="0" max="10" class="finp" value="<?= s('paypal_fee_pct','3.5') ?>"><div class="fnote">PayPal charges ~3.49%+fixed fee internationally</div></div>
              </div>
              <div class="fg">
                <div><label class="flbl">Status</label><select name="paypal_enabled" class="finp"><option value="1" <?= s('paypal_enabled','0')==='1'?'selected':'' ?>>✓ Enabled</option><option value="0" <?= s('paypal_enabled','0')==='0'?'selected':'' ?>>✗ Disabled</option></select></div>
              </div>
            </div>
          </div>

        </div>

        <!-- ── SITE ─────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#eff6ff">🌐</div>
            <span class="card-title">Site</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div><label class="flabel">Site Name</label><input name="site_name" class="form-control" value="<?= s('site_name','CloudVault') ?>"></div>
              <div>
                <label class="flabel">Public Frontend</label>
                <select name="frontend_enabled" class="form-control">
                  <option value="1" <?= get_setting('frontend_enabled','1')==='1'?'selected':'' ?>>Enabled — show landing page at /</option>
                  <option value="0" <?= get_setting('frontend_enabled','1')==='0'?'selected':'' ?>>Disabled — go straight to login (panel-only)</option>
                </select>
                <span style="font-size:11px;color:#94a3b8">Disable when this install is a management/backup panel only.</span>
              </div>
              <div><label class="flabel">Site Favicon URL <span>(optional)</span></label><input name="site_favicon" class="form-control" value="<?= s('site_favicon') ?>" placeholder="https://..."></div>
              <div><label class="flabel">Site Logo URL (Light) <span>(optional)</span></label><input name="site_logo" class="form-control" value="<?= s('site_logo') ?>" placeholder="https://..."></div>
              <div><label class="flabel">Site Logo URL (Dark)<span>(optional)</span></label><input name="site_logo_d" class="form-control" value="<?= s('site_logo_d') ?>" placeholder="https://..."></div>
            </div>
            <div class="form-grid full">
              <div><label class="flabel">Custom CSS</label><textarea name="site_custom_css" class="form-control" rows="3" style="font-family:monospace;font-size:12px"><?= s('site_custom_css') ?></textarea></div>
            </div>
            <div class="form-grid full">
              <div><label class="flabel">Custom JS</label><textarea name="site_custom_js" class="form-control" rows="3" style="font-family:monospace;font-size:12px"><?= s('site_custom_js') ?></textarea></div>
            </div>
            <div class="form-grid full">
    <div>
        <label class="flabel">Primary Color</label>
        <div style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="primary_color" id="primary_color" class="form-control" value="<?= s('primary_color') ?>" placeholder="#000000">
            <input type="color" class="color-picker-tool" value="<?= s('primary_color') ?>" oninput="updateHex(this, 'primary_color')">
        </div>
    </div>
</div>

<div class="form-grid full">
    <div>
        <label class="flabel">Primary Color Hover</label>
        <div style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="primary_color_hover" id="primary_color_hover" class="form-control" value="<?= s('primary_color_hover') ?>" placeholder="#000000">
            <input type="color" class="color-picker-tool" value="<?= s('primary_color_hover') ?>" oninput="updateHex(this, 'primary_color_hover')">
        </div>
    </div>
</div>

<script>
function updateHex(picker, targetId) {
    // Picker se color lekar text input me daal deta hai
    document.getElementById(targetId).value = picker.value.toUpperCase();
}

// Optional: Agar user manual text likhe toh picker bhi update ho jaye
document.querySelectorAll('.form-control').forEach(input => {
    input.addEventListener('input', function() {
        let picker = this.nextElementSibling;
        if(this.value.length === 7 && this.value.startsWith('#')) {
            picker.value = this.value;
        }
    });
});
</script>

<style>
.color-picker-tool {
    padding: 0;
    border: none;
    width: 40px;
    height: 40px;
    cursor: pointer;
    background: none;
    border-radius: 5px;
}
.color-picker-tool::-webkit-color-swatch {
    border-radius: 5px;
    border: 1px solid #ccc;
}
</style>
          </div>
        </div>

        <!-- ── COMPANY ──────────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#f0fdf4">🏢</div>
            <span class="card-title">Company Information</span>
          </div>
          <div class="card-body">
            <div class="form-grid full" style="margin-bottom:14px">
              <div>
                <label class="flabel">Enable Organization Registration</label>
                <div style="display:flex;gap:16px;margin-top:6px">
                  <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                    <input type="radio" name="organization_allowed" value="1" <?= get_setting('organization_allowed','1')==='1'?'checked':'' ?> style="accent-color:var(--primary)"> ON
                  </label>
                  <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                    <input type="radio" name="organization_allowed" value="0" <?= get_setting('organization_allowed','1')==='0'?'checked':'' ?> style="accent-color:var(--primary)"> OFF
                  </label>
                </div>
              </div>
            </div>
            <div class="form-grid full" style="margin-bottom:14px">
              <div>
                <label class="flabel">Enable Registration</label>
                <div style="display:flex;gap:16px;margin-top:6px">
                  <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                    <input type="radio" name="user_registration_allowed" value="1" <?= get_setting('user_registration_allowed','1')==='1'?'checked':'' ?> style="accent-color:var(--primary)"> ON
                  </label>
                  <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                    <input type="radio" name="user_registration_allowed" value="0" <?= get_setting('user_registration_allowed','1')==='0'?'checked':'' ?> style="accent-color:var(--primary)"> OFF
                  </label>
                </div>
              </div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">Company Name</label><input name="company_name" class="form-control" value="<?= s('company_name') ?>"></div>
              <div><label class="flabel">Email</label><input name="company_email" type="email" class="form-control" value="<?= s('company_email') ?>"></div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">Phone</label><input name="company_phone" class="form-control" value="<?= s('company_phone') ?>"></div>
              <div><label class="flabel">Invoice Prefix</label><input name="invoice_prefix" class="form-control" value="<?= s('invoice_prefix','INV') ?>" style="font-family:monospace"></div>
            </div>
            <div class="form-grid full">
              <div><label class="flabel">Address</label><input name="company_address" class="form-control" value="<?= s('company_address') ?>"></div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">City</label><input name="company_city" class="form-control" value="<?= s('company_city') ?>"></div>
              <div><label class="flabel">State</label><input name="company_state" class="form-control" value="<?= s('company_state') ?>"></div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">Country</label><input name="company_country" class="form-control" value="<?= s('company_country','India') ?>"></div>
              <div><label class="flabel">PIN/ZIP</label><input name="company_pin" class="form-control" value="<?= s('company_pin') ?>"></div>
            </div>

            <!-- ── GST Settings ─────────────────────────────────── -->
            <div style="border-top:1px solid var(--border);padding-top:18px;margin-top:6px">
              <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-500);margin-bottom:14px">🇮🇳 GST / Tax Settings (India)</div>
              <div class="form-grid">
                <div>
                  <label class="flabel">Company GSTIN <span>(optional)</span></label>
                  <input name="company_gstin" class="form-control" placeholder="e.g. 09AABCU9603R1ZV" value="<?= s('company_gstin') ?>" style="font-family:monospace;text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                  <div class="note">Your GST registration number. Shown on invoices.</div>
                </div>
                <div>
                  <label class="flabel">Company GST State <span>(for SGST/CGST vs IGST)</span></label>
                  <select name="company_gst_state" class="form-control">
                    <option value="">-- Select State --</option>
                    <?php
                    $indian_states = ['Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal','Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Jammu and Kashmir','Ladakh','Lakshadweep','Puducherry'];
                    $cur_state = get_setting('company_gst_state','');
                    foreach ($indian_states as $st_name): ?>
                    <option value="<?= htmlspecialchars($st_name) ?>" <?= $cur_state===$st_name?'selected':'' ?>><?= htmlspecialchars($st_name) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="note">Same-state users → SGST+CGST. Other-state/non-Indian → IGST.</div>
                </div>
              </div>
              <div class="form-grid">
                <div>
                  <label class="flabel">GST Rate (%) <span>default 18%</span></label>
                  <input name="gst_rate" type="number" step="0.01" min="0" max="100" class="form-control" value="<?= s('gst_rate','18') ?>" placeholder="18">
                  <div class="note">Leave blank or 0 to disable GST. Applied only on Indian users.</div>
                </div>
                <div>
                  <label class="flabel">GST Enabled</label>
                  <div style="display:flex;gap:16px;margin-top:8px">
                    <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                      <input type="radio" name="gst_enabled" value="1" <?= get_setting('gst_enabled','1')==='1'?'checked':'' ?> style="accent-color:var(--primary)"> ON
                    </label>
                    <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
                      <input type="radio" name="gst_enabled" value="0" <?= get_setting('gst_enabled','1')==='0'?'checked':'' ?> style="accent-color:var(--primary)"> OFF
                    </label>
                  </div>
                  <div class="note">When OFF, no GST is charged or shown on any invoice.</div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- ── BILLING ─────────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#fff7ed">&#128176;</div>
            <span class="card-title">Billing</span>
          </div>
          <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:16px;font-size:12.5px">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
              Exchange rates (EUR→USD, USD→INR) are auto-fetched every 3 hours by cron. Not configurable here.
            </div>
            <div class="form-grid">
              <div>
                <label class="flabel">Minimum Deposit</label>
                <input name="min_deposit" type="number" step="1" class="form-control" value="<?= s('min_deposit','100') ?>">
                <div class="note">In user's billing currency (INR or USD)</div>
              </div>
              <div><label class="flabel">Max Servers Without KYC</label><input name="max_servers_without_kyc" type="number" class="form-control" value="<?= s('max_servers_without_kyc','1') ?>"></div>
            </div>
            <div class="form-grid">
              <div>
                <label class="flabel">Low Balance Warning</label>
                <input name="low_balance_warn" type="number" step="0.01" class="form-control" value="<?= s('low_balance_warn','5') ?>">
                <div class="note">Warn user when balance drops below this</div>
              </div>
              <div>
                <label class="flabel">Suspend on Zero Balance</label>
                <select name="suspend_on_zero" class="form-control">
                  <option value="1" <?= s('suspend_on_zero','1')==='1'?'selected':'' ?>>Yes</option>
                  <option value="0" <?= s('suspend_on_zero','1')==='0'?'selected':'' ?>>No (not recommended)</option>
                </select>
              </div>
            </div>
            <div class="form-grid full">
              <div>
                <label class="flabel">Billing Cron Secret</label>
                <input name="billing_cron_secret" class="form-control" value="<?= s('billing_cron_secret') ?>" placeholder="Random string" style="font-family:monospace">
                <div class="note">Used in: <?= BASE_URL ?>/cron/billing.php?secret=YOUR_SECRET</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── SMTP ─────────────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#faf5ff">📧</div>
            <span class="card-title">Email (SMTP)</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div><label class="flabel">SMTP Host</label><input name="SMTP_HOST" class="form-control" value="<?= s('SMTP_HOST') ?>" style="font-family:monospace"></div>
              <div><label class="flabel">SMTP Port</label><input name="SMTP_PORT" type="number" class="form-control" value="<?= s('SMTP_PORT','587') ?>"></div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">Username</label><input name="SMTP_USERNAME" class="form-control" value="<?= s('SMTP_USERNAME') ?>" style="font-family:monospace"></div>
              <div>
                <label class="flabel">Encryption</label>
                <select name="SMTP_ENCRYPTION" class="form-control">
                  <option value="tls" <?= s('SMTP_ENCRYPTION','tls')==='tls'?'selected':'' ?>>TLS (587)</option>
                  <option value="ssl" <?= s('SMTP_ENCRYPTION','tls')==='ssl'?'selected':'' ?>>SSL (465)</option>
                </select>
              </div>
            </div>
            <div class="form-grid full">
              <div><label class="flabel">SMTP Password</label><input name="SMTP_PASS" type="password" class="form-control" value="<?= s('SMTP_PASS') ?>" autocomplete="new-password"></div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">From Name</label><input name="invoice_from_name" class="form-control" value="<?= s('invoice_from_name', $app_name.' Billing') ?>"></div>
            </div>
          </div>
        </div>

        <!-- ── CAPTCHA ─────────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#f0fdf4">🛡️</div>
            <span class="card-title">reCAPTCHA</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div>
                <label class="flabel">Enabled</label>
                <select name="captcha_enabled" class="form-control">
                  <option value="1" <?= s('captcha_enabled','1')==='1'?'selected':'' ?>>Enabled</option>
                  <option value="0" <?= s('captcha_enabled','1')==='0'?'selected':'' ?>>Disabled</option>
                </select>
              </div>
            </div>
            <div class="form-grid">
              <div><label class="flabel">Site Key</label><input name="captcha_site_key" class="form-control" value="<?= s('captcha_site_key') ?>" style="font-family:monospace"></div>
              <div><label class="flabel">Secret Key</label><input name="captcha_secret_key" class="form-control" value="<?= s('captcha_secret_key') ?>" style="font-family:monospace"></div>
            </div>
          </div>
        </div>
        
        <!-- ── WhatsApp API ─────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#fef9c3">📞</div>
            <span class="card-title">WhatsApp API Setting</span>
          </div>
          <div class="card-body">
            <div class="form-grid full">
              <div>
                <label class="flabel">API URL</label>
                <input name="wa_api" type="text" step="1" class="form-control" value="<?= s('wa_api') ?>">
                <div class="note">WhatsApp Official API Url End Point</div>
              </div>
            </div>
            <div class="form-grid full">
              <div>
                <label class="flabel">API Token</label>
                <input name="wa_token" type="password" step="1" class="form-control" value="<?= s('wa_token') ?>">
                <div class="note">WhatsApp API Token</div>
              </div>
            </div>
            <div class="form-grid">
              <div>
                <label class="flabel">Admin Number</label>
                <input name="wa_admin_number" type="number" step="1" class="form-control" value="<?= s('wa_admin_number') ?>">
                <div class="note">Enter Admin WhatsApp Number with country code 91 725XXXXXXX</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── MAINTENANCE ─────────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:#fef9c3">🔧</div>
            <span class="card-title">Maintenance</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div>
                <label class="flabel">Mode</label>
                <select name="maintenance_mode" class="form-control">
                  <option value="0" <?= s('maintenance_mode','0')==='0'?'selected':'' ?>>Off</option>
                  <option value="1" <?= s('maintenance_mode','0')==='1'?'selected':'' ?>>On — Show maintenance page</option>
                </select>
              </div>
            </div>
            <div class="form-grid full">
              <div><label class="flabel">Message</label><textarea name="maintenance_message" class="form-control" rows="2"><?= s('maintenance_message','We are performing scheduled maintenance. Back shortly.') ?></textarea></div>
            </div>
          </div>
        </div>

        <!-- ── CRON REFERENCE ──────────────────────────────────── -->
        <div class="card">
          <div class="card-head">
            <div class="card-icon" style="background:var(--gray-100)">⏰</div>
            <span class="card-title">Cron Jobs Reference</span>
          </div>
          <div class="card-body">
            <p style="font-size:13px;color:var(--gray-600);margin-bottom:12px">Add these to your server crontab (cPanel → Cron Jobs):</p>
            <pre style="background:#0d1117;color:#3fb950;padding:14px;border-radius:9px;font-size:12.5px;overflow-x:auto;line-height:1.9"><?php
echo "# Hourly billing deduction\n";
echo "0 * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/billing.php >> /var/log/cv_billing.log 2>&amp;1\n\n";
echo "# Currency rate refresh (every 3 hours)\n";
echo "0 */3 * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/currency_refresh.php >> /var/log/cv_currency.log 2>&amp;1\n\n";
echo "# Server status poller (every minute)\n";
echo "* * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/server-status.php >> /var/log/cv_status.log 2>&amp;1\n\n";
echo "# Every minute | WhatsaApp Api\n";
echo "* * * * * /usr/local/bin/php /home/cloudgreat/public_html/admin/wa-queue.php >> /home/cloudgreat/wa-queue.log 2>&1\n\n";
echo "# Hourly | Storage Api\n";
echo "0 * * * * /usr/local/bin/php /home/cloudgreat/public_html/cron/storage-billing.php >> /home/cloudgreat/cron.log 2>&1";
?></pre>
          </div>
        </div>

          <!-- ── DNS / Cloudflare ─────────────────────────── -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#e0f2fe">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
              </div>
              <span class="scard-title">DNS Management — Cloudflare</span>
              <?php $dns_ok = !empty(get_setting('dns_cf_api_token')) && !empty(get_setting('dns_cf_account_id')); ?>
              <span class="status-pill <?= $dns_ok?'pill-on':'pill-off' ?>" style="margin-left:auto">
                <span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>
                <?= $dns_ok ? 'Configured' : 'Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#e0f2fe;border:1px solid #7dd3fc;border-radius:9px;padding:12px 15px;margin-bottom:16px;font-size:12.5px;color:#0369a1;line-height:1.7">
                <strong>Step 1:</strong> <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" style="font-weight:700;color:#0369a1">Cloudflare → API Tokens</a> → Create Token → "Edit zone DNS" template.<br>
                <strong>Step 2:</strong> Account ID milega <a href="https://dash.cloudflare.com" target="_blank" style="font-weight:700;color:#0369a1">Cloudflare Dashboard</a> → koi bhi domain click karo → right sidebar mein.
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Cloudflare API Token</label>
                  <input name="dns_cf_api_token" type="password" class="finp" autocomplete="new-password"
                         value="<?= htmlspecialchars(get_setting('dns_cf_api_token','')) ?>"
                         placeholder="Zone:Read + DNS:Edit permissions">
                  <div class="fnote">Create at Cloudflare → Profile → API Tokens</div>
                </div>
                <div>
                  <label class="flbl">Cloudflare Account ID</label>
                  <input name="dns_cf_account_id" class="finp" style="font-family:monospace"
                         value="<?= htmlspecialchars(get_setting('dns_cf_account_id','')) ?>"
                         placeholder="32-char hex string">
                  <div class="fnote">Cloudflare Dashboard → any domain → right sidebar</div>
                </div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">DNS Feature</label>
                  <select name="dns_enabled" class="finp">
                    <option value="1" <?= get_setting('dns_enabled','1')==='1'?'selected':'' ?>>✓ Enabled — users can manage DNS</option>
                    <option value="0" <?= get_setting('dns_enabled','1')==='0'?'selected':'' ?>>✗ Disabled — hide from sidebar</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- ══ Google Sign-In ══════════════════════════════════ -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#fff7ed">
                <svg viewBox="0 0 24 24" width="16" height="16">
                  <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                  <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                  <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                  <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
              </div>
              <span class="scard-title">Google Sign-In / Sign-Up</span>
              <span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:500">OAuth 2.0</span>
              <?php $g_ok = !empty(get_setting('google_client_id')) && get_setting('google_signin_enabled','0')==='1'; ?>
              <span style="margin-left:auto;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600;background:<?= $g_ok?'#dcfce7':'#f1f5f9' ?>;color:<?= $g_ok?'#16a34a':'#94a3b8' ?>">
                <?= $g_ok ? '● Active' : '○ Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:9px;padding:12px 15px;margin-bottom:16px;font-size:12.5px;color:#9a3412;line-height:1.9">
                <strong>Setup:</strong> <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:#9a3412;font-weight:700">Google Cloud Console → Credentials</a> → Create OAuth 2.0 Client ID → Application type: <strong>Web application</strong><br>
                Authorized JavaScript origins me daalo: <code style="background:rgba(0,0,0,.07);padding:1px 7px;border-radius:4px"><?= htmlspecialchars(BASE_URL) ?></code>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Client ID <span style="font-weight:400;color:#94a3b8">(public)</span></label>
                  <input name="google_client_id" class="finp" style="font-size:11.5px;font-family:monospace"
                         value="<?= s('google_client_id') ?>"
                         placeholder="XXXXXXXXXX-xxxx.apps.googleusercontent.com">
                  <div class="fnote">Google Console → OAuth 2.0 Client IDs se copy karo</div>
                </div>
                <div>
                  <label class="flbl">Client Secret <span style="font-weight:400;color:#94a3b8">(private)</span></label>
                  <input name="google_client_secret" type="password" class="finp" autocomplete="new-password"
                         value="<?= s('google_client_secret') ?>" placeholder="GOCSPX-xxxxxxxxxxxxx">
                  <div class="fnote">Server-side token verification ke liye</div>
                </div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Feature Toggle</label>
                  <select name="google_signin_enabled" class="finp">
                    <option value="1" <?= s('google_signin_enabled','0')==='1'?'selected':'' ?>>✓ Enabled — Login &amp; Register pe button dikhega</option>
                    <option value="0" <?= s('google_signin_enabled','0')==='0'?'selected':'' ?>>✗ Disabled</option>
                  </select>
                </div>
                <div>
                  <label class="flbl">Authorized JS Origin (read-only)</label>
                  <input class="finp" readonly value="<?= htmlspecialchars(BASE_URL) ?>" style="background:#f8fafc;color:#64748b;font-size:12px;font-family:monospace">
                  <div class="fnote">Google Console me exactly yahi daalni hai</div>
                </div>
              </div>
            </div>
          </div>

          <!-- ══ GitHub Sign-In ══════════════════════════════════ -->
          <div class="scard">
            <div class="scard-head">
              <div class="scard-ic" style="background:#f0f6ff">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="#1b1f23"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0 1 12 6.844a9.59 9.59 0 0 1 2.504.337c1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.02 10.02 0 0 0 22 12.017C22 6.484 17.522 2 12 2z"/></svg>
              </div>
              <span class="scard-title">GitHub Sign-In / Sign-Up</span>
              <span style="margin-left:6px;font-size:11px;color:#94a3b8;font-weight:500">OAuth App</span>
              <?php $gh_ok = !empty(get_setting('github_client_id')) && get_setting('github_signin_enabled','0')==='1'; ?>
              <span style="margin-left:auto;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:600;background:<?= $gh_ok?'#dcfce7':'#f1f5f9' ?>;color:<?= $gh_ok?'#16a34a':'#94a3b8' ?>">
                <?= $gh_ok ? '● Active' : '○ Not configured' ?>
              </span>
            </div>
            <div class="scard-body">
              <div style="background:#f0f6ff;border:1px solid #c8d8f0;border-radius:9px;padding:12px 15px;margin-bottom:16px;font-size:12.5px;color:#1e3a5f;line-height:1.9">
                <strong>Setup:</strong> <a href="https://github.com/settings/developers" target="_blank" style="color:#1e3a5f;font-weight:700">GitHub → Settings → Developer Settings → OAuth Apps</a> → New OAuth App<br>
                Homepage URL: <code style="background:rgba(0,0,0,.07);padding:1px 7px;border-radius:4px"><?= htmlspecialchars(BASE_URL) ?></code>&nbsp;&nbsp;
                Callback URL: <code style="background:rgba(0,0,0,.07);padding:1px 7px;border-radius:4px"><?= htmlspecialchars(BASE_URL) ?>/includes/github_callback.php</code>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Client ID <span style="font-weight:400;color:#94a3b8">(public)</span></label>
                  <input name="github_client_id" class="finp" style="font-size:12px;font-family:monospace"
                         value="<?= s('github_client_id') ?>" placeholder="Ov23liXXXXXXXXXXXXXX">
                  <div class="fnote">GitHub OAuth App → Client ID</div>
                </div>
                <div>
                  <label class="flbl">Client Secret <span style="font-weight:400;color:#94a3b8">(private)</span></label>
                  <input name="github_client_secret" type="password" class="finp" autocomplete="new-password"
                         value="<?= s('github_client_secret') ?>" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                  <div class="fnote">GitHub OAuth App → Client Secret → Generate a new client secret</div>
                </div>
              </div>
              <div class="fg">
                <div>
                  <label class="flbl">Feature Toggle</label>
                  <select name="github_signin_enabled" class="finp">
                    <option value="1" <?= s('github_signin_enabled','0')==='1'?'selected':'' ?>>✓ Enabled — Login &amp; Register pe button dikhega</option>
                    <option value="0" <?= s('github_signin_enabled','0')==='0'?'selected':'' ?>>✗ Disabled</option>
                  </select>
                </div>
                <div>
                  <label class="flbl">Callback URL (read-only)</label>
                  <input class="finp" readonly value="<?= htmlspecialchars(BASE_URL) ?>/includes/github_callback.php" style="background:#f8fafc;color:#64748b;font-size:11.5px;font-family:monospace">
                  <div class="fnote">GitHub OAuth App me exactly yahi Callback URL daalni hai</div>
                </div>
              </div>
            </div>
          </div>

        <button type="submit" class="save-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save All Settings
        </button>

      
</form>

      <div style="height:32px"></div>
    </div>
  </div>
</div>
</body>
</html>
