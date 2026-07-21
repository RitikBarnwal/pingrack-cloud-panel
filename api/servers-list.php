<?php
/**
 * api/servers-list.php
 * AJAX pagination for servers.php — returns cards HTML + pagination HTML.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/servers.php';
require_login();

header('Content-Type: application/json');

if (!verify_csrf($_GET['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token.']);
    exit;
}

$user     = current_user();
$uid      = (int)$user['id'];
$currency = strtoupper($user['currency'] ?? 'USD');
$curr_sym = user_currency_symbol($currency);

$per    = 10;
$page   = max(1, (int)($_GET['p'] ?? 1));
$filter = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$offset = ($page - 1) * $per;

$where  = ['user_id = ?', 'deleted_at IS NULL'];
$params = [$uid];
if ($filter !== 'all') { $where[] = 'status = ?'; $params[] = $filter; }
if ($search !== '') { $where[] = '(name LIKE ? OR ipv4 LIKE ? OR os_label LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$wsql = 'WHERE ' . implode(' AND ', $where);

$total       = (int)db()->prepare("SELECT COUNT(*) FROM servers $wsql")->execute($params) ? (function() use ($wsql,$params){ $s=db()->prepare("SELECT COUNT(*) FROM servers $wsql");$s->execute($params);return (int)$s->fetchColumn();}
)() : 0;
$total_pages = max(1,(int)ceil($total/$per));
$page = min($page,$total_pages);

$fp = array_merge($params, [$per, $offset]);
$st = db()->prepare("SELECT * FROM servers $wsql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$st->execute($fp);
$servers = $st->fetchAll();

function os_icon_l(string $os_label, int $size=26): string {
    $os=$os_label; $os=strtolower($os);
    $m=['ubuntu'=>'ubuntu/ubuntu-plain','debian'=>'debian/debian-plain','centos'=>'centos/centos-plain',
        'fedora'=>'fedora/fedora-plain','rocky'=>'rockylinux/rockylinux-original','windows'=>'windows8/windows8-original'];
    foreach($m as $k=>$p){ if(str_contains($os,$k)) return "<img src=\"https://cdn.jsdelivr.net/gh/devicons/devicon/icons/$p.svg\" width=\"$size\" height=\"$size\" alt=\"$k\" onerror=\"this.style.display='none'\">"; }
    return "<img src=\"https://cdn.jsdelivr.net/gh/devicons/devicon/icons/linux/linux-original.svg\" width=\"$size\" height=\"$size\" alt=\"linux\" onerror=\"this.style.display='none'\">";
}
function sbdg(string $s): string {
    return match($s){
        'running'=>'<span class="badge badge-green"><span class="sdot sdot-green"></span>Running</span>',
        'stopped'=>'<span class="badge badge-gray"><span class="sdot sdot-gray"></span>Stopped</span>',
        'provisioning','starting'=>'<span class="badge badge-blue"><span class="sdot sdot-blue sdot-pulse"></span>'.ucfirst($s).'</span>',
        'stopping','rebuilding'=>'<span class="badge badge-yellow"><span class="sdot sdot-yellow sdot-pulse"></span>'.ucfirst($s).'</span>',
        'suspended','error'=>'<span class="badge badge-red"><span class="sdot sdot-red"></span>'.ucfirst($s).'</span>',
        default=>'<span class="badge badge-gray">Unknown</span>',
    };
}

// Cards HTML
ob_start();
$base = BASE_URL;
foreach ($servers as $s):
$sn = htmlspecialchars($s['name'], ENT_QUOTES);
?>
<div class="srv-card" data-id="<?= $s['id'] ?>">
  <div class="os-icon-wrap"><?= os_icon_l($s['os_label']??'') ?></div>
  <div class="srv-info">
    <div class="srv-info-name">
      <a href="<?= $base ?>/servers/view.php?id=<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
      <?= sbdg($s['status']) ?>
    </div>
    <div class="srv-info-sub">
      <?php if($s['ipv4']):?><span class="srv-info-ip"><?= htmlspecialchars($s['ipv4']) ?></span><span class="info-sep">·</span><?php endif;?>
      <span class="srv-info-os"><?= htmlspecialchars($s['os_label']?:'—') ?></span>
      <span class="info-sep">·</span>
      <span class="srv-info-date"><?= date('d M Y',strtotime($s['created_at'])) ?></span>
    </div>
  </div>
  <div class="specs-group">
    <span class="spec-chip"><?= $s['vcpu'] ?>vCPU</span>
    <span class="spec-chip"><?= (int)$s['ram_gb'] ?>GB RAM</span>
    <span class="spec-chip"><?= (int)$s['disk_gb'] ?>GB SSD</span>
  </div>
  <div class="region-col">
    <img src="https://flagcdn.com/w20/<?= htmlspecialchars($s['region_flag']??'de') ?>.png"
         srcset="https://flagcdn.com/w40/<?= htmlspecialchars($s['region_flag']??'de') ?>.png 2x"
         width="20" height="15" alt="" onerror="this.style.display='none'">
    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['region_label']?:$s['region_slug']) ?></span>
  </div>
  <div class="price-col">
    <div class="price-main"><?= $curr_sym.number_format((float)$s['price_hourly'],4) ?>/hr</div>
    <div class="price-sub">~<?= $curr_sym.number_format((float)$s['price_monthly'],2) ?>/mo</div>
  </div>
  <div class="actions-col">
    <?php if($s['status']==='running'):?>
    <button class="act-btn" title="Reboot" onclick="srvAction(<?=$s['id']?>,'reboot','<?=$sn?>')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.86"/></svg></button>
    <button class="act-btn" title="Power Off" onclick="srvAction(<?=$s['id']?>,'stop','<?=$sn?>')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg></button>
    <?php elseif(in_array($s['status'],['stopped','suspended'])):?>
    <button class="act-btn power-on" title="Power On" onclick="srvAction(<?=$s['id']?>,'start','<?=$sn?>')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg></button>
    <?php else:?><div style="width:32px"></div><?php endif;?>
    <a class="act-btn" title="Manage" href="<?= $base ?>/servers/<?=$s['id']?>.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></a>
    <button class="act-btn danger" title="Delete" onclick="srvDelete(<?=$s['id']?>,'<?=$sn?>')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4h6v2"/></svg></button>
  </div>
</div>
<?php endforeach;
$cards_html = ob_get_clean();

// Pagination HTML
ob_start();
if ($total_pages > 1):
$range=2; $shown=[]; $pv=null;
for($i=1;$i<=$total_pages;$i++){if($i===1||$i===$total_pages||abs($i-$page)<=$range)$shown[]=$i;}
?>
<div class="paging" id="paging">
  <span class="paging-info">Showing <?=$offset+1?>–<?=min($offset+$per,$total)?> of <?=$total?> servers</span>
  <div class="paging-btns">
    <button class="pbtn" <?=$page<=1?'disabled':''?> onclick="goPage(<?=$page-1?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg></button>
    <?php foreach($shown as $p):if($pv!==null&&$p-$pv>1):?><span class="pbtn-ellipsis">…</span><?php endif;?><button class="pbtn <?=$p===$page?'active':''?>" onclick="goPage(<?=$p?>)"><?=$p?></button><?php $pv=$p;endforeach;?>
    <button class="pbtn" <?=$page>=$total_pages?'disabled':''?> onclick="goPage(<?=$page+1?>)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg></button>
  </div>
</div>
<?php endif;
$paging_html = ob_get_clean();

echo json_encode(['ok'=>true, 'cards'=>$cards_html, 'paging'=>$paging_html]);
