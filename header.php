<?php
// Shared compact header for registration and user management.
$headerStaff=$_SESSION['staff']??null;
$headerAdmin=is_admin($headerStaff);
$headerUsers=basename($_SERVER['SCRIPT_NAME']??'')==='users.php';
?>
<header class="app-header">
<div class="header-top">
<a class="brand" href="index.php"><img class="brand-logo" src="<?=e(asset_url('assets/logo.png'))?>" width="40" height="40" alt="กองการเจ้าหน้าที่ อบจ.กำแพงเพชร"><span>ระบบจ่ายคูปอง</span></a>
<?php if($headerStaff): ?><nav class="header-actions" aria-label="เมนูหลัก">
<?php if($headerAdmin): ?><a class="header-menu" href="<?=$headerUsers?'index.php':'users.php'?>"><?=$headerUsers?'บันทึกคูปอง':'จัดการผู้ใช้'?></a><?php endif ?>
<form method="post" action="index.php"><?=csrf()?><input type="hidden" name="action" value="logout"><button class="header-logout" aria-label="ออกจากระบบ" title="ออกจากระบบ"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 5H5v14h5m4-12 5 5-5 5m-5-5h10" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>
</nav><?php endif ?>
</div>
<?php if($headerStaff): ?><div class="header-person"><span class="header-role <?=$headerAdmin?'is-admin':''?>"><?=$headerAdmin?'ผู้ดูแลระบบ':'เจ้าหน้าที่'?></span><span class="header-name"><?=e($headerStaff['name'])?></span></div><?php endif ?>
</header>
