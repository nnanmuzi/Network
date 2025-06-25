<?php
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set("Asia/Shanghai");

define('SUCCESS', 100);
define('CARD_NOT_FOUND', 200);
define('CARD_EXPIRED', 150);
define('MACHINE_MISMATCH', 300);
define('INVALID_REQUEST', 400);
define('TIMESTAMP_EXPIRED', 401);
define('UID_INVALID', 402);
define('APPID_INVALID', 403);
define('USER_NOT_FOUND', 404);
define('IP_BLOCKED', 250);
define('UID_APPID_INVALID', 408);

define('MAX_REQ_PER_WINDOW', 30);
define('RATE_LIMIT_WINDOW', 15);
define('BLOCK_DURATION', 60);

function respond($code, $message, $data = []) {
    echo json_encode([
        'code' => $code,
        'message' => $message,
        'timestamp' => time(),
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean($str) {
    return preg_match('/^[\w\-@\.]+$/', $str) ? $str : '';
}

function check_rate_limit($ip) {
    $path = __DIR__ . "/data/ip";
    if (!is_dir($path)) mkdir($path, 0755, true);
    $file = "$path/$ip";
    $now = time();

    $records = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $records = array_filter(array_map('intval', $records), function ($t) use ($now) {
        return $t > 0 && $now - $t <= RATE_LIMIT_WINDOW;
    });

    if (in_array(-1, $records)) {
        $last_block = end($records);
        if ($now - $last_block < BLOCK_DURATION) {
            respond(IP_BLOCKED, "IP 请求过于频繁，请稍后再试");
        } else {
            $records = [];
        }
    }

    if (count($records) >= MAX_REQ_PER_WINDOW) {
        $records[] = $now;
        $records[] = -1;
        file_put_contents($file, implode("\n", $records));
        respond(IP_BLOCKED, "IP 请求频率过高，已封锁 60 秒");
    }

    $records[] = $now;
    file_put_contents($file, implode("\n", $records));
}

function get_username($uid) {
    $base = __DIR__ . "/data/user";
    if (!is_dir($base)) return false;

    foreach (scandir($base) as $user) {
        if ($user === "." || $user === "..") continue;
        $keyFile = "$base/$user/key.ini";
        if (file_exists($keyFile)) {
            $content = file_get_contents($keyFile);
            if (preg_match('/uid\s*=\s*"?' . preg_quote($uid, '/') . '"?/', $content)) {
                return $user;
            }
        }
    }
    return false;
}

// 参数验证
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(INVALID_REQUEST, "仅支持 POST 请求");
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !is_array($data)) {
    respond(INVALID_REQUEST, "请求格式错误");
}

$ip = $_SERVER['REMOTE_ADDR'];
$client_ip = $data['ip'] ?? '';
$machine_code = $data['machine_code'] ?? '';
$kami = trim($data['kami'] ?? '');
$uid = clean($data['uid'] ?? '');
$appid = clean($data['appid'] ?? '');
$timestamp = intval($data['timestamp'] ?? 0);

if (!$client_ip || !$machine_code || !$kami || !$uid || !$appid || !$timestamp) {
    respond(INVALID_REQUEST, "参数缺失");
}

if (abs(time() - $timestamp) > 15) {
    respond(TIMESTAMP_EXPIRED, "请求超时，请校准客户端时间");
}

check_rate_limit($client_ip);

// 获取用户与APP路径
$username = get_username($uid);
if (!$username) respond(UID_APPID_INVALID, "UID 与 APPID 无效");

$app_path = __DIR__ . "/data/user/$username/$appid";
$kami_file = "$app_path/kami.ini";
$bind_file = "$app_path/ip_code.ini";

if (!file_exists($kami_file)) respond(UID_APPID_INVALID, "UID 与 APPID 无效");
if (!file_exists($bind_file)) file_put_contents($bind_file, '');

// 读取卡密
$kami_lines = file($kami_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$found = false;

foreach ($kami_lines as $i => $line) {
    $fields = explode('|', $line);
    if (count($fields) < 7) continue;

    list($code, $status, $duration, $reserved, $active_time, $expire_time, $create_time) = $fields;

    if ($code !== $kami) continue;
    $found = true;

    $duration = (int)$duration;
    $active_time = (int)$active_time;
    $expire_time = (int)$expire_time;
    $create_time = (int)$create_time;
    $now = time();

    // 是否绑定
    $bind_lines = file($bind_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $is_bound = false;

    foreach ($bind_lines as $bind) {
        $parts = explode('=', $bind);
        if (count($parts) === 3 && $parts[0] === $kami) {
            if ($parts[2] !== $machine_code) {
                respond(MACHINE_MISMATCH, "机器码不匹配");
            }
            $is_bound = true;
            break;
        }
    }

    // 激活逻辑
    if ($status === '未使用') {
        $active_time = $now;
        $expire_time = $active_time + $duration;
        $status = '使用中';

        $fields[1] = $status;
        $fields[4] = $active_time;
        $fields[5] = $expire_time;
        $kami_lines[$i] = implode('|', $fields);
        file_put_contents($kami_file, implode("\n", $kami_lines) . "\n");

        file_put_contents($bind_file, "$kami=$client_ip=$machine_code\n", FILE_APPEND);
    }

    // 过期检查
    if ($status === '使用中' && $expire_time > 0 && $now >= $expire_time) {
        $status = '已过期';
    }

    // 判断是否永久卡（只根据 duration 值）
    $is_permanent = ($duration === 4070908800);

    // 时间剩余
    if ($status === '已过期') {
        $time_left = 0;
    } elseif ($is_permanent) {
        $time_left = 3; // ✅ 永久卡固定 3 秒
    } else {
        $time_left = max(0, $expire_time - $now);
    }

    respond(SUCCESS, "验证成功", [
        'card_info' => [
            'code' => $code,
            'status' => $status,
            'create_time' => $create_time,
            'active_time' => $active_time,
            'expire_time' => $expire_time,
            'time_left' => $time_left
        ],
        'user_info' => [
            'username' => $username,
            'appid' => $appid
        ]
    ]);
}

if (!$found) {
    respond(CARD_NOT_FOUND, "卡密不存在");
}
