<?php
require __DIR__.'/app.php';
$error = ''; $result = null; $rows = []; $organizations = []; $total = 0; $today = 0;
$filterGroup=trim((string)($_GET['filter_group']??''));
$filterOrg=clean_text((string)($_GET['filter_organization']??''));
$search=trim((string)($_GET['search']??''));
$perPage=page_size($_GET['per_page']??10);
$history=page_info(0,1,$perPage);
if (isset($_SESSION['staff']) && time()-($_SESSION['activity']??0)>7200) unset($_SESSION['staff']);
if (isset($_SESSION['staff'])) $_SESSION['activity'] = time();
try {
    current_staff();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf']??''))) throw new RuntimeException('หน้าฟอร์มหมดอายุ กรุณารีเฟรชแล้วลองใหม่');
        $action = $_POST['action']??'';
        if ($action === 'logout') { $_SESSION = []; session_destroy(); header('Location: index.php'); exit; }
        if ($action === 'login') {
            usleep(400000);
            $q=db()->prepare('SELECT * FROM staff WHERE username=?'); $q->execute([trim($_POST['username']??'')]); $staff=$q->fetch();
            if (!$staff || !password_verify($_POST['password']??'', $staff['password_hash'])) throw new RuntimeException('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            session_regenerate_id(true); $_SESSION['staff']=['id'=>$staff['id'],'name'=>$staff['name']]; $_SESSION['activity']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));
            header('Location: index.php'); exit;
        }
        if ($action === 'issue') {
            if (!isset($_SESSION['staff'])) throw new RuntimeException('กรุณาเข้าสู่ระบบก่อนบันทึก');
            $id=trim($_POST['citizen_id']??''); $name=clean_text($_POST['full_name']??''); $group=$_POST['group_name']??''; $org=clean_text($_POST['organization']??'');
            if (!valid_id($id)) throw new RuntimeException('เลขบัตรประชาชนไม่ถูกต้อง กรุณาตรวจสอบเลข 13 หลัก');
            if ($name==='' || mb_strlen($name)>200 || mb_strlen($org)>200 || !in_array($group,$groups,true)) throw new RuntimeException('กรุณากรอกชื่อและเลือกกลุ่มผู้เข้าร่วมให้ถูกต้อง');
            if (!preg_match('/^\S+\s+\S+/u',normalized_name($name))) throw new RuntimeException('กรุณากรอกชื่อและนามสกุล โดยเว้นวรรคระหว่างชื่อกับนามสกุล');
            try {
                $q=db()->prepare('INSERT INTO coupons (event_key,citizen_id,full_name,name_key,group_name,organization,staff_id) VALUES (?,?,?,?,?,?,?)');
                $q->execute([$config['event_key'],$id,$name,name_key($name),$group,$org,$_SESSION['staff']['id']]);
                $_SESSION['receipt']=['kind'=>'success','id'=>db()->lastInsertId()];
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1]??0)!==1062) throw $ex;
                $q=db()->prepare('SELECT id FROM coupons WHERE event_key=? AND citizen_id=?'); $q->execute([$config['event_key'],$id]);
                $existing=$q->fetchColumn();
                if (!$existing) {
                    $q=db()->prepare('SELECT id FROM coupons WHERE event_key=? AND name_key=?'); $q->execute([$config['event_key'],name_key($name)]);
                    $existing=$q->fetchColumn();
                    $_SESSION['receipt']=['kind'=>'name_duplicate','id'=>$existing];
                } else $_SESSION['receipt']=['kind'=>'duplicate','id'=>$existing];
            }
            header('Location: index.php'); exit;
        }
    }
    if (isset($_SESSION['staff'])) {
        if (isset($_SESSION['receipt'])) {
            $receipt=$_SESSION['receipt']; unset($_SESSION['receipt']);
            $q=db()->prepare('SELECT c.*,s.name AS staff_name FROM coupons c JOIN staff s ON s.id=c.staff_id WHERE c.id=? AND c.event_key=?'); $q->execute([$receipt['id'],$config['event_key']]);
            $result=$q->fetch(); if ($result) $result['kind']=$receipt['kind'];
        }
        $q=db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(DATE(issued_at)=CURRENT_DATE()),0) AS today FROM coupons WHERE event_key=?'); $q->execute([$config['event_key']]); $stats=$q->fetch(); $total=$stats['total']; $today=$stats['today'];
        $history=history_records(db(),$config['event_key'],$filterGroup,$filterOrg,$search,max(1,(int)($_GET['page']??1)),$perPage);
        $rows=$history['rows'];
    }
} catch (PDOException $ex) { error_log('Coupon database error: '.$ex->getCode()); $error='เชื่อมต่อฐานข้อมูลไม่ได้ หรือยังไม่ได้ติดตั้งระบบ กรุณาแจ้งผู้ดูแล'; }
catch (RuntimeException $ex) { $error=$ex->getMessage(); }
if (isset($_SESSION['staff'])) {
    try {
        $q=db()->prepare("SELECT DISTINCT group_name,organization FROM coupons WHERE event_key=? AND organization<>'' ORDER BY organization");
        $q->execute([$config['event_key']]);
        $seen=[];
        foreach ($q as $organization) {
            $organization['organization']=clean_text($organization['organization']);
            $key=$organization['group_name'].':'.mb_strtolower($organization['organization'],'UTF-8');
            if ($organization['organization']!=='' && !isset($seen[$key])) { $organizations[]=$organization; $seen[$key]=true; }
        }
    } catch (PDOException $ex) { error_log('Organization list unavailable: '.$ex->getCode()); }
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ระบบจ่ายคูปอง · <?=e($config['event'])?></title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body>
<?php require __DIR__.'/header.php'; ?>
<main><section class="hero"><h1><?=e($config['event'])?></h1></section>
<?php if($error): ?><div class="notice danger" role="alert"><?=e($error)?></div><?php endif ?>
<?php if(!isset($_SESSION['staff'])): ?>
<section class="card login"><h2>เข้าสู่ระบบ</h2><form method="post"><?=csrf()?><input type="hidden" name="action" value="login"><label>ชื่อผู้ใช้<input name="username" required autocomplete="username" maxlength="80"></label><label>รหัสผ่าน<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">เข้าสู่ระบบเจ้าหน้าที่</button></form></section>
<?php else: ?>

<section class="stats"><div><span>จ่ายคูปองทั้งหมด</span><strong><?=number_format((int)$total)?><small> คน</small></strong></div><div><span>จ่ายคูปองวันนี้</span><strong><?=number_format((int)$today)?><small> คน</small></strong></div></section>
<?php if($result): ?><section role="status" class="notice <?=$result['kind']==='success'?'success':'danger'?>"><h2><?=$result['kind']==='success'?'✓ บันทึกสำเร็จ สามารถมอบคูปองได้':($result['kind']==='name_duplicate'?'พบชื่อ–นามสกุลซ้ำ แต่เลขบัตรต่างกัน กรุณาตรวจสอบกับผู้ดูแลก่อนจ่าย':'รับคูปองแล้ว — ไม่สามารถรับซ้ำได้')?></h2><p><?=e($result['full_name'])?> · <?=e($result['citizen_id'])?></p><p><?=e($result['issued_at'])?><br>ผู้บันทึก: <?=e($result['staff_name'])?></p></section><?php endif ?>
<div class="layout"><section class="card"><div class="section-title"><div><h2>บันทึกการจ่ายคูปอง</h2></div></div>
<form method="post" id="issue-form"><?=csrf()?><input type="hidden" name="action" value="issue">
<label for="group_name">กลุ่มผู้เข้าร่วม <em>*</em></label><select id="group_name" name="group_name" required><option value="">เลือกกลุ่มผู้เข้าร่วม</option><?php foreach($groups as $group): ?><option <?=($_POST['group_name']??'')===$group?'selected':''?>><?=e($group)?></option><?php endforeach ?></select>
<label for="organization-picker">ชื่อฟาร์ม / ซุ้ม / หน่วยงาน <span class="muted">(ถ้ามี)</span></label>
<select id="organization-picker" aria-label="เลือกฟาร์ม ซุ้ม หรือหน่วยงาน" hidden><option value="">เลือกหน่วยงาน</option></select>
<div id="organization-entry"><label for="organization" class="muted">ชื่อหน่วยงานใหม่</label><input id="organization" name="organization" autocomplete="off" maxlength="200" placeholder="พิมพ์ชื่อหน่วยงาน" value="<?=e($_POST['organization']??'')?>"></div>
<template id="organization-source"><?php foreach($organizations as $organization): ?><option data-group="<?=e($organization['group_name'])?>" value="<?=e($organization['organization'])?>"></option><?php endforeach ?></template>
<output id="organization-count" class="organization-count" aria-live="polite" hidden></output>
<button type="button" class="view-members" id="view-members" hidden>ดูรายชื่อที่ลงทะเบียนแล้ว ↓</button>
<label for="citizen_id">เลขบัตรประจำตัวประชาชน <em>*</em></label><input id="citizen_id" name="citizen_id" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" required autocomplete="off" placeholder="กรอกเลขบัตร 13 หลัก" value="<?=e($_POST['citizen_id']??'')?>">
<label for="full_name">ชื่อ–นามสกุล <em>*</em></label><input id="full_name" name="full_name" required maxlength="200" placeholder="คำนำหน้า ชื่อ และนามสกุล" value="<?=e($_POST['full_name']??'')?>">
<button class="primary" id="issue-button">ยืนยันบันทึกและจ่ายคูปอง</button></form></section>
</div>
<section class="card history" id="history">
<div class="history-heading"><h2>ประวัติการจ่ายคูปอง</h2><span>พบ <?=$history['total']?> คน</span></div>
<form method="get" action="index.php#history" class="search history-search" id="history-filters">
<input type="hidden" name="per_page" value="<?=$perPage?>">
<div class="history-filter-fields">
<label for="filter-group">กลุ่ม<select id="filter-group" name="filter_group"><option value="">ทุกกลุ่ม</option><?php foreach($groups as $group): ?><option value="<?=e($group)?>" <?=$filterGroup===$group?'selected':''?>><?=e($group)?></option><?php endforeach ?></select></label>
<label for="filter-organization">ฟาร์ม / ซุ้ม / หน่วยงาน<select id="filter-organization" name="filter_organization"><option value="">ทุกหน่วยงาน</option><?php $filterNames=[]; foreach($organizations as $organization): ?><?php if(($filterGroup==='' || $organization['group_name']===$filterGroup) && !isset($filterNames[$organization['organization']])): $filterNames[$organization['organization']]=true; ?><option value="<?=e($organization['organization'])?>" <?=$filterOrg===$organization['organization']?'selected':''?>><?=e($organization['organization'])?></option><?php endif ?><?php endforeach ?><?php if($filterOrg!=='' && !isset($filterNames[$filterOrg])): ?><option value="<?=e($filterOrg)?>" selected><?=e($filterOrg)?></option><?php endif ?></select></label>
</div>
<input name="search" aria-label="ค้นหาชื่อหรือเลขบัตรประชาชน" placeholder="ค้นหาชื่อ / เลขบัตร" value="<?=e($_GET['search']??'')?>">
<button class="secondary">ค้นหา</button><a href="index.php#history" class="quiet history-nav">ทั้งหมด</a>
</form>
<?php if($filterGroup!=='' || $filterOrg!==''): ?><p class="filter-summary"><?=e($filterGroup?:'ทุกกลุ่ม')?><?= $filterOrg!==''?' · '.e($filterOrg):'' ?></p><?php endif ?>
<?=pagination($history,'index.php','history',['filter_group'=>$filterGroup,'filter_organization'=>$filterOrg,'search'=>$search],true)?>
<div class="history-list">
<?php foreach($rows as $row): ?>
<article class="history-item">
<div class="recipient"><h3><?=e($row['full_name'])?></h3><p class="recipient-id">เลขบัตร <?=e($row['citizen_id'])?></p></div>
<dl class="recipient-details"><div><dt>กลุ่ม</dt><dd><?=e($row['group_name'])?></dd></div><?php if($row['organization']!==''): ?><div><dt>หน่วยงาน</dt><dd><?=e($row['organization'])?></dd></div><?php endif ?></dl>
<div class="record-meta"><time datetime="<?=e(str_replace(' ','T',$row['issued_at']).'+07:00')?>"><?=e(date('d/m/',strtotime($row['issued_at'])).((int)date('Y',strtotime($row['issued_at']))+543))?> · <?=e(date('H:i',strtotime($row['issued_at'])))?> น.</time><span>ผู้บันทึก <b><?=e($row['staff_name'])?></b></span></div>
</article>
<?php endforeach ?>
<?php if(!$rows): ?><p class="empty">ไม่พบรายการ</p><?php endif ?>
</div>
<?php if(count($rows)>0): ?><?=pagination($history,'index.php','history',['filter_group'=>$filterGroup,'filter_organization'=>$filterOrg,'search'=>$search],true)?><?php endif ?>
</section>
<?php endif ?></main></body></html>
