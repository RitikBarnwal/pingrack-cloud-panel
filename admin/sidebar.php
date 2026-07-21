<?php
// admin/sidebar.php — Management panel sidebar (backup-focused IA)
// Clean grouped navigation; product-only modules live in one collapsible "Extra".

if (!function_exists('adm_active')) {
    function adm_active(array $pages = [], array $tabs = []): string {
        $current = basename($_SERVER['PHP_SELF']);
        $tab     = $_GET['tab'] ?? '';
        if (in_array($current, $pages)) return 'active';
        if ($tabs && in_array($tab, $tabs)) return 'active';
        return '';
    }
}

// Small helper: pending-count badge (safe, returns '' on error / zero)
if (!function_exists('adm_pending')) {
    function adm_pending(string $sql): int {
        try { return (int)db()->query($sql)->fetchColumn(); }
        catch (\Throwable $e) { return 0; }
    }
}

// ── Pending counters ──────────────────────────────────────────
$pc_career   = adm_pending("SELECT COUNT(*) FROM career_applications WHERE status='pending'");
$pc_legal    = adm_pending("SELECT COUNT(*) FROM legal_pages WHERE is_published=0");
$pc_kyc      = adm_pending("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'");
$pc_callback = adm_pending("SELECT COUNT(*) FROM callback_requests WHERE status='pending'");
// Aggregate for the collapsed "Extra" group header
$pc_extra    = $pc_career + $pc_legal;

// ── Is an "Extra" item currently open? (auto-expand the group) ─
$__cur  = basename($_SERVER['PHP_SELF']);
$__tab  = $_GET['tab'] ?? '';
$extra_pages = ['bulk-email.php','bulk-whatsapp.php','announcement.php','kb.php','career.php',
                'ga-dashboard.php','ga-settings.php','legal-pages.php','cookie-consent.php'];
$extra_tabs  = ['coupons','referrals'];
$extra_open  = in_array($__cur, $extra_pages) || in_array($__tab, $extra_tabs);
?>

