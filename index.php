<?php
// 引入配置和函数
require_once 'api/config.php';

// 前端页面需要设置为HTML格式，覆盖config.php里的application/json
header('Content-Type: text/html; charset=utf-8');

require_once 'api/db.php';
require_once 'api/functions.php';

// 兼容旧代码：定义DB_PREFIX和db()函数
define('DB_PREFIX', TABLE_PREFIX);
function db() {
    return Database::getInstance()->getPdo();
}

// 自动检查并安装数据库表
function auto_install() {
    $db = db();
    $prefix = DB_PREFIX;
    
    try {
        // 检查 nl_checkins 表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}checkins'");
        if (!$stmt->fetch()) {
            // 创建签到表
            $sql = "CREATE TABLE `{$prefix}checkins` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int unsigned NOT NULL COMMENT '用户ID',
              `checkin_date` date NOT NULL COMMENT '签到日期',
              `traffic_reward` bigint NOT NULL DEFAULT 0 COMMENT '奖励流量(字节)',
              `continuous_days` int NOT NULL DEFAULT 1 COMMENT '连续签到天数',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `user_date` (`user_id`, `checkin_date`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='签到记录表'";
            $db->exec($sql);
        }
        
        // 检查 nl_users 表是否有 email_verified 字段
        $stmt = $db->query("SHOW COLUMNS FROM `{$prefix}users` LIKE 'email_verified'");
        if (!$stmt->fetch()) {
            // 添加 email_verified 字段
            $sql = "ALTER TABLE `{$prefix}users` ADD COLUMN `email_verified` tinyint NOT NULL DEFAULT 0 COMMENT '邮箱验证状态: 0未验证, 1已验证' AFTER `status`";
            $db->exec($sql);
        }
    } catch (Exception $e) {
        // 静默失败，不影响正常使用
    }
}

// 执行自动安装
auto_install();

// 开启session
session_start();

// 获取当前action
$action = $_GET['action'] ?? 'home';

// 获取当前用户
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $post_action = $_POST['action'] ?? '';
    
    // 登录处理
    if ($post_action == 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$username || !$password) {
            $error = '请输入邮箱和密码';
        } else {
            $db = db();
            // 支持邮箱或用户名登录
            $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] == 0) {
                    $error = '账号已被禁用';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    header('Location: index.php?action=dashboard');
                    exit;
                }
            } else {
                $error = '邮箱或密码错误';
            }
        }
    }
    
    // 注册处理
    if ($post_action == 'register') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $nickname = trim($_POST['nickname'] ?? '');
        
        $error = '';
        if (!$username || !$password || !$email) {
            $error = '请填写必填项';
        } elseif (!validate_username($username)) {
            $error = '用户名格式不正确（3-50位字母数字下划线）';
        } elseif (!validate_email($email)) {
            $error = '邮箱格式不正确';
        } elseif (!validate_password($password)) {
            $error = '密码长度至少6位';
        } elseif ($password !== $confirm_password) {
            $error = '两次输入的密码不一致';
        } else {
            $db = db();
            
            // 检查用户名是否存在
            $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = '用户名已存在';
            } else {
                // 检查邮箱是否存在
                $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = '邮箱已被注册';
                } else {
                    // 创建用户
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "users (username, email, password, nickname, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW())");
                    $stmt->execute([$username, $email, $hashed_password, $nickname ?: $username]);
                    
                    $success = '注册成功，请登录';
                }
            }
        }
    }
    
    // 退出登录
    if ($post_action == 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    
    // 找回密码 - 发送重置邮件
    if ($post_action == 'forgot_password') {
        $email = trim($_POST['email'] ?? '');
        
        if (!$email) {
            $error = '请输入邮箱';
        } elseif (!validate_email($email)) {
            $error = '邮箱格式不正确';
        } else {
            $db = db();
            $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // 生成重置令牌
                $token = random_str(32);
                $expire = date('Y-m-d H:i:s', time() + 3600); // 1小时有效
                
                // 保存重置令牌（这里简化处理，实际应该存在单独的表中）
                // 为了简单，我们直接用JWT的方式生成token
                $reset_token = jwt_encode([
                    'user_id' => $user['id'],
                    'email' => $email,
                    'exp' => time() + 3600
                ]);
                
                // 发送重置密码邮件
                require_once 'api/mail.php';
                $mailer = new Mailer();
                $reset_url = 'http://' . $_SERVER['HTTP_HOST'] . '/index.php?action=reset_password&token=' . $reset_token;
                $sent = $mailer->sendResetPasswordEmail($email, $user['username'], $reset_url);
                
                if ($sent) {
                    $success = '重置密码邮件已发送，请查收邮箱';
                } else {
                    $error = '邮件发送失败，请稍后重试';
                }
            } else {
                // 为了安全，不提示邮箱是否存在
                $success = '如果该邮箱已注册，重置密码邮件已发送';
            }
        }
    }
    
    // 重置密码
    if ($post_action == 'reset_password') {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!$token) {
            $error = '无效的重置链接';
        } elseif (!$password) {
            $error = '请输入新密码';
        } elseif (!validate_password($password)) {
            $error = '密码长度至少6位';
        } elseif ($password !== $confirm_password) {
            $error = '两次输入的密码不一致';
        } else {
            // 验证token
            $decoded = jwt_decode($token);
            if (!$decoded || !isset($decoded['user_id']) || $decoded['exp'] < time()) {
                $error = '重置链接无效或已过期';
            } else {
                $db = db();
                $user_id = $decoded['user_id'];
                
                // 更新密码
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE " . DB_PREFIX . "users SET password = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$hashed_password, $user_id]);
                
                if ($result) {
                    $success = '密码重置成功，请登录';
                    header('Location: index.php?action=login&reset=success');
                    exit;
                } else {
                    $error = '密码重置失败，请稍后重试';
                }
            }
        }
    }
    
    // 创建隧道
    if ($post_action == 'create_tunnel' && $current_user) {
        $name = trim($_POST['name'] ?? '');
        $node_id = intval($_POST['node_id'] ?? 0);
        $type = $_POST['type'] ?? 'tcp';
        $local_addr = trim($_POST['local_addr'] ?? '127.0.0.1');
        $local_port = intval($_POST['local_port'] ?? 0);
        $remote_port = intval($_POST['remote_port'] ?? 0);
        
        $error = '';
        if (!$name) {
            $error = '请输入隧道名称';
        } elseif (!$node_id) {
            $error = '请选择节点';
        } elseif (!$local_port) {
            $error = '请输入本地端口';
        } elseif (!$remote_port) {
            $error = '请输入远程端口';
        } else {
            $db = db();
            
            // 检查节点是否存在
            $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "nodes WHERE id = ? AND status = 1");
            $stmt->execute([$node_id]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$node) {
                $error = '节点不存在或不可用';
            } else {
                // 检查远程端口是否已被占用
                $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "tunnels WHERE node_id = ? AND remote_port = ?");
                $stmt->execute([$node_id, $remote_port]);
                if ($stmt->fetch()) {
                    $error = '该远程端口已被占用，请换一个';
                } else {
                    // 创建隧道
                    $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "tunnels (user_id, node_id, name, type, local_addr, local_port, remote_port, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
                    $stmt->execute([$current_user['id'], $node_id, $name, $type, $local_addr, $local_port, $remote_port]);
                    
                    $success = '隧道创建成功！';
                    header('Location: index.php?action=tunnels');
                    exit;
                }
            }
        }
    }
    
    // 删除隧道
    if ($post_action == 'delete_tunnel' && $current_user) {
        $tunnel_id = intval($_POST['tunnel_id'] ?? 0);
        
        if ($tunnel_id) {
            $db = db();
            $stmt = $db->prepare("DELETE FROM " . DB_PREFIX . "tunnels WHERE id = ? AND user_id = ?");
            $stmt->execute([$tunnel_id, $current_user['id']]);
        }
        
        header('Location: index.php?action=tunnels');
        exit;
    }
    
    // 编辑隧道
    if ($post_action == 'edit_tunnel' && $current_user) {
        $tunnel_id = intval($_POST['tunnel_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $local_addr = trim($_POST['local_addr'] ?? '127.0.0.1');
        $local_port = intval($_POST['local_port'] ?? 0);
        $remote_port = intval($_POST['remote_port'] ?? 0);
        
        $error = '';
        if (!$tunnel_id) {
            $error = '参数错误';
        } elseif (!$name) {
            $error = '请输入隧道名称';
        } elseif (!$local_port) {
            $error = '请输入本地端口';
        } elseif (!$remote_port) {
            $error = '请输入远程端口';
        } else {
            $db = db();
            
            // 检查隧道是否存在且属于当前用户
            $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "tunnels WHERE id = ? AND user_id = ?");
            $stmt->execute([$tunnel_id, $current_user['id']]);
            $tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tunnel) {
                $error = '隧道不存在';
            } else {
                // 如果远程端口变了，检查是否被占用
                if ($remote_port != $tunnel['remote_port']) {
                    $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "tunnels WHERE node_id = ? AND remote_port = ? AND id != ?");
                    $stmt->execute([$tunnel['node_id'], $remote_port, $tunnel_id]);
                    if ($stmt->fetch()) {
                        $error = '该远程端口已被占用，请换一个';
                    }
                }
                
                if (!$error) {
                    // 更新隧道
                    $stmt = $db->prepare("UPDATE " . DB_PREFIX . "tunnels SET name = ?, local_addr = ?, local_port = ?, remote_port = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $local_addr, $local_port, $remote_port, $tunnel_id, $current_user['id']]);
                    
                    $success = '隧道修改成功！';
                    header('Location: index.php?action=tunnels');
                    exit;
                }
            }
        }
    }
    
    // 切换隧道状态
    if ($post_action == 'toggle_tunnel' && $current_user) {
        $tunnel_id = intval($_POST['tunnel_id'] ?? 0);
        
        if ($tunnel_id) {
            $db = db();
            
            // 检查隧道是否存在且属于当前用户
            $stmt = $db->prepare("SELECT status FROM " . DB_PREFIX . "tunnels WHERE id = ? AND user_id = ?");
            $stmt->execute([$tunnel_id, $current_user['id']]);
            $tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tunnel) {
                $new_status = $tunnel['status'] == 1 ? 0 : 1;
                $stmt = $db->prepare("UPDATE " . DB_PREFIX . "tunnels SET status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$new_status, $tunnel_id, $current_user['id']]);
            }
        }
        
        header('Location: index.php?action=tunnels');
        exit;
    }
    
    // 修改密码
    if ($post_action == 'change_password' && $current_user) {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $error = '';
        if (!$old_password || !$new_password) {
            $error = '请填写完整信息';
        } elseif (!validate_password($new_password)) {
            $error = '新密码长度至少6位';
        } elseif ($new_password !== $confirm_password) {
            $error = '两次输入的新密码不一致';
        } else {
            $db = db();
            $stmt = $db->prepare("SELECT password FROM " . DB_PREFIX . "users WHERE id = ?");
            $stmt->execute([$current_user['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                $error = '原密码错误';
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE " . DB_PREFIX . "users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $current_user['id']]);
                
                $success = '密码修改成功！';
            }
        }
    }
    
    // 签到领流量
    if ($post_action == 'checkin' && $current_user) {
        $db = db();
        $today = date('Y-m-d');
        
        // 检查今日是否已签到
        $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "checkins WHERE user_id = ? AND checkin_date = ?");
        $stmt->execute([$current_user['id'], $today]);
        if ($stmt->fetch()) {
            $error = '今日已签到，明天再来吧';
        } else {
            // 计算连续签到天数
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $db->prepare("SELECT continuous_days FROM " . DB_PREFIX . "checkins WHERE user_id = ? AND checkin_date = ?");
            $stmt->execute([$current_user['id'], $yesterday]);
            $last_checkin = $stmt->fetch(PDO::FETCH_ASSOC);
            $continuous_days = $last_checkin ? intval($last_checkin['continuous_days']) + 1 : 1;
            
            // 计算奖励流量（半公益性质，奖励丰厚）
            $base_reward = 10 * 1024 * 1024 * 1024; // 基础 10GB
            $extra_reward = 0;
            
            // 连续签到奖励
            if ($continuous_days % 7 == 0) {
                $extra_reward += 5 * 1024 * 1024 * 1024; // 每7天额外 5GB
            }
            if ($continuous_days % 30 == 0) {
                $extra_reward += 10 * 1024 * 1024 * 1024; // 每30天额外 10GB
            }
            
            $total_reward = $base_reward + $extra_reward;
            
            // 插入签到记录
            $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "checkins (user_id, checkin_date, traffic_reward, continuous_days) VALUES (?, ?, ?, ?)");
            $stmt->execute([$current_user['id'], $today, $total_reward, $continuous_days]);
            
            // 更新用户流量限制
            $stmt = $db->prepare("UPDATE " . DB_PREFIX . "users SET traffic_limit = traffic_limit + ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$total_reward, $current_user['id']]);
            
            // 更新当前用户信息
            $current_user['traffic_limit'] += $total_reward;
            
            $success = '签到成功！获得 ' . format_traffic($total_reward) . ' 流量' . ($extra_reward > 0 ? '（含连续签到奖励）' : '');
        }
    }
}

