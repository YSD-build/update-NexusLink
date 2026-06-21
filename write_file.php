<?php
// 简单的文件写入脚本，用于通过POST方式写入文件内容
// 密码保护
$password = 'nexuslink123';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['password']) || $_POST['password'] !== $password) {
        die('密码错误');
    }
    
    $filename = $_POST['filename'] ?? 'test.txt';
    $content = $_POST['content'] ?? '';
    
    // 安全检查：不允许写入上级目录
    $filename = basename($filename);
    
    $result = file_put_contents($filename, $content);
    
    if ($result !== false) {
        echo "成功写入文件: $filename，大小: $result 字节";
    } else {
        echo "写入失败";
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>文件写入工具</title>
</head>
<body>
    <h1>文件写入工具</h1>
    <form method="post">
        <p>
            <label>密码：</label>
            <input type="password" name="password">
        </p>
        <p>
            <label>文件名：</label>
            <input type="text" name="filename" value="test.txt">
        </p>
        <p>
            <label>内容：</label><br>
            <textarea name="content" rows="10" cols="50"></textarea>
        </p>
        <p>
            <button type="submit">写入文件</button>
        </p>
    </form>
</body>
</html>
