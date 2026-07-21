<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/os_icons.php';
require_login();

$user     = current_user();
$app_name = APP_NAME;
$balance  = number_format((float)$user['wallet_balance'], 2);
$curr     = $user['currency'] ?? 'INR';
$curr_sym = $curr === 'INR' ? '₹' : '$';
$avatar   = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1));
$fname    = htmlspecialchars($user['account_type']==='organization'?($user['company_name']?:$user['username']):($user['full_name']?:$user['username']));
$uname    = $user['username'];
$csrf     = csrf_token();
$err      = '';
$success  = '';

// ── Helpers ────────────────────────────────────────────────
function gen_ticket_id(): string {
    do {
        $id = (string)random_int(100000, 999999);
        $ex = db()->prepare('SELECT id FROM tickets WHERE ticket_id=? LIMIT 1');
        $ex->execute([$id]);
    } while ($ex->fetch());
    return $id;
}

function ticket_dept_label(string $d): string {
    return match($d) {
        'technical' => 'Cloud Support',
        'billing'   => 'Cloud Billing',
        'sales'     => 'Cloud Sales',
        'abuse'     => 'Abuse',
        default     => 'Cloud Support',
    };
}

function ticket_status_label(string $s): string {
    return match($s) {
        'open'        => 'Open',
        'in_progress' => 'Answered',
        'waiting'     => 'Pending on customer',
        'resolved'    => 'Answered',
        'closed'      => 'Closed',
        default       => ucfirst($s),
    };
}

function send_ticket_email_to_user(array $user, array $ticket, string $reply_msg, bool $is_admin_reply): void {
    try {
        $app     = APP_NAME;
        $tid     = $ticket['ticket_id'];
        $subject = $ticket['subject'];
        $who     = $is_admin_reply ? $app . ' Support Team' : ($user['full_name'] ?: $user['username']);
        $action_label = $is_admin_reply ? 'We replied to your ticket' : 'Your reply was received';
        $color   = $is_admin_reply ? '#1a1a2e' : '#16a34a';

        // Escape all user‑supplied content for HTML safety
        $user_name_escaped  = htmlspecialchars($user['full_name'] ?: $user['username']);
        $subject_escaped    = htmlspecialchars($subject);
        $who_escaped        = htmlspecialchars($who);
        $reply_msg_escaped  = nl2br(htmlspecialchars($reply_msg));  // preserve line breaks

        // The reply message text (without HTML)
        $action_message = $is_admin_reply
            ? 'Our support team has replied to your ticket.'
            : 'We received your reply.';

        // The CSRF token (available from outer scope via $csrf)
        $csrf_token = $csrf ?? '';

        // Build the ticket view URL
        $ticket_url = BASE_URL . "/tickets.php?id=" . $ticket['id'];

        // ─────────────────────────────────────────────────────────
        // HEREDOC – all variables are pre‑computed, no PHP inside
        // ─────────────────────────────────────────────────────────
        $body = <<<HTML
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='padding:32px 0'>
  <tr><td align='center'>
    <table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
      <tr><td style='background:{$color};padding:24px 32px'>
        <h2 style='margin:0;color:#fff;font-size:20px'>{$app} Support</h2>
        <p style='margin:4px 0 0;color:rgba(255,255,255,.75);font-size:13px'>{$action_label}</p>
       </td>
      </td>
      <tr><td style='padding:28px 32px'>
        <p style='font-size:14px;color:#374151;margin:0 0 6px'>
          Hi <strong>{$user_name_escaped}</strong>,
        </p>
        <p style='font-size:13px;color:#6b7280;margin:0 0 20px'>
          {$action_message}
        </p>
        <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0;margin-bottom:20px'>
          <tr><td style='padding:12px 16px;border-bottom:1px solid #e2e8f0'>
            <span style='font-size:11px;color:#94a3b8;text-transform:uppercase;font-weight:700'>Ticket</span>&nbsp;
            <span style='font-size:13px;font-weight:700;color:#1e293b;font-family:monospace'>#{$tid}</span>
           </td>
          </tr>
          <tr><td style='padding:12px 16px;border-bottom:1px solid #e2e8f0'>
            <span style='font-size:11px;color:#94a3b8;text-transform:uppercase;font-weight:700'>Subject</span><br>
            <span style='font-size:13px;color:#374151'>{$subject_escaped}</span>
           </td>
          </tr>
          <tr><td style='padding:14px 16px'>
            <span style='font-size:11px;color:#94a3b8;text-transform:uppercase;font-weight:700'>Reply from {$who_escaped}</span><br>
            <div style='font-size:13.5px;color:#1e293b;line-height:1.7;margin-top:8px;white-space:pre-wrap'>{$reply_msg_escaped}</div>
           </td>
          </tr>
        </table>
        <div style='text-align:center'>
          <a href='{$ticket_url}' style='background:{$color};color:#fff;text-decoration:none;padding:11px 28px;border-radius:8px;font-size:13px;font-weight:700;display:inline-block'>
            View Ticket &rarr;
          </a>
        </div>
        <p style='font-size:12px;color:#94a3b8;text-align:center;margin:20px 0 0'>
          {$app} · Ticket #{$tid} · Reply to this email or visit the portal
        </p>
       </td>
      </tr>
    </table>
    </td>
   </tr>
</table>
<!-- ══ MOBILE NEW TICKET MODAL (static HTML – no PHP inside) ═════════════ -->
<div class="tk-modal-overlay" id="tk-modal-overlay">
  <div class="tk-modal">
    <div class="tk-modal-handle"></div>
    <div class="tk-modal-head">
      <div class="tk-modal-title">Open Support Ticket</div>
      <button class="tk-modal-close" onclick="closeTkModal()">×</button>
    </div>
    <div class="tk-modal-body">
      <form method="POST" id="ntk-modal-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="{$csrf_token}">
        <input type="hidden" name="action" value="create_ticket">
        <input type="hidden" name="message" id="ntk-modal-msg-hidden">

        <div class="form-group">
          <label class="flabel">Subject <span style="color:var(--danger)">*</span></label>
          <input type="text" name="subject" class="form-control" required maxlength="200"
                 placeholder="Brief description of your issue" id="ntk-modal-subject">
        </div>

        <div class="ntk-grid-2" style="margin-bottom:14px">
          <div class="form-group" style="margin-bottom:0">
            <label class="flabel">Department</label>
            <select name="department" class="form-control">
              <option value="technical">Cloud Support</option>
              <option value="billing">Cloud Billing</option>
              <option value="sales">Cloud Sales</option>
              <option value="abuse">Abuse</option>
            </select>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="flabel">Priority</label>
            <select name="priority" class="form-control">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="flabel">Message <span style="color:var(--danger)">*</span></label>
          <div class="ntk-editor-wrap">
            <div class="ntk-toolbar">
              <button type="button" onclick="ntkModalFmt('bold')"          class="rte-btn" title="Bold"><b>B</b></button>
              <button type="button" onclick="ntkModalFmt('italic')"        class="rte-btn" title="Italic"><i style="font-style:italic">I</i></button>
              <button type="button" onclick="ntkModalFmt('underline')"     class="rte-btn" title="Underline"><u>U</u></button>
              <button type="button" onclick="ntkModalFmt('strikeThrough')" class="rte-btn" title="Strikethrough"><s>S</s></button>
              <div class="rte-btn sep"></div>
              <button type="button" onclick="ntkModalFmt('insertUnorderedList')" class="rte-btn" title="Bullet">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/></svg>
              </button>
              <button type="button" onclick="ntkModalFmt('insertOrderedList')" class="rte-btn" title="Numbered">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
              </button>
              <div class="rte-btn sep"></div>
              <button type="button" onclick="ntkModalFmt('formatBlock','pre')" class="rte-btn" title="Code">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
              </button>
            </div>
            <div id="ntk-modal-rte" class="ntk-rte" contenteditable="true"
                 data-placeholder="Describe your issue in detail..." spellcheck="true"></div>
            <div id="ntk-modal-attach-preview" class="ntk-attach-preview"></div>
            <div class="ntk-editor-footer">
              <div style="display:flex;align-items:center;gap:8px">
                <label for="ntk-modal-attach" class="ntk-attach-lbl">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                  Attach
                </label>
                <input type="file" id="ntk-modal-attach" name="attachments[]" multiple
                       accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx,.xlsx,.csv,.log"
                       style="display:none" onchange="ntkModalHandleAttach(this)">
              </div>
              <div style="width:100%;margin-top:4px">
                <button type="button" onclick="ntkModalSubmit()" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-size:14px">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                  Submit Ticket
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

</body>
</html>
HTML;

        require_once __DIR__ . '/includes/mailer.php';
        send_mail($user['email'], $user['full_name'] ?: $user['username'],
            "[Ticket #{$tid}] Re: " . $subject, $body);
    } catch (Throwable $e) {
        error_log('[ticket mail user] ' . $e->getMessage());
    }
}

