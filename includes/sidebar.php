<?php
/**
 * includes/sidebar.php
 * Reusable sidebar — include on every dashboard page.
 * Requires: $user, $app_name, $avatar, $fname, $uname, $curr_sym, $balance
 */
$current_page = basename($_SERVER['PHP_SELF']);
$in_servers   = str_contains($_SERVER['REQUEST_URI'], '/servers');
?>
<?php if (impersonating()): ?>
<!-- ═ IMPERSONATION BANNER ════════════════════════════════ -->
<div id="impersonate-bar" style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#7c3aed;color:white;padding:9px 18px;display:flex;align-items:center;gap:12px;font-size:13px;font-weight:600;box-shadow:0 2px 12px rgba(124,58,237,.4)">
  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
  <span>Viewing as <strong><?= htmlspecialchars(current_user()['username'] ?? '') ?></strong></span>
  <span style="opacity:.7">— You are logged in as admin <strong><?= impersonate_admin_name() ?></strong></span>
  <button onclick="exitImpersonate()"
          style="margin-left:auto;padding:5px 14px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);border-radius:7px;color:white;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .13s"
          onmouseover="this.style.background='rgba(255,255,255,.3)'"
          onmouseout="this.style.background='rgba(255,255,255,.2)'">
    ← Exit to Admin
  </button>
</div>
<style>
  /* Push all content down when impersonation bar visible */
  .app-shell { margin-top: 40px; }
