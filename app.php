<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
if (PHP_SAPI !== 'cli') {
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self' https://static.cloudflareinsights.com; connect-src 'self' https://cloudflareinsights.com; form-action 'self'; frame-ancestors 'none'");
}
$config = require __DIR__.'/config.php';
function db(): PDO {
    global $config;
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function asset_url(string $path): string { return $path.'?v='.filemtime(__DIR__.'/'.$path); }
function current_staff(): ?array {
    if (!isset($_SESSION['staff']) || time()-($_SESSION['activity']??0)>7200) { unset($_SESSION['staff']); return null; }
    $q=db()->prepare('SELECT id,username,name,role FROM staff WHERE id=?'); $q->execute([$_SESSION['staff']['id']]);
    $staff=$q->fetch();
    if (!$staff) { unset($_SESSION['staff']); return null; }
    $_SESSION['activity']=time(); $_SESSION['staff']=$staff;
    return $staff;
}
function is_admin(?array $staff): bool { return $staff && $staff['role']==='admin' && $staff['username']==='phet'; }
function clean_text(string $value): string {
    return trim(preg_replace('/[\s\x{200B}\x{FEFF}]+/u', ' ', $value));
}
function normalized_name(string $name): string {
    $name = clean_text($name);
    $name = preg_replace('/^(?:นางสาว|นาง|นาย|เด็กชาย|เด็กหญิง|ด\.ช\.|ด\.ญ\.|น\.ส\.)\s*/u', '', $name);
    return mb_strtolower(clean_text($name), 'UTF-8');
}
function name_key(string $name): string {
    return hash('sha256', normalized_name($name));
}
function organization_count(PDO $pdo, string $event, string $group, string $organization): int {
    if ($organization === '') return 0;
    $q = $pdo->prepare('SELECT BINARY organization AS organization,COUNT(*) AS total FROM coupons WHERE event_key=? AND group_name=? GROUP BY BINARY organization');
    $q->execute([$event, $group]);
    $count = 0;
    $key=mb_strtolower(clean_text($organization), 'UTF-8');
    foreach ($q as $row) if (mb_strtolower(clean_text($row['organization']), 'UTF-8') === $key) $count+=(int)$row['total'];
    return $count;
}
function page_size($value): int { return in_array((int)$value,[10,25,50],true)?(int)$value:10; }
function page_info(int $total, int $page, int $size): array {
    $size=page_size($size); $pages=max(1,(int)ceil($total/$size)); $page=min(max(1,$page),$pages);
    return ['total'=>$total,'page'=>$page,'pages'=>$pages,'size'=>$size,'offset'=>($page-1)*$size];
}
function pagination(array $info, string $path, string $anchor, array $filters, bool $ajax=false): string {
    $page=$info['page']; $pages=$info['pages']; $size=$info['size']; $total=$info['total'];
    unset($filters['page'],$filters['per_page']);
    $linkClass=$ajax?'history-nav':''; $formClass=$ajax?'history-page-form':'';
    ob_start(); ?>
    <nav class="pagination" aria-label="แบ่งหน้ารายการ">
      <div class="page-summary">แสดง <?=$total ? ($info['offset']+1).'–'.min($info['offset']+$size,$total) : '0'?> จาก <?=number_format($total)?> รายการ</div>
      <div class="page-links">
      <?php foreach(['หน้าแรก'=>1,'ก่อนหน้า'=>$page-1,'ถัดไป'=>$page+1,'หน้าสุดท้าย'=>$pages] as $label=>$target): $disabled=($target<1 || $target>$pages || $target===$page); ?>
      <?php if($disabled): ?><span aria-disabled="true"><?=e($label)?></span><?php else: ?><a class="<?=e($linkClass)?>" href="<?=e($path.'?'.http_build_query($filters+['page'=>$target,'per_page'=>$size]).'#'.$anchor)?>"><?=e($label)?></a><?php endif ?>
      <?php endforeach ?>
      </div>
      <form method="get" action="<?=e($path.'#'.$anchor)?>" class="page-controls <?=e($formClass)?>">
      <?php foreach($filters as $key=>$value): ?><input type="hidden" name="<?=e($key)?>" value="<?=e($value)?>"><?php endforeach ?>
      <label>หน้า <input type="number" name="page" min="1" max="<?=$pages?>" value="<?=$page?>" inputmode="numeric" required aria-label="ไปยังหน้า"></label><span>/ <?=$pages?></span>
      <label>ต่อหน้า <select name="per_page"><?php foreach([10,25,50] as $option): ?><option value="<?=$option?>" <?=$option===$size?'selected':''?>><?=$option?></option><?php endforeach ?></select></label><button class="secondary">ไป</button>
      </form>
    </nav>
    <?php return ob_get_clean();
}
function history_records(PDO $pdo, string $event, string $group, string $organization, string $search, int $page, int $size=50): array {
    $sql=' FROM coupons c JOIN staff s ON s.id=c.staff_id WHERE c.event_key=?';
    $args=[$event];
    if ($group!=='') { $sql.=' AND c.group_name=?'; $args[]=$group; }
    if ($search!=='') { $sql.=' AND (c.citizen_id=? OR c.full_name LIKE ?)'; $args[]=$search; $args[]='%'.$search.'%'; }
    if ($organization!=='') {
        // Keep the existing whitespace/case matching without loading recipient records.
        $q=$pdo->prepare('SELECT DISTINCT BINARY c.organization AS organization'.$sql); $q->execute($args);
        $names=[]; $key=mb_strtolower(clean_text($organization),'UTF-8');
        foreach ($q as $row) if (mb_strtolower(clean_text($row['organization']),'UTF-8')===$key) $names[]=$row['organization'];
        if (!$names) return page_info(0,$page,$size)+['rows'=>[]];
        $sql.=' AND BINARY c.organization IN ('.implode(',',array_fill(0,count($names),'?')).')';
        $args=array_merge($args,$names);
    }
    $q=$pdo->prepare('SELECT COUNT(*)'.$sql); $q->execute($args);
    $info=page_info((int)$q->fetchColumn(),$page,$size);
    $q=$pdo->prepare('SELECT c.*,s.name AS staff_name'.$sql.' ORDER BY c.id DESC LIMIT '.$info['size'].' OFFSET '.$info['offset']);
    $q->execute($args);
    return $info+['rows'=>$q->fetchAll()];
}
function valid_id(string $id): bool {
    if (!preg_match('/^[1-9][0-9]{12}$/D', $id)) return false;
    $sum = 0;
    for ($i=0; $i<12; $i++) $sum += (int)$id[$i] * (13-$i);
    return (11-$sum%11)%10 === (int)$id[12];
}
function masked(string $id): string { return substr($id,0,1).' •••• ••••• '.substr($id,-3); }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$groups = ['ฟาร์มกระบือ (ฟาร์มละ 5 คน)', 'ฟาร์มไก่ชน (ฟาร์มละ 2 คน)', 'ซุ้มพระเครื่อง (ซุ้มละ 2 คน)', 'ผู้เข้าร่วมพิธีเปิด / หัวหน้าส่วนราชการ', 'ขบวนอัญเชิญถ้วยพระราชทาน (โรงเรียน / กองสาธารณสุข)', 'กลุ่มเต้นแอโรบิก', 'กลุ่มแม่บ้าน', 'กลุ่มรำไทย (โรงเรียนโกสัมพีนคร)', 'กลุ่มรถบังไฟ', 'กลุ่มหนังสติ๊ก / หน้าไม้'];
