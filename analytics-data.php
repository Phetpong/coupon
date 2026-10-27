<?php
declare(strict_types=1);

function analytics_date(string $value): bool {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value;
}

function analytics_data(PDO $pdo, string $event, string $from, string $to, string $group, int $staffId): array {
    foreach([$from,$to] as $date) if($date!=='' && !analytics_date($date)) throw new RuntimeException('วันที่ไม่ถูกต้อง');
    if($from!=='' && $to!=='' && $from>$to) throw new RuntimeException('วันเริ่มต้นต้องไม่เกินวันสิ้นสุด');
    $where='c.event_key=?'; $args=[$event];
    if($from!=='') { $where.=' AND c.issued_at>=?'; $args[]=$from.' 00:00:00'; }
    if($to!=='') { $where.=' AND c.issued_at<?'; $args[]=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
    if($group!=='') { $where.=' AND c.group_name=?'; $args[]=$group; }
    if($staffId>0) { $where.=' AND c.staff_id=?'; $args[]=$staffId; }
    $run=static function(string $sql) use($pdo,$args):array { $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll(); };
    $summary=$run('SELECT COUNT(*) AS total,COUNT(DISTINCT c.staff_id) AS staff_count,COUNT(DISTINCT DATE(c.issued_at)) AS day_count FROM coupons c WHERE '.$where)[0];
    $daily=$run('SELECT DATE(c.issued_at) AS label,COUNT(*) AS total FROM coupons c WHERE '.$where.' GROUP BY DATE(c.issued_at) ORDER BY label');
    $byGroup=$run('SELECT c.group_name AS label,COUNT(*) AS total FROM coupons c WHERE '.$where.' GROUP BY c.group_name ORDER BY total DESC,label');
    $byStaff=$run('SELECT s.name AS label,s.username AS detail,COUNT(*) AS total FROM coupons c JOIN staff s ON s.id=c.staff_id WHERE '.$where.' GROUP BY s.id,s.name,s.username ORDER BY total DESC,s.username');
    $rawOrg=$run('SELECT c.group_name,c.organization,COUNT(*) AS total FROM coupons c WHERE '.$where.' GROUP BY c.group_name,c.organization');
    $orgMap=[]; $namedUnits=[];
    foreach($rawOrg as $row) {
        $name=clean_text($row['organization']); $key=json_encode([$row['group_name'],mb_strtolower($name,'UTF-8')]);
        $orgMap[$key]??=['label'=>$name!==''?$name:'ไม่ระบุหน่วยงาน','detail'=>$row['group_name'],'total'=>0];
        $orgMap[$key]['total']+=(int)$row['total'];
        if($name!=='')$namedUnits[$key]=true;
    }
    $byOrg=array_values($orgMap);
    usort($byOrg,static fn($a,$b)=>($b['total']<=>$a['total']) ?: strcmp($a['label'],$b['label']));
    $summary['organization_count']=count($namedUnits);
    return ['summary'=>$summary,'daily'=>$daily,'group'=>$byGroup,'staff'=>$byStaff,'organization'=>$byOrg];
}

function analytics_day(string $date): string {
    return substr($date,8,2).'/'.substr($date,5,2).'/'.((int)substr($date,0,4)+543);
}
