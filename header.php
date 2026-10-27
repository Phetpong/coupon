<?php
// Shared compact header for registration and user management.
$headerStaff=$_SESSION['staff']??null;
$headerAdmin=is_admin($headerStaff);
$headerUsers=basename($_SERVER['SCRIPT_NAME']??'')==='users.php';
?>
<header class="app-header">
<div class="header-top">
<a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5h16v5a2 2 0 0 0 0 4v5H4v-5a2 2 0 0 0 0-4V5Z"/><path d="M14 8v2m0 2v2m0 2v1"/></svg></span><span>ระบบจ่ายคูปอง</span></a>
<?php if($headerStaff): ?><nav class="header-actions" aria-label="เมนูหลัก">
<?php if($headerAdmin): ?><a class="header-menu" href="<?=$headerUsers?'index.php':'users.php'?>"><?=$headerUsers?'บันทึกคูปอง':'จัดการผู้ใช้'?></a><?php endif ?>
<form method="post" action="index.php"><?=csrf()?><input type="hidden" name="action" value="logout"><button class="header-logout" aria-label="ออกจากระบบ" title="ออกจากระบบ"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10 5H5v14h5m4-12 5 5-5 5m-5-5h10" stroke-linecap="round" stroke-linejoin="round"/></svg></button></form>
</nav><?php endif ?>
</div>
<?php if($headerStaff): ?><div class="header-person"><span class="header-role <?=$headerAdmin?'is-admin':''?>"><?=$headerAdmin?'ผู้ดูแลระบบ':'เจ้าหน้าที่'?></span><span class="header-name"><?=e($headerStaff['name'])?></span></div><?php endif ?>
</header>
