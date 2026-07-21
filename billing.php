<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$currency = strtoupper($user['currency'] ?? 'INR');
$curr_sym = currency_symbol($currency);
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = (float)$user['wallet_balance'];
$csrf     = csrf_token();

// --- Pagination Logic ---
$limit_tx = 15; // Transactions per page
$page_tx  = isset($_GET['p_tx']) ? max(1, (int)$_GET['p_tx']) : 1;
$offset_tx = ($page_tx - 1) * $limit_tx;

$limit_inv = 10; // Invoices per page
$page_inv  = isset($_GET['p_inv']) ? max(1, (int)$_GET['p_inv']) : 1;
$offset_inv = ($page_inv - 1) * $limit_inv;

// Total counts for pagination
$total_tx = db()->prepare("SELECT COUNT(*) FROM transactions WHERE user_id=?");
$total_tx->execute([$user['id']]);
$total_tx_count = $total_tx->fetchColumn();
$total_tx_pages = ceil($total_tx_count / $limit_tx);

$total_inv = db()->prepare("SELECT COUNT(*) FROM invoices WHERE user_id=?");
$total_inv->execute([$user['id']]);
$total_inv_count = $total_inv->fetchColumn();
$total_inv_pages = ceil($total_inv_count / $limit_inv); 

// Razorpay config
$rzp_key_id      = get_setting('razorpay_key_id', '');
$rzp_enabled     = !empty($rzp_key_id) && !empty(get_setting('razorpay_key_secret', ''));
$gateway_fee_pct = (float)get_setting('payment_gateway_fee_pct', '2');
$min_deposit_inr = (float)get_setting('min_deposit', '100'); // Always stored in INR
// Convert to user's currency for display/validation
if ($currency === 'INR') {
    $min_deposit = $min_deposit_inr;
} else {
    // Convert INR -> USD (or other currency) using cached rate
    $inr_usd_rate = get_rate('INR', 'USD');
    $min_deposit_usd = round($min_deposit_inr * $inr_usd_rate, 2);
    if ($currency === 'USD') {
        $min_deposit = $min_deposit_usd;
    } else {
        $rate = get_rate('USD', $currency);
        $min_deposit = round($min_deposit_usd * $rate, 2);
    }
    // Minimum $1 for non-INR
    $min_deposit = max($min_deposit, 1.0);
}
$gateway_name    = get_setting('payment_gateway_name', 'Razorpay');

// Stripe config
$stripe_enabled       = get_setting('stripe_enabled', '0') === '1'
                        && !empty(get_setting('stripe_publishable_key'))
                        && !empty(get_setting('stripe_secret_key'));
$stripe_pub_key       = get_setting('stripe_publishable_key', '');
$stripe_fee_pct       = (float)get_setting('stripe_fee_pct', '2.9');

// PayPal config
$paypal_enabled       = get_setting('paypal_enabled', '0') === '1'
                        && !empty(get_setting('paypal_client_id'))
                        && !empty(get_setting('paypal_client_secret'));
$paypal_client_id     = get_setting('paypal_client_id', '');
$paypal_mode          = get_setting('paypal_mode', 'sandbox');
$paypal_fee_pct       = (float)get_setting('paypal_fee_pct', '3.5');

// Which gateways are available
$gateways_available   = array_filter([
    'razorpay' => $rzp_enabled,
    'stripe'   => $stripe_enabled,
    'paypal'   => $paypal_enabled,
]);

// GST config for billing page fee breakdown
$gst_enabled        = get_setting('gst_enabled', '1') === '1';
$gst_rate           = (float)get_setting('gst_rate', '18');
$company_gst_state  = trim(get_setting('company_gst_state', ''));
$user_state         = trim($user['state'] ?? '');
$user_country       = strtoupper($user['country'] ?? 'IN');
// Determine GST type for this user
$gst_applicable = $gst_enabled && $gst_rate > 0 && $user_country === 'IN';
$gst_type = 'none';
if ($gst_applicable) {
    if (!empty($company_gst_state) && !empty($user_state) &&
        strtolower($company_gst_state) === strtolower($user_state)) {
        $gst_type = 'SGST+CGST'; // same state
    } else {
        $gst_type = 'IGST'; // different state
    }
}

// Load transactions
$show_usage = isset($_GET['show_usage']) && $_GET['show_usage'] == '1';

$sql = "SELECT * FROM transactions WHERE user_id=?";

if (!$show_usage) {
    $sql .= " AND description NOT LIKE 'Hourly billing — %'";
}

$sql .= " ORDER BY created_at DESC LIMIT 50";

$transactions = db()->prepare($sql);
$transactions->execute([$user['id']]);
$transactions = $transactions->fetchAll() ?: [];

// Load invoices
$invoices = db()->prepare(
    "SELECT * FROM invoices WHERE user_id=? ORDER BY created_at DESC LIMIT 20"
);
$invoices->execute([$user['id']]);
$invoices = $invoices->fetchAll() ?: [];

