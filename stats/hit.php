<?php
/* VLink 自建统计 - 记录端 v2.0
   用法1（访问记录）：页面里放 <img src="hit.php"> 像素
   用法2（链接点击）：JS 调用 hit.php?type=click&u=链接地址
   数据文件：同目录 .s_stats_9k2m7x.php（PHP 保护头，无法被下载查看） */
date_default_timezone_set('Asia/Shanghai');
$DATA = __DIR__ . '/.s_stats_9k2m7x.php';

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
$PX = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

if (!file_exists($DATA)) {
    @file_put_contents($DATA, "<?php exit('forbidden'); ?>\n", LOCK_EX);
}

$ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip  = substr(trim(explode(',', (string)$ip)[0]), 0, 64);
$ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
$ref = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 300);
$now = time();
$type = ($_GET['type'] ?? 'visit') === 'click' ? 'click' : 'visit';

if ($type === 'click') {
    /* 点击事件：u=被点的链接。文件锁内做同IP同链接5秒去重（防连点/并发双发） */
    $lk = substr($_GET['u'] ?? '', 0, 500);
    if ($lk === '' || $ip === '') { echo $PX; exit; }
    $fp = @fopen($DATA, 'c');
    if ($fp) {
        @flock($fp, LOCK_EX);
        $lines = @file($DATA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $skip = false;
        foreach (array_slice($lines, -60) as $ln) {
            if (strpos($ln, '<?php') === 0) continue;
            $r = json_decode($ln, true);
            if ($r && ($r['ty'] ?? '') === 'click' && ($r['ip'] ?? '') === $ip
                && ($r['lk'] ?? '') === $lk && ($now - ($r['t'] ?? 0)) < 5) { $skip = true; break; }
        }
        if (!$skip) {
            fseek($fp, 0, SEEK_END);
            @fputs($fp, json_encode(['t'=>$now,'ty'=>'click','ip'=>$ip,'ua'=>$ua,'ref'=>$ref,'lk'=>$lk], JSON_UNESCAPED_UNICODE) . "\n");
        }
        @flock($fp, LOCK_UN);
    }
    @fclose($fp);
    echo $PX; exit;
}

/* 访问事件：30秒内同IP同UA去重（防刷新刷数据） */
$url = substr($_GET['u'] ?? '', 0, 300);
$sw  = substr($_GET['s'] ?? '', 0, 20);
$skip = false;
$lines = @file($DATA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines) {
    foreach (array_slice($lines, -40) as $ln) {
        if (strpos($ln, '<?php') === 0) continue;
        $r = json_decode($ln, true);
        if ($r && ($r['ty'] ?? 'visit') === 'visit' && ($r['ip'] ?? '') === $ip
            && ($r['ua'] ?? '') === $ua && ($now - ($r['t'] ?? 0)) < 30) { $skip = true; break; }
    }
}
if (!$skip) {
    @file_put_contents($DATA,
        json_encode(['t'=>$now,'ty'=>'visit','ip'=>$ip,'ua'=>$ua,'ref'=>$ref,'url'=>$url,'s'=>$sw], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX);
}
echo $PX;