function send_ticket_email_to_admin(array $user, array $ticket, string $message, bool $is_new): void {
    try {
        $admin_email = get_setting('company_email', get_setting('SMTP_FROM', ''));
        if (!$admin_email) return;
        $app  = APP_NAME;
        $tid  = $ticket['ticket_id'];
        $dept = ticket_dept_label($ticket['department'] ?? 'general');
        $prio = strtoupper($ticket['priority'] ?? 'medium');
        $prio_color = match($ticket['priority'] ?? 'medium') {
            'urgent' => '#dc2626', 'high' => '#d97706', default => '#16a34a'
        };

        $action = $is_new ? "New ticket created" : "Customer replied";
        $body = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='padding:32px 0'>
<tr><td align='center'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)'>
  <tr><td style='background:#1a1a2e;padding:24px 32px;display:flex;align-items:center;justify-content:space-between'>
    <div>
      <h2 style='margin:0;color:#fff;font-size:18px'>🎫 {$action}</h2>
      <p style='margin:4px 0 0;color:#94a3b8;font-size:12px'>{$app} Support System</p>
    </div>
  </td></tr>
  <tr><td style='padding:24px 32px'>
    <table width='100%' style='border-collapse:collapse;font-size:13px;margin-bottom:18px'>
      <tr><td style='padding:7px 0;color:#64748b;width:120px'>Ticket ID</td><td><span style='font-family:monospace;font-weight:700;color:#2563eb'>#{$tid}</span></td></tr>
      <tr><td style='padding:7px 0;color:#64748b'>Customer</td><td><strong>" . htmlspecialchars($user['full_name'] ?: $user['username']) . "</strong> (ID: {$user['id']})</td></tr>
      <tr><td style='padding:7px 0;color:#64748b'>Email</td><td>" . htmlspecialchars($user['email']) . "</td></tr>
      <tr><td style='padding:7px 0;color:#64748b'>Subject</td><td><strong>" . htmlspecialchars($ticket['subject']) . "</strong></td></tr>
      <tr><td style='padding:7px 0;color:#64748b'>Department</td><td>{$dept}</td></tr>
      <tr><td style='padding:7px 0;color:#64748b'>Priority</td><td><span style='color:{$prio_color};font-weight:700'>{$prio}</span></td></tr>
    </table>
    <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:18px'>
      <div style='font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;margin-bottom:8px'>Message</div>
      <div style='font-size:13.5px;color:#1e293b;line-height:1.7;white-space:pre-wrap'>" . htmlspecialchars($message) . "</div>
    </div>
    <div style='text-align:center'>
      <a href='" . BASE_URL . "/admin/?tab=tickets&tid={$tid}' style='background:#1a1a2e;color:#fff;text-decoration:none;padding:11px 28px;border-radius:8px;font-size:13px;font-weight:700;display:inline-block'>
        Reply in Admin Panel &rarr;
      </a>
    </div>
  </td></tr>
</table>
</td></tr></table></body></html>";

        require_once __DIR__ . '/includes/mailer.php';
        send_mail($admin_email, $app . ' Admin',
            "[Ticket #{$tid}] " . ($is_new ? 'New: ' : 'Reply: ') . $ticket['subject'], $body);
    } catch (Throwable $e) {
        error_log('[ticket mail admin] ' . $e->getMessage());
    }
}

// ── POST handler ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $err = 'Invalid request.'; goto RENDER; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_ticket') {
        $subject    = trim($_POST['subject']    ?? '');
        $department = $_POST['department']      ?? 'technical';
        $priority   = $_POST['priority']        ?? 'medium';
        $message    = trim($_POST['message']    ?? '');

        if (strlen($subject) < 3)   { $err = 'Subject too short.'; goto RENDER; }
        if (strlen($message) < 10)  { $err = 'Please describe your issue.'; goto RENDER; }

        $tid = gen_ticket_id();
        db()->prepare(
            'INSERT INTO tickets (ticket_id,user_id,subject,department,priority,status,related_to)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$tid, $user['id'], $subject, $department, $priority, 'open', 'other']);
        $tk_id = (int)db()->lastInsertId();

        db()->prepare(
            'INSERT INTO ticket_replies (ticket_id,user_id,message,is_admin) VALUES (?,?,?,0)'
        )->execute([$tk_id, $user['id'], $message]);

        $ticket_row = ['id'=>$tk_id,'ticket_id'=>$tid,'subject'=>$subject,'department'=>$department,'priority'=>$priority];
        send_ticket_email_to_admin($user, $ticket_row, $message, true);

        header('Location: ' . BASE_URL . '/tickets.php?id=' . $tk_id . '&new=1'); exit;
    }

    if ($action === 'reply_ticket') {
        $tk_id   = (int)($_POST['ticket_db_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        $tk = db()->prepare('SELECT * FROM tickets WHERE id=? AND user_id=? LIMIT 1');
        $tk->execute([$tk_id, $user['id']]);
        $ticket = $tk->fetch();
        if (!$ticket || strlen($message) < 1) { $err = 'Please write a message.'; goto RENDER; }

        if (in_array($ticket['status'], ['resolved','closed'])) {
            db()->prepare("UPDATE tickets SET status='open',updated_at=NOW() WHERE id=?")->execute([$tk_id]);
        } else {
            db()->prepare("UPDATE tickets SET status='waiting',updated_at=NOW() WHERE id=?")->execute([$tk_id]);
        }

        db()->prepare(
            'INSERT INTO ticket_replies (ticket_id,user_id,message,is_admin) VALUES (?,?,?,0)'
        )->execute([$tk_id, $user['id'], $message]);
        $reply_id = (int)db()->lastInsertId();

        // Handle attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            $upload_dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__ . '/uploads/tickets', '/') . '/';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
            foreach ($_FILES['attachments']['name'] as $i => $fname) {
                if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                $allow = ['jpg','jpeg','png','gif','pdf','txt','zip','doc','docx','xlsx','csv','log'];
                if (!in_array($ext, $allow)) continue;
                if ($_FILES['attachments']['size'][$i] > 10 * 1024 * 1024) continue; // 10MB max
                $safe  = preg_replace('/[^a-z0-9._-]/i', '_', $fname);
                $uname_file = time() . '_' . $i . '_' . $safe;
                $dest  = $upload_dir . $uname_file;
                if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $dest)) {
                    db()->prepare('INSERT INTO ticket_attachments (reply_id, filename, filepath, filesize) VALUES (?,?,?,?)')
                       ->execute([$reply_id, $fname, 'uploads/tickets/' . $uname_file, $_FILES['attachments']['size'][$i]]);
                }
            }
        }

        send_ticket_email_to_admin($user, $ticket, $message, false);

        header('Location: ' . BASE_URL . '/tickets.php?id=' . $tk_id . '#bottom'); exit;
    }

    if ($action === 'close_ticket') {
        $tk_id = (int)($_POST['ticket_db_id'] ?? 0);
        $tk = db()->prepare('SELECT id FROM tickets WHERE id=? AND user_id=? LIMIT 1');
        $tk->execute([$tk_id, $user['id']]);
        if ($tk->fetch()) {
            db()->prepare("UPDATE tickets SET status='closed',closed_at=NOW() WHERE id=?")->execute([$tk_id]);
        }
        header('Location: ' . BASE_URL . '/tickets.php?id=' . $tk_id); exit;
    }
}

