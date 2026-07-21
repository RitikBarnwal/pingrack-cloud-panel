<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
session_start_safe();
require_login();

$user    = current_user();
$csrf    = csrf_token();
$uid     = (int)$user['id'];
$curr    = strtoupper($user['currency'] ?? 'INR');

// ── Settings ───────────────────────────────────────────────
$app_name       = APP_NAME;
$currency       = strtoupper($user['currency'] ?? 'INR');
$curr_sym       = user_currency_symbol($currency);
$sym = $curr_sym; // Use per-user currency symbol
$avatar         = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$enabled    = get_setting('referral_enabled', '1') === '1';
$reward_on  = get_setting('referral_reward_on', 'register');

// Load settings per user's own currency
$user_currency = strtoupper($user['currency'] ?? 'INR');

if ($user_currency === 'USD') {
    $bonus_referrer = (float)get_setting('referral_bonus_referrer_usd', '10');
    $bonus_referee  = (float)get_setting('referral_bonus_referee_usd',  '5');
    $min_topup      = (float)get_setting('referral_min_topup_usd', '0');
    $curr_sym       = '$';
} else {
    $bonus_referrer = (float)get_setting('referral_bonus_referrer_inr',
                          get_setting('referral_bonus_referrer', '100'));
    $bonus_referee  = (float)get_setting('referral_bonus_referee_inr',
                          get_setting('referral_bonus_referee', '50'));
    $min_topup      = (float)get_setting('referral_min_topup_inr',
                          get_setting('referral_min_topup', '0'));
    $curr_sym       = '₹';
}
// Legacy vars for backward compat
$min_topup_inr = $min_topup;
$min_topup_usd = $min_topup;
// Show min topup in user's own currency
// min_topup already set per user currency above
$uname    = htmlspecialchars($user['username']);
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$balance  = (float)$user['wallet_balance'];

// ── Ensure user has referral code ─────────────────────────
function ensure_referral_code(int $uid): string {
    $row = db()->prepare('SELECT referral_code FROM users WHERE id=? LIMIT 1');
    $row->execute([$uid]);
    $r = $row->fetchColumn();
    if ($r) return $r;
    // Generate
    do {
        $code = strtoupper(substr(md5($uid . uniqid('', true)), 0, 8));
        $ex   = db()->prepare('SELECT id FROM users WHERE referral_code=? LIMIT 1');
        $ex->execute([$code]);
    } while ($ex->fetch());
    db()->prepare('UPDATE users SET referral_code=? WHERE id=?')->execute([$code, $uid]);
    return $code;
}

$ref_code = ensure_referral_code($uid);
$ref_link = BASE_URL . '/register.php?ref=' . $ref_code;

// ── Stats (PHP 8.3 safe) ──────────────────────────────────
$stats_query = db()->prepare('
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = "rewarded" THEN 1 ELSE 0 END) AS rewarded_count,
        SUM(CASE WHEN status = "pending"  THEN 1 ELSE 0 END) AS pending_count,
        COALESCE(SUM(referrer_bonus), 0) AS total_earned_val
    FROM referrals
    WHERE referrer_id = ?
');
$stats_query->execute([$uid]);
$stats_data = $stats_query->fetch(PDO::FETCH_ASSOC) ?: [];

// Safe scalar variables — no array offset access in HTML
$total_refs    = (int)($stats_data['total_count']      ?? 0);
$rewarded_refs = (int)($stats_data['rewarded_count']   ?? 0);
$pending_refs  = (int)($stats_data['pending_count']    ?? 0);
$earned_amt    = (float)($stats_data['total_earned_val'] ?? 0);