// Quick amounts based on currency
$quick_amounts = $currency === 'INR'
    ? [$min_deposit, 500, 1000, 2000, 5000]
    : [10, 25, 50, 100];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Billing & Wallet — <?= $app_name ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <?php if ($rzp_enabled): ?>
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
  <?php endif; ?>
  <?php if ($stripe_enabled): ?>
  <script src="https://js.stripe.com/v3/"></script>
  <?php endif; ?>
  <?php if ($paypal_enabled): ?>
  <script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($paypal_client_id) ?>&currency=USD"></script>
  <?php endif; ?>
  <style>
    .page-wrap{padding:24px;max-width:960px}

    /* Wallet card */
    .wallet-hero{background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:16px;padding:28px 32px;color:white;margin-bottom:22px;position:relative;overflow:hidden}
    .wallet-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.04)}
    .wallet-hero::after{content:'';position:absolute;bottom:-60px;right:60px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.03)}
    .wh-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.9px;color:rgba(255,255,255,.5);margin-bottom:8px}
    .wh-balance{font-size:42px;font-weight:900;letter-spacing:-2px;line-height:1;color:white}
    .wh-currency{font-size:20px;font-weight:600;color:rgba(255,255,255,.6);margin-right:4px}
    .wh-meta{display:flex;gap:18px;margin-top:16px;flex-wrap:wrap}
    .wh-meta-item{font-size:12.5px;color:rgba(255,255,255,.5);display:flex;align-items:center;gap:5px}
    .wh-meta-item strong{color:rgba(255,255,255,.85)}

    /* Add funds card */
    .add-funds-card{background:white;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:20px}
    .add-funds-head{padding:16px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
    .add-funds-title{font-size:14px;font-weight:800;color:#1e293b}
    .add-funds-body{padding:22px}

    /* Quick amount buttons */
    .quick-amts{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}
    .qa-btn{padding:9px 18px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px;font-weight:700;color:#475569;background:white;cursor:pointer;transition:all .14s;font-family:inherit}
    .qa-btn:hover,.qa-btn.active{border-color:var(--primary);background:#f4f4f4;color:var(--primary);box-shadow:0 0 0 3px #17171717;}

    /* Amount input row */
    .amt-row{display:flex;gap:10px;align-items:flex-start;margin-bottom:14px}
    .amt-input-wrap{position:relative;flex:1;max-width:280px}
    .amt-prefix{position:absolute;left:13px;top:50%;transform:translateY(-50%);font-size:16px;font-weight:800;color:#94a3b8;pointer-events:none}
    .amt-input{width:100%;padding:12px 14px 12px 32px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700;color:#1e293b;outline:none;transition:border-color .14s}
    .amt-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px #17171717}

    /* Fee breakdown */
    .fee-breakdown{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:18px;font-size:13px}
    .fee-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0}
    .fee-row.total{font-weight:800;color:#1e293b;border-top:1px solid #e2e8f0;margin-top:6px;padding-top:10px;font-size:14px}
    .fee-lbl{color:#64748b}
    .fee-val{font-family:'JetBrains Mono',monospace;font-weight:700;color:#1e293b}

    /* Pay button */
    .pay-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;max-width:280px;padding:13px 24px;background:var(--primary);color:white;border:none;border-radius:10px;font-size:15px;font-weight:800;font-family:inherit;cursor:pointer;transition:all .16s;box-shadow:0 0 0 3px #17171717}
    .pay-btn:hover{background:var(--primary-hover);transform:translateY(-1px);box-shadow:0 0 0 3px #17171717}
    .pay-btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}
    .pay-btn svg{width:16px;height:16px}
    .rzp-powered{font-size:11px;color:#94a3b8;margin-top:8px;display:flex;align-items:center;gap:4px}

    /* Gateway not configured */
    .gw-notice{background:#fef9c3;border:1.5px solid #fde047;border-radius:10px;padding:14px 16px;font-size:13px;color:#854d0e;margin-bottom:16px}

    /* Cards general */
    .card{background:white;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:20px}
    .card-head{padding:14px 22px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.7px}

    /* Transaction table */
    .tx-tbl{width:100%;border-collapse:collapse}
    .tx-tbl thead th{padding:10px 16px;text-align:left;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;background:#f8fafc;border-bottom:1px solid #f1f5f9}
    .tx-tbl tbody tr{border-bottom:1px solid #f8fafc;transition:background .12s}
    .tx-tbl tbody tr:last-child{border:none}
    .tx-tbl tbody tr:hover{background:#f8fafc}
    .tx-tbl td{padding:12px 16px;font-size:13px;vertical-align:middle}
    .tx-credit{color:#16a34a;font-weight:800}
    .tx-debit{color:#dc2626;font-weight:800}
    .tx-ref{font-size:11px;color:#94a3b8;font-family:monospace;margin-top:2px}

    /* Low balance */
    .low-balance-banner{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:20px}
.btn-pdf {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    background: #eff6ff;
    color: var(--primary);
    text-decoration: none;
    border: 1px solid #bfdbfe;
    transition: all .14s;
    white-space: nowrap;
}
    @keyframes spin{to{transform:rotate(360deg)}}
    /* Pagination Styles */
    .pagination-wrap { padding: 16px 22px; border-top: 1px solid #f1f5f9; display: flex; justify-content: center; gap: 5px; }
    .pg-link { padding: 6px 12px; border-radius: 6px; border: 1px solid #e2e8f0; font-size: 13px; font-weight: 700; text-decoration: none; color: #64748b; transition: 0.2s; }
    .pg-link:hover { background: #f8fafc; border-color: var(--primary); color: var(--primary); }
    .pg-link.active { background: var(--primary); color: white; border-color: var(--primary); }
    .pg-link.disabled { opacity: 0.5; pointer-events: none; }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-content" style="margin-left:260px;min-height:100vh;background:#f0f2f5">
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">Billing & Wallet</span>
    </div>
    <div class="topbar"><span class="topbar-title">Billing & Wallet</span></div>

    <div class="page-wrap">

      <?php if ($balance < 5): ?>
      <div class="low-balance-banner">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
          <div style="font-size:13.5px;font-weight:800;color:#991b1b">Low wallet balance</div>
          <div style="font-size:12.5px;color:#dc2626;margin-top:2px">Your balance is critically low. Add funds to prevent server suspension.</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Wallet hero -->
      <div class="wallet-hero">
        <div class="wh-label">Available Balance</div>
        <div class="wh-balance">
          <span class="wh-currency"><?= $curr_sym ?></span><?= number_format($balance, 6) ?>
        </div>
        <div class="wh-meta">
          <div class="wh-meta-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Currency: <strong><?= $currency ?></strong>
          </div>
          <div class="wh-meta-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Billed hourly per running server
          </div>
          <?php if ($balance > 0): ?>
          <div class="wh-meta-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Account active
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Add Funds -->
      <div class="add-funds-card">
        <div class="add-funds-head">
          <div style="width:32px;height:32px;border-radius:8px;background:#17171717;display:flex;align-items:center;justify-content:center;font-size:15px">💳</div>
          <div class="add-funds-title">Add Funds to Wallet</div>
          <?php if ($rzp_enabled): ?>
          <div style="margin-left:auto;display:flex;align-items:center;gap:5px;font-size:11.5px;color:#94a3b8">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Secured by <?php if (htmlspecialchars($gateway_name) === "Razorpay") : ?>
            <svg width="1896px" height="401px" viewBox="0 0 1896 401" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="max-height: 20px;width: unset;align-self: center;">
    <!-- Generator: Sketch 46.2 (44496) - http://www.bohemiancoding.com/sketch -->
    <title>Group</title>
    <desc>Created with Sketch.</desc>
    <defs></defs>
    <g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
        <g id="Group">
            <path d="M451.9209,151.4937 C448.9309,162.6497 443.1359,170.8377 434.5349,176.0657 C425.9239,181.2927 413.8469,183.9117 398.2689,183.9117 L348.7769,183.9117 L366.1509,119.0807 L415.6429,119.0807 C431.2089,119.0807 441.8959,121.6947 447.7019,126.9217 C453.4969,132.1547 454.9109,140.3437 451.9209,151.4937 M503.1739,150.0967 C509.4679,126.6377 506.8589,108.6267 495.3509,96.0797 C483.8409,83.5327 463.6739,77.2627 434.8709,77.2627 L324.3909,77.2627 L257.8969,325.4027 L311.5719,325.4027 L338.3809,225.3777 L373.5809,225.3777 C381.4739,225.3777 387.6869,226.6577 392.2309,229.2137 C396.7849,231.7747 399.4509,236.3067 400.2739,242.8037 L409.8479,325.4027 L467.3589,325.4027 L458.0289,248.3847 C456.1279,231.1897 448.2589,221.0827 434.4309,218.0637 C452.0599,212.9577 466.8259,204.4677 478.7179,192.6167 C490.5989,180.7717 498.7579,166.6017 503.1739,150.0967" id="Fill-1" fill="#072654"></path>
            <path d="M633.625,236.533 C629.14,253.258 622.231,266.042 612.901,274.868 C603.56,283.7 592.386,288.111 579.382,288.111 C566.122,288.111 557.128,283.758 552.387,275.042 C547.623,266.332 547.461,253.733 551.889,237.228 C556.305,220.735 563.352,207.841 573.053,198.539 C582.742,189.255 594.09,184.602 607.105,184.602 C620.11,184.602 628.919,189.082 633.485,198.024 C638.053,206.966 638.11,219.802 633.625,236.533 L633.625,236.533 Z M657.153,148.706 L650.431,173.8 C647.521,164.736 641.9,157.538 633.578,152.195 C625.245,146.852 614.918,144.174 602.608,144.174 C587.506,144.174 572.983,148.069 559.052,155.852 C545.12,163.64 532.938,174.617 522.519,188.786 C512.099,202.961 504.461,219.107 499.604,237.228 C494.748,255.356 493.774,271.328 496.695,285.149 C499.616,298.977 505.944,309.605 515.691,317.04 C525.428,324.481 537.969,328.19 553.303,328.19 C565.612,328.19 577.342,325.635 588.469,320.523 C599.595,315.418 609.041,308.325 616.818,299.266 L609.807,325.403 L661.731,325.403 L709.079,148.706 L657.153,148.706 Z" id="Fill-3" fill="#072654"></path>
            <polygon id="Fill-5" fill="#072654" points="895.79 148.7061 744.882 148.7061 734.334 188.0911 822.155 188.0911 706.042 288.4581 696.132 325.4031 851.92 325.4031 862.478 286.0241 768.388 286.0241 886.263 184.2541"></polygon>
            <path d="M1028.6514,236.1853 C1023.9804,253.6053 1017.0604,266.6273 1007.9044,275.2223 C998.7484,283.8163 987.6674,288.1103 974.6634,288.1103 C947.4714,288.1103 938.5234,270.8113 947.7964,236.1853 C952.4094,218.9903 959.3634,206.0383 968.6594,197.3283 C977.9654,188.6123 989.2324,184.2543 1002.4804,184.2543 C1015.4844,184.2543 1024.2584,188.6123 1028.7794,197.3283 C1033.2984,206.0383 1033.2644,218.9903 1028.6514,236.1853 M1059.0304,155.3243 C1047.0804,147.8943 1031.8154,144.1743 1013.2244,144.1743 C994.4014,144.1743 976.9694,147.8943 960.9174,155.3243 C944.8644,162.7653 931.1984,173.4523 919.9214,187.3893 C908.6314,201.3323 900.4954,217.5943 895.5114,236.1853 C890.5274,254.7763 889.9484,271.0323 893.7734,284.9753 C897.5864,298.9183 905.5144,309.6053 917.5914,317.0403 C929.6574,324.4813 945.0954,328.1903 963.9194,328.1903 C982.5094,328.1903 999.7674,324.4813 1015.7054,317.0403 C1031.6184,309.6053 1045.2374,298.9183 1056.5264,284.9753 C1067.8034,271.0323 1075.9404,254.7763 1080.9244,236.1853 C1085.9084,217.5943 1086.4884,201.3323 1082.6744,187.3893 C1078.8494,173.4523 1070.9674,162.7653 1059.0304,155.3243" id="Fill-7" fill="#072654"></path>
            <path d="M1602.1367,236.533 C1597.6517,253.258 1590.7427,266.042 1581.4127,274.868 C1572.0817,283.7 1560.8857,288.111 1547.8817,288.111 C1534.6457,288.111 1525.6397,283.758 1520.8987,275.042 C1516.1347,266.332 1515.9727,253.733 1520.4007,237.228 C1524.8167,220.735 1531.8637,207.841 1541.5647,198.539 C1551.2537,189.255 1562.6017,184.602 1575.6167,184.602 C1588.6217,184.602 1597.4307,189.082 1601.9967,198.024 C1606.5647,206.966 1606.6217,219.802 1602.1367,236.533 L1602.1367,236.533 Z M1625.6647,148.706 L1618.9427,173.8 C1616.0327,164.736 1610.4117,157.538 1602.0897,152.195 C1593.7567,146.852 1583.4297,144.174 1571.1197,144.174 C1556.0177,144.174 1541.4947,148.069 1527.5637,155.852 C1513.6317,163.64 1501.4497,174.617 1491.0307,188.786 C1480.6107,202.961 1472.9717,219.107 1468.1157,237.228 C1463.2597,255.356 1462.2967,271.328 1465.2067,285.149 C1468.1267,298.977 1474.4447,309.605 1484.2027,317.04 C1493.9397,324.481 1506.4807,328.19 1521.8147,328.19 C1534.1227,328.19 1545.8537,325.635 1556.9797,320.523 C1568.1067,315.418 1577.5527,308.325 1585.3297,299.266 L1578.3187,325.403 L1630.2427,325.403 L1677.5907,148.706 L1625.6647,148.706 Z" id="Fill-9" fill="#072654"></path>
            <path d="M1244.165,196.1055 L1257.401,148.0105 C1252.904,145.6865 1246.946,144.5225 1239.517,144.5225 C1227.66,144.5225 1216.243,147.4835 1205.244,153.4115 C1195.798,158.4975 1187.754,165.6365 1180.962,174.5815 L1187.847,148.6815 L1172.813,148.7065 L1135.938,148.7065 L1088.227,325.4025 L1140.87,325.4025 L1165.616,233.0505 C1169.221,219.5765 1175.688,209.0635 1185.042,201.5125 C1194.372,193.9615 1206.02,190.1825 1219.964,190.1825 C1228.563,190.1825 1236.619,192.1585 1244.165,196.1055" id="Fill-11" fill="#072654"></path>
            <path d="M1390.6973,237.2256 C1386.2693,253.7306 1379.4083,266.3296 1370.1123,275.0396 C1360.7943,283.7556 1349.6433,288.1076 1336.6393,288.1076 C1323.6233,288.1076 1314.7573,283.6976 1310.0393,274.8656 C1305.3103,266.0396 1305.1943,253.2606 1309.6793,236.5296 C1314.1653,219.7996 1321.1423,206.9626 1330.6243,198.0206 C1340.1043,189.0786 1351.3593,184.5986 1364.3753,184.5986 C1377.1473,184.5986 1385.8293,189.2526 1390.4303,198.5426 C1395.0203,207.8376 1395.1133,220.7376 1390.6973,237.2256 M1427.4853,155.8486 C1417.7153,148.0656 1405.2783,144.1776 1390.1873,144.1776 C1376.9393,144.1776 1364.3293,147.1966 1352.3903,153.2356 C1340.4183,159.2856 1330.7173,167.5206 1323.2753,177.9806 L1323.4433,176.8216 L1332.2873,148.6786 L1322.1103,148.6786 L1322.1103,148.7036 L1280.9003,148.7036 L1267.8153,197.5656 C1267.6643,198.1336 1267.5373,198.6636 1267.3853,199.2376 L1213.4093,400.6806 L1266.0423,400.6806 L1293.2213,299.2696 C1295.8863,308.3216 1301.4273,315.4146 1309.8193,320.5206 C1318.2103,325.6316 1328.5603,328.1876 1340.8813,328.1876 C1356.2163,328.1876 1370.7963,324.4786 1384.6463,317.0376 C1398.4863,309.6076 1410.5163,298.9736 1420.7173,285.1466 C1430.9273,271.3306 1438.4623,255.3526 1443.3183,237.2256 C1448.1753,219.1036 1449.1823,202.9586 1446.3663,188.7836 C1443.5503,174.6136 1437.2443,163.6376 1427.4853,155.8486" id="Fill-13" fill="#072654"></path>
            <path d="M1895.5381,148.7554 L1895.5721,148.7064 L1863.6921,148.7064 C1862.6731,148.7064 1861.7741,148.7354 1860.8421,148.7554 L1844.2961,148.7554 L1835.8351,160.5434 C1835.1571,161.4314 1834.4791,162.3254 1833.7491,163.3624 L1832.8271,164.7274 L1765.5851,258.3754 L1751.6421,148.7064 L1696.5641,148.7064 L1724.4561,315.3544 L1662.8591,400.6834 L1664.6151,400.6834 L1696.0651,400.6834 L1717.7341,400.6834 L1732.6621,379.5374 C1733.1021,378.9074 1733.4791,378.3914 1733.9491,377.7254 L1751.3691,353.0284 L1751.8681,352.3214 L1829.8131,241.8274 L1895.4851,148.8284 L1895.5721,148.7554 L1895.5381,148.7554 Z" id="Fill-15" fill="#072654"></path>
            <polygon id="Fill-17" fill="#3395FF" points="122.6338 105.6902 106.8778 163.6732 197.0338 105.3642 138.0748 325.3482 197.9478 325.4032 285.0458 0.4822"></polygon>
            <path d="M25.5947,232.9246 L0.8077,325.4026 L123.5337,325.4026 C123.5337,325.4026 173.7317,137.3196 173.7457,137.2656 C173.6987,137.2956 25.5947,232.9246 25.5947,232.9246" id="Fill-19" fill="#072654"></path>
        </g>
    </g>
</svg>
            <?php else : ?>
            <?= htmlspecialchars($gateway_name) ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="add-funds-body">

          <?php if (!$rzp_enabled): ?>
          <div class="gw-notice">
            ⚠ Payment gateway is not configured yet. Contact admin to enable wallet top-up.
          </div>
          <?php else: ?>

          <!-- Quick amounts -->
          <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px">Quick Select</div>
          <div class="quick-amts">
            <?php foreach ($quick_amounts as $qa): ?>
            <button type="button" class="qa-btn" onclick="setAmount(<?= $qa ?>)">
              <?= $curr_sym ?><?= number_format($qa) ?>
            </button>
            <?php endforeach; ?>
          </div>

          <!-- Amount input -->
          <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px">Enter Amount</div>
          <div class="amt-row">
            <div class="amt-input-wrap">
              <span class="amt-prefix"><?= $curr_sym ?></span>
              <input type="number" id="topup-amount" class="amt-input"
                     placeholder="<?= number_format($min_deposit, 0) ?>"
                     min="<?= $min_deposit ?>" step="1"
                     oninput="onAmountChange(this.value)">
            </div>
          </div>

          <!-- Fee breakdown -->
          <div class="fee-breakdown" id="fee-breakdown" style="display:none">
            <div class="fee-row">
              <span class="fee-lbl">Wallet credit</span>
              <span class="fee-val" id="fb-wallet">—</span>
            </div>
            <!-- Coupon discount row (hidden by default) -->
            <div class="fee-row" id="fb-discount-row" style="display:none">
              <span class="fee-lbl" style="color:#16a34a">🎟️ Coupon discount</span>
              <span class="fee-val" id="fb-discount" style="color:#16a34a">—</span>
            </div>
            <div class="fee-row" id="fb-charged-row" style="display:none">
              <span class="fee-lbl">You pay</span>
              <span class="fee-val" id="fb-charged">—</span>
            </div>
            <div class="fee-row">
              <span class="fee-lbl">Gateway fee (<?= $gateway_fee_pct ?>%)</span>
              <span class="fee-val" id="fb-fee">—</span>
            </div>
            <?php if ($gst_applicable): ?>
            <div class="fee-row" id="fb-gst-row" style="display:none">
              <?php if ($gst_type === 'SGST+CGST'): ?>
              <span class="fee-lbl" id="fb-gst-label">SGST (<?= $gst_rate/2 ?>%) + CGST (<?= $gst_rate/2 ?>%)</span>
              <?php else: ?>
              <span class="fee-lbl" id="fb-gst-label">IGST (<?= $gst_rate ?>%)</span>
              <?php endif; ?>
              <span class="fee-val" id="fb-gst" style="color:#b45309">—</span>
            </div>
            <?php endif; ?>
            <div class="fee-row total">
              <span>Total charged</span>
              <span id="fb-total">—</span>
            </div>
          </div>
            <style>
            .cpnForm {
                width: 24.1%;
            }
            @media (max-width: 640px) {
                .cpnForm {
                    width: 63%;
                }
            }
            </style>
          <!-- Coupon code input -->
          <div id="coupon-wrap" style="display:none;margin-bottom:12px">
            <div style="font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:6px">🎟️ Coupon Code</div>
            <div style="display:flex;gap:8px">
              <input type="text" id="coupon-input" placeholder="Enter coupon code"
                     class="form-control cpnForm" style="font-family:monospace;text-transform:uppercase;letter-spacing:1px;font-size:14px;font-weight:700"
                     oninput="this.value=this.value.toUpperCase()" maxlength="50">
              <button type="button" id="coupon-apply-btn" onclick="applyCoupon()" class="btn btn-ghost btn-sm" style="white-space:nowrap;flex-shrink:0">Apply</button>
              <button type="button" id="coupon-remove-btn" onclick="removeCoupon()" class="btn btn-ghost btn-sm" style="display:none;white-space:nowrap;color:var(--danger);border-color:var(--danger);flex-shrink:0">Remove</button>
            </div>
            <div id="coupon-msg" style="font-size:12.5px;margin-top:6px;display:none"></div>
          </div>

          <!-- Gateway selector (shown only when multiple gateways enabled) -->
          <?php $gw_count = count($gateways_available); ?>
          <?php if ($gw_count > 1): ?>
          <div style="margin-bottom:14px">
            <div style="font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:8px">Payment Method</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap" id="gw-selector">
              <?php if ($rzp_enabled): ?>
              <label style="display:flex;align-items:center;gap:7px;border:1.5px solid var(--primary);border-radius:9px;padding:8px 14px;cursor:pointer;background:var(--primary-light);font-size:13px;font-weight:600" id="gw-btn-razorpay">
                <input type="radio" name="gateway" value="razorpay" checked style="accent-color:var(--primary)" onchange="selectGateway('razorpay')">
                <svg width="1896px" height="401px" viewBox="0 0 1896 401" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="max-height: 20px;width: unset;align-self: center;">
    <!-- Generator: Sketch 46.2 (44496) - http://www.bohemiancoding.com/sketch -->
    <title>Group</title>
    <desc>Created with Sketch.</desc>
    <defs></defs>
    <g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
        <g id="Group">
            <path d="M451.9209,151.4937 C448.9309,162.6497 443.1359,170.8377 434.5349,176.0657 C425.9239,181.2927 413.8469,183.9117 398.2689,183.9117 L348.7769,183.9117 L366.1509,119.0807 L415.6429,119.0807 C431.2089,119.0807 441.8959,121.6947 447.7019,126.9217 C453.4969,132.1547 454.9109,140.3437 451.9209,151.4937 M503.1739,150.0967 C509.4679,126.6377 506.8589,108.6267 495.3509,96.0797 C483.8409,83.5327 463.6739,77.2627 434.8709,77.2627 L324.3909,77.2627 L257.8969,325.4027 L311.5719,325.4027 L338.3809,225.3777 L373.5809,225.3777 C381.4739,225.3777 387.6869,226.6577 392.2309,229.2137 C396.7849,231.7747 399.4509,236.3067 400.2739,242.8037 L409.8479,325.4027 L467.3589,325.4027 L458.0289,248.3847 C456.1279,231.1897 448.2589,221.0827 434.4309,218.0637 C452.0599,212.9577 466.8259,204.4677 478.7179,192.6167 C490.5989,180.7717 498.7579,166.6017 503.1739,150.0967" id="Fill-1" fill="#072654"></path>
            <path d="M633.625,236.533 C629.14,253.258 622.231,266.042 612.901,274.868 C603.56,283.7 592.386,288.111 579.382,288.111 C566.122,288.111 557.128,283.758 552.387,275.042 C547.623,266.332 547.461,253.733 551.889,237.228 C556.305,220.735 563.352,207.841 573.053,198.539 C582.742,189.255 594.09,184.602 607.105,184.602 C620.11,184.602 628.919,189.082 633.485,198.024 C638.053,206.966 638.11,219.802 633.625,236.533 L633.625,236.533 Z M657.153,148.706 L650.431,173.8 C647.521,164.736 641.9,157.538 633.578,152.195 C625.245,146.852 614.918,144.174 602.608,144.174 C587.506,144.174 572.983,148.069 559.052,155.852 C545.12,163.64 532.938,174.617 522.519,188.786 C512.099,202.961 504.461,219.107 499.604,237.228 C494.748,255.356 493.774,271.328 496.695,285.149 C499.616,298.977 505.944,309.605 515.691,317.04 C525.428,324.481 537.969,328.19 553.303,328.19 C565.612,328.19 577.342,325.635 588.469,320.523 C599.595,315.418 609.041,308.325 616.818,299.266 L609.807,325.403 L661.731,325.403 L709.079,148.706 L657.153,148.706 Z" id="Fill-3" fill="#072654"></path>
            <polygon id="Fill-5" fill="#072654" points="895.79 148.7061 744.882 148.7061 734.334 188.0911 822.155 188.0911 706.042 288.4581 696.132 325.4031 851.92 325.4031 862.478 286.0241 768.388 286.0241 886.263 184.2541"></polygon>
            <path d="M1028.6514,236.1853 C1023.9804,253.6053 1017.0604,266.6273 1007.9044,275.2223 C998.7484,283.8163 987.6674,288.1103 974.6634,288.1103 C947.4714,288.1103 938.5234,270.8113 947.7964,236.1853 C952.4094,218.9903 959.3634,206.0383 968.6594,197.3283 C977.9654,188.6123 989.2324,184.2543 1002.4804,184.2543 C1015.4844,184.2543 1024.2584,188.6123 1028.7794,197.3283 C1033.2984,206.0383 1033.2644,218.9903 1028.6514,236.1853 M1059.0304,155.3243 C1047.0804,147.8943 1031.8154,144.1743 1013.2244,144.1743 C994.4014,144.1743 976.9694,147.8943 960.9174,155.3243 C944.8644,162.7653 931.1984,173.4523 919.9214,187.3893 C908.6314,201.3323 900.4954,217.5943 895.5114,236.1853 C890.5274,254.7763 889.9484,271.0323 893.7734,284.9753 C897.5864,298.9183 905.5144,309.6053 917.5914,317.0403 C929.6574,324.4813 945.0954,328.1903 963.9194,328.1903 C982.5094,328.1903 999.7674,324.4813 1015.7054,317.0403 C1031.6184,309.6053 1045.2374,298.9183 1056.5264,284.9753 C1067.8034,271.0323 1075.9404,254.7763 1080.9244,236.1853 C1085.9084,217.5943 1086.4884,201.3323 1082.6744,187.3893 C1078.8494,173.4523 1070.9674,162.7653 1059.0304,155.3243" id="Fill-7" fill="#072654"></path>
            <path d="M1602.1367,236.533 C1597.6517,253.258 1590.7427,266.042 1581.4127,274.868 C1572.0817,283.7 1560.8857,288.111 1547.8817,288.111 C1534.6457,288.111 1525.6397,283.758 1520.8987,275.042 C1516.1347,266.332 1515.9727,253.733 1520.4007,237.228 C1524.8167,220.735 1531.8637,207.841 1541.5647,198.539 C1551.2537,189.255 1562.6017,184.602 1575.6167,184.602 C1588.6217,184.602 1597.4307,189.082 1601.9967,198.024 C1606.5647,206.966 1606.6217,219.802 1602.1367,236.533 L1602.1367,236.533 Z M1625.6647,148.706 L1618.9427,173.8 C1616.0327,164.736 1610.4117,157.538 1602.0897,152.195 C1593.7567,146.852 1583.4297,144.174 1571.1197,144.174 C1556.0177,144.174 1541.4947,148.069 1527.5637,155.852 C1513.6317,163.64 1501.4497,174.617 1491.0307,188.786 C1480.6107,202.961 1472.9717,219.107 1468.1157,237.228 C1463.2597,255.356 1462.2967,271.328 1465.2067,285.149 C1468.1267,298.977 1474.4447,309.605 1484.2027,317.04 C1493.9397,324.481 1506.4807,328.19 1521.8147,328.19 C1534.1227,328.19 1545.8537,325.635 1556.9797,320.523 C1568.1067,315.418 1577.5527,308.325 1585.3297,299.266 L1578.3187,325.403 L1630.2427,325.403 L1677.5907,148.706 L1625.6647,148.706 Z" id="Fill-9" fill="#072654"></path>
            <path d="M1244.165,196.1055 L1257.401,148.0105 C1252.904,145.6865 1246.946,144.5225 1239.517,144.5225 C1227.66,144.5225 1216.243,147.4835 1205.244,153.4115 C1195.798,158.4975 1187.754,165.6365 1180.962,174.5815 L1187.847,148.6815 L1172.813,148.7065 L1135.938,148.7065 L1088.227,325.4025 L1140.87,325.4025 L1165.616,233.0505 C1169.221,219.5765 1175.688,209.0635 1185.042,201.5125 C1194.372,193.9615 1206.02,190.1825 1219.964,190.1825 C1228.563,190.1825 1236.619,192.1585 1244.165,196.1055" id="Fill-11" fill="#072654"></path>
            <path d="M1390.6973,237.2256 C1386.2693,253.7306 1379.4083,266.3296 1370.1123,275.0396 C1360.7943,283.7556 1349.6433,288.1076 1336.6393,288.1076 C1323.6233,288.1076 1314.7573,283.6976 1310.0393,274.8656 C1305.3103,266.0396 1305.1943,253.2606 1309.6793,236.5296 C1314.1653,219.7996 1321.1423,206.9626 1330.6243,198.0206 C1340.1043,189.0786 1351.3593,184.5986 1364.3753,184.5986 C1377.1473,184.5986 1385.8293,189.2526 1390.4303,198.5426 C1395.0203,207.8376 1395.1133,220.7376 1390.6973,237.2256 M1427.4853,155.8486 C1417.7153,148.0656 1405.2783,144.1776 1390.1873,144.1776 C1376.9393,144.1776 1364.3293,147.1966 1352.3903,153.2356 C1340.4183,159.2856 1330.7173,167.5206 1323.2753,177.9806 L1323.4433,176.8216 L1332.2873,148.6786 L1322.1103,148.6786 L1322.1103,148.7036 L1280.9003,148.7036 L1267.8153,197.5656 C1267.6643,198.1336 1267.5373,198.6636 1267.3853,199.2376 L1213.4093,400.6806 L1266.0423,400.6806 L1293.2213,299.2696 C1295.8863,308.3216 1301.4273,315.4146 1309.8193,320.5206 C1318.2103,325.6316 1328.5603,328.1876 1340.8813,328.1876 C1356.2163,328.1876 1370.7963,324.4786 1384.6463,317.0376 C1398.4863,309.6076 1410.5163,298.9736 1420.7173,285.1466 C1430.9273,271.3306 1438.4623,255.3526 1443.3183,237.2256 C1448.1753,219.1036 1449.1823,202.9586 1446.3663,188.7836 C1443.5503,174.6136 1437.2443,163.6376 1427.4853,155.8486" id="Fill-13" fill="#072654"></path>
            <path d="M1895.5381,148.7554 L1895.5721,148.7064 L1863.6921,148.7064 C1862.6731,148.7064 1861.7741,148.7354 1860.8421,148.7554 L1844.2961,148.7554 L1835.8351,160.5434 C1835.1571,161.4314 1834.4791,162.3254 1833.7491,163.3624 L1832.8271,164.7274 L1765.5851,258.3754 L1751.6421,148.7064 L1696.5641,148.7064 L1724.4561,315.3544 L1662.8591,400.6834 L1664.6151,400.6834 L1696.0651,400.6834 L1717.7341,400.6834 L1732.6621,379.5374 C1733.1021,378.9074 1733.4791,378.3914 1733.9491,377.7254 L1751.3691,353.0284 L1751.8681,352.3214 L1829.8131,241.8274 L1895.4851,148.8284 L1895.5721,148.7554 L1895.5381,148.7554 Z" id="Fill-15" fill="#072654"></path>
            <polygon id="Fill-17" fill="#3395FF" points="122.6338 105.6902 106.8778 163.6732 197.0338 105.3642 138.0748 325.3482 197.9478 325.4032 285.0458 0.4822"></polygon>
            <path d="M25.5947,232.9246 L0.8077,325.4026 L123.5337,325.4026 C123.5337,325.4026 173.7317,137.3196 173.7457,137.2656 C173.6987,137.2956 25.5947,232.9246 25.5947,232.9246" id="Fill-19" fill="#072654"></path>
        </g>
    </g>
</svg>
              </label>
              <?php endif; ?>
              <?php if ($stripe_enabled): ?>
              <label style="display:flex;align-items:center;gap:7px;border:1.5px solid var(--border);border-radius:9px;padding:8px 14px;cursor:pointer;font-size:13px;font-weight:600" id="gw-btn-stripe">
                <input type="radio" name="gateway" value="stripe" style="accent-color:var(--primary)" onchange="selectGateway('stripe')" <?= !$rzp_enabled ? 'checked' : '' ?>>
                <svg width="82" height="24" viewBox="0 0 360 150" fill="none" xmlns="http://www.w3.org/2000/svg">
<path fill-rule="evenodd" clip-rule="evenodd" d="M360 77.4001C360 51.8001 347.6 31.6001 323.9 31.6001C300.1 31.6001 285.7 51.8001 285.7 77.2001C285.7 107.3 302.7 122.5 327.1 122.5C339 122.5 348 119.8 354.8 116V96.0001C348 99.4001 340.2 101.5 330.3 101.5C320.6 101.5 312 98.1001 310.9 86.3001H359.8C359.8 85.0001 360 79.8001 360 77.4001ZM310.6 67.9001C310.6 56.6001 317.5 51.9001 323.8 51.9001C329.9 51.9001 336.4 56.6001 336.4 67.9001H310.6Z" fill="#533AFD"></path>
<path fill-rule="evenodd" clip-rule="evenodd" d="M247.1 31.6001C237.3 31.6001 231 36.2001 227.5 39.4001L226.2 33.2001H204.2V149.8L229.2 144.5L229.3 116.2C232.9 118.8 238.2 122.5 247 122.5C264.9 122.5 281.2 108.1 281.2 76.4001C281.1 47.4001 264.6 31.6001 247.1 31.6001ZM241.1 100.5C235.2 100.5 231.7 98.4001 229.3 95.8001L229.2 58.7001C231.8 55.8001 235.4 53.8001 241.1 53.8001C250.2 53.8001 256.5 64.0001 256.5 77.1001C256.5 90.5001 250.3 100.5 241.1 100.5Z" fill="#533AFD"></path>
<path fill-rule="evenodd" clip-rule="evenodd" d="M169.8 25.7L194.9 20.3V0L169.8 5.3V25.7Z" fill="#533AFD"></path>
<path d="M194.9 33.3H169.8V120.8H194.9V33.3Z" fill="#533AFD"></path>
<path fill-rule="evenodd" clip-rule="evenodd" d="M142.9 40.7L141.3 33.3H119.7V120.8H144.7V61.5C150.6 53.8 160.6 55.2 163.7 56.3V33.3C160.5 32.1 148.8 29.9 142.9 40.7Z" fill="#533AFD"></path>
<path fill-rule="evenodd" clip-rule="evenodd" d="M92.8999 11.6001L68.4999 16.8001L68.3999 96.9001C68.3999 111.7 79.4999 122.6 94.2999 122.6C102.5 122.6 108.5 121.1 111.8 119.3V99.0001C108.6 100.3 92.7999 104.9 92.7999 90.1001V54.6001H111.8V33.3001H92.7999L92.8999 11.6001Z" fill="#533AFD"></path>
<path fill-rule="evenodd" clip-rule="evenodd" d="M25.3 58.7001C25.3 54.8001 28.5 53.3001 33.8 53.3001C41.4 53.3001 51 55.6001 58.6 59.7001V36.2001C50.3 32.9001 42.1 31.6001 33.8 31.6001C13.5 31.6001 0 42.2001 0 59.9001C0 87.5001 38 83.1001 38 95.0001C38 99.6001 34 101.1 28.4 101.1C20.1 101.1 9.5 97.7001 1.1 93.1001V116.9C10.4 120.9 19.8 122.6 28.4 122.6C49.2 122.6 63.5 112.3 63.5 94.4001C63.4 64.6001 25.3 69.9001 25.3 58.7001Z" fill="#533AFD"></path>
</svg>
                <!--span style="color:#6772e5">Stripe</span-->
              </label>
              <?php endif; ?>
              <?php if ($paypal_enabled): ?>
              <label style="display:flex;align-items:center;gap:7px;border:1.5px solid var(--border);border-radius:9px;padding:8px 14px;cursor:pointer;font-size:13px;font-weight:600" id="gw-btn-paypal">
                <input type="radio" name="gateway" value="paypal" style="accent-color:var(--primary)" onchange="selectGateway('paypal')" <?= !$rzp_enabled && !$stripe_enabled ? 'checked' : '' ?>>
                <svg class="svg-icon " xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="72px" height="19px" viewBox="0 0 72 19" style="enable-background:new 0 0 72 19;" xml:space="preserve">
<path id="XMLID_105_" fill="#253B80" d="M9.9,5.2c-0.3,2-1.8,2-3.3,2H5.8l0.6-3.7c0-0.2,0.2-0.4,0.5-0.4h0.4c1,0,1.9,0,2.4,0.6
C9.9,4,10,4.5,9.9,5.2z M9.3,0H3.7C3.4,0,3,0.3,3,0.7L0.7,14.9c0,0.3,0.2,0.5,0.5,0.5h2.6c0.4,0,0.7-0.3,0.8-0.7l0.6-3.8
c0.1-0.4,0.4-0.7,0.8-0.7h1.8c3.7,0,5.8-1.8,6.3-5.3c0.2-1.5,0-2.7-0.7-3.6C12.5,0.5,11.1,0,9.3,0z"></path>
<path id="XMLID_102_" fill="#253B80" d="M22.2,10.3c-0.3,1.5-1.5,2.5-3,2.5c-0.8,0-1.4-0.2-1.8-0.7C17,11.7,16.8,11,17,10.3
c0.2-1.5,1.5-2.6,3-2.6c0.8,0,1.4,0.3,1.8,0.7C22.1,8.9,22.3,9.6,22.2,10.3z M25.9,5.1h-2.7c-0.2,0-0.4,0.2-0.5,0.4l-0.1,0.7
L22.4,6c-0.6-0.8-1.9-1.1-3.1-1.1c-2.9,0-5.4,2.2-5.9,5.3c-0.3,1.6,0.1,3,1,4.1c0.8,1,2,1.4,3.3,1.4c2.4,0,3.7-1.5,3.7-1.5
l-0.1,0.7c0,0.3,0.2,0.5,0.5,0.5h2.4c0.4,0,0.7-0.3,0.8-0.7l1.4-9.1C26.4,5.4,26.1,5.1,25.9,5.1z"></path>
<path id="XMLID_101_" fill="#253B80" d="M40,5.1h-2.7c-0.3,0-0.5,0.1-0.6,0.3L33,10.9l-1.6-5.2c-0.1-0.3-0.4-0.5-0.7-0.5h-2.6
c-0.3,0-0.5,0.3-0.4,0.6l2.9,8.6l-2.8,3.9c-0.2,0.3,0,0.7,0.4,0.7h2.7c0.3,0,0.5-0.1,0.6-0.3l8.9-12.8C40.6,5.5,40.4,5.1,40,5.1z"></path>
<path id="XMLID_98_" fill="#179BD7" d="M49.5,5.2c-0.3,2-1.8,2-3.3,2h-0.8L46,3.5c0-0.2,0.2-0.4,0.5-0.4h0.4c1,0,1.9,0,2.4,0.6
C49.5,4,49.6,4.5,49.5,5.2z M48.8,0h-5.5c-0.4,0-0.7,0.3-0.8,0.7l-2.2,14.2c0,0.3,0.2,0.5,0.5,0.5h2.8c0.3,0,0.5-0.2,0.5-0.5l0.6-4
c0.1-0.4,0.4-0.7,0.8-0.7h1.8c3.7,0,5.8-1.8,6.3-5.3c0.2-1.5,0-2.7-0.7-3.6C52.1,0.5,50.7,0,48.8,0z"></path>
<path id="XMLID_95_" fill="#179BD7" d="M61.7,10.3c-0.3,1.5-1.5,2.5-3,2.5c-0.8,0-1.4-0.2-1.8-0.7c-0.4-0.5-0.5-1.1-0.4-1.9
c0.2-1.5,1.5-2.6,3-2.6c0.8,0,1.4,0.3,1.8,0.7C61.6,8.9,61.8,9.6,61.7,10.3z M65.4,5.1h-2.7c-0.2,0-0.4,0.2-0.5,0.4l-0.1,0.7L62,6
c-0.6-0.8-1.9-1.1-3.1-1.1c-2.9,0-5.4,2.2-5.9,5.3c-0.3,1.6,0.1,3,1,4.1c0.8,1,2,1.4,3.3,1.4c2.4,0,3.7-1.5,3.7-1.5l-0.1,0.7
c0,0.3,0.2,0.5,0.5,0.5h2.4c0.4,0,0.7-0.3,0.8-0.7L66,5.6C65.9,5.4,65.7,5.1,65.4,5.1z"></path>
<path id="XMLID_94_" fill="#179BD7" d="M68.5,0.4l-2.3,14.5c0,0.3,0.2,0.5,0.5,0.5H69c0.4,0,0.7-0.3,0.8-0.7L72,0.5
C72,0.3,71.8,0,71.5,0H69C68.8,0,68.6,0.2,68.5,0.4z"></path>
</svg>
                <!--span style="color:#003087">🅿 PayPal</span-->
              </label>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Stripe card element (shown when Stripe selected) -->
          <?php if ($stripe_enabled): ?>
          <div id="stripe-wrap" style="display:<?= (!$rzp_enabled && !$paypal_enabled) || ($gw_count===1 && $stripe_enabled) ? 'block' : 'none' ?>;margin-bottom:14px">
            <div style="font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:6px">Card Details</div>
            <div id="stripe-card-element" style="border:1.5px solid var(--border);border-radius:9px;padding:11px 13px;background:white"></div>
            <div id="stripe-card-error" style="font-size:12.5px;color:var(--danger);margin-top:6px;display:none"></div>
          </div>
          <?php endif; ?>

          <!-- PayPal button (shown when PayPal selected) -->
          <?php if ($paypal_enabled): ?>
          <div id="paypal-wrap" style="display:<?= (!$rzp_enabled && !$stripe_enabled) ? 'block' : 'none' ?>;margin-bottom:14px">
            <div id="paypal-button-container"></div>
          </div>
          <?php endif; ?>

          <!-- Pay button -->
          <button class="pay-btn" id="pay-btn" onclick="startPayment()" disabled>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Pay Now
          </button>
          <div class="rzp-powered">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            100% secure payments
          </div>

          <?php endif; ?>

        </div>
      </div>

      <!-- Transaction history -->
      <div class="card">
        <div class="card-head">
          <span class="card-title">Transaction History</span>
          <?php if ($show_usage): ?>
<a href="?"
   style="font-size:12px;font-weight:700;color:#dc2626;text-decoration:none">
   Hide Usage Bills
</a>
<?php else: ?>
<a href="?show_usage=1"
   style="font-size:12px;font-weight:700;color:var(--primary);text-decoration:none">
   Show Usage Bills
</a>
<?php endif; ?>
          <span style="font-size:12px;color:#94a3b8"><?= count($transactions) ?> entries</span>
        </div>
        <?php if (empty($transactions)): ?>
        <div style="padding:36px;text-align:center;color:#94a3b8">
          <div style="font-size:28px;margin-bottom:10px">📋</div>
          <div style="font-size:14px;font-weight:700;color:#64748b;margin-bottom:4px">No transactions yet</div>
          <div style="font-size:13px">Add funds to see your transaction history.</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="tx-tbl">
            <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Amount</th><th>Balance After</th></tr></thead>
            <tbody>
            <?php foreach ($transactions as $tx):
              if ($tx['ref_type'] === 'gateway_fee') continue; // hide internal fee rows
              $sym = $tx['currency'] === 'INR' ? '₹' : '$';
            ?>
            <tr>
              <td style="font-size:12px;color:#64748b;white-space:nowrap"><?= date('d M Y, H:i', strtotime($tx['created_at'])) ?></td>
              <td>
                <div style="font-size:13px;font-weight:600;color:#1e293b"><?= htmlspecialchars($tx['description'] ?? '') ?></div>
                <?php if ($tx['ref_id']): ?>
                <!--div class="tx-ref"><?= htmlspecialchars($tx['ref_id']) ?></div-->
                <?php endif; ?>
              </td>
              <td><span class="badge <?= $tx['type']==='credit'?'badge-green':'badge-red' ?>"><?= ucfirst($tx['type']) ?></span></td>
              <td class="<?= $tx['type']==='credit'?'tx-credit':'tx-debit' ?>">
                <?= $tx['type']==='credit' ? '+' : '−' ?><?= $sym . number_format((float)$tx['amount'], 4) ?>
              </td>
              <td style="font-family:monospace;font-size:12.5px;color:#64748b"><?= $sym . number_format((float)$tx['balance_after'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <!-- Transaction Pagination -->
        <?php if ($total_tx_pages > 1): ?>
        <div class="pagination-wrap">
          <a href="?p_tx=<?= max(1, $page_tx-1) ?>&p_inv=<?= $page_inv ?>" class="pg-link <?= $page_tx <= 1 ? 'disabled' : '' ?>">«</a>
          <?php for($i=1; $i<=$total_tx_pages; $i++): ?>
            <a href="?p_tx=<?= $i ?>&p_inv=<?= $page_inv ?>" class="pg-link <?= $i==$page_tx?'active':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a href="?p_tx=<?= min($total_tx_pages, $page_tx+1) ?>&p_inv=<?= $page_inv ?>" class="pg-link <?= $page_tx >= $total_tx_pages ? 'disabled' : '' ?>">»</a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Invoices -->
      <?php if (!empty($invoices)): ?>
      <div class="card">
        <div class="card-head">
          <span class="card-title">Invoices</span>
        </div>
        <div style="overflow-x:auto">
          <table class="tx-tbl">
            <thead><tr><th>Invoice #</th><th>Date</th><th>Amount</th><th>Status</th><th>View</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $inv):
              $sym = $inv['currency'] === 'INR' ? '₹' : '$';
            ?>
            <tr>
              <td style="font-family:monospace;font-weight:700;font-size:12.5px"><?= htmlspecialchars($inv['invoice_no']) ?></td>
              <td style="font-size:12px;color:#64748b"><?= date('d M Y', strtotime($inv['created_at'])) ?></td>
              <td style="font-family:monospace;font-weight:700"><?= $sym . number_format((float)$inv['amount'], 2) ?></td>
              <td><span class="badge <?= $inv['status']==='paid'?'badge-green':'badge-yellow' ?>"><?= ucfirst($inv['status']) ?></span></td>
              <td><div style="display:flex;align-items:center;gap:8px">
                <a href="/invoice.php?id=<?= $inv['id'] ?>" class="btn-pdf" target="_blank"><img src="https://i.ibb.co/vvv0ZC8Y/pdf.png"> PDF</a>
              </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <!-- Invoices Pagination -->
        <?php if ($total_inv_pages > 1): ?>
        <div class="pagination-wrap">
          <a href="?p_inv=<?= max(1, $page_inv-1) ?>&p_tx=<?= $page_tx ?>" class="pg-link <?= $page_inv <= 1 ? 'disabled' : '' ?>">«</a>
          <?php for($i=1; $i<=$total_inv_pages; $i++): ?>
            <a href="?p_inv=<?= $i ?>&p_tx=<?= $page_tx ?>" class="pg-link <?= $i==$page_inv?'active':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <a href="?p_inv=<?= min($total_inv_pages, $page_inv+1) ?>&p_tx=<?= $page_tx ?>" class="pg-link <?= $page_inv >= $total_inv_pages ? 'disabled' : '' ?>">»</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<!-- Success modal -->
<div id="success-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:9000;align-items:center;justify-content:center;padding:20px">
  <div style="background:white;border-radius:16px;width:100%;max-width:380px;overflow:hidden;text-align:center;padding:36px 28px;box-shadow:0 24px 64px rgba(0,0,0,.18)">
    <div style="margin-bottom:14px;display: flex;justify-content: center;gap: 3%;"><img src="https://em-content.zobj.net/source/apple/453/party-popper_1f389.png" style="width: 52px;"><img src="https://em-content.zobj.net/source/apple/453/partying-face_1f973.png" style="width: 52px;"></div>
    <div style="font-size:18px;font-weight:900;color:#1e293b;margin-bottom:6px">Payment Successful!</div>
    <div style="font-size:13.5px;color:#64748b;margin-bottom:20px" id="success-msg">Your wallet has been credited.</div>
    <button onclick="location.reload()" style="width:100%;padding:12px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit">
      View Updated Balance
    </button>
  </div>
</div>

<script>
var CSRF        = '<?= csrf_token() ?>';
var BASE        = '<?= BASE_URL ?>';
var CURRENCY    = '<?= $currency ?>';
var CURR_SYM    = '<?= addslashes($curr_sym) ?>';
var FEE_PCT     = <?= $gateway_fee_pct ?>;
var MIN_DEPOSIT = <?= $min_deposit ?>;
var RZP_ENABLED    = <?= $rzp_enabled    ? 'true' : 'false' ?>;
var STRIPE_ENABLED = <?= $stripe_enabled ? 'true' : 'false' ?>;
var PAYPAL_ENABLED = <?= $paypal_enabled ? 'true' : 'false' ?>;
var STRIPE_PUB_KEY = '<?= addslashes($stripe_pub_key) ?>';
var ACTIVE_GATEWAY = '<?= $rzp_enabled ? 'razorpay' : ($stripe_enabled ? 'stripe' : ($paypal_enabled ? 'paypal' : 'none')) ?>';
var stripe_obj = null, stripe_card = null, stripe_initialized = false;
var GST_APPLICABLE = <?= $gst_applicable ? 'true' : 'false' ?>;
var GST_RATE    = <?= $gst_rate ?>;
var GST_TYPE    = '<?= $gst_type ?>';

var currentAmount  = 0;
var activeCoupon   = null; // {coupon_id, code, discount_amt, charged_amt, gateway_fee, total_charge}

/* ── Quick amount ─────────────────────────────────────────── */
function setAmount(amt) {
  document.querySelectorAll('.qa-btn').forEach(function(b){ b.classList.remove('active'); });
  event.target.classList.add('active');
  document.getElementById('topup-amount').value = amt;
  onAmountChange(amt);
}

/* ── Amount change ────────────────────────────────────────── */
function onAmountChange(val) {
  var amt = parseFloat(val) || 0;
  currentAmount = amt;

  var payBtn    = document.getElementById('pay-btn');
  var feeBox    = document.getElementById('fee-breakdown');
  var cpnWrap   = document.getElementById('coupon-wrap');

  // Reset coupon if amount changes
  if (activeCoupon && activeCoupon._amount !== amt) {
    removeCoupon();
  }

  if (amt < MIN_DEPOSIT) {
    payBtn.disabled = true;
    feeBox.style.display = 'none';
    if (cpnWrap) cpnWrap.style.display = 'none';
    payBtn.textContent = 'Minimum: ' + CURR_SYM + MIN_DEPOSIT;
    return;
  }

  // Show coupon field
  if (cpnWrap) cpnWrap.style.display = '';

  if (activeCoupon) {
    // Coupon already applied — use its values
    updateFeeDisplay(amt, activeCoupon.discount_amt, activeCoupon.charged_amt, activeCoupon.gateway_fee, activeCoupon.total_charge);
  } else {
    var fee   = Math.round(amt * FEE_PCT / 100 * 100) / 100;
    var subTotal = amt + fee;
    var gstAmt = 0;
    if (GST_APPLICABLE && GST_RATE > 0) {
      gstAmt = Math.round(subTotal * GST_RATE / 100 * 100) / 100;
    }
    var total = Math.round((subTotal + gstAmt) * 100) / 100;
    updateFeeDisplay(amt, 0, amt, fee, total, gstAmt);
  }

  feeBox.style.display = 'block';
  payBtn.disabled = false;
}

function updateFeeDisplay(walletAmt, discountAmt, chargedAmt, gatewayFee, totalCharge, gstAmt) {
  gstAmt = gstAmt || 0;
  document.getElementById('fb-wallet').textContent = CURR_SYM + walletAmt.toFixed(2);
  document.getElementById('fb-fee').textContent    = CURR_SYM + gatewayFee.toFixed(2);
  document.getElementById('fb-total').textContent  = CURR_SYM + totalCharge.toFixed(2);

  // GST row
  var gstRow = document.getElementById('fb-gst-row');
  if (gstRow) {
    if (GST_APPLICABLE && gstAmt > 0) {
      document.getElementById('fb-gst').textContent = CURR_SYM + gstAmt.toFixed(2);
      gstRow.style.display = '';
    } else {
      gstRow.style.display = 'none';
    }
  }

  var discRow    = document.getElementById('fb-discount-row');
  var chargedRow = document.getElementById('fb-charged-row');
  if (discountAmt > 0) {
    document.getElementById('fb-discount').textContent = '- ' + CURR_SYM + discountAmt.toFixed(2);
    document.getElementById('fb-charged').textContent  = CURR_SYM + chargedAmt.toFixed(2);
    discRow.style.display    = '';
    chargedRow.style.display = '';
  } else {
    discRow.style.display    = 'none';
    chargedRow.style.display = 'none';
  }

  var payBtn = document.getElementById('pay-btn');
  payBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Pay ' + CURR_SYM + totalCharge.toFixed(2);
  if (discountAmt > 0) {
    payBtn.innerHTML += ' <span style="font-size:11px;opacity:.8">(saved ' + CURR_SYM + discountAmt.toFixed(2) + ')</span>';
  }
}

/* ── Coupon apply ─────────────────────────────────────────── */
function applyCoupon() {
  var code = document.getElementById('coupon-input').value.trim().toUpperCase();
  var amt  = currentAmount;
  var msg  = document.getElementById('coupon-msg');

  if (!code) { showCouponMsg('Please enter a coupon code.', 'err'); return; }
  if (amt < MIN_DEPOSIT) { showCouponMsg('Enter deposit amount first.', 'err'); return; }

  var btn = document.getElementById('coupon-apply-btn');
  btn.disabled = true;
  btn.textContent = 'Checking...';

  fetch(BASE + '/api/coupon-validate.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code: code, amount: amt, csrf: CSRF })
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    btn.textContent = 'Apply';
    if (d.ok) {
      // Recalculate GST on coupon-discounted total
      var couponSubTotal = d.charged_amt + d.gateway_fee;
      var couponGst = 0;
      if (GST_APPLICABLE && GST_RATE > 0) {
        couponGst = Math.round(couponSubTotal * GST_RATE / 100 * 100) / 100;
      }
      var couponTotal = Math.round((couponSubTotal + couponGst) * 100) / 100;
      activeCoupon = {
        coupon_id:    d.coupon_id,
        code:         d.code,
        discount_amt: d.discount_amt,
        charged_amt:  d.charged_amt,
        gateway_fee:  d.gateway_fee,
        total_charge: couponTotal,
        gst_amt:      couponGst,
        _amount:      amt,
      };
      document.getElementById('coupon-input').disabled = true;
      document.getElementById('coupon-apply-btn').style.display  = 'none';
      document.getElementById('coupon-remove-btn').style.display = '';
      showCouponMsg(d.msg, 'ok');
      updateFeeDisplay(amt, d.discount_amt, d.charged_amt, d.gateway_fee, couponTotal, couponGst);
    } else {
      activeCoupon = null;
      showCouponMsg(d.error || 'Invalid coupon.', 'err');
    }
  })
  .catch(function(){ btn.disabled=false; btn.textContent='Apply'; showCouponMsg('Network error.','err'); });
}

function removeCoupon() {
  activeCoupon = null;
  document.getElementById('coupon-input').value    = '';
  document.getElementById('coupon-input').disabled = false;
  document.getElementById('coupon-apply-btn').style.display  = '';
  document.getElementById('coupon-remove-btn').style.display = 'none';
  document.getElementById('coupon-msg').style.display = 'none';
  onAmountChange(currentAmount);
}

function showCouponMsg(text, type) {
  var el = document.getElementById('coupon-msg');
  el.style.display = '';
  el.style.color   = type === 'ok' ? '#16a34a' : '#dc2626';
  el.innerHTML   = text;
}

/* ── Enter key on coupon input ────────────────────────────── */
document.getElementById('coupon-input')?.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
});

/* ── Start Razorpay payment ──────────────────────────────── */
// ── Gateway selector ─────────────────────────────────────────
function selectGateway(gw) {
  ACTIVE_GATEWAY = gw;
  // Update label styles
  ['razorpay','stripe','paypal'].forEach(function(g) {
    var btn = document.getElementById('gw-btn-' + g);
    if (!btn) return;
    btn.style.borderColor   = g === gw ? 'var(--primary)' : 'var(--border)';
    btn.style.background    = g === gw ? 'var(--primary-light)' : '';
  });
  // Show/hide panels
  var sw = document.getElementById('stripe-wrap');
  var pw = document.getElementById('paypal-wrap');
  if (sw) sw.style.display = gw === 'stripe'  ? 'block' : 'none';
  if (pw) pw.style.display = gw === 'paypal'  ? 'block' : 'none';
  var payBtn = document.getElementById('pay-btn');
  if (payBtn) payBtn.style.display = gw === 'paypal' ? 'none' : '';
  // Init Stripe if needed
  if (gw === 'stripe' && !stripe_initialized) initStripe();
  // Init PayPal if needed
  if (gw === 'paypal') initPayPal();
}

// ── Stripe init ───────────────────────────────────────────────
function initStripe() {
  if (!STRIPE_PUB_KEY || stripe_initialized) return;
  stripe_obj  = Stripe(STRIPE_PUB_KEY);
  var elements = stripe_obj.elements();
  stripe_card = elements.create('card', {
    style: {
      base: { fontFamily: 'inherit', fontSize: '14px', color: '#1f2937',
              '::placeholder': { color: '#9ca3af' } }
    }
  });
  stripe_card.mount('#stripe-card-element');
  stripe_card.on('change', function(e) {
    var errEl = document.getElementById('stripe-card-error');
    if (errEl) { errEl.textContent = e.error ? e.error.message : ''; errEl.style.display = e.error ? 'block' : 'none'; }
  });
  stripe_initialized = true;
}

// ── PayPal init ───────────────────────────────────────────────
function initPayPal() {
  var container = document.getElementById('paypal-button-container');
  if (!container || container.dataset.rendered) return;
  container.dataset.rendered = '1';
  if (typeof paypal === 'undefined') return;

  paypal.Buttons({
    createOrder: function(data, actions) {
      var amt = parseFloat(document.getElementById('topup-amount') ? document.getElementById('topup-amount').value : 0);
      if (!amt || isNaN(amt)) { alert('Enter a valid amount first'); return; }
      return fetch(BASE + '/api/paypal-create-order.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({amount: amt, csrf: CSRF})
      }).then(r=>r.json()).then(function(d){
        if (!d.ok) { alert(d.error || 'PayPal order creation failed'); throw new Error(d.error); }
        return d.order_id;
      });
    },
    onApprove: function(data, actions) {
      return fetch(BASE + '/api/paypal-capture.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({order_id: data.orderID, csrf: CSRF})
      }).then(r=>r.json()).then(function(d){
        if (d.ok) { showPaySuccess(d.amount); }
        else { showPayError(d.error || 'PayPal payment failed'); }
      });
    },
    onError: function(err) { showPayError('PayPal error: ' + err); }
  }).render('#paypal-button-container');
}

// Init Stripe on page load if it's the default gateway
if (ACTIVE_GATEWAY === 'stripe' && STRIPE_ENABLED) { document.addEventListener('DOMContentLoaded', initStripe); }
if (ACTIVE_GATEWAY === 'paypal' && PAYPAL_ENABLED) { document.addEventListener('DOMContentLoaded', function(){ setTimeout(initPayPal, 500); }); }

function startPayment() {
  if (ACTIVE_GATEWAY === 'stripe') { startStripePayment(); return; }
  if (!RZP_ENABLED) { alert('Payment gateway not configured.'); return; }
  if (currentAmount < MIN_DEPOSIT) { alert('Minimum deposit is ' + CURR_SYM + MIN_DEPOSIT); return; }

  var btn = document.getElementById('pay-btn');
  btn.disabled = true;
  btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="animation:spin .7s linear infinite"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Creating order...';

  // Build request body — include coupon if applied
  var reqBody = { amount: currentAmount, csrf: CSRF };
  if (activeCoupon) {
    reqBody.coupon_code = activeCoupon.code;
  }

  // Step 1: Create order on server
  fetch(BASE + '/api/razorpay-create-order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(reqBody)
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (!d.ok) {
      btn.disabled = false;
      onAmountChange(currentAmount);
      alert(d.error || 'Could not create payment order.');
      return;
    }

    // Step 2: Open Razorpay checkout
    const primaryColor = getComputedStyle(document.documentElement) .getPropertyValue('--primary') .trim();
    var options = {
      key:         d.rzp_key,
      amount:      d.amount,
      currency:    d.currency,
      name:        '<?= addslashes($app_name) ?>',
      description: d.discount_amt > 0 ? 'Wallet Top-up (Coupon: ' + (d.coupon_code||'') + ')' : 'Wallet Top-up',
      order_id:    d.order_id,
      prefill: {
        name:    d.user_name,
        email:   d.user_email,
        contact: d.user_phone || '',
      },
      theme: { color: primaryColor },
      modal: {
        ondismiss: function() {
          btn.disabled = false;
          onAmountChange(currentAmount);
        }
      },
      handler: function(response) {
        // Step 3: Verify on server
        btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" style="animation:spin .7s linear infinite"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Verifying...';

        fetch(BASE + '/api/razorpay-verify.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_order_id:   response.razorpay_order_id,
            razorpay_signature:  response.razorpay_signature,
            wallet_amt:          d.wallet_amt,
            gateway_fee:         d.gateway_fee,
            coupon_id:           d.coupon_id    || 0,
            discount_amt:        d.discount_amt || 0,
            charged_amt:         d.charged_amt  || d.wallet_amt,
            csrf:                CSRF,
          })
        })
        .then(function(r){ return r.json(); })
        .then(function(v){
          if (v.ok) {
            document.getElementById('success-msg').textContent =
              CURR_SYM + d.wallet_amt.toFixed(2) + ' has been added to your wallet!';
            document.getElementById('success-modal').style.display = 'flex';
          } else {
            // Show exact server error
            var msg = v.error || 'Verification failed.';
            document.getElementById('success-modal').style.display = 'none';
            showPayError(msg, response.razorpay_payment_id);
          }
        })
        .catch(function(err){
          showPayError('Network error during verification.', response.razorpay_payment_id);
        });
      }
    };

    var rzp = new Razorpay(options);
    rzp.on('payment.failed', function(resp){
      btn.disabled = false;
      onAmountChange(currentAmount);
      alert('Payment failed: ' + (resp.error.description || 'Unknown error'));
    });
    rzp.open();
  })
  .catch(function(){
    btn.disabled = false;
    onAmountChange(currentAmount);
    alert('Request failed. Please try again.');
  });
}

// Close success modal on backdrop click
document.getElementById('success-modal').addEventListener('click', function(e){
  if (e.target === this) location.reload();
});

// Show payment error with copy button
// ── Stripe payment execution ─────────────────────────────────
function startStripePayment() {
  if (!stripe_obj || !stripe_card) { alert('Stripe not initialized. Refresh and try again.'); return; }
  var amt = parseFloat(document.getElementById('topup-amount') ? document.getElementById('topup-amount').value : 0);
  if (!amt || isNaN(amt)) { alert('Enter a valid amount first'); return; }

  var btn = document.getElementById('pay-btn');
  btn.disabled = true;
  btn.innerHTML = '<svg class="spinner" viewBox="0 0 24 24" style="animation:spin .6s linear infinite"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-dasharray="30" stroke-linecap="round"/></svg> Processing...';

  // Step 1: Create PaymentIntent on server
  fetch(BASE + '/api/stripe-create-intent.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({amount: amt, csrf: CSRF, coupon: appliedCoupon || ''})
  })
  .then(function(r){ return r.json(); })
  .then(function(d) {
    if (!d.ok) { showPayError(d.error || 'Payment init failed'); resetPayBtn(); return; }
    // Step 2: Confirm card payment
    return stripe_obj.confirmCardPayment(d.client_secret, {
      payment_method: { card: stripe_card }
    });
  })
  .then(function(result) {
    if (!result) return;
    if (result.error) {
      showPayError(result.error.message); resetPayBtn();
    } else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
      // Step 3: Verify on server
      fetch(BASE + '/api/stripe-verify.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({payment_intent_id: result.paymentIntent.id, csrf: CSRF})
      }).then(r=>r.json()).then(function(v){
        if (v.ok) showPaySuccess(v.amount);
        else { showPayError(v.error || 'Verification failed'); resetPayBtn(); }
      });
    }
  })
  .catch(function(err){ showPayError('Network error: ' + err); resetPayBtn(); });
}

function resetPayBtn() {
  var btn = document.getElementById('pay-btn');
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Pay Now';
  }
}

function showPaySuccess(amount) {
  var sym = '<?= addslashes($curr_sym) ?>';
  // Show success message
  var wrap = document.querySelector('.add-funds-card') || document.body;
  var div = document.createElement('div');
  div.style.cssText = 'background:#f0fdf4;border:2px solid #22c55e;border-radius:12px;padding:18px;text-align:center;font-size:15px;font-weight:700;color:#15803d;margin-bottom:14px';
  div.innerHTML = '✅ Payment successful! ' + sym + parseFloat(amount).toFixed(2) + ' added to your wallet.';
  wrap.insertBefore(div, wrap.firstChild);
  setTimeout(function(){ location.reload(); }, 2500);
}

function showPayError(msg, payment_id) {
  var errDiv = document.getElementById('pay-error-box');
  if (!errDiv) {
    errDiv = document.createElement('div');
    errDiv.id = 'pay-error-box';
    errDiv.style.cssText = 'background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px;margin-top:14px;font-size:13px;color:#991b1b;max-width:400px';
    document.getElementById('pay-btn').insertAdjacentElement('afterend', errDiv);
  }
  errDiv.innerHTML = '<strong>⚠ ' + esc(msg) + '</strong>'
    + (payment_id ? '<br><span style="font-family:monospace;font-size:12px;color:#dc2626">Payment ID: ' + esc(payment_id) + '</span>'
    + '<button onclick="navigator.clipboard.writeText(\''+payment_id+'\').then(function(){this.textContent=\'Copied!\'},function(){})" style="margin-left:8px;padding:2px 8px;border:1px solid #fca5a5;border-radius:5px;background:white;color:#dc2626;font-size:11px;cursor:pointer;font-family:inherit">Copy ID</button>' : '')
    + '<br><span style="font-size:12px;color:#64748b;margin-top:4px;display:block">Screenshot this and contact support.</span>';
  errDiv.style.display = 'block';
  onAmountChange(document.getElementById('topup-amount').value);
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>