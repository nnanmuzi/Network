<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$userDir = __DIR__ . "/data/user/" . basename($username);
$goldFile = "$userDir/gold.ini";
$keyFile = "$userDir/key.ini";
$loginInfoFile = "$userDir/login_info.ini";
$cdkFile = __DIR__ . "/cdk.ini"; // 兑换码文件

// 确保用户目录存在
if (!is_dir($userDir)) {
    mkdir($userDir, 0755, true);
}

// 读取金币余额
$gold = 0;
if (file_exists($goldFile)) {
    $gold = (int)file_get_contents($goldFile);
} else {
    // 如果金币文件不存在，创建并初始化为0
    file_put_contents($goldFile, "0");
}

// 读取用户UID
$uid = '';
if (file_exists($keyFile)) {
    $keyContent = parse_ini_file($keyFile);
    $uid = $keyContent['uid'] ?? '';
}

// 读取登录信息
$loginInfo = [];
if (file_exists($loginInfoFile)) {
    $loginInfo = parse_ini_file($loginInfoFile);
}

// 处理兑换码请求
$cdkMessage = '';
$cdkMessageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_cdk'])) {
    $cdk = trim($_POST['cdk'] ?? '');
    
    if (empty($cdk)) {
        $cdkMessage = "请输入兑换码";
        $cdkMessageType = 'danger';
    } elseif (file_exists($cdkFile)) {
        $cdkContent = file_get_contents($cdkFile);
        $cdkLines = explode("\n", $cdkContent);
        $found = false;
        $newCdkContent = '';
        
        foreach ($cdkLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // 解析格式：金币数量=兑换码
            if (strpos($line, '=') !== false) {
                list($amount, $code) = explode('=', $line, 2);
                $amount = trim($amount);
                $code = trim($code);
                
                if ($code === $cdk) {
                    // 找到匹配的兑换码
                    $gold += (int)$amount;
                    file_put_contents($goldFile, (string)$gold);
                    $cdkMessage = "成功兑换 {$amount} 金币！当前余额：{$gold}";
                    $cdkMessageType = 'success';
                    $found = true;
                    continue; // 跳过这一行，不写入新内容
                }
            }
            $newCdkContent .= $line . "\n";
        }
        
        if ($found) {
            // 更新兑换码文件
            file_put_contents($cdkFile, trim($newCdkContent));
        } else {
            $cdkMessage = "兑换码无效或已使用";
            $cdkMessageType = 'danger';
        }
    } else {
        $cdkMessage = "兑换码系统暂不可用";
        $cdkMessageType = 'danger';
    }
}

// 处理创建兑换码请求
$createCdkMessage = '';
$createCdkMessageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_cdk'])) {
    $amount = intval($_POST['amount'] ?? 0);
    
    if ($amount <= 0) {
        $createCdkMessage = "请输入有效的金币数量";
        $createCdkMessageType = 'danger';
    } elseif ($amount > 500) {
        $createCdkMessage = "单次最多只能创建500金币的兑换码";
        $createCdkMessageType = 'danger';
    } else {
        // 计算手续费和总扣除金额
        $fee = ceil($amount * 0.05); // 5%手续费，向上取整
        $totalCost = $amount + $fee;
        
        if ($gold < $totalCost) {
            $createCdkMessage = "金币不足，创建{$amount}金币的兑换码需要{$totalCost}金币（含{$fee}金币手续费），当前余额：{$gold}";
            $createCdkMessageType = 'danger';
        } else {
            // 扣除金币
            $gold -= $totalCost;
            file_put_contents($goldFile, (string)$gold);
            
            // 生成随机兑换码
            $cdk = strtoupper(bin2hex(random_bytes(8))); // 16位随机码
            
            // 写入兑换码文件
            $cdkLine = "{$amount}={$cdk}";
            file_put_contents($cdkFile, $cdkLine . PHP_EOL, FILE_APPEND);
            
            $createCdkMessage = "成功创建兑换码！兑换码：{$cdk}（价值{$amount}金币），已扣除{$totalCost}金币（含{$fee}金币手续费），当前余额：{$gold}";
            $createCdkMessageType = 'success';
        }
    }
}

