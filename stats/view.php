<?php
/* VLink 自建统计 - 报表端 v2.2
   访问：https://supersubsidy.top/view.php?pw=vlink2026
   加 &bot=1 可查看被过滤的机器人/爬虫记录
   v2.2 新增：手机型号识别、打开环境（微信/抖音/APP内置）、机器人过滤、设备画像 */
date_default_timezone_set('Asia/Shanghai');
$PW   = 'vlink2026';   /* ← 部署后第一件事：改成你自己的密码 */
$DATA = __DIR__ . '/.s_stats_9k2m7x.php';

/* ---- 登录防爆破：同一 IP 连错 8 次锁 10 分钟 ---- */
$LIM = __DIR__ . '/.s_login_9k2m7x.php';
$lip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$limMap = [];
if (file_exists($LIM)) {
    foreach (@file($LIM, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $p = explode('|', $ln);
        if (count($p) === 3) $limMap[$p[0]] = ['n' => (int)$p[1], 't' => (int)$p[2]];
    }
}
$rec = $limMap[$lip] ?? ['n' => 0, 't' => 0];
if (time() - $rec['t'] > 600) $rec = ['n' => 0, 't' => 0];
if ($rec['n'] >= 8) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>已锁定</title></head>'
       . '<body style="font-family:-apple-system,\'PingFang SC\',sans-serif;background:#f5f6fa;display:flex;justify-content:center;align-items:center;min-height:90vh;margin:0">'
       . '<div style="background:#fff;padding:30px;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center;max-width:320px">'
       . '<h2 style="margin:0 0 10px">🔒 暂时锁定</h2>'
       . '<p style="color:#888;font-size:13px;margin:0">密码错误次数过多，请 10 分钟后再试。</p></div></body></html>';
    exit;
}

$pw = $_GET['pw'] ?? '';
if ($pw !== $PW) {
    if ($pw !== '') {
        $rec['n']++; $rec['t'] = time(); $limMap[$lip] = $rec;
        $buf = '';
        foreach ($limMap as $ip => $v) { if (time() - $v['t'] < 600) $buf .= $ip . '|' . $v['n'] . '|' . $v['t'] . "\n"; }
        @file_put_contents($LIM, $buf, LOCK_EX);
    }
    $msg = ($pw !== '') ? '⚠️ 密码错误，请重试' : '请输入密码查看报表';
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>访问统计</title></head>';
    echo '<body style="font-family:-apple-system,\'PingFang SC\',sans-serif;background:#f5f6fa;display:flex;justify-content:center;align-items:center;min-height:90vh;margin:0">';
    echo '<div style="background:#fff;padding:30px;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center;max-width:320px;width:90%;box-sizing:border-box">';
    echo '<h2 style="margin:0 0 6px">📊 访问统计报表</h2>';
    echo '<p style="color:#999;font-size:13px;margin:0 0 18px">' . $msg . '</p>';
    echo '<form method="get" style="display:flex;gap:8px"><input type="password" name="pw" placeholder="输入密码" style="flex:1;padding:10px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box"><button style="padding:10px 18px;border:0;background:#4f6ef2;color:#fff;border-radius:8px;cursor:pointer">查看</button></form>';
    echo '</div></body></html>';
    exit;
}
$SHOWBOT = (($_GET['bot'] ?? '') === '1');