</style>
<script>
function exitImpersonate() {
  fetch('<?= BASE_URL ?>/api/admin-impersonate.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'exit', csrf:'<?= csrf_token() ?>'})
  }).then(r=>r.json()).then(function(d){
    if (d.ok) window.location.href = d.redirect;
    else alert(d.error || 'Error exiting impersonation');
  });
}
</script>
<?php endif; ?>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <?php if (!empty(get_setting('site_logo', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo', '')) ?>" alt="Logo" style="width: 200px;">
<?php else: ?>
    <div class="logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
    </div>
    <span class="logo-text"><?= $app_name ?></span>
<?php endif; ?>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Dashboard</div>
    <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <div class="nav-label">Compute</div>
    <a href="<?= BASE_URL ?>/servers.php" class="nav-link <?= ($current_page === 'servers.php' || $in_servers) ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
      My Servers
    </a>
    <a href="<?= BASE_URL ?>/ssh-keys.php" class="nav-link <?= $current_page === 'ssh-keys.php' ? 'active' : '' ?>">
      <svg fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-key-round" aria-hidden="true"><path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"></path><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"></circle></svg>
      SSH Keys
    </a>
    <div class="nav-label">Storage</div>
    <a href="<?= BASE_URL ?>/storage.php" class="nav-link <?= ($current_page === 'storage.php' || str_contains($_SERVER['REQUEST_URI'],'/storage/')) ? 'active' : '' ?>">
      <svg fill="none" stroke="currentColor" stroke-width="2" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 72 72" style="enable-background:new 0 0 72 72;width:16px" xml:space="preserve"><path d="M40,1.5c-16,0-29,3.6-29,8c0,0.1,0,0.3,0,0.4l0,0l0.8,7c0.8-0.7,1.4-1.1,1.5-1.2l0,0
	C11.3,17.3-0.7,27.3,2.1,42.3c0.9,4.8,3.6,8,7.7,9c0.8,0.2,1.7,0.3,2.6,0.3c1,0,2-0.1,3.1-0.3L15,46.4c-1.5,0.3-3,0.4-4.3,0.1
	c-2.3-0.5-3.6-2.2-4.2-5.2c-1.5-8,2.6-14.5,5.9-18.2L15,46.5c7.1-1.7,16-9.5,22-17.9c-0.2-0.4-0.2-0.9-0.2-1.4
	c0-2.3,1.7-4.1,3.7-4.1s3.7,1.8,3.7,4.1c0,2.1-1.5,3.9-3.4,4.1c-7.5,10.7-17.3,18.4-25.3,20.1l0.4,3.4v0.4l0,0
	c0.7,4.1,11,7.4,23.7,7.4c13.1,0,23.7-3.5,23.7-7.8l5.6-44.6c0-0.2,0.1-0.4,0.1-0.5C69,5.1,56,1.5,40,1.5z M40,15.3
	c-12.1,0-21.8-2.1-21.8-4.7c0-2.6,9.8-4.7,21.8-4.7c12.1,0,21.8,2.1,21.8,4.7C61.8,13.1,52,15.3,40,15.3z"></path></svg>
      Object Storage
    </a>
    <?php if (get_setting('proxy_module_enabled', '1') === '1'): ?>
<div class="nav-label">Proxy</div>
<a href="<?= BASE_URL ?>/proxy/"
   class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/proxy') ? 'active' : '' ?>">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="10"/>
    <line x1="2" y1="12" x2="22" y2="12"/>
    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12a15.3 15.3 0 0 1 4-10z"/>
  </svg>
  Proxy Services
</a>
<?php endif; ?>
    <div class="nav-label">Email</div>
    <a href="<?= BASE_URL ?>/email/" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/email/') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      SMTP Email
    </a>
    <div class="nav-label">Networking</div>
    <a href="<?= BASE_URL ?>/dns.php" class="nav-link <?= ($current_page === 'dns.php' || str_contains($_SERVER['REQUEST_URI'],'/dns/')) ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      DNS Management
    </a>
    <a href="<?= BASE_URL ?>/firewalls.php" class="nav-link <?= $current_page === 'firewalls.php' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Firewalls
    </a>
    
    <div class="nav-label">Support</div>
    <a href="<?= BASE_URL ?>/tickets.php" class="nav-link <?= $current_page === 'tickets.php' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Support Tickets
      <?php
      // Show unread badge
      try {
        $unread = db()->prepare("SELECT COUNT(*) FROM tickets t JOIN ticket_replies r ON r.ticket_id=t.id WHERE t.user_id=? AND r.is_admin=1 AND r.created_at > t.updated_at");
        $unread->execute([$user['id']]);
        $unread_count = (int)$unread->fetchColumn();
        if ($unread_count > 0) echo '<span class="badge badge-red" style="margin-left:auto;font-size:10px">' . $unread_count . '</span>';
      } catch(Throwable $e) {}
      ?>
    </a>
    
    <?php if (get_setting('callback_enabled', '1') === '1'): ?>
  <!-- ── Request Callback Button ─────────────────────────── -->
  <a href="javascript:void(0)" onclick="openCallbackModal()" class="nav-link">
  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-phone-call h-3.5 w-3.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path><path d="M14.05 2a9 9 0 0 1 8 7.94"></path><path d="M14.05 6A5 5 0 0 1 18 10"></path></svg>
  Request a Callback
</a>
  <?php endif; ?>

    <div class="nav-label" style="margin-top:10px">Account</div>
    <?php
    // KYC status check — show only if not approved
    $kyc_sidebar = null;
    try {
      $kyc_st = db()->prepare('SELECT status FROM kyc_requests WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1');
      $kyc_st->execute([$user['id']]);
      $kyc_sidebar = $kyc_st->fetchColumn() ?: null;
    } catch(Throwable $e) { $kyc_sidebar = null; }
    ?>
    <a href="<?= BASE_URL ?>/profile.php" class="nav-link <?= $current_page === 'profile.php' ? 'active' : '' ?>">
      <svg fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-settings" aria-hidden="true"><path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"></path><circle cx="12" cy="12" r="3"></circle></svg>
      Settings
    </a>
    <a href="<?= BASE_URL ?>/billing.php" class="nav-link <?= $current_page === 'billing.php' ? 'active' : '' ?>">
      <svg fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wallet" aria-hidden="true"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"></path><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"></path></svg>
      Billing & Wallet
      <?php if ((float)$user['wallet_balance'] < 5): ?>
      <span class="badge badge-red" style="margin-left:auto;font-size:10px">Low</span>
      <?php endif; ?>
    </a>
    <?php if ($kyc_sidebar !== 'approved'): ?>
    <a href="<?= BASE_URL ?>/kyc.php" class="nav-link <?= $current_page === 'kyc.php' ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      KYC Verification
      <?php if ($kyc_sidebar === 'rejected'): ?>
      <span class="badge badge-red" style="margin-left:auto;font-size:10px">Rejected</span>
      <?php elseif ($kyc_sidebar === 'pending'): ?>
      <span class="badge badge-yellow" style="margin-left:auto;font-size:10px">Review</span>
      <?php else: ?>
      <span class="badge badge-red" style="margin-left:auto;font-size:10px">Required</span>
      <?php endif; ?>
    </a>
    <?php endif; ?>

    <!-- More (collapsible secondary group) -->
    <?php $more_active = in_array($current_page, ['referral.php','api-keys.php','history.php'], true); ?>
    <button type="button" class="nav-group-head <?= $more_active ? '' : 'collapsed' ?>"
            id="usr-more-head" onclick="usrToggleMore()" aria-expanded="<?= $more_active ? 'true' : 'false' ?>">
      <svg class="nav-group-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      <span>More</span>
    </button>
    <div class="nav-group-items <?= $more_active ? 'open' : '' ?>" id="usr-more-items">
      <a href="<?= BASE_URL ?>/referral.php" class="nav-link <?= $current_page === 'referral.php' ? 'active' : '' ?>">
        <svg fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-gift" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"></rect><path d="M12 8v13"></path><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"></path><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"></path></svg>
        Refer & Earn
      </a>
      <a href="<?= BASE_URL ?>/api-keys.php" class="nav-link <?= $current_page === 'api-keys.php' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17L10 12L4 7"></path><line x1="13" y1="17" x2="20" y2="17"></line></svg>
        API Access
      </a>
      <a href="<?= BASE_URL ?>/history.php" class="nav-link <?= $current_page === 'history.php' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Activity History
      </a>
    </div>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <div class="nav-label" style="margin-top:10px">Admin</div>
    <a href="<?= BASE_URL ?>/admin/" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'],'/admin') ? 'active' : '' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
      Admin Panel
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <!-- Wallet mini -->
    <a href="<?= BASE_URL ?>/billing.php" style="display:flex;align-items:center;justify-content:space-between;padding:9px 10px;background:var(--gray-50);border:1px solid var(--border);border-radius:9px;margin-bottom:10px;text-decoration:none;transition:background .14s" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--gray-50)'">
      <span style="font-size:11.5px;font-weight:600;color:var(--gray-500)">Wallet Balance</span>
      <span style="font-size:13px;font-weight:800;color:var(--gray-900)"><?= $curr_sym . $balance ?></span>
    </a>
    <div class="user-card">
      <div class="avatar"><img src="<?= getGravatar($user['email'], $user['user_profile']) ?>"></div>
      <div class="user-meta">
        <div class="user-name"><?= $fname ?> <?= ($user['kyc'] == 1 ? '<i style="color:#009688;vertical-align: middle;" class="fi fi-bs-badge-check"></i>' : '') ?></div>
        <div class="user-plan plan-free">@<?= $uname ?></div>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="nav-link" style="color:var(--danger)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sign Out
    </a>
  </div>
</aside>

<!-- ── User sidebar: collapsible group + active-link polish ─────── -->
<style>
.nav-group-head{
  display:flex;align-items:center;gap:6px;width:100%;
  margin-top:10px;padding:10px 10px 5px;
  background:none;border:none;cursor:pointer;font-family:inherit;
  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;
  color:var(--gray-400);transition:color .12s;
}
.nav-group-head:hover{color:var(--gray-600)}
.nav-group-chev{width:11px;height:11px;flex-shrink:0;opacity:.75;transition:transform .2s cubic-bezier(.4,0,.2,1);transform:rotate(90deg)}
.nav-group-head.collapsed .nav-group-chev{transform:rotate(0deg)}
.nav-group-items{max-height:0;overflow:hidden;transition:max-height .28s cubic-bezier(.4,0,.2,1)}
.nav-group-items.open{max-height:320px}
.nav-group-items .nav-link{padding-left:14px;position:relative}
.nav-group-items .nav-link::after{content:'';position:absolute;left:5px;top:50%;transform:translateY(-50%);width:3px;height:3px;border-radius:50%;background:var(--gray-300)}
.nav-group-items .nav-link.active::after{background:var(--primary)}
.nav-link.active{box-shadow:inset 0 0 0 1px hsl(0 0% 9% / .06)}
</style>
<script>
function usrToggleMore(){
  var h=document.getElementById('usr-more-head'),i=document.getElementById('usr-more-items');
  if(!h||!i)return;
  var o=i.classList.toggle('open');
  h.classList.toggle('collapsed',!o);
  h.setAttribute('aria-expanded',o?'true':'false');
  try{localStorage.setItem('usr_more_open',o?'1':'0')}catch(e){}
}
(function(){
  var h=document.getElementById('usr-more-head'),i=document.getElementById('usr-more-items');
  if(!h||!i)return;
  if(<?= $more_active ? 'true' : 'false' ?>)return;         // keep open when a child page is active
  try{if(localStorage.getItem('usr_more_open')==='1'){i.classList.add('open');h.classList.remove('collapsed');h.setAttribute('aria-expanded','true');}}catch(e){}
})();
</script>

<?php if (get_setting('callback_enabled', '1') === '1'): ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- REQUEST A CALLBACK — Modal                                        -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<style>
#cb-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:1000;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .22s}
#cb-modal-bg.cb-open{opacity:1;pointer-events:all}
#cb-modal{background:white;border-radius:16px;width:460px;max-width:96vw;max-height:94vh;overflow-y:auto;box-shadow:0 24px 70px rgba(0,0,0,.18);transform:translateY(14px) scale(.98);transition:transform .22s,opacity .22s;opacity:0}
#cb-modal-bg.cb-open #cb-modal{transform:translateY(0) scale(1);opacity:1}
.cb-head{padding:20px 22px 14px;display:flex;align-items:center;gap:11px}
.cb-head-icon{width:36px;height:36px;border-radius:10px;background:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cb-head h2{font-size:16px;font-weight:900;color:#0f172a;margin:0}
.cb-head p{font-size:12px;color:#64748b;margin:2px 0 0}
.cb-close{margin-left:auto;background:none;border:none;cursor:pointer;color:#94a3b8;padding:5px;border-radius:7px;transition:all .13s;line-height:0}
.cb-close:hover{background:#f1f5f9;color:#475569}
.cb-divider{height:1px;background:#f1f5f9;margin:0 22px}
.cb-body{padding:18px 22px 22px}
.cb-field{margin-bottom:14px}
.cb-label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px}
.cb-label .req{color:#ef4444;margin-left:2px}
.cb-input{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13.5px;font-family:inherit;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;box-sizing:border-box;background:white}
.cb-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-ring)}
.cb-input::placeholder{color:#cbd5e1}
.cb-select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;cursor:pointer}
.cb-textarea{resize:vertical;min-height:80px}
.cb-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cb-footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding-top:6px}
.cb-btn-cancel{padding:9px 20px;border:1.5px solid #e2e8f0;background:white;border-radius:9px;font-size:13.5px;font-weight:700;color:#64748b;cursor:pointer;font-family:inherit;transition:all .15s}
.cb-btn-cancel:hover{border-color:#94a3b8;color:#1e293b}
.cb-btn-submit{padding:9px 22px;background:var(--primary);border:none;border-radius:9px;font-size:13.5px;font-weight:700;color:white;cursor:pointer;font-family:inherit;transition:all .15s;display:flex;align-items:center;gap:7px}
.cb-btn-submit:hover{background:var(--primary-hover)}
.cb-btn-submit:disabled{opacity:.6;cursor:not-allowed}
.cb-toast{position:fixed;bottom:26px;right:24px;z-index:1100;padding:12px 18px;border-radius:11px;font-size:13.5px;font-weight:700;box-shadow:0 8px 30px rgba(0,0,0,.15);transform:translateY(12px);opacity:0;transition:all .3s;pointer-events:none;max-width:340px}
.cb-toast.show{transform:translateY(0);opacity:1}
.cb-toast.ok{background:#0f172a;color:white}
.cb-toast.fail{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.cb-error-msg{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:9px 13px;font-size:13px;font-weight:600;margin-bottom:14px;display:none}
</style>

<div id="cb-modal-bg">
  <div id="cb-modal" role="dialog" aria-modal="true" aria-label="Request a Callback">
    <div class="cb-head">
      <div class="cb-head-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 11.9 19.79 19.79 0 0 1 1.61 3.31 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      </div>
      <div>
        <h2>Request a Callback</h2>
        <p>Fill in your details and we'll call you back.</p>
      </div>
      <button class="cb-close" onclick="closeCallbackModal()" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="cb-divider"></div>
    <div class="cb-body">

      <div class="cb-error-msg" id="cb-error"></div>

      <!-- Name -->
      <div class="cb-field">
        <label class="cb-label" for="cb-name">Name</label>
        <input id="cb-name" value="<?= $fname ?>" type="text" class="cb-input" placeholder="Enter your name">
      </div>

      <!-- Phone -->
      <div class="cb-field">
        <label class="cb-label" for="cb-phone">Phone Number<span class="req">*</span></label>
        <input id="cb-phone" type="tel" value="<?php echo $user['phone']; ?>" class="cb-input" placeholder="Enter your phone number">
      </div>

      <!-- Department + Preferred Time (two cols) -->
      <!--div class="cb-row"-->
        <div class="cb-field">
          <label class="cb-label" for="cb-dept">Department<span class="req">*</span></label>
          <select id="cb-dept" class="cb-input cb-select">
            <option value="">Select department</option>
            <?php
            try {
              $depts = db()->query("SELECT name FROM callback_departments WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_COLUMN);
              foreach ($depts as $d) echo '<option value="'.htmlspecialchars($d).'">'.htmlspecialchars($d).'</option>';
            } catch(Throwable $e) {}
            ?>
          </select>
        </div>
        <div class="cb-field">
          <label class="cb-label" for="cb-time">Preferred Time</label>
          <select id="cb-time" class="cb-input cb-select">
            <option value="">Select preferred time slot</option>
            <?php
            try {
              $slots = db()->query("SELECT label FROM callback_timeslots WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_COLUMN);
              foreach ($slots as $s) echo '<option value="'.htmlspecialchars($s).'">'.htmlspecialchars($s).'</option>';
            } catch(Throwable $e) {}
            ?>
          </select>
        </div>
      <!--/div-->

      <!-- Message -->
      <div class="cb-field">
        <label class="cb-label" for="cb-msg">Message<span class="req">*</span></label>
        <textarea id="cb-msg" class="cb-input cb-textarea" placeholder="Briefly describe what you'd like to discuss..."></textarea>
      </div>

      <div class="cb-footer">
        <button class="cb-btn-cancel" onclick="closeCallbackModal()">Cancel</button>
        <button class="cb-btn-submit" id="cb-submit-btn" onclick="submitCallback()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 11.9 19.79 19.79 0 0 1 1.61 3.31 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          Request Callback
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="cb-toast" id="cb-toast"></div>

<script>
(function(){
  var _cbCsrf = '<?= csrf_token() ?>';
  var _cbBase = '<?= BASE_URL ?>';

  window.openCallbackModal = function() {
    document.getElementById('cb-modal-bg').classList.add('cb-open');
    document.getElementById('cb-error').style.display = 'none';
    document.body.style.overflow = 'hidden';
  };
  window.closeCallbackModal = function() {
    document.getElementById('cb-modal-bg').classList.remove('cb-open');
    document.body.style.overflow = '';
  };

  document.getElementById('cb-modal-bg').addEventListener('click', function(e){
    if (e.target === this) closeCallbackModal();
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeCallbackModal();
  });

  function showToast(msg, type) {
    var t = document.getElementById('cb-toast');
    t.textContent = msg;
    t.className = 'cb-toast ' + type;
    setTimeout(function(){ t.classList.add('show'); }, 10);
    setTimeout(function(){ t.classList.remove('show'); }, 4000);
  }

  window.submitCallback = function() {
    var name  = document.getElementById('cb-name').value.trim();
    var phone = document.getElementById('cb-phone').value.trim();
    var dept  = document.getElementById('cb-dept').value;
    var time  = document.getElementById('cb-time').value;
    var msg   = document.getElementById('cb-msg').value.trim();
    var errEl = document.getElementById('cb-error');

    errEl.style.display = 'none';

    if (!phone) { errEl.textContent = 'Phone number is required.'; errEl.style.display='block'; return; }
    if (!dept)  { errEl.textContent = 'Please select a department.'; errEl.style.display='block'; return; }
    if (!msg)   { errEl.textContent = 'Please describe what you\'d like to discuss.'; errEl.style.display='block'; return; }

    var btn = document.getElementById('cb-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:cb-spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0"/></svg> Sending...';

    fetch(_cbBase + '/api/callback-request.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({csrf: _cbCsrf, name: name, phone: phone, dept: dept, time: time, message: msg})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.ok) {
        closeCallbackModal();
        // Reset form
        ['cb-name','cb-phone','cb-msg'].forEach(function(id){ document.getElementById(id).value=''; });
        document.getElementById('cb-dept').value='';
        document.getElementById('cb-time').value='';
        showToast('✅ ' + (d.message || 'Callback requested successfully!'), 'ok');
      } else {
        errEl.textContent = d.error || 'Something went wrong. Please try again.';
        errEl.style.display = 'block';
      }
    })
    .catch(function(){
      errEl.textContent = 'Network error. Please try again.';
      errEl.style.display = 'block';
    })
    .finally(function(){
      btn.disabled = false;
      btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 11.9 19.79 19.79 0 0 1 1.61 3.31 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Request Callback';
    });
  };
})();
</script>
<style>
@keyframes cb-spin { to { transform: rotate(360deg); } }
</style>
<?php endif; ?>