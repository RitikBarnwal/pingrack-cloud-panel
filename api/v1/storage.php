<?php
/**
 * api/v1/storage.php
 * GET    /api/v1/storage                          — list buckets
 * GET    /api/v1/storage?id=N                     — single bucket
 * DELETE /api/v1/storage?id=N                     — delete bucket
 * GET    /api/v1/storage?id=N&objects=1           — list objects [?prefix=&limit=]
 * DELETE /api/v1/storage?id=N&objects=1&key=K     — delete object
 * GET    /api/v1/storage?id=N&presign=1&key=K     — presigned URL [?expires=3600]
 * GET    /api/v1/storage?id=N&usage=1             — usage stats
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/../../includes/storage.php';
require_once __DIR__.'/_auth.php';

$auth = api_auth();
$uid  = (int)$auth['user_id'];
$meth = $_SERVER['REQUEST_METHOD'];
$id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$ov   = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']??'');
if($ov&&$ov==='DELETE') $meth=$ov;

/* usage */
if($meth==='GET'&&$id&&isset($_GET['usage'])){
    $b=sbg($id,$uid);
    $used=storage_sync_usage((int)$b['id'],$b['name'],$b['region']);
    api_ok(['usage'=>['bucket'=>$b['name'],'used_gb'=>round($used,4),'plan_gb'=>(int)($b['storage_gb']??0),'used_pct'=>storage_pct($used,(int)($b['storage_gb']??1)),'endpoint_url'=>$b['endpoint_url']??null]]);
}

/* presign */
if($meth==='GET'&&$id&&isset($_GET['presign'])){
    $key=trim($_GET['key']??'');
    if(!$key) api_error('"key" required.');
    $exp=min((int)($_GET['expires']??3600),86400);
    $b=sbg($id,$uid);
    if($b['status']!=='active') api_error('Bucket not active.',422);
    try{
        $minio=storage_minio_for($b['region']);
        $url=$minio->presignedGetObject($b['name'],$key,$exp);
        api_ok(['url'=>$url,'expires_at'=>date('c',time()+$exp),'expires_in'=>$exp]);
    }catch(Throwable $e){ api_error('Storage error: '.$e->getMessage(),502); }
}

/* list objects */
if($meth==='GET'&&$id&&isset($_GET['objects'])){
    $b=sbg($id,$uid);
    if($b['status']!=='active') api_error('Bucket not active.',422);
    $prefix=$_GET['prefix']??'';
    $lim=min((int)($_GET['limit']??100),1000);
    try{
        $minio=storage_minio_for($b['region']);
        $objs=$minio->listObjects($b['name'],$prefix,$lim);
        api_ok(['objects'=>$objs,'prefix'=>$prefix,'bucket'=>$b['name']]);
    }catch(Throwable $e){ api_error('Storage error: '.$e->getMessage(),502); }
}

/* delete object */
if($meth==='DELETE'&&$id&&isset($_GET['objects'])){
    $key=trim($_GET['key']??'');
    if(!$key) api_error('"key" required.');
    $b=sbg($id,$uid);
    try{
        $minio=storage_minio_for($b['region']);
        $minio->removeObject($b['name'],$key);
        api_ok(['message'=>'Object deleted.','key'=>$key]);
    }catch(Throwable $e){ api_error('Storage error: '.$e->getMessage(),502); }
}

/* single bucket */
if($meth==='GET'&&$id){ api_ok(['bucket'=>fb(sbg($id,$uid))]); }

/* list buckets */
if($meth==='GET'){
    $lim=min((int)($_GET['limit']??20),100);$off=(int)($_GET['offset']??0);
    $c=db()->prepare('SELECT COUNT(*) FROM storage_buckets WHERE user_id=? AND deleted_at IS NULL');
    $c->execute([$uid]);$tot=(int)$c->fetchColumn();
    $s=db()->prepare('SELECT b.*,sp.storage_gb FROM storage_buckets b LEFT JOIN storage_plans sp ON sp.id=b.plan_id WHERE b.user_id=? AND b.deleted_at IS NULL ORDER BY b.created_at DESC LIMIT ? OFFSET ?');
    $s->execute([$uid,$lim,$off]);
    api_ok(['buckets'=>array_map('fb',$s->fetchAll()?:[]),'meta'=>['total'=>$tot,'limit'=>$lim,'offset'=>$off]]);
}

/* delete bucket */
if($meth==='DELETE'&&$id){
    sbg($id,$uid); // verify ownership
    try{
        storage_delete_bucket($id,$uid);
        api_ok(['message'=>'Bucket deleted.']);
    }catch(Throwable $e){ api_error('Storage error: '.$e->getMessage(),502); }
}
api_error('Method not allowed.',405);

function sbg(int $id,int $uid):array{
    $b=storage_get_bucket($id,$uid);
    if(!$b) api_error('Bucket not found.',404);
    return $b;
}
function fb(array $b):array{
    return['id'=>(int)$b['id'],'name'=>$b['name'],'display_name'=>$b['display_name']??$b['name'],
           'region'=>$b['region'],'status'=>$b['status'],'endpoint_url'=>$b['endpoint_url']??null,
           'access_key'=>$b['access_key']??null,'plan_gb'=>(int)($b['storage_gb']??0),
           'used_gb'=>round((float)($b['usage_gb']??0),4),'public'=>(bool)($b['is_public']??false),
           'price_monthly'=>(float)($b['price_monthly']??0),'currency'=>$b['currency']??'INR','created_at'=>$b['created_at']];
}