/* ============ 机型代号 → 中文名（仅放已核实的） ============ */
$MODELS = [
  'V2408A' => 'iQOO 13', 'V2171A' => 'iQOO 9', 'V2068A' => 'vivo Y31s',
  'HMA-AL00' => '华为 Mate 20',
  'ABR-AL80' => '华为 P50', 'ABR-AL00' => '华为 P50',
  'BRC-AN00' => '荣耀 X60',
  '25098PN5AC' => '小米 17 Pro',
  'PLM110' => 'OPPO K13 Turbo',
  'SM-S921U' => '三星 S24', 'SM-A556B' => '三星 A55',
];
$MYSELF_IP = ['101.32.163.241'];   // 服务器自己（本地测试产生的数据）
function brandOf($ua, $code) {
    $u = strtoupper($ua); $c = strtoupper($code);
    if (strpos($u, 'HONOR') !== false || strpos($u, 'HONOR') !== false) return '荣耀';
    if (strpos($u, 'HUAWEI') !== false || strpos($u, 'HARMONYOS') !== false) return '华为';
    if (strpos($u, 'VIVO') !== false || preg_match('/^V\d{4}/', $c)) return 'vivo/iQOO';
    if (preg_match('/^SM-/', $c)) return '三星';
    if (preg_match('/^\d{5}[A-Z]{2}/', $c)) return '小米/红米';
    if (preg_match('/^(PCHM|PEGM|CPH|PHK|RMX|PEMM)/', $c)) return 'OPPO/realme';
    if (strpos($u, 'XIAOMI') !== false || strpos($u, 'REDMI') !== false || strpos($u, 'MIUI') !== false) return '小米/红米';
    if (strpos($u, 'IPHONE') !== false) return '苹果';
    if (strpos($u, 'PIXEL') !== false) return '谷歌 Pixel';
    return '安卓机';
}
/* ============ 设备解析：返回 [是否机器人, 机器人名, 图标, 一句话描述] ============ */
function deviceInfo($ua) {
    global $MODELS;
    $ua = (string)$ua;
    $r = ['bot' => false, 'botname' => '', 'icon' => '❓', 'label' => '未知设备', 'brand' => '', 'model' => ''];
    if ($ua === '') { $r['icon'] = '👻'; $r['label'] = '无设备信息'; $r['bot'] = true; $r['botname'] = '空UA'; return $r; }
    /* 1. 机器人 / 扫描器 */
    $botMap = [
      'ClaudeBot' => 'ClaudeBot 爬虫', 'GPTBot' => 'GPTBot 爬虫', 'CCBot' => 'CCBot 爬虫',
      'meta-webindexer' => 'Meta 爬虫', 'facebookexternalhit' => 'Facebook 抓取',
      'AhrefsBot' => 'Ahrefs 工具', 'SemrushBot' => 'Semrush 工具', 'DotBot' => 'DotBot 工具',
      'curl/' => 'curl 命令行', 'Wget' => 'Wget 下载器', 'python' => 'Python 脚本',
      'HeadlessChrome' => '无头浏览器', 'PhantomJS' => 'PhantomJS', 'Scrapy' => 'Scrapy 爬虫',
      'Go-http-client' => 'Go 脚本', 'Java/' => 'Java 脚本', 'scan' => '扫描器',
    ];
    foreach ($botMap as $k => $name) { if (stripos($ua, $k) !== false) { $r['bot'] = true; $r['botname'] = $name; } }
    if (stripos($ua, 'wp-admin') !== false || stripos($ua, 'install.php') !== false) { $r['bot'] = true; $r['botname'] = '漏洞扫描器'; }
    if (stripos($ua, 'WorkBuddy') !== false || stripos($ua, 'Electron') !== false) { $r['bot'] = true; $r['botname'] = '自己测试'; }
    if (stripos($ua, 'bot') !== false || stripos($ua, 'spider') !== false || stripos($ua, 'crawler') !== false) { $r['bot'] = true; if (!$r['botname']) $r['botname'] = '网络爬虫'; }
    if ($r['bot']) { $r['icon'] = '🤖'; $r['label'] = '🤖 ' . $r['botname']; return $r; }
    /* 2. 打开环境 */
    $env = ''; $icon = '📱';
    if (stripos($ua, 'MicroMessenger') !== false) { $env = '微信内'; $icon = '💬'; }
    elseif (stripos($ua, 'aweme') !== false || stripos($ua, 'douyin') !== false) { $env = '抖音内'; $icon = '🎵'; }
    elseif (stripos($ua, 'thunder') !== false || stripos($ua, 'xunlei') !== false) { $env = '迅雷内'; $icon = '⚡'; }
    elseif (stripos($ua, 'QQ/') !== false || stripos($ua, 'MQQBrowser') !== false) { $env = 'QQ内'; $icon = '🐧'; }
    elseif (stripos($ua, 'Weibo') !== false) { $env = '微博内'; $icon = '🐦'; }
    elseif (stripos($ua, 'Quark') !== false) { $env = '夸克浏览器'; }
    elseif (stripos($ua, 'UCBrowser') !== false) { $env = 'UC浏览器'; }
    elseif (stripos($ua, 'baiduboxapp') !== false) { $env = '百度App内'; }
    elseif (strpos($ua, '; wv)') !== false) { $env = 'App内置浏览器'; }
    elseif (stripos($ua, 'Windows') !== false || stripos($ua, 'Macintosh') !== false) { $env = '电脑浏览器'; $icon = '💻'; }
    else { $env = '手机浏览器'; }
    /* 3. 系统 */
    $os = '';
    if (preg_match('/Android ([\d.]+)/', $ua, $m)) { $os = 'Android ' . $m[1]; if (stripos($ua, 'HarmonyOS') !== false) $os .= '(鸿蒙)'; }
    elseif (preg_match('/OS (\d+)[_.](\d+)/', $ua, $m)) { $os = 'iOS ' . $m[1] . '.' . $m[2]; }
    elseif (stripos($ua, 'Windows NT 10') !== false) { $os = 'Win10/11'; }
    elseif (stripos($ua, 'Macintosh') !== false) { $os = 'Mac'; }
    /* 4. 机型 */
    $code = ''; $model = ''; $brand = '';
    if (preg_match('/Android[^;]*;\s*([^;]+?)\s*(?:Build|;|\)|AppleWebKit)/i', $ua, $mm)) {
        $code = trim($mm[1]);
        if (strtolower($code) === 'linux') $code = '';
    }
    if (stripos($ua, 'iPhone') !== false) {
        $brand = '苹果'; $model = 'iPhone';
        if (preg_match('/iPhone(\d+),(\d+)/', $ua, $im)) $model = 'iPhone ' . $im[1] . $im[2];
    } elseif ($code !== '') {
        $brand = brandOf($ua, $code);
        if (stripos($code, 'Pixel') !== false) $brand = '谷歌';
        $model = isset($MODELS[$code]) ? $MODELS[$code] : $code;   // 查到就显示中文名，没查到显示代号
    } elseif (stripos($ua, 'Pixel') !== false) {
        $brand = '谷歌'; $model = 'Pixel';
    } elseif (stripos($ua, 'Windows') !== false) {
        $brand = '电脑'; $model = 'Windows 电脑';
    } elseif (stripos($ua, 'Macintosh') !== false) {
        $brand = '电脑'; $model = 'Mac 电脑';
    }
    if ($model === '') { $model = '未知机型'; }
    $r['icon'] = $icon; $r['brand'] = $brand; $r['model'] = $model;
    $r['label'] = $icon . ' ' . ($brand ? $brand . ' ' : '') . $model . ($os ? ' · ' . $os : '') . ' · ' . $env;
    return $r;
}
/* 来源渠道 */
function srcOf($ref) {
    $ref = (string)$ref;
    if ($ref === '') return ['🔗 直接打开', '#bbb'];
    if (stripos($ref, 'bird_key_report_from') !== false) return ['🎵 抖音（搜索/历史）', '#e8890c'];
    if (stripos($ref, 'e2e_test') !== false) return ['🧪 自己测试', '#bbb'];
    if (stripos($ref, 'supersubsidy') !== false) return ['🏠 站内', '#888'];
    $h = parse_url($ref, PHP_URL_HOST);
    return ['🌐 ' . ($h ?: '外部'), '#4f6ef2'];
}
function shortLink($u) {
    $u = (string)$u; $u = preg_replace('/^https?:\/\//', '', $u);
    $u = preg_replace('/\s+/', ' ', $u);
    if (strlen($u) > 52) $u = mb_substr($u, 0, 52, 'UTF-8') . '…';
    return $u;
}

