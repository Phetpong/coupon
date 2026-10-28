<?php
require __DIR__.'/app.php';
try { $staff=current_staff(); } catch(PDOException $ex) { http_response_code(503); exit('ไม่สามารถเชื่อมต่อฐานข้อมูล'); }
if (!$staff) { audit_attempt('access_denied',null,null,null,['page'=>'audit.php','reason'=>'login_required']); header('Location: index.php'); exit; }
if (!is_admin($staff)) { audit_attempt('access_denied',$staff,null,null,['page'=>'audit.php','reason'=>'admin_required']); http_response_code(403); exit('ไม่มีสิทธิ์ดูประวัติการใช้งาน'); }
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET'); exit('หน้านี้ใช้สำหรับดูข้อมูลเท่านั้น'); }
$param=static fn(string $key):string=>is_string($_GET[$key]??null)?trim($_GET[$key]):'';
$from=$param('from'); $to=$param('to'); $actor=$param('actor'); $category=$param('category'); $outcome=$param('outcome');
$size=page_size($param('per_page')); $info=page_info(0,(int)$param('page'),$size);
$categories=[
    'auth'=>['label'=>'การเข้าสู่ระบบ','events'=>['login_success','login_failed','logout']],
    'coupon'=>['label'=>'การจ่ายคูปอง','events'=>['coupon_issued','coupon_id_duplicate','coupon_name_duplicate']],
    'user'=>['label'=>'การจัดการผู้ใช้','events'=>['user_created','user_updated','password_changed','user_deleted']],
    'security'=>['label'=>'การเข้าถึง','events'=>['access_denied','csrf_rejected']]
];
$labels=audit_events(); $rows=[]; $actors=[]; $error='';
$filters=['from'=>$from,'to'=>$to,'actor'=>$actor,'category'=>$category,'outcome'=>$outcome];
try {
    foreach ([$from,$to] as $value) {
        if ($value==='') continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new RuntimeException('กรุณาระบุวันที่ให้ถูกต้อง');
    }
    if ($from!=='' && $to!=='' && $from>$to) throw new RuntimeException('วันเริ่มต้นต้องไม่เกินวันสิ้นสุด');
    if ($actor!=='' && $actor!=='anonymous' && (!ctype_digit($actor) || (int)$actor<1)) throw new RuntimeException('เจ้าหน้าที่ไม่ถูกต้อง');
    if ($category!=='' && !isset($categories[$category])) throw new RuntimeException('หมวดเหตุการณ์ไม่ถูกต้อง');
    if (!in_array($outcome,['','success','denied'],true)) throw new RuntimeException('ผลลัพธ์ไม่ถูกต้อง');
    $actors=db()->query('SELECT id,name,username FROM staff ORDER BY name,username')->fetchAll();
    $deleted=db()->query('SELECT a.actor_id AS id,a.actor_name AS name,a.actor_username AS username FROM audit_logs a JOIN (SELECT actor_id,MAX(id) AS id FROM audit_logs WHERE actor_id IS NOT NULL GROUP BY actor_id) latest ON latest.id=a.id LEFT JOIN staff s ON s.id=a.actor_id WHERE s.id IS NULL ORDER BY a.actor_name')->fetchAll();
    foreach ($deleted as $entry) { $entry['name'].=' (ลบบัญชีแล้ว)'; $actors[]=$entry; }
    $where=' WHERE 1=1'; $args=[];
    if ($from!=='') { $where.=' AND a.occurred_at>=?'; $args[]=$from.' 00:00:00'; }
    if ($to!=='') { $where.=' AND a.occurred_at<=?'; $args[]=$to.' 23:59:59.999999'; }
    if ($actor==='anonymous') $where.=' AND a.actor_id IS NULL';
    elseif ($actor!=='') { $where.=' AND a.actor_id=?'; $args[]=(int)$actor; }
    if ($category!=='') { $events=$categories[$category]['events']; $where.=' AND a.event_type IN ('.implode(',',array_fill(0,count($events),'?')).')'; $args=array_merge($args,$events); }
    if ($outcome!=='') { $where.=' AND a.outcome=?'; $args[]=$outcome; }
    $q=db()->prepare('SELECT COUNT(*) FROM audit_logs a'.$where); $q->execute($args);
    $info=page_info((int)$q->fetchColumn(),(int)$param('page'),$size);
    $q=db()->prepare('SELECT a.*,s.id AS current_target_staff,c.id AS current_coupon,c.event_key AS coupon_event FROM audit_logs a LEFT JOIN staff s ON a.target_type=\'staff\' AND s.id=a.target_id LEFT JOIN coupons c ON a.target_type=\'coupon\' AND c.id=a.target_id'.$where.' ORDER BY a.occurred_at DESC,a.id DESC LIMIT '.$info['size'].' OFFSET '.$info['offset']);
    $q->execute($args); $rows=$q->fetchAll();
} catch (PDOException $ex) { error_log('Audit read failed: '.$ex->getCode()); $error='โหลดประวัติไม่ได้ กรุณาตรวจสอบฐานข้อมูลและการรัน migration'; }
catch (RuntimeException $ex) { $error=$ex->getMessage(); }
$reasons=['invalid_credentials'=>'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง','login_required'=>'ยังไม่ได้เข้าสู่ระบบ','admin_required'=>'ต้องใช้สิทธิ์แอดมิน'];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ประวัติการใช้งานระบบ</title><link rel="icon" href="<?=e(asset_url('assets/logo-small.png'))?>"><link rel="stylesheet" href="<?=e(asset_url('assets/style.css'))?>"><link rel="stylesheet" href="<?=e(asset_url('assets/audit.css'))?>"><script src="<?=e(asset_url('assets/app.js'))?>" defer></script></head><body class="audit-screen">
<?php require __DIR__.'/header.php'; ?>
<main><div class="audit-title"><h1>ประวัติการใช้งานระบบ</h1><p class="muted">ประวัติทุกงาน · เวลาไทย · อ่านอย่างเดียว</p></div>
<section class="card"><form method="get" action="audit.php#records" class="audit-filters">
<label for="audit-from">ตั้งแต่วันที่<input id="audit-from" name="from" type="date" value="<?=e($from)?>"></label>
<label for="audit-to">ถึงวันที่<input id="audit-to" name="to" type="date" value="<?=e($to)?>"></label>
<label for="audit-actor">เจ้าหน้าที่<select id="audit-actor" name="actor" aria-label="เจ้าหน้าที่"><option value="">ทุกคน</option><option value="anonymous" <?=$actor==='anonymous'?'selected':''?>>ยังไม่ยืนยันตัวตน</option><?php foreach($actors as $person): ?><option value="<?=(int)$person['id']?>" <?=$actor===(string)$person['id']?'selected':''?>><?=e($person['name'].' ('.$person['username'].')')?></option><?php endforeach ?></select></label>
<label for="audit-category">หมวดเหตุการณ์<select id="audit-category" name="category" aria-label="หมวดเหตุการณ์"><option value="">ทุกหมวด</option><?php foreach($categories as $key=>$item): ?><option value="<?=e($key)?>" <?=$category===$key?'selected':''?>><?=e($item['label'])?></option><?php endforeach ?></select></label>
<label for="audit-outcome">ผลลัพธ์<select id="audit-outcome" name="outcome" aria-label="ผลลัพธ์"><option value="">ทั้งหมด</option><option value="success" <?=$outcome==='success'?'selected':''?>>สำเร็จ</option><option value="denied" <?=$outcome==='denied'?'selected':''?>>ถูกปฏิเสธ</option></select></label>
<input type="hidden" name="per_page" value="<?=$size?>"><div class="audit-actions"><button class="secondary">แสดงผล</button><a class="quiet" href="audit.php">ล้างตัวกรอง</a></div>
</form></section>
<?php if($error): ?><p class="notice danger" role="alert" tabindex="-1" data-error-focus><?=e($error)?></p><?php else: ?>
<section class="card audit-records" id="records"><div class="history-heading"><h2>รายการใช้งาน</h2><span><?=number_format($info['total'])?> รายการ</span></div>
<?=pagination($info,'audit.php','records',$filters)?>
<div class="audit-columns" aria-hidden="true"><span>เวลา</span><span>เจ้าหน้าที่</span><span>การกระทำ</span><span>รายการที่เกี่ยวข้อง</span><span>ผลลัพธ์</span></div>
<?php foreach($rows as $row): $detail=json_decode($row['details'],true)?:[]; $time=new DateTimeImmutable($row['occurred_at']); ?>
<article class="audit-record"><div class="audit-cell"><span class="audit-mobile-label">เวลา</span><time datetime="<?=e($time->format('c'))?>"><?=e($time->format('d/m/').((int)$time->format('Y')+543))?><br><?=e($time->format('H:i:s'))?></time></div>
<div class="audit-cell"><span class="audit-mobile-label">เจ้าหน้าที่</span><strong><?=e($row['actor_name']?:'ยังไม่ยืนยันตัวตน')?></strong><?php if($row['actor_username']): ?><small><?=e($row['actor_username'])?></small><?php endif ?></div>
<div class="audit-cell"><span class="audit-mobile-label">การกระทำ</span><h3><?=e($labels[$row['event_type']]??$row['event_type'])?></h3></div>
<div class="audit-cell"><span class="audit-mobile-label">รายการที่เกี่ยวข้อง</span><?php if($row['target_type']==='staff'): ?><?php if($row['current_target_staff']): ?><a href="users.php?edit=<?=(int)$row['target_id']?>#user-form">บัญชี #<?=(int)$row['target_id']?></a><?php else: ?>บัญชี #<?=(int)$row['target_id']?> (ลบแล้ว)<?php endif ?><?php elseif($row['target_type']==='coupon' && $row['target_id']): ?><?php if($row['current_coupon'] && $row['coupon_event']===$config['event_key']): ?><a href="index.php?record=<?=(int)$row['target_id']?>#history">รายการจ่าย #<?=(int)$row['target_id']?></a><?php else: ?>รายการจ่าย #<?=(int)$row['target_id']?><?php endif ?><?php else: ?><?=e($detail['page']??'—')?><?php endif ?></div>
<div class="audit-cell"><span class="audit-mobile-label">ผลลัพธ์</span><span class="audit-badge <?=$row['outcome']==='success'?'success':'danger'?>"><?=$row['outcome']==='success'?'สำเร็จ':'ถูกปฏิเสธ'?></span></div>
<details class="audit-details"><summary>รายละเอียด #<?=(int)$row['id']?></summary><dl>
<div><dt>สิทธิ์ขณะเกิดเหตุ</dt><dd><?=e(['admin'=>'ผู้ดูแลระบบ','officer'=>'เจ้าหน้าที่'][$row['actor_role']]??'ยังไม่ยืนยันตัวตน')?></dd></div>
<div><dt>รหัสงาน</dt><dd><?=e($row['event_key'])?></dd></div>
<?php if(isset($detail['reason'])): ?><div><dt>เหตุผล</dt><dd><?=e($reasons[$detail['reason']]??$detail['reason'])?></dd></div><?php endif ?>
<?php if(isset($detail['attempted_username'])): ?><div><dt>Username ที่กรอก</dt><dd><?=e($detail['attempted_username'])?></dd></div><?php endif ?>
<?php foreach(['before'=>'ก่อนแก้ไข','after'=>'หลังแก้ไข'] as $side=>$label): ?><?php if(isset($detail[$side])): ?><div><dt><?=e($label)?></dt><dd><?php foreach(['username'=>'Username','name'=>'ชื่อ–สกุล'] as $key=>$field): ?><?php if(isset($detail[$side][$key])): ?><p><?=e($field)?>: <?=e($detail[$side][$key])?></p><?php endif ?><?php endforeach ?></dd></div><?php endif ?><?php endforeach ?>
<div><dt>IP ต้นทาง</dt><dd><?=e($row['ip_address']?:'ไม่ระบุ')?></dd></div><div><dt>เบราว์เซอร์ / อุปกรณ์</dt><dd><?=e($row['user_agent']?:'ไม่ระบุ')?></dd></div>
</dl></details></article>
<?php endforeach ?>
<?php if(!$rows): ?><p class="empty">ยังไม่มีประวัติตามตัวกรองที่เลือก</p><?php else: ?><?=pagination($info,'audit.php','records',$filters)?><?php endif ?>
</section><?php endif ?></main></body></html>
