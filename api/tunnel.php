<?php
/**
 * 隧道接口
 * GET    /api/tunnel.php?action=list       我的隧道列表
 * GET    /api/tunnel.php?action=detail     隧道详情
 * POST   /api/tunnel.php?action=create     创建隧道
 * POST   /api/tunnel.php?action=update     更新隧道
 * POST   /api/tunnel.php?action=delete     删除隧道
 * GET    /api/tunnel.php?action=config     获取配置
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        get_my_tunnels();
        break;
    case 'detail':
        get_tunnel_detail();
        break;
    case 'create':
        create_tunnel();
        break;
    case 'update':
        update_tunnel();
        break;
    case 'delete':
        delete_tunnel();
        break;
    case 'config':
        get_tunnel_config();
        break;
    default:
        error('无效的操作');
}

// 我的隧道列表
function get_my_tunnels() {
    $auth = auth_required();
    $userId = $auth['user_id'];

    $tunnels = Database::fetchAll(
        'SELECT t.*, n.name as node_name, n.host as node_host, n.port as node_port 
         FROM ' . TABLE_PREFIX . 'tunnels t 
         LEFT JOIN ' . TABLE_PREFIX . 'nodes n ON t.node_id = n.id 
         WHERE t.user_id = ? 
         ORDER BY t.id DESC',
        [$userId]
    );

    foreach ($tunnels as &$t) {
        $t['id'] = (int)$t['id'];
        $t['user_id'] = (int)$t['user_id'];
        $t['node_id'] = (int)$t['node_id'];
        $t['local_port'] = (int)$t['local_port'];
        $t['remote_port'] = (int)$t['remote_port'];
        $t['status'] = (int)$t['status'];
        $t['traffic'] = (int)$t['traffic'];
        $t['traffic_text'] = format_bytes($t['traffic']);
        $t['node_port'] = (int)$t['node_port'];
        // 组装node对象
        $t['node'] = [
            'id' => (int)$t['node_id'],
            'name' => $t['node_name'],
            'host' => $t['node_host'],
            'port' => (int)$t['node_port'],
        ];
    }

    success([
        'tunnels' => $tunnels,
        'total' => count($tunnels),
    ]);
}

// 隧道详情
function get_tunnel_detail() {
    $auth = auth_required();
    $userId = $auth['user_id'];
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        error('隧道ID不能为空');
    }

    $tunnel = Database::fetchOne(
        'SELECT t.*, n.name as node_name, n.host as node_host, n.port as node_port 
         FROM ' . TABLE_PREFIX . 'tunnels t 
         LEFT JOIN ' . TABLE_PREFIX . 'nodes n ON t.node_id = n.id 
         WHERE t.id = ? AND t.user_id = ?',
        [$id, $userId]
    );

    if (!$tunnel) {
        error('隧道不存在', 404);
    }

    $tunnel['id'] = (int)$tunnel['id'];
    $tunnel['user_id'] = (int)$tunnel['user_id'];
    $tunnel['node_id'] = (int)$tunnel['node_id'];
    $tunnel['local_port'] = (int)$tunnel['local_port'];
    $tunnel['remote_port'] = (int)$tunnel['remote_port'];
    $tunnel['status'] = (int)$tunnel['status'];
    $tunnel['traffic'] = (int)$tunnel['traffic'];
    $tunnel['traffic_text'] = format_bytes($tunnel['traffic']);
    $tunnel['node_port'] = (int)$tunnel['node_port'];
    // 组装node对象
    $tunnel['node'] = [
        'id' => (int)$tunnel['node_id'],
        'name' => $tunnel['node_name'],
        'host' => $tunnel['node_host'],
        'port' => (int)$tunnel['node_port'],
    ];

    success(['tunnel' => $tunnel]);
}

// 创建隧道
function create_tunnel() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $auth = auth_required();
    $userId = $auth['user_id'];

    $nodeId = (int)get_param('node_id', 0);
    $name = trim(get_param('name', ''));
    $type = get_param('type', 'tcp');
    $localAddr = trim(get_param('local_addr', '127.0.0.1'));
    $localPort = (int)get_param('local_port', 0);
    $remotePort = (int)get_param('remote_port', 0);

    if (!$nodeId || !$name || !$localPort) {
        error('节点、名称、本地端口不能为空');
    }
    if (!in_array($type, ['tcp', 'udp'])) {
        error('类型只能是tcp或udp');
    }
    if ($localPort < 1 || $localPort > 65535) {
        error('本地端口范围1-65535');
    }

    // 检查节点
    $node = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'nodes WHERE id = ?',
        [$nodeId]
    );
    if (!$node) {
        error('节点不存在');
    }
    if ($node['status'] != 1) {
        error('节点不可用');
    }

    // 分配远程端口
    if ($remotePort == 0) {
        $remotePort = allocate_port($nodeId, $node['min_port'], $node['max_port']);
        if (!$remotePort) {
            error('没有可用端口');
        }
    } else {
        // 检查端口是否被占用
        $exists = Database::fetchOne(
            'SELECT id FROM ' . TABLE_PREFIX . 'tunnels WHERE node_id = ? AND remote_port = ?',
            [$nodeId, $remotePort]
        );
        if ($exists) {
            error('该端口已被占用');
        }
        // 检查端口范围
        if ($remotePort < $node['min_port'] || $remotePort > $node['max_port']) {
            error('端口范围: ' . $node['min_port'] . ' - ' . $node['max_port']);
        }
    }

    // 创建隧道
    $tunnelId = Database::insert('tunnels', [
        'user_id' => $userId,
        'node_id' => $nodeId,
        'name' => $name,
        'type' => $type,
        'local_addr' => $localAddr ?: '127.0.0.1',
        'local_port' => $localPort,
        'remote_port' => $remotePort,
        'status' => 1,
    ]);

    // 生成配置
    $config = generate_config($node, [
        'name' => $name,
        'type' => $type,
        'local_addr' => $localAddr ?: '127.0.0.1',
        'local_port' => $localPort,
        'remote_port' => $remotePort,
    ]);

    success([
        'tunnel_id' => (int)$tunnelId,
        'remote_port' => $remotePort,
        'config' => $config,
    ], '隧道创建成功');
}

// 更新隧道
function update_tunnel() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $auth = auth_required();
    $userId = $auth['user_id'];
    $id = (int)get_param('id', 0);

    if (!$id) {
        error('隧道ID不能为空');
    }

    $tunnel = Database::fetchOne(
        'SELECT * FROM ' . TABLE_PREFIX . 'tunnels WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );

    if (!$tunnel) {
        error('隧道不存在', 404);
    }

    $name = trim(get_param('name', ''));
    $localAddr = trim(get_param('local_addr', ''));
    $localPort = (int)get_param('local_port', 0);
    $status = (int)get_param('status', 0);

    $updateData = [];
    if ($name) $updateData['name'] = $name;
    if ($localAddr) $updateData['local_addr'] = $localAddr;
    if ($localPort) $updateData['local_port'] = $localPort;
    if ($status) $updateData['status'] = $status;

    if (empty($updateData)) {
        error('没有要更新的内容');
    }

    Database::update('tunnels', $updateData, 'id = ?', [$id]);

    // 重新获取隧道和节点信息
    $tunnel = Database::fetchOne(
        'SELECT t.*, n.name as node_name, n.host as node_host, n.port as node_port, n.token as node_token 
         FROM ' . TABLE_PREFIX . 'tunnels t 
         LEFT JOIN ' . TABLE_PREFIX . 'nodes n ON t.node_id = n.id 
         WHERE t.id = ?',
        [$id]
    );

    $config = generate_config($tunnel, $tunnel);

    success(['config' => $config], '更新成功');
}

// 删除隧道
function delete_tunnel() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('请使用POST请求');
    }

    $auth = auth_required();
    $userId = $auth['user_id'];
    $id = (int)get_param('id', 0);

    if (!$id) {
        error('隧道ID不能为空');
    }

    $tunnel = Database::fetchOne(
        'SELECT id FROM ' . TABLE_PREFIX . 'tunnels WHERE id = ? AND user_id = ?',
        [$id, $userId]
    );

    if (!$tunnel) {
        error('隧道不存在', 404);
    }

    Database::delete('tunnels', 'id = ?', [$id]);

    success([], '隧道已删除');
}

// 获取配置
function get_tunnel_config() {
    $auth = auth_required();
    $userId = $auth['user_id'];
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        error('隧道ID不能为空');
    }

    $tunnel = Database::fetchOne(
        'SELECT t.*, n.name as node_name, n.host as node_host, n.port as node_port, n.token as node_token 
         FROM ' . TABLE_PREFIX . 'tunnels t 
         LEFT JOIN ' . TABLE_PREFIX . 'nodes n ON t.node_id = n.id 
         WHERE t.id = ? AND t.user_id = ?',
        [$id, $userId]
    );

    if (!$tunnel) {
        error('隧道不存在', 404);
    }

    $config = generate_config($tunnel, $tunnel);

    success([
        'config' => $config,
        'node' => [
            'name' => $tunnel['node_name'],
            'host' => $tunnel['node_host'],
            'port' => (int)$tunnel['node_port'],
        ],
        'tunnel' => [
            'name' => $tunnel['name'],
            'type' => $tunnel['type'],
            'local_addr' => $tunnel['local_addr'],
            'local_port' => (int)$tunnel['local_port'],
            'remote_port' => (int)$tunnel['remote_port'],
        ],
    ]);
}

// 分配端口
function allocate_port($nodeId, $minPort, $maxPort) {
    $usedPorts = Database::fetchAll(
        'SELECT remote_port FROM ' . TABLE_PREFIX . 'tunnels WHERE node_id = ?',
        [$nodeId]
    );

    $used = [];
    foreach ($usedPorts as $p) {
        $used[$p['remote_port']] = true;
    }

    // 随机尝试
    for ($i = 0; $i < 100; $i++) {
        $port = rand($minPort, $maxPort);
        if (!isset($used[$port])) {
            return $port;
        }
    }

    // 顺序查找
    for ($port = $minPort; $port <= $maxPort; $port++) {
        if (!isset($used[$port])) {
            return $port;
        }
    }

    return 0;
}

// 生成配置文件
function generate_config($node, $tunnel) {
    $nodeHost = $node['host'] ?? $node['node_host'];
    $nodePort = $node['port'] ?? $node['node_port'];
    $nodeToken = $node['token'] ?? $node['node_token'];
    $nodeName = $node['name'] ?? $node['node_name'];

    return "# NexusLink 客户端配置\n"
        . "# 节点: {$nodeName}\n"
        . "# 隧道: {$tunnel['name']}\n"
        . "\n"
        . "server_ip: {$nodeHost}\n"
        . "server_port: {$nodePort}\n"
        . "token: {$nodeToken}\n"
        . "\n"
        . "proxies:\n"
        . "  {$tunnel['name']}:\n"
        . "    type: {$tunnel['type']}\n"
        . "    port: {$tunnel['remote_port']}\n"
        . "    localaddr: {$tunnel['local_addr']}\n"
        . "    localport: {$tunnel['local_port']}\n";
}
