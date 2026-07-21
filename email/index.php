<?php
// email/index.php — SMTP Email Service
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/currency.php';
require_login();

if (get_setting('smtp_module_enabled','1') !== '1') { http_response_code(404); die('Service unavailable.'); }

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();


$uid      = (int)$user['id'];
$sym      = user_currency_symbol($currency);
$csrf     = csrf_token();
$balance  = (float)$user['wallet_balance'];
$tab      = $_GET['tab'] ?? 'plans';

// Plans
$plans = db()->query("SELECT * FROM smtp_plans WHERE is_active=1 ORDER BY sort_order,id")->fetchAll();
foreach ($plans as &$p) {
    $p['features'] = json_decode($p['features'] ?? '[]', true) ?: [];
    $p['price']    = $currency === 'INR' ? (float)$p['price_inr'] : (float)$p['price_usd'];
}
unset($p);

// My orders
$ord_st = db()->prepare(
    "SELECT o.*, p.name plan_name FROM smtp_orders o
     JOIN smtp_plans p ON p.id=o.plan_id
     WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 30"
);
$ord_st->execute([$uid]);
$orders = $ord_st->fetchAll();

$active_count  = count(array_filter($orders, fn($o) => $o['status'] === 'active'));
$total_sent    = array_sum(array_column($orders, 'emails_sent'));