/* ---------- 读数据 ---------- */
$allV = []; $allC = [];
if (file_exists($DATA)) {
    $lines = @file($DATA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $ln) {
        if (strpos($ln, '<?php') === 0) continue;
        $r = json_decode($ln, true);
        if (!is_array($r) || !isset($r['t'])) continue;
        if (($r['ty'] ?? 'visit') === 'click') $allC[] = $r; else $allV[] = $r;
    }
}
/* 机器行为1：同一个 IP 在 60 秒内点了 3 个及以上不同链接（真人做不到） */
$machineClick = [];
$byIp = [];
foreach ($allC as $i => $r) { $byIp[$r['ip'] ?: '?'][] = $i; }
foreach ($byIp as $ip => $idxs) {
    if (count($idxs) < 3) continue;
    usort($idxs, function($a, $b) use ($allC) { return $allC[$a]['t'] - $allC[$b]['t']; });
    for ($a = 0; $a < count($idxs); $a++) {
        $set = []; $t0 = $allC[$idxs[$a]]['t'];
        for ($b = $a; $b < count($idxs); $b++) {
            if ($allC[$idxs[$b]]['t'] - $t0 > 60) break;
            $set[$allC[$idxs[$b]]['lk'] ?? ''] = 1;
            if (count($set) >= 3) { for ($k = $a; $k <= $b; $k++) $machineClick[$idxs[$k]] = true; break; }
        }
    }
}
/* 机器行为2：只点过链接、从没打开过页面的 IP */
$visitIps = [];
foreach ($allV as $r) { $visitIps[$r['ip'] ?: '?'] = 1; }
$clickOnlyBot = [];
foreach ($allC as $i => $r) { $ip = $r['ip'] ?: '?'; if (!isset($visitIps[$ip])) $clickOnlyBot[$i] = true; }

