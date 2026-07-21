<?php
/**
 * api/career-apply.php
 * Job application submission — with WA + email notification to admin
 */
require_once __DIR__ . '/../includes/bootstrap.php';
session_start_safe();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$job_id = intval($_POST['job_id'] ?? 0);
$name   = trim($_POST['name']   ?? '');
$email  = trim($_POST['email']  ?? '');
$phone  = trim($_POST['phone']  ?? '');
$link   = trim($_POST['portfolio_url'] ?? '');
$cover  = trim($_POST['cover_letter']  ?? '');

// ── Validate ──────────────────────────────────────────────────
if (!$job_id || !$name || !$email || !$cover) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']); exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid email address']); exit;
}

$job_st = db()->prepare("SELECT id,title,department,openings_count FROM career_openings WHERE id=? AND is_active=1 LIMIT 1");
$job_st->execute([$job_id]);
$job = $job_st->fetch();
if (!$job) {
    echo json_encode(['ok' => false, 'error' => 'This position is no longer available']); exit;
}

// Duplicate check
$dup = db()->prepare("SELECT id FROM career_applications WHERE job_id=? AND email=? LIMIT 1");
$dup->execute([$job_id, $email]);
if ($dup->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'You have already applied for this position']); exit;
}

// ── Resume upload ─────────────────────────────────────────────
$resume_path = '';
if (!empty($_FILES['resume']['tmp_name'])) {
    $file   = $_FILES['resume'];
    $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ok_ext = ['pdf','doc','docx'];
    $ok_mime= ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
if (!in_array($mime, $ok_mime)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid file type']); exit;
}
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Resume must be under 5 MB']); exit;
    }

    $upload_dir = '/www/uploads/resumes/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $fname = 'resume_' . $job_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $fname)) {
        echo json_encode(['ok' => false, 'error' => 'Failed to upload resume. Try again.']); exit;
    }
    $resume_path = 'resumes/' . $fname;
}

// ── Save application ──────────────────────────────────────────
try {
    db()->prepare(
        "INSERT INTO career_applications
           (job_id,name,email,phone,portfolio_url,cover_letter,resume_path,status,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,'pending',NOW(),NOW())"
    )->execute([$job_id,$name,$email,$phone,$link,$cover,$resume_path]);
    $app_id = (int)db()->lastInsertId();
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error. Try again.']); exit;
}

$company     = get_setting('site_name', APP_NAME);
$admin_panel = BASE_URL . '/admin/career.php?tab=applications';

// ── Email to applicant ────────────────────────────────────────
try {
    require_once __DIR__ . '/../includes/mailer.php';
    $subj = "Application received — {$job['title']} at {$company}";
    $body = '
      <tr><td style="padding:28px 36px 0">
        <p style="margin:0 0 8px;font-size:15px;color:#111827">Hi <strong>' . htmlspecialchars($name) . '</strong>,</p>
        <p style="margin:0 0 16px;font-size:14px;color:#6b7280;line-height:1.65">
          Thanks for applying for <strong>' . htmlspecialchars($job['title']) . '</strong> at <strong>' . htmlspecialchars($company) . '</strong>.
          We\'ve received your application and will review it shortly. We typically respond within 5–7 business days.
        </p>
        <p style="margin:0;font-size:14px;color:#6b7280">Best,<br><strong>' . htmlspecialchars($company) . ' Team</strong></p>
      </td></tr>';
    send_mail($email, $subj, $body);
} catch (Exception $e) { /* non-fatal */ }

// ── Email to admin ────────────────────────────────────────────
try {
    require_once __DIR__ . '/../includes/mailer.php';
    $admin_email = get_setting('company_email', 'admin@' . parse_url(BASE_URL, PHP_URL_HOST));
    $asubj = "New Application: {$job['title']} — {$name}";
    $abody = '
      <tr><td style="padding:28px 36px 0">
        <p style="margin:0 0 16px;font-size:15px;color:#111827">New job application received.</p>
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px">
          <tr><td style="padding:7px 0;color:#6b7280;width:130px">Position</td><td style="color:#111827;font-weight:600">' . htmlspecialchars($job['title']) . '</td></tr>
          <tr><td style="padding:7px 0;color:#6b7280">Department</td><td style="color:#111827">' . htmlspecialchars($job['department'] ?? '') . '</td></tr>
          <tr><td style="padding:7px 0;color:#6b7280">Applicant</td><td style="color:#111827;font-weight:600">' . htmlspecialchars($name) . '</td></tr>
          <tr><td style="padding:7px 0;color:#6b7280">Email</td><td><a href="mailto:' . htmlspecialchars($email) . '" style="color:var(--primary)">' . htmlspecialchars($email) . '</a></td></tr>
          <tr><td style="padding:7px 0;color:#6b7280">Phone</td><td style="color:#111827">' . htmlspecialchars($phone ?: '—') . '</td></tr>
          ' . ($resume_path ? '<tr><td style="padding:7px 0;color:#6b7280">Resume</td><td><a href="' . BASE_URL . '/admin/download-resume.php?file=' . urlencode($fname) . '" style="color:var(--primary)">Download Resume</a></td></tr>' : '') . '
        </table>
        <a href="' . $admin_panel . '" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 22px;border-radius:8px;font-weight:700;text-decoration:none;font-size:13px">
          Review Application →
        </a>
      </td></tr>';
    send_mail($admin_email, $asubj, $abody);
} catch (Exception $e) { /* non-fatal */ }

// ── WhatsApp to admin ─────────────────────────────────────────
try {
    $wa_api   = get_setting('wa_api', '');
    $wa_token = get_setting('wa_token', '');
    $wa_admin = get_setting('wa_admin_number', '');

    if ($wa_api && $wa_token && $wa_admin) {
        $wa_msg = "🔔 *New Job Application*\n\n"
                . "📌 *Position:* {$job['title']}\n"
                . "👤 *Applicant:* {$name}\n"
                . "📧 *Email:* {$email}\n"
                . ($phone ? "📱 *Phone:* {$phone}\n" : "")
                . "\n👉 Review: {$admin_panel}";

        $wa_url = rtrim($wa_api, '/')
                . '?number=' . urlencode($wa_admin)
                . '&type=text'
                . '&message=' . urlencode($wa_msg)
                . '&token=' . urlencode($wa_token);

        $ch = curl_init($wa_url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8]);
        curl_exec($ch);
        curl_close($ch);
    }
} catch (Exception $e) { /* non-fatal */ }

echo json_encode(['ok' => true, 'id' => $app_id]);
