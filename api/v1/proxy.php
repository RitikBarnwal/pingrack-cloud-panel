<?php
/**
 * api/v1/proxy.php
 * GET    /api/v1/proxy            — list proxy orders
 * GET    /api/v1/proxy?id=N       — single proxy detail
 * GET    /api/v1/proxy?id=N&stats=1   — bandwidth stats
 * PUT    /api/v1/proxy?id=N&rotate=1  — request IP rotation
 * DELETE /api/v1/proxy?id=N       — cancel order
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/_auth.php';

$auth = api_auth();
$uid  = (int)$auth['user_id'];
$meth = $_SERVER['REQUEST_METHOD'];
$id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$ov   = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']??'');
if($ov && in_array($ov,['PUT','DELETE'])) $meth=$ov;

/* stats */
if($meth==='GET'&&$id&&isset($_GET['stats'])){
    $o=pg($id,$uid);
    api_ok(['stats'=>['bandwidth_used_gb'=>(float)($o['bandwidth_used_gb']??0),'bandwidth_avail_gb'=>(float)($o['bandwidth_avail_gb']??0),'status'=>$o['status'],'last_synced_at'=>$o['last_synced_at']??null,'expires_at'=>$o['expires_at']??null]]);
}
/* single */
if($meth==='GET'&&$id){ api_ok(['proxy'=>fp(pg($id,$uid))]); }
/* list */
if($meth==='GET'){
    $lim=min((int)($_GET['limit']??20),100);
    $off=(int)($_GET['offset']??0);
    $st=$_GET['status']??null;
    $w=['po.user_id=?','po.deleted_at IS NULL'];$p=[$uid];
    if($st){$w[]='po.status=?';$p[]=$st;}
    $wh=implode(' AND ',$w);
    $c=db()->prepare("SELECT COUNT(*) FROM proxy_orders po WHERE $wh");$c->execute($p);$tot=(int)$c->fetchColumn();
    $p[]=$lim;$p[]=$off;
    $s=db()->prepare("SELECT po.*,pp.name AS plan_name FROM proxy_orders po JOIN proxy_plans pp ON pp.id=po.plan_id WHERE $wh ORDER BY po.created_at DESC LIMIT ? OFFSET ?");
    $s->execute($p);
    api_ok(['proxies'=>array_map('fp',$s->fetchAll()?:[]),'meta'=>['total'=>$tot,'limit'=>$lim,'offset'=>$off]]);
}
/* rotate */
if($meth==='PUT'&&$id&&isset($_GET['rotate'])){
    $o=pg($id,$uid);
    if($o['status']!=='active') api_error('Proxy must be active.',422);
    db()->prepare('UPDATE proxy_orders SET whitelist_unlock_at=NULL,last_synced_at=NULL WHERE id=? AND user_id=?')->execute([$id,$uid]);
    api_ok(['message'=>'IP rotation requested. New IPs assigned shortly.','proxy_id'=>$id]);
}
/* cancel */
if($meth==='DELETE'&&$id){
    $o=pg($id,$uid);
    if(!in_array($o['status'],['active','pending','provisioning'])) api_error('Cannot cancel in status: '.$o['status'].'.',422);
    db()->prepare('UPDATE proxy_orders SET status=?,deleted_at=NOW() WHERE id=? AND user_id=?')->execute(['cancelled',$id,$uid]);
    api_ok(['message'=>'Proxy order cancelled.']);
}
api_error('Method not allowed.',405);

function pg(int $id,int $uid):array{
    $s=db()->prepare('SELECT po.*,pp.name AS plan_name,pc.password_plain FROM proxy_orders po JOIN proxy_plans pp ON pp.id=po.plan_id LEFT JOIN proxy_credentials pc ON pc.order_id=po.id WHERE po.id=? AND po.user_id=? AND po.deleted_at IS NULL LIMIT 1');
    $s->execute([$id,$uid]);$r=$s->fetch();
    if(!$r) api_error('Proxy not found.',404);
    return $r;
}
function fp(array $o):array{
    $a=$o['status']==='active';
    return['id'=>(int)$o['id'],'plan'=>$o['plan_name']??null,'status'=>$o['status'],'type'=>$o['proxy_type']??'http','location'=>$o['location']??null,
           'gateway_host'=>$a?($o['gateway_host']??null):null,'gateway_port'=>$a?($o['gateway_port']??null):null,
           'username'=>$a?($o['username']??null):null,'proxy_list'=>$a?($o['proxy_list']??null):null,
           'whitelist_ip'=>$o['whitelist_ip']??null,'bandwidth_used_gb'=>(float)($o['bandwidth_used_gb']??0),
           'bandwidth_avail_gb'=>(float)($o['bandwidth_avail_gb']??0),'expires_at'=>$o['expires_at']??null,'created_at'=>$o['created_at']];
}