function smtp_badge(string $s, bool $domain_verified=false): string {
    if ($s === 'pending' && !$domain_verified) {
        return '<span class="badge badge-yellow">🔍 Verify Domain</span>';
    }
    if ($s === 'pending' && $domain_verified) {
        return '<span class="badge badge-yellow">⏳ Activating</span>';
    }
    return match($s) {
        'active'    => '<span class="badge badge-green"><span style="width:5px;height:5px;border-radius:50%;background:#16a34a;display:inline-block;margin-right:3px"></span>Active</span>',
        'suspended' => '<span class="badge badge-red">⚠ Suspended</span>',
        'expired'   => '<span class="badge badge-gray">Expired</span>',
        'cancelled' => '<span class="badge badge-gray">Cancelled</span>',
        default     => '<span class="badge badge-gray">'.ucfirst($s).'</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>SMTP Email — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .plan-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    .plan-card{background:white;border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px;position:relative;display:flex;flex-direction:column;transition:all .16s;box-shadow:var(--shadow-sm)}
    .plan-card:hover{border-color:var(--gray-300);box-shadow:var(--shadow-md);transform:translateY(-2px)}
    .plan-card.popular{border-color:var(--primary);box-shadow:0 0 0 1px var(--primary)}
    .popular-badge{position:absolute;top:-1px;left:50%;transform:translateX(-50%);background:var(--primary);color:white;font-size:10px;font-weight:800;padding:3px 14px;border-radius:0 0 8px 8px;letter-spacing:.5px;white-space:nowrap}
    .plan-name{font-size:14px;font-weight:700;color:var(--gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em}
    .plan-quota-num{font-size:30px;font-weight:900;color:var(--gray-900);letter-spacing:-.8px;line-height:1}
    .plan-quota-unit{font-size:13px;font-weight:500;color:var(--gray-400);margin-left:3px}
    .plan-cost-per{font-size:12px;color:var(--gray-400);margin:4px 0 14px}
    .plan-cost-per strong{color:var(--success);font-weight:700}
    .plan-divider{height:1px;background:var(--gray-100);margin-bottom:14px}
    .plan-price-row{display:flex;align-items:baseline;gap:3px;margin-bottom:16px}
    .plan-price-amt{font-size:28px;font-weight:900;color:var(--primary);letter-spacing:-.6px}
    .plan-price-per{font-size:12.5px;color:var(--gray-400)}
    .plan-feats{list-style:none;padding:0;margin:0 0 20px;display:flex;flex-direction:column;gap:8px;flex:1}
    .plan-feats li{font-size:13px;color:var(--gray-600);display:flex;align-items:flex-start;gap:8px;line-height:1.45}
    .feat-check{width:16px;height:16px;flex-shrink:0;margin-top:1px;color:var(--success)}

    .order-card{background:white;border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:12px;transition:all .15s;box-shadow:var(--shadow-sm)}
    .order-card:hover{border-color:var(--gray-300)}
    .order-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
    .order-ref{font-family:var(--mono);font-size:11.5px;color:var(--gray-400);margin-bottom:4px}
    .order-name{font-size:15px;font-weight:800;color:var(--gray-900)}
    .order-meta{font-size:12px;color:var(--gray-500);margin-top:3px}

    .creds-wrap{background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-top:12px}
    .creds-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-500);margin-bottom:12px;display:flex;align-items:center;gap:6px}
    .creds-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
    .cred-box{background:white;border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px}
    .cred-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-400);margin-bottom:4px}
    .cred-row{display:flex;align-items:center;gap:6px}
    .cred-val{font-family:var(--mono);font-size:12px;color:var(--gray-900);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .copy-btn{flex-shrink:0;background:none;border:none;color:var(--gray-400);cursor:pointer;padding:3px 5px;border-radius:4px;transition:.12s;line-height:1}
    .copy-btn:hover{color:var(--primary);background:var(--gray-100)}

    .usage-lbl{display:flex;justify-content:space-between;font-size:11.5px;font-weight:600;color:var(--gray-500);margin-bottom:5px}

    .page-topstrip{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap}
    .page-heading{font-size:20px;font-weight:900;color:var(--gray-900);letter-spacing:-.5px;display:flex;align-items:center;gap:9px}
    .smtp-tabs{display:flex;gap:3px;background:var(--gray-100);border-radius:9px;padding:3px;width:fit-content;margin-bottom:22px}
    .smtp-tab{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-500);text-decoration:none;transition:.14s}
    .smtp-tab.active{background:white;color:var(--gray-900);box-shadow:var(--shadow-sm)}

    .info-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:12px;margin-top:22px}
    .info-card{background:white;border:1px solid var(--border);border-radius:var(--radius);padding:14px 15px;box-shadow:var(--shadow-sm)}
    .info-icon{font-size:20px;margin-bottom:7px}
    .info-title{font-size:13px;font-weight:700;color:var(--gray-900);margin-bottom:3px}
    .info-desc{font-size:12px;color:var(--gray-500);line-height:1.55}

    .modal-box{max-width:440px}
    .sum-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--gray-100);font-size:13.5px}
    .sum-row:last-child{border:none}

    @media(max-width:640px){
      .creds-grid{grid-template-columns:1fr}
      .order-hdr{flex-direction:column}
      .plan-grid{grid-template-columns:1fr}
      .info-strip{grid-template-columns:1fr 1fr}
    }
    @media(max-width:420px){
      .info-strip{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="overlay" id="overlay"></div>

  <div class="main-content">

    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="toggleSidebar()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-size:14px;font-weight:700;color:var(--gray-900)">SMTP Email</span>
      <a href="<?= BASE_URL ?>/billing.php" class="btn btn-sm btn-secondary" style="margin-left:auto;padding:5px 10px;font-size:12px">+ Funds</a>
    </div>

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        SMTP Email
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:12.5px;color:var(--gray-500)">Balance: <strong style="color:var(--gray-900)"><?= $sym ?><?= number_format($balance,2) ?></strong></span>
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-sm btn-secondary">+ Add Funds</a>
      </div>
    </div>

    <div class="page-body">

      <!-- Stats -->
      <div class="stats-row" style="grid-template-columns:repeat(3,1fr)">
        <div class="stat-card">
          <div class="stat-label">Active Services</div>
          <div class="stat-value" style="color:var(--success)"><?= $active_count ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Total Orders</div>
          <div class="stat-value"><?= count($orders) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Emails Sent</div>
          <div class="stat-value"><?= number_format($total_sent) ?></div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="smtp-tabs">
  <a href="?tab=plans" class="smtp-tab <?= $tab==='plans' ? 'active' : '' ?>">
    Plans
  </a>

  <a href="?tab=orders" class="smtp-tab <?= $tab==='orders' ? 'active' : '' ?>">
    My Services
    <span class="section-count" style="margin-left:4px">
      <?= count(array_filter($orders, fn($o) => in_array($o['status'], ['active','pending']))) ?>
    </span>
  </a>

  <a href="?tab=history" class="smtp-tab <?= $tab==='history' ? 'active' : '' ?>">
    History
    <span class="section-count" style="margin-left:4px">
      <?= count(array_filter($orders, fn($o) => !in_array($o['status'], ['active','pending']))) ?>
    </span>
  </a>
</div>

      <?php if ($tab === 'plans'): ?>

      <div class="plan-grid">
        <?php foreach ($plans as $p): ?>
        <div class="plan-card <?= $p['is_featured']?'popular':'' ?>">
          <?php if ($p['is_featured']): ?><div class="popular-badge">MOST POPULAR</div><?php endif; ?>

          <div class="plan-name"><?= htmlspecialchars($p['name']) ?></div>

          <div>
            <span class="plan-quota-num"><?= number_format($p['emails_month']) ?></span>
            <span class="plan-quota-unit">emails/mo</span>
          </div>
          <div class="plan-cost-per">
            ≈ <strong><?= $sym ?><?= number_format($p['price'] / $p['emails_month'] * 1000, 2) ?></strong> per 1,000 emails
          </div>

          <div class="plan-divider"></div>

          <div class="plan-price-row">
            <span class="plan-price-amt"><?= $sym ?><?= number_format($p['price'], 2) ?></span>
            <span class="plan-price-per">/ <?= $p['duration_days'] ?> days</span>
          </div>

          <ul class="plan-feats">
            <?php foreach ($p['features'] as $f): ?>
            <li>
              <svg class="feat-check" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="8" fill="#dcfce7"/><path d="M5 8l2 2 4-4" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <?= htmlspecialchars($f) ?>
            </li>
            <?php endforeach; ?>
          </ul>

          <button class="btn btn-primary btn-full" onclick="openOrder(<?= htmlspecialchars(json_encode($p)) ?>)">
            Get Started
          </button>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Info strip -->
      <div class="info-strip">
        <?php foreach([
          ['🚀','Instant Setup','Credentials delivered automatically — no manual configuration needed'],
          ['🔒','Dedicated Access','Your account gets isolated sending credentials'],
          ['📊','Analytics','Track delivery, opens, bounces and complaints'],
          ['🌐','Fast Delivery','Optimised infrastructure for Indian recipients'],
        ] as [$ic,$ti,$de]): ?>
        <div class="info-card">
          <div class="info-icon"><?= $ic ?></div>
          <div class="info-title"><?= $ti ?></div>
          <div class="info-desc"><?= $de ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php else: /* ORDERS */ ?>

      <?php if (empty($orders)): ?>
      <div style="background:white;border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:52px 20px;text-align:center;box-shadow:var(--shadow-sm)">
        <div style="font-size:38px;margin-bottom:12px">📤</div>
        <div style="font-size:17px;font-weight:800;color:var(--gray-900);margin-bottom:7px">No SMTP services yet</div>
        <p style="font-size:13.5px;color:var(--gray-500);margin:0 auto 22px;max-width:340px;line-height:1.65">Purchase a plan to receive your SMTP credentials and start sending emails from your application.</p>
        <a href="?tab=plans" class="btn btn-primary">Browse Plans →</a>
      </div>
      <?php else: ?>
      <?php
if ($tab === 'orders') {
    $orders = array_filter($orders, fn($o) =>
        in_array($o['status'], ['active', 'pending'])
    );
}

if ($tab === 'history') {
    $orders = array_filter($orders, fn($o) =>
        !in_array($o['status'], ['active', 'pending'])
    );
}
?>
      <?php foreach ($orders as $o):
        $pct  = $o['emails_quota'] > 0 ? min(100, round($o['emails_sent']/$o['emails_quota']*100,1)) : 0;
        $bcls = $pct >= 90 ? 'crit' : ($pct >= 70 ? 'warn' : '');
      ?>
      <div class="order-card">
        <div class="order-hdr">
          <div>
            <div class="order-ref"><?= htmlspecialchars($o['order_ref']) ?></div>
            <div class="order-name"><?= htmlspecialchars($o['plan_name']) ?></div>
            <div class="order-meta">
              <?= $o['expires_at'] ? 'Expires '.date('d M Y', strtotime($o['expires_at'])) : '' ?>
            </div>
          </div>
          <?= smtp_badge($o['status'], (bool)$o['domain_verified']) ?>
        </div>

        <!-- Usage bar -->
        <div class="usage-lbl">
          <span>Emails Used</span>
          <span><?= number_format($o['emails_sent']) ?> / <?= number_format($o['emails_quota']) ?></span>
        </div>
        <div class="bar-track" style="margin-bottom:14px">
          <div class="bar-fill <?= $bcls ?>" style="width:<?= $pct ?>%"></div>
        </div>

        <?php if ($o['status'] === 'active' && $o['smtp_host']): ?>
        <!-- ── Active: Show credentials ── -->
        <div class="creds-wrap">
          <?php if ($o['sender_domain']): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:6px">
            <div style="display:flex;align-items:center;gap:7px">
              <span style="font-size:12px;color:var(--gray-500)">Sending domain:</span>
              <span style="font-size:13px;font-weight:700;color:var(--gray-900)"><?= htmlspecialchars($o['sender_domain']) ?></span>
              <?php if($o['domain_verified']): ?>
              <span class="badge badge-green" style="font-size:10px">✓ Verified</span>
              <?php endif; ?>
            </div>
            <button onclick="openChangeDomain(<?= $o['id'] ?>)" class="btn btn-sm btn-ghost" style="font-size:11.5px;padding:4px 10px">Change Domain</button>
          </div>
          <?php endif; ?>
          <div class="creds-title">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            SMTP Credentials
          </div>
          <div class="creds-grid">
            <?php
            $creds = [
              'Host'     => $o['smtp_host'],
              'Port'     => (string)($o['smtp_port'] ?? 587),
              'Username' => $o['smtp_username'],
              'Password' => $o['smtp_password'],
            ];
            foreach ($creds as $lbl => $val):
              if (!$val) continue;
              $eid = 'cv-'.$o['id'].'-'.md5($lbl);
            ?>
            <div class="cred-box">
              <div class="cred-lbl"><?= $lbl ?></div>
              <div class="cred-row">
                <span class="cred-val" id="<?= $eid ?>"><?= htmlspecialchars($val) ?></span>
                <button class="copy-btn" onclick="copyEl('<?= $eid ?>')" title="Copy">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:7px;margin-top:12px;flex-wrap:wrap">
            <button onclick="copyAll(<?= $o['id'] ?>)" class="btn btn-sm btn-secondary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy All
            </button>
            <button onclick="toggleCode(<?= $o['id'] ?>)" class="btn btn-sm btn-secondary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>Sample Code
            </button>
          </div>
          <div id="code-<?= $o['id'] ?>" style="display:none;margin-top:12px">
            <div style="display:flex;gap:5px;margin-bottom:8px">
              <?php foreach(['PHP','Python','cURL'] as $lang): ?>
              <button id="ctab-<?= $o['id'] ?>-<?= $lang ?>" onclick="switchCode(<?= $o['id'] ?>,'<?= $lang ?>')" class="btn btn-sm btn-ghost" style="font-size:11.5px;padding:4px 10px"><?= $lang ?></button>
              <?php endforeach; ?>
            </div>
            <div id="cblock-<?= $o['id'] ?>" class="code-block" style="font-size:11px;border-radius:var(--radius)"></div>
          </div>
        </div>

        <?php elseif ($o['status'] === 'pending' && !$o['domain_verified']): ?>
        <!-- ── Pending: Domain verification required ── -->
        <div style="background:var(--gray-50);border:1.5px solid var(--border);border-radius:var(--radius);padding:16px">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="width:36px;height:36px;border-radius:9px;background:#eff6ff;border:1px solid #bfdbfe;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">🌐</div>
            <div style="flex:1">
              <div style="font-size:14px;font-weight:700;color:var(--gray-900);margin-bottom:3px">Domain Verification Required</div>
              <div style="font-size:13px;color:var(--gray-500);margin-bottom:12px;line-height:1.55">
                Add DNS records to <strong><?= htmlspecialchars($o['sender_domain'] ?? 'your domain') ?></strong> to verify ownership and enable email sending.
              </div>
              <?php if ($o['dkim_tokens']): ?>
              <button onclick="openDnsModal(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['sender_domain'])) ?>')" class="btn btn-primary btn-sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                View DNS Records
              </button>
              <?php else: ?>
              <button onclick="loadDnsRecords(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['sender_domain'])) ?>')" class="btn btn-primary btn-sm">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.1"/></svg>
                Get DNS Records
              </button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php elseif ($o['status'] === 'suspended'): ?>
        <div class="alert alert-error">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Service suspended. <a href="<?= BASE_URL ?>/billing.php" style="color:var(--danger);font-weight:700">Add funds to reactivate →</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Order Modal -->
<div class="modal-bd" id="orderModal">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-head">
      <span class="modal-title">Get SMTP Service</span>
      <button onclick="closeModal('orderModal')" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:18px;line-height:1;padding:2px">✕</button>
    </div>
    <div id="orderSummary"></div>
    <!-- Domain input -->
    <div style="padding:0 0 4px">
      <div style="background:var(--gray-50);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:4px">
        <label class="flabel" style="margin-bottom:7px">
          Sending Domain
          <span style="font-weight:400;color:var(--gray-400);text-transform:none"> — The domain you'll send emails from</span>
        </label>
        <input type="text" id="domainInput" class="form-control" placeholder="e.g. mail.yourdomain.com"
               style="font-family:var(--mono);font-size:13px">
        <div class="field-hint hint-info" style="margin-top:7px">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Use a subdomain like <code style="background:var(--gray-200);padding:1px 5px;border-radius:3px;font-size:11px">mail.yourdomain.com</code> — You'll add 4 DNS records to verify it
        </div>
      </div>
    </div>
    <div class="modal-footer" style="padding-top:14px;border-top:1px solid var(--gray-100);margin-top:4px">
      <button onclick="closeModal('orderModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="placeOrder()" class="btn btn-primary" id="placeBtn">Continue →</button>
    </div>
  </div>
</div>

<!-- DNS Records Modal -->
<div class="modal-bd" id="dnsModal">
  <div class="modal-box" style="max-width:600px">
    <div class="modal-head">
      <div>
        <div class="modal-title">Add DNS Records</div>
        <div style="font-size:12px;color:var(--gray-400);margin-top:2px" id="dnsModalDomain"></div>
      </div>
      <button onclick="closeModal('dnsModal')" style="background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:18px;line-height:1;padding:2px">✕</button>
    </div>
    <div class="modal-body" style="margin-bottom:0;padding:0">
      <div style="background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:12px 16px;font-size:12.5px;color:#1e40af;line-height:1.6">
        <strong>Add all records below to your DNS provider</strong> (Cloudflare, GoDaddy, Namecheap etc.), then click <strong>Check Verification</strong>.
        DNS propagation can take up to 72 hours.
      </div>
      <div id="dnsRecordsTable" style="padding:16px"></div>
    </div>
    <div class="modal-footer" style="border-top:1px solid var(--gray-100)">
      <button onclick="closeModal('dnsModal')" class="btn btn-ghost">Close</button>
      <button onclick="verifyDomain()" class="btn btn-primary" id="verifyBtn">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.1"/></svg>
        Check Verification
      </button>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF='<?= $csrf ?>',BASE='<?= BASE_URL ?>',BAL=<?= $balance ?>,SYM='<?= addslashes($sym) ?>',CUR='<?= $currency ?>';
let _plan=null;

function openOrder(p){
  _plan=p;
  const price=CUR==='INR'?parseFloat(p.price_inr):parseFloat(p.price_usd);
  const ok=BAL>=price;
  const cper=(price/p.emails_month*1000).toFixed(2);
  document.getElementById('orderSummary').innerHTML=`
    <div style="padding:0 0 4px">
      <div class="sum-row"><span style="color:var(--gray-500)">Plan</span><strong>${p.name}</strong></div>
      <div class="sum-row"><span style="color:var(--gray-500)">Emails / month</span><strong>${parseInt(p.emails_month).toLocaleString()}</strong></div>
      <div class="sum-row"><span style="color:var(--gray-500)">Per 1,000 emails</span><strong style="color:var(--success)">≈ ${SYM}${cper}</strong></div>
      <div class="sum-row"><span style="color:var(--gray-500)">Total</span><strong style="color:var(--primary);font-size:18px">${SYM}${price.toFixed(2)}</strong></div>
      <div class="sum-row"><span style="color:var(--gray-500)">Wallet</span>
        <span style="color:${ok?'var(--success)':'var(--danger)'};font-weight:700">${SYM}${BAL.toFixed(2)} ${ok?'✓ Sufficient':'✗ Low balance'}</span>
      </div>
      ${!ok?`<div class="alert alert-error" style="margin:10px 0 0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Insufficient balance. <a href="${BASE}/billing.php" style="color:var(--danger);font-weight:700">Add funds →</a></div>`:''}
    </div>`;
  document.getElementById('domainInput').value='';
  document.getElementById('placeBtn').disabled=!ok;
  document.getElementById('placeBtn').textContent='Continue →';
  document.getElementById('orderModal').classList.add('open');
  if(ok) setTimeout(()=>document.getElementById('domainInput').focus(),200);
}

function placeOrder(){
  if(!_plan)return;
  const domain=document.getElementById('domainInput').value.trim();
  if(!domain){
    document.getElementById('domainInput').focus();
    document.getElementById('domainInput').style.borderColor='var(--danger)';
    setTimeout(()=>document.getElementById('domainInput').style.borderColor='',2000);
    return;
  }
  const btn=document.getElementById('placeBtn');
  btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Processing…';
  fetch(BASE+'/api/smtp-order.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,plan_id:_plan.id,domain})
  }).then(r=>r.json()).then(d=>{
    closeModal('orderModal');
    if(d.ok){
      toast('✓ Order placed! Add DNS records to verify your domain.','ok');
      setTimeout(()=>{
        location.href='?tab=orders';
      },1200);
      // Show DNS records modal after redirect would fail, so store and show on orders tab
      if(d.dns_records && d.dns_records.length){
        sessionStorage.setItem('pending_dns',JSON.stringify({order_id:d.order_id,domain:d.domain,records:d.dns_records}));
      }
    }else{
      toast('❌ '+(d.error||'Order failed'),'err');
      btn.disabled=false;btn.innerHTML='Continue →';
    }
  }).catch(()=>{toast('Network error.','err');btn.disabled=false;btn.innerHTML='Continue →';});
}

function copyEl(id){
  const el=document.getElementById(id);
  if(!el)return;
  navigator.clipboard.writeText(el.textContent.trim()).then(()=>{
    el.style.color='var(--success)';
    setTimeout(()=>el.style.color='',1000);
    toast('Copied!','ok');
  });
}

function copyAll(oid){
  const labels=['Host','Port','Username','Password'];
  const lines=[];
  document.querySelectorAll('#order-'+oid+' .cred-box, .order-card .cred-box').forEach(box=>{
    const lbl=box.querySelector('.cred-lbl')?.textContent.trim();
    const val=box.querySelector('.cred-val')?.textContent.trim();
    if(lbl&&val)lines.push(lbl+': '+val);
  });
  // fallback: grab by IDs
  if(!lines.length){
    labels.forEach(l=>{
      const el=document.getElementById('cv-'+oid+'-'+strmd5(l));
      if(el)lines.push(l+': '+el.textContent.trim());
    });
  }
  navigator.clipboard.writeText(lines.join('\n')).then(()=>toast('All credentials copied!','ok'));
}

// Simple string → id hash (mirrors PHP md5 used in element IDs)
function strmd5(s){
  // We stored IDs as cv-{orderId}-{md5(label)}
  // Scan DOM for matching label instead
  const all=document.querySelectorAll('.cred-box');
  for(const box of all){
    if(box.querySelector('.cred-lbl')?.textContent.trim()===s){
      return box.querySelector('.cred-val')?.id?.split('-').pop()||'';
    }
  }
  return '';
}

const CODE={
  PHP:(h,p,u,pw)=>
[
'<'+'?php',
'use PHPMailer\\PHPMailer\\PHPMailer;',
'',
'$mail = new PHPMailer(true);',
'$mail->isSMTP();',
`$mail->Host       = '${h}';`,
'$mail->SMTPAuth   = true;',
`$mail->Username   = '${u}';`,
`$mail->Password   = '${pw}';`,
'$mail->SMTPSecure = \'tls\';',
`$mail->Port       = ${p};`,
'$mail->setFrom(\'you@yourdomain.com\', \'Sender\');',
'$mail->addAddress(\'recipient@example.com\');',
'$mail->Subject = \'Hello!\';',
'$mail->Body    = \'Sent via GreatHost SMTP.\';',
'$mail->send();',
].join('\n'),

  Python:(h,p,u,pw)=>
`import smtplib
from email.mime.text import MIMEText

msg = MIMEText('Sent via GreatHost SMTP.')
msg['Subject'] = 'Hello!'
msg['From']    = 'you@yourdomain.com'
msg['To']      = 'recipient@example.com'

with smtplib.SMTP('${h}', ${p}) as s:
    s.starttls()
    s.login('${u}', '${pw}')
    s.send_message(msg)`,

  cURL:(h,p,u,pw)=>
`curl --ssl-reqd \\
  smtp://${h}:${p} \\
  --user "${u}:${pw}" \\
  --mail-from "you@yourdomain.com" \\
  --mail-rcpt "recipient@example.com" \\
  --upload-file - <<'EOF'
From: You <you@yourdomain.com>
To: Recipient <recipient@example.com>
Subject: Hello!

Sent via GreatHost SMTP.
EOF`,
};

function getCreds(oid){
  const creds={};
  document.querySelectorAll('.order-card .cred-box').forEach(box=>{
    // only from THIS order card
    if(!box.closest('.order-card')?.querySelector(`#code-${oid}`))return;
    const l=box.querySelector('.cred-lbl')?.textContent.trim();
    const v=box.querySelector('.cred-val')?.textContent.trim();
    if(l&&v)creds[l]=v;
  });
  // fallback: scan all visible cred IDs
  ['Host','Port','Username','Password'].forEach(l=>{
    if(creds[l])return;
    document.querySelectorAll('.cred-val').forEach(el=>{
      if(el.id.startsWith('cv-'+oid+'-'))creds[l]=el.textContent.trim();
    });
  });
  return creds;
}

