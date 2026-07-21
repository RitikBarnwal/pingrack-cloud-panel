<?php
/**
 * includes/mailer_invoice.php
 *
 * process_wallet_topup(array $user, float $amount, string $payment_id)
 *   -> Does NOT credit wallet. Call wallet_credit() BEFORE this.
 * send_server_order_email(array $user, array $server, ?string $api_key)
 * send_rebuild_email(array $user, array $server, ?string $root_pass)
 */
declare(strict_types=1);

/* ══════════════════════════════════════════
   OTP EMAIL
══════════════════════════════════════════ */

function send_otp_email(string $to, string $toName, string $otp, ?string $subject = null): bool {
    try {
        require_once __DIR__ . '/mailer.php';

        if ($subject === null) {
            $subject = APP_NAME . ' — Your verification code';
        }

        // Professional OTP Body matching your existing email style
        $body = '
      <tr><td style="padding:28px 36px 0">
        <p style="margin:0 0 8px;font-size:15px;color:#111827">
          Hi <strong>' . htmlspecialchars($toName) . '</strong>,
        </p>
        <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.65">
          Use the verification code below to complete your action. 
          This code will expire in <strong>10 minutes</strong>.
        </p>
      </td></tr>

      <tr><td style="padding:0 36px 20px">
        <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:28px 20px;margin-bottom:16px;text-align:center">
          <div style="font-size:42px;font-weight:900;letter-spacing:10px;color:#1d4ed8;font-family:monospace;word-break:break-all">
            ' . htmlspecialchars($otp) . '
          </div>
        </div>
       </td>
      </tr>

      <tr><td style="padding:0 36px 20px">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin-bottom:16px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;font-size:13px;padding:4px 0">📧 Email</td>
                <td style="text-align:right;font-weight:600;color:#0f172a;font-size:13px;padding:4px 0">' . htmlspecialchars($to) . '</td>
            </tr>
            <tr><td style="color:#6b7280;font-size:13px;padding:4px 0">⏱️ Expires</td>
                <td style="text-align:right;font-weight:600;color:#dc2626;font-size:13px;padding:4px 0">in 10 minutes</td>
            </tr>
           </table>
        </div>
       </td>
      </table>

      <tr><td style="padding:0 36px 28px">
        <div style="background:#fefce8;border:1.5px solid #fde047;border-radius:10px;padding:12px 16px;margin-bottom:8px">
          <p style="margin:0;font-size:12px;color:#854d0e">
            🔒 <strong>Security tip:</strong> Never share this code with anyone. Our support team will never ask for it.
          </p>
        </div>
        <p style="margin:16px 0 0;font-size:12px;color:#9ca3af;text-align:center">
          If you didn\'t request this, you can safely ignore this email.
        </p>
       </td>
      </table>';

        $html = _email_shell(
            'linear-gradient(135deg,#0f172a,#1e3a8a,#2563eb)',
            'Verification Code',
            $body
        );

        return send_mail(
            to:        $to,
            to_name:   $toName,
            subject:   $subject,
            html_body: $html
        );

    } catch (Throwable $e) {
        error_log('[otp-email] FAILED: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        return false;
    }
}

/* ══════════════════════════════════════════
   INVOICE CORE
══════════════════════════════════════════ */

function generate_invoice_no(): string {
    $prefix = get_setting('invoice_prefix', 'INV');
    $year   = date('Y');
    
    // Last number nikalne ke liye optimized query
    $stmt = db()->query("SELECT invoice_no FROM invoices WHERE invoice_no LIKE '$prefix-$year-%' ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    
    $num = 1;
    if ($last) {
        if (preg_match('/-(\d+)$/', $last, $m)) {
            $num = (int)$m[1] + 1;
        }
    }
    return $prefix . '-' . $year . '-' . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
}

function create_invoice(int $userId, float $amount, string $currency, string $type, array $items = [], ?string $period_start = null, ?string $period_end = null, ?float $wallet_credit_amt = null, float $discount_amt = 0): array {
    $currency = strtoupper($currency);
    $attempts = 0;
    $maxAttempts = 5;

    // Fetch user for GST calc (needed to store gst_* cols)
    $u_stmt = db()->prepare('SELECT country, state FROM users WHERE id=? LIMIT 1');
    $u_stmt->execute([$userId]);
    $u_row = $u_stmt->fetch() ?: ['country'=>'IN','state'=>''];
    $gst = calculate_gst($amount, $u_row);

    while ($attempts < $maxAttempts) {
        try {
            $no = generate_invoice_no();
            $stmt = db()->prepare(
                'INSERT INTO invoices (user_id, invoice_no, amount, currency, status, type, items, period_start, period_end, wallet_credit_amt, discount_amt, gst_type, gst_rate, gst_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId, $no, round($amount, 2), $currency, 'paid', $type,
                json_encode($items), $period_start, $period_end,
                round($wallet_credit_amt ?? $amount, 2),
                round($discount_amt, 2),
                $gst['applicable'] ? $gst['type'] : null,
                $gst['applicable'] ? $gst['rate']  : null,
                $gst['applicable'] ? $gst['total_tax'] : null,
            ]);
            
            return [
                'id'                => (int)db()->lastInsertId(),
                'invoice_no'        => $no,
                'amount'            => round($amount, 2),
                'wallet_credit_amt' => $wallet_credit_amt ?? $amount,
                'discount_amt'      => round($discount_amt, 2),
                'currency'          => $currency,
                'type'              => $type,
                'items'             => $items,
                'created_at'        => date('Y-m-d H:i:s'),
                'gst_type'          => $gst['applicable'] ? $gst['type'] : null,
                'gst_rate'          => $gst['applicable'] ? $gst['rate']  : null,
                'gst_amount'        => $gst['applicable'] ? $gst['total_tax'] : null,
            ];
        } catch (PDOException $e) {
            // Agar duplicate invoice no ka error aaye (Error 23000)
            if ($e->getCode() == 23000) {
                $attempts++;
                usleep(100000); // 0.1 sec wait karke dobara try karein
                continue;
            }
            throw $e;
        }
    }
    throw new Exception("Could not generate a unique invoice number after $maxAttempts attempts.");
}

/* ══════════════════════════════════════════
   INVOICE HTML & PDF
══════════════════════════════════════════ */

/* ══════════════════════════════════════════
   GST CALCULATION HELPER
══════════════════════════════════════════ */

/**
 * Calculate GST breakdown for a given base amount.
 * Returns array: ['applicable'=>bool, 'type'=>'SGST+CGST'|'IGST'|'none',
 *                 'rate'=>float, 'sgst'=>float, 'cgst'=>float, 'igst'=>float,
 *                 'total_tax'=>float, 'grand_total'=>float]
 */
function calculate_gst(float $base_amount, array $user): array {
    $none = ['applicable'=>false,'type'=>'none','rate'=>0,'sgst'=>0,'cgst'=>0,'igst'=>0,'total_tax'=>0,'grand_total'=>$base_amount];

    // Only for Indian users
    if (strtoupper($user['country'] ?? 'IN') !== 'IN') return $none;

    // Check if GST is enabled
    if (get_setting('gst_enabled', '1') !== '1') return $none;

    $rate = (float)get_setting('gst_rate', '18');
    if ($rate <= 0) return $none;

    $company_gst_state = trim(get_setting('company_gst_state', ''));
    $user_state        = trim($user['state'] ?? '');

    $total_tax  = round($base_amount * $rate / 100, 2);
    $grand_total = $base_amount + $total_tax;

    // Same state → SGST + CGST (half-half), Different state → IGST
    if (!empty($company_gst_state) && !empty($user_state) &&
        strtolower($company_gst_state) === strtolower($user_state)) {
        $half = round($total_tax / 2, 2);
        return [
            'applicable'  => true,
            'type'        => 'SGST+CGST',
            'rate'        => $rate,
            'sgst'        => $half,
            'cgst'        => $total_tax - $half, // handles odd-cent rounding
            'igst'        => 0,
            'total_tax'   => $total_tax,
            'grand_total' => $grand_total,
        ];
    } else {
        return [
            'applicable'  => true,
            'type'        => 'IGST',
            'rate'        => $rate,
            'sgst'        => 0,
            'cgst'        => 0,
            'igst'        => $total_tax,
            'total_tax'   => $total_tax,
            'grand_total' => $grand_total,
        ];
    }
}

function render_invoice_html(array $inv, array $user): string {
    $app        = APP_NAME;
    $currency   = strtoupper($inv['currency'] ?? $user['currency'] ?? 'INR');
    $sym        = $currency === 'INR' ? '₹' : '$';
    $co_name    = get_setting('company_name',    $app);
    $co_addr    = get_setting('company_address', '');
    $co_city    = get_setting('company_city',    '');
    $co_state   = get_setting('company_state',   '');
    $co_pin     = get_setting('company_pin',     '');
    $co_email   = get_setting('company_email',   '');
    $co_phone   = get_setting('company_phone',   '');
    $co_gstin   = get_setting('company_gstin',   '');
    $co_cin     = get_setting('company_cin',     '');
    $co_website = get_setting('app_url',         '');
    $hsn_code   = get_setting('invoice_hsn',     '998314');

    // ── GST FIX: Use saved DB values — never recalculate from live settings ──
    // This prevents showing/hiding GST on old invoices when settings change
    $gst_type    = $inv['gst_type']   ?? null;
    $gst_rate    = (float)($inv['gst_rate']   ?? 0);
    $gst_amount  = (float)($inv['gst_amount'] ?? 0);
    $gst_applicable = !empty($gst_type) && $gst_amount > 0;

    $gst_sgst = $gst_cgst = $gst_igst = 0;
    if ($gst_applicable) {
        if ($gst_type === 'SGST+CGST') {
            $half = round($gst_amount / 2, 2);
            $gst_sgst = $half;
            $gst_cgst = $gst_amount - $half;
        } else {
            $gst_igst = $gst_amount;
        }
    }

    $base_amount       = (float)($inv['amount'] ?? 0);
    $wallet_credit_amt = (float)($inv['wallet_credit_amt'] ?? $base_amount);
    $discount_amt_inv  = (float)($inv['discount_amt'] ?? 0);
    $grand_total       = $base_amount + $gst_amount;

    $bill_name = ($user['account_type'] ?? '') === 'organization'
        ? ($user['company_name'] ?: $user['username'])
        : ($user['full_name'] ?: $user['username']);

    $type_labels = [
        'wallet_topup'   => 'Wallet Top-up',
        'server_order'   => 'Server Order',
        'hourly_billing' => 'Hourly Billing',
    ];
    $type_label = $type_labels[$inv['type'] ?? ''] ?? ucfirst(str_replace('_',' ',$inv['type']??''));

    // Items
    $items_list = is_array($inv['items']) ? $inv['items'] : (json_decode($inv['items']??'[]',true)?:[]);
    $items_rows = '';
    $i_n = 1;
    $pay_ref = '';
    foreach ($items_list as $item) {
        $amt = (float)($item['amount'] ?? 0);
        if ($amt == 0) { $pay_ref = $item['description'] ?? ''; continue; }
        $neg = $amt < 0;
        $desc = htmlspecialchars($item['description'] ?? 'Service');
        $amt_display = $neg
            ? '<span style="color:#16a34a">&#8722; '.$sym.number_format(abs($amt),2).'</span>'
            : $sym.number_format($amt,2);
        $items_rows .= '
        <tr>
          <td class="td-sn">'.$i_n.'</td>
          <td class="td-desc">'.($neg?'<span style="color:#16a34a">'.$desc.'</span>':$desc).'</td>
          <td class="td-hsn">'.($i_n===1?htmlspecialchars($hsn_code):'&#8212;').'</td>
          <td class="td-qty">1</td>
          <td class="td-amt">'.$amt_display.'</td>
        </tr>';
        $i_n++;
    }

    $co_addr_full  = implode(', ', array_filter([$co_addr, $co_city]));
    $co_state_full = implode(' ', array_filter([$co_state, $co_pin]));
    $bill_addr     = implode(', ', array_filter([$user['city']??'', $user['state']??'', $user['country']??'']));
    $inv_date      = date('d M Y', strtotime($inv['created_at'] ?? 'now'));
    $inv_time      = date('h:i A', strtotime($inv['created_at'] ?? 'now'));
    $clean_ref     = !empty($pay_ref) ? preg_replace('/^Payment Reference:\s*/i', '', $pay_ref) : '';

    // Totals rows
    $tot = '<div class="tot-row"><span>Subtotal</span><span class="tv">'.$sym.number_format($base_amount,2).'</span></div>';
    if ($discount_amt_inv > 0) {
        $tot .= '<div class="tot-row" style="color:#16a34a"><span>Coupon Discount</span><span class="tv" style="color:#16a34a">&#8722; '.$sym.number_format($discount_amt_inv,2).'</span></div>';
    }
    if ($gst_applicable) {
        if ($gst_type === 'SGST+CGST') {
            $tot .= '<div class="tot-row"><span>SGST '.($gst_rate/2).'%</span><span class="tv">+ '.$sym.number_format($gst_sgst,2).'</span></div>';
            $tot .= '<div class="tot-row"><span>CGST '.($gst_rate/2).'%</span><span class="tv">+ '.$sym.number_format($gst_cgst,2).'</span></div>';
        } else {
            $tot .= '<div class="tot-row"><span>IGST '.$gst_rate.'%</span><span class="tv">+ '.$sym.number_format($gst_igst,2).'</span></div>';
        }
    }

    // Payment info cells
    $pi = _pc('Invoice No', htmlspecialchars($inv['invoice_no']??''));
    $pi .= _pc('Date &amp; Time', $inv_date.' &bull; '.$inv_time);
    $pi .= _pc('Status', '<span style="color:#16a34a;font-weight:800">&#10003; PAID</span>');
    $pi .= _pc('Currency', htmlspecialchars($currency));
    if ($clean_ref) $pi .= _pc('Transaction ID', '<span style="color:#673de6">'.htmlspecialchars($clean_ref).'</span>');
    if ($wallet_credit_amt != $base_amount) $pi .= _pc('Wallet Credited', $sym.number_format($wallet_credit_amt,2));

    $css = '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Helvetica Neue",Arial,sans-serif;background:#fff;color:#1a1a2e;font-size:13px;line-height:1.5}
.inv{max-width:740px;margin:0 auto;background:#fff}
.hdr{background:#1a1a2e;padding:26px 32px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.hdr-co{}.hdr-co-name{font-size:19px;font-weight:900;color:#fff;letter-spacing:-.3px}
.hdr-co-sub{font-size:10.5px;color:rgba(255,255,255,.4);margin-top:1px;letter-spacing:.4px}
.hdr-inv{text-align:right}
.hdr-inv-word{font-size:28px;font-weight:900;color:#fff;letter-spacing:2.5px;text-transform:uppercase}
.hdr-inv-no{font-size:11.5px;font-family:monospace;color:rgba(255,255,255,.45);margin-top:2px}
.ribbon{background:linear-gradient(90deg,#4c1d95,#673de6,#7c3aed);padding:9px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.paid-pill{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.25);color:#fff;font-size:9.5px;font-weight:900;padding:3px 11px;border-radius:99px;letter-spacing:1.8px;text-transform:uppercase;display:inline-flex;align-items:center;gap:5px}
.paid-dot{width:6px;height:6px;background:#4ade80;border-radius:50%}
.ribbon-type{font-size:12px;color:rgba(255,255,255,.75);font-weight:600;margin-left:10px}
.ribbon-meta{font-size:11px;color:rgba(255,255,255,.6);display:flex;gap:16px}
.parties{display:grid;grid-template-columns:1fr 1fr;border-bottom:1.5px solid #f1f5f9}
.party{padding:20px 24px}
.party:first-child{border-right:1px solid #f1f5f9}
.ptag{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:1.6px;color:#9ca3af;margin-bottom:8px;display:flex;align-items:center;gap:7px}
.ptag::before{content:"";width:16px;height:2px;background:#673de6;border-radius:2px;display:block}
.pname{font-size:13.5px;font-weight:800;color:#1a1a2e;margin-bottom:3px}
.pline{font-size:11.5px;color:#6b7280;line-height:1.6;margin-top:2px}
.gchip{display:inline-flex;align-items:center;background:#f3f0ff;color:#5b21b6;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;font-family:monospace;margin-top:5px}
.tsec{padding:0 24px}
.thead{font-size:9.5px;font-weight:900;text-transform:uppercase;letter-spacing:1.2px;color:#9ca3af;padding:16px 0 8px;display:flex;align-items:center;gap:8px}
.thead::after{content:"";flex:1;height:1.5px;background:#f1f5f9}
table{width:100%;border-collapse:collapse}
thead th{background:#f8f9fc;padding:8px 10px;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;border-bottom:1.5px solid #eef0f4}
thead th:last-child,thead th:nth-child(4){text-align:right}thead th:nth-child(3){text-align:center}
.td-sn{padding:11px 10px;font-size:11.5px;color:#9ca3af;font-weight:600;border-bottom:1px solid #f8f9fc;width:30px}
.td-desc{padding:11px 10px;font-size:13px;color:#1a1a2e;border-bottom:1px solid #f8f9fc;line-height:1.5}
.td-hsn{padding:11px 10px;font-size:10.5px;color:#9ca3af;text-align:center;font-family:monospace;border-bottom:1px solid #f8f9fc}
.td-qty{padding:11px 10px;font-size:12px;color:#6b7280;text-align:right;border-bottom:1px solid #f8f9fc}
.td-amt{padding:11px 10px;font-size:13.5px;font-weight:700;text-align:right;color:#1a1a2e;border-bottom:1px solid #f8f9fc}
.totsec{display:flex;justify-content:flex-end;padding:14px 24px 0}
.totbox{min-width:250px}
.tot-row{display:flex;justify-content:space-between;padding:4px 0;font-size:12.5px;color:#6b7280}
.tv{font-weight:600;color:#374151}
.tot-sep{height:1.5px;background:#eef0f4;margin:7px 0}
.grand{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#1a1a2e;border-radius:8px;margin-top:3px}
.gl{font-size:10.5px;font-weight:800;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.8px}
.gv{font-size:17px;font-weight:900;color:#fff}
.pigrid{display:grid;grid-template-columns:1fr 1fr;margin:18px 24px 0;border:1px solid #eef0f4;border-radius:9px;overflow:hidden}
.pc{padding:12px 16px;border-right:1px solid #eef0f4}
.pc:last-child,.pc:nth-child(even){border-right:none}
.pc:nth-child(n+3){border-top:1px solid #eef0f4}
.pclbl{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;margin-bottom:3px}
.pcval{font-size:12px;font-weight:700;color:#1a1a2e;font-family:monospace}
.gstnote{margin:14px 24px 0;padding:9px 13px;background:#f3f0ff;border:1px solid #ddd6fe;border-radius:7px;font-size:11px;color:#5b21b6;line-height:1.6}
.sealsec{display:flex;justify-content:space-between;align-items:flex-end;padding:16px 24px;flex-wrap:wrap;gap:12px}
.sealleft{font-size:11px;color:#9ca3af;line-height:1.8}
.sealright{text-align:right}
.sealcircle{width:74px;height:74px;border:2.5px solid #673de6;border-radius:50%;margin:0 0 5px auto;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#faf8ff;position:relative}
.sealring{position:absolute;inset:5px;border:1px dashed #c4b5fd;border-radius:50%}
.sealtxt{font-size:7px;font-weight:900;color:#673de6;text-transform:uppercase;letter-spacing:1px;z-index:1;line-height:1.3;text-align:center}
.sealcheck{font-size:14px;color:#673de6;z-index:1;font-weight:900}
.seallabel{font-size:8.5px;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px}
.foot{background:#f8f9fc;border-top:1.5px solid #eef0f4;padding:11px 24px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:10px;color:#9ca3af}
.foot strong{color:#374151}
@media(max-width:520px){
  .hdr,.ribbon,.party,.tsec,.totsec,.pigrid,.gstnote,.sealsec,.foot{padding-left:14px;padding-right:14px}
  .parties{grid-template-columns:1fr}
  .party:first-child{border-right:none;border-bottom:1px solid #f1f5f9}
  .pigrid{grid-template-columns:1fr}
  .pc:nth-child(even){border-right:none;border-top:1px solid #eef0f4}
}
@media print{body{background:#fff}}
';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice '.htmlspecialchars($inv['invoice_no']??'').'</title><style>'.$css.'</style></head><body>
<div class="inv">

<div class="hdr">
  <div class="hdr-co">
    <div class="hdr-co-name">'.htmlspecialchars($co_name).'</div>
    <div class="hdr-co-sub">'.(!empty($co_email)?htmlspecialchars($co_email):'').(!empty($co_phone)?' &bull; '.htmlspecialchars($co_phone):'').'</div>
  </div>
  <div class="hdr-inv">
    <div class="hdr-inv-word">Invoice</div>
    <div class="hdr-inv-no">'.htmlspecialchars($inv['invoice_no']??'').'</div>
  </div>
</div>

<div class="ribbon">
  <div style="display:flex;align-items:center">
    <span class="paid-pill"><span class="paid-dot"></span>PAID</span>
    <span class="ribbon-type">'.htmlspecialchars($type_label).'</span>
  </div>
  <div class="ribbon-meta">
    <span>&#128197; '.$inv_date.'</span>
    '.(!empty($co_gstin)?'<span>GSTIN: '.htmlspecialchars($co_gstin).'</span>':'').'
  </div>
</div>

<div class="parties">
  <div class="party">
    <div class="ptag">Billed By</div>
    <div class="pname">'.htmlspecialchars($co_name).'</div>
    '.(!empty($co_addr_full)?'<div class="pline">'.htmlspecialchars($co_addr_full).'</div>':'').'
    '.(!empty($co_state_full)?'<div class="pline">'.htmlspecialchars($co_state_full).'</div>':'').'
    '.(!empty($co_email)?'<div class="pline">'.htmlspecialchars($co_email).'</div>':'').'
    '.(!empty($co_phone)?'<div class="pline">'.htmlspecialchars($co_phone).'</div>':'').'
    '.(!empty($co_gstin)?'<div class="gchip">GSTIN: '.htmlspecialchars($co_gstin).'</div>':'').'
    '.(!empty($co_cin)?'<div class="pline" style="font-size:10.5px;color:#9ca3af;margin-top:4px">CIN: '.htmlspecialchars($co_cin).'</div>':'').'
  </div>
  <div class="party" style="text-align:right">
    <div class="ptag" style="justify-content:flex-end">Bill To</div>
    <div class="pname">'.htmlspecialchars($bill_name).'</div>
    <div class="pline">'.htmlspecialchars($user['email']).'</div>
    '.(!empty($bill_addr)?'<div class="pline">'.htmlspecialchars($bill_addr).'</div>':'').'
    '.(!empty($user['gstin'])?'<div class="gchip" style="margin-left:auto">GSTIN: '.htmlspecialchars($user['gstin']).'</div>':'').'
  </div>
</div>

<div class="tsec">
  <div class="thead">Line Items</div>
  <table>
    <thead><tr><th>#</th><th>Description</th><th>HSN / SAC</th><th>Qty</th><th>Amount</th></tr></thead>
    <tbody>'.$items_rows.'</tbody>
  </table>
</div>

<div class="totsec">
  <div class="totbox">
    '.$tot.'
    <div class="tot-sep"></div>
    <div class="grand">
      <span class="gl">Total Paid</span>
      <span class="gv">'.$sym.number_format($grand_total,2).' '.htmlspecialchars($currency).'</span>
    </div>
  </div>
</div>

<div class="pigrid">'.$pi.'</div>

'.($gst_applicable?'<div class="gstnote">&#128203; <strong>GST Invoice:</strong> '.($gst_type==='SGST+CGST'?'SGST + CGST applied (same-state transaction).':'IGST applied (inter-state transaction).').(!empty($co_gstin)?' Supplier GSTIN: <strong>'.htmlspecialchars($co_gstin).'</strong>.':'').(!empty($user['gstin'])?' Recipient GSTIN: <strong>'.htmlspecialchars($user['gstin']).'</strong>.':'').'</div>':'').'

<div class="sealsec">
  <div class="sealleft">
    <div>This is a computer-generated invoice.</div>
    <div>No physical signature is required.</div>
    '.(!empty($co_website)?'<div style="margin-top:3px">'.htmlspecialchars(parse_url($co_website,PHP_URL_HOST)??$co_website).'</div>':'').'
  </div>
  <div class="sealright">
    <div class="sealcircle">
      <div class="sealring"></div>
      <div class="sealtxt">Authorised</div>
      <div class="sealcheck">&#10003;</div>
    </div>
    <div class="seallabel">for '.htmlspecialchars($co_name).'</div>
  </div>
</div>

<div class="foot">
  <strong>'.htmlspecialchars($co_name).'</strong>'.(!empty($co_gstin)?' &bull; GSTIN: '.htmlspecialchars($co_gstin):'').'
  <span>Thank you for your business! &#128591;</span>
</div>

</div></body></html>';
}

function _pc(string $label, string $value): string {
    return '<div class="pc"><div class="pclbl">'.$label.'</div><div class="pcval">'.$value.'</div></div>';
}


function generate_invoice_pdf(array $inv, array $user): string|bool {
    $html = render_invoice_html($inv, $user);
    
    // Aapka confirm kiya hua path
    $bin = '/home/cloudgreat/public_html/wkhtmltopdf'; 
    
    if (file_exists($bin) && is_executable($bin)) {
        $uid = $inv['id'] ?? uniqid();
        $tmpH = sys_get_temp_dir() . '/inv_' . $uid . '.html';
        $tmpP = sys_get_temp_dir() . '/inv_' . $uid . '.pdf';
        
        file_put_contents($tmpH, $html);
        
        // <--- YE RAHI WO LINE --->
        // Isme humne --disable-smart-shrinking dala hai taaki content repeat na ho aur size sahi rahe
        exec("$bin --quiet --print-media-type --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 " . escapeshellarg($tmpH) . ' ' . escapeshellarg($tmpP) . ' 2>&1');
        
        if (file_exists($tmpP) && filesize($tmpP) > 1000) {
            $pdf = file_get_contents($tmpP);
            @unlink($tmpH); @unlink($tmpP);
            return $pdf;
        }
        @unlink($tmpH);
    }
    return false;
}

/* ══════════════════════════════════════════
   EMAIL SHELL BUILDER
══════════════════════════════════════════ */

function _email_shell(string $gradient, string $hdr_sub, string $body): string {
    // Ye function ab upar ka gradient aur wrap handle karega
    return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 16px">
  <tr><td align="center">
    <table width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
      <tr><td style="background:' . $gradient . ';padding:28px 36px">
        <span style="font-size:22px;font-weight:900;color:white">☁ ' . htmlspecialchars(APP_NAME) . '</span>
      </td></tr>
      ' . $body . '
      <tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:18px 36px">
        <span style="font-size:12px;color:#94a3b8">© ' . htmlspecialchars(APP_NAME) . ' · This is an automated message</span>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>';
}

/* ══════════════════════════════════════════
   SEND INVOICE EMAIL
══════════════════════════════════════════ */

function send_invoice_email(array $user, array $inv, string $subject, string $intro, string $payment_id, ?float $wallet_credit_amt = null): bool {
    try {
        require_once __DIR__ . '/mailer.php';

        $currency = strtoupper($inv['currency'] ?? $user['currency'] ?? 'INR');
        $sym      = $currency === 'INR' ? '₹' : '$';
        
        // Wallet data
        $new_balance = number_format((float)($user['wallet_balance'] ?? 0), 2);
        $credited_amt = number_format($wallet_credit_amt ?? (float)$inv['wallet_credit_amt'] ?? (float)$inv['amount'], 2);
        
        // URLs - Ab hum attachment nahi, link bhej rahe hain
        $app_url = get_setting('app_url', 'https://storemela.xyz');
        $invoice_url = $app_url . '/invoice.php?id=' . $inv['id'];
        $wallet_url = $app_url . '/wallet';

        // NAYA UI BODY (Clean & Professional)
        // GST for email display
        $email_gst = calculate_gst((float)$inv['amount'], $user);
        $gst_rows_html = '';
        if ($email_gst['applicable']) {
            if ($email_gst['type'] === 'SGST+CGST') {
                $gst_rows_html .= '<tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">SGST (' . ($email_gst['rate']/2) . '%)</td><td style="text-align:right;font-weight:600;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;color:#b45309">+ ' . $sym . number_format($email_gst['sgst'], 2) . '</td></tr>';
                $gst_rows_html .= '<tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">CGST (' . ($email_gst['rate']/2) . '%)</td><td style="text-align:right;font-weight:600;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;color:#b45309">+ ' . $sym . number_format($email_gst['cgst'], 2) . '</td></tr>';
            } else {
                $gst_rows_html .= '<tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">IGST (' . $email_gst['rate'] . '%)</td><td style="text-align:right;font-weight:600;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;color:#b45309">+ ' . $sym . number_format($email_gst['igst'], 2) . '</td></tr>';
            }
        }
        $display_total_email = $email_gst['applicable'] ? $email_gst['grand_total'] : (float)$inv['amount'];

        $body = '
      <tr><td style="padding:28px 36px 0">
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:18px 20px;margin-bottom:22px">
          <div style="font-size:38px;font-weight:900;color:#16a34a;letter-spacing:-1px">' . $sym . $credited_amt . '</div>
          <div style="font-size:14px;color:#15803d;font-weight:600;margin-top:4px">added to your wallet 💰</div>
        </div>
        <p style="margin:0 0 6px;font-size:15px;color:#111827">Hi <strong>' . htmlspecialchars($user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username']))) . '</strong>,</p>
        <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.65">Your wallet has been successfully recharged. You can now use this balance to manage your services.</p>
      </td></tr>
      <tr><td style="padding:0 36px 24px">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Invoice No</td><td style="text-align:right;font-family:monospace;font-size:12px;padding:6px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($inv['invoice_no']) . '</td></tr>
            <tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Payment ID</td><td style="text-align:right;font-family:monospace;font-size:12px;padding:6px 0;border-bottom:1px solid #f1f5f9">' . htmlspecialchars($payment_id) . '</td></tr>
            <tr><td style="color:#6b7280;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Status</td><td style="text-align:right;font-weight:600;padding:6px 0;border-bottom:1px solid #f1f5f9;color:#16a34a">PAID</td></tr>
            ' . $gst_rows_html . '
            <tr><td style="color:#111827;font-weight:700;padding:10px 0 4px;font-size:13px">Total Charged (incl. GST)</td><td style="text-align:right;font-weight:900;color:#1d4ed8;font-size:18px;padding:10px 0 4px">' . $sym . number_format($display_total_email, 2) . '</td></tr>
            <tr><td style="color:#111827;font-weight:700;padding:4px 0;font-size:12px">Current Balance</td><td style="text-align:right;font-weight:700;color:#16a34a;font-size:14px;padding:4px 0">' . $sym . $new_balance . '</td></tr>
          </table>
        </div>
      </td></tr>
      <tr><td style="padding:0 36px 28px;text-align:center">
        <a href="' . $invoice_url . '" style="display:inline-block;background:#2563eb;color:white;padding:14px 25px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;box-shadow:0 4px 12px rgba(37,99,235,0.2);margin-bottom:10px">
          📄 View & Download Invoice
        </a>
        <br>
        <a href="' . $wallet_url . '" style="display:inline-block;color:#64748b;text-decoration:none;font-weight:600;font-size:13px;padding:10px">
          Go to Wallet →
        </a>
      </td></tr>';

        $html = _email_shell(
            'linear-gradient(135deg,#065f46,#16a34a)',
            'Wallet Top-up Confirmation',
            $body
        );

        // Send mail WITHOUT attachments
        return send_mail(
            to:          $user['email'],
            to_name: $user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username'])),
            subject:     $subject,
            html_body:   $html
            // Attachments array poora hata diya
        );

    } catch (Throwable $e) {
        error_log('[invoice-email] CRITICAL EXCEPTION: ' . $e->getMessage());
        return false;
    }
}

/* ══════════════════════════════════════════
   WALLET TOP-UP  (after wallet_credit)
══════════════════════════════════════════ */

function process_wallet_topup(array $user, float $amount, string $payment_id, array $coupon_info = []): array {
    $currency     = strtoupper($user['currency'] ?? 'INR');
    $sym          = $currency === 'INR' ? '₹' : '$';
    $discount_amt = (float)($coupon_info['discount_amt'] ?? 0);
    $charged_amt  = (float)($coupon_info['charged_amt']  ?? $amount);

    // Invoice items — coupon discount line alag dikhao
    $items = [
        ['description' => 'Wallet Top-up — ' . APP_NAME, 'amount' => $amount],
    ];
    if ($discount_amt > 0) {
        $items[] = ['description' => '🎟️ Coupon Discount Applied', 'amount' => -$discount_amt];
    }
    $items[] = ['description' => 'Payment Reference: ' . $payment_id, 'amount' => 0];

    // Invoice amount = actual paid amount (charged_amt), wallet credit alag extra field
    $inv = create_invoice(
        (int)$user['id'],
        $charged_amt,
        $currency,
        'wallet_topup',
        $items,
        null,
        null,
        $amount,       // wallet_credit_amt
        $discount_amt  // discount_amt
    );

    send_invoice_email(
        $user, $inv,
        APP_NAME . ' — Wallet Funded: ' . $sym . number_format($amount, 2),
        'Your ' . APP_NAME . ' wallet has been credited with ' . $sym . number_format($amount, 2) . ' ' . $currency . '.',
        $payment_id,
        $amount
    );
    return $inv;
}

/* ══════════════════════════════════════════
   PASSWORD HELPERS
══════════════════════════════════════════ */

function _decrypt_server_password(string $enc_pass, string $api_key): ?string {
    try {
        if (empty($enc_pass)) return null;
        
        // Key ko 32 bytes (256-bit) banane ke liye hash use karein
        $key = hash('sha256', $api_key, true);
        $data = base64_decode($enc_pass);
        
        // Agar aap CBC use kar rahe hain toh IV zaroori hai. 
        // Agar aapka purana data ECB mein hai, toh niche wala line hi kaam karega
        // Par recommendation hai ki naye data ke liye CBC mode switch karein.
        
        $dec = openssl_decrypt($data, 'AES-128-ECB', substr(hash('sha256', $api_key), 0, 16));
        return $dec ?: null;
    } catch (Throwable $e) { 
        return null; 
    }
}

function _get_plain_root_password(array $server, ?string $api_key = null): ?string {
    if (empty($server['root_password'])) return null;
    if ($api_key) { $p = _decrypt_server_password($server['root_password'], $api_key); if ($p) return $p; }
    try {
        $prov = db()->query('SELECT api_key FROM providers WHERE is_active=1 LIMIT 1')->fetch();
        if ($prov) return _decrypt_server_password($server['root_password'], $prov['api_key']) ?: null;
    } catch (Throwable $e) {}
    return null;
}

/* ══════════════════════════════════════════
   SERVER ORDER EMAIL
══════════════════════════════════════════ */

function send_server_order_email(array $user, array $server, ?string $api_key = null): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $app       = APP_NAME;
        $currency  = strtoupper($user['currency'] ?? 'INR');
        $sym       = $currency === 'INR' ? '₹' : '$';
        $root_pass = _get_plain_root_password($server, $api_key);
        $app_url   = get_setting('app_url', 'https://storemela.xyz');
        $server_url = $app_url . '/servers/view.php?id=' . ($server['id'] ?? '');

        // ── Hero banner ──────────────────────────────────────
        $body = '
      <tr><td style="padding:28px 36px 0">
        <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:18px 20px;margin-bottom:22px">
          <div style="font-size:28px;font-weight:900;color:#1d4ed8;letter-spacing:-0.5px">🚀 ' . htmlspecialchars($server['name']) . '</div>
          <div style="font-size:14px;color:#3b82f6;font-weight:600;margin-top:4px">Server deployed successfully</div>
        </div>
        <p style="margin:0 0 6px;font-size:15px;color:#111827">Hi <strong>' . htmlspecialchars($user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username'])) . '</strong>,</p>
        <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.65">Your new cloud server is being provisioned and will be ready within 1–2 minutes.</p>
      </td></tr>

      <tr><td style="padding:0 36px 20px">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:10px">📋 Server Details</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px 20px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Server Name</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['name']) . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Plan</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars(strtoupper($server['plan_slug'] ?? '')) . ' &nbsp;·&nbsp; ' . ($server['vcpu'] ?? '?') . ' vCPU / ' . ($server['ram_gb'] ?? '?') . ' GB RAM / ' . ($server['disk_gb'] ?? '?') . ' GB NVMe</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">OS</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['os_label'] ?? '') . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Data Center</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['region_label'] ?? '') . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Hourly Rate</td>
                <td style="text-align:right;font-weight:700;color:#2563eb;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . $sym . number_format((float)($server['price_hourly'] ?? 0), 4) . '/hr</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;font-size:13px">Est. Monthly</td>
                <td style="text-align:right;font-weight:700;color:#64748b;padding:10px 0;font-size:13px">~' . $sym . number_format((float)($server['price_hourly'] ?? 0) * 730, 2) . '/mo</td></tr>
          </table>
        </div>
      </td></tr>

      <tr><td style="padding:0 36px 20px">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:10px">🔐 SSH / Login Details</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px 20px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">IP Address</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['ipv4'] ?? 'Assigning…') . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Port</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">22</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Username</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">root</td></tr>
            ' . ($root_pass
                ? '<tr><td style="color:#6b7280;padding:10px 0;font-size:13px">Password</td>
                   <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;padding:10px 0;font-size:13px">' . htmlspecialchars($root_pass) . '</td></tr>'
                : '<tr><td style="color:#6b7280;padding:10px 0;font-size:13px">Auth</td>
                   <td style="text-align:right;font-weight:700;color:#16a34a;padding:10px 0;font-size:13px">SSH Key</td></tr>'
            ) . '
          </table>
        </div>
      </td></tr>'

        // Password warning block
        . ($root_pass ? '
      <tr><td style="padding:0 36px 20px">
        <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:14px 18px;font-size:13px;color:#9a3412">
          ⚠️ <strong>Save this password immediately!</strong> It will NOT appear in any future emails.
        </div>
      </td></tr>
      <tr><td style="padding:0 36px 20px">
        <div style="background:#0f172a;border-radius:10px;padding:14px 20px">
          <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;letter-spacing:.5px">QUICK CONNECT</div>
          <div style="font-family:monospace;font-size:13px;color:#7dd3fc">ssh root@' . htmlspecialchars($server['ipv4'] ?? 'YOUR_IP') . '</div>
        </div>
      </td></tr>' : '')

        . '
      <tr><td style="padding:0 36px 20px">
        <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:14px 18px;font-size:13px;color:#1e40af">
          💡 <strong>Billing starts now</strong> and is deducted hourly from your wallet. Keep your balance topped up to avoid suspension.
        </div>
      </td></tr>

      <tr><td style="padding:0 36px 28px;text-align:center">
        <a href="' . $server_url . '" style="display:inline-block;background:#2563eb;color:white;padding:14px 25px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;box-shadow:0 4px 12px rgba(37,99,235,0.2)">
          🖥️ Manage Server
        </a>
      </td></tr>';

        $html = _email_shell('linear-gradient(135deg,#0f172a,#1e3a8a,#2563eb)', 'Server Deployed', $body);

        send_mail(
            to:        $user['email'],
            to_name:   $user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username']),
            subject:   $app . ' — Server Deployed: ' . ($server['name'] ?? ''),
            html_body: $html
        );
        error_log('[server-order-email] Sent to ' . $user['email'] . ' for ' . ($server['name'] ?? '?'));
    } catch (Throwable $e) {
        error_log('[server-order-email] FAILED: ' . $e->getMessage());
    }
}

/* ══════════════════════════════════════════
   REBUILD EMAIL
══════════════════════════════════════════ */

function send_rebuild_email(array $user, array $server, ?string $root_pass = null): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $app     = APP_NAME;
        $app_url = get_setting('app_url', 'https://storemela.xyz');
        $server_url = $app_url . '/servers/view.php?id=' . ($server['id'] ?? '');

        $body = '
      <tr><td style="padding:28px 36px 0">
        <div style="background:#fdf4ff;border:1.5px solid #e9d5ff;border-radius:12px;padding:18px 20px;margin-bottom:22px">
          <div style="font-size:28px;font-weight:900;color:#7c3aed;letter-spacing:-0.5px">🔨 ' . htmlspecialchars($server['name']) . '</div>
          <div style="font-size:14px;color:#9333ea;font-weight:600;margin-top:4px">Server rebuild started</div>
        </div>
        <p style="margin:0 0 6px;font-size:15px;color:#111827">Hi <strong>' . htmlspecialchars($user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username'])) . '</strong>,</p>
        <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.65">Your server is being rebuilt with a fresh OS installation. It will be back online within 1–3 minutes.</p>
      </td></tr>

      <tr><td style="padding:0 36px 20px">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:10px">📋 Server Details</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px 20px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Server Name</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['name']) . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">New OS</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['os_label'] ?? '') . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;font-size:13px">Data Center</td>
                <td style="text-align:right;font-weight:700;color:#0f172a;padding:10px 0;font-size:13px">' . htmlspecialchars($server['region_label'] ?? '') . '</td></tr>
          </table>
        </div>
      </td></tr>

      <tr><td style="padding:0 36px 20px">
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:10px">🔐 New SSH / Login Details</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:4px 20px">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">IP Address</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">' . htmlspecialchars($server['ipv4'] ?? 'Assigning…') . '</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Port</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">22</td></tr>
            <tr><td style="color:#6b7280;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">Username</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#0f172a;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px">root</td></tr>
            ' . ($root_pass
                ? '<tr><td style="color:#6b7280;padding:10px 0;font-size:13px">New Password</td>
                   <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;padding:10px 0;font-size:13px">' . htmlspecialchars($root_pass) . '</td></tr>'
                : '<tr><td style="color:#6b7280;padding:10px 0;font-size:13px">Auth</td>
                   <td style="text-align:right;font-weight:700;color:#16a34a;padding:10px 0;font-size:13px">SSH Key</td></tr>'
            ) . '
          </table>
        </div>
      </td></tr>'

        . ($root_pass ? '
      <tr><td style="padding:0 36px 20px">
        <div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:14px 18px;font-size:13px;color:#9a3412">
          ⚠️ <strong>Save this new password immediately!</strong> It will NOT appear in any future emails.
        </div>
      </td></tr>
      <tr><td style="padding:0 36px 20px">
        <div style="background:#0f172a;border-radius:10px;padding:14px 20px">
          <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;letter-spacing:.5px">QUICK CONNECT</div>
          <div style="font-family:monospace;font-size:13px;color:#7dd3fc">ssh root@' . htmlspecialchars($server['ipv4'] ?? 'YOUR_IP') . '</div>
        </div>
      </td></tr>' : '')

        . '
      <tr><td style="padding:0 36px 20px">
        <div style="background:#fff1f2;border:1.5px solid #fecdd3;border-radius:10px;padding:14px 18px;font-size:13px;color:#9f1239">
          ⚠️ <strong>All previous data has been wiped.</strong> A rebuild performs a complete OS reinstall — all files and configurations are permanently gone.
        </div>
      </td></tr>

      <tr><td style="padding:0 36px 28px;text-align:center">
        <a href="' . $server_url . '" style="display:inline-block;background:#7c3aed;color:white;padding:14px 25px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;box-shadow:0 4px 12px rgba(124,58,237,0.2)">
          🖥️ Manage Server
        </a>
      </td></tr>';

        $html = _email_shell('linear-gradient(135deg,#1e1b4b,#4c1d95,#7c3aed)', 'Server Rebuilt', $body);

        send_mail(
            to:        $user['email'],
            to_name:   $user['account_type']==='organization' ? ($user['company_name'] ?: $user['username']) : ($user['full_name'] ?: $user['username']),
            subject:   $app . ' — Server Rebuilt: ' . ($server['name'] ?? ''),
            html_body: $html
        );
        error_log('[rebuild-email] Sent to ' . $user['email'] . ' for ' . ($server['name'] ?? '?'));
    } catch (Throwable $e) {
        error_log('[rebuild-email] FAILED: ' . $e->getMessage());
    }
}