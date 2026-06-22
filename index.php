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

// 初始化设置表
function init_settings_front() {
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
                'site_description' => '高性能内网穿透平台',
                'register_enabled' => '1',
                'email_verify_required' => '0',
                'default_traffic_limit' => '100',
                'checkin_reward' => '10',
                'min_port' => '10000',
                'max_port' => '60000',
                'max_tunnels_per_user' => '10',
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
function get_setting_front($key, $default = '') {
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
function get_all_settings_front() {
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

// 初始化设置
init_settings_front();

// 获取所有设置
$site_settings = get_all_settings_front();

// 定义站点名称常量（用于兼容）
if (!defined('SITE_NAME')) {
    define('SITE_NAME', $site_settings['site_name'] ?? 'NexusLink');
}


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
        
        // 检查 nl_login_logs 表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}login_logs'");
        if (!$stmt->fetch()) {
            // 创建登录记录表
            $sql = "CREATE TABLE `{$prefix}login_logs` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int unsigned NOT NULL COMMENT '用户ID',
              `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '登录IP',
              `user_agent` varchar(500) NOT NULL DEFAULT '' COMMENT '浏览器UA',
              `status` tinyint NOT NULL DEFAULT 1 COMMENT '登录状态: 1成功, 0失败',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录记录表'";
            $db->exec($sql);
        }

        // 检查 nl_api_keys 表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}api_keys'");
        if (!$stmt->fetch()) {
            // 创建API密钥表
            $sql = "CREATE TABLE `{$prefix}api_keys` (
              `id` int unsigned NOT NULL AUTO_INCREMENT,
              `user_id` int unsigned NOT NULL COMMENT '用户ID',
              `name` varchar(100) NOT NULL DEFAULT '' COMMENT '密钥名称',
              `api_key` varchar(64) NOT NULL COMMENT 'API密钥',
              `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态: 1启用, 0禁用',
              `last_used_at` datetime DEFAULT NULL COMMENT '最后使用时间',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `api_key` (`api_key`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API密钥表'";
            $db->exec($sql);
        }


        // 检查 nl_login_fails 表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}login_fails'");
        if (!$stmt->fetch()) {
            // 创建登录失败表
            $sql = "CREATE TABLE `{$prefix}login_fails` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `ip` varchar(45) NOT NULL COMMENT 'IP地址',
              `username` varchar(50) DEFAULT NULL COMMENT '尝试登录的用户名',
              `fail_count` int(11) NOT NULL DEFAULT 1 COMMENT '失败次数',
              `last_attempt` datetime NOT NULL COMMENT '最后尝试时间',
              `locked_until` datetime DEFAULT NULL COMMENT '锁定截止时间',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `ip_username` (`ip`, `username`),
              KEY `ip` (`ip`),
              KEY `locked_until` (`locked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录失败记录表'";
            $db->exec($sql);
        }

        // 检查 nl_operation_logs 表是否存在
        $stmt = $db->query("SHOW TABLES LIKE '{$prefix}operation_logs'");
        if (!$stmt->fetch()) {
            // 创建操作日志表
            $sql = "CREATE TABLE `{$prefix}operation_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) DEFAULT NULL COMMENT '操作用户ID',
              `username` varchar(50) DEFAULT NULL COMMENT '操作用户名',
              `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
              `user_agent` varchar(500) DEFAULT NULL COMMENT '用户代理',
              `action` varchar(100) NOT NULL COMMENT '操作类型',
              `target_type` varchar(50) DEFAULT NULL COMMENT '目标类型',
              `target_id` int(11) DEFAULT NULL COMMENT '目标ID',
              `description` text COMMENT '操作描述',
              `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1成功 0失败',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `action` (`action`),
              KEY `created_at` (`created_at`),
              KEY `ip` (`ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表'";
            $db->exec($sql);
        }

        // 插入默认安全设置
        $defaultSettings = [
            'login_max_fails' => '5',
            'login_lock_time' => '30',
            'login_captcha_enabled' => '0',
            'password_min_length' => '8',
            'password_require_uppercase' => '0',
            'password_require_lowercase' => '1',
            'password_require_number' => '1',
            'password_require_special' => '0',
            'session_timeout' => '86400',
            'max_concurrent_logins' => '5',
            'csrf_protection' => '1',
            'xss_protection' => '1',
            'operation_log_enabled' => '1',
            'email_on_new_login' => '0',
            'ip_whitelist_enabled' => '0',
            'ip_whitelist' => ''
        ];

        foreach ($defaultSettings as $key => $value) {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO {$prefix}settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
            } catch (Exception $e) {
                // 忽略重复插入错误
            }
        }

    } catch (Exception $e) {
        // 静默失败，不影响正常使用
    }
}


// 记录登录日志
function log_login($user_id, $status) {
    $db = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // 截断UA，避免过长
    if (strlen($user_agent) > 500) {
        $user_agent = substr($user_agent, 0, 500);
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "login_logs (user_id, ip, user_agent, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $ip, $user_agent, $status ? 1 : 0]);
    } catch (Exception $e) {
        // 静默失败，不影响登录
    }
}

// 执行自动安装
auto_install();

// 开启session
session_start();

// 获取当前action
$action = $_GET['action'] ?? 'home';

// 处理邮箱验证token
// 处理邮箱验证链接（支持 ?verify=xxx 和 ?action=verify&token=xxx 两种格式）
if (isset($_GET['verify']) || ($action == 'verify' && isset($_GET['token']))) {
    require_once 'api/functions.php';
    
    $token = $_GET['verify'] ?? $_GET['token'];
    $action = 'verify_result';
    $payload = jwt_decode($token);
    
    if (!$payload || !isset($payload['type']) || $payload['type'] !== 'email_verify') {
        $error = '验证链接无效或已过期';
    } else {
        $db = db();
        $user_id = intval($payload['user_id']);
        $email = $payload['email'];
        
        // 验证用户
        $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ? AND email = ?");
        $stmt->execute([$user_id, $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $error = '验证链接无效';
        } elseif ($user['email_verified']) {
            $success = '邮箱已验证，无需重复验证';
        } else {
            // 更新验证状态
            $stmt = $db->prepare("UPDATE " . DB_PREFIX . "users SET email_verified = 1, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $success = '邮箱验证成功！';
            
            // 如果用户已登录，刷新用户信息
            if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?");
                $stmt->execute([$user_id]);
                $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
    
    // 验证结果页面在上面已经设置了action
}

// 处理重置密码链接（支持 ?reset=xxx 格式）
if (isset($_GET['reset'])) {
    $reset_token = $_GET['reset'];
    $action = 'reset_password';
}



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
                    // 记录登录失败日志
                    log_login($user['id'], false);
                    $error = '账号已被禁用';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    // 记录登录成功日志
                    log_login($user['id'], true);
                    header('Location: index.php?action=dashboard');
                    exit;
                }
            } else {
                // 记录登录失败日志
                if ($user) {
                    log_login($user['id'], false);
                }
                $error = '邮箱或密码错误';
            }
        }
    }
    
    // 注册处理
    if ($post_action == 'register') {
        // 检查是否开启注册
        if (($site_settings['register_enabled'] ?? '1') != '1') {
            $error = '抱歉，当前已关闭用户注册';
        } else {
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
                $sent = Mailer::sendResetPasswordEmail($email, $user['username'], $reset_token);
                
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
            
            // 检查用户隧道数量
            $max_tunnels = intval($site_settings['max_tunnels_per_user'] ?? 10);
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM " . DB_PREFIX . "tunnels WHERE user_id = ?");
            $stmt->execute([$current_user['id']]);
            $tunnel_count = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tunnel_count && $tunnel_count['count'] >= $max_tunnels) {
                $error = '您已达到最大隧道数量限制（' . $max_tunnels . '个）';
            } else {
                // 检查端口范围
                $min_port = intval($site_settings['min_port'] ?? 10000);
                $max_port = intval($site_settings['max_port'] ?? 60000);
                if ($remote_port < $min_port || $remote_port > $max_port) {
                    $error = '远程端口必须在 ' . $min_port . ' - ' . $max_port . ' 之间';
                } else {
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
    

    // 重新发送验证邮件
    if ($post_action == 'resend_verify' && $current_user) {
        if ($current_user['email_verified']) {
            $error = '邮箱已验证，无需重复验证';
        } else {
            require_once 'api/mail.php';
            require_once 'api/functions.php';
            
            // 生成邮箱验证token（有效期24小时）
            $verifyToken = jwt_encode([
                'user_id' => (int)$current_user['id'],
                'email' => $current_user['email'],
                'type' => 'email_verify',
                'exp' => time() + 86400,
            ]);
            
            // 发送验证邮件
            $mailSent = Mailer::sendVerifyEmail($current_user['email'], $current_user['username'], $verifyToken);
            
            if ($mailSent) {
                $success = '验证邮件已发送，请查收邮箱';
            } else {
                $error = '邮件发送失败，请稍后重试';
            }
        }
    }


    // 创建API密钥
    if ($post_action == 'create_api_key' && $current_user) {
        $name = trim($_POST['name'] ?? '');
        
        if (!$name) {
            $error = '请输入密钥名称';
        } else {
            $db = db();
            
            // 检查用户密钥数量（最多5个）
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM " . DB_PREFIX . "api_keys WHERE user_id = ?");
            $stmt->execute([$current_user['id']]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count >= 5) {
                $error = '最多只能创建5个API密钥';
            } else {
                // 生成API密钥
                $api_key = 'nl_' . bin2hex(random_bytes(24));
                
                $stmt = $db->prepare("INSERT INTO " . DB_PREFIX . "api_keys (user_id, name, api_key) VALUES (?, ?, ?)");
                $stmt->execute([$current_user['id'], $name, $api_key]);
                
                $success = 'API密钥创建成功：' . $api_key;
            }
        }
    }
    
    // 删除API密钥
    if ($post_action == 'delete_api_key' && $current_user) {
        $key_id = intval($_POST['key_id'] ?? 0);
        
        if ($key_id > 0) {
            $db = db();
            $stmt = $db->prepare("DELETE FROM " . DB_PREFIX . "api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$key_id, $current_user['id']]);
            
            $success = 'API密钥已删除';
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
    <title><?php echo htmlspecialchars($site_settings['site_name'] ?? 'NexusLink'); ?> 内网穿透平台</title>
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
                        <span style="font-size:18px;">节点列表</span>
                    </div>
                    <div class="node-grid">
                        <?php
                        $nodes = get_nodes();
                        if ($nodes):
                            foreach ($nodes as $node):
                        ?>
                        <div class="node-card">
                            <div class="node-name"><?php echo htmlspecialchars($node['name']); ?></div>
                            <div class="node-location"><?php echo htmlspecialchars($node['location']); ?></div>
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
                <!-- 欢迎横幅 -->
                <div class="welcome-banner">
                    <div class="welcome-banner-bg"></div>
                    <div class="welcome-banner-content">
                        <div class="welcome-greeting">
                            <span class="welcome-wave">👋</span>
                            <span class="welcome-text">欢迎回来，</span>
                            <span class="welcome-username"><?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?></span>
                        </div>
                        <div class="welcome-subtitle">
                            开始使用 NexusLink 内网穿透，让您的服务随时随地可访问
                        </div>
                        <div class="welcome-actions">
                            <a href="index.php?action=create_tunnel" class="btn btn-primary btn-lg">
                                <span class="btn-icon">+</span>
                                创建隧道
                            </a>
                            <a href="index.php?action=nodes" class="btn btn-light btn-lg">
                                查看节点
                            </a>
                        </div>
                    </div>
                    <div class="welcome-decoration">
                        <div class="deco-circle deco-circle-1"></div>
                        <div class="deco-circle deco-circle-2"></div>
                        <div class="deco-circle deco-circle-3"></div>
                    </div>
                </div>

                <!-- 统计卡片 -->
                <div class="dashboard-stats">
                    <div class="stat-card-enhanced stat-tunnels">
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number">
                                <?php
                                $user_tunnels = get_user_tunnels($current_user['id']);
                                echo count($user_tunnels);
                                ?>
                            </div>
                            <div class="stat-label">我的隧道</div>
                        </div>
                        <div class="stat-trend">
                            <?php
                            $active_tunnels = array_filter($user_tunnels, function($t) { return $t['status'] == 1; });
                            echo count($active_tunnels) . ' 运行中';
                            ?>
                        </div>
                    </div>

                    <div class="stat-card-enhanced stat-nodes">
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number">
                                <?php
                                $all_nodes = get_nodes();
                                $online_nodes = array_filter($all_nodes, function($n) { return $n['status'] == 1; });
                                echo count($online_nodes);
                                ?>
                            </div>
                            <div class="stat-label">在线节点</div>
                        </div>
                        <div class="stat-trend">
                            共 <?php echo count($all_nodes); ?> 个节点
                        </div>
                    </div>

                    <div class="stat-card-enhanced stat-traffic">
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo format_traffic($current_user['traffic']); ?></div>
                            <div class="stat-label">已用流量</div>
                        </div>
                        <div class="stat-progress">
                            <?php if ($current_user['traffic_limit']): ?>
                                <?php $percent = min(100, round($current_user['traffic'] / $current_user['traffic_limit'] * 100, 1)); ?>
                                <div class="progress-bar-mini">
                                    <div class="progress-fill" style="width: <?php echo $percent; ?>%;"></div>
                                </div>
                                <span class="progress-text"><?php echo $percent; ?>%</span>
                            <?php else: ?>
                                <span class="progress-text">不限流量</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stat-card-enhanced stat-remain">
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-number">
                                <?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit'] - $current_user['traffic']) : '不限'; ?>
                            </div>
                            <div class="stat-label">剩余流量</div>
                        </div>
                        <div class="stat-trend positive">
                            <?php
                            $checkin_info = get_checkin_info($current_user['id']);
                            if (!$checkin_info['today_checked']) {
                                echo '签到可 +10GB';
                            } else {
                                echo '今日已签到';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- 快捷操作和签到 -->
                <div class="dashboard-grid">
                    <!-- 签到卡片 -->
                    <div class="card checkin-card-enhanced">
                        <div class="card-header">
                            <div class="card-title-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                            </div>
                            <span>每日签到</span>
                        </div>
                        <div class="checkin-content">
                            <div class="checkin-streak">
                                <div class="streak-number"><?php echo $checkin_info['continuous_days']; ?></div>
                                <div class="streak-label">连续签到天数</div>
                            </div>
                            <div class="checkin-status">
                                <?php if ($checkin_info['today_checked']): ?>
                                    <div class="status-badge success">今日已签到</div>
                                    <div class="checkin-reward">已获得 +10GB 流量</div>
                                <?php else: ?>
                                    <div class="status-badge warning">今日未签到</div>
                                    <div class="checkin-reward">签到可获得 +10GB 流量</div>
                                <?php endif; ?>
                            </div>
                            <div class="checkin-action">
                                <?php if (!$checkin_info['today_checked']): ?>
                                <form method="post" action="">
                                    <input type="hidden" name="action" value="checkin">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        立即签到
                                    </button>
                                </form>
                                <?php else: ?>
                                <a href="index.php?action=checkin" class="btn btn-block">
                                    查看签到记录
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 快捷操作 -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                </svg>
                            </div>
                            <span>快捷操作</span>
                        </div>
                        <div class="quick-actions">
                            <a href="index.php?action=create_tunnel" class="quick-action-item">
                                <div class="quick-action-icon blue">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="12" y1="5" x2="12" y2="19"/>
                                        <line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                </div>
                                <div class="quick-action-text">
                                    <div class="quick-action-title">创建隧道</div>
                                    <div class="quick-action-desc">快速新建一条隧道</div>
                                </div>
                                <div class="quick-action-arrow">→</div>
                            </a>
                            <a href="index.php?action=checkin" class="quick-action-item">
                                <div class="quick-action-icon green">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-text">
                                    <div class="quick-action-title">每日签到</div>
                                    <div class="quick-action-desc">领取免费流量</div>
                                </div>
                                <div class="quick-action-arrow">→</div>
                            </a>
                            <a href="index.php?action=profile" class="quick-action-item">
                                <div class="quick-action-icon purple">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </div>
                                <div class="quick-action-text">
                                    <div class="quick-action-title">个人中心</div>
                                    <div class="quick-action-desc">管理账户信息</div>
                                </div>
                                <div class="quick-action-arrow">→</div>
                            </a>
                            <a href="index.php?action=help" class="quick-action-item">
                                <div class="quick-action-icon orange">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                                    </svg>
                                </div>
                                <div class="quick-action-text">
                                    <div class="quick-action-title">帮助中心</div>
                                    <div class="quick-action-desc">查看使用教程</div>
                                </div>
                                <div class="quick-action-arrow">→</div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 最近隧道 -->
                <?php if (!empty($user_tunnels)): ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                        </div>
                        <span>我的隧道</span>
                        <a href="index.php?action=tunnels" class="card-more">查看全部 →</a>
                    </div>
                    <div class="recent-tunnels">
                        <?php
                        $recent_tunnels = array_slice($user_tunnels, 0, 4);
                        foreach ($recent_tunnels as $tunnel):
                            $node_name = '';
                            foreach ($all_nodes as $node) {
                                if ($node['id'] == $tunnel['node_id']) {
                                    $node_name = $node['name'];
                                    break;
                                }
                            }
                        ?>
                        <div class="recent-tunnel-item">
                            <div class="tunnel-status-dot <?php echo $tunnel['status'] == 1 ? 'online' : 'offline'; ?>"></div>
                            <div class="tunnel-info">
                                <div class="tunnel-name"><?php echo htmlspecialchars($tunnel['name']); ?></div>
                                <div class="tunnel-meta">
                                    <span class="tunnel-type"><?php echo strtoupper($tunnel['type']); ?></span>
                                    <span class="tunnel-node"><?php echo htmlspecialchars($node_name); ?></span>
                                    <span class="tunnel-port">:<?php echo $tunnel['remote_port']; ?></span>
                                </div>
                            </div>
                            <div class="tunnel-traffic">
                                <?php echo format_traffic($tunnel['traffic']); ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($action == 'create_tunnel'): ?>
                <!-- 创建隧道 -->
                <div class="card" style="max-width:650px;">
                    <div class="card-title">创建隧道</div>
                    
                    <div class="card-desc">
                        填写以下信息创建一条新的内网穿透隧道，创建后可随时修改配置
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <input type="hidden" name="action" value="create_tunnel">
                        
                        <div class="form-section">
                            <div class="form-section-title">基本信息</div>
                            
                            <div class="form-group">
                                <label class="form-label">隧道名称 <span class="required">*</span></label>
                                <input type="text" name="name" class="form-input" placeholder="给你的隧道起个名字，如：我的世界服务器" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                <div class="form-hint">便于识别和管理你的隧道</div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">选择节点 <span class="required">*</span></label>
                                <select name="node_id" class="form-input">
                                    <option value="">请选择节点</option>
                                    <?php
                                    $nodes = get_nodes();
                                    $default_node_id = $_GET['node_id'] ?? ($_POST['node_id'] ?? '');
                                    foreach ($nodes as $node) {
                                        $selected = ($default_node_id == $node['id']) ? 'selected' : '';
                                        $status_text = $node['status'] == 1 ? '在线' : '离线';
                                        $status_class = $node['status'] == 1 ? 'text-success' : 'text-error';
                                        echo '<option value="' . $node['id'] . '" ' . $selected . '>' . htmlspecialchars($node['name']) . ' - ' . htmlspecialchars($node['location']) . ' (' . $status_text . ')</option>';
                                    }
                                    ?>
                                </select>
                                <div class="form-hint">选择离你最近的节点以获得最佳速度</div>
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
                                <div class="form-hint">外网访问时使用的端口，范围：10000 - 60000</div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-large">创建隧道</button>
                            <a href="index.php?action=tunnels" class="btn btn-large">取消</a>
                        </div>
                    </form>
                </div>
                
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
                            showToast('配置已复制到剪贴板', 'success');
                        }).catch(() => {
                            // 降级方案
                            const textarea = document.createElement('textarea');
                            textarea.value = configText;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            showToast('配置已复制到剪贴板', 'success');
                        });
                    }
                    
                    function copyAccessAddr() {
                        const addr = document.getElementById('accessAddr').value;
                        navigator.clipboard.writeText(addr).then(() => {
                            showToast('访问地址已复制', 'success');
                        }).catch(() => {
                            const textarea = document.createElement('textarea');
                            textarea.value = addr;
                            document.body.appendChild(textarea);
                            textarea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textarea);
                            showToast('访问地址已复制', 'success');
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
                <div class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">我的隧道</h1>
                        <p class="page-desc">管理您的所有内网穿透隧道</p>
                    </div>
                    <a href="index.php?action=create_tunnel" class="btn btn-primary btn-lg">
                        <span class="btn-icon">+</span>
                        创建隧道
                    </a>
                </div>

                <?php
                $tunnels = get_user_tunnels($current_user['id']);
                if ($tunnels):
                ?>
                <div class="tunnel-stats-bar">
                    <div class="tunnel-stat">
                        <span class="tunnel-stat-number"><?php echo count($tunnels); ?></span>
                        <span class="tunnel-stat-label">总隧道数</span>
                    </div>
                    <div class="tunnel-stat">
                        <span class="tunnel-stat-number success">
                            <?php
                            $active_count = array_filter($tunnels, function($t) { return $t['status'] == 1; });
                            echo count($active_count);
                            ?>
                        </span>
                        <span class="tunnel-stat-label">运行中</span>
                    </div>
                    <div class="tunnel-stat">
                        <span class="tunnel-stat-number warning">
                            <?php
                            $disabled_count = array_filter($tunnels, function($t) { return $t['status'] == 0; });
                            echo count($disabled_count);
                            ?>
                        </span>
                        <span class="tunnel-stat-label">已禁用</span>
                    </div>
                    <div class="tunnel-stat">
                        <span class="tunnel-stat-number info">
                            <?php
                            $total_traffic = array_sum(array_column($tunnels, 'traffic'));
                            echo format_traffic($total_traffic);
                            ?>
                        </span>
                        <span class="tunnel-stat-label">总流量</span>
                    </div>
                </div>

                <div class="tunnel-grid-enhanced">
                    <?php foreach ($tunnels as $tunnel): ?>
                    <div class="tunnel-card-enhanced <?php echo $tunnel['status'] == 1 ? 'status-active' : 'status-disabled'; ?>">
                        <!-- 卡片顶部状态条 -->
                        <div class="tunnel-card-topbar">
                            <div class="tunnel-status-indicator">
                                <span class="status-dot <?php echo $tunnel['status'] == 1 ? 'dot-online' : 'dot-offline'; ?>"></span>
                                <span class="status-text"><?php echo $tunnel['status'] == 1 ? '运行中' : '已禁用'; ?></span>
                            </div>
                            <span class="tunnel-type-badge type-<?php echo $tunnel['type']; ?>">
                                <?php echo strtoupper($tunnel['type']); ?>
                            </span>
                        </div>

                        <!-- 卡片主体 -->
                        <div class="tunnel-card-main">
                            <div class="tunnel-card-title">
                                <h3 class="tunnel-name-enhanced"><?php echo htmlspecialchars($tunnel['name']); ?></h3>
                                <div class="tunnel-node-badge">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                    <?php echo htmlspecialchars($tunnel['node_name'] ?: '未知节点'); ?>
                                </div>
                            </div>

                            <div class="tunnel-details-grid">
                                <div class="tunnel-detail-item">
                                    <span class="detail-label">本地地址</span>
                                    <span class="detail-value mono"><?php echo htmlspecialchars($tunnel['local_addr']); ?>:<?php echo $tunnel['local_port']; ?></span>
                                </div>
                                <div class="tunnel-detail-item">
                                    <span class="detail-label">远程端口</span>
                                    <span class="detail-value mono accent"><?php echo $tunnel['remote_port']; ?></span>
                                </div>
                                <div class="tunnel-detail-item">
                                    <span class="detail-label">已用流量</span>
                                    <span class="detail-value"><?php echo format_traffic($tunnel['traffic']); ?></span>
                                </div>
                                <div class="tunnel-detail-item">
                                    <span class="detail-label">创建时间</span>
                                    <span class="detail-value"><?php echo date('m-d', strtotime($tunnel['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- 卡片操作区 -->
                        <div class="tunnel-card-actions">
                            <form method="post" action="" class="toggle-form">
                                <input type="hidden" name="action" value="toggle_tunnel">
                                <input type="hidden" name="tunnel_id" value="<?php echo $tunnel['id']; ?>">
                                <button type="submit" class="toggle-btn <?php echo $tunnel['status'] == 1 ? 'toggled' : ''; ?>">
                                    <span class="toggle-track">
                                        <span class="toggle-thumb"></span>
                                    </span>
                                    <span class="toggle-label"><?php echo $tunnel['status'] == 1 ? '已启用' : '已禁用'; ?></span>
                                </button>
                            </form>
                            
                            <div class="action-buttons">
                                <a href="index.php?action=tunnel_config&id=<?php echo $tunnel['id']; ?>" class="action-btn" title="查看配置">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                        <line x1="16" y1="13" x2="8" y2="13"/>
                                        <line x1="16" y1="17" x2="8" y2="17"/>
                                        <polyline points="10 9 9 9 8 9"/>
                                    </svg>
                                    配置
                                </a>
                                <a href="index.php?action=edit_tunnel&id=<?php echo $tunnel['id']; ?>" class="action-btn" title="编辑">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                    </svg>
                                    编辑
                                </a>
                                <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定要删除这个隧道吗？');">
                                    <input type="hidden" name="action" value="delete_tunnel">
                                    <input type="hidden" name="tunnel_id" value="<?php echo $tunnel['id']; ?>">
                                    <button type="submit" class="action-btn danger" title="删除">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="3 6 5 6 21 6"/>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                        </svg>
                                        删除
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state-enhanced">
                    <div class="empty-illustration">
                        <div class="empty-icon-large">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="empty-title">还没有隧道</h3>
                    <p class="empty-desc">创建您的第一条内网穿透隧道，让本地服务随时可访问</p>
                    <a href="index.php?action=create_tunnel" class="btn btn-primary btn-lg">
                        <span class="btn-icon">+</span>
                        创建第一个隧道
                    </a>
                    
                    <div class="empty-features">
                        <div class="empty-feature">
                            <div class="feature-icon blue">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                                </svg>
                            </div>
                            <span>高速稳定</span>
                        </div>
                        <div class="empty-feature">
                            <div class="feature-icon green">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                </svg>
                            </div>
                            <span>安全可靠</span>
                        </div>
                        <div class="empty-feature">
                            <div class="feature-icon purple">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </div>
                            <span>即开即用</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            <?php elseif ($action == 'nodes'): ?>
                <!-- 节点列表 -->
                <div class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">节点列表</h1>
                        <p class="page-desc">选择最优节点，享受高速稳定的内网穿透服务</p>
                    </div>
                </div>

                <div class="node-stats">
                    <div class="node-stat-card">
                        <div class="node-stat-icon online">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <div class="node-stat-info">
                            <div class="node-stat-number">
                                <?php
                                $all_nodes = get_nodes();
                                $online = array_filter($all_nodes, function($n) { return $n['status'] == 1; });
                                echo count($online);
                                ?>
                            </div>
                            <div class="node-stat-label">在线节点</div>
                        </div>
                    </div>
                    <div class="node-stat-card">
                        <div class="node-stat-icon total">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                            </svg>
                        </div>
                        <div class="node-stat-info">
                            <div class="node-stat-number"><?php echo count($all_nodes); ?></div>
                            <div class="node-stat-label">全部节点</div>
                        </div>
                    </div>
                </div>

                <div class="node-grid-enhanced">
                    <?php
                    $nodes = get_nodes();
                    if ($nodes):
                        foreach ($nodes as $node):
                    ?>
                    <div class="node-card-enhanced <?php echo $node['status'] == 1 ? 'node-online' : 'node-offline'; ?>">
                        <div class="node-card-decoration"></div>
                        
                        <div class="node-card-header">
                            <div class="node-icon-wrapper">
                                <div class="node-icon">
                                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                </div>
                                <span class="node-status-dot <?php echo $node['status'] == 1 ? 'dot-online' : 'dot-offline'; ?>"></span>
                            </div>
                            <div class="node-title-area">
                                <h3 class="node-name-enhanced"><?php echo htmlspecialchars($node['name']); ?></h3>
                                <span class="node-location-badge">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                        <circle cx="12" cy="10" r="3"/>
                                    </svg>
                                    <?php echo htmlspecialchars($node['location'] ?: '未知位置'); ?>
                                </span>
                            </div>
                            <span class="node-status-badge <?php echo $node['status'] == 1 ? 'badge-online' : 'badge-offline'; ?>">
                                <?php echo $node['status'] == 1 ? '在线' : '离线'; ?>
                            </span>
                        </div>

                        <div class="node-card-info">
                            <div class="node-info-row">
                                <span class="info-label">节点地址</span>
                                <span class="info-value mono"><?php echo htmlspecialchars($node['host']); ?></span>
                            </div>
                            <div class="node-info-row">
                                <span class="info-label">通信端口</span>
                                <span class="info-value mono"><?php echo $node['port']; ?></span>
                            </div>
                            <div class="node-info-row">
                                <span class="info-label">端口范围</span>
                                <span class="info-value mono"><?php echo $node['min_port']; ?> - <?php echo $node['max_port']; ?></span>
                            </div>
                        </div>

                        <?php if ($node['description']): ?>
                        <div class="node-card-desc">
                            <?php echo htmlspecialchars($node['description']); ?>
                        </div>
                        <?php endif; ?>

                        <div class="node-card-footer">
                            <a href="index.php?action=create_tunnel&node_id=<?php echo $node['id']; ?>" class="btn btn-primary btn-block btn-small <?php echo $node['status'] != 1 ? 'disabled' : ''; ?>">
                                <?php echo $node['status'] == 1 ? '使用此节点创建隧道' : '节点暂不可用'; ?>
                            </a>
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
                <div class="checkin-page">
                    <div class="card">
                        <div class="card-title">
                            <span class="card-title-icon icon-checkin"></span>
                            每日签到
                        </div>
                        
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
                            <div class="checkin-status-icon">
                                <?php if ($checkin_info['today_checked']): ?>
                                    <span style="color:white;">✓</span>
                                <?php else: ?>
                                    <span style="color:white;">🎁</span>
                                <?php endif; ?>
                            </div>
                            <div class="checkin-status-title">
                                <?php echo $checkin_info['today_checked'] ? '今日已签到' : '今日未签到'; ?>
                            </div>
                            <div class="checkin-status-desc">
                                连续签到 <strong><?php echo $checkin_info['continuous_days']; ?></strong> 天 · 累计获得 <strong><?php echo format_traffic($checkin_info['total_traffic']); ?></strong> 流量
                            </div>
                            
                            <?php if (!$checkin_info['today_checked']): ?>
                            <form method="post" action="">
                                <input type="hidden" name="action" value="checkin">
                                <button type="submit" class="checkin-btn">
                                    立即签到领流量
                                </button>
                            </form>
                            <?php else: ?>
                            <div class="checkin-reward-tag">
                                今日已获得 <?php echo format_traffic($checkin_info['today_reward']); ?> 流量
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- 最近7天签到记录 -->
                        <div class="card checkin-records-card">
                            <div class="card-title" style="font-size: 16px; padding-bottom: 15px;">最近7天签到记录</div>
                            <div class="checkin-records">
                                <?php foreach ($checkin_info['records'] as $record): ?>
                                <div class="checkin-record-item">
                                    <div class="checkin-record-dot <?php echo $record['checked'] ? 'checked' : 'unchecked'; ?>">
                                        <?php echo $record['checked'] ? '✓' : ''; ?>
                                    </div>
                                    <div class="checkin-record-day"><?php echo $record['day']; ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- 签到奖励规则 -->
                        <div class="card">
                            <div class="card-title" style="font-size: 16px; padding-bottom: 15px;">签到奖励规则</div>
                            <div class="reward-rules">
                                <div class="reward-rule-item">
                                    <div class="reward-rule-icon">📅</div>
                                    <div class="reward-rule-content">
                                        <div class="reward-rule-title">每日签到</div>
                                        <div class="reward-rule-desc">每天签到即可获得基础奖励</div>
                                    </div>
                                    <div class="reward-rule-amount">+10GB</div>
                                </div>
                                <div class="reward-rule-item">
                                    <div class="reward-rule-icon">🔥</div>
                                    <div class="reward-rule-content">
                                        <div class="reward-rule-title">连续签到7天</div>
                                        <div class="reward-rule-desc">连续签到满7天额外奖励</div>
                                    </div>
                                    <div class="reward-rule-amount">+5GB</div>
                                </div>
                                <div class="reward-rule-item">
                                    <div class="reward-rule-icon">🏆</div>
                                    <div class="reward-rule-content">
                                        <div class="reward-rule-title">连续签到30天</div>
                                        <div class="reward-rule-desc">连续签到满30天额外奖励</div>
                                    </div>
                                    <div class="reward-rule-amount">+10GB</div>
                                </div>
                            </div>
                            <div style="color: var(--text-secondary); font-size: 13px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-light);">
                                提示：连续签到天数越多，奖励越丰厚。中断签到将重新计算连续天数。
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'change_password'): ?>
                <!-- 修改密码 -->
                <div class="card" style="max-width:500px;">
                    <div class="card-title">修改密码</div>
                    
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

            <?php elseif ($action == 'api_keys'): ?>
                <!-- API密钥管理 -->
                <div class="card">
                    <div class="card-title">
                        <span class="card-title-icon icon-api"></span>
                        API 密钥管理
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <!-- 创建密钥表单 -->
                    <div class="create-api-form">
                        <form method="post" action="" style="display: flex; gap: 10px; align-items: flex-end;">
                            <input type="hidden" name="action" value="create_api_key">
                            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                <label class="form-label">密钥名称</label>
                                <input type="text" name="name" class="form-input" placeholder="例如：我的脚本、服务器监控" required>
                            </div>
                            <button type="submit" class="btn btn-primary">创建密钥</button>
                        </form>
                    </div>
                    
                    <div style="margin: 20px 0; border-top: 1px solid var(--border-color);"></div>
                    
                    <?php
                    // 获取用户的API密钥
                    $db = db();
                    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "api_keys WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt->execute([$current_user['id']]);
                    $api_keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if (empty($api_keys)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">🔑</div>
                            <div class="empty-text">暂无API密钥</div>
                            <div class="empty-desc">创建API密钥后，可通过API接口管理您的隧道</div>
                        </div>
                    <?php else: ?>
                        <div class="api-keys-list">
                            <?php foreach ($api_keys as $key): ?>
                                <div class="api-key-item">
                                    <div class="api-key-info">
                                        <div class="api-key-name"><?php echo htmlspecialchars($key['name']); ?></div>
                                        <div class="api-key-value" onclick="copyText('<?php echo htmlspecialchars($key['api_key']); ?>')" title="点击复制">
                                            <code><?php echo htmlspecialchars($key['api_key']); ?></code>
                                            <span class="copy-hint">点击复制</span>
                                        </div>
                                        <div class="api-key-meta">
                                            <span>创建时间：<?php echo htmlspecialchars($key['created_at']); ?></span>
                                            <span>最后使用：<?php echo $key['last_used_at'] ? htmlspecialchars($key['last_used_at']) : '从未使用'; ?></span>
                                            <span class="<?php echo $key['status'] ? 'text-success' : 'text-error'; ?>">
                                                <?php echo $key['status'] ? '已启用' : '已禁用'; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="api-key-actions">
                                        <form method="post" action="" onsubmit="return confirm('确定要删除这个API密钥吗？删除后无法恢复！');">
                                            <input type="hidden" name="action" value="delete_api_key">
                                            <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-small">删除</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px;">
                        <a href="index.php?action=profile" class="btn">← 返回个人中心</a>
                    </div>
                </div>

            <?php elseif ($action == 'verify_result'): ?>
                <!-- 邮箱验证结果 -->
                <div class="card" style="max-width:500px; text-align:center;">
                    <div class="card-title">邮箱验证</div>
                    
                    <?php if (isset($error)): ?>
                        <div style="padding: 30px 0;">
                            <div style="font-size: 48px; margin-bottom: 15px;">❌</div>
                            <div style="font-size: 18px; font-weight: 600; color: var(--error-color); margin-bottom: 10px;">验证失败</div>
                            <div style="color: var(--text-secondary);"><?php echo htmlspecialchars($error); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div style="padding: 30px 0;">
                            <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
                            <div style="font-size: 18px; font-weight: 600; color: var(--success-color); margin-bottom: 10px;">验证成功</div>
                            <div style="color: var(--text-secondary);"><?php echo htmlspecialchars($success); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:20px;">
                        <?php if ($current_user): ?>
                            <a href="index.php?action=dashboard" class="btn btn-primary">进入控制台</a>
                        <?php else: ?>
                            <a href="index.php?action=login" class="btn btn-primary">去登录</a>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action == 'login_logs'): ?>
                <!-- 登录记录 -->
                <div class="card">
                    <div class="card-title">
                        <span class="card-title-icon icon-login"></span>
                        登录记录
                    </div>
                    
                    <?php
                    // 获取登录记录
                    $db = db();
                    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                    $per_page = 20;
                    $offset = ($page - 1) * $per_page;
                    
                    // 获取总数
                    $stmt = $db->prepare("SELECT COUNT(*) as total FROM " . DB_PREFIX . "login_logs WHERE user_id = ?");
                    $stmt->execute([$current_user['id']]);
                    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    $total_pages = ceil($total / $per_page);
                    
                    // 获取登录记录
                    $stmt = $db->prepare("SELECT * FROM " . DB_PREFIX . "login_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
                    $stmt->execute([$current_user['id'], $per_page, $offset]);
                    $login_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if (empty($login_logs)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📋</div>
                            <div class="empty-text">暂无登录记录</div>
                        </div>
                    <?php else: ?>
                        <div class="login-logs-list">
                            <?php foreach ($login_logs as $log): ?>
                                <div class="login-log-item">
                                    <div class="log-status <?php echo $log['status'] ? 'status-success' : 'status-failed'; ?>">
                                        <?php echo $log['status'] ? '✓' : '✗'; ?>
                                    </div>
                                    <div class="log-info">
                                        <div class="log-time"><?php echo htmlspecialchars($log['created_at']); ?></div>
                                        <div class="log-ip">IP：<?php echo htmlspecialchars($log['ip'] ?: '未知'); ?></div>
                                        <div class="log-ua" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                            <?php 
                                            $ua = htmlspecialchars($log['user_agent'] ?: '未知');
                                            echo mb_strlen($ua) > 60 ? mb_substr($ua, 0, 60) . '...' : $ua;
                                            ?>
                                        </div>
                                    </div>
                                    <div class="log-result <?php echo $log['status'] ? 'text-success' : 'text-error'; ?>">
                                        <?php echo $log['status'] ? '成功' : '失败'; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="index.php?action=login_logs&page=<?php echo $page - 1; ?>" class="btn btn-small">上一页</a>
                                <?php endif; ?>
                                <span class="pagination-info">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 页</span>
                                <?php if ($page < $total_pages): ?>
                                    <a href="index.php?action=login_logs&page=<?php echo $page + 1; ?>" class="btn btn-small">下一页</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div style="margin-top:20px;">
                        <a href="index.php?action=profile" class="btn">← 返回个人中心</a>
                    </div>
                </div>

            <?php elseif ($action == 'verify_email'): ?>
                <!-- 邮箱验证 -->
                <div class="card" style="max-width:500px;">
                    <div class="card-title">
                        <span class="card-title-icon icon-email"></span>
                        邮箱验证
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($current_user['email_verified']): ?>
                        <div style="text-align:center; padding: 30px 0;">
                            <div style="font-size: 48px; margin-bottom: 15px;">✅</div>
                            <div style="font-size: 18px; font-weight: 600; color: var(--success-color); margin-bottom: 10px;">邮箱已验证</div>
                            <div style="color: var(--text-secondary);">您的邮箱 <?php echo htmlspecialchars($current_user['email']); ?> 已成功验证</div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding: 20px 0 30px;">
                            <div style="font-size: 48px; margin-bottom: 15px;">📧</div>
                            <div style="font-size: 18px; font-weight: 600; color: var(--warning-color); margin-bottom: 10px;">邮箱未验证</div>
                            <div style="color: var(--text-secondary); margin-bottom: 20px;">
                                当前邮箱：<?php echo htmlspecialchars($current_user['email']); ?><br>
                                验证邮箱后可享受更多功能
                            </div>
                            
                            <form method="post" action="" style="margin-top: 20px;">
                                <input type="hidden" name="action" value="resend_verify">
                                <button type="submit" class="btn btn-primary btn-large btn-block">重新发送验证邮件</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top:20px;">
                        <a href="index.php?action=profile" class="btn">← 返回个人中心</a>
                    </div>
                </div>

            <?php elseif ($action == 'profile'): ?>
                <!-- 个人中心 -->
                <div class="user-center">
                    <!-- 用户信息头部卡片 -->
                    <div class="user-header-card">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($current_user['username'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name">
                                <?php echo htmlspecialchars($current_user['nickname'] ?: $current_user['username']); ?>
                                <span class="user-role-tag <?php echo $current_user['role'] == 'admin' ? 'role-admin' : 'role-user'; ?>">
                                    <?php echo $current_user['role'] == 'admin' ? '管理员' : '普通用户'; ?>
                                </span>
                            </div>
                            <div class="user-email">
                                <span class="email-icon-small"></span>
                                <?php echo htmlspecialchars($current_user['email']); ?>
                                <?php if ($current_user['email_verified']): ?>
                                    <span class="verified-badge">✓ 已验证</span>
                                <?php else: ?>
                                    <span class="unverified-badge">未验证</span>
                                <?php endif; ?>
                            </div>
                            <div class="user-meta">
                                <span class="meta-item">
                                    <span class="meta-label">注册时间</span>
                                    <span class="meta-value"><?php echo date('Y-m-d', strtotime($current_user['created_at'])); ?></span>
                                </span>
                                <span class="meta-item">
                                    <span class="meta-label">UID</span>
                                    <span class="meta-value">#<?php echo $current_user['id']; ?></span>
                                </span>
                                <span class="meta-item">
                                    <span class="meta-label">隧道数量</span>
                                    <span class="meta-value">
                                        <?php
                                        $tunnel_count = 0;
                                        if (function_exists('get_user_tunnels')) {
                                            $tunnels = get_user_tunnels($current_user['id']);
                                            $tunnel_count = count($tunnels);
                                        }
                                        echo $tunnel_count . ' 个';
                                        ?>
                                    </span>
                                </span>
                            </div>
                        </div>
                        <div class="user-quick-actions">
                            <a href="index.php?action=create_tunnel" class="btn btn-primary">
                                <span class="btn-icon">+</span>
                                创建隧道
                            </a>
                        </div>
                    </div>

                    <!-- 流量概览卡片 -->
                    <div class="traffic-overview-card">
                        <div class="section-title">
                            <span class="section-icon section-icon-chart"></span>
                            流量概览
                            <span class="section-badge">本月</span>
                        </div>
                        <div class="traffic-progress-section">
                            <div class="traffic-progress-header">
                                <span>已使用</span>
                                <span class="traffic-percent">
                                    <?php 
                                    $traffic_percent = 0;
                                    if ($current_user['traffic_limit'] > 0) {
                                        $traffic_percent = min(100, round($current_user['traffic'] / $current_user['traffic_limit'] * 100));
                                    }
                                    echo $current_user['traffic_limit'] ? $traffic_percent . '%' : '不限流量';
                                    ?>
                                </span>
                            </div>
                            <div class="progress-bar large">
                                <div class="progress-bar-inner" style="width: <?php echo $current_user['traffic_limit'] ? $traffic_percent : 100; ?>%;"></div>
                            </div>
                            <div class="traffic-used-info">
                                <span class="used-amount"><?php echo format_traffic($current_user['traffic']); ?></span>
                                <span class="total-amount"> / <?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit']) : '不限'; ?></span>
                            </div>
                        </div>
                        <div class="traffic-stats-grid">
                            <div class="traffic-stat-card">
                                <div class="stat-icon used-icon"></div>
                                <div class="stat-content">
                                    <div class="stat-label">已用流量</div>
                                    <div class="stat-value"><?php echo format_traffic($current_user['traffic']); ?></div>
                                </div>
                            </div>
                            <div class="traffic-stat-card success">
                                <div class="stat-icon remain-icon"></div>
                                <div class="stat-content">
                                    <div class="stat-label">剩余流量</div>
                                    <div class="stat-value text-success">
                                        <?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit'] - $current_user['traffic']) : '不限'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="traffic-stat-card">
                                <div class="stat-icon total-icon"></div>
                                <div class="stat-content">
                                    <div class="stat-label">总流量</div>
                                    <div class="stat-value"><?php echo $current_user['traffic_limit'] ? format_traffic($current_user['traffic_limit']) : '不限'; ?></div>
                                </div>
                            </div>
                            <div class="traffic-stat-card primary">
                                <div class="stat-icon tunnel-icon-small"></div>
                                <div class="stat-content">
                                    <div class="stat-label">隧道数量</div>
                                    <div class="stat-value">
                                        <?php
                                        $tunnel_count = 0;
                                        if (function_exists('get_user_tunnels')) {
                                            $tunnels = get_user_tunnels($current_user['id']);
                                            $tunnel_count = count($tunnels);
                                        }
                                        echo $tunnel_count;
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 功能分类区域 -->
                    <div class="function-sections">
                        <!-- 账户安全 -->
                        <div class="function-section">
                            <div class="section-title">
                                <span class="section-icon section-icon-shield"></span>
                                账户安全
                            </div>
                            <div class="function-grid">
                                <a href="index.php?action=change_password" class="function-card">
                                    <div class="function-icon password-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">修改密码</div>
                                        <div class="function-desc">定期修改密码保障账户安全</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=verify_email" class="function-card">
                                    <div class="function-icon email-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">邮箱验证</div>
                                        <div class="function-desc">
                                            <?php if ($current_user['email_verified']): ?>
                                                <span class="text-success">已验证</span>
                                            <?php else: ?>
                                                <span class="text-warning">未验证</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=login_logs" class="function-card">
                                    <div class="function-icon login-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">登录记录</div>
                                        <div class="function-desc">查看账户登录历史记录</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=api_keys" class="function-card">
                                    <div class="function-icon api-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">API 密钥</div>
                                        <div class="function-desc">管理 API 访问密钥</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                            </div>
                        </div>

                        <!-- 隧道管理 -->
                        <div class="function-section">
                            <div class="section-title">
                                <span class="section-icon section-icon-globe"></span>
                                隧道管理
                            </div>
                            <div class="function-grid">
                                <a href="index.php?action=tunnels" class="function-card">
                                    <div class="function-icon tunnel-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">我的隧道</div>
                                        <div class="function-desc">管理所有已创建的隧道</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=create_tunnel" class="function-card">
                                    <div class="function-icon add-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">创建隧道</div>
                                        <div class="function-desc">快速创建新的内网穿透隧道</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=nodes" class="function-card">
                                    <div class="function-icon node-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">节点列表</div>
                                        <div class="function-desc">查看所有可用节点信息</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=help" class="function-card">
                                    <div class="function-icon doc-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">使用教程</div>
                                        <div class="function-desc">客户端配置和使用指南</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                            </div>
                        </div>

                        <!-- 其他功能 -->
                        <div class="function-section">
                            <div class="section-title">
                                <span class="section-icon section-icon-settings"></span>
                                其他功能
                            </div>
                            <div class="function-grid">
                                <a href="index.php?action=checkin" class="function-card">
                                    <div class="function-icon checkin-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">每日签到</div>
                                        <div class="function-desc">签到领取免费流量奖励</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=help" class="function-card">
                                    <div class="function-icon help-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">帮助中心</div>
                                        <div class="function-desc">常见问题和使用帮助</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <a href="index.php?action=about" class="function-card">
                                    <div class="function-icon about-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">关于我们</div>
                                        <div class="function-desc">了解 NexusLink 平台</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </a>
                                <div class="function-card logout-card" onclick="if(confirm('确定要退出登录吗？')) window.location.href='index.php?action=logout'">
                                    <div class="function-icon logout-icon"></div>
                                    <div class="function-info">
                                        <div class="function-name">退出登录</div>
                                        <div class="function-desc">安全退出当前账户</div>
                                    </div>
                                    <div class="function-arrow">→</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($action == 'help'): ?>
                <!-- 帮助中心 -->
                <div class="card" style="max-width:800px;">
                    <div class="card-title">帮助中心</div>
                    
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

    <!-- Toast 提示 -->
    <div id="toast" class="toast">
        <div class="toast-icon">✓</div>
        <div class="toast-message" id="toastMessage">操作成功</div>
    </div>

    <script>
    function showToast(message, type) {
        type = type || "success";
        var toast = document.getElementById("toast");
        var toastMessage = document.getElementById("toastMessage");
        var toastIcon = toast.querySelector(".toast-icon");
        toast.className = "toast " + (type === "success" ? "toast-success" : type === "error" ? "toast-error" : "toast-info");
        if (type === "success") {
            toastIcon.textContent = "✓";
        } else if (type === "error") {
            toastIcon.textContent = "!";
        } else {
            toastIcon.textContent = "i";
        }
        toastMessage.textContent = message;
        setTimeout(function() { toast.classList.add("show"); }, 10);
        setTimeout(function() { toast.classList.remove("show"); }, 2500);
    }
    </script>
</body>
</html>