function toggleCode(oid){
  const el=document.getElementById('code-'+oid);
  if(el.style.display==='none'){el.style.display='block';switchCode(oid,'PHP');}
  else el.style.display='none';
}

function switchCode(oid,lang){
  // gather creds from this order's DOM
  const h=document.getElementById('cv-'+oid+'-'+md5label(oid,'Host'))||{textContent:''};
  const p=document.getElementById('cv-'+oid+'-'+md5label(oid,'Port'))||{textContent:'587'};
  const u=document.getElementById('cv-'+oid+'-'+md5label(oid,'Username'))||{textContent:''};
  const pw=document.getElementById('cv-'+oid+'-'+md5label(oid,'Password'))||{textContent:''};
  document.getElementById('cblock-'+oid).textContent=(CODE[lang]||'')(h.textContent.trim(),p.textContent.trim(),u.textContent.trim(),pw.textContent.trim());
  ['PHP','Python','cURL'].forEach(l=>{
    const btn=document.getElementById('ctab-'+oid+'-'+l);
    if(btn){btn.style.background=l===lang?'var(--primary)':'';btn.style.color=l===lang?'white':'';btn.style.borderColor=l===lang?'var(--primary)':'';}
  });
}

function md5label(oid,label){
  // find element by scanning IDs with this oid prefix
  const prefix='cv-'+oid+'-';
  const boxes=document.querySelectorAll('[id^="'+prefix+'"]');
  for(const el of boxes){
    if(el.closest('.cred-box')?.querySelector('.cred-lbl')?.textContent.trim()===label)
      return el.id.replace(prefix,'');
  }
  return '';
}

