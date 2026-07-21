<?php
/**
 * api/v1/smtp.php
 * GET  /api/v1/smtp                        — list SMTP orders
 * GET  /api/v1/smtp?id=N                   — single account
 * GET  /api/v1/smtp?id=N&stats=1           — delivery stats
 * GET  /api/v1/smtp?id=N&dns_records=1     — DKIM/SPF/DMARC records
 * POST /api/v1/smtp?id=N&test=1            — send test email {to}
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/_auth.php';

$auth = api_auth();
$uid  = (int)$auth['user_id'];
$meth = $_SERVER['REQUEST_METHOD'];
$id   = isset($_GET['id']) ? (int)$_GET['id'] : null;

/* dns_records */
if($meth==='GET'&&$id&&isset($_GET['dns_records'])){
    $o=sg($id,$uid);$records=[];
    if(!empty($o['dkim_tokens'])){
        $tokens=json_decode($o['dkim_tokens'],true)??[];
        foreach($tokens as $t){ $records[]=['type'=>'CNAME','name'=>$t['Name']??'','value'=>$t['Value']??'']; }
    }
    if(!empty($o['domain'])){
        $records[]=['type'=>'TXT','name'=>'@','value'=>'v=spf1 include:amazonses.com ~all'];
        $records[]=['type'=>'TXT','name'=>'_dmarc','value'=>'v=DMARC1; p=quarantine; rua=mailto:dmarc@'.$o['domain']];
    }
    api_ok(['records'=>$records,'domain'=>$o['domain']??null]);
}

/* stats */
if($meth==='GET'&&$id&&isset($_GET['stats'])){
    $o=sg($id,$uid);
    api_ok(['stats'=>['emails_month_limit'=>(int)($o['emails_month']??0),'domain'=>$o['domain']??null,'domain_verified'=>!empty($o['domain_added_at']),'status'=>$o['status']]]);
}

/* test email */
if($meth==='POST'&&$id&&isset($_GET['test'])){
    $o=sg($id,$uid);
    if($o['status']!=='active') api_error('Account not active.',422);
    if(empty($o['smtp_host']))  api_error('SMTP credentials not provisioned yet.',422);
    $b=json_decode(file_get_contents('php://input'),true)??[];
    $to=trim($b['to']??$auth['email']??'');
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) api_error('Invalid email in "to".');
    // Use PHPMailer if available, else raw SMTP
    if(class_exists('\PHPMailer\PHPMailer\PHPMailer')){
        try{
            $m=new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();$m->Host=$o['smtp_host'];$m->SMTPAuth=true;
            $m->Username=$o['smtp_username'];$m->Password=$o['smtp_password'];
            $m->SMTPSecure='tls';$m->Port=(int)($o['smtp_port']??587);
            $m->setFrom($o['smtp_username'],APP_NAME);
            $m->addAddress($to);
            $m->Subject='Test Email from '.APP_NAME;
            $m->Body='This is a test email sent via the '.APP_NAME.' API.';
            $m->send();
            api_ok(['message'=>'Test email sent to '.$to.'.']);
        }catch(Throwable $e){ api_error('SMTP error: '.$e->getMessage(),502); }
    }
    api_error('PHPMailer not available on this server.',503);
}

/* single */
if($meth==='GET'&&$id){ api_ok(['account'=>fs(sg($id,$uid))]); }

/* list */
if($meth==='GET'){
    $lim=min((int)($_GET['limit']??20),100);$off=(int)($_GET['offset']??0);
    $st=$_GET['status']??null;
    $w=['o.user_id=?'];$p=[$uid];
    if($st){$w[]='o.status=?';$p[]=$st;}
    $wh=implode(' AND ',$w);
    $c=db()->prepare("SELECT COUNT(*) FROM smtp_orders o WHERE $wh");$c->execute($p);$tot=(int)$c->fetchColumn();
    $p[]=$lim;$p[]=$off;
    $s=db()->prepare("SELECT o.*,p.name AS plan_name,p.emails_month FROM smtp_orders o JOIN smtp_plans p ON p.id=o.plan_id WHERE $wh ORDER BY o.created_at DESC LIMIT ? OFFSET ?");
    $s->execute($p);
    api_ok(['accounts'=>array_map('fs',$s->fetchAll()?:[]),'meta'=>['total'=>$tot,'limit'=>$lim,'offset'=>$off]]);
}
api_error('Method not allowed.',405);

function sg(int $id,int $uid):array{
    $s=db()->prepare('SELECT o.*,p.name AS plan_name,p.emails_month FROM smtp_orders o JOIN smtp_plans p ON p.id=o.plan_id WHERE o.id=? AND o.user_id=? LIMIT 1');
    $s->execute([$id,$uid]);$r=$s->fetch();
    if(!$r) api_error('SMTP account not found.',404);
    return $r;
}
function fs(array $o):array{
    $a=$o['status']==='active';
    return['id'=>(int)$o['id'],'plan'=>$o['plan_name']??null,'status'=>$o['status'],'domain'=>$o['domain']??null,
           'domain_verified'=>!empty($o['domain_added_at']),
           'smtp_host'=>$a?($o['smtp_host']??null):null,'smtp_port'=>$a?(int)($o['smtp_port']??587):null,
           'smtp_username'=>$a?($o['smtp_username']??null):null,
           'emails_month'=>(int)($o['emails_month']??0),'order_ref'=>$o['order_ref']??null,
           'expires_at'=>$o['expires_at']??null,'created_at'=>$o['created_at']];
}