RENDER:
// ── Data ───────────────────────────────────────────────────
$view_ticket    = null;
$ticket_replies = [];
if (isset($_GET['id'])) {
    $tk = db()->prepare('SELECT * FROM tickets WHERE id=? AND user_id=? LIMIT 1');
    $tk->execute([(int)$_GET['id'], $user['id']]);
    $view_ticket = $tk->fetch() ?: null;
    if ($view_ticket) {
        $rp = db()->prepare("SELECT r.*, u.full_name, u.username FROM ticket_replies r LEFT JOIN users u ON u.id=r.user_id WHERE r.ticket_id=? AND r.is_internal=0 ORDER BY r.created_at ASC");
        $rp->execute([$view_ticket['id']]);
        $ticket_replies = $rp->fetchAll() ?: [];
    }
}

$show_new = isset($_GET['new_ticket']);

$tickets_st = db()->prepare("SELECT t.*, (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id=t.id AND r.is_admin=1) as admin_replies FROM tickets t WHERE t.user_id=? ORDER BY t.updated_at DESC LIMIT 100");
$tickets_st->execute([$user['id']]);
$all_tickets = $tickets_st->fetchAll() ?: [];

$sc = ['open'=>0,'in_progress'=>0,'waiting'=>0,'resolved'=>0,'closed'=>0];
foreach ($all_tickets as $t) { if (isset($sc[$t['status']])) $sc[$t['status']]++; }
$total = count($all_tickets);
$answered = ($sc['in_progress'] + $sc['resolved']);
$open_count = $sc['open'] + $sc['waiting'];

