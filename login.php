<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // 与注册代码一致的路径处理
    $userDir = "data/user/" . basename($username);
    $keyFile = "$userDir/key.ini";
    
    if (file_exists($keyFile)) {
        $data = parse_ini_file($keyFile);
        
        // 与注册代码一致的验证方式
        if (isset($data['password_hash']) && password_verify($password, $data['password_hash'])) {
            $_SESSION['username'] = $username;
            
            // 记录登录时间（与主界面代码一致）
            $loginInfoFile = "$userDir/login_info.ini";
            $currentLoginInfo = [
                'last_login_ip' => !empty($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : 
                                 (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']),
                'last_login_time' => date('Y-m-d H:i:s')
            ];
            file_put_contents($loginInfoFile, 
                "last_login_ip = \"{$currentLoginInfo['last_login_ip']}\"\n" .
                "last_login_time = \"{$currentLoginInfo['last_login_time']}\"\n");
            
            header("Location: index.php");
            exit();
        }
    }
    $msg = "用户名或密码错误";
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>登录 - Muzi</title>
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --error-color: #e74c3c;
            --light-gray: #f5f5f5;
            --medium-gray: #e0e0e0;
            --dark-gray: #757575;
            --white: #ffffff;
        }
        
        body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: var(--white);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        
        .container {
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
            background-color: var(--white);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
        }
        
        h1 {
            color: var(--primary-color);
            font-weight: 300;
            margin-bottom: 2rem;
            font-size: 1.8rem;
            text-align: center;
        }
        
        .error-message {
            color: var(--error-color);
            background-color: rgba(231, 76, 60, 0.1);
            padding: 0.8rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 0.9rem;
        }
        
        form {
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }
        
        input {
            padding: 0.9rem 1rem;
            border: 1px solid var(--medium-gray);
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.2s ease;
        }
        
        input:focus {
            outline: none;
            border-color: var(--secondary-color);
        }
        
        input::placeholder {
            color: var(--dark-gray);
            opacity: 0.7;
        }
        
        button {
            background-color: var(--secondary-color);
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
        
        .register-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--dark-gray);
            font-size: 0.9rem;
        }
        
        .register-link a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>登录您的账户</h1>
    <?php if (isset($msg)) echo "<p class='error-message'>$msg</p>"; ?>
    <form method="post">
        <input type="text" name="username" placeholder="用户名" required>
        <input type="password" name="password" placeholder="密码" required>
        <button type="submit">登 录</button>
    </form>
    <p class="register-link">没有账号？<a href="enrol.php">立即注册</a></p>
</div>
</body>
</html>