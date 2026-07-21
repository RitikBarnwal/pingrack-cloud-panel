<?php
// admin/smtp.php — SMTP (Amazon SES) Admin
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$fname    = htmlspecialchars($user['full_name'] ?: $user['username']);
$msg = ''; $err = '';
$csrf    = csrf_token();
$tab     = $_GET['tab'] ?? 'orders';

$plans  = db()->query("SELECT * FROM smtp_plans ORDER BY sort_order,id")->fetchAll();
$orders = db()->query(
    "SELECT o.*, p.name plan_name, u.username, u.email ue, u.full_name
     FROM smtp_orders o JOIN smtp_plans p ON p.id=o.plan_id JOIN users u ON u.id=o.user_id
     ORDER BY o.created_at DESC LIMIT 300"
)->fetchAll();

$total   = count($orders);
$pending = count(array_filter($orders,fn($o)=>$o['status']==='pending'));
$active  = count(array_filter($orders,fn($o)=>$o['status']==='active'));
$rev_inr = array_sum(array_map(fn($o)=>$o['currency']==='INR'?$o['amount_paid']:0, $orders));

$ses_ak  = get_setting('smtp_ses_access_key','');
$ses_sk  = get_setting('smtp_ses_secret_key','');
$ses_rgn = get_setting('smtp_ses_region','ap-south-1');
$auto    = get_setting('smtp_auto_activate','0');
$mod     = get_setting('smtp_module_enabled','1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>SMTP Admin — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--gray-50);color:var(--gray-900);margin:0}
    .pw{max-width:1300px;margin:0 auto;padding:28px 24px}
    .ph h1{font-size:20px;font-weight:800;margin:0 0 4px}
    .ph p{color:var(--gray-500);font-size:13px;margin:0 0 24px}
    .stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px}
    .sc{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px}
    .sc .lbl{font-size:11px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    .sc .val{font-size:22px;font-weight:900}
    .tab-bar{display:flex;gap:4px;background:var(--gray-100);border-radius:10px;padding:4px;width:fit-content;margin-bottom:22px}
    .tb{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-500);text-decoration:none;transition:.15s}
    .tb.active{background:white;color:var(--gray-900);box-shadow:0 1px 4px rgba(0,0,0,.08)}
    .tbl-wrap{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden}
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .tbl tbody tr{border-bottom:1px solid var(--gray-100);transition:background .1s}
    .tbl tbody tr:last-child{border:none}
    .tbl tbody tr:hover{background:var(--gray-50)}
    .tbl td{padding:11px 14px;vertical-align:middle}
    .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .14s}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{background:var(--primary-hover)}
    .btn-ghost{background:white;color:var(--gray-700);border:1px solid var(--border)}.btn-ghost:hover{background:var(--gray-50)}
    .btn-success{background:#16a34a;color:white}.btn-success:hover{background:#15803d}
    .btn-danger{background:#dc2626;color:white}.btn-danger:hover{background:#b91c1c}
    .btn-warn{background:#d97706;color:white}.btn-warn:hover{background:#b45309}
    .btn-amber{background:#ff9900;color:#0d1117}.btn-amber:hover{background:#e68900}
    .modal-bd{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:20px;overflow-y:auto}
    .modal-bd.open{display:flex}
    .modal-box{background:white;border-radius:14px;width:100%;max-width:600px;box-shadow:0 20px 60px rgba(0,0,0,.14);max-height:92vh;overflow-y:auto;margin:auto}
    .mh{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:1;border-radius:14px 14px 0 0}
    .mh-title{font-size:15px;font-weight:800}
    .mc{background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:18px;padding:2px 6px;border-radius:5px}.mc:hover{background:var(--gray-100)}
    .mb{padding:20px}
    .mf{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:white;border-radius:0 0 14px 14px}
    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fg.full{grid-template-columns:1fr}
    .flbl{display:block;font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
    .finp{width:100%;box-sizing:border-box;padding:9px 12px;background:white;border:1.5px solid var(--border);border-radius:8px;color:var(--gray-900);font-size:13px;font-family:inherit}
    .finp:focus{outline:none;border-color:var(--primary)}
    textarea.finp{resize:vertical;height:90px;font-size:13px}
    .fnote{font-size:11.5px;color:var(--gray-400);margin-top:4px}
    .msep{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin:16px 0 10px;display:flex;align-items:center;gap:8px}
    .msep::after{content:'';flex:1;height:1px;background:var(--border)}
    .aws-card{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:16px}
    .aws-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#0f2027,#203a43)}
    .aws-head-title{font-size:15px;font-weight:800;color:white}
    .aws-head-sub{font-size:12px;color:rgba(255,255,255,.6);margin-left:4px}
    .aws-configured{padding:4px 12px;border-radius:99px;font-size:12px;font-weight:700;margin-left:auto}
    .aws-body{padding:20px}
    .plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
    .plan-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:16px}
    .plan-card h4{font-size:14px;font-weight:800;margin:0 0 4px}
    .plan-card p{font-size:12px;color:var(--gray-500);margin:0 0 12px}
  
    @media(max-width:960px){
      .adm-main{margin-left:0 !important}
      .adm-topbar{display:none !important}
      .adm-mobile-bar{display:flex !important}
      .tbl-wrap,table{overflow-x:auto;-webkit-overflow-scrolling:touch}
    }
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
<?php require_once __DIR__ . '/sidebar.php'; ?>
<div class="adm-main">
<div class="pw">

  <div class="ph">
    <h1>📧 SMTP Email — Amazon SES</h1>
    <p>Manage plans, orders and AWS credentials</p>
  </div>

  <div class="stats-row">
    <div class="sc"><div class="lbl">Total Orders</div><div class="val"><?= $total ?></div></div>
    <div class="sc" style="border-color:#fcd34d"><div class="lbl">Pending</div><div class="val" style="color:#d97706"><?= $pending ?></div></div>
    <div class="sc" style="border-color:#86efac"><div class="lbl">Active</div><div class="val" style="color:#16a34a"><?= $active ?></div></div>
    <div class="sc" style="border-color:#ff9900"><div class="lbl">Revenue ₹</div><div class="val" style="color:#d97706">₹<?= number_format($rev_inr,0) ?></div></div>
  </div>

  <div class="tab-bar">
    <a href="?tab=orders"   class="tb <?= $tab==='orders'  ?'active':'' ?>">
      Orders<?php if($pending>0):?> <span style="background:#dc2626;color:white;font-size:10px;padding:1px 6px;border-radius:99px"><?= $pending ?></span><?php endif;?>
    </a>
    <a href="?tab=plans"    class="tb <?= $tab==='plans'   ?'active':'' ?>">Plans (<?= count($plans) ?>)</a>
    <a href="?tab=settings" class="tb <?= $tab==='settings'?'active':'' ?>">⚙️ AWS Settings</a>
  </div>

  <!-- ═══ ORDERS ═══ -->
  <?php if ($tab === 'orders'): ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Ref</th><th>User</th><th>Plan</th><th>Region</th><th>Status</th><th>IAM User</th><th>Amount</th><th>Expires</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if(empty($orders)): ?>
        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--gray-400)">No orders yet</td></tr>
        <?php else: foreach($orders as $o):
          $sl=['pending'=>'background:#fef3c7;color:#92400e','active'=>'background:#dcfce7;color:#166534','suspended'=>'background:#ffedd5;color:#9a3412','expired'=>'background:#f1f5f9;color:#475569','cancelled'=>'background:#fee2e2;color:#991b1b'];
          $ss=$sl[$o['status']]??'background:#f1f5f9;color:#475569';
        ?>
        <tr>
          <td><code style="font-size:11px;color:var(--primary);font-family:'JetBrains Mono',monospace"><?= $o['order_ref'] ?></code></td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($o['full_name']?:$o['username']) ?></div>
            <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($o['ue']) ?></div>
          </td>
          <td style="font-size:12.5px"><?= htmlspecialchars($o['plan_name']) ?></td>
          <td style="font-size:12px;color:var(--gray-500)"><?= htmlspecialchars($o['aws_region']??'ap-south-1') ?></td>
          <td><span style="<?= $ss ?>;font-size:10px;font-weight:700;padding:2px 9px;border-radius:99px"><?= ucfirst($o['status']) ?></span></td>
          <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--gray-500)"><?= htmlspecialchars($o['iam_username']??'—') ?></td>
          <td style="font-weight:700">₹<?= number_format($o['amount_paid'],2) ?></td>
          <td style="font-size:11.5px;color:var(--gray-500)"><?= $o['expires_at']?date('d M Y',strtotime($o['expires_at'])):'—' ?></td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              <button onclick='openManage(<?= htmlspecialchars(json_encode($o)) ?>)' class="btn btn-primary">Manage</button>
              <?php if($o['status']==='pending'): ?>
              <button onclick="provisionNow(<?= $o['id'] ?>)" class="btn btn-amber">⚡ Provision</button>
              <?php endif; ?>
              <?php if(in_array($o['status'],['active','pending']) && $o['iam_username']): ?>
              <button onclick="cancelOrder(<?= $o['id'] ?>)" class="btn btn-danger">Cancel</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ═══ PLANS ═══ -->
  <?php elseif ($tab === 'plans'): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div style="font-size:13px;color:var(--gray-500)"><?= count($plans) ?> plans</div>
    <button onclick="openPlan(null)" class="btn btn-primary">+ Add Plan</button>
  </div>
  <div class="plans-grid">
    <?php foreach($plans as $p): ?>
    <div class="plan-card" style="<?= $p['is_active']?'':'opacity:.5' ?>">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px">
        <span style="background:#ff990018;color:#cc7a00;font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px">☁️ Amazon SES</span>
        <?php if($p['is_featured']): ?><span style="background:#ede9fe;color:#6d28d9;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">★ Popular</span><?php endif; ?>
      </div>
      <h4><?= htmlspecialchars($p['name']) ?></h4>
      <p><?= number_format($p['emails_month']) ?> emails/mo · ₹<?= number_format($p['price_inr'],0) ?>/mo</p>
      <div style="display:flex;gap:5px;flex-wrap:wrap">
        <button onclick='openPlan(<?= htmlspecialchars(json_encode($p)) ?>)' class="btn btn-ghost">Edit</button>
        <button onclick="togglePlan(<?= $p['id'] ?>,<?= $p['is_active']?0:1 ?>)" class="btn <?= $p['is_active']?'btn-warn':'btn-success' ?>"><?= $p['is_active']?'Disable':'Enable' ?></button>
        <button onclick="deletePlan(<?= $p['id'] ?>)" class="btn btn-danger">Delete</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══ SETTINGS ═══ -->
  <?php else: ?>

  <!-- Global -->
  <div class="aws-card" style="border-color:var(--border)">
    <div style="background:white;padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
      <div style="font-size:14px;font-weight:800">⚙️ Global Settings</div>
    </div>
    <div class="aws-body">
      <div class="fg">
        <div><label class="flbl">Module</label>
          <select id="gs_mod" class="finp">
            <option value="1" <?= $mod==='1'?'selected':'' ?>>✓ Enabled</option>
            <option value="0" <?= $mod==='0'?'selected':'' ?>>✗ Disabled</option>
          </select></div>
        <div><label class="flbl">Auto-Activate</label>
          <select id="gs_auto" class="finp">
            <option value="0" <?= $auto==='0'?'selected':'' ?>>✗ Manual (Admin provisions)</option>
            <option value="1" <?= $auto==='1'?'selected':'' ?>>⚡ Auto (AWS IAM API)</option>
          </select>
          <div class="fnote">Auto = IAM user created instantly on order</div>
        </div>
      </div>
      <div style="text-align:right"><button onclick="saveGlobal()" class="btn btn-primary" id="gsBtn">Save</button></div>
    </div>
  </div>

  <!-- AWS Credentials -->
  <div class="aws-card">
    <div class="aws-head">
      <div style="font-size:28px">☁️</div>
      <div>
        <div class="aws-head-title">Amazon Web Services</div>
        <div class="aws-head-sub">IAM + SES Configuration</div>
      </div>
      <span class="aws-configured" style="background:<?= ($ses_ak&&$ses_sk)?'#dcfce7':'#fef3c7' ?>;color:<?= ($ses_ak&&$ses_sk)?'#166534':'#92400e' ?>">
        <?= ($ses_ak&&$ses_sk) ? '● Configured' : '⚠ Not configured' ?>
      </span>
    </div>
    <div class="aws-body">
      <!-- Setup guide -->
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px;color:#1e40af;line-height:1.8">
        <div style="font-weight:800;margin-bottom:8px">AWS Setup Guide</div>
        <div>1. <a href="https://console.aws.amazon.com/iam/home#/users" target="_blank" style="color:#1d4ed8;font-weight:700">AWS IAM Console</a> → Create user: <code style="background:#dbeafe;padding:1px 5px;border-radius:4px">greathost-smtp-admin</code></div>
        <div>2. Attach policies: <code style="background:#dbeafe;padding:1px 5px;border-radius:4px">IAMFullAccess</code> + <code style="background:#dbeafe;padding:1px 5px;border-radius:4px">AmazonSESFullAccess</code></div>
        <div>3. Create Access Key → copy Access Key ID + Secret Access Key below</div>
        <div>4. <a href="https://console.aws.amazon.com/ses/home?region=ap-south-1#verified-senders-email" target="_blank" style="color:#1d4ed8;font-weight:700">SES Console</a> → Request production access (removes sandbox)</div>
      </div>

      <div class="fg">
        <div><label class="flbl">Access Key ID</label>
          <input type="text" id="ses_ak" class="finp" value="<?= htmlspecialchars($ses_ak) ?>" placeholder="AKIAIOSFODNN7EXAMPLE">
        </div>
        <div><label class="flbl">Secret Access Key</label>
          <input type="password" id="ses_sk" class="finp" value="<?= htmlspecialchars($ses_sk) ?>" placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY">
        </div>
      </div>
      <div class="fg">
        <div><label class="flbl">Default Region</label>
          <select id="ses_rgn" class="finp">
            <option value="ap-south-1"   <?= $ses_rgn==='ap-south-1'  ?'selected':'' ?>>🇮🇳 ap-south-1 (Mumbai) — Recommended</option>
            <option value="us-east-1"    <?= $ses_rgn==='us-east-1'   ?'selected':'' ?>>🇺🇸 us-east-1 (N. Virginia)</option>
            <option value="us-west-2"    <?= $ses_rgn==='us-west-2'   ?'selected':'' ?>>🇺🇸 us-west-2 (Oregon)</option>
            <option value="eu-west-1"    <?= $ses_rgn==='eu-west-1'   ?'selected':'' ?>>🇪🇺 eu-west-1 (Ireland)</option>
            <option value="ap-southeast-1" <?= $ses_rgn==='ap-southeast-1'?'selected':'' ?>>🇸🇬 ap-southeast-1 (Singapore)</option>
          </select>
          <div class="fnote">Users get SMTP endpoint for this region — Mumbai best for India</div>
        </div>
        <div style="display:flex;align-items:flex-end">
          <button onclick="saveAWS()" class="btn btn-primary" id="awsBtn">Save AWS Credentials</button>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div></div>

<!-- ── MANAGE ORDER MODAL ── -->
<div class="modal-bd" id="manageModal">
  <div class="modal-box">
    <div class="mh"><span class="mh-title">Manage Order</span><button class="mc" onclick="closeModal('manageModal')">✕</button></div>
    <div class="mb">
      <div id="oInfo" style="background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:13px"></div>
      <input type="hidden" id="mm_id">
      <div class="fg">
        <div><label class="flbl">Status</label>
          <select id="mm_status" class="finp">
            <?php foreach(['pending','active','suspended','expired','cancelled'] as $s): ?>
            <option value="<?=$s?>"><?=ucfirst($s)?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="flbl">Expires At</label><input type="date" id="mm_exp" class="finp"></div>
      </div>
      <div class="msep">AWS / SMTP Credentials</div>
      <div class="fg">
        <div><label class="flbl">SMTP Host</label><input type="text" id="mm_host" class="finp" placeholder="email-smtp.ap-south-1.amazonaws.com"></div>
        <div><label class="flbl">SMTP Port</label><input type="number" id="mm_port" class="finp" value="587"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">SMTP Username (IAM AK)</label><input type="text" id="mm_user" class="finp"></div>
        <div><label class="flbl">SMTP Password (Derived)</label><input type="text" id="mm_pass" class="finp"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">AWS Access Key</label><input type="text" id="mm_ak" class="finp"></div>
        <div><label class="flbl">AWS Secret Key</label><input type="text" id="mm_sk" class="finp"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">AWS Region</label><input type="text" id="mm_rgn" class="finp" value="ap-south-1"></div>
        <div><label class="flbl">IAM Username</label><input type="text" id="mm_iam" class="finp"></div>
      </div>
      <div class="fg full"><div><label class="flbl">Notes</label><textarea id="mm_notes" class="finp" style="height:60px"></textarea></div></div>
    </div>
    <div class="mf">
      <button onclick="closeModal('manageModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveOrder()" class="btn btn-primary" id="saveOrdBtn">Save</button>
    </div>
  </div>
</div>

<!-- ── PLAN MODAL ── -->
<div class="modal-bd" id="planModal">
  <div class="modal-box">
    <div class="mh"><span class="mh-title" id="pmTitle">Add Plan</span><button class="mc" onclick="closeModal('planModal')">✕</button></div>
    <div class="mb">
      <input type="hidden" id="pm_id">
      <div class="fg">
        <div><label class="flbl">Name</label><input type="text" id="pm_name" class="finp" placeholder="Starter"></div>
        <div><label class="flbl">Emails/Month</label><input type="number" id="pm_quota" class="finp" placeholder="10000"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">Price INR (₹)</label><input type="number" id="pm_inr" class="finp" step="0.01"></div>
        <div><label class="flbl">Price USD ($)</label><input type="number" id="pm_usd" class="finp" step="0.0001"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">Duration (days)</label><input type="number" id="pm_days" class="finp" value="30"></div>
        <div><label class="flbl">Sort Order</label><input type="number" id="pm_sort" class="finp" value="0"></div>
      </div>
      <div class="fg full"><div><label class="flbl">Features <span style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--gray-400)">(one per line)</span></label>
        <textarea id="pm_feats" class="finp">Amazon SES powered
SMTP + AWS SDK access
ap-south-1 Mumbai region
99.9% delivery SLA</textarea></div></div>
      <div style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="pm_feat" style="accent-color:var(--primary)">
        <label for="pm_feat" style="font-size:13px;font-weight:600;color:var(--gray-700);cursor:pointer">Mark as Popular</label>
      </div>
    </div>
    <div class="mf">
      <button onclick="closeModal('planModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="savePlan()" class="btn btn-primary" id="savePlanBtn">Save Plan</button>
    </div>
  </div>
</div>

<script>
const CSRF='<?= $csrf ?>',BASE='<?= BASE_URL ?>';

function openManage(o){
  document.getElementById('mm_id').value    =o.id;
  document.getElementById('mm_status').value=o.status;
  document.getElementById('mm_exp').value   =o.expires_at?o.expires_at.substr(0,10):'';
  document.getElementById('mm_host').value  =o.smtp_host||'';
  document.getElementById('mm_port').value  =o.smtp_port||587;
  document.getElementById('mm_user').value  =o.smtp_username||'';
  document.getElementById('mm_pass').value  =o.smtp_password||'';
  document.getElementById('mm_ak').value    =o.aws_access_key||'';
  document.getElementById('mm_sk').value    =o.aws_secret_key||'';
  document.getElementById('mm_rgn').value   =o.aws_region||'ap-south-1';
  document.getElementById('mm_iam').value   =o.iam_username||'';
  document.getElementById('mm_notes').value =o.notes||'';
  document.getElementById('oInfo').innerHTML=`<strong>${o.order_ref}</strong> · ${o.username} · ${o.plan_name} · ₹${parseFloat(o.amount_paid).toFixed(2)}`;
  document.getElementById('manageModal').classList.add('open');
}
function saveOrder(){
  const btn=document.getElementById('saveOrdBtn');
  btn.disabled=true;btn.textContent='Saving…';
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'update_order',
      id:document.getElementById('mm_id').value,
      status:document.getElementById('mm_status').value,
      expires_at:document.getElementById('mm_exp').value,
      smtp_host:document.getElementById('mm_host').value,
      smtp_port:document.getElementById('mm_port').value,
      smtp_username:document.getElementById('mm_user').value,
      smtp_password:document.getElementById('mm_pass').value,
      aws_access_key:document.getElementById('mm_ak').value,
      aws_secret_key:document.getElementById('mm_sk').value,
      aws_region:document.getElementById('mm_rgn').value,
      iam_username:document.getElementById('mm_iam').value,
      notes:document.getElementById('mm_notes').value,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok)location.reload();
    else{alert(d.error||'Failed');btn.disabled=false;btn.textContent='Save';}
  });
}
function provisionNow(id){
  if(!confirm('Provision via AWS IAM API?'))return;
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'provision_order',id})
  }).then(r=>r.json()).then(d=>{
    if(d.ok){alert('✅ '+(d.message||'Done!'));location.reload();}
    else alert('❌ '+(d.error||'Failed'));
  });
}
function cancelOrder(id){
  if(!confirm('Cancel order and delete IAM user from AWS?'))return;
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'cancel_order',id})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error||'Failed');});
}
function openPlan(p){
  document.getElementById('pmTitle').textContent=p?'Edit Plan':'Add Plan';
  document.getElementById('pm_id').value   =p?.id||'';
  document.getElementById('pm_name').value =p?.name||'';
  document.getElementById('pm_quota').value=p?.emails_month||10000;
  document.getElementById('pm_inr').value  =p?.price_inr||'';
  document.getElementById('pm_usd').value  =p?.price_usd||'';
  document.getElementById('pm_days').value =p?.duration_days||30;
  document.getElementById('pm_sort').value =p?.sort_order||0;
  document.getElementById('pm_feat').checked=p?.is_featured==1;
  let f=[];try{f=JSON.parse(p?.features||'[]');}catch(e){}
  document.getElementById('pm_feats').value=f.join('\n');
  document.getElementById('planModal').classList.add('open');
}
function savePlan(){
  const btn=document.getElementById('savePlanBtn');
  btn.disabled=true;btn.textContent='Saving…';
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'save_plan',
      id:document.getElementById('pm_id').value,
      name:document.getElementById('pm_name').value,
      emails_month:document.getElementById('pm_quota').value,
      price_inr:document.getElementById('pm_inr').value,
      price_usd:document.getElementById('pm_usd').value,
      duration_days:document.getElementById('pm_days').value,
      sort_order:document.getElementById('pm_sort').value,
      features:document.getElementById('pm_feats').value,
      is_featured:document.getElementById('pm_feat').checked?1:0,
      is_active:1,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok)location.reload();
    else{alert(d.error||'Failed');btn.disabled=false;btn.textContent='Save Plan';}
  });
}
function togglePlan(id,v){
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'toggle_plan',id,is_active:v})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error);});
}
function deletePlan(id){
  if(!confirm('Delete?'))return;
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'delete_plan',id})
  }).then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert(d.error||'Active orders exist');});
}
function saveGlobal(){
  const btn=document.getElementById('gsBtn');
  btn.disabled=true;btn.textContent='Saving…';
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'save_settings',
      smtp_module_enabled:document.getElementById('gs_mod').value,
      smtp_auto_activate:document.getElementById('gs_auto').value,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok){btn.textContent='Saved ✓';setTimeout(()=>{btn.disabled=false;btn.textContent='Save';},2000);}
    else{alert(d.error);btn.disabled=false;btn.textContent='Save';}
  });
}
function saveAWS(){
  const btn=document.getElementById('awsBtn');
  btn.disabled=true;btn.textContent='Saving…';
  fetch(BASE+'/api/smtp-admin-action.php',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({csrf:CSRF,action:'save_settings',
      smtp_ses_access_key:document.getElementById('ses_ak').value,
      smtp_ses_secret_key:document.getElementById('ses_sk').value,
      smtp_ses_region:document.getElementById('ses_rgn').value,
    })
  }).then(r=>r.json()).then(d=>{
    if(d.ok){btn.textContent='Saved ✓';setTimeout(()=>{btn.disabled=false;btn.textContent='Save AWS Credentials';},2000);}
    else{alert(d.error);btn.disabled=false;btn.textContent='Save AWS Credentials';}
  });
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-bd').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');});});
</script>
</body>
</html>
