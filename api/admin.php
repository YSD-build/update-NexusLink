<?php
/**
 * 管理员接口
 * GET    /api/admin.php?action=node_list       节点列表
 * POST   /api/admin.php?action=node_create     创建节点
 * POST   /api/admin.php?action=node_update     更新节点
 * POST   /api/admin.php?action=node_delete     删除节点
 * GET    /api/admin.php?action=user_list       用户列表
 * POST   /api/admin.php?action=user_update     更新用户
 * POST   /api/admin.php?action=user_delete     删除用户
 * GET    /api/admin.php?action=stats           统计数据
 * GET    /api/admin.php?action=tunnel_list     所有隧道列表
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? 'stats';

// 所有接口都需要管理员权限
$auth = admin_required();

switch ($action) {
    // 节点管理
    case 'node_list':
        admin_node_list();
        break;
    case 'node_create':
        admin_node_create();
        break;
    case 'node_update':
        admin_node_update();
        break;
    case 'node_delete':
        admin_node_delete();
        break;

    // 用户管理
    case 'user_list':
        admin_user_list();
        break;
    case 'user_update':
        admin_user_update();
        break;
    case 'user_delete':
        admin_user_delete();
        break;

    // 统计
    case 'stats':
        admin_stats();
        break;

    // 隧道管理
    case 'tunnel_list':
        admin_tunnel_list();
        break;

    default:
        error('无效的操作');
}

// ========== 节点管理 ==========

function admin_node_list() {
    $nodes = Database::fetchAll(
        'SELECT * FROM ' . TABLE_PREFIX . 'nodes ORDER BY id DESC'
    );

    foreach ($nodes as &$node) {
        $node['id'] = (int)$node['id'];
        $node['port'] = (int)$node['port'];
        $node['status'] = (int)$node['status'];
        $node['max_ports'] = (int)$node['max_ports'];
        $node['min_port'] = (int)$node['min_port'];
        $node['max_port'] = (int)$node['max_port'];
        $node['tunnel_count'] = Database::count('tunnels', 'node_id = ?', [$node['id']]);
    }

    success([
        'nodes' => $nodes,
        'total' => count($nodes),
    ]);
}

function admin_node_create() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $name = trim(get_param('name', ''));
    $host = trim(get_param('host', ''));
    $port = (int)get_param('port', 7000);
    $token = trim(get_param('token', ''));
    $location = trim(get_param('location', ''));
    $description = trim(get_param('description', ''));
    $minPort = (int)get_param('min_port', 10000);
    $maxPort = (int)get_param('max_port', 60000);

    if (!$name || !$host || !$token) {
        error('名称、地址、Token不能为空');
    }

    $id = Database::insert('nodes', [
        'name' => $name,
        'host' => $host,
        'port' => $port,
        'token' => $token,
        'location' => $location,
        'description' => $description,
        'status' => 1,
        'min_port' => $minPort,
        'max_port' => $maxPort,
    ]);

    success(['id' => (int)$id], '节点创建成功');
}

function admin_node_update() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $id = (int)get_param('id', 0);
    if (!$id) {
        error('节点ID不能为空');
    }

    $node = Database::fetchOne('SELECT id FROM ' . TABLE_PREFIX . 'nodes WHERE id = ?', [$id]);
    if (!$node) {
        error('节点不存在', 404);
    }

    $data = [];
    $fields = ['name', 'host', 'port', 'token', 'location', 'description', 'status', 'min_port', 'max_port'];
    foreach ($fields as $field) {
        $val = get_param($field, null);
        if ($val !== null) {
            $data[$field] = $val;
        }
    }

    if (empty($data)) {
        error('没有要更新的内容');
    }

    Database::update('nodes', $data, 'id = ?', [$id]);

    success([], '节点更新成功');
}

function admin_node_delete() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $id = (int)get_param('id', 0);
    if (!$id) {
        error('节点ID不能为空');
    }

    // 检查是否有隧道
    $count = Database::count('tunnels', 'node_id = ?', [$id]);
    if ($count > 0) {
        error('该节点下还有隧道，无法删除');
    }

    Database::delete('nodes', 'id = ?', [$id]);

    success([], '节点已删除');
}

// ========== 用户管理 ==========

function admin_user_list() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = 20;
    $offset = ($page - 1) * $pageSize;

    $users = Database::fetchAll(
        'SELECT id, username, nickname, email, role, status, traffic, traffic_limit, created_at 
         FROM ' . TABLE_PREFIX . 'users 
         ORDER BY id DESC 
         LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset
    );

    $total = Database::count('users');

    foreach ($users as &$user) {
        $user['id'] = (int)$user['id'];
        $user['status'] = (int)$user['status'];
        $user['traffic'] = (int)$user['traffic'];
        $user['traffic_text'] = format_bytes($user['traffic']);
        $user['traffic_limit'] = (int)$user['traffic_limit'];
        $user['tunnel_count'] = Database::count('tunnels', 'user_id = ?', [$user['id']]);
    }

    success([
        'users' => $users,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ]);
}

function admin_user_update() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $id = (int)get_param('id', 0);
    if (!$id) {
        error('用户ID不能为空');
    }

    $user = Database::fetchOne('SELECT id FROM ' . TABLE_PREFIX . 'users WHERE id = ?', [$id]);
    if (!$user) {
        error('用户不存在', 404);
    }

    $data = [];
    $fields = ['nickname', 'email', 'role', 'status', 'traffic_limit'];
    foreach ($fields as $field) {
        $val = get_param($field, null);
        if ($val !== null) {
            $data[$field] = $val;
        }
    }

    // 密码单独处理
    $password = get_param('password', '');
    if ($password) {
        if (!validate_password($password)) {
            error('密码长度6-50位');
        }
        $data['password'] = password_hash($password, PASSWORD_BCRYPT);
    }

    if (empty($data)) {
        error('没有要更新的内容');
    }

    Database::update('users', $data, 'id = ?', [$id]);

    success([], '用户更新成功');
}

function admin_user_delete() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $id = (int)get_param('id', 0);
    if (!$id) {
        error('用户ID不能为空');
    }

    // 不能删除自己
    $auth = admin_required();
    if ($id == $auth['user_id']) {
        error('不能删除自己');
    }

    // 删除用户的隧道
    Database::delete('tunnels', 'user_id = ?', [$id]);
    Database::delete('traffic_logs', 'user_id = ?', [$id]);
    Database::delete('users', 'id = ?', [$id]);

    success([], '用户已删除');
}

// ========== 统计 ==========

function admin_stats() {
    $userCount = Database::count('users');
    $nodeCount = Database::count('nodes');
    $tunnelCount = Database::count('tunnels');
    $activeTunnelCount = Database::count('tunnels', 'status = 1');

    // 总流量
    $totalTraffic = Database::fetchOne(
        'SELECT SUM(traffic) as total FROM ' . TABLE_PREFIX . 'users'
    );
    $totalTrafficBytes = (int)($totalTraffic['total'] ?? 0);

    success([
        'stats' => [
            'user_count' => $userCount,
            'node_count' => $nodeCount,
            'tunnel_count' => $tunnelCount,
            'active_tunnel_count' => $activeTunnelCount,
            'total_traffic' => $totalTrafficBytes,
            'total_traffic_text' => format_bytes($totalTrafficBytes),
        ]
    ]);
}

// ========== 隧道管理 ==========

function admin_tunnel_list() {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = 50;
    $offset = ($page - 1) * $pageSize;

    $tunnels = Database::fetchAll(
        'SELECT t.*, n.name as node_name, u.username as user_username 
         FROM ' . TABLE_PREFIX . 'tunnels t 
         LEFT JOIN ' . TABLE_PREFIX . 'nodes n ON t.node_id = n.id 
         LEFT JOIN ' . TABLE_PREFIX . 'users u ON t.user_id = u.id 
         ORDER BY t.id DESC 
         LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset
    );

    $total = Database::count('tunnels');

    foreach ($tunnels as &$t) {
        $t['id'] = (int)$t['id'];
        $t['user_id'] = (int)$t['user_id'];
        $t['node_id'] = (int)$t['node_id'];
        $t['local_port'] = (int)$t['local_port'];
        $t['remote_port'] = (int)$t['remote_port'];
        $t['status'] = (int)$t['status'];
        $t['traffic'] = (int)$t['traffic'];
        $t['traffic_text'] = format_bytes($t['traffic']);
        // 组装node对象
        $t['node'] = [
            'id' => (int)$t['node_id'],
            'name' => $t['node_name'],
        ];
        // 组装user对象
        $t['user'] = [
            'id' => (int)$t['user_id'],
            'username' => $t['user_username'],
        ];
    }

    success([
        'tunnels' => $tunnels,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ]);
}