function toast(msg,type='ok'){
  const w=document.getElementById('toastWrap');
  const t=document.createElement('div');
  t.className='toast toast-'+(type==='ok'?'ok':'err');
  const icon=type==='ok'
    ?'<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'
    :'<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>';
  t.innerHTML=`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icon}</svg>${msg}`;
  w.appendChild(t);
  setTimeout(()=>t.remove(),3000);
}

// ── DNS Records Modal ─────────────────────────────────────
let _dnsOrderId = null;

function openDnsModal(orderId, domain){
  _dnsOrderId = orderId;
  document.getElementById('dnsModalDomain').textContent = domain;
  // Load records from server
  fetch(BASE+'/api/smtp-domain.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'get_dns_records',order_id:orderId})
  }).then(r=>r.json()).then(d=>{
    if(d.ok) renderDnsRecords(d.records, d.verified);
    else toast('Failed to load DNS records','err');
  });
  document.getElementById('dnsModal').classList.add('open');
}

function loadDnsRecords(orderId, domain){
  _dnsOrderId = orderId;
  document.getElementById('dnsModalDomain').textContent = domain;
  document.getElementById('dnsRecordsTable').innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray-400)"><span class="spinner spinner-blue"></span> Loading…</div>';
  document.getElementById('dnsModal').classList.add('open');
  fetch(BASE+'/api/smtp-domain.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'get_dns_records',order_id:orderId})
  }).then(r=>r.json()).then(d=>{
    if(d.ok) renderDnsRecords(d.records, d.verified);
    else document.getElementById('dnsRecordsTable').innerHTML='<div class="alert alert-error">Failed to load records</div>';
  });
}