<!-- ── Collapsible "Extra" group styling (inline so it never depends on admin.css cache) ── -->
<style>
.adm-group-head{
  display:flex;align-items:center;gap:8px;width:100%;
  margin:12px 0 2px;padding:8px 10px;
  background:transparent;border:none;border-radius:6px;
  cursor:pointer;font-family:inherit;
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
  line-height:1.2;text-align:left;color:rgba(255,255,255,.42);
  transition:background .15s,color .15s;
}
.adm-group-head:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85)}
.adm-group-head span{flex:1}
.adm-group-chev{width:13px;height:13px;flex-shrink:0;opacity:.85;transition:transform .22s cubic-bezier(.4,0,.2,1);transform:rotate(90deg)}
.adm-group-head.collapsed .adm-group-chev{transform:rotate(0deg)}
.adm-group-badge{flex-shrink:0}
.adm-group-items{max-height:0;overflow:hidden;transition:max-height .28s cubic-bezier(.4,0,.2,1)}
.adm-group-items.open{max-height:720px}
.adm-group-items .adm-link{padding-left:12px;position:relative}
.adm-group-items .adm-link::after{content:'';position:absolute;left:4px;top:50%;transform:translateY(-50%);width:3px;height:3px;border-radius:50%;background:rgba(255,255,255,.18)}
.adm-group-items .adm-link.active::after{background:var(--primary,#2563eb)}
</style>

<!-- ── Overlay (click to close sidebar) ──────────────────── -->
<div class="adm-overlay" id="adm-overlay" onclick="admCloseSidebar()"></div>

<!-- ── Sidebar ───────────────────────────────────────────── -->
<aside class="adm-sidebar" id="adm-sidebar">

  <!-- Logo -->
  <div class="adm-logo">
    <?php if (!empty(get_setting('site_logo_d', ''))) : ?>
    <img src="<?= htmlspecialchars(get_setting('site_logo_d', '')) ?>" alt="Logo" style="width: 130px;">
<?php else: ?>
    <div class="adm-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5">
        <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>
      </svg>
    </div>
    <div>
      <div class="adm-logo-text"><?= APP_NAME ?></div>
      <span class="adm-logo-sub">Management Panel</span>
    </div>
<?php endif; ?>
    <span class="adm-badge" style="margin-left:auto">ADMIN</span>
  </div>

  <nav class="adm-nav">

    <!-- Overview -->
    <div class="adm-nav-lbl">Main</div>
    <a href="<?= BASE_URL ?>/admin/index.php" class="adm-link <?= adm_active(['index.php'], ['overview']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      Overview
    </a>

    <!-- Backups (panel focus) -->
    <div class="adm-nav-lbl">Backups</div>
    <a href="<?= BASE_URL ?>/admin/db-backup.php" class="adm-link <?= adm_active(['db-backup.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
      Database Backup
    </a>
    <a href="<?= BASE_URL ?>/admin/full-backup.php" class="adm-link <?= adm_active(['full-backup.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Full Backup
    </a>
    <a href="<?= BASE_URL ?>/admin/storage.php" class="adm-link <?= adm_active(['storage.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="6" rx="1"/><rect x="2" y="9" width="20" height="6" rx="1"/><rect x="2" y="15" width="20" height="6" rx="1"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="12" x2="6.01" y2="12"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
      Object Storage
    </a>
    <a href="<?= BASE_URL ?>/admin/cron-health.php" class="adm-link <?= adm_active(['cron-health.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Cron &amp; Jobs Health
    </a>

    <!-- Infrastructure -->
    <div class="adm-nav-lbl">Infrastructure</div>
    <a href="<?= BASE_URL ?>/admin/vps-packages.php" class="adm-link <?= adm_active(['vps-packages.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
      VPS Packages
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=providers" class="adm-link <?= adm_active([], ['providers']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
      Cloud Providers
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=orders" class="adm-link <?= adm_active([], ['orders']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
      Custom Orders
    </a>

    <!-- Users & Servers -->
    <div class="adm-nav-lbl">Users &amp; Servers</div>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=users" class="adm-link <?= adm_active([], ['users']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      All Users
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=servers" class="adm-link <?= adm_active([], ['servers']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
      All Servers
    </a>
    <a href="<?= BASE_URL ?>/history.php" class="adm-link <?= str_contains($_SERVER['REQUEST_URI'],'/history')?'active':'' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Activity History
    </a>

    <!-- Billing & Support -->
    <div class="adm-nav-lbl">Billing &amp; Support</div>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=revenue" class="adm-link <?= adm_active([], ['revenue']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
      Revenue Analytics
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=transactions" class="adm-link <?= adm_active([], ['transactions']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Transactions
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=invoices" class="adm-link <?= adm_active([], ['invoices']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Invoices
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=kyc" class="adm-link <?= adm_active([], ['kyc']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      KYC Requests
      <?php if ($pc_kyc > 0): ?><span class="adm-n-badge"><?= $pc_kyc ?></span><?php endif; ?>
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php?tab=tickets" class="adm-link <?= adm_active([], ['tickets']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Support Tickets
    </a>
    <a href="<?= BASE_URL ?>/admin/callback-requests.php" class="adm-link <?= adm_active(['callback-requests.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 11.9 19.79 19.79 0 0 1 1.61 3.31 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
      Callbacks
      <?php if ($pc_callback > 0): ?><span class="adm-n-badge"><?= $pc_callback ?></span><?php endif; ?>
    </a>

    <!-- System -->
    <div class="adm-nav-lbl">System</div>
    <a href="<?= BASE_URL ?>/admin/settings.php" class="adm-link <?= adm_active(['settings.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Settings
    </a>
    <a href="<?= BASE_URL ?>/admin/analytics.php" class="adm-link <?= adm_active(['analytics.php']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      Activity &amp; Security
    </a>

    <!-- Extra (product-only modules, collapsible) -->
    <button type="button" class="adm-group-head <?= $extra_open ? '' : 'collapsed' ?>"
            id="adm-extra-head" onclick="admToggleExtra()" aria-expanded="<?= $extra_open ? 'true' : 'false' ?>">
      <svg class="adm-group-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      <span>Extra</span>
      <?php if ($pc_extra > 0): ?><span class="adm-n-badge adm-group-badge"><?= $pc_extra ?></span><?php endif; ?>
    </button>
    <div class="adm-group-items <?= $extra_open ? 'open' : '' ?>" id="adm-extra-items">
      <a href="<?= BASE_URL ?>/admin/index.php?tab=coupons" class="adm-link <?= adm_active([], ['coupons']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        Coupons
      </a>
      <a href="<?= BASE_URL ?>/admin/index.php?tab=referrals" class="adm-link <?= adm_active([], ['referrals']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Referrals
      </a>
      <a href="<?= BASE_URL ?>/admin/bulk-email.php" class="adm-link <?= adm_active(['bulk-email.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Bulk Email
      </a>
      <a href="<?= BASE_URL ?>/admin/bulk-whatsapp.php" class="adm-link <?= adm_active(['bulk-whatsapp.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        Bulk WhatsApp
      </a>
      <a href="<?= BASE_URL ?>/admin/announcement.php" class="adm-link <?= adm_active(['announcement.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        Announcements
      </a>
      <a href="<?= BASE_URL ?>/admin/kb.php" class="adm-link <?= adm_active(['kb.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Knowledge Base
      </a>
      <a href="<?= BASE_URL ?>/admin/career.php" class="adm-link <?= adm_active(['career.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        Careers
        <?php if ($pc_career > 0): ?><span class="adm-n-badge"><?= $pc_career ?></span><?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/admin/ga-dashboard.php" class="adm-link <?= adm_active(['ga-dashboard.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Google Analytics
      </a>
      <a href="<?= BASE_URL ?>/admin/ga-settings.php" class="adm-link <?= adm_active(['ga-settings.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        GA Settings
      </a>
      <a href="<?= BASE_URL ?>/admin/legal-pages.php" class="adm-link <?= adm_active(['legal-pages.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Legal Pages
        <?php if ($pc_legal > 0): ?><span class="adm-n-badge"><?= $pc_legal ?></span><?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/admin/cookie-consent.php" class="adm-link <?= adm_active(['cookie-consent.php']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/></svg>
        Cookie Consent
      </a>
    </div>

    <!-- Back to dashboard -->
    <div style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(255,255,255,.05)">
      <a href="<?= BASE_URL ?>/dashboard.php" class="adm-back-link">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Dashboard
      </a>
    </div>

  </nav>

  <!-- User footer -->
  <div class="adm-footer-bar">
    <div class="adm-user-row">
      <div class="adm-user-av">
        <?= strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1)) ?>
      </div>
      <span class="adm-user-name">
        <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?>
      </span>
      <a href="<?= BASE_URL ?>/logout.php" class="adm-logout-btn">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:middle;margin-right:2px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Logout
      </a>
    </div>
  </div>

</aside>

<script>
function admToggleSidebar() {
  var s = document.getElementById('adm-sidebar');
  var o = document.getElementById('adm-overlay');
  s.classList.toggle('open');
  o.classList.toggle('open');
  document.body.style.overflow = s.classList.contains('open') ? 'hidden' : '';
}
function admCloseSidebar() {
  document.getElementById('adm-sidebar').classList.remove('open');
  document.getElementById('adm-overlay').classList.remove('open');
  document.body.style.overflow = '';
}
// ── Collapsible "Extra" group (remembers state) ──────────────
function admToggleExtra() {
  var head  = document.getElementById('adm-extra-head');
  var items = document.getElementById('adm-extra-items');
  var open  = items.classList.toggle('open');
  head.classList.toggle('collapsed', !open);
  head.setAttribute('aria-expanded', open ? 'true' : 'false');
  try { localStorage.setItem('adm_extra_open', open ? '1' : '0'); } catch (e) {}
}
// Restore saved state on load (unless an Extra item is active → keep open)
(function () {
  var head  = document.getElementById('adm-extra-head');
  var items = document.getElementById('adm-extra-items');
  if (!head || !items) return;
  var forcedOpen = <?= $extra_open ? 'true' : 'false' ?>;
  if (forcedOpen) return;
  try {
    if (localStorage.getItem('adm_extra_open') === '1') {
      items.classList.add('open');
      head.classList.remove('collapsed');
      head.setAttribute('aria-expanded', 'true');
    }
  } catch (e) {}
})();
// Close on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') admCloseSidebar();
});
// Close sidebar when a nav link is tapped on mobile
document.querySelectorAll('.adm-link').forEach(function(link) {
  link.addEventListener('click', function() {
    if (window.innerWidth <= 960) admCloseSidebar();
  });
});
</script>
