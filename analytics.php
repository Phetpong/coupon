<?php
require __DIR__.'/app.php';
require __DIR__.'/analytics-data.php';
try { $staff=current_staff(); } catch(PDOException $e) { http_response_code(503);exit('ไม่สามารถเชื่อมต่อฐานข้อมูล'); }
if(!$staff) { audit_attempt('access_denied',null,null,null,['page'=>'analytics.php','reason'=>'login_required']); header('Location: index.php');exit; }
if(!is_admin($staff)) { audit_attempt('access_denied',$staff,null,null,['page'=>'analytics.php','reason'=>'admin_required']); http_response_code(403);exit('ไม่มีสิทธิ์ดูข้อมูลวิเคราะห์'); }
$from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));
$group=(string)($_GET['group']??'');$staffId=max(0,(int)($_GET['staff']??0));
$report=(string)($_GET['report']??'group');if(!in_array($report,['group','organization','staff','daily'],true))$report='group';
$size=page_size($_GET['per_page']??10);$error='';$staffOptions=[];
$data=['summary'=>['total'=>0,'staff_count'=>0,'day_count'=>0,'organization_count'=>0],'daily'=>[],'group'=>[],'staff'=>[],'organization'=>[]];
try {
    $staffOptions=db()->query('SELECT id,name,username FROM staff ORDER BY name,username')->fetchAll();
    if($group!=='' && !in_array($group,$groups,true)) throw new RuntimeException('กรุณาเลือกกลุ่มที่ถูกต้อง');
    $data=analytics_data(db(),$config['event_key'],$from,$to,$group,$staffId);
} catch(PDOException $e) { error_log('Analytics database error: '.$e->getCode());$error='โหลดข้อมูลวิเคราะห์ไม่ได้ กรุณาลองใหม่'; }
catch(RuntimeException $e) { $error=$e->getMessage(); }
$summary=$data['summary'];$reportRows=$data[$report];
if($report==='daily')$reportRows=array_reverse($reportRows);
$page=page_info(count($reportRows),(int)($_GET['page']??1),$size);
$visible=array_slice($reportRows,$page['offset'],$page['size']);
$filters=['from'=>$from,'to'=>$to,'group'=>$group,'staff'=>$staffId,'report'=>$report];
$peak=max([1,...array_column($data['daily'],'total')]);
$dailyChart=array_slice($data['daily'],-14);
$reportNames=['group'=>'ตามกลุ่มผู้เข้าร่วม','organization'=>'ตามฟาร์ม / ซุ้ม / หน่วยงาน','staff'=>'ตามเจ้าหน้าที่','daily'=>'ตามวันที่'];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>วิเคราะห์ข้อมูล · <?=e($config['event'])?></title><link rel="icon" type="image/png" href="<?=e(asset_url('assets/logo-small.png'))?>"><link rel="stylesheet" href="<?=e(asset_url('assets/style.css'))?>"><link rel="stylesheet" href="<?=e(asset_url('assets/analytics.css'))?>"><script src="<?=e(asset_url('assets/app.js'))?>" defer></script></head><body class="analytics-screen">
<?php require __DIR__.'/header.php'; ?>
<main class="analytics-page"><div class="analytics-title"><h1>วิเคราะห์การจ่ายคูปอง</h1><p><?=e($config['event'])?></p></div>
<section class="card"><form method="get" action="analytics.php" class="analytics-filters">
<div class="date-range"><label for="date-from">ตั้งแต่วันที่<input id="date-from" type="date" name="from" value="<?=e($from)?>"></label><label for="date-to">ถึงวันที่<input id="date-to" type="date" name="to" value="<?=e($to)?>"></label></div>
<label for="analytics-group">กลุ่มผู้เข้าร่วม<select id="analytics-group" name="group" aria-label="กลุ่มผู้เข้าร่วม"><option value="">ทุกกลุ่ม</option><?php foreach($groups as $item): ?><option value="<?=e($item)?>" <?=$group===$item?'selected':''?>><?=e($item)?></option><?php endforeach ?></select></label>
<label for="analytics-staff">เจ้าหน้าที่<select id="analytics-staff" name="staff" aria-label="เจ้าหน้าที่"><option value="0">ทุกคน</option><?php foreach($staffOptions as $item): ?><option value="<?=(int)$item['id']?>" <?=$staffId===(int)$item['id']?'selected':''?>><?=e($item['name'].' ('.$item['username'].')')?></option><?php endforeach ?></select></label>
<input type="hidden" name="report" value="<?=e($report)?>"><input type="hidden" name="per_page" value="<?=$size?>">
<div class="analytics-actions"><button class="primary">แสดงผล</button><a class="quiet" href="analytics.php">ล้างตัวกรอง</a></div>
</form></section>
<?php if($error): ?><p class="notice danger" role="alert" tabindex="-1" data-error-focus><?=e($error)?></p><?php else: ?>
<p class="analytics-scope"><?=$from!==''?e(analytics_day($from)):'เริ่มต้นงาน'?> – <?=$to!==''?e(analytics_day($to)):'ล่าสุด'?> · เวลาไทย</p>
<section class="analytics-metrics" aria-label="สรุปตามตัวกรอง">
<?php foreach(['total'=>'จ่ายคูปองแล้ว','staff_count'=>'เจ้าหน้าที่ที่บันทึก','organization_count'=>'หน่วยงานที่ระบุ','day_count'=>'วันที่มีการจ่าย'] as $key=>$label): ?><div><span><?=e($label)?></span><strong><?=number_format((int)$summary[$key])?></strong><small><?=$key==='day_count'?'วัน':($key==='organization_count'?'แห่ง':'คน')?></small></div><?php endforeach ?>
</section>
<div class="analytics-workspace">
<section class="card analytics-section analytics-trend"><div class="history-heading"><h2>แนวโน้มรายวัน</h2><span>ล่าสุดไม่เกิน 14 วันที่มีการจ่าย</span></div>
<?php if(!$dailyChart): ?><p class="empty">ไม่มีข้อมูลในช่วงที่เลือก</p><?php else: ?><div class="analytics-chart"><?php foreach($dailyChart as $day): ?><div class="analytics-bar"><div><span><?=e(analytics_day($day['label']))?></span><strong><?=number_format((int)$day['total'])?> คน</strong></div><progress value="<?=(int)$day['total']?>" max="<?=$peak?>" aria-label="<?=e(analytics_day($day['label']))?> จำนวน <?=(int)$day['total']?> คน"><?=(int)$day['total']?></progress></div><?php endforeach ?></div><?php endif ?></section>
<section class="card analytics-section" id="breakdown"><div class="analytics-detail-heading"><h2>รายละเอียดการจ่าย</h2>
<form method="get" action="analytics.php#breakdown" class="analytics-report-form"><?php foreach($filters as $key=>$value): ?><?php if($key!=='report'): ?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endif ?><?php endforeach ?><input type="hidden" name="per_page" value="<?=$size?>"><label for="analytics-report">แสดงข้อมูล<select id="analytics-report" name="report" aria-label="รูปแบบรายงาน"><?php foreach($reportNames as $key=>$label): ?><option value="<?=e($key)?>" <?=$report===$key?'selected':''?>><?=e($label)?></option><?php endforeach ?></select></label><button class="secondary">แสดง</button></form>
</div>
<div class="history-list analytics-mobile-records"><?php foreach($visible as $row): ?><article class="analytics-result"><div><h3><?=e($report==='daily'?analytics_day($row['label']):$row['label'])?></h3><?php if(isset($row['detail'])): ?><p><?=e($row['detail'])?></p><?php endif ?></div><div class="analytics-result-number"><strong><?=number_format((int)$row['total'])?></strong><span>คน · <?=$summary['total']?number_format(100*(int)$row['total']/(int)$summary['total'],1):'0'?>%</span></div></article><?php endforeach ?><?php if(!$visible): ?><p class="empty">ไม่พบรายการ</p><?php endif ?></div>
<div class="analytics-table-wrap" tabindex="0" role="region" aria-label="ตารางรายละเอียดการจ่าย เลื่อนดูรายการเพิ่มเติม"><table class="analytics-table"><caption><?=e($reportNames[$report])?></caption><thead><tr><th scope="col"><?=e($reportNames[$report])?></th><th scope="col">จำนวน (คน)</th><th scope="col">สัดส่วน</th></tr></thead><tbody>
<?php foreach($visible as $row): ?><tr><th scope="row"><?=e($report==='daily'?analytics_day($row['label']):$row['label'])?><?php if(isset($row['detail'])): ?><small><?=e($row['detail'])?></small><?php endif ?></th><td><?=number_format((int)$row['total'])?></td><td><?=$summary['total']?number_format(100*(int)$row['total']/(int)$summary['total'],1):'0'?>%</td></tr><?php endforeach ?>
<?php if(!$visible): ?><tr><td colspan="3" class="empty">ไม่พบรายการ</td></tr><?php endif ?>
</tbody></table></div>
<?php if($visible): ?><?=pagination($page,'analytics.php','breakdown',$filters)?><?php endif ?>
<p class="muted">สัดส่วนเทียบยอดตามตัวกรอง · หน่วยงานชื่อเดียวกันต่างกลุ่มนับแยกกัน</p></section></div>
<?php endif ?></main></body></html>
