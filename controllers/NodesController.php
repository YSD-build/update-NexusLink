<?php
/**
 * 蓝天内网穿透 · 节点控制器
 * ===========================================================================
 * 覆盖：节点 CRUD、心跳接收、连通性探测、token 管理
 *
 * 【本控制器承载了 v1.1.5「心跳无法使用」的两个修复点】
 *   1. index(withToken=1) —— 让运维能正规取回 node_token
 *   2. token()            —— 独立的查看/轮换端点
 *   3. index() 返回心跳诊断字段（lastSeenAgo / heartbeatStale）
 *
 * 心跳接口（heartbeat）不挂鉴权中间件 —— 它的凭据是 X-Node-Token，
 * 由方法内部自行校验。这一点在 routes.php 里能一眼确认。
 * ===========================================================================
 */

final class NodesController
{
    /* ==================== 节点心跳 ==================== */

    /**
     * POST /v1/nodes/heartbeat
     *
     * 【为什么不需要登录】
     * 心跳的发起方是「节点上的服务端进程」，它没有、也不该有控制台账号。
     * 它唯一的凭据是创建节点时下发的那串 node_token（X-Node-Token 头）。
     * 如果这个接口也要求管理员登录，节点就永远无法上报状态。
     *
     * 【容错设计】
     * token 读取支持三种来源（请求头优先，其次 body 的 token/nodeToken），
     * 是为了照顾不同节点实现的顺手写法 —— 逼用户改节点端代码才能对接
     * 是很差的产品体验。
     */
    public static function heartbeat(): void
    {
        $db = pdo();
        $b  = Req::body();

        $nt = trim((string) (
            $_SERVER['HTTP_X_NODE_TOKEN']
            ?? $b['token']
            ?? $b['nodeToken']
            ?? ''
        ));

        if ($nt === '') {
            respond([
                'success' => false,
                'error'   => 'NODE_TOKEN_REQUIRED',
                'message' => '缺少 X-Node-Token 请求头。请到「节点管理」查看该节点的 Token。',
            ], 401);
        }

        if (!hasColumn($db, 'nodes', 'node_token')) {
            respond([
                'success' => false,
                'error'   => 'SCHEMA_NOT_READY',
                'message' => '数据库尚未完成迁移，缺少 nodes.node_token',
            ], 500);
        }

        $st = $db->prepare('SELECT id, name FROM nodes WHERE node_token = ? LIMIT 1');
        $st->execute([$nt]);
        $node = $st->fetch();

        if (!$node) {
            respond([
                'success' => false,
                'error'   => 'INVALID_NODE_TOKEN',
                'message' => 'Node Token 无效或已轮换。请到「节点管理」重新获取。',
            ], 401);
        }

        $num = function ($v, $min = 0, $max = 100000) {
            return clamp($v, $min, $max);
        };

        $cpu = $num($b['cpu'] ?? 0, 0, 100);
        $mem = $num($b['mem'] ?? $b['memory'] ?? 0, 0, 100);
        $connCount = (int) $num($b['connCount'] ?? $b['conn_count'] ?? 0, 0, 100000000);

        // 隧道数与客户端数由节点自行上报（它才真正知道自己承载了多少）
        $sets = [
            'last_seen = NOW()',
            'status = ?',
            'cpu = ?',
            'mem = ?',
            'conn_count = ?',
        ];
        $params = ['online', $cpu, $mem, $connCount];

        if (array_key_exists('tunnelCount', $b) || array_key_exists('tunnels', $b)) {
            $sets[] = 'tunnels = ?';
            $params[] = (int) $num($b['tunnelCount'] ?? $b['tunnels'] ?? 0, 0, 1000000);
        }
        if (array_key_exists('clientCount', $b) || array_key_exists('clients', $b)) {
            $sets[] = 'clients = ?';
            $params[] = (int) $num($b['clientCount'] ?? $b['clients'] ?? 0, 0, 1000000);
        }

        $ver = trim((string) ($b['version'] ?? ''));
        if ($ver !== '') {
            $sets[] = 'version = ?';
            $params[] = mb_substr($ver, 0, 32);
        }

        // 节点可以顺便声明/修正自己的能力
        if (array_key_exists('protocols', $b)) {
            $sets[] = 'protocols = ?';
            $params[] = encodeProtocols(normalizeProtocols($b['protocols']));
        }

        // 节点自行上报的地址（多网卡环境由节点选择对外可达的地址）
        $host = trim((string) ($b['host'] ?? ''));
        if ($host !== '' && mb_strlen($host) <= 64) {
            $sets[] = 'host = ?';
            $params[] = $host;
        }

        $params[] = (int) $node['id'];
        $db->prepare('UPDATE nodes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        // 顺便跑一次离线清扫，让「谁掉线了」在心跳周期内就能反映出来
        sweepOfflineNodes($db);

        $offlineSec = (int) settingsGet($db, 'nodeOfflineSeconds', 90);

        respond([
            'success'    => true,
            'node'       => $node['name'],
            'serverTime' => date('c'),
            // 建议上报间隔 = 离线判定时长的 1/3，保证连续丢两次心跳也还来得及补救
            'interval'   => max(10, (int) ($offlineSec / 3)),
            'offlineAt'  => $offlineSec,
        ]);
    }

    /* ==================== 列表 ==================== */

    /**
     * GET /v1/nodes[?withToken=1]
     *
     * withToken=1 需要管理员权限，且会额外返回 nodeToken 与
     * tokenConfigured 字段。
     */
    public static function index(): void
    {
        $db = pdo();

        // 【v1.1.5】允许显式索取 nodeToken。
        //
        // 背景：node_token 只在「创建节点」那一次的响应里出现过，之后
        // 列表接口永不回显。这个默认策略是对的（凭据不该到处飘），
        // 但它有个致命副作用 —— 用户创建完节点后去配置节点端心跳时，
        // 如果当时没把 token 抄下来，就再也拿不到，表现就是
        // 「心跳怎么都调不通」，只能删了重建。
        //
        // 折中：默认仍不回显；只有管理员**显式**带 withToken=1 时才返回。
        $wantToken = Req::queryBool('withToken');
        if ($wantToken) {
            requireRole('admin');
        }

        $rows = self::fetchNodes($db, $wantToken);
        $timeout = (int) settingsGet($db, 'nodeOfflineSeconds', 90);

        foreach ($rows as &$r) {
            self::normalizeNode($r, $timeout, $wantToken);
        }
        unset($r);

        respond([
            'success' => true,
            'nodes'   => $rows,
            // 顶层也带一份，方便前端显示「超过多久算离线」
            'heartbeatTimeout' => $timeout,
        ]);
    }

    /** GET /v1/nodes/{id} */
    public static function show(string $id): void
    {
        $db = pdo();
        $rows = self::fetchNodes($db, false, (int) $id);
        if (!$rows) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $timeout = (int) settingsGet($db, 'nodeOfflineSeconds', 90);
        $node = $rows[0];
        self::normalizeNode($node, $timeout, false);
        respond(['success' => true, 'node' => $node]);
    }

    /**
     * 查询节点。按列存在性拼 SQL —— 老库缺 protocols/last_seen 等列时
     * 也能正常返回，而不是整表查询 500。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchNodes(PDO $db, bool $wantToken, ?int $onlyId = null): array
    {
        $hasNew = hasColumn($db, 'nodes', 'protocols');

        $cols = 'id, name, host, port, status, clients, tunnels,
                 max_tunnels AS maxTunnels, region, version';
        $cols .= $hasNew
            ? ', protocols, last_seen AS lastSeen, cpu, mem, conn_count AS connCount, probe_ok AS probeOk, probe_at AS probeAt'
            : ", '[\"tcp\",\"udp\",\"http\",\"https\"]' AS protocols, NULL AS lastSeen,
               0 AS cpu, 0 AS mem, 0 AS connCount, 0 AS probeOk, NULL AS probeAt";

        if ($wantToken) {
            $cols .= hasColumn($db, 'nodes', 'node_token')
                ? ', node_token AS nodeToken'
                : ", '' AS nodeToken";
        }

        if ($onlyId !== null) {
            $st = $db->prepare("SELECT {$cols} FROM nodes WHERE id = ? LIMIT 1");
            $st->execute([$onlyId]);
            return $st->fetchAll();
        }

        return $db->query("SELECT {$cols} FROM nodes ORDER BY id")->fetchAll();
    }

    /**
     * 规范化单条节点记录：类型转换 + 心跳诊断字段。
     */
    private static function normalizeNode(array &$r, int $timeout, bool $wantToken): void
    {
        $r['id'] = (string) $r['id'];
        foreach (['port', 'clients', 'tunnels', 'maxTunnels', 'connCount'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['cpu'] = (float) $r['cpu'];
        $r['mem'] = (float) $r['mem'];
        $r['protocols'] = decodeProtocols($r['protocols'] ?? null);
        $r['probeOk'] = (bool) $r['probeOk'];

        if ($wantToken) {
            $r['nodeToken'] = (string) ($r['nodeToken'] ?? '');
            // 明确告知这个 token 是否真的可用，前端据此显示警示
            $r['tokenConfigured'] = $r['nodeToken'] !== '';
        } else {
            // node_token 永不在默认响应里出现
            unset($r['nodeToken']);
        }

        // 【v1.1.5】心跳诊断字段。
        //
        // 「心跳无法使用」过去极难排查：界面只显示在线/离线，而用户
        // 根本不知道为什么 —— 是节点没发？token 不对？还是发了但被判超时？
        // 这几个字段把判断依据直接摆出来。
        $lastSeen = $r['lastSeen'] ?? null;
        if ($lastSeen) {
            $ago = time() - (int) strtotime((string) $lastSeen);
            $r['lastSeenAgo'] = $ago;
            $r['heartbeatStale'] = $ago > $timeout;
        } else {
            $r['lastSeenAgo'] = null;   // 从未心跳过
            $r['heartbeatStale'] = true;
        }
        $r['heartbeatTimeout'] = $timeout;
    }

    /* ==================== 创建 / 更新 / 删除 ==================== */

    /** POST /v1/nodes */
    public static function create(): void
    {
        $db = pdo();
        $b  = body();

        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED', 'message' => '请填写节点名称'], 400);
        }
        checkLen($name, 'node_name', '节点名称');
        checkLen($b['host'] ?? '', 'host', 'host');

        // 协议声明：缺省视为「四协议全支持」，显式传了就必须合法
        if (array_key_exists('protocols', $b)) {
            $proto = normalizeProtocols($b['protocols']);
            if (!$proto) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_PROTOCOLS',
                    'message' => '至少要选择一个协议',
                    'allowed' => KNOWN_PROTOCOLS,
                ], 400);
            }
        } else {
            $proto = KNOWN_PROTOCOLS;
        }

