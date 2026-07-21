<?php
// admin/career.php — Career Management
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$user     = current_user();
$app_name = APP_NAME;
$csrf     = csrf_token();

// ── WA helper ────────────────────────────────────────────────
function send_career_wa(string $phone, string $msg): void {
    $api   = get_setting('wa_api',   '');
    $token = get_setting('wa_token', '');
    if (!$api || !$token || !$phone) return;
    $url = rtrim($api,'/').'?number='.urlencode($phone).'&type=text&message='.urlencode($msg).'&token='.urlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8]);
    curl_exec($ch); curl_close($ch);
}

function send_career_email(string $to, string $subject, string $body_rows): void {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        send_mail($to, $subject, $body_rows);
    } catch (Exception $e) {}
}

// ── AJAX POST handlers ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // ── Save / Update Job ──────────────────────────────────────
    if ($action === 'save_job') {
        $id    = intval($_POST['id'] ?? 0);
        $data  = [
            trim($_POST['title']            ?? ''),
            trim($_POST['department']       ?? ''),
            trim($_POST['location']         ?? 'Remote'),
            trim($_POST['job_type']         ?? 'Full-time'),
            trim($_POST['salary_range']     ?? ''),
            trim($_POST['experience_years'] ?? ''),
            trim($_POST['skills_tags']      ?? ''),
            intval($_POST['openings_count'] ?? 1),
            trim($_POST['description']      ?? ''),
            trim($_POST['requirements']     ?? ''),
            trim($_POST['responsibilities'] ?? ''),
            intval($_POST['is_active']      ?? 1),
        ];
        if (!$data[0]) { echo json_encode(['ok'=>false,'error'=>'Title required']); exit; }

        if ($id) {
            db()->prepare("UPDATE career_openings SET title=?,department=?,location=?,job_type=?,salary_range=?,experience_years=?,skills_tags=?,openings_count=?,description=?,requirements=?,responsibilities=?,is_active=?,updated_at=NOW() WHERE id=?")
               ->execute([...$data, $id]);
        } else {
            db()->prepare("INSERT INTO career_openings (title,department,location,job_type,salary_range,experience_years,skills_tags,openings_count,description,requirements,responsibilities,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
               ->execute($data);
            $id = (int)db()->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]); exit;
    }

    // ── Toggle / Delete Job ───────────────────────────────────
    if ($action === 'toggle_job') {
        db()->prepare("UPDATE career_openings SET is_active=? WHERE id=?")->execute([intval($_POST['is_active']??0), intval($_POST['id']??0)]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'delete_job') {
        $id = intval($_POST['id']??0);
        if ($id) {
            // delete resume files
            $apps = db()->prepare("SELECT resume_path FROM career_applications WHERE job_id=?");
            $apps->execute([$id]);
            foreach ($apps->fetchAll() as $a) {
                if ($a['resume_path'] && file_exists('/www/uploads/'.$a['resume_path']))
                    @unlink('/www/uploads/'.$a['resume_path']);
            }
            db()->prepare("DELETE FROM career_applications WHERE job_id=?")->execute([$id]);
            db()->prepare("DELETE FROM career_openings WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    // ── Update Application Status + Notify ────────────────────
    if ($action === 'update_app_status') {
        $id      = intval($_POST['id'] ?? 0);
        $status  = trim($_POST['status'] ?? '');
        $notes   = trim($_POST['admin_notes'] ?? '');
        $notify  = intval($_POST['notify'] ?? 0); // 1=send WA+email
        $allowed = ['pending','reviewing','shortlisted','rejected','hired'];

        if (!$id || !in_array($status, $allowed)) {
            echo json_encode(['ok'=>false,'error'=>'Invalid']); exit;
        }

        db()->prepare("UPDATE career_applications SET status=?,admin_notes=?,updated_at=NOW() WHERE id=?")
           ->execute([$status, $notes, $id]);

        if ($notify) {
            // Load app + job details
            $app_st = db()->prepare(
                "SELECT ca.*, co.title job_title, co.department
                 FROM career_applications ca
                 JOIN career_openings co ON co.id=ca.job_id
                 WHERE ca.id=?"
            );
            $app_st->execute([$id]);
            $app = $app_st->fetch();

            if ($app) {
                $company = get_setting('site_name', APP_NAME);

                $status_labels = [
                    'reviewing'   => ['label'=>'Under Review',  'emoji'=>'👀'],
                    'shortlisted' => ['label'=>'Shortlisted',   'emoji'=>'⭐'],
                    'rejected'    => ['label'=>'Not Selected',  'emoji'=>'❌'],
                    'hired'       => ['label'=>'Offer Extended','emoji'=>'🎉'],
                    'pending'     => ['label'=>'Pending',       'emoji'=>'⏳'],
                ];
                $sl     = $status_labels[$status] ?? ['label'=>ucfirst($status),'emoji'=>'📋'];
                $s_text = $sl['emoji'] . ' ' . $sl['label'];

                // WA to applicant (if phone given)
                if ($app['phone']) {
                    $wa_messages = [
                        'reviewing'   => "Hi {$app['name']},\n\nYour application for *{$app['job_title']}* at *{$company}* is currently under review. We'll update you soon!\n\nThank you for your patience.",
                        'shortlisted' => "Hi {$app['name']}, 🎉\n\nGreat news! You've been *shortlisted* for *{$app['job_title']}* at *{$company}*.\n\nOur team will contact you shortly to schedule the next steps.\n\nBest of luck!",
                        'rejected'    => "Hi {$app['name']},\n\nThank you for applying for *{$app['job_title']}* at *{$company}*.\n\nAfter careful consideration, we've decided to move forward with other candidates. We encourage you to apply again in the future.\n\nBest wishes! 🙏",
                        'hired'       => "Hi {$app['name']}, 🎉🎉\n\nCongratulations! We're thrilled to extend an *offer* for the *{$app['job_title']}* role at *{$company}*!\n\nOur HR team will reach out with the official offer letter shortly. Welcome to the team!",
                        'pending'     => "Hi {$app['name']},\n\nYour application for *{$app['job_title']}* at *{$company}* has been received. We'll review it and get back to you soon.",
                    ];
                    send_career_wa($app['phone'], $wa_messages[$status] ?? "Your application status has been updated to: {$sl['label']}");
                }

                // Email to applicant
                $email_bodies = [
                    'reviewing'   => "We're currently reviewing your application for <strong>{$app['job_title']}</strong>. We'll keep you posted on the progress.",
                    'shortlisted' => "<strong>Great news!</strong> You've been shortlisted for <strong>{$app['job_title']}</strong>. Our team will contact you shortly to schedule next steps.",
                    'rejected'    => "After careful consideration, we've decided to move forward with other candidates for <strong>{$app['job_title']}</strong>. We truly appreciate your interest and encourage you to apply for future openings.",
                    'hired'       => "<strong>Congratulations!</strong> 🎉 We're excited to extend an offer for the <strong>{$app['job_title']}</strong> role. Our HR team will reach out with the official offer letter.",
                    'pending'     => "Your application for <strong>{$app['job_title']}</strong> has been received and is awaiting review.",
                ];

                $email_subj = [
                    'reviewing'   => "Update on your application — {$app['job_title']} | {$company}",
                    'shortlisted' => "You've been shortlisted — {$app['job_title']} | {$company}",
                    'rejected'    => "Application update — {$app['job_title']} | {$company}",
                    'hired'       => "🎉 Offer extended — {$app['job_title']} | {$company}",
                    'pending'     => "Application received — {$app['job_title']} | {$company}",
                ];

                $body_content = $email_bodies[$status] ?? '';
                $email_body   = '
                  <tr><td style="padding:28px 36px 0">
                    <p style="margin:0 0 8px;font-size:15px;color:#111827">Hi <strong>' . htmlspecialchars($app['name']) . '</strong>,</p>
                    <p style="margin:0 0 20px;font-size:14px;color:#6b7280;line-height:1.65">' . $body_content . '</p>
                    ' . ($notes ? '<div style="background:#f8fafc;border-left:3px solid #2563eb;padding:12px 16px;margin-bottom:20px;font-size:13px;color:#374151;line-height:1.6"><strong>Message from team:</strong><br>' . nl2br(htmlspecialchars($notes)) . '</div>' : '') . '
                    <p style="margin:0;font-size:13px;color:#9ca3af">Best regards,<br><strong>' . htmlspecialchars($company) . ' Team</strong></p>
                  </td></tr>';

                send_career_email($app['email'], $email_subj[$status] ?? "Application Update — {$company}", $email_body);

                // Update notified_at
                db()->prepare("UPDATE career_applications SET notified_at=NOW() WHERE id=?")->execute([$id]);
            }
        }

        echo json_encode(['ok'=>true]); exit;
    }

    // ── Delete Application ─────────────────────────────────────
    if ($action === 'delete_app') {
        $id = intval($_POST['id']??0);
        if ($id) {
            $app = db()->prepare("SELECT resume_path FROM career_applications WHERE id=?");
            $app->execute([$id]);
            $row = $app->fetch();
            if ($row && $row['resume_path'] && file_exists('/www/uploads/' . $row['resume_path']))
            @unlink('/www/uploads/'.$row['resume_path']);
            db()->prepare("DELETE FROM career_applications WHERE id=?")->execute([$id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

// ── Page data ──────────────────────────────────────────────────
$tab  = $_GET['tab'] ?? 'openings';
$jobs = db()->query("SELECT * FROM career_openings ORDER BY is_active DESC, created_at DESC")->fetchAll();

$apps_st = db()->prepare(
    "SELECT ca.*, co.title job_title, co.department
     FROM career_applications ca
     JOIN career_openings co ON co.id=ca.job_id
     ORDER BY ca.created_at DESC"
);
$apps_st->execute();
$apps = $apps_st->fetchAll();

// Stats
$total_apps   = count($apps);
$pending      = count(array_filter($apps, fn($a)=>$a['status']==='pending'));
$reviewing    = count(array_filter($apps, fn($a)=>$a['status']==='reviewing'));
$shortlisted  = count(array_filter($apps, fn($a)=>$a['status']==='shortlisted'));
$hired        = count(array_filter($apps, fn($a)=>$a['status']==='hired'));
$total_jobs   = count($jobs);
$active_jobs  = count(array_filter($jobs, fn($j)=>$j['is_active']));

$wa_ok = !empty(get_setting('wa_api')) && !empty(get_setting('wa_token'));

function appStatusBadge(string $s): string {
    $map = [
        'pending'     => 'background:#fef3c7;color:#92400e',
        'reviewing'   => 'background:#dbeafe;color:#1e40af',
        'shortlisted' => 'background:#ede9fe;color:#5b21b6',
        'rejected'    => 'background:#fee2e2;color:#991b1b',
        'hired'       => 'background:#dcfce7;color:#166534',
    ];
    $emojis = ['pending'=>'⏳','reviewing'=>'👀','shortlisted'=>'⭐','rejected'=>'❌','hired'=>'✅'];
    $style  = $map[$s] ?? 'background:#f1f5f9;color:#475569';
    $emoji  = $emojis[$s] ?? '';
    return "<span style='{$style};font-size:10px;font-weight:700;padding:3px 10px;border-radius:99px'>{$emoji} ".ucfirst($s)."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Careers — Admin — <?= $app_name ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/admin.css">
  <style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--gray-50);color:var(--gray-900);margin:0}
    .pw{max-width:1280px;margin:0 auto;padding:28px 24px}
    .ph{margin-bottom:24px}
    .ph h1{font-size:20px;font-weight:800;color:var(--gray-900);margin:0 0 4px}
    .ph p{color:var(--gray-500);font-size:13px;margin:0}

    .stats-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px}
    .sc{background:white;border:1px solid var(--border);border-radius:12px;padding:14px 16px}
    .sc .lbl{font-size:11px;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    .sc .val{font-size:22px;font-weight:900;color:var(--gray-900)}

    .tab-bar{display:flex;gap:4px;background:var(--gray-100);border-radius:10px;padding:4px;width:fit-content;margin-bottom:22px}
    .tb{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:600;color:var(--gray-500);text-decoration:none;transition:.15s;white-space:nowrap}
    .tb.active{background:white;color:var(--gray-900);box-shadow:0 1px 4px rgba(0,0,0,.08)}
    .tb:hover:not(.active){color:var(--gray-700)}

    .tbl-wrap{background:white;border:1px solid var(--border);border-radius:13px;overflow:hidden}
    .tbl{width:100%;border-collapse:collapse;font-size:13px}
    .tbl thead th{padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-400);background:var(--gray-50);border-bottom:1px solid var(--border)}
    .tbl tbody tr{border-bottom:1px solid var(--gray-100);transition:background .12s}
    .tbl tbody tr:last-child{border:none}
    .tbl tbody tr:hover{background:var(--gray-50)}
    .tbl td{padding:11px 14px;vertical-align:middle}

    .btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .14s;text-decoration:none}
    .btn-primary{background:var(--primary);color:white}.btn-primary:hover{background:var(--primary-hover)}
    .btn-ghost{background:white;color:var(--gray-700);border:1px solid var(--border)}.btn-ghost:hover{background:var(--gray-50)}
    .btn-success{background:#16a34a;color:white}.btn-success:hover{background:#15803d}
    .btn-danger{background:#dc2626;color:white}.btn-danger:hover{background:#b91c1c}
    .btn-warn{background:#d97706;color:white}.btn-warn:hover{background:#b45309}
    .btn-purple{background:#7c3aed;color:white}.btn-purple:hover{background:#6d28d9}

    .modal-bd{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:20px}
    .modal-bd.open{display:flex}
    .modal-box{background:white;border-radius:14px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.14);max-height:92vh;overflow-y:auto;display:flex;flex-direction:column}
    .mh{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:white;z-index:1;border-radius:14px 14px 0 0}
    .mh-title{font-size:15px;font-weight:800;color:var(--gray-900)}
    .mc{background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px;border-radius:5px}.mc:hover{background:var(--gray-100)}
    .mb{padding:20px}
    .mf{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:white;border-radius:0 0 14px 14px}

    .fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
    .fg.full{grid-template-columns:1fr}
    .fg.three{grid-template-columns:1fr 1fr 1fr}
    .flbl{display:block;font-size:12px;font-weight:700;color:var(--gray-600);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px}
    .flbl span{font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-400)}
    .finp{width:100%;box-sizing:border-box;padding:9px 12px;background:white;border:1.5px solid var(--border);border-radius:8px;color:var(--gray-900);font-size:13px;font-family:inherit}
    .finp:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    textarea.finp{resize:vertical;font-size:13px;height:100px}
    .fnote{font-size:11.5px;color:var(--gray-400);margin-top:4px}
    .msep{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-400);margin:18px 0 12px;display:flex;align-items:center;gap:8px}
    .msep::after{content:'';flex:1;height:1px;background:var(--border)}

    /* Job cards */
    .jobs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
    .jc{background:white;border:1.5px solid var(--border);border-radius:13px;padding:20px;transition:border-color .15s}
    .jc:hover{border-color:var(--gray-300)}
    .jc-title{font-size:15px;font-weight:800;color:var(--gray-900);margin:8px 0 4px}
    .jc-meta{font-size:12px;color:var(--gray-500);margin:0 0 12px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .tag{background:var(--gray-100);color:var(--gray-600);font-size:11px;font-weight:700;padding:2px 8px;border-radius:5px}
    .skill-tag{background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:600;padding:2px 7px;border-radius:5px}
    .vacancy-badge{background:#dcfce7;color:#166534;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px}

    /* Applicant detail in modal */
    .app-info{background:var(--gray-50);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:16px}
    .app-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:13px}
    .app-info-grid span{color:var(--gray-400)}.app-info-grid strong{color:var(--gray-900)}
    .cover-box{background:var(--gray-50);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:13px;color:var(--gray-700);line-height:1.7;max-height:150px;overflow-y:auto}
    .notify-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;margin-top:12px}
    .notify-box-title{font-size:12px;font-weight:800;color:#1d4ed8;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px}
    .wa-status{font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:99px}
  
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
    <h1>💼 Career Management</h1>
    <p>Job openings, applications, shortlisting and hiring</p>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="sc"><div class="lbl">Active Jobs</div><div class="val" style="color:var(--primary)"><?= $active_jobs ?></div></div>
    <div class="sc"><div class="lbl">Total Apps</div><div class="val"><?= $total_apps ?></div></div>
    <div class="sc" style="border-color:#fcd34d"><div class="lbl">Pending</div><div class="val" style="color:#d97706"><?= $pending ?></div></div>
    <div class="sc" style="border-color:#bfdbfe"><div class="lbl">Reviewing</div><div class="val" style="color:#1d4ed8"><?= $reviewing ?></div></div>
    <div class="sc" style="border-color:#c4b5fd"><div class="lbl">Shortlisted</div><div class="val" style="color:#7c3aed"><?= $shortlisted ?></div></div>
    <div class="sc" style="border-color:#86efac"><div class="lbl">Hired</div><div class="val" style="color:#16a34a"><?= $hired ?></div></div>
  </div>

  <!-- WA warning -->
  <?php if (!$wa_ok): ?>
  <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#92400e;display:flex;align-items:center;gap:10px">
    ⚠️ WhatsApp not configured — applicants won't receive WA notifications.
    <a href="<?= BASE_URL ?>/admin/settings.php" style="color:#92400e;font-weight:700;margin-left:4px">Setup in Settings →</a>
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="tab-bar">
    <a href="?tab=openings"     class="tb <?= $tab==='openings'    ?'active':'' ?>">Job Openings (<?= $active_jobs ?>)</a>
    <a href="?tab=applications" class="tb <?= $tab==='applications'?'active':'' ?>">
      Applications<?php if($pending>0): ?> <span style="background:#dc2626;color:white;font-size:10px;padding:1px 6px;border-radius:99px"><?= $pending ?></span><?php endif; ?>
    </a>
  </div>

  <!-- ════ JOB OPENINGS ════ -->
  <?php if ($tab === 'openings'): ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div style="font-size:13px;color:var(--gray-500)"><?= $total_jobs ?> total openings</div>
    <button onclick="openJobModal(null)" class="btn btn-primary">+ Add Opening</button>
  </div>

  <div class="jobs-grid">
    <?php foreach ($jobs as $j):
      $skills = array_filter(array_map('trim', explode(',', $j['skills_tags'] ?? '')));
      $app_count = count(array_filter($apps, fn($a) => $a['job_id'] == $j['id']));
    ?>
    <div class="jc" style="<?= $j['is_active'] ? '' : 'opacity:.55' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start">
        <span class="tag"><?= htmlspecialchars($j['department'] ?: 'General') ?></span>
        <div style="display:flex;gap:5px;align-items:center">
          <?php if ($j['openings_count'] > 1): ?>
          <span class="vacancy-badge"><?= $j['openings_count'] ?> openings</span>
          <?php endif; ?>
          <span style="background:<?= $j['is_active'] ? '#dcfce7' : '#f1f5f9' ?>;color:<?= $j['is_active'] ? '#166534' : '#475569' ?>;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">
            <?= $j['is_active'] ? '● Active' : '○ Inactive' ?>
          </span>
        </div>
      </div>
      <div class="jc-title"><?= htmlspecialchars($j['title']) ?></div>
      <div class="jc-meta">
        <?php if ($j['location']): ?><span>📍 <?= htmlspecialchars($j['location']) ?></span><?php endif; ?>
        <?php if ($j['job_type']): ?><span class="tag"><?= htmlspecialchars($j['job_type']) ?></span><?php endif; ?>
        <?php if ($j['salary_range']): ?><span style="color:var(--primary);font-weight:700"><?= htmlspecialchars($j['salary_range']) ?></span><?php endif; ?>
        <?php if ($j['experience_years']): ?><span class="tag">⏱ <?= htmlspecialchars($j['experience_years']) ?></span><?php endif; ?>
      </div>
      <?php if ($skills): ?>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:12px">
        <?php foreach (array_slice($skills,0,5) as $sk): ?>
        <span class="skill-tag"><?= htmlspecialchars($sk) ?></span>
        <?php endforeach; ?>
        <?php if (count($skills) > 5): ?><span class="skill-tag">+<?= count($skills)-5 ?> more</span><?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
        <a href="?tab=applications&job_id=<?= $j['id'] ?>" style="font-size:12px;color:var(--primary);font-weight:700;text-decoration:none">
          <?= $app_count ?> application<?= $app_count!=1?'s':'' ?> →
        </a>
        <div style="display:flex;gap:5px">
          <button onclick="openJobModal(<?= htmlspecialchars(json_encode($j)) ?>)" class="btn btn-ghost">Edit</button>
          <button onclick="toggleJob(<?= $j['id'] ?>,<?= $j['is_active']?0:1 ?>)" class="btn <?= $j['is_active']?'btn-warn':'btn-success' ?>"><?= $j['is_active']?'Pause':'Activate' ?></button>
          <button onclick="deleteJob(<?= $j['id'] ?>,'<?= htmlspecialchars(addslashes($j['title'])) ?>')" class="btn btn-danger">Delete</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ════ APPLICATIONS ════ -->
  <?php else:
    $filter_job = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
    $filter_status = $_GET['status'] ?? '';
    $filtered = $apps;
    if ($filter_job) $filtered = array_filter($filtered, fn($a)=>$a['job_id']==$filter_job);
    if ($filter_status) $filtered = array_filter($filtered, fn($a)=>$a['status']===$filter_status);
  ?>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
    <select onchange="location.href='?tab=applications&job_id='+this.value+'&status=<?= urlencode($filter_status) ?>'" class="finp" style="width:auto">
      <option value="0">All Positions</option>
      <?php foreach ($jobs as $j): ?>
      <option value="<?= $j['id'] ?>" <?= $filter_job==$j['id']?'selected':'' ?>><?= htmlspecialchars($j['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <select onchange="location.href='?tab=applications&job_id=<?= $filter_job ?>&status='+this.value" class="finp" style="width:auto">
      <option value="">All Status</option>
      <?php foreach (['pending','reviewing','shortlisted','rejected','hired'] as $s): ?>
      <option value="<?= $s ?>" <?= $filter_status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="margin-left:auto;font-size:13px;color:var(--gray-500)"><?= count($filtered) ?> applications</div>
  </div>

  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr>
        <th>Applicant</th><th>Position</th><th>Applied</th><th>Status</th><th>Resume</th><th>Notified</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if (empty($filtered)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--gray-400);padding:40px">No applications found</td></tr>
        <?php else: foreach ($filtered as $a): ?>
        <tr>
          <td>
            <div style="font-weight:600;color:var(--gray-900)"><?= htmlspecialchars($a['name']) ?></div>
            <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($a['email']) ?></div>
            <?php if ($a['phone']): ?><div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($a['phone']) ?></div><?php endif; ?>
          </td>
          <td>
            <div style="font-weight:600;font-size:12.5px"><?= htmlspecialchars($a['job_title']) ?></div>
            <div style="font-size:11px;color:var(--gray-400)"><?= htmlspecialchars($a['department'] ?? '') ?></div>
          </td>
          <td style="font-size:11.5px;color:var(--gray-500)"><?= date('d M Y', strtotime($a['created_at'])) ?></td>
          <td><?= appStatusBadge($a['status']) ?></td>
          <td>
            <?php if ($a['resume_path']): ?>
            <a href="<?= BASE_URL ?>/admin/download-resume.php?file=<?= urlencode(basename($a['resume_path'])) ?>" target="_blank" class="btn btn-ghost" style="font-size:11px;padding:4px 10px">📄 View</a>
            <?php else: ?>
            <span style="font-size:11px;color:var(--gray-300)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($a['notified_at']): ?>
            <span style="font-size:11px;color:#16a34a;font-weight:600">✓ <?= date('d M', strtotime($a['notified_at'])) ?></span>
            <?php else: ?>
            <span style="font-size:11px;color:var(--gray-300)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap">
              <button onclick="openAppModal(<?= htmlspecialchars(json_encode($a)) ?>)" class="btn btn-primary">Review</button>
              <button onclick="deleteApp(<?= $a['id'] ?>,'<?= htmlspecialchars(addslashes($a['name'])) ?>')" class="btn btn-danger">Delete</button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
</div>

<!-- ══ ADD/EDIT JOB MODAL ══ -->
<div class="modal-bd" id="jobModal">
  <div class="modal-box" style="max-width:700px">
    <div class="mh">
      <span class="mh-title" id="jobModalTitle">Add Job Opening</span>
      <button class="mc" onclick="closeModal('jobModal')">✕</button>
    </div>
    <div class="mb">
      <input type="hidden" id="jm_id">
      <div class="fg">
        <div><label class="flbl">Job Title</label><input type="text" id="jm_title" class="finp" placeholder="Senior PHP Developer"></div>
        <div>
  <label class="flbl">Department</label>
  <select id="jm_dept" class="finp">
    <option value="">Select</option>
    <option value="engineering">Engineering</option>
    <option value="tech">Tech</option>
    <option value="design">Design</option>
    <option value="marketing">Marketing</option>
    <option value="growth">Growth</option>
    <option value="sales">Sales</option>
    <option value="business">Business</option>
    <option value="support">Support</option>
    <option value="customer">Customer</option>
    <option value="finance">Finance</option>
    <option value="accounting">Accounting</option>
    <option value="hr">HR</option>
    <option value="people">People</option>
    <option value="product">Product</option>
    <option value="data">Data</option>
    <option value="analytics">Analytics</option>
    <option value="others">Others</option>
  </select>
</div>
      </div>
      <div class="fg three">
        <div><label class="flbl">Location</label><input type="text" id="jm_loc" class="finp" placeholder="Remote / Bangalore"></div>
        <div><label class="flbl">Job Type</label>
          <select id="jm_type" class="finp">
            <option>Full-time</option><option>Part-time</option><option>Contract</option><option>Internship</option>
          </select>
        </div>
        <div><label class="flbl">Vacancies</label><input type="number" id="jm_count" class="finp" placeholder="1" min="1" value="1"></div>
      </div>
      <div class="fg">
        <div><label class="flbl">Salary Range</label><input type="text" id="jm_salary" class="finp" placeholder="₹8L – ₹14L per year"></div>
        <div><label class="flbl">Experience</label><input type="text" id="jm_exp" class="finp" placeholder="2–4 years"></div>
      </div>
      <div class="fg full">
        <div><label class="flbl">Skills / Tags <span>(comma separated)</span></label>
        <input type="text" id="jm_skills" class="finp" placeholder="PHP, MySQL, Linux, REST API"></div>
      </div>
      <div class="fg full">
        <div><label class="flbl">Description</label><textarea id="jm_desc" class="finp"></textarea></div>
      </div>
      <div class="fg full">
        <div><label class="flbl">Requirements <span>(one per line)</span></label><textarea id="jm_req" class="finp"></textarea></div>
      </div>
      <div class="fg full">
        <div><label class="flbl">Responsibilities <span>(one per line)</span></label><textarea id="jm_resp" class="finp"></textarea></div>
      </div>
      <div class="fg">
        <div><label class="flbl">Status</label>
          <select id="jm_active" class="finp"><option value="1">✓ Active</option><option value="0">✗ Inactive</option></select>
        </div>
      </div>
    </div>
    <div class="mf">
      <button onclick="closeModal('jobModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveJob()" class="btn btn-primary" id="saveJobBtn">Save Opening</button>
    </div>
  </div>
</div>

<!-- ══ APPLICATION REVIEW MODAL ══ -->
<div class="modal-bd" id="appModal">
  <div class="modal-box" style="max-width:660px">
    <div class="mh">
      <span class="mh-title" id="appModalTitle">Application Review</span>
      <button class="mc" onclick="closeModal('appModal')">✕</button>
    </div>
    <div class="mb">
      <div class="app-info" id="appInfoBox"></div>

      <div class="msep">Cover Letter</div>
      <div class="cover-box" id="appCoverBox"></div>

      <div class="msep">Status & Decision</div>
      <input type="hidden" id="am_id">
      <div class="fg">
        <div>
          <label class="flbl">Update Status</label>
          <select id="am_status" class="finp">
            <option value="pending">⏳ Pending</option>
            <option value="reviewing">👀 Reviewing</option>
            <option value="shortlisted">⭐ Shortlisted</option>
            <option value="rejected">❌ Rejected</option>
            <option value="hired">✅ Hired</option>
          </select>
        </div>
        <div id="am_resume_col">
          <label class="flbl">Resume</label>
          <div id="am_resume_link" style="padding-top:2px"></div>
        </div>
      </div>
      <div class="fg full">
        <div>
          <label class="flbl">Admin Notes <span>(internal — can be shared with applicant)</span></label>
          <textarea id="am_notes" class="finp" style="height:80px" placeholder="Optional message to include in notification…"></textarea>
        </div>
      </div>

      <!-- Notification box -->
      <div class="notify-box">
        <div class="notify-box-title">📣 Notify Applicant</div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;font-weight:600;color:var(--gray-700)">
            <input type="checkbox" id="am_notify" style="width:15px;height:15px;accent-color:var(--primary)" checked>
            Send notification on save
          </label>
          <span class="wa-status" id="wa_status_badge"
                style="background:<?= $wa_ok?'#dcfce7':'#fee2e2' ?>;color:<?= $wa_ok?'#166534':'#991b1b' ?>">
            <?= $wa_ok ? '● WhatsApp ready' : '○ WA not configured' ?>
          </span>
          <span class="wa-status" style="background:#dbeafe;color:#1e40af">● Email ready</span>
        </div>
        <div id="notify_preview" style="margin-top:10px;font-size:12px;color:var(--gray-500);line-height:1.5;display:none"></div>
      </div>
    </div>
    <div class="mf">
      <button onclick="closeModal('appModal')" class="btn btn-ghost">Cancel</button>
      <button onclick="saveApp()" class="btn btn-primary" id="saveAppBtn">Save & Notify</button>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
const BASE = '<?= BASE_URL ?>';

// ── Job Modal ──────────────────────────────────────────────
function openJobModal(j) {
  document.getElementById('jobModalTitle').textContent = j ? 'Edit Job Opening' : 'Add Job Opening';
  document.getElementById('jm_id').value       = j?.id || '';
  document.getElementById('jm_title').value    = j?.title || '';
  document.getElementById('jm_dept').value     = j?.department || '';
  document.getElementById('jm_loc').value      = j?.location || 'Remote';
  document.getElementById('jm_type').value     = j?.job_type || 'Full-time';
  document.getElementById('jm_count').value    = j?.openings_count || 1;
  document.getElementById('jm_salary').value   = j?.salary_range || '';
  document.getElementById('jm_exp').value      = j?.experience_years || '';
  document.getElementById('jm_skills').value   = j?.skills_tags || '';
  document.getElementById('jm_desc').value     = j?.description || '';
  document.getElementById('jm_req').value      = j?.requirements || '';
  document.getElementById('jm_resp').value     = j?.responsibilities || '';
  document.getElementById('jm_active').value   = j ? (j.is_active ? '1' : '0') : '1';
  document.getElementById('jobModal').classList.add('open');
}

function saveJob() {
  const btn = document.getElementById('saveJobBtn');
  btn.disabled=true; btn.textContent='Saving…';
  const fd = new FormData();
  fd.append('action','save_job');
  ['id','title','department','location','job_type','openings_count','salary_range','experience_years',
   'skills_tags','description','requirements','responsibilities','is_active'].forEach(k => {
    const elId = 'jm_'+k.replace('openings_count','count').replace('experience_years','exp').replace('skills_tags','skills')
                         .replace('description','desc').replace('requirements','req').replace('responsibilities','resp')
                         .replace('is_active','active').replace('job_type','type').replace('department','dept').replace('location','loc').replace('salary_range','salary');
    const el = document.getElementById(elId) || document.getElementById('jm_'+k);
    if (el) fd.append(k, el.value);
  });
  // map correctly
  const map = {id:'jm_id',title:'jm_title',department:'jm_dept',location:'jm_loc',job_type:'jm_type',
    openings_count:'jm_count',salary_range:'jm_salary',experience_years:'jm_exp',skills_tags:'jm_skills',
    description:'jm_desc',requirements:'jm_req',responsibilities:'jm_resp',is_active:'jm_active'};
  const fd2 = new FormData();
  fd2.append('action','save_job');
  Object.entries(map).forEach(([k,elId])=>{ const el=document.getElementById(elId); if(el) fd2.append(k,el.value); });

  fetch(location.href,{method:'POST',body:fd2})
    .then(r=>r.json()).then(d=>{
      if(d.ok) location.reload();
      else { alert(d.error||'Failed'); btn.disabled=false; btn.textContent='Save Opening'; }
    });
}

function toggleJob(id,val) {
  const fd=new FormData(); fd.append('action','toggle_job'); fd.append('id',id); fd.append('is_active',val);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Failed'); });
}

function deleteJob(id,title) {
  if(!confirm('Delete job "'+title+'" and ALL its applications?')) return;
  const fd=new FormData(); fd.append('action','delete_job'); fd.append('id',id);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Failed'); });
}

// ── Application Modal ──────────────────────────────────────
const STATUS_PREVIEWS = {
  reviewing:   '👀 Will tell applicant: "Your application is under review."',
  shortlisted: '⭐ Will tell applicant: "You have been shortlisted! Team will contact you."',
  rejected:    '❌ Will tell applicant: "After review, we\'ve moved forward with other candidates."',
  hired:       '🎉 Will tell applicant: "Congratulations! Offer extended — HR will reach out."',
  pending:     '⏳ Will tell applicant: "Application received, awaiting review."',
};

function openAppModal(a) {
  document.getElementById('appModalTitle').textContent = a.name + ' — ' + a.job_title;
  document.getElementById('am_id').value     = a.id;
  document.getElementById('am_status').value = a.status;
  document.getElementById('am_notes').value  = a.admin_notes || '';

  // Resume link
  const rl = document.getElementById('am_resume_link');
  rl.innerHTML = a.resume_path
    ? `<a href="${BASE}/admin/download-resume.php?file=${encodeURIComponent(a.resume_path.split('/').pop())}" target="_blank" class="btn btn-ghost" style="font-size:12px;padding:5px 12px">📄 Download Resume</a>`
    : '<span style="font-size:12px;color:var(--gray-300)">No resume uploaded</span>';

  // Info grid
  document.getElementById('appInfoBox').innerHTML = `
    <div class="app-info-grid">
      <div><span>Name</span><strong>${a.name}</strong></div>
      <div><span>Email</span><strong><a href="mailto:${a.email}" style="color:var(--primary)">${a.email}</a></strong></div>
      <div><span>Phone</span><strong>${a.phone||'—'}</strong></div>
      <div><span>Position</span><strong>${a.job_title}</strong></div>
      <div><span>Applied</span><strong>${a.created_at.substr(0,10)}</strong></div>
      ${a.portfolio_url ? `<div><span>Portfolio</span><strong><a href="${a.portfolio_url}" target="_blank" style="color:var(--primary)">View →</a></strong></div>` : ''}
    </div>`;

  document.getElementById('appCoverBox').textContent = a.cover_letter || '—';

  updateNotifyPreview();
  document.getElementById('appModal').classList.add('open');
}

function updateNotifyPreview() {
  const st  = document.getElementById('am_status').value;
  const box = document.getElementById('notify_preview');
  const chk = document.getElementById('am_notify').checked;
  if (chk) {
    box.style.display = 'block';
    box.textContent   = STATUS_PREVIEWS[st] || '';
  } else {
    box.style.display = 'none';
  }
}
document.getElementById('am_status').addEventListener('change', updateNotifyPreview);
document.getElementById('am_notify').addEventListener('change', updateNotifyPreview);

function saveApp() {
  const btn = document.getElementById('saveAppBtn');
  btn.disabled=true; btn.textContent='Saving…';
  const fd=new FormData();
  fd.append('action','update_app_status');
  fd.append('id',    document.getElementById('am_id').value);
  fd.append('status',document.getElementById('am_status').value);
  fd.append('admin_notes',document.getElementById('am_notes').value);
  fd.append('notify', document.getElementById('am_notify').checked ? '1' : '0');

  fetch(location.href,{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{
      if(d.ok) location.reload();
      else { alert(d.error||'Failed'); btn.disabled=false; btn.textContent='Save & Notify'; }
    });
}

function deleteApp(id,name) {
  if(!confirm('Delete application from "'+name+'"?')) return;
  const fd=new FormData(); fd.append('action','delete_app'); fd.append('id',id);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.ok) location.reload(); else alert(d.error||'Failed'); });
}

function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bd').forEach(m=>{
  m.addEventListener('click',e=>{ if(e.target===m) m.classList.remove('open'); });
});
</script>
</body>
</html>