// Status → display
function disp_status(string $s): array {
    return match($s) {
        'open'        => ['Open',               '#dc2626','#fef2f2'],
        'in_progress' => ['Answered',            '#2563eb','#eff6ff'],
        'waiting'     => ['Pending on customer', '#d97706','#fffbeb'],
        'resolved'    => ['Answered',            '#16a34a','#f0fdf4'],
        'closed'      => ['Closed',              '#6b7280','#f1f5f9'],
        default       => [ucfirst($s),           '#6b7280','#f1f5f9'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php inject_global_head(); ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Support Center — <?= APP_NAME ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/app.css">
  <style>
    /* ── Layout ───────────────── */
    .tk-shell{display:flex;height:100vh;overflow:hidden}
    .tk-sidebar{width:260px;background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
    .tk-main{flex:1;display:flex;flex-direction:column;overflow:hidden;background:#f8fafc}

    /* ── Thread layout ───────── */
    .thread-layout{display:flex;flex:1;overflow:hidden}
    .thread-list{width:380px;flex-shrink:0;border-right:1px solid var(--border);background:#fff;overflow-y:auto;display:flex;flex-direction:column}
    .thread-content{flex:1;display:flex;flex-direction:column;overflow:hidden}

    /* ── List header ─────────── */
    .tl-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;background:#fff;position:sticky;top:0;z-index:5}
    .tl-title{font-size:18px;font-weight:800;color:#0f172a}
    .tl-sub{font-size:12px;color:var(--gray-400);margin-top:1px}

    /* ── Stats ───────────────── */
    .tk-stats{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--border);flex-shrink:0}
    .tk-stat{padding:14px 16px;border-right:1px solid var(--border)}
    .tk-stat:last-child{border-right:none}
    .tk-stat-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--gray-400);margin-bottom:4px}
    .tk-stat-val{font-size:22px;font-weight:800;color:#0f172a}

    /* ── Search ──────────────── */
    .tl-search{padding:12px 14px;border-bottom:1px solid var(--border);flex-shrink:0}
    .tl-search input{width:100%;padding:7px 12px 7px 32px;background:#f1f5f9;border:1.5px solid transparent;border-radius:8px;font-size:13px;outline:none;transition:all .14s}
    .tl-search input:focus{background:#fff;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .tl-search-wrap{position:relative}
    .tl-search-wrap svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400)}

    /* ── Ticket row ──────────── */
    .tk-row{padding:14px 16px;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .1s;text-decoration:none;display:block;color:inherit}
    .tk-row:hover{background:#f8fafc}
    .tk-row.active{background:#eff6ff;border-left:3px solid var(--primary)}
    .tk-row-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:3px}
    .tk-row-id{font-size:12px;font-family:monospace;font-weight:700;color:var(--primary)}
    .tk-row-time{font-size:11px;color:var(--gray-400)}
    .tk-row-subject{font-size:13.5px;font-weight:700;color:#0f172a;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .tk-row-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .tk-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
    .tk-badge-dept{background:#f1f5f9;color:#475569}

    /* ── Ticket detail header ── */
    .td-header{background:#fff;border-bottom:1px solid var(--border);padding:16px 22px;flex-shrink:0}
    .td-breadcrumb{font-size:12px;color:var(--gray-400);margin-bottom:8px;display:flex;align-items:center;gap:5px}
    .td-breadcrumb a{color:var(--gray-400);text-decoration:none}
    .td-breadcrumb a:hover{color:var(--primary)}
    .td-title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
    .td-title{font-size:17px;font-weight:800;color:#0f172a;flex:1;min-width:0}
    .td-sub{font-size:12px;color:var(--gray-400);margin-top:4px}
    .td-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}

    /* ── Right panel detail ─── */
    .td-detail-panel{width:280px;flex-shrink:0;border-left:1px solid var(--border);background:#fff;overflow-y:auto;padding:18px}
    .dp-section{margin-bottom:20px}
    .dp-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--gray-400);margin-bottom:10px}
    .dp-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;font-size:13px}
    .dp-key{color:var(--gray-400)}
    .dp-val{font-weight:600;color:#0f172a;text-align:right;max-width:160px;word-break:break-word}

    /* ── Messages ─────────────── */
    .messages-area{flex:1;overflow-y:auto;padding:20px 22px}
    .msg-wrap{display:flex;gap:12px;margin-bottom:18px}
    .msg-avatar{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0}
    .msg-avatar.staff{background:#1a1a2e;color:#fff}
    .msg-avatar.user{}
    .msg-bubble{flex:1;min-width:0}
    .msg-meta{font-size:11.5px;color:var(--gray-400);margin-bottom:5px;display:flex;align-items:center;gap:8px}
    .msg-name{font-weight:700;color:#0f172a;font-size:13px}
    .msg-staff-tag{background:#1a1a2e;color:#fff;padding:1px 6px;border-radius:99px;font-size:9.5px;font-weight:700;text-transform:uppercase}
    .msg-body{background:#fff;border:1px solid var(--border);border-radius:0 10px 10px 10px;padding:13px 16px;font-size:13.5px;color:#374151;line-height:1.7;white-space:pre-wrap;word-break:break-word}
    .msg-body.staff{background:#f8faff;border-color:#bfdbfe}

    /* ── Reply editor ─────────── */
    .reply-panel{border-top:1px solid var(--border);background:#fff;flex-shrink:0}
    .reply-toolbar{display:flex;gap:2px;padding:8px 14px 6px;flex-wrap:wrap;align-items:center;border-bottom:1px solid #f0f4f8}
    .rte-btn{width:28px;height:28px;border:none;background:transparent;cursor:pointer;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;color:#64748b;font-size:13px;font-weight:700;transition:background .1s;flex-shrink:0}
    .rte-btn:hover{background:#f1f5f9;color:#0f172a}
    .rte-btn.sep{width:1px;height:18px;background:#e2e8f0;margin:0 3px;cursor:default;pointer-events:none}
    .rte-editor{min-height:100px;max-height:220px;overflow-y:auto;padding:13px 16px;font-size:13.5px;color:#374151;line-height:1.75;outline:none;background:#fff}
    .rte-editor:empty::before{content:attr(data-placeholder);color:#94a3b8;pointer-events:none}
    .rte-editor blockquote{border-left:3px solid #e2e8f0;margin:6px 0;padding:4px 12px;color:#64748b}
    .rte-editor pre{background:#f1f5f9;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;overflow-x:auto}
    .rte-editor a{color:var(--primary);text-decoration:underline}
    .reply-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;border-top:1px solid #f0f4f8;background:#fff}
    /* Attachment chip */
    .attach-chip{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:12px;color:#374151;max-width:160px}
    .attach-chip span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .attach-chip button{border:none;background:none;cursor:pointer;color:#94a3b8;font-size:14px;line-height:1;padding:0;flex-shrink:0}
    .attach-chip button:hover{color:#dc2626}
    .char-count{font-size:12px;color:var(--gray-400)}

    /* ── New ticket form ─────── */
    .ntk-wrap{flex:1;overflow-y:auto;padding:28px;max-width:680px;margin:0 auto;width:100%}
    .ntk-title{font-size:22px;font-weight:900;color:#0f172a;margin-bottom:4px}
    .ntk-sub{font-size:13.5px;color:var(--gray-500);margin-bottom:24px}

    /* ── Empty state ─────────── */
    .empty-ticket{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--gray-400);gap:10px;padding:40px}

    /* ── Mobile ───────────────── */
    @media(max-width:768px) {
      /* Full width stacked layout */
      .thread-layout{flex-direction:column;height:auto;overflow:visible}
      .thread-list{
        display:<?= isset($_GET['id']) ? 'none' : 'flex' ?>;
        width:100%;border-right:none;border-bottom:1px solid var(--border);
        max-height:none;overflow:visible;
      }
      .thread-content{
        /*display:<?= isset($_GET['id']) ? 'flex' : 'none' ?>;*/
        width:100%;flex-direction:column;height:auto;overflow:visible;
      }
      .td-detail-panel{display:none}

      /* Stats 2x2 grid */
      .tk-stats{grid-template-columns:1fr 1fr}
      .tk-stat:nth-child(2){border-right:none}
      .tk-stat:nth-child(1),.tk-stat:nth-child(2){border-bottom:1px solid var(--border)}
      .tk-stat-val{font-size:18px}
      .tk-stat{padding:10px 13px}

      /* Messages scroll naturally */
      .messages-area{overflow-y:visible;max-height:none;padding:14px 14px 6px}

      /* Ticket detail header */
      .td-header{padding:12px 14px}
      .td-title{font-size:15px}
      .td-pills{gap:4px;margin-top:5px}
      .td-title-row{flex-direction:column;gap:8px;align-items:flex-start}
      .td-title-row form{width:100%}
      .td-title-row form button{width:100%;justify-content:center}
      .td-breadcrumb{font-size:11.5px;margin-bottom:5px}

      /* New ticket form */
      .ntk-wrap{padding:16px 14px;max-width:100%}
      .ntk-title{font-size:18px}

      /* Reply panel */
      .reply-panel{position:relative;flex-shrink:0}
      .reply-toolbar{
        overflow-x:auto;-webkit-overflow-scrolling:touch;
        scrollbar-width:none;flex-wrap:nowrap;padding:6px 10px 5px;gap:1px;
      }
      .reply-toolbar::-webkit-scrollbar{display:none}
      .rte-btn{width:30px;height:30px;flex-shrink:0}
      .rte-editor{min-height:90px;max-height:160px;padding:10px 12px;font-size:14px}
      .reply-footer{flex-wrap:wrap;gap:8px;padding:8px 12px}
      .reply-footer > div{flex:1;min-width:0}
      .reply-footer > button,.reply-footer .btn{width:100%;justify-content:center}

      /* Ticket list rows */
      .tk-row{padding:12px 14px}
      .tl-header{padding:14px 14px}
      .tl-title{font-size:16px}
      .tl-search{padding:10px 12px}

      /* Messages */
      .msg-wrap{gap:9px;margin-bottom:14px}
      .msg-avatar{width:30px;height:30px;font-size:11px;border-radius:7px}
      .msg-body{padding:10px 12px;font-size:13px}
      .msg-meta{font-size:11px}
      .msg-name{font-size:12.5px}
    }

    @media(max-width:480px){
      .tk-stat-val{font-size:16px}
      .ntk-title{font-size:17px}
      .msg-body{font-size:13px;padding:9px 11px}
      .rte-btn.sep{display:none}
    }
  <style>
    .reply-send-btn{transition:opacity .15s}
    @media(max-width:768px){.reply-send-btn{width:100%;justify-content:center;padding:11px 16px !important;font-size:14px !important}}
    /* Mobile grid fix for new ticket form */
    .ntk-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media(max-width:600px){.ntk-grid-2{grid-template-columns:1fr !important}}

    /* ── NTK RTE editor ─────────────────────────────── */
    .ntk-editor-wrap{border:1.5px solid var(--border);border-radius:10px;overflow:hidden;background:#fff;transition:border-color .14s}
    .ntk-editor-wrap:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
    .ntk-toolbar{display:flex;gap:1px;padding:7px 10px 6px;flex-wrap:nowrap;overflow-x:auto;border-bottom:1px solid #f0f4f8;scrollbar-width:none;background:#fafafa}
    .ntk-toolbar::-webkit-scrollbar{display:none}
    .ntk-rte{min-height:140px;max-height:280px;overflow-y:auto;padding:12px 14px;font-size:13.5px;color:#374151;line-height:1.75;outline:none;background:#fff}
    .ntk-rte:empty::before{content:attr(data-placeholder);color:#94a3b8;pointer-events:none}
    .ntk-rte blockquote{border-left:3px solid #e2e8f0;margin:6px 0;padding:4px 12px;color:#64748b}
    .ntk-rte pre{background:#f1f5f9;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:12px;overflow-x:auto}
    .ntk-attach-preview{display:flex;flex-wrap:wrap;gap:6px;padding:0;max-height:0;overflow:hidden;transition:all .2s;border-top:0 solid #f1f5f9}
    .ntk-attach-preview.has-files{padding:8px 12px;max-height:200px;border-top-width:1px}
    .ntk-editor-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-top:1px solid #f0f4f8;background:#fafafa;flex-wrap:wrap;gap:8px}
    .ntk-attach-lbl{display:flex;align-items:center;gap:5px;cursor:pointer;font-size:13px;color:#64748b;font-weight:600;padding:5px 9px;border-radius:6px;transition:background .12s}
    .ntk-attach-lbl:hover{background:#f1f5f9;color:#374151}

    /* ── MODAL overlay (mobile new ticket) ─────────── */
    .tk-modal-overlay{
      display:none;position:fixed;inset:0;z-index:1000;
      background:rgba(0,0,0,.45);backdrop-filter:blur(3px);
      align-items:flex-end;justify-content:center;
    }
    .tk-modal-overlay.open{display:flex}
    .tk-modal{
      background:#fff;width:100%;max-width:520px;
      border-radius:20px 20px 0 0;
      padding:0 0 env(safe-area-inset-bottom,0);
      max-height:92vh;overflow-y:auto;
      animation:slideUp .25s cubic-bezier(.25,.46,.45,.94);
    }
    @keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
    .tk-modal-handle{width:40px;height:4px;background:#e2e8f0;border-radius:99px;margin:12px auto 0}
    .tk-modal-head{
      padding:16px 18px 12px;border-bottom:1px solid var(--border);
      display:flex;align-items:center;justify-content:space-between;
      position:sticky;top:0;background:#fff;z-index:2;
    }
    .tk-modal-title{font-size:16px;font-weight:800;color:#0f172a}
    .tk-modal-close{
      width:30px;height:30px;border:none;background:#f1f5f9;
      border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;
      color:#64748b;font-size:18px;line-height:1;transition:background .13s;
    }
    .tk-modal-close:hover{background:#e2e8f0;color:#0f172a}
    .tk-modal-body{padding:16px 18px 20px}
    /* Inside modal — RTE compact */
    .tk-modal .ntk-rte{min-height:100px;max-height:180px}
    .tk-modal .ntk-editor-footer{flex-wrap:wrap;gap:8px}
    .tk-modal .ntk-editor-footer > div:last-child{width:100%}
    .tk-modal .ntk-editor-footer .btn{width:100%;justify-content:center;padding:11px}
    .tk-modal .ntk-grid-2{grid-template-columns:1fr 1fr}
    /* Show modal trigger only on mobile */
    .ntk-modal-trigger{display:none}
    @media(max-width:768px){
      .ntk-modal-trigger{display:flex}
    }
    @media(min-width:769px){
      .tk-modal-overlay{display:none !important}
    }
  </style>
</head>
<body>
<div class="app-shell">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content" style="overflow:hidden;padding:0">

    <!-- Mobile bar -->
    <div class="mobile-bar">
      <button class="ham-btn" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlay').classList.toggle('open')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <span style="font-weight:800;font-size:14px">Support Center</span>
    </div>

    <div class="thread-layout" style="height:calc(100vh - 0px);overflow:hidden">

      <!-- ══ LEFT — Ticket List ════════════════════════════ -->
      <div class="thread-list">

        <div class="tl-header">
          <div>
            <div class="tl-title">Support Center</div>
            <div class="tl-sub">Get help from our support team</div>
          </div>
          <a href="<?= BASE_URL ?>/tickets.php?new_ticket=1" class="btn btn-primary btn-sm">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Open Ticket
          </a>
        </div>

        <!-- Stats -->
        <div class="tk-stats">
          <div class="tk-stat">
            <div class="tk-stat-label">Total</div>
            <div class="tk-stat-val"><?= $total ?></div>
          </div>
          <div class="tk-stat">
            <div class="tk-stat-label">Open</div>
            <div class="tk-stat-val"><?= $open_count ?></div>
          </div>
          <div class="tk-stat">
            <div class="tk-stat-label">Answered</div>
            <div class="tk-stat-val"><?= $answered ?></div>
          </div>
          <div class="tk-stat">
            <div class="tk-stat-label">Closed</div>
            <div class="tk-stat-val"><?= $sc['closed'] ?></div>
          </div>
        </div>

        <!-- Search -->
        <div class="tl-search">
          <div class="tl-search-wrap">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" placeholder="Search tickets..." id="tk-search" oninput="filterTickets(this.value)">
          </div>
        </div>

        <!-- Ticket list -->
        <div id="tk-list" style="flex:1;overflow-y:auto">
          <?php if (empty($all_tickets)): ?>
          <div style="padding:40px 20px;text-align:center;color:var(--gray-400)">
            <div style="font-size:32px;margin-bottom:10px">🎫</div>
            <div style="font-size:13.5px;font-weight:700;margin-bottom:6px">No tickets yet</div>
            <div style="font-size:12.5px">Click "Open Ticket" to get started</div>
          </div>
          <?php else: ?>
          <?php foreach ($all_tickets as $t):
            [$slabel, $scolor, $sbg] = disp_status($t['status']);
            $is_active = isset($view_ticket) && $view_ticket['id'] === $t['id'];
          ?>
          <a class="tk-row <?= $is_active?'active':'' ?>" href="<?= BASE_URL ?>/tickets.php?id=<?= $t['id'] ?>">
            <div class="tk-row-head">
              <span class="tk-row-id">#<?= htmlspecialchars($t['ticket_id']) ?></span>
              <span class="tk-row-time"><?= date('d M, H:i', strtotime($t['updated_at'])) ?></span>
            </div>
            <div class="tk-row-subject"><?= htmlspecialchars($t['subject']) ?></div>
            <div class="tk-row-meta">
              <span class="tk-badge tk-badge-dept"><?= ticket_dept_label($t['department']) ?></span>
              <span class="tk-badge" style="background:<?= $sbg ?>;color:<?= $scolor ?>">
                <span style="width:5px;height:5px;border-radius:50%;background:<?= $scolor ?>;display:inline-block"></span>
                <?= $slabel ?>
              </span>
              <?php if ($t['admin_replies'] > 0 && $t['status'] !== 'closed'): ?>
              <span style="font-size:10px;color:var(--primary);font-weight:700">Staff replied</span>
              <?php endif; ?>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ══ RIGHT — Content ═══════════════════════════════ -->
      <div class="thread-content">

        <?php if ($err): ?>
        <div style="margin:16px 22px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#dc2626;font-size:13px"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <?php if ($show_new || (!$view_ticket && !isset($_GET['id']))): ?>
        <!-- ── NEW TICKET FORM ─────────────────────────── -->
        <div class="ntk-wrap">
          <?php if (isset($_GET['new_ticket'])): ?>
          <div class="ntk-title">Open Support Ticket</div>
          <div class="ntk-sub">Describe your issue and our team will respond shortly.</div>

          <div class="list-card" style="padding:24px">
            <form method="POST" id="ntk-form" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="create_ticket">
              <input type="hidden" name="message" id="ntk-msg-hidden">

              <div class="form-group">
                <label class="flabel">Subject <span style="color:var(--danger)">*</span></label>
                <input type="text" name="subject" class="form-control" required maxlength="200"
                       placeholder="Brief description of your issue" autofocus>
              </div>

              <div class="ntk-grid-2">
                <div class="form-group">
                  <label class="flabel">Department</label>
                  <select name="department" class="form-control">
                    <option value="technical">Cloud Support</option>
                    <option value="billing">Cloud Billing</option>
                    <option value="sales">Cloud Sales</option>
                    <option value="abuse">Abuse</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="flabel">Priority</label>
                  <select name="priority" class="form-control">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                  </select>
                </div>
              </div>

              <!-- RTE Message -->
              <div class="form-group">
                <label class="flabel">Message <span style="color:var(--danger)">*</span></label>
                <div class="ntk-editor-wrap">
                  <!-- Toolbar -->
                  <div class="ntk-toolbar">
                    <button type="button" onclick="ntkFmt('bold')"          class="rte-btn" title="Bold"><b>B</b></button>
                    <button type="button" onclick="ntkFmt('italic')"        class="rte-btn" title="Italic"><i style="font-style:italic">I</i></button>
                    <button type="button" onclick="ntkFmt('underline')"     class="rte-btn" title="Underline"><u>U</u></button>
                    <button type="button" onclick="ntkFmt('strikeThrough')" class="rte-btn" title="Strikethrough"><s>S</s></button>
                    <div class="rte-btn sep"></div>
                    <button type="button" onclick="ntkFmt('insertUnorderedList')" class="rte-btn" title="Bullet list">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/></svg>
                    </button>
                    <button type="button" onclick="ntkFmt('insertOrderedList')" class="rte-btn" title="Numbered list">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                    </button>
                    <div class="rte-btn sep"></div>
                    <button type="button" onclick="ntkFmt('formatBlock','pre')" class="rte-btn" title="Code block">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                    </button>
                    <button type="button" onclick="ntkFmt('formatBlock','blockquote')" class="rte-btn" title="Quote">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1zm12 0c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>
                    </button>
                  </div>
                  <!-- Editor area -->
                  <div id="ntk-rte" class="ntk-rte" contenteditable="true"
                       data-placeholder="Describe your issue in detail..." spellcheck="true"></div>
                  <!-- Attachment preview -->
                  <div id="ntk-attach-preview" class="ntk-attach-preview"></div>
                  <!-- Footer: attach + submit -->
                  <div class="ntk-editor-footer">
                    <div style="display:flex;align-items:center;gap:10px">
                      <label for="ntk-attach-input" class="ntk-attach-lbl" title="Attach files">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        Attach
                      </label>
                      <input type="file" id="ntk-attach-input" name="attachments[]" multiple
                             accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx,.xlsx,.csv,.log"
                             style="display:none" onchange="ntkHandleAttach(this)">
                      <span id="ntk-char-cnt" style="font-size:12px;color:var(--gray-400)">0 chars</span>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                      <a href="<?= BASE_URL ?>/tickets.php" class="btn btn-ghost btn-sm">Cancel</a>
                      <button type="button" onclick="ntkSubmit()" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Submit Ticket
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
          <?php else: ?>
          <div class="empty-ticket">
            <div style="font-size:48px">💬</div>
            <div style="font-weight:700;font-size:16px;color:#374151">Select a ticket to view</div>
            <div style="font-size:13px">Or open a new support ticket</div>
            <a href="<?= BASE_URL ?>/tickets.php?new_ticket=1" class="btn btn-primary">
              + Open New Ticket
            </a>
          </div>
          <?php endif; ?>
        </div>

        <?php elseif ($view_ticket): ?>
        <?php
          [$slabel, $scolor, $sbg] = disp_status($view_ticket['status']);
          $is_closed = in_array($view_ticket['status'], ['closed']);
        ?>

        <!-- ── TICKET DETAIL ──────────────────────────── -->
        <div style="display:flex;flex:1;overflow:hidden">
          <div style="flex:1;display:flex;flex-direction:column;overflow:hidden">

            <!-- Header -->
            <div class="td-header">
              <div class="td-breadcrumb">
                <a href="<?= BASE_URL ?>/tickets.php">Support</a>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                <a href="<?= BASE_URL ?>/tickets.php">Tickets</a>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                <span>#<?= htmlspecialchars($view_ticket['ticket_id']) ?></span>
              </div>
              <div class="td-title-row">
                <div>
                  <div class="td-title">#<?= htmlspecialchars($view_ticket['ticket_id']) ?></div>
                  <div class="td-sub"><?= htmlspecialchars($view_ticket['subject']) ?></div>
                  <div class="td-pills">
                    <span class="tk-badge" style="background:<?= $sbg ?>;color:<?= $scolor ?>;font-size:12px;padding:3px 10px">
                      <span style="width:6px;height:6px;border-radius:50%;background:<?= $scolor ?>;display:inline-block"></span>
                      <?= $slabel ?>
                    </span>
                    <span class="tk-badge" style="background:#f1f5f9;color:#475569;font-size:12px;padding:3px 10px"><?= ticket_dept_label($view_ticket['department']) ?></span>
                    <span class="tk-badge" style="background:#f1f5f9;color:#64748b;font-size:12px;padding:3px 10px"><?= ucfirst($view_ticket['priority']) ?> Priority</span>
                  </div>
                </div>
                <?php if (!$is_closed): ?>
                <form method="POST" style="margin:0;flex-shrink:0">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="close_ticket">
                  <input type="hidden" name="ticket_db_id" value="<?= $view_ticket['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);border-color:#fca5a5;font-size:12px"
                          onclick="return confirm('Close this ticket?')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Close Ticket
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Messages -->
            <div class="messages-area" id="messages-area">
              <?php if (!empty($_GET['new'])): ?>
              <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#15803d;display:flex;align-items:center;gap:8px">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Ticket <strong>#<?= htmlspecialchars($view_ticket['ticket_id']) ?></strong> created! Our team will respond soon.
              </div>
              <?php endif; ?>

              <?php foreach ($ticket_replies as $rp):
                $is_staff = (int)$rp['is_admin'];
                $rp_name  = $is_staff ? (APP_NAME . ' Support') : ($rp['full_name'] ?: $rp['username']);
                $time_fmt = date('d M Y', strtotime($rp['created_at'])) === date('d M Y') 
                    ? 'Today at ' . date('g:ia', strtotime($rp['created_at']))
                    : date('d M Y, g:ia', strtotime($rp['created_at']));
                // Get attachments for this reply
                $atts_st = db()->prepare('SELECT * FROM ticket_attachments WHERE reply_id=? ORDER BY id');
                $atts_st->execute([$rp['id']]);
                $atts = $atts_st->fetchAll() ?: [];
              ?>
              <div class="msg-wrap" <?= $is_staff ? 'style="padding-left:46px"' : '' ?>>
                <?php if (!$is_staff): ?>
                <div class="msg-avatar user"><img style="border-radius: 5px;" src="<?= getGravatar($user['email'], $user['user_profile']) ?>"></div>
                <?php else: ?>
                <div style="width:34px;height:34px;border-radius:9px;background:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">⚡</div>
                <?php endif; ?>
                <div class="msg-bubble">
                  <div class="msg-meta">
                    <span class="msg-name"><?= htmlspecialchars($rp_name) ?></span>
                    <?php if ($is_staff): ?>
                    <span class="msg-staff-tag">Staff</span>
                    <?php endif; ?>
                    <span><?= $time_fmt ?></span>
                  </div>
                  <div class="msg-body <?= $is_staff ? 'staff' : '' ?>"><?= nl2br(htmlspecialchars($rp['message'])) ?></div>
                  <?php if (!empty($atts)): ?>
                  <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">
                    <?php foreach ($atts as $att):
                      $ext  = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                      $icon = in_array($ext, ['jpg','jpeg','png','gif']) ? '🖼️' : ($ext==='pdf'?'📄':($ext==='zip'?'🗜️':'📎'));
                      $size = $att['filesize'] < 1024 ? $att['filesize'].'B' : ($att['filesize'] < 1048576 ? round($att['filesize']/1024).'KB' : round($att['filesize']/1048576,1).'MB');
                    ?>
                    <a href="<?= BASE_URL . '/' . htmlspecialchars($att['filepath']) ?>" target="_blank" download="<?= htmlspecialchars($att['filename']) ?>"
                       style="display:inline-flex;align-items:center;gap:5px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:5px 10px;font-size:12px;color:#374151;text-decoration:none;transition:border-color .12s"
                       onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='#e2e8f0'">
                      <span><?= $icon ?></span>
                      <span style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($att['filename']) ?></span>
                      <span style="color:#94a3b8;font-size:10.5px"><?= $size ?></span>
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </a>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
              <div id="bottom" style="height:1px"></div>
            </div>

            <!-- Reply Panel -->
            <?php if (!$is_closed): ?>
            <div class="reply-panel">
              <form method="POST" id="reply-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="reply_ticket">
                <input type="hidden" name="ticket_db_id" value="<?= $view_ticket['id'] ?>">
                <input type="hidden" name="message" id="reply-hidden">

                <!-- Reply label row -->
                <div style="padding:10px 16px 0;display:flex;align-items:center;gap:6px">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                  <span style="font-size:13px;font-weight:700;color:#374151">Reply</span>
                </div>

                <!-- Toolbar -->
                <div class="reply-toolbar">
                  <button type="button" onclick="fmt('bold')"          class="rte-btn" title="Bold"><b>B</b></button>
                  <button type="button" onclick="fmt('italic')"        class="rte-btn" title="Italic"><i style="font-style:italic">I</i></button>
                  <button type="button" onclick="fmt('underline')"     class="rte-btn" title="Underline"><u>U</u></button>
                  <button type="button" onclick="fmt('strikeThrough')" class="rte-btn" title="Strikethrough"><s>S</s></button>
                  <div class="rte-btn sep"></div>
                  <button type="button" onclick="fmt('insertUnorderedList')" class="rte-btn" title="Bullet list">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4" cy="6" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1.5" fill="currentColor" stroke="none"/></svg>
                  </button>
                  <button type="button" onclick="fmt('insertOrderedList')" class="rte-btn" title="Numbered list">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                  </button>
                  <div class="rte-btn sep"></div>
                  <button type="button" onclick="fmt('formatBlock','pre')" class="rte-btn" title="Code block">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                  </button>
                  <button type="button" onclick="fmt('formatBlock','blockquote')" class="rte-btn" title="Quote">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1zm12 0c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/></svg>
                  </button>
                  <button type="button" onclick="insertLink()" class="rte-btn" title="Insert link">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                  </button>
                  <div class="rte-btn sep"></div>
                  <button type="button" onclick="document.execCommand('undo')" class="rte-btn" title="Undo">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
                  </button>
                  <button type="button" onclick="document.execCommand('redo')" class="rte-btn" title="Redo">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.49-4.86"/></svg>
                  </button>
                </div>

                <!-- Editor -->
                <div id="rte" class="rte-editor" contenteditable="true"
                     data-placeholder="Write your reply..." spellcheck="true"
                     oninput="updateChar()"></div>

                <!-- Attachment preview area -->
                <div id="attach-preview" style="display:none;padding:8px 16px;border-top:1px solid #f1f5f9;display:flex;flex-wrap:wrap;gap:6px;min-height:0"></div>

                <!-- Footer: Attach + char count + Send -->
                <div class="reply-footer">
                  <div style="display:flex;align-items:center;gap:12px">
                    <!-- Attach button -->
                    <label for="attach-input" style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:13px;color:#64748b;font-weight:600;padding:5px 10px;border-radius:6px;transition:background .12s" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                      Attach
                    </label>
                    <input type="file" id="attach-input" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.txt,.zip,.doc,.docx,.xlsx,.csv,.log" style="display:none" onchange="handleAttach(this)">
                    <!-- Char count -->
                    <span id="char-cnt" style="font-size:12px;color:var(--gray-400)">0 chars</span>
                  </div>
                  <button type="button" onclick="submitReply()" class="btn btn-primary reply-send-btn" style="background:#1a1a2e;border-color:#1a1a2e;font-size:13.5px;padding:8px 20px;border-radius:8px;font-weight:700">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send Reply
                  </button>
                </div>
              </form>
            </div>
            <?php else: ?>
            <div style="padding:14px 22px;background:#f8fafc;border-top:1px solid var(--border);text-align:center;font-size:13px;color:var(--gray-400)">
              This ticket is closed. <a href="<?= BASE_URL ?>/tickets.php?new_ticket=1" style="color:var(--primary);font-weight:600">Open a new ticket</a> for further help.
            </div>
            <?php endif; ?>
          </div>

          <!-- Right detail panel -->
          <div class="td-detail-panel">
            <div class="dp-section">
              <div class="dp-label">Details</div>
              <div class="dp-row"><span class="dp-key">Ticket ID</span><span class="dp-val" style="font-family:monospace;color:var(--primary)">#<?= htmlspecialchars($view_ticket['ticket_id']) ?></span></div>
              <div class="dp-row"><span class="dp-key">Status</span>
                <span class="tk-badge" style="background:<?= $sbg ?>;color:<?= $scolor ?>;font-size:11px">
                  <span style="width:5px;height:5px;border-radius:50%;background:<?= $scolor ?>;display:inline-block"></span>
                  <?= $slabel ?>
                </span>
              </div>
              <div class="dp-row"><span class="dp-key">Department</span><span class="dp-val"><?= ticket_dept_label($view_ticket['department']) ?></span></div>
              <div class="dp-row"><span class="dp-key">Priority</span><span class="dp-val"><?= ucfirst($view_ticket['priority']) ?></span></div>
              <div class="dp-row"><span class="dp-key">Created</span><span class="dp-val"><?= date('Y-m-d H:i:s', strtotime($view_ticket['created_at'])) ?></span></div>
              <div class="dp-row"><span class="dp-key">Last Activity</span><span class="dp-val"><?= date('d M, H:i', strtotime($view_ticket['updated_at'])) ?></span></div>
              <div class="dp-row"><span class="dp-key">Replies</span><span class="dp-val"><?= count($ticket_replies) ?></span></div>
            </div>
            <div class="dp-section">
              <div class="dp-label">Requester</div>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:32px;height:32px;background:var(--primary);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px"><img style="border-radius: 5px;" src="<?= getGravatar($user['email'], $user['user_profile']) ?>"></div>
                <div>
                  <div style="font-size:13px;font-weight:700;color:#0f172a"><?= htmlspecialchars($fname) ?></div>
                  <div style="font-size:11.5px;color:var(--gray-400)"><?= htmlspecialchars($user['email']) ?></div>
                </div>
              </div>
            </div>
            <?php if (!$is_closed): ?>
            <div class="dp-section">
              <div class="dp-label">Actions</div>
              <a href="#rte" onclick="document.getElementById('rte').focus()" style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;padding:8px 10px;border-radius:7px;text-decoration:none;margin-bottom:4px" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                Write Reply
              </a>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="close_ticket">
                <input type="hidden" name="ticket_db_id" value="<?= $view_ticket['id'] ?>">
                <button type="submit" style="display:flex;align-items:center;gap:8px;font-size:13px;color:#dc2626;padding:8px 10px;border-radius:7px;background:transparent;border:none;cursor:pointer;width:100%;text-align:left"
                        onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background=''"
                        onclick="return confirm('Close this ticket?')">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                  Close Ticket
                </button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="overlay" id="overlay" onclick="document.getElementById('sidebar').classList.remove('open');this.classList.remove('open')"></div>

<script>
// ── RTE ───────────────────────────────────────────────────
function fmt(cmd, val) {
  var rte = document.getElementById('rte');
  rte.focus();
  document.execCommand(cmd, false, val || null);
  updateChar();
}

function insertLink() {
  var url = prompt('Enter URL (https://...):');
  if (url) {
    if (!url.startsWith('http')) url = 'https://' + url;
    document.getElementById('rte').focus();
    document.execCommand('createLink', false, url);
  }
}

function updateChar() {
  var txt = document.getElementById('rte').innerText || '';
  document.getElementById('char-cnt').textContent = txt.length + ' chars';
}

function submitReply() {
  var rte = document.getElementById('rte');
  var txt = (rte.innerText || '').trim();
  if (!txt) { rte.focus(); rte.style.boxShadow='0 0 0 2px #dc2626'; setTimeout(()=>rte.style.boxShadow='',1500); return; }
  document.getElementById('reply-hidden').value = txt;
  var btn = document.querySelector('#reply-form button[type=button][onclick*=submitReply]');
  if (btn) { btn.disabled = true; btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .7s linear infinite"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg> Sending...'; }
  document.getElementById('reply-form').submit();
}

// ── Attachments ───────────────────────────────────────────
var pendingFiles = []; // DataTransfer approach for multi-file

function handleAttach(input) {
  var preview = document.getElementById('attach-preview');
  preview.style.display = 'flex';

  Array.from(input.files).forEach(function(file, i) {
    // Size check 10MB
    if (file.size > 10 * 1024 * 1024) {
      alert(file.name + ' is too large (max 10MB)');
      return;
    }
    pendingFiles.push(file);
    var idx = pendingFiles.length - 1;
    var ext = file.name.split('.').pop().toLowerCase();
    var icon = ['jpg','jpeg','png','gif'].includes(ext) ? '🖼️' : ['pdf'].includes(ext) ? '📄' : ['zip'].includes(ext) ? '🗜️' : '📎';
    var size = file.size < 1024 ? file.size + 'B' : file.size < 1048576 ? Math.round(file.size/1024) + 'KB' : (file.size/1048576).toFixed(1) + 'MB';
    var chip = document.createElement('div');
    chip.className = 'attach-chip';
    chip.id = 'chip-' + idx;
    chip.innerHTML = icon + ' <span title="' + file.name + '">' + file.name + '</span> <span style="color:var(--gray-400);font-size:10px">(' + size + ')</span> <button type="button" onclick="removeAttach(' + idx + ')" title="Remove">✕</button>';
    preview.appendChild(chip);
  });

  // Update file input with all pending files via DataTransfer
  syncFileInput();
}

function removeAttach(idx) {
  pendingFiles[idx] = null; // null = removed
  var chip = document.getElementById('chip-' + idx);
  if (chip) chip.remove();
  syncFileInput();
  var preview = document.getElementById('attach-preview');
  if (!preview.querySelector('.attach-chip')) preview.style.display = 'none';
}

function syncFileInput() {
  var dt = new DataTransfer();
  pendingFiles.forEach(function(f) { if (f) dt.items.add(f); });
  document.getElementById('attach-input').files = dt.files;
}

// Drag & drop on editor
var rteEl = document.getElementById('rte');
if (rteEl) {
  rteEl.addEventListener('dragover', function(e) { e.preventDefault(); this.style.background='#f0f9ff'; });
  rteEl.addEventListener('dragleave', function()  { this.style.background=''; });
  rteEl.addEventListener('drop', function(e) {
    e.preventDefault(); this.style.background='';
    var files = e.dataTransfer.files;
    if (files.length) {
      var inp = document.getElementById('attach-input');
      var dt  = new DataTransfer();
      // Existing files
      Array.from(inp.files).forEach(function(f) { dt.items.add(f); });
      // New dropped files
      Array.from(files).forEach(function(f) { dt.items.add(f); });
      inp.files = dt.files;
      handleAttach(inp);
    }
  });
}

// Paste images into editor
if (rteEl) {
  rteEl.addEventListener('paste', function(e) {
    var items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (var i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image') === 0) {
        e.preventDefault();
        var file = items[i].getAsFile();
        if (!file) continue;
        // Add to attachments
        pendingFiles.push(file);
        var idx = pendingFiles.length - 1;
        var preview = document.getElementById('attach-preview');
        preview.style.display = 'flex';
        var chip = document.createElement('div');
        chip.className = 'attach-chip';
        chip.id = 'chip-' + idx;
        chip.innerHTML = '🖼️ <span>pasted-image-' + idx + '.png</span> <button type="button" onclick="removeAttach(' + idx + ')">✕</button>';
        preview.appendChild(chip);
        syncFileInput();
      }
    }
  });
}

// ── Search ────────────────────────────────────────────────
function filterTickets(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.tk-row').forEach(function(row) {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ── Auto scroll ───────────────────────────────────────────
var ma = document.getElementById('messages-area');
if (ma) ma.scrollTop = ma.scrollHeight;

// ── NTK (New Ticket) RTE ──────────────────────────────────
function ntkFmt(cmd, val) {
  document.getElementById('ntk-rte').focus();
  document.execCommand(cmd, false, val || null);
  ntkUpdateChar();
}
function ntkUpdateChar() {
  var rte = document.getElementById('ntk-rte');
  if (!rte) return;
  var cnt = document.getElementById('ntk-char-cnt');
  if (cnt) cnt.textContent = rte.innerText.trim().length + ' chars';
}
document.addEventListener('DOMContentLoaded', function() {
  var r = document.getElementById('ntk-rte');
  if (r) r.addEventListener('input', ntkUpdateChar);
});

function ntkHandleAttach(inp) {
  var preview = document.getElementById('ntk-attach-preview');
  if (!preview) return;
  Array.from(inp.files).forEach(function(f) {
    var chip = document.createElement('span');
    chip.className = 'attach-chip';
    var icon = /\.(jpg|jpeg|png|gif)$/i.test(f.name) ? '🖼️' : /\.pdf$/i.test(f.name) ? '📄' : '📎';
    chip.innerHTML = icon + ' <span>' + f.name + '</span> <button type="button" onclick="this.parentNode.remove();ntkSyncAttach()">×</button>';
    preview.appendChild(chip);
  });
  if (preview.children.length) preview.classList.add('has-files');
}
function ntkSyncAttach() {
  var preview = document.getElementById('ntk-attach-preview');
  if (preview && !preview.querySelector('.attach-chip')) preview.classList.remove('has-files');
}

function ntkSubmit() {
  // Works for both desktop form and modal form
  var forms = [document.getElementById('ntk-form'), document.getElementById('ntk-modal-form')];
  var rteIds = ['ntk-rte', 'ntk-modal-rte'];
  var hidIds = ['ntk-msg-hidden', 'ntk-modal-msg-hidden'];
  forms.forEach(function(form, i) {
    if (!form) return;
    var rte = document.getElementById(rteIds[i]);
    var hid = document.getElementById(hidIds[i]);
    if (!rte || !hid) return;
    var text = rte.innerHTML.trim();
    if (!text || rte.innerText.trim().length < 5) {
      alert('Please describe your issue (min 5 chars).');
      rte.focus(); return;
    }
    hid.value = rte.innerText; // store plain text
    form.submit();
  });
}

// ── Modal open/close ──────────────────────────────────────
function openTkModal() {
  document.getElementById('tk-modal-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ var el=document.getElementById('ntk-modal-subject'); if(el) el.focus(); }, 300);
}
function closeTkModal() {
  document.getElementById('tk-modal-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
// Close on backdrop click
document.addEventListener('DOMContentLoaded', function() {
  var ov = document.getElementById('tk-modal-overlay');
  if (ov) ov.addEventListener('click', function(e){ if(e.target===ov) closeTkModal(); });
});

function ntkModalFmt(cmd, val) {
  document.getElementById('ntk-modal-rte').focus();
  document.execCommand(cmd, false, val || null);
}
function ntkModalHandleAttach(inp) {
  var preview = document.getElementById('ntk-modal-attach-preview');
  if (!preview) return;
  Array.from(inp.files).forEach(function(f) {
    var chip = document.createElement('span');
    chip.className = 'attach-chip';
    var icon = /\.(jpg|jpeg|png|gif)$/i.test(f.name) ? '🖼️' : /\.pdf$/i.test(f.name) ? '📄' : '📎';
    chip.innerHTML = icon + ' <span>' + f.name + '</span> <button type="button" onclick="this.parentNode.remove()">×</button>';
    preview.appendChild(chip);
    preview.classList.add('has-files');
  });
}
function ntkModalSubmit() {
  var form = document.getElementById('ntk-modal-form');
  var rte  = document.getElementById('ntk-modal-rte');
  var hid  = document.getElementById('ntk-modal-msg-hidden');
  if (!form || !rte || !hid) return;
  var txt = rte.innerText.trim();
  if (txt.length < 5) { alert('Please describe your issue (min 5 chars).'); rte.focus(); return; }
  hid.value = txt;
  form.submit();
}
</script>
</body>
</html>
