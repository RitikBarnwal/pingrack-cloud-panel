<?php
/**
 * api/v1/dns.php
 * GET    /api/v1/dns                              — list zones
 * GET    /api/v1/dns?id=N                         — zone + records
 * POST   /api/v1/dns                              — create zone {domain}
 * DELETE /api/v1/dns?id=N                         — delete zone
 * GET    /api/v1/dns?id=N&records=1               — list records
 * POST   /api/v1/dns?id=N&records=1               — add record {type,name,value,ttl}
 * DELETE /api/v1/dns?id=N&records=1&record_id=R   — delete record
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/dns.php';
require_once __DIR__.'/_auth.php';

$auth  = api_auth();
$uid   = (int)$auth['user_id'];
$meth  = $_SERVER['REQUEST_METHOD'];
$zid   = isset($_GET['id'])        ? (int)$_GET['id']        : null;
$recs  = isset($_GET['records']);
$rid   = isset($_GET['record_id']) ? (int)$_GET['record_id'] : null;
$ov    = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']??'');
if($ov && in_array($ov,['PUT','DELETE','PATCH'])) $meth=$ov;

/* ── records sub-resource ── */
if($zid && $recs){
    $zone = dns_get_zone($zid,$uid);
    if(!$zone) api_error('Zone not found.',404);

    if($meth==='GET'){
        $st=db()->prepare('SELECT * FROM dns_records WHERE zone_id=? ORDER BY type,name');
        $st->execute([$zid]);
        api_ok(['records'=>$st->fetchAll()?:[]]);
    }
    if($meth==='POST'){
        $b=json_decode(file_get_contents('php://input'),true)??[];
        $type=strtoupper(trim($b['type']??''));
        $name=trim($b['name']??'');
        $val =trim($b['value']??'');
        $ttl =(int)($b['ttl']??3600);
        $prio=array_key_exists('priority',$b)?(int)$b['priority']:null;
        $ok_types=['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'];
        if(!$type||!in_array($type,$ok_types)) api_error('type required. Allowed: '.implode(',',$ok_types));
        if(!$name) api_error('name required.');
        if(!$val)  api_error('value required.');
        if(empty($zone['cf_zone_id'])) api_error('Zone not yet active on Cloudflare.',422);
        try{
            $d=['type'=>$type,'name'=>$name,'content'=>$val,'ttl'=>$ttl];
            if($prio!==null) $d['priority']=$prio;
            $cf_rid=dns_add_record($zone['cf_zone_id'],$d);
            db()->prepare('INSERT INTO dns_records (zone_id,type,name,value,ttl,priority,cf_record_id) VALUES (?,?,?,?,?,?,?)')->execute([$zid,$type,$name,$val,$ttl,$prio,$cf_rid]);
            api_ok(['record'=>['id'=>(int)db()->lastInsertId(),'type'=>$type,'name'=>$name,'value'=>$val,'ttl'=>$ttl]],201);
        }catch(Throwable $e){ api_error('CF error: '.$e->getMessage(),502); }
    }
    if($meth==='DELETE'){
        if(!$rid) api_error('record_id required.');
        $st=db()->prepare('SELECT * FROM dns_records WHERE id=? AND zone_id=? LIMIT 1');
        $st->execute([$rid,$zid]);
        $rec=$st->fetch();
        if(!$rec) api_error('Record not found.',404);
        try{
            if(!empty($zone['cf_zone_id'])&&!empty($rec['cf_record_id']))
                dns_delete_record($zone['cf_zone_id'],$rec['cf_record_id']);
            db()->prepare('DELETE FROM dns_records WHERE id=?')->execute([$rid]);
            api_ok(['message'=>'Record deleted.']);
        }catch(Throwable $e){ api_error('CF error: '.$e->getMessage(),502); }
    }
    api_error('Method not allowed.',405);
}

/* ── zones ── */
if($meth==='GET'&&$zid){
    $zone=dns_get_zone($zid,$uid);
    if(!$zone) api_error('Zone not found.',404);
    $st=db()->prepare('SELECT * FROM dns_records WHERE zone_id=? ORDER BY type,name');
    $st->execute([$zid]);
    api_ok(['zone'=>fz($zone),'records'=>$st->fetchAll()?:[]]);
}
if($meth==='GET'){
    $lim=(int)($_GET['limit']??50);$lim=min($lim,200);
    $off=(int)($_GET['offset']??0);
    $c=db()->prepare('SELECT COUNT(*) FROM dns_zones WHERE user_id=? AND deleted_at IS NULL');
    $c->execute([$uid]);$total=(int)$c->fetchColumn();
    $s=db()->prepare('SELECT * FROM dns_zones WHERE user_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $s->execute([$uid,$lim,$off]);
    api_ok(['zones'=>array_map('fz',$s->fetchAll()?:[]),'meta'=>['total'=>$total,'limit'=>$lim,'offset'=>$off]]);
}
if($meth==='POST'){
    if(!dns_is_configured()) api_error('DNS not configured by admin.',503);
    $b=json_decode(file_get_contents('php://input'),true)??[];
    $dom=strtolower(trim($b['domain']??''));
    if(!$dom||!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',$dom)) api_error('Invalid domain.');
    $dup=db()->prepare('SELECT id FROM dns_zones WHERE domain=? AND user_id=? AND deleted_at IS NULL LIMIT 1');
    $dup->execute([$dom,$uid]);
    if($dup->fetch()) api_error("Zone '{$dom}' already exists.",409);
    try{
        $cf=dns_add_zone($dom);
        db()->prepare('INSERT INTO dns_zones (user_id,domain,cf_zone_id,nameservers,status) VALUES (?,?,?,?,?)')->execute([$uid,$dom,$cf['cf_zone_id'],$cf['nameservers'],$cf['status']]);
        api_ok(['zone'=>fz(dns_get_zone((int)db()->lastInsertId(),$uid))],201);
    }catch(Throwable $e){ api_error('CF error: '.$e->getMessage(),502); }
}
if($meth==='DELETE'&&$zid){
    $zone=dns_get_zone($zid,$uid);
    if(!$zone) api_error('Zone not found.',404);
    try{
        if(!empty($zone['cf_zone_id'])) dns_delete_zone($zone['cf_zone_id']);
        db()->prepare('UPDATE dns_zones SET deleted_at=NOW() WHERE id=?')->execute([$zid]);
        api_ok(['message'=>'Zone deleted.']);
    }catch(Throwable $e){ api_error('CF error: '.$e->getMessage(),502); }
}
api_error('Method not allowed.',405);

function fz(array $z):array{
    return['id'=>(int)$z['id'],'domain'=>$z['domain'],'status'=>$z['status'],
           'cf_zone_id'=>$z['cf_zone_id']??null,
           'nameservers'=>json_decode($z['nameservers']??'[]',true),
           'created_at'=>$z['created_at']];
}
