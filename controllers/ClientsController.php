<?php
/**
 * 客户端凭据控制器
 * ===========================================================================
 * 对应资源：/clients/*（NexusLink 官方客户端契约 + 本项目扩展的用量上报）
 *
 * 【一个贯穿本文件的关键点：$name 不是 id】
 * 客户端的主键是自增 id，但所有对外接口一律用 name 定位 —— 因为 name 才是
 * 客户端配置文件里写的那个标识。所以路由里的 {name} 参数直接当 name 用，
 * 绝不能拿去和 id 比较。这一点在迁移时最容易写错。
 * ===========================================================================
 */

final class ClientsController
{
    /**
     * POST /clients/{name}/report —— 流量上报
     *
     * 供隧道节点周期性回传该客户端的实际用量，超配额自动下线全部隧道。
     * 这是本项目扩展接口，官方契约里没有。
     */
    public static function report(string $name)
    {
        $db = ltDb();
        $b  = Req::body();

        $st = $db->prepare('SELECT max_traffic_bytes AS quota FROM clients WHERE name = ?');
        $st->execute([$name]);
        $c = $st->fetch();
        if (!$c) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $in  = (int) ($b['bytesIn'] ?? $b['bytes_in'] ?? 0);
        $out = (int) ($b['bytesOut'] ?? $b['bytes_out'] ?? 0);

        // mode=delta 累加（默认），mode=absolute 覆盖。
        // 默认选累加是因为节点重启后计数会归零，覆盖模式会让用量「凭空回退」。
        $mode = $b['mode'] ?? 'delta';
        if ($mode === 'absolute') {
            $db->prepare('UPDATE clients SET bytes_in = ?, bytes_out = ? WHERE name = ?')
               ->execute([$in, $out, $name]);
        } else {
            $db->prepare('UPDATE clients SET bytes_in = bytes_in + ?, bytes_out = bytes_out + ? WHERE name = ?')
               ->execute([$in, $out, $name]);
        }

        $now = $db->prepare('SELECT bytes_in AS bi, bytes_out AS bo FROM clients WHERE name = ?');
        $now->execute([$name]);
        $n     = $now->fetch();
        $total = (int) $n['bi'] + (int) $n['bo'];

        // 超配额 → 自动下线该客户端全部隧道
        $offlined = 0;
        $quota    = (int) $c['quota'];
        if ($quota > 0 && $total >= $quota) {
            $offlined = offlineClientTunnels($db, $name);
        }

        respond([
            'success'   => true,
            'total'     => $total,
            'quota'     => $quota,
            'overQuota' => $quota > 0 && $total >= $quota,
            'offlined'  => $offlined,
        ]);
    }

    /** GET /clients/{name}/traffic —— 单客户端流量明细 */
    public static function traffic(string $name)
    {
        $db = ltDb();
        $st = $db->prepare(
            'SELECT bytes_in AS bytesIn, bytes_out AS bytesOut,
                    max_traffic_bytes AS maxTrafficBytes,
                    proxy_count AS proxyCount, max_tunnels AS maxTunnels,
                    IF(connected = 1, TRUE, FALSE) AS connected
             FROM clients WHERE name = ?'
        );
        $st->execute([$name]);
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

    /** GET /clients —— 客户端列表 */
    public static function index()
    {
        $db = ltDb();
        // 注意：按 NexusLink 官方契约，列表不返回 token，避免多租户凭据泄露。
        $rows = $db->query(
            'SELECT name, bytes_in AS bytesIn, bytes_out AS bytesOut,
                    IF(connected = 1, TRUE, FALSE) AS connected,
                    proxy_count AS proxyCount, max_tunnels AS maxTunnels,
                    max_traffic_bytes AS maxTrafficBytes
             FROM clients ORDER BY id'
        )->fetchAll();

        $rows = array_map([self::class, 'normalizeClient'], $rows);
        respond(['success' => true, 'clients' => $rows]);
    }

    /** POST /clients —— 新建客户端 */
    public static function create()
    {
        $db = ltDb();
        $b  = Req::body();

        // 官方契约：缺 name / token → 400
        $name  = trim($b['name'] ?? '');
        $token = trim($b['token'] ?? '');
        if ($name === '' || $token === '') {
            respond(['success' => false, 'error' => 'NAME_AND_TOKEN_REQUIRED'], 400);
        }
        // 长度校验：超限返回可读的 400，而非让 MySQL 抛错变 500
        checkLen($name, 'client_name', 'name');
        checkLen($token, 'client_token', 'token');

        // 官方契约：名称或 token 任一冲突 → 409（并区分是哪个冲突）
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

    /**
     * PUT /clients/{name} —— 轮换 token / 调整配额
     * 本项目扩展：官方无此接口。
     */
    public static function update(string $name)
    {
        $db = ltDb();
        $b  = Req::body();

        $st = $db->prepare('SELECT id FROM clients WHERE name = ?');
        $st->execute([$name]);
        if (!$st->fetch()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // 轮换 token 时同样要检查冲突
        $newToken = trim($b['token'] ?? '');
        if ($newToken !== '') {
            checkLen($newToken, 'client_token', 'token');
            $ck = $db->prepare('SELECT name FROM clients WHERE token = ? AND name <> ? LIMIT 1');
            $ck->execute([$newToken, $name]);
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
        $params[] = $name;
        $db->prepare('UPDATE clients SET ' . implode(', ', $fields) . ' WHERE name = ?')->execute($params);

        // token 轮换后旧会话失效：踢下线
        if ($newToken !== '') {
            $db->prepare('UPDATE clients SET connected = 0 WHERE name = ?')->execute([$name]);
            $db->prepare("UPDATE proxies SET status = 'offline' WHERE client_name = ?")->execute([$name]);
        }

        respond(['success' => true]);
    }

    /** DELETE /clients/{name} —— 删除并踢下线 */
    public static function destroy(string $name)
    {
        $db = ltDb();
        $st = $db->prepare('SELECT id FROM clients WHERE name = ?');
        $st->execute([$name]);
        if (!$st->fetch()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $db->prepare('DELETE FROM clients WHERE name = ?')->execute([$name]);
        $db->prepare('DELETE FROM proxies WHERE client_name = ?')->execute([$name]);
        respond(['success' => true]);
    }

    /**
     * 把一行 clients 记录归一化成 API 输出格式。
     *
     * 【为什么单独抽出来】
     * 列表和明细两处原本各写一遍同样的 8 行类型转换，改配额字段时
     * 改一处漏一处，前端就会看到 "1073741824" 和 1073741824 两种形态。
     */
    public static function normalizeClient(array $r): array
    {
        $r['connected']       = (bool) ($r['connected'] ?? false);
        $r['bytesIn']         = (int) ($r['bytesIn'] ?? 0);
        $r['bytesOut']        = (int) ($r['bytesOut'] ?? 0);
        $r['proxyCount']      = (int) ($r['proxyCount'] ?? 0);
        $r['maxTunnels']      = (int) ($r['maxTunnels'] ?? 0);
        $r['maxTrafficBytes'] = (int) ($r['maxTrafficBytes'] ?? 0);
        $total                = $r['bytesIn'] + $r['bytesOut'];
        $r['total']           = $total;
        $r['usedPercent']     = $r['maxTrafficBytes'] > 0
            ? round($total / $r['maxTrafficBytes'] * 100, 2)
            : 0;
        $r['overQuota']       = $r['maxTrafficBytes'] > 0 && $total >= $r['maxTrafficBytes'];
        return $r;
    }
}
