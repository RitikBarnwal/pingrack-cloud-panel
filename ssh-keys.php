<?php
/**
 * ssh-keys.php — SSH Key Management
 * Keys are stored in DB only. They are injected at server deploy time.
 * No provider API call needed here — works with ALL providers.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/servers.php';
require_once __DIR__ . '/includes/currency.php';
require_login();

$user     = current_user();
$uid      = (int)$user['id'];
$app_name = APP_NAME;
$csrf     = csrf_token();
$balance  = number_format((float)$user['wallet_balance'], 2);
$curr_sym = currency_symbol(strtoupper($user['currency'] ?? 'INR'));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = htmlspecialchars($user['username']);
$balance  = number_format((float)$user['wallet_balance'], 2);
$msg = ''; $err = '';

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Add SSH key
    if ($action === 'add_key') {
        $name       = trim($_POST['key_name']   ?? '');
        $public_key = trim($_POST['public_key'] ?? '');
        // Normalize — remove \r\n, extra spaces
        $public_key = preg_replace('/\s+/', ' ', $public_key);

        if (!$name) {
            $err = 'Key name is required.';
        } elseif (!$public_key) {
            $err = 'Public key is required.';
        } elseif (!preg_match('/^(ssh-rsa|ssh-ed25519|ssh-dss|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|sk-ssh-ed25519|sk-ecdsa-sha2-nistp256)\s+[A-Za-z0-9+\/=]+/', $public_key)) {
            $err = 'Invalid key format. Key must start with: ssh-rsa, ssh-ed25519, or ecdsa-sha2-nistp256. Make sure you paste the PUBLIC key (.pub file), not the private key.';
        } else {
            // Check duplicate name
            $ex = db()->prepare('SELECT id FROM ssh_keys WHERE user_id=? AND name=? LIMIT 1');
            $ex->execute([$uid, $name]);
            if ($ex->fetch()) {
                $err = "A key named \"{$name}\" already exists.";
            } else {
                // Generate fingerprint from public key (local — no provider needed)
                $fingerprint = '';
                try {
                    $parts = explode(' ', $public_key);
                    $key_data = base64_decode($parts[1] ?? '');
                    if ($key_data) {
                        $fingerprint = 'SHA256:' . base64_encode(hash('sha256', $key_data, true));
                        $fingerprint = rtrim($fingerprint, '=');
                    }
                } catch (Throwable $e) {}

                try {
                    db()->prepare(
                        'INSERT INTO ssh_keys (user_id, provider_id, name, fingerprint, public_key, created_at)
                         VALUES (?, NULL, ?, ?, ?, NOW())'
                    )->execute([$uid, $name, $fingerprint, $public_key]);
                    $msg = "SSH key \"{$name}\" added successfully.";
                } catch (Throwable $e) {
                    $err = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    // Delete SSH key
    if ($action === 'delete_key') {
        $kid = (int)($_POST['key_id'] ?? 0);
        $key = db()->prepare('SELECT * FROM ssh_keys WHERE id=? AND user_id=? LIMIT 1');
        $key->execute([$kid, $uid]);
        $key = $key->fetch();

        if (!$key) {
            $err = 'Key not found.';
        } else {
            db()->prepare('DELETE FROM ssh_keys WHERE id=? AND user_id=?')->execute([$kid, $uid]);
            $msg = "SSH key \"{$key['name']}\" deleted.";
        }
    }
}

// Load keys
try {
    $st = db()->prepare('SELECT * FROM ssh_keys WHERE user_id=? ORDER BY created_at DESC');
    $st->execute([$uid]);
    $keys = $st->fetchAll() ?: [];
} catch (Throwable $e) { $keys = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>SSH Keys — <?= $app_name ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    .page-wrap{padding:24px;max-width:800px}
    .card{background:white;border:1.5px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:18px}
    .card-head{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;background:#fafbfd}
    .card-title{font-size:14px;font-weight:800;color:var(--gray-900);flex:1}
    .card-body{padding:20px}
    .flabel{display:block;font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
    .flabel span{font-weight:400;color:var(--gray-400);text-transform:none}
    .finp{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:13px;color:var(--gray-900);outline:none;transition:border-color .13s}
    .finp:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .key-item{display:flex;align-items:flex-start;gap:14px;padding:16px 0;border-bottom:1px solid var(--gray-100)}
    .key-item:last-child{border:none;padding-bottom:0}
    .key-icon{width:40px;height:40px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .key-name{font-size:14px;font-weight:800;color:var(--gray-900)}
    .key-fp{font-size:11.5px;color:var(--gray-400);font-family:'JetBrains Mono',monospace;margin-top:3px;word-break:break-all}
    .key-pub{font-size:11px;color:var(--gray-500);font-family:'JetBrains Mono',monospace;margin-top:6px;word-break:break-all;background:var(--gray-50);padding:7px 10px;border-radius:7px;border:1px solid var(--border);line-height:1.5}
    .del-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border:1.5px solid #fca5a5;background:white;color:#dc2626;border-radius:7px;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .13s;flex-shrink:0}
    .del-btn:hover{background:#fef2f2}
    .add-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;background:var(--primary);color:white;border:none;border-radius:9px;font-size:13.5px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s}
    .add-btn:hover{background:var(--primary-hover)}
    .empty-wrap{padding:44px 20px;text-align:center}
    .empty-icon{width:52px;height:52px;border-radius:13px;background:var(--gray-100);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .hint-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:13px 16px;font-size:13px;color:#15803d;display:flex;gap:9px;align-items:flex-start;margin-bottom:18px}
    .hint-box svg{flex-shrink:0;margin-top:1px}
    .err-box{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:10px;padding:12px 15px;font-size:13px;font-weight:600;color:#dc2626;display:flex;gap:8px;margin-bottom:16px}
    .suc-box{background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 15px;font-size:13px;font-weight:700;color:#15803d;display:flex;gap:8px;margin-bottom:16px}
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="margin-left:260px;min-height:100vh;background:var(--gray-50)">

    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:15px">SSH Keys</span>
    </div>

    <div class="topbar">
      <span class="topbar-title">SSH Keys</span>
      <div style="margin-left:auto">
        <a href="<?= BASE_URL ?>/billing.php" class="btn btn-secondary btn-sm">
          <?= $curr_sym . $balance ?>
        </a>
      </div>
    </div>

    <div class="page-wrap">

      <?php if ($msg): ?>
      <div class="suc-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>
      <?php if ($err): ?>
      <div class="err-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($err) ?>
      </div>
      <?php endif; ?>

      <!-- Info -->
      <div class="hint-box">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <div>
          SSH keys are added to your servers at deploy time for <strong>passwordless, secure access</strong>.
          Generate one with: <code style="font-family:'JetBrains Mono',monospace;font-size:12px;background:#d1fae5;padding:2px 5px;border-radius:4px">ssh-keygen -t ed25519 -C "your@email.com"</code>
          — then paste the <code style="font-family:monospace;font-size:12px">.pub</code> file content below.
        </div>
      </div>

      <!-- Add key form -->
      <div class="card">
        <div class="card-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <span class="card-title">Add SSH Key</span>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_key">

            <div style="margin-bottom:14px">
              <label class="flabel">Key Name</label>
              <input type="text" name="key_name" class="finp" style="max-width:320px"
                     placeholder="e.g. my-laptop, office-pc" required
                     value="<?= htmlspecialchars($_POST['key_name'] ?? '') ?>">
            </div>

            <div style="margin-bottom:18px">
              <label class="flabel">Public Key <span>(contents of ~/.ssh/id_ed25519.pub)</span></label>
              <textarea name="public_key" class="finp" rows="4"
                        style="font-family:'JetBrains Mono',monospace;font-size:12px;resize:vertical;line-height:1.6"
                        placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... user@host"
                        required><?= htmlspecialchars($_POST['public_key'] ?? '') ?></textarea>
              <div style="font-size:12px;color:var(--gray-400);margin-top:5px">
                Supported: <code style="font-family:monospace">ssh-rsa</code>, <code style="font-family:monospace">ssh-ed25519</code>, <code style="font-family:monospace">ecdsa-sha2-nistp256/384/521</code>
              </div>
            </div>

            <button data-loading="Adding..." type="submit" class="add-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Add SSH Key
            </button>
          </form>
        </div>
      </div>

      <!-- Key list -->
      <div class="card">
        <div class="card-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--gray-500)" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
          <span class="card-title">Your SSH Keys</span>
          <span class="badge badge-blue" style="margin-left:auto"><?= count($keys) ?></span>
        </div>

        <?php if (empty($keys)): ?>
        <div class="empty-wrap">
          <div class="empty-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--gray-400)" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
          </div>
          <div style="font-size:15px;font-weight:800;color:var(--gray-700);margin-bottom:5px">No SSH keys yet</div>
          <div style="font-size:13px;color:var(--gray-500)">Add your first key above — it will be injected into new servers at deploy time.</div>
        </div>
        <?php else: ?>
        <div style="padding:0 20px">
          <?php foreach ($keys as $k): ?>
          <div class="key-item">
            <div class="key-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
            </div>
            <div style="flex:1;min-width:0">
              <div class="key-name"><?= htmlspecialchars($k['name']) ?></div>
              <?php if ($k['fingerprint']): ?>
              <div class="key-fp"><?= htmlspecialchars($k['fingerprint']) ?></div>
              <?php endif; ?>
              <div style="font-size:11.5px;color:var(--gray-400);margin-top:3px">
                Added <?= date('d M Y', strtotime($k['created_at'])) ?>
              </div>
              <?php if ($k['public_key']): ?>
              <div class="key-pub"><?= htmlspecialchars(substr($k['public_key'], 0, 90)) ?>…</div>
              <?php endif; ?>
            </div>
            <form method="POST" style="flex-shrink:0"
                  onsubmit="return confirm('Delete SSH key \'<?= htmlspecialchars(addslashes($k['name'])) ?>\'?')">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_key">
              <input type="hidden" name="key_id" value="<?= $k['id'] ?>">
              <button data-loading="Deleting..." type="submit" class="del-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                Delete
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');}</script>
<script>
    document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function () {

        const btn = this.querySelector('button[type="submit"]');

        if (!btn) return;

        btn.disabled = true;

        const text = btn.dataset.loading || 'Loading...';

        btn.innerHTML = `
            <span class="spinner"></span>
            ${text}
        `;
    });
});
</script>
</body>
</html>
