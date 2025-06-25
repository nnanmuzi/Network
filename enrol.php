<?php
// 注册页面 by muzi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // 验证用户名格式（英文+数字，不超过16位）
    if (!preg_match('/^[a-zA-Z0-9]{1,16}$/', $username)) {
        $msg = "用户名必须为1-16位英文或数字组合";
    } 
    // 验证密码长度（不超过32位）
    elseif (strlen($password) > 32) {
        $msg = "密码长度不能超过32位";
    }
    // 检查必填字段
    elseif (empty($username) || empty($password)) {
        $msg = "请输入完整信息";
    }
    // 检查用户是否存在
    elseif (file_exists("data/user/$username")) {
        $msg = "用户已存在";
    }
    // 所有验证通过
    else {
        // 生成24位数字+大写字母的UID
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $uid = '';
        for ($i = 0; $i < 24; $i++) {
            $uid .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        mkdir("data/user/$username", 0777, true);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        // 存储用户信息，包括UID
        file_put_contents("data/user/$username/key.ini", 
            "username=\"$username\"\n".
            "password_hash=\"$hash\"\n".
            "uid=\"$uid\"\n");
            
        header("Location: login.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>注册 - Muzi</title>
    <style>
        /* 主色调定义 */
        :root {
            --主色: #2c3e50;        /* 深蓝灰 */
            --辅色: #3498db;       /* 亮蓝色 */
            --错误色: #e74c3c;     /* 红色 */
            --浅灰: #f5f5f5;       /* 背景灰 */
            --中灰: #e0e0e0;       /* 边框灰 */
            --深灰: #757575;       /* 文字灰 */
            --纯白: #ffffff;       /* 纯白色 */
        }
        
        /* 基础页面样式 */
        body {
            font-family: 'Segoe UI', 'Microsoft YaHei', '微软雅黑', sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: var(--纯白);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        /* 注册卡片容器 */
        .container {
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
            background-color: var(--纯白);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
        }
        
        /* 标题样式 */
        h1 {
            color: var(--主色);
            font-weight: 300;
            margin-bottom: 2rem;
            font-size: 1.8rem;
            text-align: center;
        }
        
        /* 错误提示样式 */
        .错误提示 {
            color: var(--错误色);
            background-color: rgba(231, 76, 60, 0.1);
            padding: 0.8rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
        }
        
        /* 表单样式 */
        form {
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }
        
        /* 输入框样式 */
        input {
            padding: 0.9rem 1rem;
            border: 1px solid var(--中灰);
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.2s ease;
        }
        
        input:focus {
            outline: none;
            border-color: var(--辅色);
        }
        
        input::placeholder {
            color: var(--深灰);
            opacity: 0.7;
        }
        
        /* 提示文字样式 */
        .提示文字 {
            font-size: 0.8rem;
            color: var(--深灰);
            margin-top: -0.8rem;
            margin-bottom: -0.5rem;
        }
        
        /* 注册按钮样式 */
        button {
            background-color: var(--辅色);
            color: white;
            border: none;
            padding: 1rem;
            font-size: 1rem;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            margin-top: 0.5rem;
        }
        
        button:hover {
            background-color: #2980b9;
        }
        
        /* 登录链接样式 */
        .登录链接 {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--深灰);
            font-size: 0.9rem;
        }
        
        .登录链接 a {
            color: var(--辅色);
            text-decoration: none;
            font-weight: 500;
        }
        
        .登录链接 a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>创建您的账户</h1>
    <?php if (isset($msg)) echo "<p class='错误提示'>$msg</p>"; ?>
    <form method="post">
        <input type="text" name="username" placeholder="请输入用户名（英文/数字）" required
               pattern="[a-zA-Z0-9]{1,16}" title="1-16位英文或数字">
        <p class="提示文字">用户名必须为1-16位英文或数字组合</p>
        
        <input type="password" name="password" placeholder="请设置登录密码" required
               maxlength="32" title="密码最长32位">
        <p class="提示文字">密码长度不超过32位</p>
        
        <button type="submit">立即注册</button>
    </form>
    <p class="登录链接">已有账号？<a href="login.php">前往登录</a></p>
</div>
</body>
</html>