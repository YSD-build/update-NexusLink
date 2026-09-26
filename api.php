<?php
/**
 * 蓝天内网穿透 · 分发系统 REST API
 * 兼容 PHP 7.4 / 8.0+，对齐 NexusLink /api/v1/* 语义
 *
 * ---------------------------------------------------------------------------
 * 【零配置部署】本文件放在网站根目录，前端请求走 PATH_INFO 形式，
 * 不依赖任何 nginx 伪静态 / try_files / rewrite 规则。
 *
 * 支持的请求形式（三种全部兼容）：
 *   1. /api.php/v1/auth/login          ← 前端默认走这个，nginx 天然支持
 *   2. /api/v1/auth/login              ← 需要 try_files 转发，为兼容保留
 *   3. /index.php/api/v1/auth/login    ← PATH_INFO 变体，兼容保留
 *
 * 解析优先级：能拿到 PATH_INFO 就用 PATH_INFO，否则从 REQUEST_URI 里剥离前缀。
 * ---------------------------------------------------------------------------
 *
 * 路由规则：{prefix}/v1/{resource}/{id}/{action}
 *   $segs[0] = v1
 *   $segs[1] = resource  （clients / proxies / nodes / users / api-keys / downloads / settings / dashboard / auth）
 *   $segs[2] = id 或 子动作（login / stats / close / open）
 *   $segs[3] = 动作（traffic / status）
 */

require __DIR__ . '/db.php';
require_once __DIR__ . '/updater.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/');

/**
 * 从请求中剥离出 /v1/... 部分，兼容 PATH_INFO 与普通路径两种形式。
 *
 * 例：
 *   /api.php/v1/auth/login        → /v1/auth/login
 *   /api/v1/auth/login            → /v1/auth/login
 *   /index.php/api/v1/auth/login  → /v1/auth/login
 *   /v1/auth/login                → /v1/auth/login
 */
$path = $uri;

// 1) 若 PHP 已解析出 PATH_INFO（形如 /api.php/v1/auth/login 的请求），优先使用
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo !== '') {
    $path = $pathInfo;
}

// 2) 从路径中剥掉入口文件名（api.php / index.php）及其前面的所有内容
if (preg_match('#/(?:api|index)\.php(/.+)$#', $path, $m)) {
    $path = $m[1];
} else {
    // 3) 兜底：剥掉开头的 /api 前缀
    $path = preg_replace('#^/api(?=/|$)#', '', $path);
}

// 4) 最终兜底：若仍未以 /v1 开头，尝试从任意位置截取 /v1/...
if (strpos($path, '/v1/') !== 0 && $path !== '/v1') {
    $pos = strpos($path, '/v1/');
    $path = ($pos !== false) ? substr($path, $pos) : $path;
}

$segs = array_values(array_filter(explode('/', $path), 'strlen'));

if (count($segs) < 2 || $segs[0] !== 'v1') {
    respond(['success' => false, 'error' => 'BAD_REQUEST'], 400);
}

$resource = $segs[1] ?? '';
$id       = $segs[2] ?? null;
$action   = $segs[3] ?? null;

$db = pdo();

/* ==================== 认证 ==================== */

