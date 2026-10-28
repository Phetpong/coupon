<?php
require __DIR__.'/app.php';
header('Content-Type: application/json; charset=utf-8');
try { $staff=current_staff(); }
catch (PDOException $e) { http_response_code(503); echo json_encode(['error'=>'ตรวจสอบจำนวนไม่ได้ กรุณาลองใหม่']); exit; }
if (!$staff) {
    http_response_code(401); echo json_encode(['error'=>'กรุณาเข้าสู่ระบบใหม่']); exit;
}
session_write_close();
$group = (string)($_GET['group']??''); $organization = clean_text((string)($_GET['organization']??''));
if (!in_array($group,$groups,true) || $organization==='' || mb_strlen($organization)>200) {
    http_response_code(422); echo json_encode(['error'=>'กรุณาเลือกกลุ่มและระบุหน่วยงาน']); exit;
}
try {
    $quota = [$groups[0]=>5,$groups[1]=>2,$groups[2]=>2][$group]??null;
    echo json_encode(['count'=>organization_count(db(),$config['event_key'],$group,$organization),'quota'=>$quota],JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) { http_response_code(503); echo json_encode(['error'=>'ตรวจสอบจำนวนไม่ได้ กรุณาลองใหม่']); }
