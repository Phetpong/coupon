<?php
require __DIR__.'/app.php';
require __DIR__.'/user-management.php';
try { $staff=current_staff(); } catch(PDOException $e) { http_response_code(503); exit('ไม่สามารถเชื่อมต่อฐานข้อมูล'); }
if (!$staff) { header('Location: index.php'); exit; }
if (!is_admin($staff)) { http_response_code(403); exit('ไม่มีสิทธิ์จัดการผู้ใช้'); }
$error=''; $flash=$_SESSION['user_flash']??''; unset($_SESSION['user_flash']);
$edit=['id'=>0,'username'=>'','name'=>'','role'=>'officer']; $users=[];
$userSearch=trim((string)($_GET['q']??''));
$userSize=page_size($_GET['per_page']??10);
$userPage=page_info(0,1,$userSize);
$userContext=['q'=>$userSearch,'per_page'=>$userSize,'page'=>max(1,(int)($_GET['page']??1))];
$listUrl='users.php?'.http_build_query($userContext);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('หน้าฟอร์มหมดอายุ กรุณารีเฟรช');
        $action=(string)($_POST['action']??'');
        $id=(int)($_POST['id']??0);
        if ($action==='delete' && ($_POST['confirm_delete']??'')!=='yes') throw new RuntimeException('กรุณายืนยันการลบผู้ใช้');
        db()->beginTransaction();
        manage_user(db(),(int)$staff['id'],$action,$id,trim($_POST['username']??''),clean_text($_POST['name']??''),(string)($_POST['password']??''));
        db()->commit();
        $_SESSION['user_flash']=$action==='delete'?'ลบผู้ใช้แล้ว':'บันทึกผู้ใช้แล้ว';
        header('Location: '.$listUrl.'#users-list'); exit;
    }
} catch(PDOException $e) {
    if(db()->inTransaction())db()->rollBack();
    $error=($e->errorInfo[1]??0)===1062?'ชื่อผู้ใช้นี้มีอยู่แล้ว':(($e->errorInfo[1]??0)===1451?'ลบไม่ได้ บัญชีนี้มีประวัติการใช้งาน':'บันทึกไม่ได้ กรุณาลองใหม่');
} catch(RuntimeException $e) { if(db()->inTransaction())db()->rollBack(); $error=$e->getMessage(); }
try {
    $editId=(int)($_GET['edit']??($_POST['id']??0));
    if($editId>0) { $q=db()->prepare('SELECT id,username,name,role FROM staff WHERE id=?');$q->execute([$editId]); $edit=$q->fetch()?:$edit; }
    if($error && in_array($_POST['action']??'',['create','update'],true)) { $edit['username']=$_POST['username']??'';$edit['name']=$_POST['name']??''; }
    $where=$userSearch!==''?' WHERE (s.username LIKE ? OR s.name LIKE ?)':'';
    $args=$userSearch!==''?['%'.$userSearch.'%','%'.$userSearch.'%']:[];
    $q=db()->prepare('SELECT COUNT(*) FROM staff s'.$where);$q->execute($args);
    $userPage=page_info((int)$q->fetchColumn(),(int)($_GET['page']??1),$userSize);
    $q=db()->prepare('SELECT s.id,s.username,s.name,s.role,(SELECT COUNT(*) FROM coupons c WHERE c.staff_id=s.id) AS records FROM staff s'.$where.' ORDER BY s.role,s.username,s.id LIMIT '.$userPage['size'].' OFFSET '.$userPage['offset']);
    $q->execute($args);$users=$q->fetchAll();
} catch(PDOException $e) { $error='โหลดข้อมูลผู้ใช้ไม่ได้'; }
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>จัดการผู้ใช้</title><link rel="stylesheet" href="assets/style.css"><script src="assets/app.js" defer></script></head><body>
<?php require __DIR__.'/header.php'; ?>
<main><h1>จัดการผู้ใช้</h1>
<?php if($error): ?><p class="notice danger" role="alert"><?=e($error)?></p><?php endif ?>
<?php if($flash): ?><p class="notice success" role="status"><?=e($flash)?></p><?php endif ?>
<section class="card user-editor" id="user-form"><h2><?=$edit['id']?'แก้ไขผู้ใช้':'เพิ่มผู้ใช้'?></h2><form method="post" action="<?=e($listUrl)?>">
<?=csrf()?><input type="hidden" name="action" value="<?=$edit['id']?'update':'create'?>"><input type="hidden" name="id" value="<?=(int)$edit['id']?>">
<label for="username">Username</label><input id="username" name="username" required minlength="2" maxlength="80" pattern="[a-zA-Z0-9_.-]{2,80}" autocomplete="off" value="<?=e($edit['username'])?>" <?=$edit['role']==='admin'?'readonly':''?>>
<label for="name">ชื่อ–สกุล</label><input id="name" name="name" required maxlength="150" value="<?=e($edit['name'])?>">
<label for="password">รหัสผ่าน <?=$edit['id']?'<span class="muted">เว้นว่างเพื่อใช้รหัสเดิม</span>':''?></label><input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" <?=$edit['id']?'':'required'?> placeholder="อย่างน้อย 8 ตัว">
<p class="muted">สิทธิ์: <?=e($edit['role'])?></p><button class="primary">บันทึกผู้ใช้</button><?php if($edit['id']): ?><a class="admin-link" href="<?=e($listUrl)?>">ยกเลิก / เพิ่มผู้ใช้ใหม่</a><?php endif ?>
</form></section>
<section class="card history" id="users-list"><div class="history-heading"><h2>ผู้ใช้ทั้งหมด</h2><span><?=$userPage['total']?> บัญชี</span></div>
<form method="get" action="users.php#users-list" class="history-search"><input type="hidden" name="per_page" value="<?=$userSize?>"><input name="q" value="<?=e($userSearch)?>" placeholder="ค้นหา username / ชื่อ" aria-label="ค้นหาผู้ใช้"><button class="secondary">ค้นหา</button><a class="quiet" href="users.php#users-list">ทั้งหมด</a></form>
<?=pagination($userPage,'users.php','users-list',['q'=>$userSearch])?>
<div class="history-list">
<?php foreach($users as $user): ?><article class="history-item user-item"><div class="recipient"><h3><?=e($user['name'])?></h3><p class="recipient-id"><?=e($user['username'])?> · <?=e($user['role'])?></p></div><div class="user-actions"><a class="admin-link" href="<?=e($listUrl.'&edit='.(int)$user['id'])?>#user-form">แก้ไข</a>
<?php if($user['role']!=='admin' && $user['username']!=='phet' && (int)$user['records']===0): ?><details class="delete-user"><summary>ลบ</summary><form method="post" action="<?=e($listUrl)?>"><?=csrf()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$user['id']?>"><input type="hidden" name="confirm_delete" value="yes"><p>ลบบัญชี <?=e($user['username'])?>?</p><button class="delete-button">ยืนยันลบผู้ใช้</button></form></details><?php elseif((int)$user['records']>0): ?><span class="muted">บันทึกแล้ว <?=number_format((int)$user['records'])?> รายการ</span><?php endif ?>
</div></article><?php endforeach ?><?php if(!$users): ?><p class="empty">ไม่พบผู้ใช้</p><?php endif ?></div>
<?php if($users): ?><?=pagination($userPage,'users.php','users-list',['q'=>$userSearch])?><?php endif ?>
</section></main></body></html>