function renderDnsRecords(records, verified){
  if(verified){
    document.getElementById('dnsRecordsTable').innerHTML=`
      <div class="alert alert-success">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Domain verified successfully! Your SMTP service is active.
      </div>`;
    return;
  }
  const rows = records.map(r=>`
    <div style="background:white;border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:8px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
        <span style="background:${r.type==='TXT'?'#eff6ff':'#f0fdf4'};color:${r.type==='TXT'?'#1d4ed8':'#166534'};font-size:11px;font-weight:800;padding:2px 8px;border-radius:5px;font-family:var(--mono)">${r.type}</span>
        <span style="font-size:12px;font-weight:600;color:var(--gray-600)">${r.purpose}</span>
      </div>
      <div style="margin-bottom:6px">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);margin-bottom:3px">Name / Host</div>
        <div style="display:flex;align-items:center;gap:6px">
          <code style="font-family:var(--mono);font-size:11.5px;color:var(--gray-900);flex:1;word-break:break-all;background:var(--gray-50);padding:4px 8px;border-radius:4px">${r.name}</code>
          <button onclick="copyText('${r.name}')" class="copy-btn" title="Copy">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          </button>
        </div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);margin-bottom:3px">Value / Points To</div>
        <div style="display:flex;align-items:center;gap:6px">
          <code style="font-family:var(--mono);font-size:11.5px;color:var(--primary);flex:1;word-break:break-all;background:var(--gray-50);padding:4px 8px;border-radius:4px">${r.value}</code>
          <button onclick="copyText('${r.value}')" class="copy-btn" title="Copy">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          </button>
        </div>
      </div>
    </div>`).join('');
  document.getElementById('dnsRecordsTable').innerHTML = rows ||
    '<div class="alert alert-info">No records found. Try refreshing.</div>';
}

