<?php
/**
 * api/servers-page.php
 * Returns paginated server list HTML for dashboard AJAX pagination.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_login();

header('Content-Type: application/json');

if (!isset($_GET['csrf']) || !verify_csrf($_GET['csrf'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token.']);
    exit;
}

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);

$per_page    = 10;
$page        = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($page - 1) * $per_page;
$total       = (int)db()->query("SELECT COUNT(*) FROM servers WHERE user_id=$uid AND deleted_at IS NULL")->fetchColumn();
$total_pages = (int)ceil($total / $per_page);

$st = db()->prepare(
    'SELECT * FROM servers WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$st->execute([$uid, $per_page, $offset]);
$servers = $st->fetchAll();

// OS icon helper
function os_icon_html(string $os_label, int $size = 22): string {
    $os  = strtolower($os_label);
    $map = [
        'ubuntu'  => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/ubuntu/ubuntu-plain.svg',
        'debian'  => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/debian/debian-plain.svg',
        'centos'  => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/centos/centos-plain.svg',
        'fedora'  => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/fedora/fedora-plain.svg',
        'rocky'   => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/rockylinux/rockylinux-original.svg',
        'windows' => 'https://cdn.jsdelivr.net/gh/devicons/devicon/icons/windows8/windows8-original.svg',
    ];
    foreach ($map as $key => $url) {
        if (str_contains($os, $key)) {
            return "<img src=\"$url\" width=\"$size\" height=\"$size\" alt=\"$key\" style=\"display:block\" onerror=\"this.style.display='none'\">";
        }
    }
    return "<img src=\"https://cdn.jsdelivr.net/gh/devicons/devicon/icons/linux/linux-original.svg\" width=\"$size\" height=\"$size\" alt=\"linux\" style=\"display:block\" onerror=\"this.style.display='none'\">";
}

function status_badge_api(string $status): string {
    return match($status) {
        'running'      => '<span class="badge badge-green"><span class="sdot sdot-green"></span>Running</span>',
        'stopped'      => '<span class="badge badge-gray"><span class="sdot sdot-gray"></span>Stopped</span>',
        'provisioning' => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Provisioning</span>',
        'starting'     => '<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>Starting</span>',
        'stopping'     => '<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>Stopping</span>',
        'suspended'    => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Suspended</span>',
        'error'        => '<span class="badge badge-red"><span class="sdot sdot-red"></span>Error</span>',
        default        => '<span class="badge badge-gray">Unknown</span>',
    };
}

// Build rows HTML
ob_start();
foreach ($servers as $s):
    $sname = htmlspecialchars($s['name'], ENT_QUOTES);
?>
<li class="srv-item" data-id="<?= $s['id'] ?>">
  <div class="srv-os-icon"><?= os_icon_html($s['os_label'] ?? '') ?></div>
  <div style="min-width:0;flex:1">
    <div class="srv-name">
      <a href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>" style="color:inherit;text-decoration:none"><?= htmlspecialchars($s['name']) ?></a>
      <?= status_badge_api($s['status']) ?>
    </div>
    <div class="srv-meta">
      <?php if ($s['ipv4']): ?>
      <span class="srv-meta-ip"><?= htmlspecialchars($s['ipv4']) ?></span>
      <span style="color:var(--gray-300)">·</span>
      <?php endif; ?>
      <span><?= htmlspecialchars($s['os_label'] ?: '—') ?></span>
    </div>
  </div>
  <div class="srv-specs">
    <span class="spec-chip"><?= $s['vcpu'] ?>vCPU</span>
    <span class="spec-chip"><?= (int)$s['ram_gb'] ?>GB</span>
    <span class="spec-chip"><?= (int)$s['disk_gb'] ?>GB</span>
  </div>
  <div class="srv-region">
    <img src="https://flagcdn.com/w20/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png"
         srcset="https://flagcdn.com/w40/<?= htmlspecialchars($s['region_flag'] ?? 'de') ?>.png 2x"
         width="18" height="13" alt="" onerror="this.style.display='none'" style="border-radius:2px">
  </div>
  <div class="srv-price">
    <?= $curr_sym . number_format((float)$s['price_hourly'], 4) ?>/hr
    <div class="srv-price-sub">~<?= $curr_sym . number_format((float)$s['price_monthly'], 2) ?>/mo</div>
  </div>
  <div class="srv-actions">
    <?php if ($s['status'] === 'running'): ?>
    <button class="srv-btn" title="Reboot" onclick="doAction(<?= $s['id'] ?>,'reboot','<?= $sname ?>')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg>
    </button>
    <button class="srv-btn" title="Power Off" onclick="doAction(<?= $s['id'] ?>,'stop','<?= $sname ?>')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
    </button>
    <?php elseif (in_array($s['status'], ['stopped','suspended'])): ?>
    <button class="srv-btn" title="Power On" onclick="doAction(<?= $s['id'] ?>,'start','<?= $sname ?>')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    </button>
    <?php endif; ?>
    <a class="srv-btn" title="Manage" href="<?= BASE_URL ?>/servers/view.php?id=<?= $s['id'] ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </a>
    <button class="srv-btn del" title="Delete" onclick="doDelete(<?= $s['id'] ?>,'<?= $sname ?>')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg>
    </button>
  </div>
</li>
<?php endforeach;
$html = ob_get_clean();

// Pagination HTML
ob_start();
if ($total_pages > 1):
?>
<div class="pagination">
  <span class="page-info">Showing <?= ($offset + 1) ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?></span>
  <div class="page-btns">
    <button class="page-btn" <?= $page <= 1 ? 'disabled' : '' ?> onclick="loadPage(<?= $page - 1 ?>)">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <?php for ($p = 1; $p <= min($total_pages, 7); $p++): ?>
    <button class="page-btn <?= $p === $page ? 'active' : '' ?>" onclick="loadPage(<?= $p ?>)"><?= $p ?></button>
    <?php endfor; ?>
    <?php if ($total_pages > 7): ?>
    <span style="align-self:center;color:var(--gray-400);font-size:12px;padding:0 4px">…</span>
    <button class="page-btn <?= $page === $total_pages ? 'active' : '' ?>" onclick="loadPage(<?= $total_pages ?>)"><?= $total_pages ?></button>
    <?php endif; ?>
    <button class="page-btn" <?= $page >= $total_pages ? 'disabled' : '' ?> onclick="loadPage(<?= $page + 1 ?>)">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  </div>
</div>
<?php endif;
$pagination_html = ob_get_clean();

echo json_encode(['ok' => true, 'html' => $html, 'pagination' => $pagination_html]);
