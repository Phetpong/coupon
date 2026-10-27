<?php
// Literal .env reader: no interpolation or evaluation of secrets.
$env = [];
if (is_file(__DIR__.'/.env')) {
    foreach (file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $match)) continue;
        $value = trim($match[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value,-1) === '"') || ($value[0] === "'" && substr($value,-1) === "'"))) $value = substr($value, 1, -1);
        $env[$match[1]] = $value;
    }
}
$get = static function (string $key, string $default = '') use ($env): string {
    $value = getenv($key);
    return $value !== false ? $value : ($env[$key] ?? $default);
};
return [
    'dsn' => 'mysql:host='.$get('DB_HOST','127.0.0.1').';port='.$get('DB_PORT','3306').';dbname='.$get('DB_DATABASE','coupon').';charset=utf8mb4',
    'user' => $get('DB_USERNAME','root'),
    'password' => $get('DB_PASSWORD'),
    'event' => $get('EVENT_NAME','มหกรรมของดีเมืองกำแพง 2569'),
    'event_key' => $get('EVENT_KEY','kamphaeng-2569'),
];