function verifyDomain(){
  if(!_dnsOrderId)return;
  const btn=document.getElementById('verifyBtn');
  btn.disabled=true;btn.innerHTML='<span class="spinner"></span> Checking…';
  fetch(BASE+'/api/smtp-domain.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'verify_domain',order_id:_dnsOrderId})
  }).then(r=>r.json()).then(d=>{
    if(d.verified){
      toast(d.message||'✓ Domain verified!','ok');
      setTimeout(()=>location.reload(),1500);
    }else{
      toast(d.message||'Not verified yet. Check DNS records and try again.','inf');
      btn.disabled=false;
      btn.innerHTML='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.1"/></svg> Check Verification';
    }
  }).catch(()=>{btn.disabled=false;btn.innerHTML='Check Verification';});
}

function openChangeDomain(orderId){
  if(!confirm('Change domain? You will need to re-verify a new domain.'))return;
  fetch(BASE+'/api/smtp-domain.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'change_domain',order_id:orderId})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error||'Failed');});
}

function copyText(text){
  navigator.clipboard.writeText(text).then(()=>toast('Copied!','ok'));
}

function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-bd').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');}));

// Auto-show DNS records modal if coming from order placement
window.addEventListener('load', function(){
  const pending = sessionStorage.getItem('pending_dns');
  if(pending && location.search.includes('tab=orders')){
    try{
      const d = JSON.parse(pending);
      sessionStorage.removeItem('pending_dns');
      setTimeout(()=>{
        _dnsOrderId = d.order_id;
        document.getElementById('dnsModalDomain').textContent = d.domain;
        renderDnsRecords(d.records, false);
        document.getElementById('dnsModal').classList.add('open');
      }, 600);
    }catch(e){}
  }
});

function toggleSidebar(){
  document.querySelector('.sidebar')?.classList.toggle('open');
  document.getElementById('overlay')?.classList.toggle('open');
}
document.getElementById('overlay')?.addEventListener('click',()=>{
  document.querySelector('.sidebar')?.classList.remove('open');
  document.getElementById('overlay')?.classList.remove('open');
});
</script>
</body>
</html>