// ── Referrals list ─────────────────────────────────────────
$refs = db()->prepare('
    SELECT r.*, u.username, u.full_name, u.email, u.created_at as user_joined
    FROM referrals r
    JOIN users u ON u.id = r.referee_id
    WHERE r.referrer_id = ?
    ORDER BY r.created_at DESC
');
$refs->execute([$uid]);
$referrals = $refs->fetchAll() ?: [];

// ── Page vars ──────────────────────────────────────────────
$app_name = APP_NAME;
$avatar   = strtoupper(mb_substr($user['full_name'] ?: $user['username'], 0, 1));
$balance  = number_format((float)$user['wallet_balance'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Referral Program — <?= $app_name ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
  .ref-wrap{max-width:860px;margin:0 auto;padding:28px 24px 60px}

  /* Hero card */
  .ref-hero{
    background:linear-gradient(135deg,var(--primary) 0%,#059669 60%,#0891b2 100%);
    border-radius:18px;padding:36px 36px 32px;
    color:white;position:relative;overflow:hidden;margin-bottom:24px;
  }
  .ref-hero::before{
    content:'';position:absolute;top:-40px;right:-40px;
    width:200px;height:200px;border-radius:50%;
    background:rgba(255,255,255,.07);pointer-events:none;
  }
  .ref-hero::after{
    content:'';position:absolute;bottom:-60px;right:80px;
    width:150px;height:150px;border-radius:50%;
    background:rgba(255,255,255,.05);pointer-events:none;
  }
  .ref-hero-title{font-size:24px;font-weight:900;letter-spacing:-.5px;margin-bottom:6px}
  .ref-hero-sub{font-size:14px;opacity:.85;margin-bottom:28px;max-width:480px;line-height:1.6}
  .ref-bonuses{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px}
  .ref-bonus-card{
    background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);
    border-radius:12px;padding:16px 20px;min-width:150px;
  }
  .ref-bonus-lbl{font-size:11.5px;font-weight:700;opacity:.75;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
  .ref-bonus-val{font-size:30px;font-weight:900;letter-spacing:-1px;line-height:1}
  .ref-bonus-sub{font-size:12px;opacity:.7;margin-top:3px}

  /* Link box */
  .ref-link-box{
    background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
    border-radius:12px;padding:4px 4px 4px 16px;
    display:flex;align-items:center;gap:8px;position:relative;z-index:1;
  }
  .ref-link-txt{font-size:13px;font-family:monospace;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;opacity:.9}
  .ref-copy-btn{
    background:white;color:var(--primary);border:none;
    padding:9px 16px;border-radius:9px;font-size:13px;font-weight:800;
    cursor:pointer;white-space:nowrap;transition:all .15s;font-family:inherit;
    display:flex;align-items:center;gap:6px;
  }
  .ref-copy-btn:hover{background:#f0fdf4;transform:translateY(-1px)}
  .ref-copy-btn.copied{background:#f0fdf4;color:#059669}

  /* Share buttons */
  .ref-share{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;position:relative;z-index:1}
  .ref-share-btn{
    display:flex;align-items:center;gap:7px;
    padding:8px 14px;border-radius:9px;font-size:13px;font-weight:600;
    text-decoration:none;cursor:pointer;border:none;font-family:inherit;
    background:rgba(255,255,255,.15);color:white;
    border:1px solid rgba(255,255,255,.2);transition:all .15s;
  }
  .ref-share-btn:hover{background:rgba(255,255,255,.25);color:white}

  /* Stats grid */
  .ref-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
  .ref-stat{
    background:white;border:1px solid var(--border);border-radius:14px;
    padding:18px;text-align:center;
  }
  .ref-stat-n{font-size:30px;font-weight:900;color:#0f172a;letter-spacing:-1px;line-height:1}
  .ref-stat-l{font-size:12px;color:var(--gray-400);margin-top:5px;font-weight:500}

  /* How it works */
  .ref-how{
    background:white;border:1px solid var(--border);border-radius:16px;
    padding:22px 24px;margin-bottom:24px;
  }
  .ref-how-title{font-size:14px;font-weight:800;color:#0f172a;margin-bottom:18px}
  .ref-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .ref-step{text-align:center}
  .ref-step-num{
    width:38px;height:38px;border-radius:10px;background:var(--primary-light);
    border:1.5px solid rgba(22,163,74,.2);color:var(--primary);
    font-size:16px;font-weight:900;display:flex;align-items:center;
    justify-content:center;margin:0 auto 10px;
  }
  .ref-step-t{font-size:13px;font-weight:700;color:#0f172a;margin-bottom:4px}
  .ref-step-d{font-size:12.5px;color:var(--gray-500);line-height:1.5}
  .ref-step-conn{display:none}

  /* Referrals table */
  .ref-table-card{background:white;border:1px solid var(--border);border-radius:16px;overflow:hidden}
  .ref-table-head{
    padding:16px 20px;border-bottom:1px solid var(--border);
    display:flex;align-items:center;justify-content:space-between;
  }
  .ref-table-title{font-size:14px;font-weight:800;color:#0f172a}
  .ref-table-count{font-size:12.5px;color:var(--gray-400)}
  table.reftbl{width:100%;border-collapse:collapse}
  table.reftbl thead th{
    padding:10px 16px;text-align:left;font-size:11px;font-weight:700;
    text-transform:uppercase;letter-spacing:.7px;color:var(--gray-400);
    background:#f8fafc;border-bottom:1px solid var(--border);
  }
  table.reftbl tbody td{padding:13px 16px;font-size:13.5px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
  table.reftbl tbody tr:last-child td{border-bottom:none}
  table.reftbl tbody tr:hover td{background:#f8fafc}
  .ref-av{width:32px;height:32px;border-radius:9px;background:var(--primary);color:white;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .ref-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:11.5px;font-weight:700}
  .ref-st-rewarded{background:#f0fdf4;color:var(--primary)}
  .ref-st-pending{background:#fef9c3;color:#854d0e}
  .ref-st-cancelled{background:#fef2f2;color:#dc2626}

  /* Empty state */
  .ref-empty{padding:50px 20px;text-align:center;color:var(--gray-400)}
  .ref-empty-ico{font-size:40px;margin-bottom:12px}

  /* Disabled state */
  .ref-disabled{
    background:#f8fafc;border:1.5px dashed var(--border);border-radius:16px;
    padding:48px;text-align:center;color:var(--gray-400);
  }

  @media(max-width:768px){
    .ref-wrap{padding:16px 14px 40px}
    .ref-hero{padding:24px 20px 22px}
    .ref-hero-title{font-size:20px}
    .ref-stats{grid-template-columns:1fr 1fr}
    .ref-steps{grid-template-columns:1fr}
    .ref-bonuses{gap:10px}
    .ref-bonus-val{font-size:24px}
    table.reftbl thead th:nth-child(4),
    table.reftbl tbody td:nth-child(4){display:none}
  }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:14px">Referral Program</span>
    </div>

    <div class="ref-wrap">

      <?php if (!$enabled): ?>
      <div class="ref-disabled">
        <div style="font-size:40px;margin-bottom:14px">🔒</div>
        <div style="font-size:16px;font-weight:700;color:#374151;margin-bottom:6px">Referral Program Disabled</div>
        <div style="font-size:13.5px">The referral program is currently not active. Check back later!</div>
      </div>
      <?php else: ?>

      <!-- ── Hero card ──────────────────────────────── -->
      <div class="ref-hero">
        <div class="ref-hero-title">🎁 Refer Friends, Earn Rewards</div>
        <div class="ref-hero-sub">
          Share your link. When a friend signs up<?= $reward_on === 'topup' && $min_topup > 0 ? ' and adds '.$sym.number_format($min_topup,0).'+ to their wallet' : '' ?>,
          you both get free wallet credits — no strings attached.
        </div>

        <div class="ref-bonuses">
          <div class="ref-bonus-card">
            <div class="ref-bonus-lbl">You Earn</div>
            <div class="ref-bonus-val"><?= $sym . number_format($bonus_referrer, 0) ?></div>
            <div class="ref-bonus-sub">per successful referral</div>
          </div>
          <div class="ref-bonus-card">
            <div class="ref-bonus-lbl">Friend Gets</div>
            <div class="ref-bonus-val"><?= $sym . number_format($bonus_referee, 0) ?></div>
            <div class="ref-bonus-sub">added to their wallet</div>
          </div>
        </div>

        <!-- Referral link -->
        <div class="ref-link-box">
          <div class="ref-link-txt" id="ref-link-txt"><?= htmlspecialchars($ref_link) ?></div>
          <button class="ref-copy-btn" id="copy-btn" onclick="copyLink()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            Copy Link
          </button>
        </div>

        <!-- Share buttons -->
        <div class="ref-share">
          <a href="https://wa.me/?text=<?= urlencode('Join '.$app_name.' and get '.$sym.number_format($bonus_referee,0).' free wallet credits! Sign up here: '.$ref_link) ?>"
             target="_blank" class="ref-share-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
            WhatsApp
          </a>
          <a href="https://t.me/share/url?url=<?= urlencode($ref_link) ?>&text=<?= urlencode('Join '.$app_name.' and get '.$sym.number_format($bonus_referee,0).' wallet credit free!') ?>"
             target="_blank" class="ref-share-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
            Telegram
          </a>
          <button onclick="nativeShare()" class="ref-share-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            Share
          </button>
        </div>
      </div>

      <!-- ── Stats ───────────────────────────────────── -->
      <div class="ref-stats">
        <div class="ref-stat">
          <div class="ref-stat-n"><?= $total_refs ?></div>
          <div class="ref-stat-l">Total Referrals</div>
        </div>
        <div class="ref-stat">
          <div class="ref-stat-n" style="color:var(--primary)"><?= $rewarded_refs ?></div>
          <div class="ref-stat-l">Rewarded</div>
        </div>
        <div class="ref-stat">
          <div class="ref-stat-n" style="color:#f59e0b"><?= $pending_refs ?></div>
          <div class="ref-stat-l">Pending</div>
        </div>
        <div class="ref-stat">
          <div class="ref-stat-n" style="font-size:22px"><?= $sym . number_format($earned_amt, 2) ?></div>
          <div class="ref-stat-l">Total Earned</div>
        </div>
      </div>

      <!-- ── How it works ───────────────────────────── -->
      <div class="ref-how">
        <div class="ref-how-title">How it works</div>
        <div class="ref-steps">
          <div class="ref-step">
            <div class="ref-step-num">1</div>
            <div class="ref-step-t">Share your link</div>
            <div class="ref-step-d">Copy your unique referral link and share it with friends, on social media, or in your community.</div>
          </div>
          <div class="ref-step">
            <div class="ref-step-num">2</div>
            <div class="ref-step-t">Friend signs up</div>
            <div class="ref-step-d">Your friend registers using your link<?= $reward_on === 'topup' && $min_topup > 0 ? ' and adds '.$sym.number_format($min_topup,0).'+ to their wallet' : '' ?>.</div>
          </div>
          <div class="ref-step">
            <div class="ref-step-num">3</div>
            <div class="ref-step-t">Both get rewarded</div>
            <div class="ref-step-d">You receive <?= $sym.number_format($bonus_referrer,0) ?> and your friend gets <?= $sym.number_format($bonus_referee,0) ?> — added directly to wallets.</div>
          </div>
        </div>
      </div>

      <!-- ── Referrals list ─────────────────────────── -->
      <div class="ref-table-card">
        <div class="ref-table-head">
          <div class="ref-table-title">Your Referrals</div>
          <div class="ref-table-count"><?= count($referrals) ?> total</div>
        </div>

        <?php if (empty($referrals)): ?>
        <div class="ref-empty">
          <div class="ref-empty-ico">🔗</div>
          <div style="font-size:14px;font-weight:700;color:#374151;margin-bottom:6px">No referrals yet</div>
          <div style="font-size:13px">Share your link above and start earning!</div>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
          <table class="reftbl">
            <thead>
              <tr>
                <th>Friend</th>
                <th>Joined</th>
                <th>Status</th>
                <th>You Earned</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($referrals as $r):
                $name = htmlspecialchars($r['full_name'] ?: $r['username']);
                $av   = $r['email'];
                [$stclass, $stlbl] = match($r['status']) {
                  'rewarded'  => ['ref-st-rewarded', '✓ Rewarded'],
                  'cancelled' => ['ref-st-cancelled', '✗ Cancelled'],
                  default     => ['ref-st-pending', '⏳ Pending'],
                };
              ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div class="ref-av"><img src="<?= getGravatar($av, NULL) ?>"></div>
                    <div>
                      <div style="font-weight:700;color:#0f172a"><?= $name ?></div>
                      <div style="font-size:12px;color:var(--gray-400)"><?= htmlspecialchars($r['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="color:var(--gray-500);font-size:13px"><?= date('d M Y', strtotime($r['user_joined'])) ?></td>
                <td><span class="ref-status <?= $stclass ?>"><?= $stlbl ?></span></td>
                <td style="font-weight:700;color:<?= $r['status']==='rewarded' ? 'var(--primary)' : 'var(--gray-400)' ?>">
                  <?php
                    if ($r['status']==='rewarded') {
                        // currency column may be "INR|USD" (new format) or just "INR" (old)
                        $r_curr_parts = explode('|', $r['currency'] ?? $user_currency);
                        $r_referrer_sym = strtoupper($r_curr_parts[0]) === 'USD' ? '$' : '₹';
                        echo $r_referrer_sym . number_format((float)$r['referrer_bonus'], 2);
                    } else {
                        echo '—';
                    }
                  ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<div class="overlay" id="overlay" onclick="this.classList.remove('open');document.getElementById('sidebar').classList.remove('open')"></div>
<div class="toast-wrap" id="toast-wrap"></div>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
<script>
var REF_LINK = <?= json_encode($ref_link) ?>;

function copyLink() {
  navigator.clipboard.writeText(REF_LINK).then(function() {
    var btn = document.getElementById('copy-btn');
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Copied!';
    btn.classList.add('copied');
    if (typeof toast === 'function') toast('Link copied to clipboard!', 'ok');
    setTimeout(function() {
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy Link';
      btn.classList.remove('copied');
    }, 2500);
  }).catch(function() {
    var el = document.getElementById('ref-link-txt');
    var range = document.createRange();
    range.selectNodeContents(el);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    if (typeof toast === 'function') toast('Select and copy the link manually', 'info');
  });
}

function nativeShare() {
  if (navigator.share) {
    navigator.share({
      title: '<?= addslashes($app_name) ?> — Free Wallet Credits',
      text: 'Join <?= addslashes($app_name) ?> and get <?= $sym.number_format($bonus_referee,0) ?> free wallet credits!',
      url: REF_LINK
    });
  } else {
    copyLink();
  }
}
</script>
</body>
</html>