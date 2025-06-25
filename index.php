<?php
/**
 * 主界面 - 修复刷新弹窗问题 + 金币系统新增 + 登录信息显示
 * 
 * 版本：3.2.1+金币系统+登录信息
 * 
 * 说明：
 * - 基于3.2.1版本修复刷新弹窗问题
 * - 新增金币系统：创建应用扣50金币，签到送10金币
 * - 新增登录IP和最后登录时间显示
 */

session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$userDir = __DIR__ . "/data/user/$username";
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]";

if (!file_exists($userDir)) {
    mkdir($userDir, 0755, true);
}

// ----------------- 新增登录信息记录开始 -----------------
$loginInfoFile = "$userDir/login_info.ini";

// 获取客户端IP
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// 更新登录信息
$currentLoginInfo = [
    'last_login_ip' => getClientIP(),
    'last_login_time' => date('Y-m-d H:i:s')
];

// 保存当前登录信息
file_put_contents($loginInfoFile, 
    "last_login_ip = \"{$currentLoginInfo['last_login_ip']}\"\n" .
    "last_login_time = \"{$currentLoginInfo['last_login_time']}\"\n");

// 读取历史登录信息
$loginInfo = [];
if (file_exists($loginInfoFile)) {
    $loginInfo = parse_ini_file($loginInfoFile);
}
// ----------------- 新增登录信息记录结束 -----------------

// ----------------- 金币系统开始 -----------------
$goldFile = "$userDir/gold.ini";
$signFile = "$userDir/sign.ini";

// 初始化金币文件（首次100金币）
if (!file_exists($goldFile)) {
    file_put_contents($goldFile, "100");
}
// 读取当前金币
$gold = (int)file_get_contents($goldFile);

// 签到处理
$signMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_sign'])) {
    $today = date('Y-m-d');
    $lastSignDate = file_exists($signFile) ? trim(file_get_contents($signFile)) : '';

    if ($lastSignDate === $today) {
        $signMessage = "今日已签到，请明天再来！";
    } else {
        // 签到成功，金币 +10
        $gold += 10;
        file_put_contents($goldFile, (string)$gold);
        file_put_contents($signFile, $today);
        $signMessage = "签到成功，获得 10 金币！当前金币：$gold";
    }
}

// 创建应用时扣除金币50
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_app'])) {
    if ($gold < 50) {
        $_SESSION['error'] = "金币不足，创建应用需消耗50金币，当前金币：$gold";
        header("Location: index.php");
        exit();
    }
    // 扣除50金币并写回文件
    $gold -= 50;
    file_put_contents($goldFile, (string)$gold);
}
// ----------------- 金币系统结束 -----------------

// 处理应用创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_app'])) {
    $appName = trim($_POST['app_name'] ?? '');
    
    if (empty($appName) || !preg_match('/^[a-zA-Z0-9_\-\s]{3,20}$/', $appName)) {
        $_SESSION['error'] = "应用名称无效（3-20位字母数字、下划线或短横线）";
        header("Location: index.php");
        exit();
    }

    $appId = 'app_' . bin2hex(random_bytes(4));
    $appPath = "$userDir/$appId";
    
    if (!file_exists($appPath)) {
        mkdir($appPath, 0755);
        file_put_contents("$appPath/kami.ini", "");
        file_put_contents("$appPath/app.info", $appName);
        $_SESSION['success'] = "应用 '{$appName}' 创建成功！";
    } else {
        $_SESSION['error'] = "应用已存在";
    }
    header("Location: index.php");
    exit();
}

// 处理应用删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_app'])) {
    $appId = $_POST['app_id'] ?? '';
    if (preg_match('/^app_[a-f0-9]{8}$/', $appId)) {
        $appPath = "$userDir/$appId";
        
        function deleteDirectory($dir) {
            if (!file_exists($dir)) return true;
            if (!is_dir($dir)) return unlink($dir);
            foreach (scandir($dir) as $item) {
                if ($item == '.' || $item == '..') continue;
                if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
            }
            return rmdir($dir);
        }
        
        if (deleteDirectory($appPath)) {
            $_SESSION['success'] = "应用已删除";
        } else {
            $_SESSION['error'] = "删除失败，请检查权限";
        }
    }
    header("Location: index.php");
    exit();
}