$botCount = 0; $botNames = [];
$filterBot = function ($arr, $isClick) use (&$botCount, &$botNames, $SHOWBOT, $machineClick, $clickOnlyBot, $MYSELF_IP) {
    $out = [];
    foreach ($arr as $i => $r) {
        $reason = '';
        $d = deviceInfo($r['ua'] ?? '');
        if ($d['bot']) $reason = $d['botname'];
        elseif (in_array((string)($r['ip'] ?? ''), $MYSELF_IP, true)) $reason = '本机测试';
        elseif (stripos((string)($r['ref'] ?? ''), 'e2e_test') !== false) $reason = '自己测试';
        elseif ($isClick && isset($machineClick[$i])) $reason = '机器批量点击';
        elseif ($isClick && isset($clickOnlyBot[$i])) $reason = '只点击没访问';
        elseif ($isClick && preg_match('/test/i', (string)($r['lk'] ?? ''))) $reason = '测试链接';
        if ($reason !== '') {
            $botCount++; $botNames[$reason] = ($botNames[$reason] ?? 0) + 1;
            if ($SHOWBOT) $out[] = $r;
            continue;
        }
        $out[] = $r;
    }
    return $out;
};
$visits = $filterBot($allV, false);
$clicks = $filterBot($allC, true);
$totalRaw = count($allV) + count($allC);
$botDetail = [];
foreach ($botNames as $k => $v) { $botDetail[] = $k . ' ' . $v . ' 条'; }

/* ---------- 全局索引 ---------- */
$ipAll = [];
foreach ($visits as $r) { $ip = $r['ip'] ?: '未知'; $ipAll[$ip] = ($ipAll[$ip] ?? 0) + 1; }
$lastUA = []; $lastSeen = [];
foreach ($visits as $r) { $ip = $r['ip'] ?: '未知'; $lastUA[$ip] = $r['ua'] ?? ''; if (!isset($lastSeen[$ip]) || $r['t'] > $lastSeen[$ip]) $lastSeen[$ip] = $r['t']; }

/* 今日 */
$today = date('Y-m-d');
$todayVisits = 0; $todayIPs = [];
foreach ($visits as $r) if (date('Y-m-d', $r['t']) === $today) { $todayVisits++; $ip = $r['ip'] ?: '未知'; $todayIPs[$ip] = ($todayIPs[$ip] ?? 0) + 1; }
$todayUV = count($todayIPs);
$todayClicks = 0;
foreach ($clicks as $r) if (date('Y-m-d', $r['t']) === $today) $todayClicks++;
$todayCTR = $todayVisits > 0 ? round($todayClicks / $todayVisits * 100) . '%' : '—';

/* 每日（近14天） */
$days = [];
for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = ['pv'=>0,'ips'=>[],'ck'=>0]; }
foreach ($visits as $r) { $d = date('Y-m-d', $r['t']); if (isset($days[$d])) { $days[$d]['pv']++; $days[$d]['ips'][$r['ip'] ?: '未知'] = 1; } }
foreach ($clicks as $r) { $d = date('Y-m-d', $r['t']); if (isset($days[$d])) $days[$d]['ck']++; }

/* 重复访客 */
$repeat = [];
foreach ($ipAll as $ip => $c) if ($c >= 3) $repeat[$ip] = $c;
arsort($repeat);

