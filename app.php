<?php
session_start();
if (!isset($_SESSION['username'])) {
  header('Location: login.php');
  exit;
}
$username = htmlspecialchars(trim($_SESSION['username']), ENT_QUOTES, 'UTF-8');
$app = htmlspecialchars(trim($_GET['app'] ?? ''), ENT_QUOTES, 'UTF-8');
if (!preg_match('/^app_[a-f0-9]{8}$/', $app)) die('非法访问');

$base = __DIR__ . "/data/user/$username";
$appDir = "$base/$app";
if (!is_dir($appDir)) die('应用目录不存在');

$kamiFile = "$appDir/kami.ini";
$ipFile   = "$appDir/ip_code.ini";
$goldFile = "$base/gold.ini";

// 初始化金币文件
if (!file_exists($goldFile)) {
  file_put_contents($goldFile, "100");
}
$gold = intval(file_get_contents($goldFile));

$kamiTypes = [
  '3小时卡' => 10800,
  '天卡'    => 86400,
  '周卡'    => 604800,
  '月卡'    => 2592000,
  '年卡'    => 31536000,
  '永久卡'  => 4070908800
];

function clean($v) { return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8'); }
function fmtTime($s) {
  if ($s <= 0) return '0秒';
  $units = ['年'=>31536000,'月'=>2592000,'天'=>86400,'时'=>3600,'分'=>60,'秒'=>1];
  $out = [];
  foreach($units as $u=>$d){
    if($s >= $d){$v=floor($s/$d);$out[]=$v.$u;$s%=$d;}
    if(count($out)==2) break;
  }
  return implode('', $out);
}

// 读取绑定数据
$ipBind = [];
if (is_file($ipFile)) {
  foreach (file($ipFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $ln) {
    $parts = explode('=', $ln);
    if (count($parts)===3) {
      list($k,$ip,$mc) = $parts;
      $ipBind[$k] = ['ip'=>$ip, 'mc'=>$mc];
    }
  }
}

$msg = ''; $msgClass = 'info';
$now = time();

// 生成卡密部分
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $count = max(1, min(1000, intval($_POST['count'])));
    if ($gold < $count) {
        $msg = "金币不足：生成 {$count} 张需 {$count} 金币";
        $msgClass = 'error';
    } else {
        $prefInput = trim($_POST['prefix'] ?? '');
        $prefClean = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefInput));
        if ($prefClean === '') $prefClean = 'KM';
        $pref = substr($prefClean, 0, 5);

        $length = max(8, min(32, intval($_POST['length'])));
        $type = $_POST['kami_type'] ?? '';
        $custom = intval($_POST['custom_time'] ?? 0);
        $duration = isset($kamiTypes[$type]) ? $kamiTypes[$type] : $custom;
        $create = $now;
        $buffer = '';
        for ($i = 0; $i < $count; $i++) {
            $body = strtoupper(substr(bin2hex(random_bytes(ceil($length / 2))), 0, $length));
            $k = $pref . $body;
            $buffer .= implode('|', [$k, '未使用', $duration, '', '0', '0', $create]) . "\n";
        }
        file_put_contents($kamiFile, $buffer, FILE_APPEND | LOCK_EX);
        $gold -= $count;
        file_put_contents($goldFile, $gold);
        header("Location: ?app=$app&msg=生成成功 {$count} 张&msgClass=success");
        exit;
    }
}

// 解绑卡密
if (isset($_GET['unbind'])) {
  $k = clean($_GET['unbind']);
  unset($ipBind[$k]);
  file_put_contents($ipFile, implode("\n", array_map(
    fn($kk,$v)=>"$kk={$v['ip']}={$v['mc']}", array_keys($ipBind), $ipBind
  )) . "\n", LOCK_EX);
  header("Location: ?app=$app&msg=解绑成功&msgClass=success");
  exit;
}

// 删除单张卡
if (isset($_GET['del'])) {
  $idx=intval($_GET['del']);
  $lines = file($kamiFile, FILE_IGNORE_NEW_LINES) ?: [];
  if (isset($lines[$idx])) {
    $parts = explode('|',$lines[$idx]);
    $code= $parts[0] ?? '';
    unset($lines[$idx]);
    file_put_contents($kamiFile, implode("\n",$lines)."\n",LOCK_EX);
    unset($ipBind[$code]);
    file_put_contents($ipFile, implode("\n", array_map(
      fn($kk,$v)=>"$kk={$v['ip']}={$v['mc']}", array_keys($ipBind), $ipBind
    )) . "\n", LOCK_EX);
    header("Location:?app=$app&msg=已删除 $code&msgClass=success");
    exit;
  }
}

