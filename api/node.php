<?php
/**
 * 节点接口
 * GET /api/node.php?action=list     节点列表（公开）
 * GET /api/node.php?action=detail   节点详情（公开）
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        get_node_list();
        break;
    case 'detail':
        get_node_detail();
        break;
    default:
        error('无效的操作');
}

// 节点列表（公开）
function get_node_list() {
    $nodes = Database::fetchAll(
        'SELECT id, name, host, port, location, description, status, min_port, max_port 
         FROM ' . TABLE_PREFIX . 'nodes 
         WHERE status = 1 
         ORDER BY id ASC'
    );

    // 统计每个节点的隧道数量
    foreach ($nodes as &$node) {
        $node['id'] = (int)$node['id'];
        $node['port'] = (int)$node['port'];
        $node['status'] = (int)$node['status'];
        $node['min_port'] = (int)$node['min_port'];
        $node['max_port'] = (int)$node['max_port'];
        $node['tunnel_count'] = Database::count('tunnels', 'node_id = ? AND status = 1', [$node['id']]);
    }

    success([
        'nodes' => $nodes,
        'total' => count($nodes),
    ]);
}

// 节点详情
function get_node_detail() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        error('节点ID不能为空');
    }

    $node = Database::fetchOne(
        'SELECT id, name, host, port, location, description, status, min_port, max_port 
         FROM ' . TABLE_PREFIX . 'nodes 
         WHERE id = ?',
        [$id]
    );

    if (!$node) {
        error('节点不存在', 404);
    }

    $node['id'] = (int)$node['id'];
    $node['port'] = (int)$node['port'];
    $node['status'] = (int)$node['status'];
    $node['min_port'] = (int)$node['min_port'];
    $node['max_port'] = (int)$node['max_port'];
    $node['tunnel_count'] = Database::count('tunnels', 'node_id = ? AND status = 1', [$id]);

    success(['node' => $node]);
}