/* 链接点击 */
$linkStats = [];
foreach ($clicks as $r) {
    $lk = $r['lk'] ?? ''; if ($lk === '') continue;
    if (!isset($linkStats[$lk])) $linkStats[$lk] = ['n'=>0,'ips'=>[],'last'=>0];
    $linkStats[$lk]['n']++; $linkStats[$lk]['ips'][$r['ip'] ?: '未知'][] = $r['t'];
    if ($r['t'] > $linkStats[$lk]['last']) $linkStats[$lk]['last'] = $r['t'];
}
uasort($linkStats, function($a,$b){ return $b['n'] - $a['n']; });

/* 今日时间线 */
$todayEvents = []; $todayTimes = [];
foreach ($visits as $r) {
    if (date('Y-m-d', $r['t']) !== $today) continue;
    $ip = $r['ip'] ?: '未知';
    if (!isset($todayTimes[$ip])) $todayTimes[$ip] = ['first'=>$r['t'],'last'=>$r['t']];
    else { if ($r['t'] < $todayTimes[$ip]['first']) $todayTimes[$ip]['first'] = $r['t']; if ($r['t'] > $todayTimes[$ip]['last']) $todayTimes[$ip]['last'] = $r['t']; }
    $todayEvents[] = ['t'=>$r['t'],'ty'=>'visit','ip'=>$ip,'ua'=>$r['ua'] ?? '','ref'=>$r['ref'] ?? '','lk'=>''];
}
foreach ($clicks as $r) {
    if (date('Y-m-d', $r['t']) !== $today) continue;
    $todayEvents[] = ['t'=>$r['t'],'ty'=>'click','ip'=>$r['ip'] ?: '未知','ua'=>$r['ua'] ?? '','ref'=>$r['ref'] ?? '','lk'=>$r['lk'] ?? ''];
}
usort($todayEvents, function($a,$b){ return $b['t'] - $a['t']; });

/* 设备画像：按 品牌+型号 汇总 */
$devStats = [];
foreach ($visits as $r) {
    $d = deviceInfo($r['ua'] ?? '');
    $key = ($d['brand'] ? $d['brand'] . ' ' : '') . $d['model'];
    if (!isset($devStats[$key])) $devStats[$key] = ['n'=>0,'ips'=>[],'os'=>'','env'=>'','icon'=>$d['icon'],'last'=>0];
    $devStats[$key]['n']++;
    $devStats[$key]['ips'][$r['ip'] ?: '未知'] = 1;
    $devStats[$key]['last'] = max($devStats[$key]['last'], $r['t']);
    $dd = deviceInfo($r['ua'] ?? '');
    $p = explode(' · ', $dd['label']);
    $devStats[$key]['os'] = $p[1] ?? '';
    $devStats[$key]['env'] = $p[2] ?? '';
}
uasort($devStats, function($a,$b){ return $b['n'] - $a['n']; });

$red = 'color:#e02020;font-weight:700';
function card($label, $val, $color){
    return '<div style="flex:1;min-width:120px;background:#fff;border-radius:12px;padding:14px;box-shadow:0 2px 10px rgba(0,0,0,.05)">'
         . '<div style="font-size:12px;color:#888">' . $label . '</div>'
         . '<div style="font-size:26px;font-weight:700;color:' . $color . ';margin-top:4px">' . $val . '</div></div>';
}
function secTitle($t, $sub = ''){
    return '<h3 style="margin:26px 0 10px;font-size:16px;color:#333">' . $t
         . ($sub ? ' <span style="font-size:12px;color:#999;font-weight:400">' . $sub . '</span>' : '') . '</h3>';
}