// 获取节点列表
function get_nodes() {
    $db = db();
    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "nodes WHERE status = 1 ORDER BY id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取用户隧道列表
function get_user_tunnels($user_id) {
    $db = db();
    $stmt = $db->prepare("SELECT t.*, n.name as node_name, n.host as node_host FROM " . DB_PREFIX . "tunnels t LEFT JOIN " . DB_PREFIX . "nodes n ON t.node_id = n.id WHERE t.user_id = ? ORDER BY t.id DESC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 格式化流量
function format_traffic($bytes) {
    if (!$bytes) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// 获取用户签到信息
function get_checkin_info($user_id) {
    $db = db();
    $today = date('Y-m-d');
    
    // 今日是否已签到
    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "checkins WHERE user_id = ? AND checkin_date = ?");
    $stmt->execute([$user_id, $today]);
    $today_checkin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 连续签到天数
    $continuous_days = 0;
    if ($today_checkin) {
        $continuous_days = intval($today_checkin['continuous_days']);
    } else {
        // 看昨天有没有签到
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stmt = $db->prepare("SELECT continuous_days FROM " . DB_PREFIX . "checkins WHERE user_id = ? AND checkin_date = ?");
        $stmt->execute([$user_id, $yesterday]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $continuous_days = $last ? intval($last['continuous_days']) : 0;
    }
    
    // 累计获得流量
    $stmt = $db->prepare("SELECT SUM(traffic_reward) as total FROM " . DB_PREFIX . "checkins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_reward = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_traffic = intval($total_reward['total'] ?? 0);
    
    // 最近7天签到记录
    $records = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $stmt = $db->prepare("SELECT id FROM " . DB_PREFIX . "checkins WHERE user_id = ? AND checkin_date = ?");
        $stmt->execute([$user_id, $date]);
        $records[] = [
            'date' => $date,
            'checked' => $stmt->fetch() ? true : false,
            'day' => date('m-d', strtotime($date))
        ];
    }
    
    return [
        'today_checked' => $today_checkin ? true : false,
        'continuous_days' => $continuous_days,
        'total_traffic' => $total_traffic,
        'today_reward' => $today_checkin ? intval($today_checkin['traffic_reward']) : 0,
        'records' => $records
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="NexusLink">
    <meta name="theme-color" content="#ffffff">
    <meta name="format-detection" content="telephone=no">
    <title>NexusLink 内网穿透平台</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php if (!$current_user): ?>

    <?php if ($action == 'login'): ?>
        <!-- 登录页面 -->
        <div class="auth-page">
            <div class="auth-box">
                <div class="auth-title">NexusLink</div>
                <div class="auth-subtitle">内网穿透平台</div>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="text" name="username" class="form-input" placeholder="请输入邮箱" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">密码</label>
                        <input type="password" name="password" class="form-input" placeholder="请输入密码">
                    </div>
                    
                    <div class="forgot-password">
                        <a href="index.php?action=forgot">忘记密码？</a>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-large btn-block">登 录</button>
                </form>
                
                <div class="auth-footer">
                    还没有账号？<a href="index.php?action=register">立即注册</a>
                </div>
                
                <div class="auth-footer" style="margin-top:10px;">
                    <a href="index.php">← 返回首页</a>
                </div>
            </div>
        </div>

    <?php elseif ($action == 'register'): ?>
        <!-- 注册页面 -->
        <div class="auth-page">
            <div class="auth-box">
                <div class="auth-title">用户注册</div>
                <div class="auth-subtitle">加入 NexusLink 内网穿透平台</div>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <input type="hidden" name="action" value="register">
                    
                    <div class="form-group">
                        <label class="form-label">用户名 *</label>
                        <input type="text" name="username" class="form-input" placeholder="3-50位字母数字下划线" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">邮箱 *</label>
                        <input type="email" name="email" class="form-input" placeholder="用于找回密码和接收通知" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">密码 *</label>
                        <input type="password" name="password" class="form-input" placeholder="6-50位">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">确认密码 *</label>
                        <input type="password" name="confirm_password" class="form-input" placeholder="再次输入密码">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">昵称</label>
                        <input type="text" name="nickname" class="form-input" placeholder="可选" value="<?php echo htmlspecialchars($_POST['nickname'] ?? ''); ?>">
                    </div>
                    
                    <div class="alert alert-info" style="margin-bottom:20px;">
                        注册后请牢记您的账号密码，以便后续使用
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-large btn-block">注 册</button>
                </form>
                
                <div class="auth-footer">
                    已有账号？<a href="index.php?action=login">立即登录</a>
                </div>
                
                <div class="auth-footer" style="margin-top:10px;">
                    <a href="index.php">← 返回首页</a>
                </div>
            </div>
        </div>

    <?php elseif ($action == 'forgot'): ?>
        <!-- 找回密码页面 -->
        <div class="auth-page">
            <div class="auth-box">
                <div class="auth-title">找回密码</div>
                <div class="auth-subtitle">输入邮箱，我们将发送重置链接</div>
                
                <div class="alert alert-info">
                    请输入您注册时使用的邮箱地址，我们将发送重置密码的链接到您的邮箱。
                </div>
                
                <form method="post" action="">
                    <div class="form-group">
                        <label class="form-label">邮箱地址</label>
                        <input type="email" name="email" class="form-input" placeholder="请输入邮箱地址">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-large btn-block">发送重置链接</button>
                </form>
                
                <div class="auth-footer">
                    <a href="index.php?action=login">← 返回登录</a>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 首页（未登录） -->
        <div class="header">
            <div class="container">
                <div class="header-inner">
                    <div class="logo">
                        <span class="logo-icon"></span>
                        NexusLink
                    </div>
                    <div class="nav">
                        <a href="index.php" class="active">首页</a>
                        <a href="#features">特性</a>
                        <a href="#nodes">节点</a>
                    </div>
                    <div class="user-area">
                        <a href="index.php?action=login" class="btn">登录</a>
                        <a href="index.php?action=register" class="btn btn-primary">注册</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="main">
            <div class="container">
                <!-- Hero区域 -->
                <div class="hero">
                    <h1 class="hero-title">高性能内网穿透平台</h1>
                    <p class="hero-subtitle">安全、稳定、高速的内网穿透解决方案，让您的服务随时随地可访问</p>
                    <div class="hero-buttons">
                        <a href="index.php?action=register" class="btn btn-primary btn-large">立即开始</a>
                        <a href="#features" class="btn btn-large">了解更多</a>
                    </div>
                </div>

                <!-- 特性区域 -->
                <div class="features" id="features">
                    <h2 class="features-title">为什么选择 NexusLink？</h2>
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon">🔒</div>
                            <h3 class="feature-title">安全可靠</h3>
                            <p class="feature-desc">每数据包HMAC-SHA256认证，防篡改、防重放、防注入，保障您的数据安全</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">⚡</div>
                            <h3 class="feature-title">高性能</h3>
                            <p class="feature-desc">自主研发的高性能转发引擎，总损耗低于0.3%，比传统方案快10倍以上</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🌐</div>
                            <h3 class="feature-title">多节点支持</h3>
                            <p class="feature-desc">全国多个节点可选，智能路由，就近接入，确保最佳访问速度</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">📱</div>
                            <h3 class="feature-title">全平台支持</h3>
                            <p class="feature-desc">支持Windows、Linux、macOS、Android等多平台，随时随地使用</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">🎯</div>
                            <h3 class="feature-title">简单易用</h3>
                            <p class="feature-desc">可视化管理面板，一键创建隧道，新手也能快速上手</p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">💰</div>
                            <h3 class="feature-title">免费使用</h3>
                            <p class="feature-desc">基础功能完全免费，满足个人用户日常使用需求</p>
                        </div>
                    </div>
                </div>

                <!-- 节点列表 -->
                <div class="card" id="nodes">
                    <div class="card-title">
                        <span style="font-size:18px;">🖥️ 节点列表</span>
                    </div>
                    <div class="node-grid">
                        <?php
                        $nodes = get_nodes();
                        if ($nodes):
                            foreach ($nodes as $node):
                        ?>
                        <div class="node-card">
                            <div class="node-name"><?php echo htmlspecialchars($node['name']); ?></div>
                            <div class="node-location">📍 <?php echo htmlspecialchars($node['location']); ?></div>
                            <div class="node-info">
                                <div>地址: <?php echo htmlspecialchars($node['host']); ?>:<?php echo htmlspecialchars($node['port']); ?></div>
                                <div>端口范围: <?php echo htmlspecialchars($node['min_port']); ?> - <?php echo htmlspecialchars($node['max_port']); ?></div>
                                <div><span class="node-status">● 在线</span></div>
                            </div>
                            <?php if ($node['description']): ?>
                            <div class="node-desc"><?php echo htmlspecialchars($node['description']); ?></div>
                            <?php endif; ?>
                            <div style="margin-top:12px;">
                                <a href="index.php?action=register" class="btn btn-primary btn-small">创建隧道</a>
                            </div>
                        </div>
                        <?php
                            endforeach;
                        else:
                        ?>
                        <div style="text-align:center; padding:40px; color:#909399;">
                            暂无节点
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">
            <div class="container">
                <p>© 2026 NexusLink 内网穿透平台. All rights reserved.</p>
            </div>
        </div>

    <?php endif; ?>

<?php else: ?>

    <!-- 已登录 - 控制台布局 -->
    <div class="layout">
        <!-- 左侧侧边栏 -->
        <div class="sidebar">
            <div class="sidebar-logo">
                <span class="logo-icon"></span>
                NexusLink
            </div>
            <div class="sidebar-menu">
                <a href="index.php?action=dashboard" class="menu-item <?php echo $action == 'dashboard' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-dashboard"></span>
                    <span class="menu-text">控制台</span>
                </a>
                <a href="index.php?action=tunnels" class="menu-item <?php echo $action == 'tunnels' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-tunnel"></span>
                    <span class="menu-text">我的隧道</span>
                </a>
                <a href="index.php?action=nodes" class="menu-item <?php echo $action == 'nodes' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-node"></span>
                    <span class="menu-text">节点列表</span>
                </a>
                <a href="index.php?action=checkin" class="menu-item <?php echo $action == 'checkin' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-checkin"></span>
                    <span class="menu-text">每日签到</span>
                </a>
                <a href="index.php?action=profile" class="menu-item <?php echo $action == 'profile' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-user"></span>
                    <span class="menu-text">个人中心</span>
                </a>
                <a href="index.php?action=help" class="menu-item <?php echo $action == 'help' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-help"></span>
                    <span class="menu-text">帮助中心</span>
                </a>
                <a href="index.php?action=about" class="menu-item <?php echo $action == 'about' ? 'active' : ''; ?>">
                    <span class="menu-icon icon-about"></span>
                    <span class="menu-text">关于我们</span>
                </a>
            </div>
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <span class="user-avatar"></span>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?></span>
                        <span class="user-role"><?php echo $current_user['role'] == 'admin' ? '管理员' : '普通用户'; ?></span>
                    </div>
                </div>
                <form method="post" action="">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-small btn-block btn-logout">退出登录</button>
                </form>
            </div>
        </div>
        
        <!-- 右侧主内容区 -->
        <div class="main-content">
            <?php if ($action == 'dashboard' || $action == 'home'): ?>
                <div class="welcome-section">
                    <div class="welcome-title">欢迎回来，<?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?></div>
                    <div class="welcome-desc">开始使用 NexusLink 内网穿透，让您的服务随时随地可访问</div>
                    <div class="welcome-buttons">
                        <a href="index.php?action=tunnels" class="btn btn-primary">创建隧道</a>
                        <a href="index.php?action=nodes" class="btn">查看节点</a>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card blue">
                        <div class="stat-label">我的隧道</div>
                        <div class="stat-value">
                            <?php
                            $tunnels = get_user_tunnels($current_user['id']);
                            echo count($tunnels);
                            ?>
                        </div>
                    </div>
                    <div class="stat-card green">
                        <div class="stat-label">在线节点</div>
                        <div class="stat-value">
                            <?php
                            $nodes = get_nodes();
                            echo count($nodes);
                            ?>
                        </div>
                    </div>
                    <div class="stat-card orange">
                        <div class="stat-label">已用流量</div>
                        <div class="stat-value"><?php echo format_traffic($current_user['traffic']); ?></div>
                    </div>
                    <div class="stat-card red">
                        <div class="stat-label">剩余流量</div>
                        <div class="stat-value"><?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit'] - $current_user['traffic']) : '不限'; ?></div>
                    </div>
                </div>

                <!-- 签到卡片 -->
                <?php
                $checkin_info = get_checkin_info($current_user['id']);
                ?>
                <div class="checkin-card">
                    <div class="checkin-card-content">
                        <div>
                            <div style="font-size:18px; font-weight:600; margin-bottom:8px;">
                                📅 每日签到领流量
                            </div>
                            <div style="opacity:0.9; font-size:14px;">
                                <?php if ($checkin_info['today_checked']): ?>
                                    ✅ 今日已签到 · 连续 <?php echo $checkin_info['continuous_days']; ?> 天
                                <?php else: ?>
                                    👋 今日还未签到 · 基础奖励 10 GB
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if (!$checkin_info['today_checked']): ?>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="checkin">
                                <button type="submit" class="btn btn-glass">
                                    立即签到
                                </button>
                            </form>
                            <?php else: ?>
                            <a href="index.php?action=checkin" class="btn btn-glass-light">
                                查看详情
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">快速开始</div>
                    <div style="color:#606266; line-height:1.8;">
                        <p>1. 点击左侧「我的隧道」或上方按钮创建您的第一条隧道</p>
                        <p>2. 选择节点，填写本地端口和远程端口</p>
                        <p>3. 下载客户端，使用配置文件启动</p>
                        <p>4. 享受高速稳定的内网穿透服务！</p>
                    </div>
                </div>

            <?php elseif ($action == 'create_tunnel'): ?>
                <?php
                $selected_node_id = isset($_GET['node_id']) ? intval($_GET['node_id']) : (isset($_POST['node_id']) ? intval($_POST['node_id']) : 0);
                $nodes = get_nodes();
                ?>
                
                <?php if (!$selected_node_id): ?>
                    <!-- 第一步：选择节点 -->
                    <div class="card">
                        <div class="card-title">创建隧道</div>
                        
                        <!-- 引导提示 -->
                        <div class="info-card">
                            <div class="info-icon">i</div>
                            <div class="info-content">
                                <div class="info-title">如何选择节点？</div>
                                <div class="info-desc">选择离你最近的节点以获得最佳速度。不同节点支持的端口范围和线路类型可能不同，请根据实际需求选择。</div>
                            </div>
                        </div>
                        
                        <!-- 筛选标签 -->
                        <div class="filter-bar">
                            <div class="filter-tabs">
                                <span class="filter-tab active">全部节点</span>
                                <span class="filter-tab">在线</span>
                                <span class="filter-tab">离线</span>
                            </div>
                        </div>
                        
                        <!-- 节点卡片网格 -->
                        <div class="node-grid">
                            <?php
                            foreach ($nodes as $node) {
                                $status_class = $node['status'] == 1 ? 'online' : 'offline';
                                $status_text = $node['status'] == 1 ? '在线' : '离线';
                                $desc = !empty($node['description']) ? $node['description'] : '高速稳定节点';
                                ?>
                                <a href="index.php?action=create_tunnel&node_id=<?php echo $node['id']; ?>" class="node-card">
                                    <div class="node-header">
                                        <span class="node-name">#<?php echo $node['id']; ?> <?php echo htmlspecialchars($node['name']); ?></span>
                                        <span class="node-status-tag <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </div>
                                    <div class="node-location"><?php echo htmlspecialchars($node['location']); ?></div>
                                    <div class="node-desc"><?php echo htmlspecialchars($desc); ?></div>
                                    <div class="node-footer">
                                        <span class="node-port">端口: <?php echo $node['min_port']; ?> - <?php echo $node['max_port']; ?></span>
                                    </div>
                                </a>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- 第二步：填写隧道信息 -->
                    <?php
                    $selected_node = null;
                    foreach ($nodes as $node) {
                        if ($node['id'] == $selected_node_id) {
                            $selected_node = $node;
                            break;
                        }
                    }
                    ?>
                    
                    <?php if (!$selected_node): ?>
                        <div class="card" style="max-width:650px;">
                            <div class="alert alert-danger">节点不存在</div>
                            <div class="form-actions">
                                <a href="index.php?action=create_tunnel" class="btn btn-large btn-primary">返回选择节点</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card" style="max-width:650px;">
                            <div class="card-title">创建隧道</div>
                            
                            <!-- 步骤指示器 -->
                            <div class="steps">
                                <div class="step done">
                                    <span class="step-number">1</span>
                                    <span class="step-text">选择节点</span>
                                </div>
                                <div class="step-line"></div>
                                <div class="step active">
                                    <span class="step-number">2</span>
                                    <span class="step-text">填写信息</span>
                                </div>
                            </div>
                            
                            <!-- 已选节点信息 -->
                            <div class="selected-node-card">
                                <div class="selected-node-info">
                                    <div class="selected-node-name"><?php echo htmlspecialchars($selected_node['name']); ?></div>
                                    <div class="selected-node-location"><?php echo htmlspecialchars($selected_node['location']); ?> · 端口范围 <?php echo $selected_node['min_port']; ?> - <?php echo $selected_node['max_port']; ?></div>
                                </div>
                                <a href="index.php?action=create_tunnel" class="btn btn-small">重新选择</a>
                            </div>
                            
                            <div class="card-desc">
                                填写隧道的配置信息，创建后节点和类型不可修改
                            </div>
                            
                            <?php if (isset($error)): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            
                            <?php if (isset($success)): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                            <?php endif; ?>
                            
                            <form method="post" action="">
                                <input type="hidden" name="action" value="create_tunnel">
                                <input type="hidden" name="node_id" value="<?php echo $selected_node_id; ?>">
                                
                                <div class="form-section">
                                    <div class="form-section-title">基本信息</div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">隧道名称 <span class="required">*</span></label>
                                        <input type="text" name="name" class="form-input" placeholder="给你的隧道起个名字，如：我的世界服务器" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                        <div class="form-hint">便于识别和管理你的隧道</div>
                                    </div>
                                </div>
                                
                                <div class="form-section">
                                    <div class="form-section-title">隧道配置</div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">隧道类型 <span class="required">*</span></label>
                                        <div class="radio-group">
                                            <label class="radio-item">
                                                <input type="radio" name="type" value="tcp" <?php echo (!isset($_POST['type']) || $_POST['type'] == 'tcp') ? 'checked' : ''; ?>>
                                                <span class="radio-label">TCP</span>
                                                <span class="radio-desc">适用于网站、SSH、数据库等大多数场景</span>
                                            </label>
                                            <label class="radio-item">
                                                <input type="radio" name="type" value="udp" <?php echo (isset($_POST['type']) && $_POST['type'] == 'udp') ? 'checked' : ''; ?>>
                                                <span class="radio-label">UDP</span>
                                                <span class="radio-desc">适用于游戏、DNS、语音通话等实时场景</span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">本地地址 <span class="required">*</span></label>
                                            <input type="text" name="local_addr" class="form-input" placeholder="127.0.0.1" value="<?php echo htmlspecialchars($_POST['local_addr'] ?? '127.0.0.1'); ?>">
                                            <div class="form-hint">本地服务的IP地址</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">本地端口 <span class="required">*</span></label>
                                            <input type="number" name="local_port" class="form-input" placeholder="如：8080" value="<?php echo htmlspecialchars($_POST['local_port'] ?? ''); ?>">
                                            <div class="form-hint">本地服务监听的端口</div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">远程端口 <span class="required">*</span></label>
                                        <input type="number" name="remote_port" class="form-input" placeholder="10000 - 60000" value="<?php echo htmlspecialchars($_POST['remote_port'] ?? ''); ?>">
                                        <div class="form-hint">外网访问时使用的端口，范围：<?php echo $selected_node['min_port']; ?> - <?php echo $selected_node['max_port']; ?></div>
                                    </div>
                                </div>
                                
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary btn-large">创建隧道</button>
                                    <a href="index.php?action=create_tunnel" class="btn btn-large">上一步</a>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php elseif ($action == 'edit_tunnel'): ?>
                <!-- 编辑隧道 -->
                <div class="card" style="max-width:650px;">
                    <div class="card-title">编辑隧道</div>
                    
                    <div class="card-desc">
                        修改隧道的配置信息，节点和类型创建后不可修改
                    </div>
                    
                    <?php
                    $tunnel_id = intval($_GET['id'] ?? 0);
                    $edit_tunnel = null;
                    
                    if ($tunnel_id) {
                        $stmt = db()->prepare("SELECT * FROM " . DB_PREFIX . "tunnels WHERE id = ? AND user_id = ?");
                        $stmt->execute([$tunnel_id, $current_user['id']]);
                        $edit_tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if (!$edit_tunnel):
                    ?>
                    <div class="alert alert-danger">隧道不存在</div>
                    <div class="form-actions">
                        <a href="index.php?action=tunnels" class="btn btn-large">返回隧道列表</a>
                    </div>
                    <?php else: ?>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="edit_tunnel">
                        <input type="hidden" name="tunnel_id" value="<?php echo $edit_tunnel['id']; ?>">
                        
                        <div class="form-section">
                            <div class="form-section-title">基本信息</div>
                            
                            <div class="form-group">
                                <label class="form-label">隧道名称 <span class="required">*</span></label>
                                <input type="text" name="name" class="form-input" placeholder="请输入隧道名称" value="<?php echo htmlspecialchars($_POST['name'] ?? $edit_tunnel['name']); ?>">
                                <div class="form-hint">便于识别和管理你的隧道</div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">节点</label>
                                    <div class="form-readonly">
                                        <?php
                                        $stmt = db()->prepare("SELECT name FROM " . DB_PREFIX . "nodes WHERE id = ?");
                                        $stmt->execute([$edit_tunnel['node_id']]);
                                        $node = $stmt->fetch(PDO::FETCH_ASSOC);
                                        echo htmlspecialchars($node['name'] ?? '未知节点');
                                        ?>
                                    </div>
                                    <div class="form-hint">节点创建后不可修改</div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">隧道类型</label>
                                    <div class="form-readonly">
                                        <span class="tag tag-primary" style="margin:0;"><?php echo strtoupper($edit_tunnel['type']); ?></span>
                                    </div>
                                    <div class="form-hint">类型创建后不可修改</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <div class="form-section-title">隧道配置</div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">本地地址 <span class="required">*</span></label>
                                    <input type="text" name="local_addr" class="form-input" placeholder="127.0.0.1" value="<?php echo htmlspecialchars($_POST['local_addr'] ?? $edit_tunnel['local_addr']); ?>">
                                    <div class="form-hint">本地服务的IP地址</div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">本地端口 <span class="required">*</span></label>
                                    <input type="number" name="local_port" class="form-input" placeholder="如：8080" value="<?php echo htmlspecialchars($_POST['local_port'] ?? $edit_tunnel['local_port']); ?>">
                                    <div class="form-hint">本地服务监听的端口</div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">远程端口 <span class="required">*</span></label>
                                <input type="number" name="remote_port" class="form-input" placeholder="10000 - 60000" value="<?php echo htmlspecialchars($_POST['remote_port'] ?? $edit_tunnel['remote_port']); ?>">
                                <div class="form-hint">外网访问时使用的端口，范围：10000 - 60000</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-large">保存修改</button>
                            <a href="index.php?action=tunnels" class="btn btn-large">取消</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($action == 'tunnel_config'): ?>
                <!-- 隧道配置 -->
                <div class="card" style="max-width:700px;">
                    <div class="card-title">
                        隧道配置
                        <button onclick="copyConfig()" class="btn btn-small btn-primary" style="float:right;">复制配置</button>
                    </div>
                    
                    <?php
                    $tunnel_id = intval($_GET['id'] ?? 0);
                    $tunnel = null;
                    if ($tunnel_id) {
                        $db = db();
                        $stmt = $db->prepare("SELECT t.*, n.name as node_name, n.host as node_host, n.port as node_port, n.token as node_token FROM " . DB_PREFIX . "tunnels t LEFT JOIN " . DB_PREFIX . "nodes n ON t.node_id = n.id WHERE t.id = ? AND t.user_id = ?");
                        $stmt->execute([$tunnel_id, $current_user['id']]);
                        $tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if ($tunnel):
                    ?>
                    
                    <div class="alert alert-info">
                        请将以下配置保存为 config.ini，然后使用客户端启动
                    </div>
                    
                    <div class="config-block" id="configContent">
<pre>[common]
server_addr = <?php echo htmlspecialchars($tunnel['node_host']); ?>

server_port = <?php echo htmlspecialchars($tunnel['node_port']); ?>

token = <?php echo htmlspecialchars($tunnel['node_token']); ?>


[<?php echo htmlspecialchars($tunnel['name']); ?>]
type = <?php echo htmlspecialchars($tunnel['type']); ?>

local_addr = <?php echo htmlspecialchars($tunnel['local_addr']); ?>

local_port = <?php echo htmlspecialchars($tunnel['local_port']); ?>

remote_port = <?php echo htmlspecialchars($tunnel['remote_port']); ?></pre>
                    </div>
                    
                    <div style="margin-top:20px;">
                        <div class="form-group">
                            <label class="form-label">访问地址</label>
                            <div style="position:relative;">
                                <input type="text" class="form-input" id="accessAddr" value="<?php echo htmlspecialchars($tunnel['node_host']); ?>:<?php echo htmlspecialchars($tunnel['remote_port']); ?>" readonly style="padding-right:80px;">
                                <button onclick="copyAccessAddr()" class="btn btn-small" style="position:absolute; right:4px; top:4px;">复制</button>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    function copyConfig() {
                        const configText = document.getElementById('configContent').innerText;
                        navigator.clipboard.writeText(configText).then(() => {
                            alert('配置已复制到剪贴板');
                        }).catch(() => {
                            // 降级方案
                            const textarea = document.createElement('textarea');
                            textarea.value = configText;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            alert('配置已复制到剪贴板');
                        });
                    }
                    
                    function copyAccessAddr() {
                        const addr = document.getElementById('accessAddr').value;
                        navigator.clipboard.writeText(addr).then(() => {
                            alert('访问地址已复制');
                        }).catch(() => {
                            const textarea = document.createElement('textarea');
                            textarea.value = addr;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            alert('访问地址已复制');
                        });
                    }
                    </script>
                    
                    <div style="margin-top:20px;">
                        <a href="index.php?action=tunnels" class="btn">← 返回隧道列表</a>
                    </div>
                    
                    <?php else: ?>
                    
                    <div class="alert alert-danger">
                        隧道不存在或无权访问
                    </div>
                    <div style="margin-top:20px;">
                        <a href="index.php?action=tunnels" class="btn">← 返回隧道列表</a>
                    </div>
                    
                    <?php endif; ?>
                </div>
                
            <?php elseif ($action == 'tunnels'): ?>
                <!-- 隧道管理 -->
                <div class="action-bar">
                    <div class="action-bar-title">我的隧道</div>
                    <a href="index.php?action=create_tunnel" class="btn btn-primary">+ 创建隧道</a>
                </div>

                <?php
                $tunnels = get_user_tunnels($current_user['id']);
                if ($tunnels):
                ?>
                <div class="tunnel-grid">
                    <?php foreach ($tunnels as $tunnel): ?>
                    <div class="tunnel-card <?php echo $tunnel['status'] == 0 ? 'tunnel-disabled' : ''; ?>">
                        <div class="tunnel-card-header">
                            <div class="tunnel-name"><?php echo htmlspecialchars($tunnel['name']); ?></div>
                            <form method="post" action="" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_tunnel">
                                <input type="hidden" name="tunnel_id" value="<?php echo $tunnel['id']; ?>">
                                <button type="submit" class="tunnel-switch <?php echo $tunnel['status'] == 1 ? 'active' : ''; ?>" title="<?php echo $tunnel['status'] == 1 ? '点击禁用' : '点击启用'; ?>">
                                    <span class="switch-slider"></span>
                                </button>
                            </form>
                        </div>
                        <div class="tunnel-card-body">
                            <div class="tunnel-info">
                                <span class="tunnel-info-label">节点</span>
                                <span class="tunnel-info-value"><?php echo htmlspecialchars($tunnel['node_name'] ?: '-'); ?></span>
                            </div>
                            <div class="tunnel-info">
                                <span class="tunnel-info-label">类型</span>
                                <span class="tag tag-primary" style="margin:0;"><?php echo strtoupper($tunnel['type']); ?></span>
                            </div>
                            <div class="tunnel-info">
                                <span class="tunnel-info-label">本地地址</span>
                                <span class="tunnel-info-value mono"><?php echo htmlspecialchars($tunnel['local_addr']); ?>:<?php echo htmlspecialchars($tunnel['local_port']); ?></span>
                            </div>
                            <div class="tunnel-info">
                                <span class="tunnel-info-label">远程端口</span>
                                <span class="tunnel-info-value mono"><?php echo htmlspecialchars($tunnel['remote_port']); ?></span>
                            </div>
                            <div class="tunnel-info">
                                <span class="tunnel-info-label">已用流量</span>
                                <span class="tunnel-info-value"><?php echo format_traffic($tunnel['traffic']); ?></span>
                            </div>
                        </div>
                        <div class="tunnel-card-footer">
                            <a href="index.php?action=tunnel_config&id=<?php echo $tunnel['id']; ?>" class="btn btn-small">查看配置</a>
                            <a href="index.php?action=edit_tunnel&id=<?php echo $tunnel['id']; ?>" class="btn btn-small">编辑</a>
                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定要删除这个隧道吗？');">
                                <input type="hidden" name="action" value="delete_tunnel">
                                <input type="hidden" name="tunnel_id" value="<?php echo $tunnel['id']; ?>">
                                <button type="submit" class="btn btn-small btn-danger">删除</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty">
                    <div class="empty-icon" style="width:64px; height:64px; background:var(--info-light); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; color:var(--text-secondary);">
                        <span style="width:24px; height:24px; border:2px solid currentColor; border-radius:4px; position:relative;">
                            <span style="position:absolute; top:6px; left:3px; width:18px; height:2px; background:currentColor;"></span>
                            <span style="position:absolute; top:12px; left:3px; width:18px; height:2px; background:currentColor;"></span>
                        </span>
                    </div>
                    <div class="empty-text">暂无隧道，点击上方按钮创建</div>
                    <a href="index.php?action=create_tunnel" class="btn btn-primary">创建第一个隧道</a>
                </div>
                <?php endif; ?>

            <?php elseif ($action == 'nodes'): ?>
                <!-- 节点列表 -->
                <div class="action-bar">
                    <div class="action-bar-title">节点列表</div>
                </div>

                <div class="node-grid">
                    <?php
                    $nodes = get_nodes();
                    if ($nodes):
                        foreach ($nodes as $node):
                    ?>
                    <div class="node-card">
                        <div class="node-name"><?php echo htmlspecialchars($node['name']); ?></div>
                        <div class="node-location">📍 <?php echo htmlspecialchars($node['location']); ?></div>
                        <div class="node-info">
                            <div>地址: <?php echo htmlspecialchars($node['host']); ?>:<?php echo htmlspecialchars($node['port']); ?></div>
                            <div>端口范围: <?php echo htmlspecialchars($node['min_port']); ?> - <?php echo htmlspecialchars($node['max_port']); ?></div>
                            <div><span class="node-status">● 在线</span></div>
                        </div>
                        <?php if ($node['description']): ?>
                        <div class="node-desc"><?php echo htmlspecialchars($node['description']); ?></div>
                        <?php endif; ?>
                        <div style="margin-top:12px;">
                            <a href="index.php?action=create_tunnel&node_id=<?php echo $node['id']; ?>" class="btn btn-primary btn-small">创建隧道</a>
                        </div>
                    </div>
                    <?php
                        endforeach;
                    else:
                    ?>
                    <div style="text-align:center; padding:40px; color:#909399;">
                        暂无节点
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($action == 'checkin'): ?>
                <!-- 每日签到 -->
                <div class="card" style="max-width:700px;">
                    <div class="card-title">🎁 每日签到</div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <?php
                    $checkin_info = get_checkin_info($current_user['id']);
                    ?>
                    
                    <!-- 签到状态卡片 -->
                    <div class="checkin-status-card">
                        <div style="font-size: 48px; margin-bottom: 10px;"><?php echo $checkin_info['today_checked'] ? '✅' : '🎁'; ?></div>
                        <div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">
                            <?php echo $checkin_info['today_checked'] ? '今日已签到' : '今日未签到'; ?>
                        </div>
                        <div style="font-size: 14px; opacity: 0.9; margin-bottom: 20px;">
                            连续签到 <strong><?php echo $checkin_info['continuous_days']; ?></strong> 天 · 累计获得 <strong><?php echo format_traffic($checkin_info['total_traffic']); ?></strong> 流量
                        </div>
                        
                        <?php if (!$checkin_info['today_checked']): ?>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="checkin">
                            <button type="submit" class="btn btn-large btn-glass">
                                立即签到领流量
                            </button>
                        </form>
                        <?php else: ?>
                        <div style="background: rgba(255,255,255,0.2); padding: 10px 20px; border-radius: 20px; display: inline-block;">
                            今日已获得 <?php echo format_traffic($checkin_info['today_reward']); ?> 流量
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 最近7天签到记录 -->
                    <div class="card">
                        <div class="card-title" style="font-size: 16px; padding-bottom: 15px;">最近7天签到记录</div>
                        <div style="display: flex; justify-content: space-between; gap: 10px;">
                            <?php foreach ($checkin_info['records'] as $record): ?>
                            <div style="flex: 1; text-align: center;">
                                <div style="width: 50px; height: 50px; line-height: 50px; border-radius: 50%; margin: 0 auto 8px; background: <?php echo $record['checked'] ? '#67c23a' : '#f5f7fa'; ?>; color: <?php echo $record['checked'] ? 'white' : '#909399'; ?>; font-size: 20px;">
                                    <?php echo $record['checked'] ? '✓' : '○'; ?>
                                </div>
                                <div style="font-size: 12px; color: #909399;"><?php echo $record['day']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 签到奖励规则 -->
                    <div class="card">
                        <div class="card-title" style="font-size: 16px; padding-bottom: 15px;">📋 签到奖励规则</div>
                        <div style="color: #606266; line-height: 2;">
                            <p>✅ 每日签到：基础奖励 <strong>10GB</strong> 流量</p>
                            <p>🔥 连续签到7天：额外奖励 <strong>5GB</strong> 流量</p>
                            <p>🏆 连续签到30天：额外奖励 <strong>10GB</strong> 流量</p>
                            <p style="color: #909399; font-size: 13px; margin-top: 10px;">
                                💡 提示：连续签到天数越多，奖励越丰厚。中断签到将重新计算连续天数。
                            </p>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'change_password'): ?>
                <!-- 修改密码 -->
                <div class="card" style="max-width:500px;">
                    <div class="card-title">🔐 修改密码</div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label class="form-label">原密码 *</label>
                            <input type="password" name="old_password" class="form-input" placeholder="请输入原密码">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">新密码 *</label>
                            <input type="password" name="new_password" class="form-input" placeholder="6-50位">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">确认新密码 *</label>
                            <input type="password" name="confirm_password" class="form-input" placeholder="再次输入新密码">
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-large btn-block">确认修改</button>
                    </form>
                    
                    <div style="margin-top:20px;">
                        <a href="index.php?action=profile" class="btn">← 返回个人中心</a>
                    </div>
                </div>

            <?php elseif ($action == 'profile'): ?>
                <!-- 个人中心 -->
                <div class="card" style="max-width:600px;">
                    <div class="card-title">👤 个人信息</div>
                    
                    <div class="desc-list">
                        <div class="desc-item">
                            <div class="desc-label">用户名</div>
                            <div class="desc-value"><?php echo htmlspecialchars($current_user['username']); ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">昵称</div>
                            <div class="desc-value"><?php echo htmlspecialchars($current_user['nickname'] ?: '-'); ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">邮箱</div>
                            <div class="desc-value"><?php echo htmlspecialchars($current_user['email']); ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">角色</div>
                            <div class="desc-value">
                                <span class="tag <?php echo $current_user['role'] == 'admin' ? 'tag-primary' : 'tag-info'; ?>">
                                    <?php echo $current_user['role'] == 'admin' ? '管理员' : '普通用户'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">已用流量</div>
                            <div class="desc-value"><?php echo format_traffic($current_user['traffic']); ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">流量限制</div>
                            <div class="desc-value"><?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit']) : '不限'; ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">剩余流量</div>
                            <div class="desc-value" style="color:#67c23a; font-weight:600;"><?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit'] - $current_user['traffic']) : '不限'; ?></div>
                        </div>
                        <div class="desc-item">
                            <div class="desc-label">注册时间</div>
                            <div class="desc-value"><?php echo htmlspecialchars($current_user['created_at']); ?></div>
                        </div>
                    </div>
                    
                    <div style="margin-top:20px;">
                        <a href="index.php?action=change_password" class="btn">修改密码</a>
                    </div>
                </div>

            <?php elseif ($action == 'help'): ?>
                <!-- 帮助中心 -->
                <div class="card" style="max-width:800px;">
                    <div class="card-title">❓ 帮助中心</div>
                    
                    <div style="margin-bottom:30px;">
                        <h3 style="font-size:18px; margin-bottom:15px; color:#1d2129;">快速开始</h3>
                        <div style="color:#4e5969; line-height:2;">
                            <p><strong>1. 什么是内网穿透？</strong><br>
                            内网穿透可以让您在家里或公司内网的服务，通过公网节点被外部访问。比如您本地的网站、游戏服务器、SSH 等，都可以通过 NexusLink 暴露到公网。</p>
                            
                            <p><strong>2. 如何创建第一条隧道？</strong><br>
                            点击左侧「我的隧道」→「创建隧道」，选择节点、填写本地端口和远程端口，点击创建即可。</p>
                            
                            <p><strong>3. 如何使用客户端？</strong><br>
                            下载对应平台的客户端，在隧道列表中点击「配置」获取配置文件，保存为 config.ini，然后运行客户端即可。</p>
                        </div>
                    </div>
                    
                    <div style="margin-bottom:30px;">
                        <h3 style="font-size:18px; margin-bottom:15px; color:#1d2129;">常见问题</h3>
                        <div style="color:#4e5969; line-height:2;">
                            <p><strong>Q: 远程端口被占用怎么办？</strong><br>
                            A: 请更换一个远程端口，端口范围 10000-60000。</p>
                            
                            <p><strong>Q: 连接失败怎么办？</strong><br>
                            A: 请检查：1) 本地服务是否正常启动；2) 配置文件是否正确；3) 节点是否在线。</p>
                            
                            <p><strong>Q: 流量是怎么计算的？</strong><br>
                            A: 流量按进出双向计算，即上传和下载都会消耗流量。签到可以免费获得流量。</p>
                            
                            <p><strong>Q: 支持哪些协议？</strong><br>
                            A: 目前支持 TCP 和 UDP 两种协议。</p>
                        </div>
                    </div>
                    
                    <div>
                        <h3 style="font-size:18px; margin-bottom:15px; color:#1d2129;">联系我们</h3>
                        <div style="color:#4e5969; line-height:2;">
                            <p>如有其他问题，请通过以下方式联系我们：</p>
                            <p>📧 邮箱：support@nexuslink.com</p>
                            <p>💬 QQ 群：123456789</p>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'about'): ?>
                <!-- 关于我们 -->
                <div class="card" style="max-width:700px;">
                    <div class="card-title">关于 NexusLink</div>
                    
                    <div style="text-align:center; padding:30px 0;">
                        <div style="width:80px; height:80px; background:linear-gradient(135deg, #2080f0 0%, #722ed1 100%); border-radius:20px; margin:0 auto 20px; position:relative;">
                            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); width:30px; height:30px; border:4px solid #fff; border-radius:6px;"></div>
                        </div>
                        <h2 style="font-size:28px; font-weight:700; color:#1d2129; margin-bottom:10px;">NexusLink</h2>
                        <p style="color:#86909c; font-size:16px;">高性能内网穿透平台</p>
                    </div>
                    
                    <div style="color:#4e5969; line-height:2; margin-bottom:30px;">
                        <p>NexusLink 是一个专注于内网穿透的服务平台，致力于为用户提供安全、稳定、高速的内网穿透解决方案。</p>
                        <p>我们采用自主研发的高性能转发引擎，相比传统方案，转发损耗更低，速度更快。支持 TCP、UDP 等多种协议，满足不同场景的需求。</p>
                    </div>
                    
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:20px; margin-bottom:30px;">
                        <div style="text-align:center; padding:20px; background:#f5f7fa; border-radius:12px;">
                            <div style="font-size:32px; font-weight:700; color:#2080f0; margin-bottom:5px;">0.3%</div>
                            <div style="font-size:13px; color:#86909c;">转发损耗</div>
                        </div>
                        <div style="text-align:center; padding:20px; background:#f5f7fa; border-radius:12px;">
                            <div style="font-size:32px; font-weight:700; color:#18a058; margin-bottom:5px;">10x</div>
                            <div style="font-size:13px; color:#86909c;">性能提升</div>
                        </div>
                        <div style="text-align:center; padding:20px; background:#f5f7fa; border-radius:12px;">
                            <div style="font-size:32px; font-weight:700; color:#f0a020; margin-bottom:5px;">99.9%</div>
                            <div style="font-size:13px; color:#86909c;">可用性</div>
                        </div>
                    </div>
                    
                    <div style="border-top:1px solid #f2f3f5; padding-top:20px; text-align:center; color:#86909c; font-size:13px;">
                        <p>版本：v0.2.2.beta</p>
                        <p style="margin-top:5px;">© 2026 NexusLink. All rights reserved.</p>
                    </div>
                </div>

            <?php endif; ?>
            
        </div>
    </div>

    <div class="footer">
        <div class="container">
            <p>© 2026 NexusLink 内网穿透平台. All rights reserved.</p>
        </div>
    </div>

<?php endif; ?>

</body>
</html>