if ($resource === 'auth' && $id === 'login' && $method === 'POST') {
    $b = body();
    $u = trim($b['username'] ?? '');
    $p = trim($b['password'] ?? '');

    if ($u === '' || $p === '') {
        respond(['success' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
    }

    $st = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$u]);
    $user = $st->fetch();

    if (!$user || !password_verify($p, $user['password'])) {
        respond(['success' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
    }

    if ($user['status'] === 'banned') {
        respond(['success' => false, 'error' => 'ACCOUNT_BANNED'], 403);
    }
    if ($user['status'] === 'pending') {
        respond(['success' => false, 'error' => 'ACCOUNT_PENDING'], 403);
    }

    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO sessions (token, username) VALUES (?, ?)')->execute([$token, $u]);

    respond([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'username' => $u,
            'role'     => $user['role'],
            'email'    => $user['email'],
            'realName' => $user['real_name'],
        ],
    ]);
}

if ($resource === 'auth' && $id === 'logout' && $method === 'POST') {
    $t = bearerToken();
    if ($t) {
        $db->prepare('DELETE FROM sessions WHERE token = ?')->execute([$t]);
    }
    respond(['success' => true]);
}

/* 以下接口均需鉴权 */
requireAuth();

/* ==================== 公共工具 ==================== */

/** 字段长度上限，与 sql/init.sql 中各 varchar 定义保持一致 */
const LIMITS = [
    'client_name'   => 64,   // clients.name
    'client_token'  => 128,  // clients.token
    'proxy_name'    => 64,   // proxies.name
    'node_name'     => 64,   // nodes.name
    'user_name'     => 64,   // users.username
    'api_key'       => 128,  // api_keys.key
    'host'          => 128,  // nodes.host
    'email'         => 128,  // users.email
    'custom_domain' => 190,  // proxies.custom_domain
];

/**
 * 校验字符串字段长度，超限直接返回 400 并说明原因。
 * 避免超长输入穿透到 MySQL 层触发 500，让调用方拿到可读错误。
 */
function checkLen(string $value, string $key, string $fieldLabel): void
{
    $max = LIMITS[$key] ?? 255;
    if (mb_strlen($value) > $max) {
        respond([
            'success' => false,
            'error'   => 'FIELD_TOO_LONG',
            'field'   => $fieldLabel,
            'max'     => $max,
            'actual'  => mb_strlen($value),
        ], 400);
    }
}

/** 下线指定客户端的全部隧道，并标记为断开 */
function offlineClientTunnels(PDO $db, string $clientName): int
{
    if ($clientName === '') {
        return 0;
    }
    $st = $db->prepare("UPDATE proxies SET status = 'offline' WHERE client_name = ?");
    $st->execute([$clientName]);
    $db->prepare('UPDATE clients SET connected = 0 WHERE name = ?')->execute([$clientName]);
    return $st->rowCount();
}

/* ==================== 仪表盘 ==================== */

if ($resource === 'dashboard' && $id === 'stats') {
    $totalUsers    = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $onlineNodes   = (int) $db->query("SELECT COUNT(*) FROM nodes WHERE status = 'online'")->fetchColumn();
    $activeTunnels = (int) $db->query("SELECT COUNT(*) FROM proxies WHERE status = 'online'")->fetchColumn();
    $todayTraffic  = (int) $db->query('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM clients')->fetchColumn();

    $rows = $db->query(
        'SELECT stat_date, bytes_in, bytes_out FROM traffic_stats ORDER BY stat_date DESC LIMIT 7'
    )->fetchAll();

    if (!$rows) {
        // 无历史统计时，按客户端总量派生一条趋势，避免前端空图
        $totals = $db->query(
            'SELECT COALESCE(SUM(bytes_in),0) bi, COALESCE(SUM(bytes_out),0) bo FROM clients'
        )->fetch();
        $rows = [];
        for ($i = 6; $i >= 0; $i--) {
            $rows[] = [
                'stat_date' => date('Y-m-d', strtotime("-{$i} days")),
                'bytes_in'  => (int) ($totals['bi'] / 7 * (0.6 + ($i % 3) * 0.2)),
                'bytes_out' => (int) ($totals['bo'] / 7 * (0.6 + ($i % 4) * 0.15)),
            ];
        }
    }

    usort($rows, function ($a, $b) {
        return strcmp($a['stat_date'], $b['stat_date']);
    });

    respond([
        'success' => true,
        'data'    => [
            'totalUsers'    => $totalUsers,
            'onlineNodes'   => $onlineNodes,
            'activeTunnels' => $activeTunnels,
            'todayTraffic'  => $todayTraffic,
            'trafficTrend'  => array_map(function ($r) {
                return [
                    'date' => substr($r['stat_date'], 5),
                    'in'   => (int) $r['bytes_in'],
                    'out'  => (int) $r['bytes_out'],
                ];
            }, $rows),
        ],
    ]);
}

/* ==================== 客户端凭据 ==================== */

if ($resource === 'clients') {
    // POST /clients/{name}/report —— 流量上报（本项目扩展）
    // 供隧道节点周期性回传该客户端的实际用量，超配额自动下线全部隧道。
    if ($id !== null && $action === 'report' && $method === 'POST') {
        $b = body();
        $st = $db->prepare(
            'SELECT max_traffic_bytes AS quota FROM clients WHERE name = ?'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $in  = (int) ($b['bytesIn'] ?? $b['bytes_in'] ?? 0);
        $out = (int) ($b['bytesOut'] ?? $b['bytes_out'] ?? 0);

        // mode=delta 累加（默认），mode=absolute 覆盖
        $mode = $b['mode'] ?? 'delta';
        if ($mode === 'absolute') {
            $db->prepare('UPDATE clients SET bytes_in = ?, bytes_out = ? WHERE name = ?')
               ->execute([$in, $out, $id]);
        } else {
            $db->prepare('UPDATE clients SET bytes_in = bytes_in + ?, bytes_out = bytes_out + ? WHERE name = ?')
               ->execute([$in, $out, $id]);
        }

        $now = $db->prepare('SELECT bytes_in AS bi, bytes_out AS bo FROM clients WHERE name = ?');
        $now->execute([$id]);
        $n = $now->fetch();
        $total = (int) $n['bi'] + (int) $n['bo'];

        // 超配额 → 自动下线该客户端全部隧道
        $offlined = 0;
        $quota = (int) $c['quota'];
        if ($quota > 0 && $total >= $quota) {
            $offlined = offlineClientTunnels($db, $id);
        }

        respond([
            'success'     => true,
            'total'       => $total,
            'quota'       => $quota,
            'overQuota'   => $quota > 0 && $total >= $quota,
            'offlined'    => $offlined,
        ]);
    }

    // GET /clients/{name}/traffic —— 单客户端流量
    if ($id !== null && $action === 'traffic' && $method === 'GET') {
        $st = $db->prepare(
            'SELECT bytes_in AS bytesIn, bytes_out AS bytesOut,
                    max_traffic_bytes AS maxTrafficBytes,
                    proxy_count AS proxyCount, max_tunnels AS maxTunnels,
                    IF(connected = 1, TRUE, FALSE) AS connected
             FROM clients WHERE name = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $r['bytesIn']         = (int) $r['bytesIn'];
        $r['bytesOut']        = (int) $r['bytesOut'];
        $r['maxTrafficBytes'] = (int) $r['maxTrafficBytes'];
        $r['proxyCount']      = (int) $r['proxyCount'];
        $r['maxTunnels']      = (int) $r['maxTunnels'];
        $r['connected']       = (bool) $r['connected'];
        $r['total']           = $r['bytesIn'] + $r['bytesOut'];
        $r['usedPercent']     = $r['maxTrafficBytes'] > 0
            ? round($r['total'] / $r['maxTrafficBytes'] * 100, 2)
            : 0;
        $r['overQuota']       = $r['maxTrafficBytes'] > 0 && $r['total'] >= $r['maxTrafficBytes'];
        respond(['success' => true, 'traffic' => $r]);
    }

    if ($method === 'GET') {
        // 注意：按 NexusLink 官方契约，列表不返回 token，避免多租户凭据泄露。
        // 需要查看或轮换凭据请用 PUT /clients/{name}。
        $rows = $db->query(
            'SELECT name, bytes_in AS bytesIn, bytes_out AS bytesOut,
                    IF(connected = 1, TRUE, FALSE) AS connected,
                    proxy_count AS proxyCount, max_tunnels AS maxTunnels,
                    max_traffic_bytes AS maxTrafficBytes
             FROM clients ORDER BY id'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['connected']       = (bool) $r['connected'];
            $r['bytesIn']         = (int) $r['bytesIn'];
            $r['bytesOut']        = (int) $r['bytesOut'];
            $r['proxyCount']      = (int) $r['proxyCount'];
            $r['maxTunnels']      = (int) $r['maxTunnels'];
            $r['maxTrafficBytes'] = (int) $r['maxTrafficBytes'];
            $total = $r['bytesIn'] + $r['bytesOut'];
            $r['total']           = $total;
            $r['usedPercent']     = $r['maxTrafficBytes'] > 0
                ? round($total / $r['maxTrafficBytes'] * 100, 2)
                : 0;
            $r['overQuota']       = $r['maxTrafficBytes'] > 0 && $total >= $r['maxTrafficBytes'];
        }
        unset($r);
        respond(['success' => true, 'clients' => $rows]);
    }

    if ($method === 'POST') {
        $b = body();

        // 官方契约：缺 name / token → 400
        $name  = trim($b['name'] ?? '');
        $token = trim($b['token'] ?? '');
        if ($name === '' || $token === '') {
            respond(['success' => false, 'error' => 'NAME_AND_TOKEN_REQUIRED'], 400);
        }
        // 长度校验：超限返回可读的 400，而非让 MySQL 抛错变 500
        checkLen($name, 'client_name', 'name');
        checkLen($token, 'client_token', 'token');
        // 官方契约：名称或 token 任一冲突 → 409
        $st = $db->prepare('SELECT name FROM clients WHERE name = ? OR token = ? LIMIT 1');
        $st->execute([$name, $token]);
        if ($row = $st->fetch()) {
            respond([
                'success' => false,
                'error'   => $row['name'] === $name ? 'NAME_CONFLICT' : 'TOKEN_CONFLICT',
            ], 409);
        }

        $db->prepare(
            'INSERT INTO clients (name, token, max_tunnels, max_traffic_bytes, connected)
             VALUES (?, ?, ?, ?, 0)'
        )->execute([
            $name,
            $token,
            (int) ($b['max_tunnels'] ?? $b['maxTunnels'] ?? 3),
            (int) ($b['max_traffic_bytes'] ?? $b['maxTrafficBytes'] ?? 1073741824),
        ]);
        respond(['success' => true, 'name' => $name]);
    }

    // PUT /clients/{name} —— 轮换 token / 调整配额（本项目扩展：官方无此接口）
    if ($method === 'PUT' && $id !== null) {
        $b = body();
        $st = $db->prepare('SELECT id FROM clients WHERE name = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // 轮换 token 时同样要检查冲突
        $newToken = trim($b['token'] ?? '');
        if ($newToken !== '') {
            checkLen($newToken, 'client_token', 'token');
            $ck = $db->prepare('SELECT name FROM clients WHERE token = ? AND name <> ? LIMIT 1');
            $ck->execute([$newToken, $id]);
            if ($ck->fetch()) {
                respond(['success' => false, 'error' => 'TOKEN_CONFLICT'], 409);
            }
        }

        $fields = ['max_tunnels = ?', 'max_traffic_bytes = ?'];
        $params = [
            (int) ($b['maxTunnels'] ?? $b['max_tunnels'] ?? 3),
            (int) ($b['maxTrafficBytes'] ?? $b['max_traffic_bytes'] ?? 1073741824),
        ];
        if ($newToken !== '') {
            array_unshift($fields, 'token = ?');
            array_unshift($params, $newToken);
        }
        $params[] = $id;
        $db->prepare('UPDATE clients SET ' . implode(', ', $fields) . ' WHERE name = ?')->execute($params);

        // token 轮换后旧会话失效：踢下线
        if ($newToken !== '') {
            $db->prepare('UPDATE clients SET connected = 0 WHERE name = ?')->execute([$id]);
            $db->prepare("UPDATE proxies SET status = 'offline' WHERE client_name = ?")->execute([$id]);
        }

        respond(['success' => true]);
    }

    // DELETE /clients/{name} —— 删除并踢下线
    if ($method === 'DELETE' && $id !== null) {
        $st = $db->prepare('SELECT id FROM clients WHERE name = ?');
        $st->execute([$id]);
        if (!$st->fetch()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $db->prepare('DELETE FROM clients WHERE name = ?')->execute([$id]);
        $db->prepare('DELETE FROM proxies WHERE client_name = ?')->execute([$id]);
        respond(['success' => true]);
    }
}

/* ==================== 隧道 / 代理 ==================== */

if ($resource === 'proxies') {
    // POST /proxies/close —— 下线隧道（NexusLink 官方接口）
    if ($method === 'POST' && $id === 'close') {
        $b = body();
        $name = trim($b['name'] ?? '');
        if ($name === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $st = $db->prepare('SELECT id FROM proxies WHERE name = ?');
        $st->execute([$name]);
        if (!$st->fetch()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $db->prepare("UPDATE proxies SET status = 'offline' WHERE name = ?")->execute([$name]);
        respond(['success' => true]);
    }

    // POST /proxies/open —— 重新上线隧道
    // 注意：此为「蓝天」扩展接口，NexusLink 官方 API 无 open，
    // 官方只提供 close（下线）。保留是为了让运营侧能恢复误停的隧道。
    if ($method === 'POST' && $id === 'open') {
        $b = body();
        $name = trim($b['name'] ?? '');
        if ($name === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $st = $db->prepare(
            'SELECT p.id, p.client_name AS clientName, c.max_traffic_bytes AS quota,
                    c.bytes_in AS bi, c.bytes_out AS bo
             FROM proxies p LEFT JOIN clients c ON c.name = p.client_name
             WHERE p.name = ?'
        );
        $st->execute([$name]);
        $row = $st->fetch();
        if (!$row) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // 配额已满则拒绝上线
        if ($row['quota'] !== null && (int) $row['quota'] > 0
            && ((int) $row['bi'] + (int) $row['bo']) >= (int) $row['quota']) {
            respond(['success' => false, 'error' => 'QUOTA_EXCEEDED'], 403);
        }

        $db->prepare("UPDATE proxies SET status = 'online' WHERE name = ?")->execute([$name]);
        respond(['success' => true]);
    }

    // POST /proxies/close-client —— 批量下线某客户端的全部隧道（扩展接口）
    if ($method === 'POST' && $id === 'close-client') {
        $b = body();
        $cn = trim($b['client'] ?? $b['name'] ?? '');
        if ($cn === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $n = offlineClientTunnels($db, $cn);
        respond(['success' => true, 'closed' => $n]);
    }

    if ($method === 'POST') {
        $b = body();
        $name = trim($b['name'] ?? '');
        if ($name === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $st = $db->prepare('SELECT id FROM proxies WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT'], 409);
        }

        $clientName = $b['clientName'] ?? $b['client_name'] ?? '';
        $port       = (int) ($b['port'] ?? 0);

        checkLen($name, 'proxy_name', 'name');
        checkLen($clientName, 'client_name', 'clientName');
        checkLen($b['customDomain'] ?? $b['custom_domain'] ?? '', 'custom_domain', 'customDomain');
        if ($port < 0 || $port > 65535) {
            respond(['success' => false, 'error' => 'INVALID_PORT', 'max' => 65535], 400);
        }

        // 客户端配额校验：隧道数上限
        if ($clientName !== '') {
            $cq = $db->prepare(
                'SELECT max_tunnels AS maxTunnels, max_traffic_bytes AS quota,
                        bytes_in AS bi, bytes_out AS bo
                 FROM clients WHERE name = ?'
            );
            $cq->execute([$clientName]);
            $c = $cq->fetch();
            if ($c) {
                $cnt = $db->prepare('SELECT COUNT(*) FROM proxies WHERE client_name = ?');
                $cnt->execute([$clientName]);
                if ((int) $cnt->fetchColumn() >= (int) $c['maxTunnels']) {
                    respond(['success' => false, 'error' => 'TUNNEL_QUOTA_EXCEEDED'], 403);
                }
                if ((int) $c['quota'] > 0 && ((int) $c['bi'] + (int) $c['bo']) >= (int) $c['quota']) {
                    respond(['success' => false, 'error' => 'QUOTA_EXCEEDED'], 403);
                }
            }
        }

        // 端口冲突校验（同类型下端口唯一）
        if ($port > 0) {
            $pc = $db->prepare('SELECT name FROM proxies WHERE port = ? AND type = ? LIMIT 1');
            $pc->execute([$port, $b['type'] ?? 'tcp']);
            if ($pc->fetch()) {
                respond(['success' => false, 'error' => 'PORT_CONFLICT'], 409);
            }
        }

        $db->prepare(
            'INSERT INTO proxies
               (name, type, port, local_addr, local_port, custom_domain, status, client_name, node_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        )->execute([
            $name,
            $b['type'] ?? 'tcp',
            $port,
            $b['localAddr'] ?? $b['local_addr'] ?? '127.0.0.1',
            (int) ($b['localPort'] ?? $b['local_port'] ?? 0),
            $b['customDomain'] ?? $b['custom_domain'] ?? '',
            $b['status'] ?? 'online',
            $clientName,
            $b['nodeName'] ?? $b['node_name'] ?? '',
        ]);

        // 同步客户端的隧道计数
        if ($clientName !== '') {
            $db->prepare(
                'UPDATE clients SET proxy_count = (SELECT COUNT(*) FROM proxies WHERE client_name = ?) WHERE name = ?'
            )->execute([$clientName, $clientName]);
        }

        respond(['success' => true, 'name' => $name]);
    }

    if ($method === 'GET') {
        $rows = $db->query(
            'SELECT name, type, port, local_addr AS localAddr, local_port AS localPort,
                    custom_domain AS customDomain, status,
                    client_name AS clientName, node_name AS nodeName, created_at AS createdAt
             FROM proxies ORDER BY id'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['port']      = (int) $r['port'];
            $r['localPort'] = (int) $r['localPort'];
        }
        unset($r);
        respond(['success' => true, 'proxies' => $rows]);
    }

    // DELETE /proxies/{name}
    if ($method === 'DELETE' && $id !== null) {
        $st = $db->prepare('SELECT client_name FROM proxies WHERE name = ?');
        $st->execute([$id]);
        $row = $st->fetch();

        $db->prepare('DELETE FROM proxies WHERE name = ?')->execute([$id]);

        if ($row && $row['client_name'] !== '') {
            $db->prepare(
                'UPDATE clients SET proxy_count = (SELECT COUNT(*) FROM proxies WHERE client_name = ?) WHERE name = ?'
            )->execute([$row['client_name'], $row['client_name']]);
        }
        respond(['success' => true]);
    }
}

/* ==================== 分发节点 ==================== */

if ($resource === 'nodes') {
    // 读所有人可用；增删改节点影响全局路由，锁定为管理员
    if ($method !== 'GET') {
        requireRole('admin');
    }
    if ($method === 'GET') {
        $rows = $db->query(
            'SELECT id, name, host, port, status, clients, tunnels,
                    max_tunnels AS maxTunnels, region, version
             FROM nodes ORDER BY id'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
            foreach (['port', 'clients', 'tunnels', 'maxTunnels'] as $k) {
                $r[$k] = (int) $r[$k];
            }
        }
        unset($r);
        respond(['success' => true, 'nodes' => $rows]);
    }

    if ($method === 'POST') {
        $b = body();
        $name = trim($b['name'] ?? '');
        if ($name === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $st = $db->prepare('SELECT id FROM nodes WHERE name = ?');
        $st->execute([$name]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT'], 409);
        }
        checkLen($name, 'node_name', 'name');
        checkLen($b['host'] ?? '', 'host', 'host');
        $db->prepare(
            'INSERT INTO nodes (name, host, port, region, max_tunnels, status) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $name,
            $b['host'] ?? '',
            (int) ($b['port'] ?? 7000),
            $b['region'] ?? '',
            (int) ($b['maxTunnels'] ?? $b['max_tunnels'] ?? 500),
            $b['status'] ?? 'online',
        ]);
        respond(['success' => true, 'id' => $db->lastInsertId()]);
    }

    // PUT /nodes/{id} —— 更新状态 / 指标
    if ($method === 'PUT' && $id !== null) {
        $b = body();
        $fields = [];
        $params = [];
        foreach ([
            'name' => 'name', 'host' => 'host', 'region' => 'region',
            'status' => 'status', 'port' => 'port',
            'clients' => 'clients', 'tunnels' => 'tunnels',
            'maxTunnels' => 'max_tunnels', 'max_tunnels' => 'max_tunnels',
            'version' => 'version',
        ] as $in => $col) {
            if (array_key_exists($in, $b)) {
                $fields[] = "`$col` = ?";
                $params[] = $b[$in];
            }
        }
        if ($fields) {
            $params[] = (int) $id;
            $db->prepare('UPDATE nodes SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        respond(['success' => true]);
    }

    if ($method === 'DELETE' && $id !== null) {
        $db->prepare('DELETE FROM nodes WHERE id = ?')->execute([(int) $id]);
        respond(['success' => true]);
    }
}

/* ==================== 用户 ==================== */

if ($resource === 'users') {
    // 用户管理为管理员专属：普通用户不得查看/新增/改/删任何账号
    requireRole('admin');

    if ($method === 'GET') {
        $rows = $db->query(
            'SELECT id, username, email, role, status, `group`,
                    max_tunnels AS maxTunnels, max_bandwidth AS maxBandwidth,
                    traffic_used AS trafficUsed, traffic_total AS trafficTotal,
                    real_name AS realName, created_at AS createdAt
             FROM users ORDER BY id'
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
            foreach (['maxTunnels', 'maxBandwidth', 'trafficUsed', 'trafficTotal'] as $k) {
                $r[$k] = (int) $r[$k];
            }
        }
        unset($r);
        respond(['success' => true, 'users' => $rows]);
    }

    if ($method === 'POST') {
        $b = body();
        $u = trim($b['username'] ?? '');
        if ($u === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $st = $db->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$u]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT'], 409);
        }
        checkLen($u, 'user_name', 'username');
        checkLen($b['email'] ?? '', 'email', 'email');
        $hash = password_hash($b['password'] ?? 'admin', PASSWORD_BCRYPT);
        $db->prepare(
            'INSERT INTO users
               (username, password, email, role, status, `group`, max_tunnels, max_bandwidth,
                traffic_total, real_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        )->execute([
            $u,
            $hash,
            $b['email'] ?? '',
            $b['role'] ?? 'user',
            $b['status'] ?? 'active',
            $b['group'] ?? '正式用户',
            (int) ($b['maxTunnels'] ?? 5),
            (int) ($b['maxBandwidth'] ?? 16),
            (int) ($b['trafficTotal'] ?? 0),
            $b['realName'] ?? '-',
        ]);
        respond(['success' => true, 'id' => $db->lastInsertId()]);
    }

    if ($method === 'PUT' && $id !== null) {
        $b = body();
        $db->prepare(
            'UPDATE users SET username = ?, email = ?, role = ?, `group` = ?,
                    max_tunnels = ?, max_bandwidth = ?, status = ?, real_name = ?
             WHERE id = ?'
        )->execute([
            $b['username'] ?? '',
            $b['email'] ?? '',
            $b['role'] ?? 'user',
            $b['group'] ?? '',
            (int) ($b['maxTunnels'] ?? 5),
            (int) ($b['maxBandwidth'] ?? 16),
            $b['status'] ?? 'active',
            $b['realName'] ?? '-',
            (int) $id,
        ]);
        respond(['success' => true]);
    }

    if ($method === 'DELETE' && $id !== null) {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $id]);
        respond(['success' => true]);
    }
}

/* ==================== API 密钥 ==================== */

if ($resource === 'api-keys') {
    // API 密钥等同于长期凭据，仅管理员可管理
    requireRole('admin');

    if ($method === 'GET') {
        $rows = $db->query(
            'SELECT `key`, note, scopes, created_at AS createdAt, last_used AS lastUsed FROM api_keys ORDER BY id DESC'
        )->fetchAll();
        foreach ($rows as &$r) {
            $sc = json_decode((string) $r['scopes'], true);
            $r['scopes'] = is_array($sc) ? $sc : ['admin'];
        }
        unset($r);
        respond(['success' => true, 'keys' => $rows]);
    }

    if ($method === 'POST') {
        $b = body();
        $key = trim($b['key'] ?? '');
        if ($key === '') {
            $key = 'lt_' . bin2hex(random_bytes(14));
        }
        checkLen($key, 'api_key', 'key');
        $st = $db->prepare('SELECT id FROM api_keys WHERE `key` = ?');
        $st->execute([$key]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT'], 409);
        }
        // scopes 控制该密钥权限；缺省为全权 admin
        $scopes = $b['scopes'] ?? ['admin'];
        if (!is_array($scopes) || !$scopes) {
            $scopes = ['admin'];
        }
        $scopes = array_values(array_filter(array_map('strval', $scopes)));
        $db->prepare('INSERT INTO api_keys (`key`, note, scopes, created_at) VALUES (?, ?, ?, CURDATE())')
           ->execute([$key, $b['note'] ?? '', json_encode($scopes, JSON_UNESCAPED_UNICODE)]);
        respond(['success' => true, 'key' => $key, 'scopes' => $scopes]);
    }

    if ($method === 'DELETE' && $id !== null) {
        $db->prepare('DELETE FROM api_keys WHERE `key` = ?')->execute([$id]);
        respond(['success' => true]);
    }
}

/* ==================== 在线更新（仅管理员） ==================== */

if ($resource === 'update') {
    requireRole('admin');

    // GET /update/status —— 查看当前版本、更新源、历史与本地包
    if ($method === 'GET' && $id === 'status') {
        $cfg   = cfg();
        $state = readUpdateState();
        respond([
            'success' => true,
            'current' => [
                'version'     => currentBuildVersion(),
                'appliedAt'   => $state['appliedAt'] ?? '',
                'appliedVersion' => $state['appliedVersion'] ?? '',
            ],
            'source' => [
                'manifestUrl' => (string) ($cfg['update']['manifest_url'] ?? ''),
                'configured'  => !empty($cfg['update']['manifest_url']),
            ],
            'history'  => $state['history'] ?? [],
            'packages' => listLocalPackages(),
            'env' => [
                'zipArchive' => class_exists('ZipArchive'),
                'curl'       => function_exists('curl_init'),
                // 【修复】扁平结构下 api.php 就在站点根目录，storage/ 与它同级。
                // 原代码用 dirname(__DIR__) 会指向站点根目录的上一级（如 /www/wwwroot/），
                // 那里没有 storage/，导致此项恒为 false —— 权限即使正常也显示「不可写」。
                'storageWritable' => (function () {
                    $root = __DIR__;
                    $s = $root . '/storage';
                    ensureStorageDirs();
                    return is_dir($s) && is_writable($s);
                })(),
                'storage'    => diagnoseStorage(),
                'opcache'    => function_exists('opcache_reset'),
            ],
        ]);
    }

    // GET /update/check —— 拉取远程清单，比对版本
    if ($method === 'GET' && $id === 'check') {
        $cfg = cfg();
        $url = (string) ($cfg['update']['manifest_url'] ?? '');
        if ($url === '') {
            respond([
                'success' => false,
                'error'   => 'UPDATE_SOURCE_NOT_CONFIGURED',
                'message' => '未配置更新源，请在 api/config.php 设置 update.manifest_url，'
                           . '或把新版 zip 放到 storage/packages/ 后选择本地包更新。',
            ], 400);
        }
        $r = fetchManifest($url);
        if (!$r['ok']) {
            respond(['success' => false, 'error' => $r['error']], 502);
        }
        $m       = $r['manifest'];
        $current = currentBuildVersion();
        // 【修复】远端版本优先取 appVersion，回退 version。
        // 历史上 build-meta.json 的 version 被构建脚本写成 git 短哈希，
        // 与本地同一个哈希比对时必然「相等」，导致明明有新版本却报「已是最新」。
        $remote  = (string) ($m['appVersion'] ?? '');
        if ($remote === '') {
            $remote = (string) ($m['version'] ?? '');
        }
        respond([
            'success'        => true,
            'currentVersion' => $current,
            'remoteVersion'  => $remote,
            // 【修复】改为按语义版本比较大小，而不是简单判断「不相等」。
            // 只判断不等会把降级也报成「有新版本」（如本地 1.0.4、远端 1.0.3），
            // 用户误点会把系统退回旧版，重新引入已修复的 BUG。
            'hasUpdate'      => $remote !== '' && versionCompare($remote, $current) > 0,
            'isDowngrade'    => $remote !== '' && $current !== '' && versionCompare($remote, $current) < 0,
            'publishedAt'    => (string) ($m['publishedAt'] ?? ''),
            'notes'          => (string) ($m['notes'] ?? ''),
            'fileCount'      => count($m['files']),
        ]);
    }

    // POST /update/apply —— 执行远程更新（body: {dryRun?: bool}）
    if ($method === 'POST' && $id === 'apply') {
        $b   = body();
        $cfg = cfg();
        $url = (string) ($cfg['update']['manifest_url'] ?? '');
        if ($url === '') {
            respond(['success' => false, 'error' => 'UPDATE_SOURCE_NOT_CONFIGURED'], 400);
        }
        $r = fetchManifest($url);
        if (!$r['ok']) {
            respond(['success' => false, 'error' => $r['error']], 502);
        }
        $res = applyRemoteUpdate($r['manifest'], $url, (bool) ($b['dryRun'] ?? false));
        respond($res, $res['ok'] ? 200 : 500);
    }

    // POST /update/apply-local —— 应用本地包（body: {name, dryRun?}）
    if ($method === 'POST' && $id === 'apply-local') {
        $b    = body();
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'error' => 'PACKAGE_NAME_REQUIRED'], 400);
        }
        $res = applyLocalPackage($name, (bool) ($b['dryRun'] ?? false));
        respond($res, $res['ok'] ? 200 : 500);
    }
}

/* ==================== 客户端下载 ==================== */

if ($resource === 'downloads' && $method === 'GET') {
    $rows = $db->query(
        'SELECT id, name, version, platform, arch, size, url, updated_at AS updatedAt
         FROM downloads ORDER BY platform, arch'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (string) $r['id'];
    }
    unset($r);
    respond(['success' => true, 'downloads' => $rows]);
}

/* ==================== 系统设置 ==================== */

if ($resource === 'settings') {
    // 读设置所有人可用；改设置锁定为管理员
    if ($method !== 'GET') {
        requireRole('admin');
    }
    if ($method === 'GET') {
        respond(['success' => true, 'settings' => [
            'title'            => '蓝天内网穿透',
            'webAddr'          => '0.0.0.0',
            'webPort'          => 8080,
            'sessionTimeout'   => 60,
            'allowRegister'    => true,
            'requireRealName'  => false,
            'defaultBandwidth' => 16,
            'defaultTunnels'   => 5,
        ]]);
    }
    if ($method === 'PUT') {
        respond(['success' => true]);
    }
}

respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