$totalPV = count($visits); $totalUV = count($ipAll); $totalCK = count($clicks);
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>访问统计报表</title></head>';
echo '<body style="font-family:-apple-system,\'PingFang SC\',sans-serif;background:#f5f6fa;margin:0;padding:18px 14px 40px">';
echo '<div style="max-width:900px;margin:0 auto">';
echo '<div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap"><h2 style="margin:0 0 4px;font-size:20px">📊 访问统计报表 <span style="font-size:12px;color:#999;font-weight:400">v2.2</span></h2><span style="font-size:12px;color:#999">更新 ' . date('m-d H:i') . '</span></div>';
echo '<p style="margin:0 0 8px;font-size:12px;color:#aaa"><span style="' . $red . '">红色 IP</span> = 回头客（来过不止一次）｜数据 100% 存本服务器</p>';
echo '<div style="background:#fff8e6;border:1px solid #ffe0a3;border-radius:10px;padding:9px 12px;font-size:12.5px;color:#8a6100;margin-bottom:14px">'
   . '🛡️ 已剔除 <b>' . $botCount . '</b> 条非真人记录（共 ' . $totalRaw . ' 条）'
   . ($botDetail ? '：' . htmlspecialchars(implode('、', $botDetail)) : '') . '。'
   . ($SHOWBOT ? ' <b>当前正在显示全部（含机器人）</b> ｜ <a href="?pw=' . urlencode($PW) . '" style="color:#8a6100">切回只看真人</a>'
               : ' ｜ <a href="?pw=' . urlencode($PW) . '&bot=1" style="color:#8a6100">看看被剔除了啥</a>')
   . '</div>';

/* 今日卡片 */
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo card('今日访问', $todayVisits, '#4f6ef2');
echo card('今日访客', $todayUV, '#7a5cf0');
echo card('今日链接点击', $todayClicks, '#e8890c');
echo card('今日点击率', $todayCTR, '#12b76a');
echo '</div>';

/* 设备画像 */
echo secTitle('📱 访客设备画像', '用什么手机、什么系统、在哪个 App 里打开的');
if (empty($devStats)) {
    echo '<div style="background:#fff;border-radius:12px;padding:18px;color:#999;font-size:13px">还没有真人访客数据</div>';
} else {
    echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px">';
    echo '<tr style="color:#999;font-size:12px"><td style="padding:8px 4px">手机 / 设备</td><td>系统</td><td>打开环境</td><td>来访次数</td><td>不同 IP</td><td>最近来访</td></tr>';
    foreach ($devStats as $name => $s) {
        echo '<tr style="border-top:1px solid #f2f3f7">'
           . '<td style="padding:9px 4px;font-weight:600;color:#333">' . $s['icon'] . ' ' . htmlspecialchars($name) . '</td>'
           . '<td style="color:#888;white-space:nowrap">' . htmlspecialchars($s['os']) . '</td>'
           . '<td style="color:#666;white-space:nowrap">' . htmlspecialchars($s['env']) . '</td>'
           . '<td style="font-weight:700;color:#e8890c">' . $s['n'] . ' 次</td>'
           . '<td style="color:#888">' . count($s['ips']) . '</td>'
           . '<td style="color:#888;white-space:nowrap">' . date('m-d H:i:s', $s['last']) . '</td></tr>';
    }
    echo '</table></div>';
}

/* 今天的时间线 */
echo secTitle('⏱️ 今天的时间线', '几点几分来、几点几分点链接、用的什么手机');
if (empty($todayEvents)) {
    echo '<div style="background:#fff;border-radius:12px;padding:18px;color:#999;font-size:13px">今天还没有真人访问</div>';
} else {
    echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);max-height:560px;overflow-y:auto">';
    $shown = 0;
    foreach ($todayEvents as $ev) {
        if ($shown >= 300) { echo '<div style="padding:8px 2px;color:#bbb;font-size:12px">（后面还有 ' . (count($todayEvents) - 300) . ' 条，略）</div>'; break; }
        $isCk = ($ev['ty'] === 'click');
        $isRep = ($ipAll[$ev['ip']] ?? 0) > 1;
        $tag = $isCk ? '<span style="color:#e8890c;font-weight:700">🎯 点链接</span>' : '<span style="color:#4f6ef2">👀 来访问</span>';
        $d = deviceInfo($ev['ua']);
        $src = srcOf($ev['ref']);
        echo '<div style="padding:7px 2px;border-top:1px solid #f2f3f7;font-size:12.5px;line-height:1.7">'
           . '<span style="color:#888;font-weight:700;margin-right:6px">' . date('H:i:s', $ev['t']) . '</span>'
           . '<span style="' . ($isRep ? $red : 'color:#444;font-weight:600') . ';margin-right:6px">' . htmlspecialchars($ev['ip']) . '</span>'
           . $tag;
        if ($isCk) echo ' <span style="color:#666;word-break:break-all">👉 ' . htmlspecialchars(shortLink($ev['lk'])) . '</span>';
        echo '<br><span style="color:#666;margin-left:2px">' . htmlspecialchars($d['label']) . '</span>';
        if (!$isCk) echo ' <span style="color:' . $src[1] . '">· ' . htmlspecialchars($src[0]) . '</span>';
        echo '</div>';
        $shown++;
    }
    echo '</div>';
}

