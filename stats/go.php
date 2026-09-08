<?php
/* VLink 自建统计 - 链接跳转统计端 v1.0
   用法：页面里的外链写成 /go.php?u=https%3A%2F%2F目标地址
   访客点击 → 本文件记录一笔点击（同IP同链接5秒去重）→ 302 跳到目标地址 */
date_default_timezone_set('Asia/Shanghai');

$DATA = __DIR__ . '/.s_stats_9k2m7x.php';
$PX   = base64_decode('RIFoGhoaGg==');   /* 1x1 透明gif，防直接访问暴露 */

$now = time();
$ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
$ref = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 300);

/* 解析目标链接：必须是 http/https */
$lk = $_GET['u'] ?? '';
$lk = substr($lk, 0, 800);
$scheme = strtolower(parse_url($lk, PHP_URL_SCHEME) ?: '');
if (!in_array($scheme, ['http', 'https'], true)) {
    @header('Content-Type: image/gif');
    echo $PX; exit;
}

/* 记录点击（追加到文件末尾 + 文件锁，5秒内同IP同链接去重） */
$fp = @fopen($DATA, 'a');
if ($fp) {
    @flock($fp, LOCK_EX);
    $skip = false;
    $lines = @file($DATA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -60) as $ln) {
        if (strpos($ln, '<?php') === 0) continue;
        $r = json_decode($ln, true);
        if ($r && ($r['ty'] ?? '') === 'click' && ($r['ip'] ?? '') === $ip
            && ($r['lk'] ?? '') === $lk && ($now - ($r['t'] ?? 0)) < 5) { $skip = true; break; }
    }
    if (!$skip) {
        @fputs($fp, json_encode(['t'=>$now,'ty'=>'click','ip'=>$ip,'ua'=>$ua,'ref'=>$ref,'lk'=>$lk], JSON_UNESCAPED_UNICODE) . "\n");
    }
    @flock($fp, LOCK_UN);
    @fclose($fp);
}

/* 302 跳转到目标地址 */
@header('Location: ' . $lk, true, 302);
@header('Cache-Control: no-store');
exit;