// 批量删除
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['batch_del'])) {
  $sel = array_map('intval', $_POST['selected'] ?? []);
  $lines = file($kamiFile, FILE_IGNORE_NEW_LINES) ?: [];
  $new = [];
  foreach ($lines as $i=>$ln) {
    if (in_array($i,$sel,true)) {
      $p = explode('|',$ln); $c=$p[0]??''; unset($ipBind[$c]);
      continue;
    }
    $new[]=$ln;
  }
  file_put_contents($kamiFile, implode("\n",$new)."\n",LOCK_EX);
  file_put_contents($ipFile, implode("\n", array_map(
    fn($kk,$v)=>"$kk={$v['ip']}={$v['mc']}", array_keys($ipBind), $ipBind
  )) . "\n", LOCK_EX);
  header("Location:?app=$app&msg=批量删除成功&msgClass=success");
  exit;
}

// 构建列表及统计数据
$lines = file($kamiFile, FILE_IGNORE_NEW_LINES) ?: [];
$list = []; 
$stats = ['total'=>0,'unused'=>0,'used'=>0,'expired'=>0,'permanent'=>0];
foreach ($lines as $i=>$ln) {
  $p = explode('|',$ln);
  if (count($p)<7) continue;
  list($code,$st,$t,, $stt,$exp,$ct) = $p;
  $t=intval($t); $stt=intval($stt); $exp=intval($exp); $ct=intval($ct);
  $isPerm = $t===4070908800;
  $status = $st;
  $left = 0;
  $stats['total']++;

  if ($status==='未使用') {
    $stats['unused']++;
  } elseif ($status==='使用中') {
    if ($isPerm) {
      $stats['used']++;
      $stats['permanent']++;
    } elseif ($exp <= $now) {
      $status='已过期';
      $stats['expired']++;
    } else {
      $stats['used']++; // 仅激活且未过期算“使用中”
      $left=$exp-$now;
    }
  } elseif ($status==='已过期') {
    $stats['expired']++;
  }

  $list[]=[
    'i'=>$i, 'code'=>$code, 'type'=>$isPerm?'永久卡':fmtTime($t).'卡',
    'status'=>$status, 'left'=>($isPerm?'永久':fmtTime($left)),
    'ct'=>date('Y-m-d H:i',$ct), 'stt'=>$stt?date('Y-m-d H:i',$stt):'—',
    'exp'=>$exp?date('Y-m-d H:i',$exp):($isPerm?'—':'—'),
    'ip'=>$ipBind[$code]['ip'] ?? '—', 'mc'=>$ipBind[$code]['mc'] ?? '—'
  ];
}

// 获取当前访问IP
function getUserIP() {
  foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
    if (!empty($_SERVER[$key])) {
      $ip = explode(',', $_SERVER[$key])[0];
      if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
  }
  return '未知';
}
$userIP = getUserIP();
$msg = $_GET['msg'] ?? $msg;
$msgClass = $_GET['msgClass'] ?? $msgClass;