/* 每日表 */
echo secTitle('📅 每日访客与点击（近 14 天）', '已剔除机器人');
echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;min-width:520px">';
echo '<tr style="color:#999;font-size:12px"><td style="padding:8px 4px">日期</td><td>访问次数</td><td>访客数</td><td>链接点击</td><td>点击率</td></tr>';
foreach ($days as $d => $v) {
    $ctr = $v['pv'] > 0 ? round($v['ck'] / $v['pv'] * 100) . '%' : '—';
    $hl = ($d === $today) ? 'font-weight:700;color:#4f6ef2' : '';
    echo '<tr style="border-top:1px solid #f2f3f7;' . $hl . '"><td style="padding:9px 4px">' . $d . ($d === $today ? ' (今天)' : '') . '</td><td>' . $v['pv'] . '</td><td>' . count($v['ips']) . '</td><td>' . $v['ck'] . '</td><td style="color:' . ($v['ck'] > 0 ? '#12b76a' : '#ccc') . '">' . $ctr . '</td></tr>';
}
echo '</table></div>';

/* 今日访客 */
echo secTitle('👤 今天的访客', '同 IP 多次打开会合并计数');
if ($todayUV === 0) {
    echo '<div style="background:#fff;border-radius:12px;padding:18px;color:#999;font-size:13px">今天还没有访客</div>';
} else {
    echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;min-width:600px">';
    echo '<tr style="color:#999;font-size:12px"><td style="padding:8px 4px">IP</td><td>次数</td><td>首次</td><td>最近</td><td>设备</td><td>身份</td></tr>';
    foreach ($todayIPs as $ip => $c) {
        $isOld = ($ipAll[$ip] ?? 0) > $c;
        $mark = $isOld ? '<span style="' . $red . '">回头客</span>' : '<span style="color:#bbb">新访客</span>';
        $tf = $todayTimes[$ip]['first'] ?? 0; $tl = $todayTimes[$ip]['last'] ?? 0;
        echo '<tr style="border-top:1px solid #f2f3f7"><td style="padding:9px 4px;' . (($ipAll[$ip] ?? 0) > 1 ? $red : '') . '">' . htmlspecialchars($ip) . '</td><td>' . $c . '</td>'
           . '<td style="color:#888;white-space:nowrap">' . ($tf ? date('H:i:s', $tf) : '—') . '</td>'
           . '<td style="color:#888;white-space:nowrap">' . ($tl ? date('H:i:s', $tl) : '—') . '</td>'
           . '<td style="white-space:nowrap">' . htmlspecialchars(deviceInfo($lastUA[$ip] ?? '')['label']) . '</td><td>' . $mark . '</td></tr>';
    }
    echo '</table></div>';
}

/* 重复访客 */
echo secTitle('🔁 重复访客（累计来过 3 次及以上）', '重点盯这些人');
if (empty($repeat)) {
    echo '<div style="background:#fff;border-radius:12px;padding:18px;color:#999;font-size:13px">还没有人来满 3 次</div>';
} else {
    echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px;min-width:600px">';
    echo '<tr style="color:#999;font-size:12px"><td style="padding:8px 4px">IP</td><td>累计次数</td><td>设备</td><td>最近一次</td></tr>';
    foreach ($repeat as $ip => $c) {
        echo '<tr style="border-top:1px solid #f2f3f7"><td style="padding:9px 4px;' . $red . '">' . htmlspecialchars($ip) . '</td><td style="font-weight:700;color:#e8890c">' . $c . ' 次</td>'
           . '<td style="white-space:nowrap">' . htmlspecialchars(deviceInfo($lastUA[$ip] ?? '')['label']) . '</td>'
           . '<td style="color:#888;white-space:nowrap">' . date('m-d H:i:s', $lastSeen[$ip]) . '</td></tr>';
    }
    echo '</table></div>';
}

