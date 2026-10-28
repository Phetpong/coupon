<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app.php';
try {
    // Additive and repeatable. No existing records or tables are modified.
    db()->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        occurred_at DATETIME(6) NOT NULL,
        event_key VARCHAR(100) NOT NULL,
        actor_id BIGINT UNSIGNED NULL,
        actor_name VARCHAR(200) NOT NULL,
        actor_username VARCHAR(80) NOT NULL,
        actor_role VARCHAR(20) NULL,
        event_type VARCHAR(40) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        target_type VARCHAR(20) NULL,
        target_id BIGINT UNSIGNED NULL,
        details JSON NOT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NOT NULL,
        INDEX audit_time (occurred_at,id),
        INDEX audit_actor_time (actor_id,occurred_at,id),
        INDEX audit_type_time (event_type,occurred_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Audit migration complete. Existing data retained.\n";
} catch (PDOException $ex) {
    fwrite(STDERR,"Audit migration failed. Check connection and CREATE permission. Code: ".$ex->getCode()."\n"); exit(1);
}