        $hasNew = hasColumn($db, 'nodes', 'protocols');
        $token  = $hasNew ? newNodeToken() : '';

        try {
            if ($hasNew) {
                $db->prepare(
                    'INSERT INTO nodes (name, host, port, region, max_tunnels, status, protocols, node_token)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $name,
                    $b['host'] ?? '',
                    (int) ($b['port'] ?? 7000),
                    $b['region'] ?? '',
                    (int) ($b['maxTunnels'] ?? $b['max_tunnels'] ?? 500),
                    // 新建节点默认离线：它还一次心跳都没发过，标成在线是撒谎
                    $b['status'] ?? 'offline',
                    encodeProtocols($proto),
                    $token,
                ]);
            } else {
                $db->prepare(
                    'INSERT INTO nodes (name, host, port, region, max_tunnels, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $name,
                    $b['host'] ?? '',
                    (int) ($b['port'] ?? 7000),
                    $b['region'] ?? '',
                    (int) ($b['maxTunnels'] ?? $b['max_tunnels'] ?? 500),
                    $b['status'] ?? 'offline',
                ]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                respond([
                    'success' => false,
                    'error'   => 'NAME_CONFLICT',
                    'message' => '同名节点已存在',
                ], 409);
            }
            throw $e;
        }

        respond([
            'success'   => true,
            'id'        => $db->lastInsertId(),
            'protocols' => $proto,
            // 明文 node_token 只在这一刻出现
            'nodeToken' => $token,
            'notice'    => $token !== ''
                ? '请立即保存该 nodeToken。若忘记录入，可随时在「节点管理」里查看或轮换。'
                : '当前数据库缺少 protocols/node_token 字段，请检查自动迁移是否成功',
        ]);
    }

    /** PUT /v1/nodes/{id} */
    public static function update(string $id): void
    {
        $db = pdo();
        $b  = body();
        $id = (int) $id;

        $st = $db->prepare('SELECT id, name FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $node = $st->fetch();
        if (!$node) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // ---- 协议变更前的在途隧道冲突校验 ----
        // 把一个承载着 http 隧道的节点改成「只支持 tcp」，会让那条隧道凭空失去承接方。
        // 这里直接拒绝，并把冲突隧道列出来，让运营先处理掉再改。
        if (array_key_exists('protocols', $b) && hasColumn($db, 'nodes', 'protocols')) {
            $newProto = normalizeProtocols($b['protocols']);
            if (!$newProto) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_PROTOCOLS',
                    'message' => '至少要选择一个协议',
                    'allowed' => KNOWN_PROTOCOLS,
                ], 400);
            }

            $ph = implode(',', array_fill(0, count($newProto), '?'));
            $cq = $db->prepare(
                "SELECT name, type FROM proxies
                  WHERE node_name = ? AND type NOT IN ({$ph})
                  ORDER BY name LIMIT 50"
            );
            $cq->execute(array_merge([$node['name']], $newProto));
            $conflicts = $cq->fetchAll();

            if ($conflicts) {
                respond([
                    'success'   => false,
                    'error'     => 'PROTOCOL_IN_USE',
                    'message'   => sprintf(
                        '该节点上有 %d 条隧道使用了将被移除的协议，请先删除或迁移这些隧道',
                        count($conflicts)
                    ),
                    'conflicts' => $conflicts,
                ], 409);
            }
        }

        $sets = [];
        $params = [];

        foreach ([
            'name'   => 'node_name',
            'host'   => 'host',
            'region' => null,
        ] as $field => $lenKey) {
            if (array_key_exists($field, $b)) {
                $v = trim((string) $b[$field]);
                if ($lenKey !== null) {
                    checkLen($v, $lenKey, $field);
                }
                $sets[] = "`{$field}` = ?";
                $params[] = $v;
            }
        }

        foreach (['port', 'maxTunnels', 'max_tunnels'] as $field) {
            if (array_key_exists($field, $b)) {
                $col = $field === 'maxTunnels' ? 'max_tunnels' : $field;
                $sets[] = "`{$col}` = ?";
                $params[] = (int) $b[$field];
            }
        }

        if (array_key_exists('status', $b)) {
            $st2 = in_array($b['status'], ['online', 'offline', 'busy'], true) ? $b['status'] : 'offline';
            $sets[] = 'status = ?';
            $params[] = $st2;
        }

        if (array_key_exists('protocols', $b) && hasColumn($db, 'nodes', 'protocols')) {
            $sets[] = 'protocols = ?';
            $params[] = encodeProtocols(normalizeProtocols($b['protocols']));
        }

        // 轮换 token（保留在 PUT 里以兼容旧前端；新前端建议用 POST /nodes/{id}/token）
        $rotate = !empty($b['rotateToken']) && hasColumn($db, 'nodes', 'node_token');
        if ($rotate) {
            $sets[] = 'node_token = ?';
            $params[] = newNodeToken();
        }

        if ($sets) {
            $params[] = $id;
            try {
                $db->prepare('UPDATE nodes SET ' . implode(', ', $sets) . ' WHERE id = ?')
                   ->execute($params);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    respond([
                        'success' => false,
                        'error'   => 'NAME_CONFLICT',
                        'message' => '同名节点已存在',
                    ], 409);
                }
                throw $e;
            }
        }

        $resp = ['success' => true];

        if ($b['rotateToken'] ?? false) {
            if (!$rotate) {
                respond([
                    'success' => false,
                    'error'   => 'SCHEMA_NOT_READY',
                    'message' => '数据库缺少 node_token 字段，无法轮换',
                ], 500);
            }
            $nt = $db->prepare('SELECT node_token FROM nodes WHERE id = ?');
            $nt->execute([$id]);
            $resp['nodeToken'] = (string) $nt->fetchColumn();
            $resp['notice'] = 'nodeToken 已轮换，旧凭据立即失效，请更新节点配置';
        }

        respond($resp);
    }

    /** DELETE /v1/nodes/{id} */
    public static function destroy(string $id): void
    {
        $db = pdo();
        $id = (int) $id;

        $st = $db->prepare('SELECT id, name FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $node = $st->fetch();
        if (!$node) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // 有隧道挂在这个节点上时不允许删 —— 否则那些隧道会变成孤儿记录
        $cq = $db->prepare('SELECT COUNT(*) FROM proxies WHERE node_name = ?');
        $cq->execute([$node['name']]);
        if ((int) $cq->fetchColumn() > 0) {
            respond([
                'success' => false,
                'error'   => 'NODE_IN_USE',
                'message' => '该节点上还有隧道，请先删除这些隧道',
            ], 409);
        }

        $db->prepare('DELETE FROM nodes WHERE id = ?')->execute([$id]);
        respond(['success' => true]);
    }

    /* ==================== Token 管理（v1.1.5 新增） ==================== */

    /**
     * POST /v1/nodes/{id}/token
     * body: { rotate: true }  → 轮换；否则查看当前 token
     *
     * 【为什么单独开这个端点】
     * 列表接口默认不返回 token，而「轮换」原先只挂在 PUT /nodes/{id} 的
     * rotateToken 参数里 —— 前端要轮换得先拼一个完整的 PUT 请求体，
     * 很容易误改其它字段。独立端点语义清晰。
     */
    public static function token(string $id): void
    {
        $db = pdo();
        $id = (int) $id;

        if (!hasColumn($db, 'nodes', 'node_token')) {
            respond([
                'success' => false,
                'error'   => 'SCHEMA_NOT_READY',
                'message' => '数据库缺少 nodes.node_token 字段，请检查自动迁移',
            ], 500);
        }

        $st = $db->prepare('SELECT id, name FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $node = $st->fetch();
        if (!$node) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $b = Req::body();
        $do = $b['rotate'] ?? Req::query('rotate');
        $rotate = ($do === true || $do === '1' || $do === 1);

        if ($rotate) {
            $nt = newNodeToken();
            $db->prepare('UPDATE nodes SET node_token = ? WHERE id = ?')
               ->execute([$nt, $id]);

            if (function_exists('ltLog')) {
                ltLog('node', [
                    'event' => 'token_rotated',
                    'node'  => $node['name'],
                    'by'    => Auth::username(),
                ]);
            }

            respond([
                'success'   => true,
                'node'      => $node['name'],
                'nodeToken' => $nt,
                'rotated'   => true,
                'notice'    => '已生成新 token，旧凭据立即失效。请立刻更新节点端配置。',
            ]);
        }

        $q = $db->prepare('SELECT node_token FROM nodes WHERE id = ?');
        $q->execute([$id]);
        $cur = (string) $q->fetchColumn();

        respond([
            'success'    => true,
            'node'       => $node['name'],
            'nodeToken'  => $cur,
            'rotated'    => false,
            'configured' => $cur !== '',
            'notice'     => $cur === ''
                ? '该节点尚未分配 token，请用 rotate=1 生成一个'
                : '这是当前生效的 token。如怀疑泄露，请轮换。',
        ]);
    }

    /* ==================== 主动探测 ==================== */

    /**
     * POST /v1/nodes/{id}/probe
     *
     * 【为什么这段必须放在「新建节点」之前】
     * 原 api.php 有个不分 id 的 POST 分支负责建节点。PHP 从上往下执行、
     * 命中即 return，如果把探测放在它后面，POST /nodes/3/probe 会先被
     * 建节点分支吃掉，那里读不到 name 就返回 NAME_REQUIRED ——
     * 看起来像「参数校验失败」，实际是路由被截胡。
     *
     * 现在由 routes.php 的注册顺序保证（具体路径排在通配之前），
     * 不再依赖代码位置，这条注释仅作历史留档。
     */
    public static function probe(string $id): void
    {
        $db = pdo();
        $id = (int) $id;

        $st = $db->prepare('SELECT id, name, host, port FROM nodes WHERE id = ?');
        $st->execute([$id]);
        $node = $st->fetch();
        if (!$node) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $port = (int) $node['port'];
        if ($port < 1 || $port > 65535) {
            respond([
                'success' => false,
                'error'   => 'INVALID_PORT',
                'message' => '该节点没有配置合法的服务端口，无法探测',
            ], 400);
        }

        // SSRF 前置校验：解析域名成真实 IP 之后再判断保留段
        $tgt = resolveProbeTarget((string) $node['host']);
        $ok  = false;
        $ms  = 0;
        $why = $tgt['reason'] ?? '';

        if ($tgt['ok']) {
            // 整体别让 PHP 卡住：2 秒连接 + 少量收尾，给 5 秒上限足够
            @set_time_limit(5);
            $r   = probeTcp($tgt['ip'], $port, 2.0);
            $ok  = $r['ok'];
            $ms  = $r['latencyMs'];
            $why = $r['reason'] ?? '';
        }

        // 回写探测结果，让列表页能显示「最近一次探测」而不用重新探测
        if (hasColumn($db, 'nodes', 'probe_ok') && hasColumn($db, 'nodes', 'probe_at')) {
            try {
                $db->prepare('UPDATE nodes SET probe_ok = ?, probe_at = NOW() WHERE id = ?')
                   ->execute([$ok ? 1 : 0, $id]);
            } catch (Throwable $e) {
            }
        }

        respond([
            'success'   => true,
            'ok'        => $ok,
            'latencyMs' => $ms,
            'target'    => $tgt['ip'] ?? '',
            'host'      => $node['host'],
            'port'      => $port,
            'reason'    => $why,
            'probedAt'  => date('c'),
        ]);
    }
}
