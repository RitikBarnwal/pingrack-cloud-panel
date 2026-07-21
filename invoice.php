<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer_invoice.php';
require_login();

$user   = current_user();
$inv_id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT i.*, u.username, u.account_type, u.company_name, u.email, u.full_name,
            u.city, u.state, u.country, u.currency, u.gstin
     FROM invoices i
     JOIN users u ON u.id = i.user_id
     WHERE i.id = ?"
    . ($user['role'] !== 'admin' ? " AND i.user_id = {$user['id']}" : "")
    . " LIMIT 1"
);
$stmt->execute([$inv_id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); die('Invoice not found.'); }

// Only show full invoice page for wallet_topup — others: simple view
$is_topup = ($row['type'] ?? '') === 'wallet_topup';

// PDF download
if (isset($_GET['pdf']) && $is_topup) {
    $inv = [
        'id'                => $row['id'],
        'invoice_no'        => $row['invoice_no'],
        'amount'            => $row['amount'],
        'wallet_credit_amt' => $row['wallet_credit_amt'] ?? $row['amount'],
        'discount_amt'      => $row['discount_amt'] ?? 0,
        'currency'          => $row['currency'],
        'type'              => $row['type'],
        'status'            => $row['status'],
        'items'             => json_decode($row['items'], true),
        'created_at'        => $row['created_at'],
    ];
    $pdf = generate_invoice_pdf($inv, $row);
    if ($pdf) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="invoice-' . $row['invoice_no'] . '.pdf"');
        echo $pdf;
    } else {
        // wkhtmltopdf not available — serve HTML as fallback
        header('Content-Type: text/html; charset=utf-8');
        echo render_invoice_html($inv, $row);
    }
    exit;
}

// Build invoice data
$inv_data = [
    'id'                => $row['id'],
    'invoice_no'        => $row['invoice_no'],
    'amount'            => $row['amount'],
    'wallet_credit_amt' => $row['wallet_credit_amt'] ?? $row['amount'],
    'discount_amt'      => $row['discount_amt'] ?? 0,
    'currency'          => $row['currency'],
    'type'              => $row['type'],
    'status'            => $row['status'],
    'items'             => json_decode($row['items'], true),
    'created_at'        => $row['created_at'],
];

$inv_html = render_invoice_html($inv_data, $row);
$app_name = APP_NAME;
$back_url = BASE_URL . '/billing.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Invoice <?= htmlspecialchars($row['invoice_no']) ?> — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    body { background: var(--gray-100); margin: 0; font-family: var(--font); }

    /* ── Toolbar ── */
    .inv-toolbar {
      background: white;
      border-bottom: 1px solid var(--border);
      padding: 0 20px;
      height: 54px;
      display: flex;
      align-items: center;
      gap: 10px;
      position: sticky;
      top: 0;
      z-index: 50;
      box-shadow: var(--shadow-sm);
    }
    .inv-toolbar-brand {
      font-size: 14px;
      font-weight: 800;
      color: var(--gray-900);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .inv-toolbar-brand svg { color: var(--primary); }
    .inv-toolbar-sep { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }
    .inv-num { font-size: 13px; font-weight: 600; color: var(--gray-500); font-family: var(--mono); }
    .inv-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

    /* ── Invoice wrapper ── */
    .inv-wrap {
      max-width: 820px;
      margin: 28px auto;
      padding: 0 16px 40px;
    }

    /* ── Iframe ── */
    .inv-frame-wrap {
      background: white;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 24px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.06);
      border: 1px solid var(--border);
    }
    .inv-frame-wrap iframe {
      width: 100%;
      display: block;
      border: none;
      min-height: 600px;
    }

    /* ── Non-topup notice ── */
    .not-printable-notice {
      background: var(--gray-50);
      border: 1.5px solid var(--border);
      border-radius: 12px;
      padding: 32px 24px;
      text-align: center;
      margin-top: 20px;
    }

    /* ── Print styles ── */
    @media print {
      .inv-toolbar { display: none !important; }
      .inv-wrap { margin: 0; padding: 0; max-width: 100%; }
      .inv-frame-wrap { box-shadow: none; border: none; border-radius: 0; }
      body { background: white; }
    }
  </style>
</head>
<body>

  <!-- Toolbar -->
  <div class="inv-toolbar">
    <div class="inv-toolbar-brand">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
      </svg>
      <?= $app_name ?>
    </div>
    <div class="inv-toolbar-sep"></div>
    <span class="inv-num"><?= htmlspecialchars($row['invoice_no']) ?></span>
    <?php if ($is_topup): ?>
    <span class="badge badge-green" style="font-size:10px">✓ Paid</span>
    <?php endif; ?>

    <div class="inv-toolbar-actions">
      <a href="<?= $back_url ?>" class="btn btn-sm btn-ghost">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back
      </a>

      <?php if ($is_topup): ?>
      <button onclick="printInvoice()" class="btn btn-sm btn-secondary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
      </button>
      <a href="?id=<?= $inv_id ?>&pdf=1" class="btn btn-sm btn-primary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download PDF
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Invoice content -->
  <div class="inv-wrap">
    <?php if ($is_topup): ?>
    <div class="inv-frame-wrap">
      <iframe
        id="invFrame"
        srcdoc="<?= htmlspecialchars($inv_html, ENT_QUOTES) ?>"
        onload="autoHeight(this)"
        title="Invoice <?= htmlspecialchars($row['invoice_no']) ?>">
      </iframe>
    </div>
    <?php else: ?>
    <!-- Non-topup: show basic info, no printable invoice -->
    <div class="inv-frame-wrap">
      <iframe
        id="invFrame"
        srcdoc="<?= htmlspecialchars($inv_html, ENT_QUOTES) ?>"
        onload="autoHeight(this)"
        title="Invoice <?= htmlspecialchars($row['invoice_no']) ?>">
      </iframe>
    </div>
    <div class="not-printable-notice" style="margin-top:16px">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--gray-400);margin-bottom:8px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div style="font-size:13.5px;font-weight:700;color:var(--gray-700);margin-bottom:4px">Tax Invoice for Wallet Top-ups Only</div>
      <div style="font-size:13px;color:var(--gray-500);max-width:420px;margin:0 auto;line-height:1.6">
        Downloadable tax invoices are generated only for wallet top-up transactions. Server billing is deducted hourly from your wallet.
      </div>
    </div>
    <?php endif; ?>
  </div>

<script>
function autoHeight(frame) {
  try {
    const h = frame.contentWindow.document.body.scrollHeight;
    frame.style.height = (h + 32) + 'px';
  } catch(e) {}
}

function printInvoice() {
  const frame = document.getElementById('invFrame');
  if (!frame) { window.print(); return; }
  try {
    frame.contentWindow.focus();
    frame.contentWindow.print();
  } catch(e) {
    window.print();
  }
}
</script>
</body>
</html>
