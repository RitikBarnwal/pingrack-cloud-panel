<?php
/**
 * api/v1/billing.php
 * GET /api/v1/billing                       — wallet balance + summary
 * GET /api/v1/billing?type=transactions     — tx history [?limit=&page=&from=&to=]
 * GET /api/v1/billing?type=invoices         — invoices   [?limit=&page=]
 * GET /api/v1/billing?type=usage            — per-service usage [?month=YYYY-MM]
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/_auth.php';

if($_SERVER['REQUEST_METHOD']!=='GET') api_error('Method not allowed.',405);

$auth=$api_auth=api_auth();
$uid=(int)$auth['user_id'];
$type=$_GET['type']??'';

/* transactions */
if($type==='transactions'){
    $lim=min((int)($_GET['limit']??20),200);
    $pg=max(1,(int)($_GET['page']??1));
    $off=($pg-1)*$lim;
    $from=$_GET['from']??null;$to=$_GET['to']??null;
    $w=['user_id=?'];$p=[$uid];
    if($from){$w[]='created_at>=?';$p[]=$from.' 00:00:00';}
    if($to){$w[]='created_at<=?';$p[]=$to.' 23:59:59';}
    $wh=implode(' AND ',$w);
    $c=db()->prepare("SELECT COUNT(*) FROM transactions WHERE $wh");$c->execute($p);$tot=(int)$c->fetchColumn();
    $p[]=$lim;$p[]=$off;
    $s=db()->prepare("SELECT id,type,amount,currency,description,ref_type,ref_id,balance_before,balance_after,created_at FROM transactions WHERE $wh ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $s->execute($p);$rows=$s->fetchAll()?:[];
    api_ok(['transactions'=>array_map(fn($t)=>['id'=>(int)$t['id'],'type'=>$t['type'],'amount'=>(float)$t['amount'],'currency'=>$t['currency'],'description'=>$t['description'],'ref_type'=>$t['ref_type']??null,'balance_before'=>(float)$t['balance_before'],'balance_after'=>(float)$t['balance_after'],'created_at'=>$t['created_at']],$rows),
            'meta'=>['total'=>$tot,'limit'=>$lim,'page'=>$pg,'pages'=>(int)ceil($tot/$lim)]]);
}

/* invoices */
if($type==='invoices'){
    $lim=min((int)($_GET['limit']??20),100);
    $pg=max(1,(int)($_GET['page']??1));$off=($pg-1)*$lim;
    $c=db()->prepare('SELECT COUNT(*) FROM invoices WHERE user_id=?');$c->execute([$uid]);$tot=(int)$c->fetchColumn();
    $s=db()->prepare('SELECT id,invoice_no,amount,currency,status,type,period_start,period_end,gst_amount,created_at FROM invoices WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $s->execute([$uid,$lim,$off]);$rows=$s->fetchAll()?:[];
    api_ok(['invoices'=>array_map(fn($i)=>['id'=>(int)$i['id'],'invoice_no'=>$i['invoice_no'],'amount'=>(float)$i['amount'],'currency'=>$i['currency'],'status'=>$i['status'],'type'=>$i['type']??'service','gst_amount'=>(float)($i['gst_amount']??0),'period_start'=>$i['period_start']??null,'period_end'=>$i['period_end']??null,'download_url'=>BASE_URL.'/invoices/'.$i['id'].'.pdf','created_at'=>$i['created_at']],$rows),
            'meta'=>['total'=>$tot,'limit'=>$lim,'page'=>$pg,'pages'=>(int)ceil($tot/$lim)]]);
}

/* usage summary */
if($type==='usage'){
    $month=$_GET['month']??date('Y-m');
    if(!preg_match('/^\d{4}-\d{2}$/',$month)) api_error('month must be YYYY-MM.');
    $from=$month.'-01 00:00:00';
    $to=date('Y-m-t 23:59:59',strtotime($from));
    $cur=strtoupper($auth['currency']??'INR');
    $s=db()->prepare("SELECT ref_type,ABS(SUM(amount)) AS total FROM transactions WHERE user_id=? AND type='debit' AND created_at BETWEEN ? AND ? GROUP BY ref_type");
    $s->execute([$uid,$from,$to]);$rows=$s->fetchAll()?:[];
    $br=['vps'=>0,'smtp'=>0,'proxy'=>0,'dns'=>0,'storage'=>0,'other'=>0];$gt=0;
    foreach($rows as $r){
        $rt=strtolower($r['ref_type']??'other');$amt=(float)$r['total'];$gt+=$amt;
        if(str_contains($rt,'server')||str_contains($rt,'vps')) $br['vps']+=$amt;
        elseif(str_contains($rt,'smtp'))    $br['smtp']+=$amt;
        elseif(str_contains($rt,'proxy'))   $br['proxy']+=$amt;
        elseif(str_contains($rt,'dns'))     $br['dns']+=$amt;
        elseif(str_contains($rt,'storage')) $br['storage']+=$amt;
        else $br['other']+=$amt;
    }
    api_ok(['usage'=>array_merge(array_map(fn($v)=>round($v,2),$br),['total'=>round($gt,2),'currency'=>$cur]),'month'=>$month,'period'=>['from'=>$month.'-01','to'=>date('Y-m-t',strtotime($from))]]);
}

/* default: balance */
$u=db()->prepare('SELECT wallet_balance,currency FROM users WHERE id=? LIMIT 1');$u->execute([$uid]);$row=$u->fetch();
$cur=strtoupper($row['currency']??'INR');$bal=(float)$row['wallet_balance'];
$thresh=$cur==='INR'?100:5;
$ui=db()->prepare("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM invoices WHERE user_id=? AND status='unpaid'");
$ui->execute([$uid]);[$uc,$ua]=$ui->fetch(\PDO::FETCH_NUM)?:[0,0];
$lt=db()->prepare("SELECT amount,created_at FROM transactions WHERE user_id=? AND type='credit' AND ref_type='topup' ORDER BY created_at DESC LIMIT 1");
$lt->execute([$uid]);$ltr=$lt->fetch()?:null;
api_ok(['balance'=>round($bal,2),'currency'=>$cur,'low_balance_alert'=>$bal<$thresh,'unpaid_invoices'=>(int)$uc,'unpaid_amount'=>round((float)$ua,2),'last_topup'=>$ltr?['amount'=>(float)$ltr['amount'],'date'=>$ltr['created_at']]:null]);
