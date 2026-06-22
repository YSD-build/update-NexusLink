<?php
/**
 * NexusLink 管理后台
 */

require_once 'api/config.php';
require_once 'api/db.php';
require_once 'api/functions.php';
require_once 'api/updater.php';

// 重新设置Content-Type为HTML
header('Content-Type: text/html; charset=utf-8');

// 兼容层
define('DB_PREFIX', TABLE_PREFIX);
function db() {
    return Database::getInstance()->getPdo();
}


// 初始化设置表
function init_settings() {
    $db = db();
    $prefix = DB_PREFIX;
    
    try {
        // 检查设置表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}settings'");
        if (!$stmt->fetch()) {
            // 创建设置表
            $sql = "CREATE TABLE `{$prefix}settings` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `setting_key` varchar(100) NOT NULL COMMENT '设置键',
              `setting_value` text COMMENT '设置值',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `setting_key` (`setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表'";
            $db->exec($sql);
            
            // 插入默认设置
            $default_settings = [
                'site_name' => 'NexusLink',
                'site_url' => '',
                'site_description' => '高性能内网穿透平台',
                'register_enabled' => '1',
                'email_verify_required' => '0',
                'default_traffic_limit' => '100',
                'checkin_reward' => '10',
                'min_port' => '10000',
                'max_port' => '60000',
                'max_tunnels_per_user' => '10',
                'mail_enabled' => '0',
                'mail_host' => '',
                'mail_port' => '465',
                'mail_user' => '',
                'mail_pass' => '',
                'mail_from' => '',
                'mail_from_name' => 'NexusLink',
                'mail_secure' => 'ssl',
            ];
            
            foreach ($default_settings as $key => $value) {
                $stmt = $db->prepare("INSERT INTO {$prefix}settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
            }
        }
    } catch (Exception $e) {
        // 静默失败
    }
}

// 获取设置
function get_setting($key, $default = '') {
    $db = db();
    $prefix = DB_PREFIX;
    
    try {
        $stmt = $db->prepare("SELECT setting_value FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

// 获取所有设置
function get_all_settings() {
    $db = db();
    $prefix = DB_PREFIX;
    
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM {$prefix}settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    } catch (Exception $e) {
        return [];
    }
}

// 保存设置
function save_setting($key, $value) {
    $db = db();
    $prefix = DB_PREFIX;
    
    try {
        $stmt = $db->prepare("SELECT id FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            $stmt = $db->prepare("UPDATE {$prefix}settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $db->prepare("INSERT INTO {$prefix}settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// 初始化设置
init_settings();

// 获取所有设置
$settings = get_all_settings();


// 开启session
session_start();

// 获取当前用户
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $stmt = db()->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 处理退出登录
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 处理登录
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $login_error = '请输入用户名和密码';
    } else {
        // 支持用户名或邮箱登录
        $stmt = db()->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] == 0) {
                $login_error = '账号已被禁用';
            } elseif ($user['role'] != 'admin') {
                $login_error = '无权访问管理后台';
            } else {
                $_SESSION['user_id'] = $user['id'];
                header('Location: admin.php');
                exit;
            }
        } else {
            $login_error = '用户名或密码错误';
        }
    }
}


// 处理保存设置
$settings_saved = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_settings'])) {
    // 站点设置
    if (isset($_POST['site_name'])) {
        save_setting('site_name', trim($_POST['site_name']));
    }
    if (isset($_POST['site_url'])) {
        save_setting('site_url', rtrim(trim($_POST['site_url']), '/'));
    }
    if (isset($_POST['site_description'])) {
        save_setting('site_description', trim($_POST['site_description']));
    }
    if (isset($_POST['register_enabled'])) {
        save_setting('register_enabled', $_POST['register_enabled'] == '1' ? '1' : '0');
    }
    if (isset($_POST['email_verify_required'])) {
        save_setting('email_verify_required', $_POST['email_verify_required'] == '1' ? '1' : '0');
    }
    if (isset($_POST['default_traffic_limit'])) {
        save_setting('default_traffic_limit', intval($_POST['default_traffic_limit']));
    }
    if (isset($_POST['checkin_reward'])) {
        save_setting('checkin_reward', intval($_POST['checkin_reward']));
    }
    
    // 高级设置
    if (isset($_POST['min_port'])) {
        save_setting('min_port', intval($_POST['min_port']));
    }
    if (isset($_POST['max_port'])) {
        save_setting('max_port', intval($_POST['max_port']));
    }
    if (isset($_POST['max_tunnels_per_user'])) {
        save_setting('max_tunnels_per_user', intval($_POST['max_tunnels_per_user']));
    }
    
    // 邮件配置
    if (isset($_POST['mail_enabled'])) {
        save_setting('mail_enabled', $_POST['mail_enabled'] == '1' ? '1' : '0');
    }
    if (isset($_POST['mail_host'])) {
        save_setting('mail_host', trim($_POST['mail_host']));
    }
    if (isset($_POST['mail_port'])) {
        save_setting('mail_port', intval($_POST['mail_port']));
    }
    if (isset($_POST['mail_user'])) {
        save_setting('mail_user', trim($_POST['mail_user']));
    }
    if (isset($_POST['mail_pass'])) {
        save_setting('mail_pass', trim($_POST['mail_pass']));
    }
    if (isset($_POST['mail_from'])) {
        save_setting('mail_from', trim($_POST['mail_from']));
    }
    if (isset($_POST['mail_from_name'])) {
        save_setting('mail_from_name', trim($_POST['mail_from_name']));
    }
    if (isset($_POST['mail_secure'])) {
        save_setting('mail_secure', trim($_POST['mail_secure']));
    }
    
    // 重新加载设置
    $settings = get_all_settings();
    $settings_saved = true;
}

// 处理测试邮件
$test_email_result = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_email']) && $current_user && $current_user['role'] == 'admin') {
    $test_to = trim($_POST['test_email_to'] ?? '');
    
    if (!$test_to) {
        $test_email_result = ['success' => false, 'message' => '请输入测试邮箱地址'];
    } elseif (!filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
        $test_email_result = ['success' => false, 'message' => '邮箱格式不正确'];
    } else {
        // 引入邮件类
        require_once __DIR__ . '/api/mail.php';
        
        $subject = '【' . ($settings['site_name'] ?? 'NexusLink') . '】邮件测试';
        $body = '<div style="padding: 20px; font-family: sans-serif;">
            <h2>🎉 邮件发送成功！</h2>
            <p>恭喜您，NexusLink 邮件服务配置正确！</p>
            <p>这是一封测试邮件，用于验证您的 SMTP 配置是否正常工作。</p>
            <p style="color: #666; font-size: 12px; margin-top: 30px;">
                发送时间：' . date('Y-m-d H:i:s') . '<br>
                收件人：' . htmlspecialchars($test_to) . '
            </p>
        </div>';
        
        $result = Mailer::send($test_to, $subject, $body);
        if ($result) {
            $test_email_result = ['success' => true, 'message' => '测试邮件已发送成功，请查收！'];
        } else {
            $test_email_result = ['success' => false, 'message' => '邮件发送失败，请检查配置是否正确'];
        }
    }
}


// 处理重新生成JWT密钥
$jwt_result = null;
if (isset($_GET['action']) && $_GET['action'] == 'regenerate_jwt' && $current_user && $current_user['role'] == 'admin') {
    $new_secret = bin2hex(random_bytes(32));
    
    // 尝试修改config.php
    $config_file = __DIR__ . '/api/config.php';
    $config_content = file_get_contents($config_file);
    
    if ($config_content !== false) {
        // 替换JWT_SECRET
        $new_config = preg_replace(
            "/define\('JWT_SECRET',\s*'[^']*'\);/",
            "define('JWT_SECRET', '" . $new_secret . "');",
            $config_content
        );
        
        if ($new_config !== $config_content) {
            if (file_put_contents($config_file, $new_config) !== false) {
                $jwt_result = ['success' => true, 'message' => 'JWT密钥已重新生成，所有用户将需要重新登录'];
            } else {
                $jwt_result = ['success' => false, 'message' => '配置文件写入失败，请手动修改config.php中的JWT_SECRET为：' . $new_secret];
            }
        } else {
            $jwt_result = ['success' => false, 'message' => '未找到JWT_SECRET配置项'];
        }
    } else {
        $jwt_result = ['success' => false, 'message' => '无法读取配置文件'];
    }
    
    // 也保存到设置表中
    save_setting('jwt_secret', $new_secret);
    $settings = get_all_settings();
}

// 处理清理过期数据
if (isset($_GET['action']) && $_GET['action'] == 'cleanup_data' && $current_user && $current_user['role'] == 'admin') {
    $db = db();
    $prefix = DB_PREFIX;
    
    // 清理30天前的流量日志
    $stmt = $db->prepare("DELETE FROM {$prefix}traffic_logs WHERE log_date < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    
    $cleanup_message = '过期数据清理完成';
    header('Location: admin.php?action=advanced&cleanup=1');
    exit;
}


// 处理更新相关操作
$update_result = null;
$update_info = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $updater = new Updater();
    
    if (isset($_POST['check_update'])) {
        $update_info = $updater->checkUpdate();
    } elseif (isset($_POST['do_update'])) {
        $update_result = $updater->doUpdate();
        // 更新后重新检查
        $update_info = $updater->checkUpdate();
    } elseif (isset($_POST['rollback']) && !empty($_POST['backup_dir'])) {
        $update_result = $updater->rollback($_POST['backup_dir']);
    }
}

// 获取当前页面
$action = $_GET['action'] ?? 'dashboard';

// 未登录或不是管理员，显示登录页面
if (!$current_user || $current_user['role'] != 'admin'):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NexusLink管理后台">
    <meta name="theme-color" content="#ffffff">
    <meta name="format-detection" content="telephone=no">
    <title>管理后台 - NexusLink</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .auth-page { min-height: 100vh; }
        .admin-badge {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, #d03050 0%, #f0a020 100%);
            color: #fff;
            font-size: 12px;
            border-radius: 20px;
            margin-bottom: 16px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="auth-page">
        <div class="auth-box">
            <div style="text-align: center; margin-bottom: 24px;">
                <span class="admin-badge">管理后台</span>
            </div>
            
            <div class="auth-title">NexusLink Admin</div>
            <div class="auth-subtitle">请使用管理员账号登录</div>
            
            <?php if ($login_error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="login" value="1">
                
                <div class="form-group">
                    <label class="form-label">用户名 / 邮箱</label>
                    <input type="text" name="username" class="form-input" placeholder="请输入管理员用户名或邮箱" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" placeholder="请输入密码" required>
                </div>
                
                <button type="submit" class="btn btn-primary btn-large btn-block">登录管理后台</button>
            </form>
            
            <div class="auth-footer">
                <a href="index.php">← 返回前台</a>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
endif;

// 已登录且是管理员，显示后台界面

// 获取统计数据
function get_admin_stats() {
    $db = db();
    $prefix = DB_PREFIX;
    
    $stats = [];
    
    // 用户总数
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}users");
    $stats['total_users'] = $stmt->fetchColumn();
    
    // 今日新增用户
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}users WHERE DATE(created_at) = CURDATE()");
    $stats['today_users'] = $stmt->fetchColumn();
    
    // 节点总数
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}nodes");
    $stats['total_nodes'] = $stmt->fetchColumn();
    
    // 在线节点数
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}nodes WHERE status = 1");
    $stats['online_nodes'] = $stmt->fetchColumn();
    
    // 隧道总数
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}tunnels");
    $stats['total_tunnels'] = $stmt->fetchColumn();
    
    // 启用的隧道数
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}tunnels WHERE status = 1");
    $stats['active_tunnels'] = $stmt->fetchColumn();
    
    // 总流量
    $stmt = $db->query("SELECT SUM(traffic) FROM {$prefix}users");
    $stats['total_traffic'] = $stmt->fetchColumn() ?: 0;
    
    return $stats;
}

// 格式化流量
function format_traffic($bytes) {
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

$stats = get_admin_stats();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NexusLink管理后台">
    <meta name="theme-color" content="#ffffff">
    <meta name="format-detection" content="telephone=no">
    <title>管理后台 - NexusLink</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* 后台专属样式 */
        .admin-header {
            background: linear-gradient(135deg, #d03050 0%, #f0a020 100%);
            color: #fff;
            padding: 20px 32px;
            margin-bottom: 24px;
            border-radius: var(--radius-xl);
            position: relative;
            overflow: hidden;
        }
        
        .admin-header::before {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            top: -100px;
            right: -50px;
            border-radius: 50%;
        }
        
        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .admin-header p {
            font-size: 14px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }
        
        /* 菜单分组 */
        .menu-group {
            padding: 16px 24px 8px;
            font-size: 12px;
            color: #86909c;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* 表格操作按钮 */
        .btn-xs {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 4px;
        }
        
        /* 状态标签 */
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-badge.success {
            background: var(--success-light);
            color: var(--success-color);
        }
        
        .status-badge.warning {
            background: var(--warning-light);
            color: var(--warning-color);
        }
        
        .status-badge.danger {
            background: var(--error-light);
            color: var(--error-color);
        }
        
        .status-badge.info {
            background: var(--info-light);
            color: var(--info-color);
        }
        
        /* 设置表单 */
        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-light);
        }
        
        .setting-item:last-child {
            border-bottom: none;
        }
        
        .setting-info h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        
        .setting-info p {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        /* 开关样式 */
        .switch {
            position: relative;
            width: 44px;
            height: 24px;
            background: #c9cdd4;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .switch.active {
            background: var(--primary-color);
        }
        
        .switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .switch.active::after {
            left: 22px;
        }
        
        /* 关于页面 */
        .about-logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
        }
        
        .version-info {
            text-align: center;
            padding: 20px;
        }
        
        .version-number {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #2080f0 0%, #722ed1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }
        
        .info-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 20px;
        }
        
        .info-item {
            padding: 12px 16px;
            background: var(--info-light);
            border-radius: var(--radius-medium);
        }
        
        .info-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        /* 更新页面 */
        .update-card {
            text-align: center;
            padding: 40px 20px;
        }
        
        .update-icon {
            width: 64px;
            height: 64px;
            background: var(--success-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        
        .update-status {
            font-size: 18px;
            font-weight: 600;
            color: var(--success-color);
            margin-bottom: 8px;
        }
        
        .update-desc {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- 左侧侧边栏 -->
        <div class="sidebar">
            <div class="sidebar-logo">
                <span class="logo-icon"></span>
                <span style="font-size:12px; color:#d03050; font-weight:600; margin-left:4px;">ADMIN</span>
            </div>
            
            <div class="sidebar-menu">
                <!-- 数据概览 -->
                <a href="admin.php?action=dashboard" class="menu-item <?php echo $action == 'dashboard' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-dashboard"></span>
                    <span class="menu-text">数据概览</span>
                </a>
                
                <!-- 用户管理 -->
                <a href="admin.php?action=users" class="menu-item <?php echo $action == 'users' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-user"></span>
                    <span class="menu-text">用户管理</span>
                </a>
                
                <!-- 节点管理 -->
                <a href="admin.php?action=nodes" class="menu-item <?php echo $action == 'nodes' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-node"></span>
                    <span class="menu-text">节点管理</span>
                </a>
                
                <!-- 隧道管理 -->
                <a href="admin.php?action=tunnels" class="menu-item <?php echo $action == 'tunnels' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-tunnel"></span>
                    <span class="menu-text">隧道管理</span>
                </a>
                
                <!-- 系统管理分组 -->
                <div class="menu-group">系统管理</div>
                
                <a href="admin.php?action=settings" class="menu-item <?php echo $action == 'settings' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-checkin"></span>
                    <span class="menu-text">站点设置</span>
                </a>
                
                <a href="admin.php?action=advanced" class="menu-item <?php echo $action == 'advanced' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-node"></span>
                    <span class="menu-text">高级设置</span>
                </a>
                
                <a href="admin.php?action=update" class="menu-item <?php echo $action == 'update' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-dashboard"></span>
                    <span class="menu-text">系统更新</span>
                </a>
                
                <a href="admin.php?action=about" class="menu-item <?php echo $action == 'about' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-about"></span>
                    <span class="menu-text">关于系统</span>
                </a>
            </div>
            
            <!-- 底部用户区域 -->
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="user-avatar"></div>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?></span>
                        <span class="user-role">管理员</span>
                    </div>
                </div>
                <a href="index.php" class="btn btn-small btn-block" style="margin-bottom:8px;">返回前台</a>
                <a href="admin.php?action=logout" class="btn btn-small btn-block">退出登录</a>
            </div>
        </div>
        
        <!-- 主内容区 -->
        <div class="main-content">
            <!-- 页面标题 -->
            <div class="admin-header">
                <h1>
                    <?php
                    $page_titles = [
                        'dashboard' => '数据概览',
                        'users' => '用户管理',
                        'nodes' => '节点管理',
                        'tunnels' => '隧道管理',
                        'settings' => '站点设置',
                        'advanced' => '高级设置',
                        'update' => '系统更新',
                        'about' => '关于系统'
                    ];
                    echo $page_titles[$action] ?? '管理后台';
                    ?>
                </h1>
                <p>欢迎回来，<?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?>！今天是 <?php echo date('Y年m月d日'); ?></p>
            </div>
            
            <?php if ($action == 'dashboard'): ?>
                <!-- 数据概览 -->
                <div class="stats-grid">
                    <div class="stat-card blue">
                        <div class="stat-label">用户总数</div>
                        <div class="stat-value"><?php echo $stats['total_users']; ?></div>
                        <div style="margin-top:8px; font-size:12px; color:var(--text-secondary);">
                            今日新增 <span style="color:var(--success-color); font-weight:600;">+<?php echo $stats['today_users']; ?></span>
                        </div>
                    </div>
                    
                    <div class="stat-card green">
                        <div class="stat-label">节点总数</div>
                        <div class="stat-value"><?php echo $stats['online_nodes']; ?>/<?php echo $stats['total_nodes']; ?></div>
                        <div style="margin-top:8px; font-size:12px; color:var(--text-secondary);">
                            在线节点
                        </div>
                    </div>
                    
                    <div class="stat-card orange">
                        <div class="stat-label">隧道总数</div>
                        <div class="stat-value"><?php echo $stats['active_tunnels']; ?>/<?php echo $stats['total_tunnels']; ?></div>
                        <div style="margin-top:8px; font-size:12px; color:var(--text-secondary);">
                            运行中
                        </div>
                    </div>
                    
                    <div class="stat-card red">
                        <div class="stat-label">总流量消耗</div>
                        <div class="stat-value"><?php echo format_traffic($stats['total_traffic']); ?></div>
                        <div style="margin-top:8px; font-size:12px; color:var(--text-secondary);">
                            所有用户累计
                        </div>
                    </div>
                </div>
                
                <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px;">
                    <div class="card">
                        <div class="card-title">最近注册用户</div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>用户名</th>
                                    <th>邮箱</th>
                                    <th>注册时间</th>
                                    <th>状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stmt = db()->query("SELECT * FROM " . DB_PREFIX . "users ORDER BY created_at DESC LIMIT 5");
                                $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($recent_users as $user):
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $user['status'] == 1 ? 'success' : 'danger'; ?>">
                                            <?php echo $user['status'] == 1 ? '正常' : '禁用'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="card">
                        <div class="card-title">快捷操作</div>
                        <div style="display:flex; flex-direction:column; gap:12px;">
                            <a href="admin.php?action=users" class="btn btn-block">用户管理</a>
                            <a href="admin.php?action=nodes" class="btn btn-block">节点管理</a>
                            <a href="admin.php?action=tunnels" class="btn btn-block">隧道管理</a>
                            <a href="admin.php?action=settings" class="btn btn-block">站点设置</a>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($action == 'users'): ?>
                <!-- 用户管理 -->
                <div class="card">
                    <div class="action-bar">
                        <div class="action-bar-title">用户列表</div>
                        <button class="btn btn-primary">+ 添加用户</button>
                    </div>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>用户名</th>
                                <th>邮箱</th>
                                <th>角色</th>
                                <th>已用流量</th>
                                <th>状态</th>
                                <th>注册时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = db()->query("SELECT * FROM " . DB_PREFIX . "users ORDER BY id DESC");
                            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($users as $user):
                            ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $user['role'] == 'admin' ? 'danger' : 'info'; ?>">
                                        <?php echo $user['role'] == 'admin' ? '管理员' : '普通用户'; ?>
                                    </span>
                                </td>
                                <td><?php echo format_traffic($user['traffic']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $user['status'] == 1 ? 'success' : 'danger'; ?>">
                                        <?php echo $user['status'] == 1 ? '正常' : '禁用'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                <td>
                                    <button class="btn btn-xs">编辑</button>
                                    <button class="btn btn-xs btn-danger">禁用</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($action == 'nodes'): ?>
                <!-- 节点管理 -->
                <div class="card">
                    <div class="action-bar">
                        <div class="action-bar-title">节点列表</div>
                        <button class="btn btn-primary">+ 添加节点</button>
                    </div>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>节点名称</th>
                                <th>地址</th>
                                <th>端口</th>
                                <th>位置</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = db()->query("SELECT * FROM " . DB_PREFIX . "nodes ORDER BY id DESC");
                            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if ($nodes):
                                foreach ($nodes as $node):
                            ?>
                            <tr>
                                <td><?php echo $node['id']; ?></td>
                                <td><?php echo htmlspecialchars($node['name']); ?></td>
                                <td><?php echo htmlspecialchars($node['host']); ?></td>
                                <td><?php echo $node['port']; ?></td>
                                <td><?php echo htmlspecialchars($node['location']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $node['status'] == 1 ? 'success' : 'danger'; ?>">
                                        <?php echo $node['status'] == 1 ? '在线' : '离线'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-xs">编辑</button>
                                    <button class="btn btn-xs btn-danger">删除</button>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:40px; color:#909399;">暂无节点</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($action == 'tunnels'): ?>
                <!-- 隧道管理 -->
                <div class="card">
                    <div class="action-bar">
                        <div class="action-bar-title">隧道列表</div>
                    </div>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>隧道名称</th>
                                <th>用户</th>
                                <th>节点</th>
                                <th>类型</th>
                                <th>远程端口</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = db()->query("SELECT t.*, u.username, n.name as node_name FROM " . DB_PREFIX . "tunnels t LEFT JOIN " . DB_PREFIX . "users u ON t.user_id = u.id LEFT JOIN " . DB_PREFIX . "nodes n ON t.node_id = n.id ORDER BY t.id DESC");
                            $tunnels = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            if ($tunnels):
                                foreach ($tunnels as $tunnel):
                            ?>
                            <tr>
                                <td><?php echo $tunnel['id']; ?></td>
                                <td><?php echo htmlspecialchars($tunnel['name']); ?></td>
                                <td><?php echo htmlspecialchars($tunnel['username']); ?></td>
                                <td><?php echo htmlspecialchars($tunnel['node_name']); ?></td>
                                <td>
                                    <span class="tag tag-primary"><?php echo strtoupper($tunnel['type']); ?></span>
                                </td>
                                <td><?php echo $tunnel['remote_port']; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $tunnel['status'] == 1 ? 'success' : 'info'; ?>">
                                        <?php echo $tunnel['status'] == 1 ? '启用' : '禁用'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-xs">编辑</button>
                                    <button class="btn btn-xs btn-danger">删除</button>
                                </td>
                            </tr>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:40px; color:#909399;">暂无隧道</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($action == 'settings'): ?>
                <!-- 站点设置 -->
                <div class="card" style="max-width:800px;">
                    <div class="card-title">站点设置</div>
                    
                    <?php if ($settings_saved): ?>
                        <div class="alert alert-success">设置保存成功！</div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>站点名称</h4>
                                <p>设置网站的名称，显示在标题和页面中</p>
                            </div>
                            <input type="text" name="site_name" class="form-input" style="width:200px;" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'NexusLink'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>站点URL</h4>
                                <p>网站的访问地址，用于邮件中的链接生成（如 http://51.tokenr.cn）</p>
                            </div>
                            <input type="text" name="site_url" class="form-input" style="width:300px;" placeholder="http://51.tokenr.cn" value="<?php echo htmlspecialchars($settings['site_url'] ?? ''); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>站点描述</h4>
                                <p>网站的简短描述，用于 SEO 和首页展示</p>
                            </div>
                            <input type="text" name="site_description" class="form-input" style="width:250px;" value="<?php echo htmlspecialchars($settings['site_description'] ?? '高性能内网穿透平台'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>用户注册</h4>
                                <p>开启后允许新用户注册账号</p>
                            </div>
                            <input type="hidden" name="register_enabled" value="0">
                            <label class="switch <?php echo ($settings['register_enabled'] ?? '1') == '1' ? 'active' : ''; ?>">
                                <input type="checkbox" name="register_enabled" value="1" <?php echo ($settings['register_enabled'] ?? '1') == '1' ? 'checked' : ''; ?> style="display:none;">
                            </label>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>邮箱验证</h4>
                                <p>注册时需要验证邮箱才能使用</p>
                            </div>
                            <input type="hidden" name="email_verify_required" value="0">
                            <label class="switch <?php echo ($settings['email_verify_required'] ?? '0') == '1' ? 'active' : ''; ?>">
                                <input type="checkbox" name="email_verify_required" value="1" <?php echo ($settings['email_verify_required'] ?? '0') == '1' ? 'checked' : ''; ?> style="display:none;">
                            </label>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>默认流量限制</h4>
                                <p>新用户注册后默认的流量限制（GB）</p>
                            </div>
                            <input type="number" name="default_traffic_limit" class="form-input" style="width:120px;" value="<?php echo htmlspecialchars($settings['default_traffic_limit'] ?? '100'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>签到奖励</h4>
                                <p>每日签到可获得的免费流量（GB）</p>
                            </div>
                            <input type="number" name="checkin_reward" class="form-input" style="width:120px;" value="<?php echo htmlspecialchars($settings['checkin_reward'] ?? '10'); ?>">
                        </div>
                        
                        <div style="margin-top:24px; text-align:right;">
                            <button type="submit" class="btn btn-primary">保存设置</button>
                        </div>
                    </form>
                </div>

                
            <?php elseif ($action == 'advanced'): ?>
                <!-- 高级设置 -->
                <div class="card" style="max-width:800px;">
                    <div class="card-title">高级设置</div>
                    
                    <?php if ($jwt_result): ?>
                        <div class="alert <?php echo $jwt_result['success'] ? 'alert-success' : 'alert-error'; ?>">
                            <?php echo htmlspecialchars($jwt_result['message']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($settings_saved): ?>
                        <div class="alert alert-success">设置保存成功！</div>
                    <?php endif; ?>
                    
                    <?php if ($settings_saved): ?>
                        <div class="alert alert-success">设置保存成功！</div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>端口范围</h4>
                                <p>用户可使用的远程端口范围</p>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="number" name="min_port" class="form-input" style="width:100px;" value="<?php echo htmlspecialchars($settings['min_port'] ?? '10000'); ?>">
                                <span>-</span>
                                <input type="number" name="max_port" class="form-input" style="width:100px;" value="<?php echo htmlspecialchars($settings['max_port'] ?? '60000'); ?>">
                            </div>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>单用户最大隧道数</h4>
                                <p>每个用户最多可以创建的隧道数量</p>
                            </div>
                            <input type="number" name="max_tunnels_per_user" class="form-input" style="width:120px;" value="<?php echo htmlspecialchars($settings['max_tunnels_per_user'] ?? '10'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>JWT 密钥</h4>
                                <p>用于 API 接口认证的密钥，修改后所有 token 将失效</p>
                            </div>
                            <button type="button" class="btn btn-xs" onclick="if(confirm('确定要重新生成JWT密钥吗？所有已登录用户将被强制退出！')) { location.href='admin.php?action=regenerate_jwt'; }">重新生成</button>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>邮件服务</h4>
                                <p>配置 SMTP 邮件服务器用于发送验证邮件</p>
                            </div>
                            <a href="admin.php?action=mail_config" class="btn btn-xs">配置</a>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>清理过期数据</h4>
                                <p>清理 30 天前的流量日志和过期记录</p>
                            </div>
                            <button type="button" class="btn btn-xs btn-danger" onclick="if(confirm('确定要清理过期数据吗？此操作不可恢复！')) { location.href='admin.php?action=cleanup_data'; }">立即清理</button>
                        </div>
                        
                        <div style="margin-top:24px; text-align:right;">
                            <button type="submit" class="btn btn-primary">保存设置</button>
                        </div>
                    </form>
                </div>

                
            <?php elseif ($action == 'mail_config'): ?>
                <!-- 邮件服务配置 -->
                <div class="card" style="max-width:800px;">
                    <div class="card-title">邮件服务配置</div>
                    
                    <?php if ($settings_saved): ?>
                        <div class="alert alert-success">配置保存成功！</div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="save_settings" value="1">
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>启用邮件服务</h4>
                                <p>开启后可以发送验证邮件、通知邮件等</p>
                            </div>
                            <input type="hidden" name="mail_enabled" value="0">
                            <label class="switch <?php echo ($settings['mail_enabled'] ?? '0') == '1' ? 'active' : ''; ?>">
                                <input type="checkbox" name="mail_enabled" value="1" <?php echo ($settings['mail_enabled'] ?? '0') == '1' ? 'checked' : ''; ?> style="display:none;">
                            </label>
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>SMTP 服务器</h4>
                                <p>邮件服务器地址，如 smtp.qq.com</p>
                            </div>
                            <input type="text" name="mail_host" class="form-input" style="width:250px;" value="<?php echo htmlspecialchars($settings['mail_host'] ?? ''); ?>" placeholder="smtp.example.com">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>SMTP 端口</h4>
                                <p>常用端口：465(SSL)、587(TLS)、25(不加密)</p>
                            </div>
                            <input type="number" name="mail_port" class="form-input" style="width:120px;" value="<?php echo htmlspecialchars($settings['mail_port'] ?? '465'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>邮箱账号</h4>
                                <p>用于发送邮件的邮箱地址</p>
                            </div>
                            <input type="text" name="mail_user" class="form-input" style="width:250px;" value="<?php echo htmlspecialchars($settings['mail_user'] ?? ''); ?>" placeholder="user@example.com">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>邮箱密码/授权码</h4>
                                <p>邮箱密码或SMTP授权码</p>
                            </div>
                            <input type="password" name="mail_pass" class="form-input" style="width:250px;" value="<?php echo htmlspecialchars($settings['mail_pass'] ?? ''); ?>" placeholder="请输入密码">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>发件人邮箱</h4>
                                <p>显示在收件人处的发件邮箱</p>
                            </div>
                            <input type="text" name="mail_from" class="form-input" style="width:250px;" value="<?php echo htmlspecialchars($settings['mail_from'] ?? ''); ?>" placeholder="noreply@example.com">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>发件人名称</h4>
                                <p>显示在收件人处的发件人名称</p>
                            </div>
                            <input type="text" name="mail_from_name" class="form-input" style="width:200px;" value="<?php echo htmlspecialchars($settings['mail_from_name'] ?? 'NexusLink'); ?>">
                        </div>
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>加密方式</h4>
                                <p>SSL 或 TLS</p>
                            </div>
                            <select name="mail_secure" class="form-input" style="width:120px;">
                                <option value="ssl" <?php echo ($settings['mail_secure'] ?? 'ssl') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                <option value="tls" <?php echo ($settings['mail_secure'] ?? 'ssl') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                <option value="" <?php echo ($settings['mail_secure'] ?? 'ssl') == '' ? 'selected' : ''; ?>>不加密</option>
                            </select>
                        </div>
                        
                        <div style="margin-top:24px; text-align:right;">
                            <button type="submit" class="btn btn-primary">保存配置</button>
                        </div>
                    </form>
                </div>
                
                <!-- 测试邮件 -->
                <div class="card" style="max-width:800px; margin-top:20px;">
                    <div class="card-title">测试邮件</div>
                    
                    <?php if ($test_email_result): ?>
                        <div class="alert <?php echo $test_email_result['success'] ? 'alert-success' : 'alert-error'; ?>">
                            <?php echo htmlspecialchars($test_email_result['message']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="test_email" value="1">
                        
                        <div class="setting-item">
                            <div class="setting-info">
                                <h4>测试邮箱地址</h4>
                                <p>输入您的邮箱，发送一封测试邮件验证配置</p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <input type="email" name="test_email_to" class="form-input" style="width:250px;" placeholder="test@example.com" value="<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>">
                                <button type="submit" class="btn btn-primary">发送测试</button>
                            </div>
                        </div>
                    </form>
                </div>
                
            <?php elseif ($action == 'update'): ?>
                <!-- 系统更新 -->
                <?php
                $updater = new Updater();
                // 不自动检查更新，避免页面加载慢，用户手动点击检查
                $backup_list = $updater->getBackupList();
                ?>
                
                <?php if ($update_result): ?>
                <div class="alert <?php echo $update_result['success'] ? 'alert-success' : 'alert-danger'; ?>">
                    <?php echo htmlspecialchars($update_result['message']); ?>
                    <?php if (!empty($update_result['new_version'])): ?>
                        新版本：<?php echo htmlspecialchars($update_result['new_version']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="card" style="max-width:700px;">
                    <div class="card-title">系统更新</div>
                    
                    <div class="info-list">
                        <div class="info-item">
                            <div class="info-label">当前版本</div>
                            <div class="info-value"><?php echo CURRENT_VERSION; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">更新渠道</div>
                            <div class="info-value"><?php echo UPDATE_CHANNEL == 'github' ? 'GitHub' : '自定义'; ?></div>
                        </div>
                        <?php if ($update_info && !empty($update_info['latest_version'])): ?>
                        <div class="info-item">
                            <div class="info-label">最新版本</div>
                            <div class="info-value" style="color:<?php echo $update_info['has_update'] ? 'var(--warning-color)' : 'var(--success-color)'; ?>">
                                <?php echo htmlspecialchars($update_info['latest_version']); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">更新状态</div>
                            <div class="info-value" style="color:<?php echo $update_info['has_update'] ? 'var(--warning-color)' : 'var(--success-color)'; ?>">
                                <?php echo $update_info['has_update'] ? '有新版本可用' : '已是最新版本'; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($update_info && !empty($update_info['release_notes'])): ?>
                    <div style="margin-top:20px;">
                        <h4 style="font-size:14px; font-weight:600; margin-bottom:12px;">更新说明</h4>
                        <div style="padding:16px; background:var(--info-light); border-radius:var(--radius-medium); font-size:13px; line-height:1.6; white-space:pre-wrap;">
                            <?php echo htmlspecialchars($update_info['release_notes']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($update_info['message'])): ?>
                    <div class="alert alert-info" style="margin-top:16px;">
                        <?php echo htmlspecialchars($update_info['message']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:24px; display:flex; gap:12px;">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="check_update" value="1">
                            <button type="submit" class="btn btn-primary">检查更新</button>
                        </form>
                        
                        <?php if ($update_info && $update_info['has_update']): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('确定要更新系统吗？更新前会自动备份当前版本。');">
                            <input type="hidden" name="do_update" value="1">
                            <button type="submit" class="btn btn-warning">立即更新</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- 备份管理 -->
                <div class="card" style="max-width:700px; margin-top:20px;">
                    <div class="card-title">备份管理</div>
                    
                    <?php if ($backup_list): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>版本</th>
                                <th>备份时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backup_list as $backup): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($backup['version']); ?></td>
                                <td><?php echo htmlspecialchars($backup['date']); ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('确定要回滚到该版本吗？');">
                                        <input type="hidden" name="rollback" value="1">
                                        <input type="hidden" name="backup_dir" value="<?php echo htmlspecialchars($backup['backup_dir']); ?>">
                                        <button type="submit" class="btn btn-xs">回滚</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div style="text-align:center; padding:40px; color:#909399;">
                        暂无备份记录
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:16px; font-size:12px; color:var(--text-secondary);">
                        <p>• 每次系统更新前会自动备份当前版本</p>
                        <p>• 备份文件保存在 backups/ 目录下</p>
                        <p>• 如需删除旧备份，请手动删除 backups/ 目录下的对应文件夹</p>
                    </div>
                </div>
                
            <?php elseif ($action == 'about'): ?>
                <!-- 关于系统 -->
                <div class="card" style="max-width:600px;">
                    <div class="version-info">
                        <div class="about-logo">
                            <span class="logo-icon" style="width:80px; height:80px; display:block;"></span>
                        </div>
                        <div class="version-number"><?php echo CURRENT_VERSION; ?></div>
                        <div style="color:var(--text-secondary); margin-bottom:24px;">NexusLink 内网穿透平台</div>
                    </div>
                    
                    <div class="info-list">
                        <div class="info-item">
                            <div class="info-label">系统名称</div>
                            <div class="info-value">NexusLink</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">版本号</div>
                            <div class="info-value"><?php echo CURRENT_VERSION; ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">发布日期</div>
                            <div class="info-value">2026-06-20</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">PHP 版本</div>
                            <div class="info-value"><?php echo phpversion(); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">数据库</div>
                            <div class="info-value">MySQL</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">服务器时间</div>
                            <div class="info-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                        </div>
                    </div>
                    
                    <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border-light); text-align:center; color:var(--text-secondary); font-size:13px;">
                        <p>© 2026 NexusLink. All rights reserved.</p>
                        <p style="margin-top:4px;">高性能内网穿透解决方案</p>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
    
    <div class="footer">
        <div class="container">
            <p>© 2026 NexusLink 管理后台. All rights reserved.</p>
        </div>
    </div>

<script>
// 开关按钮交互
document.addEventListener('DOMContentLoaded', function() {
    var switches = document.querySelectorAll('.switch');
    switches.forEach(function(sw) {
        var checkbox = sw.querySelector('input[type="checkbox"]');
        if (checkbox) {
            // 监听checkbox的change事件
            checkbox.addEventListener('change', function() {
                if (checkbox.checked) {
                    sw.classList.add('active');
                } else {
                    sw.classList.remove('active');
                }
            });
            // 初始化状态
            if (checkbox.checked) {
                sw.classList.add('active');
            } else {
                sw.classList.remove('active');
            }
        }
    });
});
</script>

</body>
</html>