// 获取应用列表
$apps = [];
foreach (glob("$userDir/app_*") as $appPath) {
    if (is_dir($appPath)) {
        $appId = basename($appPath);
        $appName = file_exists("$appPath/app.info") 
            ? file_get_contents("$appPath/app.info")
            : str_replace('app_', '', $appId);
        
        $apps[] = [
            'id' => $appId,
            'name' => $appName,
            'path' => $appPath,
            'kami_count' => file_exists("$appPath/kami.ini") 
                ? count(file("$appPath/kami.ini", FILE_SKIP_EMPTY_LINES))
                : 0
        ];
    }
}

usort($apps, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的应用 - Muzi</title>
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #f5f5f5;
            --medium: #e0e0e0;
            --dark: #333;
            --gray: #95a5a6;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', 'Microsoft YaHei', sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--medium);
        }
        
        h1 {
            color: var(--primary);
            font-weight: 300;
            font-size: 2.2rem;
        }
        
        h2 {
            color: var(--dark);
            font-weight: 400;
            margin: 2rem 0 1rem;
            font-size: 1.5rem;
            border-bottom: 1px solid var(--medium);
            padding-bottom: 0.5rem;
        }
        
        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background-color: var(--secondary);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn:hover {
            background-color: #2980b9;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background-color: var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
        }
          .gr:hover {
            background-color: ##2c3e50;
        }
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #219653;
        }
        
        .card {
            background-color: white;
            border-radius: 6px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }
        
        input, select {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid var(--medium);
            border-radius: 4px;
            font-size: 1rem;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        .alert {
            padding: 0.8rem 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .alert-success {
            background-color: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border-left: 3px solid var(--success);
        }
        
        .alert-error {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border-left: 3px solid var(--danger);
        }
        
        .app-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .app-card {
            padding: 1.2rem;
            border: 1px solid var(--medium);
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .app-card:hover {
            border-color: var(--secondary);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }
        
        .app-name {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }
        
        .app-id {
            font-family: 'Courier New', monospace;
            color: var(--gray);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }
        
        .app-meta {
            display: flex;
            justify-content: space-between;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .app-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
        }
        
        .confirm-box {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 2rem;
            border-radius: 6px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            z-index: 1001;
            display: none;
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        
        .confirm-title {
            margin-bottom: 1rem;
            color: var(--primary);
        }
        
        .confirm-message {
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
        
        .confirm-countdown {
            font-weight: bold;
            color: var(--danger);
            margin-bottom: 1.5rem;
        }
        
        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }
        
        .confirm-btn {
            padding: 0.5rem 1.5rem;
        }
        
        .confirm-delete {
            background-color: var(--danger);
            opacity: 0.5;
            pointer-events: none;
        }
        
        .confirm-delete.active {
            opacity: 1;
            pointer-events: auto;
        }
        
        .confirm-cancel {
            background-color: var(--medium);
        }
        
        .confirm-cancel:hover {
            background-color: var(--dark);
        }
        
        /* 新增登录信息样式 */
        .login-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: var(--gray);
        }
        
        .login-info-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .login-info-item i {
            font-size: 1.1rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .app-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .login-info {
                flex-direction: column;
                gap: 0.5rem;
            }

        }
    </style>
    <!-- 引入Font Awesome图标 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>欢迎回来，<?php echo htmlspecialchars($username); ?></h1>
            <div>
                <a href="home.php" class="btn gr">个人中心</a>
                <a href="logout.php" class="btn btn-danger">注销登录</a>
            </div>
        </div>
        
        <!-- 新增登录信息显示 -->
        <div class="login-info">
            <div class="login-info-item">
                <i class="fas fa-coins"></i>
                <span>金币余额：<strong><?php echo $gold; ?></strong> 🪙</span>
            </div>
            <div class="login-info-item">
                <i class="fas fa-globe"></i>
                <span>登录IP：<?php echo htmlspecialchars($loginInfo['last_login_ip'] ?? '未知'); ?></span>
            </div>
            <div class="login-info-item">
                <i class="fas fa-clock"></i>
                <span>最后登录：<?php echo htmlspecialchars($loginInfo['last_login_time'] ?? '从未登录'); ?></span>
            </div>
        </div>
        
        <!-- 签到按钮 -->
        <form method="post" style="margin-bottom:1rem;">
            <?php
            $today = date('Y-m-d');
            $lastSignDate = file_exists($signFile) ? trim(file_get_contents($signFile)) : '';
            $signedToday = ($lastSignDate === $today);
            ?>
            <button type="submit" name="do_sign" style="
                background-color: <?php echo $signedToday ? '#95a5a6' : '#27ae60'; ?>;
                color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor: <?php echo $signedToday ? 'not-allowed' : 'pointer'; ?>;"
                <?php echo $signedToday ? 'disabled' : ''; ?>>
                <?php echo $signedToday ? '今日已签到' : '签到 +10金币'; ?>
            </button>
        </form>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- 签到消息 -->
        <?php if ($signMessage): ?>
            <div style="color: green; margin-bottom: 1rem;">
                <?php echo htmlspecialchars($signMessage); ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>创建新应用</h2>
            <form method="post">
                <div class="form-group">
                    <label for="app_name">应用名称</label>
                    <input type="text" id="app_name" name="app_name" 
                           placeholder="输入3-20位字符（字母/数字/下划线）" required>
                </div>
                <button type="submit" name="create_app" class="btn btn-success">创建应用</button>
            </form>
        </div>
        
        <div class="card">
            <h2>应用列表 (<?php echo count($apps); ?>)</h2>
            
            <?php if (empty($apps)): ?>
                <p style="padding: 1rem;">您还没有创建任何应用</p>
            <?php else: ?>
                <div class="app-grid">
                    <?php foreach ($apps as $app): ?>
                        <div class="app-card">
                            <h3 class="app-name"><?php echo htmlspecialchars($app['name']); ?></h3>
                            <div class="app-id">ID: <?php echo $app['id']; ?></div>
                            <div class="app-meta">
                                <span>卡密数量: <?php echo $app['kami_count']; ?></span>
                                <span>创建时间: <?php echo date('Y-m-d', filemtime($app['path'])); ?></span>
                            </div>
                            <div class="app-actions">
                                <a href="app.php?app=<?php echo urlencode($app['id']); ?>" class="btn">管理</a>
                                <button class="btn btn-danger" 
                                        onclick="showDeleteConfirm('<?php echo $app['id']; ?>', '<?php echo htmlspecialchars($app['name']); ?>')">
                                    删除
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
   <!-- 删除确认弹窗 -->
<div class="confirm-overlay" id="confirmOverlay" style="display: none;"></div>
<div class="confirm-box" id="confirmBox" style="display: none;">
    <h3 class="confirm-title">确认删除应用</h3>
    <p class="confirm-message">您确定要删除应用 "<strong id="confirmAppName"></strong>" 吗？</p>
    <p class="confirm-countdown">请等待 <span id="countdown">3</span> 秒确认</p>
    <div class="confirm-buttons">
        <form method="post" id="deleteForm">
            <input type="hidden" name="delete_app" value="1">
            <input type="hidden" name="app_id" id="deleteAppId">
            <button type="submit" class="btn btn-danger confirm-delete" id="confirmDeleteBtn" disabled>确认删除</button>
        </form>
        <button class="btn confirm-cancel" onclick="hideDeleteConfirm()">取消</button>
    </div>
</div>

<script>
    // 修复：初始化隐藏弹窗
    document.getElementById('confirmOverlay').style.display = 'none';
    document.getElementById('confirmBox').style.display = 'none';

    let countdown;
    let countdownSeconds = 3;
    
    function showDeleteConfirm(appId, appName) {
        // 修复：添加参数验证
        if (!appId || !appName) return;
        
        document.getElementById('confirmAppName').textContent = appName;
        document.getElementById('deleteAppId').value = appId;
        document.getElementById('confirmOverlay').style.display = 'block';
        document.getElementById('confirmBox').style.display = 'block';
        
        // 重置倒计时
        countdownSeconds = 3;
        document.getElementById('countdown').textContent = countdownSeconds;
        document.getElementById('confirmDeleteBtn').disabled = true;
        
        // 清除已有倒计时
        clearInterval(countdown);
        
        // 开始新倒计时
        countdown = setInterval(function() {
            countdownSeconds--;
            document.getElementById('countdown').textContent = countdownSeconds;
            
            if (countdownSeconds <= 0) {
                clearInterval(countdown);
                document.getElementById('confirmDeleteBtn').disabled = false;
                document.getElementById('confirmDeleteBtn').classList.add('active'); // 添加这行确保样式也更新
            }
        }, 1000);
    }
    
    function hideDeleteConfirm() {
        clearInterval(countdown);
        document.getElementById('confirmOverlay').style.display = 'none';
        document.getElementById('confirmBox').style.display = 'none';
    }
    
    // 自动关闭消息提示
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
</script>
</body>
</html>