<?php
/**
 * 认证接口
 * POST /api/auth.php?action=register  注册
 * POST /api/auth.php?action=login     登录
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        do_register();
        break;
    case 'login':
        do_login();
        break;
    case 'verify':
        do_verify();
        break;
    case 'forgot':
        do_forgot();
        break;
    case 'captcha':
        do_captcha();
        break;
    case 'reset':
        do_reset();
        break;
    default:
        error('无效的操作');
}

// 注册
function do_register() {
    $username = trim(get_param('username', ''));
    $password = get_param('password', '');
    $email = trim(get_param('email', ''));

    // 验证
    if (!$username || !$password) {
        error('用户名和密码不能为空');
    }
    if (!validate_username($username)) {
        error('用户名格式不正确（3-50位字母数字下划线）');
    }
    if (!validate_password($password)) {
        error('密码长度6-50位');
    }
    if (!validate_email($email)) {
        error('邮箱格式不正确');
    }

    // 检查用户名是否存在
    $exists = Database::fetchOne(
        'SELECT id FROM ' . TABLE_PREFIX . 'users WHERE username = ?',
        [$username]
    );
    if ($exists) {
        error('用户名已存在');
    }

    // 检查邮箱是否已存在
    $emailExists = Database::fetchOne(
        'SELECT id FROM ' . TABLE_PREFIX . 'users WHERE email = ?',
        [$email]
    );
    if ($emailExists) {
        error('该邮箱已被注册');
    }

    // 创建用户
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $userId = Database::insert('users', [
        'username' => $username,
        'password' => $hashedPassword,
        'email' => $email,
        'nickname' => $username,
        'role' => 'user',
        'status' => 1,
        'email_verified' => 0,
    ]);

    // 生成邮箱验证token（有效期24小时）
    $verifyToken = jwt_encode([
        'user_id' => (int)$userId,
        'email' => $email,
        'type' => 'email_verify',
        'exp' => time() + 86400,
    ]);

    // 发送验证邮件
    $mailSent = Mailer::sendVerifyEmail($email, $username, $verifyToken);

    success([
        'user' => [
            'id' => (int)$userId,
            'username' => $username,
            'nickname' => $username,
            'email' => $email,
        ],
        'mail_sent' => $mailSent,
        'mail_enabled' => MAIL_ENABLE,
    ], '注册成功' . (MAIL_ENABLE ? '，请查收验证邮件' : ''));
}

// 登录
// 登录
function do_login() {
    $username = trim(get_param('username', ''));
    $password = get_param('password', '');
    $captcha = get_param('captcha', '');

    if (!$username || !$password) {
        error('用户名和密码不能为空');
    }

    $ip = get_client_ip();

    // 检查是否被锁定
    if (is_login_locked($ip, $username)) {
        error('登录失败次数过多，请稍后再试', 429);
    }

    // 检查是否需要验证码
    if (need_captcha($ip, $username)) {
        if (!$captcha) {
            error('请输入验证码', 400);
        }
        if (!verify_captcha($captcha)) {
            record_login_fail($ip, $username);
            error('验证码错误', 400);
        }
    }

    // 查找用户（支持邮箱或用户名登录）
    $user = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'users WHERE username = ? OR email = ?',
        [$username, $username]
    );

    if (!$user) {
        record_login_fail($ip, $username);
        error('用户名或密码错误', 401);
    }

    // 检查状态
    if ($user['status'] != 1) {
        record_login_fail($ip, $username);
        error('账号已被禁用', 401);
    }

    // 验证密码
    if (!password_verify($password, $user['password'])) {
        record_login_fail($ip, $username);
        error('用户名或密码错误', 401);
    }

    // 登录成功，清除失败记录
    clear_login_fails($ip, $username);

    // 记录登录日志
    log_operation($user['id'], $user['username'], 'login', 'user', $user['id'], '用户登录成功');

    // 生成Token
    $token = jwt_encode([
        'user_id' => (int)$user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
    ]);

    success([
        'token' => $token,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'role' => $user['role'],
            'email_verified' => (int)($user['email_verified'] ?? 0),
        ]
    ], '登录成功');
}
// 邮箱验证
function do_verify() {
    $token = get_param('token', '');
    
    if (!$token) {
        error('验证token不能为空');
    }
    
    // 解析token
    $payload = jwt_decode($token);
    if (!$payload) {
        error('无效的验证链接');
    }
    
    // 检查token类型
    if (!isset($payload['type']) || $payload['type'] !== 'email_verify') {
        error('无效的验证链接');
    }
    
    // 检查是否过期
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        error('验证链接已过期，请重新注册');
    }
    
    $userId = (int)$payload['user_id'];
    $email = $payload['email'];
    
    // 查找用户
    $user = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'users WHERE id = ?',
        [$userId]
    );
    
    if (!$user) {
        error('用户不存在');
    }
    
    // 检查邮箱是否匹配
    if ($user['email'] !== $email) {
        error('验证链接无效');
    }
    
    // 检查是否已验证
    if ($user['email_verified'] == 1) {
        success([
            'already_verified' => true,
        ], '邮箱已经验证过了');
        return;
    }
    
    // 更新邮箱验证状态
    Database::update('users', [
        'email_verified' => 1,
    ], 'id = ?', [$userId]);
    
    // 发送欢迎邮件
    Mailer::sendWelcomeEmail($email, $user['username']);
    
    success([
        'user' => [
            'id' => $userId,
            'username' => $user['username'],
            'email' => $email,
            'email_verified' => 1,
        ]
    ], '邮箱验证成功，欢迎加入！');
}

// 找回密码（发送重置邮件）
function do_forgot() {
    $email = trim(get_param('email', ''));
    
    if (!$email) {
        error('邮箱不能为空');
    }
    
    if (!validate_email($email)) {
        error('邮箱格式不正确');
    }
    
    // 查找用户
    $user = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'users WHERE email = ?',
        [$email]
    );
    
    // 为了安全，无论用户是否存在，都返回成功（防止邮箱枚举）
    if (!$user) {
        success([
            'mail_sent' => true,
        ], '如果该邮箱已注册，我们已发送重置密码邮件');
        return;
    }
    
    // 生成重置密码token（有效期24小时）
    $resetToken = jwt_encode([
        'user_id' => (int)$user['id'],
        'email' => $email,
        'type' => 'password_reset',
        'exp' => time() + 86400,
    ]);
    
    // 发送重置密码邮件
    $mailSent = Mailer::sendResetPasswordEmail($email, $user['username'], $resetToken);
    
    success([
        'mail_sent' => $mailSent,
        'mail_enabled' => MAIL_ENABLE,
    ], '如果该邮箱已注册，我们已发送重置密码邮件');
}

// 重置密码
function do_reset() {
    $token = get_param('token', '');
    $password = get_param('password', '');
    
    if (!$token) {
        error('重置token不能为空');
    }
    
    if (!$password) {
        error('新密码不能为空');
    }
    
    if (!validate_password($password)) {
        error('密码长度6-50位');
    }
    
    // 解析token
    $payload = jwt_decode($token);
    if (!$payload) {
        error('无效的重置链接');
    }
    
    // 检查token类型
    if (!isset($payload['type']) || $payload['type'] !== 'password_reset') {
        error('无效的重置链接');
    }
    
    // 检查是否过期
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        error('重置链接已过期，请重新申请');
    }
    
    $userId = (int)$payload['user_id'];
    $email = $payload['email'];
    
    // 查找用户
    $user = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'users WHERE id = ?',
        [$userId]
    );
    
    if (!$user) {
        error('用户不存在');
    }
    
    // 检查邮箱是否匹配
    if ($user['email'] !== $email) {
        error('重置链接无效');
    }
    
    // 更新密码
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    Database::update('users', [
        'password' => $hashedPassword,
    ], 'id = ?', [$userId]);
    
    success([
        'user' => [
            'id' => $userId,
            'username' => $user['username'],
        ]
    ], '密码重置成功，请使用新密码登录');
}

// 生成验证码
function do_captcha() {
    $code = generate_captcha();
    
    // 生成验证码图片
    $width = 120;
    $height = 40;
    $image = imagecreatetruecolor($width, $height);
    
    // 背景色
    $bgColor = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);
    
    // 文字颜色
    $textColor = imagecolorallocate($image, 51, 102, 204);
    
    // 添加干扰线
    for ($i = 0; $i < 5; $i++) {
        $lineColor = imagecolorallocate($image, rand(100, 200), rand(100, 200), rand(100, 200));
        imageline($image, rand(0, $width), rand(0, $height), rand(0, $width), rand(0, $height), $lineColor);
    }
    
    // 添加干扰点
    for ($i = 0; $i < 50; $i++) {
        $pixelColor = imagecolorallocate($image, rand(0, 255), rand(0, 255), rand(0, 255));
        imagesetpixel($image, rand(0, $width), rand(0, $height), $pixelColor);
    }
    
    // 绘制验证码文字
    $font = 5;
    $x = 10;
    for ($i = 0; $i < strlen($code); $i++) {
        $charColor = imagecolorallocate($image, rand(0, 100), rand(0, 100), rand(100, 200));
        imagechar($image, $font, $x + $i * 25, rand(5, 15), $code[$i], $charColor);
    }
    
    // 输出图片
    header('Content-Type: image/png');
    imagepng($image);
    imagedestroy($image);
    exit;
}
