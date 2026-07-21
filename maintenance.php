<?php
if (!defined('APP_NAME')) require_once __DIR__ . '/includes/bootstrap.php';
$siteName = get_setting('site_name', APP_NAME);
$msg      = htmlspecialchars(get_setting('maintenance_message', 'We are performing scheduled maintenance. Back shortly.'));
http_response_code(503);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Maintenance – <?= htmlspecialchars($siteName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#f0f5ff;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:52px 44px;max-width:460px;width:100%;text-align:center;box-shadow:0 20px 40px rgba(0,0,0,.07)}
    .icon{font-size:56px;margin-bottom:20px}
    h1{font-size:24px;font-weight:800;color:#111827;margin-bottom:10px;letter-spacing:-.4px}
    p{font-size:15px;color:#6b7280;line-height:1.65}
    .site{font-weight:800;font-size:18px;color:#2563eb;margin-bottom:28px;display:block}
    .badge{display:inline-block;background:#fef9c3;border:1px solid #fde047;color:#854d0e;padding:5px 16px;border-radius:99px;font-size:12.5px;font-weight:700;margin-top:20px}
  </style>
</head>
<body>
<div class="card">
  <div class="icon">🔧</div>
  <span class="site"><?= htmlspecialchars($siteName) ?></span>
  <h1>Under Maintenance</h1>
  <p><?= $msg ?></p>
  <div class="badge">We'll be back shortly</div>
</div>
</body>
</html>
<?php exit; ?>