$userIP = getUserIP();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>卡密管理 - <?= $app ?></title>
<style>
  /* 极简白风格，灵活布局，全屏宽度 */
  * {
    box-sizing: border-box;
  }
  body {
    margin: 0; padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #fafafa; color: #333; min-height: 100vh; display: flex; flex-direction: column;
    justify-content: flex-start;
  }
  header {
    margin-bottom: 10px;
  }
  main {
    flex: 1;
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
  }
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
  }
  .stats {
    display: flex;
    gap: 20px;
    margin: 8px 0 20px 0;
    font-size: 16px;
    font-weight: 600;
    color: #444;
  }
  .stat-item {
    background: #e8f0fe;
    border-radius: 6px;
    padding: 8px 14px;
    flex: 1;
    text-align: center;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: background 0.3s ease;
  }
  .stat-item:hover {
    background: #d0e2ff;
  }
  .stat-icon {
    font-size: 20px;
  }
  /* 颜色区分 */
  .used { background: #d4edda; color: #155724; }
  .unused { background: #f8d7da; color: #721c24; }
  .expired { background: #cce5ff; color: #004085; }
  .permanent { background: #fff3cd; color: #856404; }

  form.generate {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
    align-items: center;
  }
  form.generate label {
    white-space: nowrap;
    font-weight: 600;
  }
  form.generate input[type=text],
  form.generate input[type=number],
  form.generate select {
    padding: 6px 10px;
    border-radius: 4px;
    border: 1px solid #ccc;
    font-size: 14px;
    min-width: 80px;
  }
  form.generate button {
    background: #007bff;
    border: none;
    color: white;
    padding: 8px 18px;
    font-weight: 600;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
  }
  form.generate button:hover {
    background: #0056b3;
  }
  .custom-time {
    display: none;
  }

  /* 消息提示 */
  .msg {
    padding: 10px 15px;
    border-radius: 5px;
    margin-bottom: 15px;
    font-weight: 600;
  }
  .msg.info { background: #d1ecf1; color: #0c5460; }
  .msg.success { background: #d4edda; color: #155724; }
  .msg.error { background: #f8d7da; color: #721c24; }

  table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;
  table-layout: auto; /* 允许自适应宽度，不固定宽度 */
}
th, td {
  border: 1px solid #ddd;
  padding: 8px 10px;
  text-align: center;
  white-space: normal; /* 允许换行 */
  word-break: break-word; /* 单词断行防溢出 */
}
th {
  background: #f1f3f5;
}

/* 缩小勾选框列宽，居中 */
th:first-child, td:first-child {
  width: 36px;
  text-align: center;
  padding: 4px 6px;
}
input[type=checkbox] {
  width: 18px;
  height: 18px;
  cursor: pointer;
}


  /* 状态列颜色 */
  td.status-未使用 {
    background: #f8d7da; /* 红 */
    color: #721c24;
    font-weight: 600;
  }
  td.status-使用中 {
    background: #d4edda; /* 绿 */
    color: #155724;
    font-weight: 600;
  }
  td.status-已过期 {
    background: #cce5ff; /* 蓝 */
    color: #004085;
    font-weight: 600;
  }
  td.status-永久卡 {
    background: #fff3cd; /* 黄 */
    color: #856404;
    font-weight: 600;
  }

  /* 操作按钮 */
  a.action-link {
    color: #007bff;
    text-decoration: none;
    margin: 0 5px;
    font-weight: 600;
    cursor: pointer;
    transition: color 0.2s ease;
  }
  a.action-link:hover {
    color: #0056b3;
  }

  /* 按钮美化 */
  .actions {
    margin-top: 12px;
    display: flex;
    gap: 12px;
  }
  .actions button {
    background: #007bff;
    border: none;
    color: white;
    font-weight: 600;
    padding: 8px 18px;
    border-radius: 5px;
    cursor: pointer;
    user-select: none;
    transition: background 0.2s ease;
  }
  .actions button:hover {
    background: #0056b3;
  }

  /* 防止换行 */
  .nowrap {
    white-space: nowrap;
  }

  /* 页脚 - 返回应用列表 */
  footer {
    position: fixed;
    bottom: 10px;
    left: 10px;
    font-size: 14px;
  }
  footer a {
    color: #007bff;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
  }
  footer a:hover {
    color: #0056b3;
  }

  /* 让表单内输入框水平排列且不换行 */
  form.generate > * {
    flex-shrink: 0;
  }
  form.generate input, form.generate select {
    min-width: 120px;
  }
  form.generate label {
    min-width: auto;
  }

  /* 响应式调整 */
  @media(max-width: 900px) {
    form.generate {
      flex-wrap: wrap;
    }
  }
</style>
</head>
<body>
<header>
  <div class="topbar">
    <h1>应用: <?= $app ?></h1>
    <div>当前IP: <strong><?= $userIP ?></strong></div>
    <div>金币: <strong><?= $gold ?></strong></div>
  </div>
  <?php if($msg): ?>
  <div class="msg <?= $msgClass ?>"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
 <div class="stats" aria-label="卡密状态统计">
  <div class="stat-item" style="background:#e2e3e5;color:#383d41;">
    <span class="stat-icon">📦</span> 总数量: <?= $stats['total'] ?>
  </div>
  <div class="stat-item used" title="使用中">
    <span class="stat-icon">✔️</span> 使用中: <?= $stats['used'] ?>
  </div>
  <div class="stat-item unused" title="未使用">
    <span class="stat-icon">❌</span> 未使用: <?= $stats['unused'] ?>
  </div>
  <div class="stat-item expired" title="已过期">
    <span class="stat-icon">⌛</span> 已过期: <?= $stats['expired'] ?>
  </div>
  <div class="stat-item permanent" title="永久卡">
    <span class="stat-icon">♾️</span> 永久卡: <?= $stats['permanent'] ?>
  </div>
</div>
</header>
<main>
  <form method="post" class="generate" onsubmit="return checkGenerate()">
    <label>前缀 <input name="prefix" type="text" maxlength="5" placeholder="KM"></label>
    <label>数量 <input name="count" type="number" min="1" max="1000" value="1" placeholder="数量"></label>
    <label>长度 <input name="length" type="number" min="8" max="32" value="12" placeholder="长度"></label>
    <label>卡种 
      <select name="kami_type" id="kami_type" onchange="toggleCustomTime()">
        <?php foreach($kamiTypes as $k=>$v):?>
          <option><?= $k ?></option>
        <?php endforeach;?>
        <option>自定义</option>
      </select>
    </label>
    <label id="customTimeLabel" style="display:none;">
      秒数 <input name="custom_time" type="number" min="1" value="3600" placeholder="秒数">
    </label>
    <button type="submit" name="generate">生成</button>
  </form>

  <form method="post" id="listForm">
    <table aria-label="卡密列表" role="grid">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>卡密</th>
          <th>类型</th>
          <th>状态</th>
          <th>剩余</th>
          <th>创建</th>
          <th>激活</th>
          <th>到期</th>
          <th>IP</th>
          <th>机器码</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($list as $c): 
          $stCls = 'status-' . ($c['status']==='永久卡' ? '永久卡' : $c['status']);
          ?>
        <tr>
          <td><input type="checkbox" name="selected[]" value="<?= $c['i'] ?>"></td>
          <td class="nowrap"><?= $c['code'] ?></td>
          <td><?= $c['type'] ?></td>
          <td class="<?= htmlspecialchars($stCls) ?>"><?= $c['status'] ?></td>
          <td><?= $c['left'] ?></td>
          <td><?= $c['ct'] ?></td>
          <td><?= $c['stt'] ?></td>
          <td><?= $c['exp'] ?></td>
          <td><?= $c['ip'] ?></td>
          <td><?= $c['mc'] ?></td>
          <td>
            <a class="action-link" href="?app=<?= $app ?>&unbind=<?= urlencode($c['code']) ?>" onclick="return confirm('确认解绑卡密?')">解绑</a>
            |
            <a class="action-link" href="?app=<?= $app ?>&del=<?= $c['i'] ?>" onclick="return confirm('确认删除卡密?')">删除</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions">
      <button name="batch_del" type="submit" onclick="return confirm('确认批量删除选中卡密?')">批量删除</button>
      <button type="button" id="exportSelectedBtn">导出选中</button>
    </div>
  </form>
</main>
<footer>
  <a href="index.php" title="返回应用列表">← 返回应用列表</a>
</footer>

<script>
  // 切换自定义秒数输入框显示
  function toggleCustomTime() {
    const sel = document.getElementById('kami_type');
    const customLabel = document.getElementById('customTimeLabel');
    customLabel.style.display = (sel.value === '自定义') ? 'inline-block' : 'none';
  }
  toggleCustomTime();

  // 全选复选框功能
  document.getElementById('selectAll').addEventListener('change', function(){
    const checked = this.checked;
    document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = checked);
  });

  // 导出选中卡密纯前端实现
  document.getElementById('exportSelectedBtn').addEventListener('click', () => {
    const checkedBoxes = Array.from(document.querySelectorAll('input[name="selected[]"]:checked'));
    if (checkedBoxes.length === 0) {
      alert('请先选择要导出的卡密');
      return;
    }
    let codes = checkedBoxes.map(cb => {
      const tr = cb.closest('tr');
      return tr.cells[1].innerText.trim();
    });
    const content = codes.join("\n");
    const now = new Date();
    const pad = n => n.toString().padStart(2, '0');
    const filename = `kami_${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}.txt`;
    const blob = new Blob([content], {type: 'text/plain'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  });

  // 生成卡密前校验
  function checkGenerate() {
    const count = document.querySelector('input[name="count"]').value;
    if (count < 1 || count > 1000) {
      alert('生成数量必须在1到1000之间');
      return false;
    }
    return true;
  }
</script>
</body>
</html>