// 读取应用列表
$appList = [];
if (is_dir($userDir)) {
    $dirs = scandir($userDir);
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        $fullPath = $userDir . "/" . $dir;
        if (is_dir($fullPath) && preg_match('/^app_[a-f0-9]{8}$/', $dir)) {
            $appList[] = $dir;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>个人主页 - <?= htmlspecialchars($username) ?></title>
    <style>
       :root {
    --primary: #1f2937;
    --secondary: #3b82f6;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    --gray-light: #f3f4f6;
    --gray-medium: #d1d5db;
    --gray-dark: #374151;
    --modal-bg: rgba(0, 0, 0, 0.6);
    --radius: 12px;
    --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
}

* {
    box-sizing: border-box;
    -webkit-tap-highlight-color: transparent;
}

body {
    margin: 0;
    font-family: "Segoe UI", "Microsoft YaHei", sans-serif;
    font-size: 18px;
    background-color: var(--gray-light);
    color: var(--gray-dark);
    line-height: 1.6;
}

.container {
    max-width: 1000px;
    margin: 3rem auto;
    background: #ffffff;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 2.5rem 3rem;
}

h1 {
    font-size: 2rem;
    color: var(--primary);
    margin-bottom: 1.5rem;
}

.user-info {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    margin-bottom: 2rem;
}

.info-box {
    background-color: var(--gray-light);
    padding: 1rem 1.5rem;
    border-radius: var(--radius);
    min-width: 200px;
}

.info-box h3 {
    margin-top: 0;
    margin-bottom: 0.5rem;
    font-size: 1rem;
    color: var(--gray-dark);
}

.info-box p {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--secondary);
}

.login-info {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    color: var(--gray-dark);
}

.login-info-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.login-info-item i {
    color: var(--secondary);
    font-size: 1.1rem;
}

.section {
    margin-bottom: 2.5rem;
}

.section h2 {
    font-size: 1.5rem;
    color: var(--primary);
    border-bottom: 2px solid var(--gray-medium);
    padding-bottom: 0.5rem;
    margin-bottom: 1.5rem;
}

ul.app-list {
    list-style: none;
    padding: 0;
}

ul.app-list li {
    background-color: var(--gray-light);
    padding: 1rem 1.5rem;
    border: 1px solid var(--gray-medium);
    border-radius: var(--radius);
    margin-bottom: 1rem;
    transition: 0.3s ease;
}

ul.app-list li:hover {
    background-color: var(--secondary);
    color: white;
}

ul.app-list li a {
    color: inherit;
    text-decoration: none;
    font-weight: 500;
    display: block;
}

.btn {
    padding: 0.75rem 1.5rem;
    font-size: 1.1rem;
    border-radius: var(--radius);
    border: none;
    background-color: var(--secondary);
    color: white;
    font-weight: 600;
    cursor: pointer;
    transition: 0.3s ease;
}

.btn:hover {
    background-color: #2563eb;
}

.btn-warning {
    background-color: var(--warning);
}

.btn-warning:hover {
    background-color: #d97706;
}

.btn-success {
    background-color: var(--success);
}

.btn-success:hover {
    background-color: #0d9c6b;
}

.alert {
    padding: 1rem 1.25rem;
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    font-weight: 500;
}

.alert-success {
    background-color: #ecfdf5;
    color: var(--success);
    border: 1px solid var(--success);
}

.alert-danger {
    background-color: #fef2f2;
    color: var(--danger);
    border: 1px solid var(--danger);
}

.alert-warning {
    background-color: #fffbeb;
    color: var(--warning);
    border: 1px solid var(--warning);
}

/* Modal 样式 */
.modal-bg {
    position: fixed;
    top: 0; left: 0;
    width: 100vw; height: 100vh;
    background: var(--modal-bg);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 9999;
}

.modal {
    background: white;
    padding: 2.5rem;
    border-radius: var(--radius);
    width: 90%;
    max-width: 480px;
    box-shadow: var(--shadow);
    position: relative;
}

.modal h3 {
    font-size: 1.5rem;
    margin-top: 0;
    color: var(--primary);
}

.modal label {
    display: block;
    margin-top: 1.2rem;
    font-weight: 600;
}

.modal input[type="text"],
.modal input[type="number"] {
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    border-radius: var(--radius);
    border: 1px solid var(--gray-medium);
    margin-top: 0.5rem;
    background-color: var(--gray-light);
}

.modal button {
    margin-top: 2rem;
    width: 100%;
    font-size: 1.1rem;
}

.modal .close-btn {
    position: absolute;
    top: 1rem;
    right: 1rem;
    font-size: 1.5rem;
    background: none;
    border: none;
    color: var(--gray-medium);
    cursor: pointer;
    font-weight: bold;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.modal .close-btn:hover {
    background-color: var(--gray-light);
    color: var(--danger);
}

button:disabled {
    background-color: var(--gray-medium) !important;
    cursor: not-allowed !important;
    color: #666 !important;
}

    </style>
    <!-- 引入Font Awesome图标 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="container">
    <h1>欢迎您，<?= htmlspecialchars($username) ?></h1>
    
    <div class="user-info">
        <div class="info-box">
            <h3>用户UID</h3>
            <p><?= htmlspecialchars($uid) ?></p>
        </div>
        <div class="info-box">
            <h3>金币余额</h3>
            <p><?= $gold ?> 金币</p>
        </div>
    </div>

    <!-- 登录信息 -->
    <div class="login-info">
        <div class="login-info-item">
            <i class="fas fa-globe"></i>
            <span>登录IP：<?= htmlspecialchars($loginInfo['last_login_ip'] ?? '未知') ?></span>
        </div>
        <div class="login-info-item">
            <i class="fas fa-clock"></i>
            <span>最后登录：<?= htmlspecialchars($loginInfo['last_login_time'] ?? '从未登录') ?></span>
        </div>
    </div>

    <?php if ($cdkMessage): ?>
        <div class="alert alert-<?= $cdkMessageType ?>"><?= htmlspecialchars($cdkMessage) ?></div>
    <?php endif; ?>

    <?php if ($createCdkMessage): ?>
        <div class="alert alert-<?= $createCdkMessageType ?>"><?= htmlspecialchars($createCdkMessage) ?></div>
    <?php endif; ?>

    <div class="section">
        <h2>我的应用 (<?= count($appList) ?>)</h2>
        <?php if (empty($appList)): ?>
            <p>暂无应用，赶快去创建吧！</p>
        <?php else: ?>
            <ul class="app-list">
                <?php foreach ($appList as $app): ?>
                    <li><a href="app.php?app=<?= urlencode($app) ?>"><?= htmlspecialchars($app) ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="section">
        <button class="btn" onclick="location.href='开发文档'">查看开发文档</button>
        <button class="btn btn-success" style="margin-left: 10px;" onclick="openCreateCdkModal()">创建兑换码</button>
        <button class="btn btn-warning" style="margin-left: 10px;" onclick="openRedeemModal()">兑换金币</button>
    </div>
</div>

<!-- 创建兑换码弹窗 -->
<div class="modal-bg" id="createCdkModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="createCdkTitle">
        <button class="close-btn" aria-label="关闭" onclick="closeCreateCdkModal()">&times;</button>
        <h3 id="createCdkTitle">创建兑换码</h3>
        <form method="post" id="createCdkForm" onsubmit="return onCreateCdkSubmit(event)">
            <label for="amount">兑换码金额 (5%手续费，最多500金币)</label>
            <input type="number" id="amount" name="amount" min="1" max="500" required />
            <button type="submit" name="create_cdk" class="btn btn-success">创建兑换码</button>
        </form>
    </div>
</div>

<!-- 兑换码弹窗 -->
<div class="modal-bg" id="redeemModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="redeemTitle">
        <button class="close-btn" aria-label="关闭" onclick="closeRedeemModal()">&times;</button>
        <h3 id="redeemTitle">兑换金币</h3>
        <form method="post" id="redeemForm">
            <label for="cdk">兑换码</label>
            <input type="text" id="cdk" name="cdk" required autocomplete="off" placeholder="请输入兑换码" />
            <button type="submit" name="redeem_cdk" class="btn btn-warning">立即兑换</button>
        </form>
    </div>
</div>

<script>
    // 创建兑换码弹窗相关
    function openCreateCdkModal() {
        document.getElementById('createCdkModal').style.display = 'flex';
    }

    function closeCreateCdkModal() {
        document.getElementById('createCdkModal').style.display = 'none';
    }

    function onCreateCdkSubmit(e) {
        const amount = parseInt(document.getElementById('amount').value, 10);
        
        if (!amount || amount <= 0) {
            alert('请输入有效的金币数量');
            e.preventDefault();
            return false;
        }
        
        if (amount > 500) {
            alert('单次最多只能创建500金币的兑换码');
            e.preventDefault();
            return false;
        }
        
        if (!confirm(`确定要创建价值${amount}金币的兑换码吗？将扣除${amount}金币+5%手续费（共${Math.ceil(amount * 1.05)}金币）`)) {
            e.preventDefault();
            return false;
        }
        
        closeCreateCdkModal();
        return true;
    }

    // 兑换码弹窗相关
    function openRedeemModal() {
        document.getElementById('redeemModal').style.display = 'flex';
    }

    function closeRedeemModal() {
        document.getElementById('redeemModal').style.display = 'none';
    }
</script>
</body>
</html>