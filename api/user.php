<?php
/**
 * 用户接口
 * GET  /api/user.php?action=info       获取用户信息
 * POST /api/user.php?action=password   修改密码
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? 'info';

switch ($action) {
    case 'info':
        get_user_info();
        break;
    case 'password':
        change_password();
        break;
    case 'update_profile':
        update_profile();
        break;
    default:
        error('无效的操作');
}

// 获取用户信息
function get_user_info() {
    $auth = auth_required();
    $userId = $auth['user_id'];

    $user = Database::fetchOne(
        'SELECT id, username, nickname, email, role, traffic, traffic_limit, created_at FROM ' . TABLE_PREFIX . 'users WHERE id = ?',
        [$userId]
    );

    if (!$user) {
        error('用户不存在', 404);
    }

    // 隧道数量
    $tunnelCount = Database::count('tunnels', 'user_id = ?', [$userId]);

    success([
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'email' => $user['email'],
            'role' => $user['role'],
            'traffic' => (int)$user['traffic'],
            'traffic_text' => format_bytes($user['traffic']),
            'traffic_limit' => (int)$user['traffic_limit'],
            'traffic_limit_text' => $user['traffic_limit'] > 0 ? format_bytes($user['traffic_limit']) : '不限',
            'tunnel_count' => $tunnelCount,
            'created_at' => $user['created_at'],
        ]
    ]);
}

// 修改密码
function change_password() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $auth = auth_required();
    $userId = $auth['user_id'];

    $oldPassword = get_param('old_password', '');
    $newPassword = get_param('new_password', '');

    if (!$oldPassword || !$newPassword) {
        error('原密码和新密码不能为空');
    }
    if (!validate_password($newPassword)) {
        error('新密码长度6-50位');
    }

    $user = Database::fetchOne(
        'SELECT password FROM ' . TABLE_PREFIX . 'users WHERE id = ?',
        [$userId]
    );

    if (!password_verify($oldPassword, $user['password'])) {
        error('原密码错误');
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    Database::update('users', ['password' => $hashedPassword], 'id = ?', [$userId]);

    success([], '密码修改成功');
}


// 更新个人资料
function update_profile() {
    $auth = auth_required();
    $userId = $auth['user_id'];
    
    $nickname = $_POST['nickname'] ?? '';
    
    // 验证昵称
    if (empty($nickname)) {
        error('昵称不能为空');
    }
    
    if (mb_strlen($nickname) > 20) {
        error('昵称不能超过20个字符');
    }
    
    // 更新昵称
    $result = Database::execute(
        "UPDATE " . TABLE_PREFIX . "users SET nickname = ?, updated_at = NOW() WHERE id = ?",
        [$nickname, $userId]
    );
    
    if ($result) {
        success(['message' => '资料更新成功', 'nickname' => $nickname]);
    } else {
        error('更新失败');
    }
}
