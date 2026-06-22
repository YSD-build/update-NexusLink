<?php
/**
 * 公共函数库
 */

// ========== 响应函数 ==========

function json_response($code = 200, $data = []) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function success($data = [], $message = 'success') {
    json_response(200, array_merge(['success' => true, 'message' => $message], $data));
}

function error($message = 'error', $code = 400) {
    json_response($code, ['success' => false, 'error' => $message]);
}

// ========== 获取请求数据 ==========

function get_input() {
    $input = file_get_contents('php://input');
    if (!$input) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

function get_param($key, $default = null) {
    $input = get_input();
    return $input[$key] ?? $_GET[$key] ?? $_POST[$key] ?? $default;
}

// ========== JWT 实现 ==========

function jwt_encode($payload) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRE;
    $payload = base64url_encode(json_encode($payload));

    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

    return "$header.$payload.$signature";
}

function jwt_decode($token) {
    if (!$token) return null;

    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    list($header, $payload, $signature) = $parts;

    // 验证签名
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(base64url_decode($payload), true);

    // 验证过期
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

// ========== 认证中间件 ==========

function get_token() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
        return $matches[1];
    }

    return $_GET['token'] ?? '';
}

function auth_required() {
    $token = get_token();
    if (!$token) {
        error('未提供认证令牌', 401);
    }

    $payload = jwt_decode($token);
    if (!$payload) {
        error('认证令牌无效或已过期', 401);
    }

    return $payload;
}

function admin_required() {
    $payload = auth_required();

    if (!isset($payload['role']) || $payload['role'] !== 'admin') {
        error('需要管理员权限', 403);
    }

    return $payload;
}

// ========== 工具函数 ==========

function format_bytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function random_str($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function get_client_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ========== 验证函数 ==========

function validate_username($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username);
}

function validate_password($password) {
    return strlen($password) >= 6 && strlen($password) <= 50;
}

function validate_email($email) {
    if (!$email) return true; // 允许为空
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}


// ========== 安全功能函数 ==========

/**
 * 检查登录是否被锁定
 */
function is_login_locked($ip, $username = null) {
    $maxFails = get_security_setting('login_max_fails', 5);
    
    // 检查 IP 级别锁定
    $sql = "SELECT * FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username IS NULL AND locked_until > NOW()";
    $record = Database::fetchOne($sql, [$ip]);
    if ($record) {
        return true;
    }
    
    // 检查 IP + 用户名级别锁定
    if ($username) {
        $sql = "SELECT * FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username = ? AND locked_until > NOW()";
        $record = Database::fetchOne($sql, [$ip, $username]);
        if ($record) {
            return true;
        }
    }
    
    return false;
}

/**
 * 记录登录失败
 */
function record_login_fail($ip, $username = null) {
    $maxFails = get_security_setting('login_max_fails', 5);
    $lockTime = get_security_setting('login_lock_time', 30); // 分钟
    
    // 检查是否已有记录
    if ($username) {
        $sql = "SELECT * FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username = ?";
        $record = Database::fetchOne($sql, [$ip, $username]);
    } else {
        $sql = "SELECT * FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username IS NULL";
        $record = Database::fetchOne($sql, [$ip]);
    }
    
    if ($record) {
        $newCount = $record['fail_count'] + 1;
        $lockedUntil = null;
        
        if ($newCount >= $maxFails) {
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockTime * 60);
        }
        
        $sql = "UPDATE " . TABLE_PREFIX . "login_fails SET fail_count = ?, last_attempt = NOW(), locked_until = ? WHERE id = ?";
        Database::execute($sql, [$newCount, $lockedUntil, $record['id']]);
    } else {
        $sql = "INSERT INTO " . TABLE_PREFIX . "login_fails (ip, username, fail_count, last_attempt) VALUES (?, ?, 1, NOW())";
        Database::execute($sql, [$ip, $username]);
    }
    
    // 记录操作日志
    log_operation(null, $username, 'login_fail', 'user', null, "登录失败，IP: {$ip}");
}

/**
 * 清除登录失败记录
 */
function clear_login_fails($ip, $username = null) {
    if ($username) {
        $sql = "DELETE FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username = ?";
        Database::execute($sql, [$ip, $username]);
    } else {
        $sql = "DELETE FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username IS NULL";
        Database::execute($sql, [$ip]);
    }
}

/**
 * 获取剩余登录尝试次数
 */
function get_remaining_attempts($ip, $username = null) {
    $maxFails = get_security_setting('login_max_fails', 5);
    
    if ($username) {
        $sql = "SELECT fail_count FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username = ?";
        $record = Database::fetchOne($sql, [$ip, $username]);
    } else {
        $sql = "SELECT fail_count FROM " . TABLE_PREFIX . "login_fails WHERE ip = ? AND username IS NULL";
        $record = Database::fetchOne($sql, [$ip]);
    }
    
    if ($record) {
        return max(0, $maxFails - $record['fail_count']);
    }
    
    return $maxFails;
}

