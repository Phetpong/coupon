<?php
// Shared server-side user operations. Never accept a role from the browser.
function user_field_errors(string $action, string $username, string $name, string $password): array {
    $errors=[];
    if (!preg_match('/^[a-zA-Z0-9_.-]{2,80}$/D',$username)) $errors['username']='ใช้ a-z, 0-9, _, . หรือ - จำนวน 2–80 ตัว';
    if ($name==='' || mb_strlen($name)>150) $errors['name']='กรอกชื่อ–สกุล ไม่เกิน 150 ตัวอักษร';
    if (($action==='create' || $password!=='') && (mb_strlen($password)<8 || strlen($password)>72)) $errors['password']='รหัสผ่านต้องมีอย่างน้อย 8 ตัว และไม่เกิน 72 ไบต์';
    return $errors;
}
function manage_user(PDO $pdo, int $actorId, string $action, int $id, string $username, string $name, string $password): void {
    if (!$pdo->inTransaction()) throw new LogicException('User changes require a transaction');
    $q=$pdo->prepare('SELECT id,username,name,role FROM staff WHERE id=? FOR UPDATE'); $q->execute([$actorId]); $actor=$q->fetch();
    if (!is_admin($actor ?: null)) throw new RuntimeException('ไม่มีสิทธิ์จัดการผู้ใช้');
    if (!in_array($action,['create','update','delete'],true)) throw new RuntimeException('คำสั่งไม่ถูกต้อง');
    $target=null;
    if ($action!=='create') {
        $q=$pdo->prepare('SELECT id,username,name,role FROM staff WHERE id=? FOR UPDATE'); $q->execute([$id]); $target=$q->fetch();
        if (!$target) throw new RuntimeException('ไม่พบผู้ใช้');
    }
    if ($action==='delete') {
        if ($id===$actorId || $target['role']==='admin' || $target['username']==='phet') throw new RuntimeException('ไม่สามารถลบบัญชีผู้ดูแลระบบ');
        $q=$pdo->prepare('SELECT COUNT(*) FROM coupons WHERE staff_id=?'); $q->execute([$id]);
        if ($q->fetchColumn()>0) throw new RuntimeException('ลบไม่ได้ บัญชีนี้มีประวัติจ่ายคูปอง');
        $pdo->prepare('DELETE FROM staff WHERE id=?')->execute([$id]);
        audit_write($pdo,'user_deleted','success',$actor,'staff',$id,['before'=>$target]); return;
    }
    $errors=user_field_errors($action,$username,$name,$password);
    if ($errors) throw new RuntimeException(reset($errors));
    if ($action==='update' && ($target['username']==='phet' || $target['role']==='admin') && $username!==$target['username']) throw new RuntimeException('ไม่สามารถเปลี่ยน username ของผู้ดูแลระบบ');
    if ($action==='create') {
        $pdo->prepare("INSERT INTO staff(username,name,password_hash,role) VALUES(?,?,?,'officer')")->execute([$username,$name,password_hash($password,PASSWORD_DEFAULT)]);
        audit_write($pdo,'user_created','success',$actor,'staff',(int)$pdo->lastInsertId(),['after'=>['username'=>$username,'name'=>$name]]);
    } else {
        $sql='UPDATE staff SET username=?,name=?'; $args=[$username,$name];
        if ($password!=='') { $sql.=',password_hash=?'; $args[]=password_hash($password,PASSWORD_DEFAULT); }
        $args[]=$id; $pdo->prepare($sql.' WHERE id=?')->execute($args);
        if ($username!==$target['username'] || $name!==$target['name']) audit_write($pdo,'user_updated','success',$actor,'staff',$id,['before'=>$target,'after'=>['username'=>$username,'name'=>$name]]);
        if ($password!=='') audit_write($pdo,'password_changed','success',$actor,'staff',$id);
    }
}