/* 链接点击 */
echo secTitle('🔗 页面链接点击记录', '哪个 IP 点了哪条链接');
if (empty($linkStats)) {
    echo '<div style="background:#fff;border-radius:12px;padding:18px;color:#999;font-size:13px">还没有真人点击记录</div>';
} else {
    echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05)">';
    foreach ($linkStats as $lk => $s) {
        $ipHtml = [];
        foreach ($s['ips'] as $ip => $ts) {
            sort($ts);
            $times = implode('、', array_map(function($t){ return date('m-d H:i:s', $t); }, array_slice($ts, -6)));
            if (count($ts) > 6) $times = '…' . $times;
            $ipHtml[] = '<span style="' . (($ipAll[$ip] ?? 0) > 1 ? $red : 'color:#666;font-weight:600') . '">' . htmlspecialchars($ip) . '</span>'
                      . '<span style="color:#aaa">（' . $times . '）</span> '
                      . '<span style="color:#999">' . htmlspecialchars(deviceInfo($lastUA[$ip] ?? '')['label']) . '</span>';
        }
        echo '<div style="padding:11px 2px;border-top:1px solid #f2f3f7">';
        echo '<div style="font-size:13px;color:#333;word-break:break-all">👉 ' . htmlspecialchars(shortLink($lk)) . '</div>';
        echo '<div style="font-size:12px;color:#888;margin-top:5px">总点击 <b style="color:#e8890c">' . $s['n'] . '</b> 次 ｜ ' . count($s['ips']) . ' 个 IP：</div>';
        echo '<div style="font-size:12px;color:#666;margin-top:4px;line-height:1.9">' . implode('<br>', $ipHtml) . '</div>';
        echo '</div>';
    }
    echo '</div>';
}

/* 最近动态 */
echo secTitle('📋 最近 60 条动态', '来访和点击都在内');
$merged = [];
foreach ($visits as $r) { $merged[] = ['t'=>$r['t'],'ty'=>'visit','ip'=>$r['ip'] ?: '未知','ua'=>$r['ua'] ?? '','ref'=>$r['ref'] ?? '','lk'=>'']; }
foreach ($clicks as $r) { $merged[] = ['t'=>$r['t'],'ty'=>'click','ip'=>$r['ip'] ?: '未知','ua'=>$r['ua'] ?? '','ref'=>$r['ref'] ?? '','lk'=>$r['lk'] ?? '']; }
usort($merged, function($a,$b){ return $b['t'] - $a['t']; });
$recent = array_slice($merged, 0, 60);
echo '<div style="background:#fff;border-radius:12px;padding:6px 14px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;min-width:620px">';
echo '<tr style="color:#999;font-size:12px"><td style="padding:8px 4px">时间</td><td>动作</td><td>IP</td><td>设备</td><td>内容/来源</td></tr>';
foreach ($recent as $r) {
    $ip = $r['ip']; $isCk = ($r['ty'] === 'click'); $isRep = ($ipAll[$ip] ?? 0) > 1;
    $act = $isCk ? '<span style="color:#e8890c;font-weight:700">点链接</span>' : '<span style="color:#4f6ef2">来访</span>';
    $content = $isCk ? '<span style="color:#666;word-break:break-all">' . htmlspecialchars(shortLink($r['lk'])) . '</span>' : srcOf($r['ref'])[0];
    echo '<tr style="border-top:1px solid #f2f3f7"><td style="padding:8px 4px;color:#888;white-space:nowrap">' . date('m-d H:i:s', $r['t']) . '</td>'
       . '<td>' . $act . '</td>'
       . '<td style="' . ($isRep ? $red : 'color:#444') . ';white-space:nowrap">' . htmlspecialchars($ip) . '</td>'
       . '<td style="white-space:nowrap">' . htmlspecialchars(deviceInfo($r['ua'])['label']) . '</td>'
       . '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $content . '</td></tr>';
}
echo '</table></div>';

echo '<p style="margin-top:24px;font-size:11px;color:#bbb;text-align:center">真人访问 ' . $totalPV . ' ｜ 真人访客 ' . $totalUV . ' ｜ 真人点击 ' . $totalCK . ' ｜ 已剔除机器人 ' . $botCount . ' 条 ｜ VLink 自建统计 v2.2</p>';
echo '</div></body></html>';