/**
 * 生成 CSRF 令牌
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    
    return $token;
}

/**
 * 验证 CSRF 令牌
 */
function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    // 令牌有效期 1 小时
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 获取 CSRF 令牌（如果不存在则生成）
 */
function get_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        return generate_csrf_token();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * XSS 防护 - 输出过滤
 */
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * XSS 防护 - 严格过滤
 */
function xss_clean($data) {
    if (is_array($data)) {
        return array_map('xss_clean', $data);
    }
    
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * 验证密码强度
 */
function validate_password_strength($password) {
    $minLength = get_security_setting('password_min_length', 8);
    $requireUpper = get_security_setting('password_require_uppercase', 0);
    $requireLower = get_security_setting('password_require_lowercase', 1);
    $requireNumber = get_security_setting('password_require_number', 1);
    $requireSpecial = get_security_setting('password_require_special', 0);
    
    $errors = [];
    
    if (strlen($password) < $minLength) {
        $errors[] = "密码长度至少 {$minLength} 位";
    }
    
    if ($requireUpper && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "密码必须包含大写字母";
    }
    
    if ($requireLower && !preg_match('/[a-z]/', $password)) {
        $errors[] = "密码必须包含小写字母";
    }
    
    if ($requireNumber && !preg_match('/[0-9]/', $password)) {
        $errors[] = "密码必须包含数字";
    }
    
    if ($requireSpecial && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "密码必须包含特殊字符";
    }
    
    return $errors;
}

/**
 * 记录操作日志
 */
function log_operation($userId, $username, $action, $targetType = null, $targetId = null, $description = null, $status = 1) {
    $enabled = get_security_setting('operation_log_enabled', 1);
    if (!$enabled) return;
    
    $ip = get_client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    try {
        $sql = "INSERT INTO " . TABLE_PREFIX . "operation_logs (user_id, username, ip, user_agent, action, target_type, target_id, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        Database::execute($sql, [$userId, $username, $ip, $userAgent, $action, $targetType, $targetId, $description, $status]);
    } catch (Exception $e) {
        // 日志记录失败不影响主流程
    }
}

/**
 * 检查是否需要验证码
 */
function need_captcha($ip, $username = null) {
    $captchaEnabled = get_security_setting('login_captcha_enabled', 0);
    if (!$captchaEnabled) {
        return false;
    }
    
    $remaining = get_remaining_attempts($ip, $username);
    
    // 剩余尝试次数少于等于 2 次时显示验证码
    return $remaining <= 2;
}

/**
 * 生成简单验证码
 */
function generate_captcha() {
    $code = random_str(4, '0123456789abcdefghijklmnopqrstuvwxyz');
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['captcha_code'] = strtolower($code);
    $_SESSION['captcha_time'] = time();
    
    return $code;
}

/**
 * 验证验证码
 */
function verify_captcha($code) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['captcha_code']) || empty($code)) {
        return false;
    }
    
    // 验证码有效期 5 分钟
    if (time() - $_SESSION['captcha_time'] > 300) {
        return false;
    }
    
    $result = strtolower($code) === $_SESSION['captcha_code'];
    
    // 验证后立即失效
    unset($_SESSION['captcha_code']);
    
    return $result;
}

/**
 * 获取安全设置（带默认值）
 */
function get_security_setting($key, $default = '') {
    static $settings = null;
    
    if ($settings === null) {
        try {
            $sql = "SELECT setting_key, setting_value FROM " . TABLE_PREFIX . "settings";
            $stmt = Database::getInstance()->getPdo()->query($sql);
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $settings = [];
        }
    }
    
    return $settings[$key] ?? $default;
}

/**
 * 自动安装安全相关表
 */
function auto_install_security_tables() {
    $pdo = Database::getInstance()->getPdo();
    
    // 创建登录失败表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "login_fails` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `username` varchar(50) DEFAULT NULL,
        `fail_count` int(11) NOT NULL DEFAULT 1,
        `last_attempt` datetime NOT NULL,
        `locked_until` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ip_username` (`ip`, `username`),
        KEY `ip` (`ip`),
        KEY `locked_until` (`locked_until`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // 创建操作日志表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "operation_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) DEFAULT NULL,
        `username` varchar(50) DEFAULT NULL,
        `ip` varchar(45) DEFAULT NULL,
        `user_agent` varchar(500) DEFAULT NULL,
        `action` varchar(100) NOT NULL,
        `target_type` varchar(50) DEFAULT NULL,
        `target_id` int(11) DEFAULT NULL,
        `description` text,
        `status` tinyint(1) NOT NULL DEFAULT 1,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `action` (`action`),
        KEY `created_at` (`created_at`),
        KEY `ip` (`ip`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
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
            $stmt = $pdo->prepare("INSERT IGNORE INTO " . TABLE_PREFIX . "settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        } catch (Exception $e) {
            // 忽略重复插入错误
        }
    }
}

