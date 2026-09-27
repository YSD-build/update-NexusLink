<?php
/**
 * 隧道 / 代理控制器
 * ===========================================================================
 * 对应资源：/proxies/*
 *
 * 【NexusLink 契约边界】
 * 官方只提供 close（下线）。open / close-client 是「蓝天」扩展接口，
 * 保留是为了让运营侧能恢复误停的隧道、以及按客户端批量处置。
 * ===========================================================================
 */

final class ProxiesController
{
    /** POST /proxies/close —— 下线隧道（官方接口） */
    public static function close()
    {
        $db   = ltDb();
        $b    = Req::body();
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

    /**
     * POST /proxies/open —— 重新上线隧道（本项目扩展）
     *
     * 上线前必须复查配额：隧道停着的时候客户端可能已经把流量跑超了，
     * 无条件恢复等于绕过配额限制。
     */
    public static function open()
    {
        $db   = ltDb();
        $b    = Req::body();
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

    /** POST /proxies/close-client —— 批量下线某客户端的全部隧道（扩展接口） */
    public static function closeClient()
    {
        $db = ltDb();
        $b  = Req::body();
        $cn = trim($b['client'] ?? $b['name'] ?? '');
        if ($cn === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }
        $n = offlineClientTunnels($db, $cn);
        respond(['success' => true, 'closed' => $n]);
    }

    /**
     * POST /proxies —— 新建隧道
     *
     * 【校验顺序即报错优先级，不能随便调】
     * 节点存在性校验必须排在端口冲突之前。否则「节点压根不存在」的请求
     * 会先撞上端口冲突，返回一个误导性的 PORT_CONFLICT，运营就会跑去改端口，
     * 而真正的问题是节点名写错了。先回答「这个节点能不能接」，
     * 再回答「端口占没占」。
     */
    public static function create()
    {
        $db = ltDb();
        $b  = Req::body();

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
        $nodeName   = trim((string) ($b['nodeName'] ?? $b['node_name'] ?? ''));
        $type       = strtolower(trim((string) ($b['type'] ?? 'tcp')));
        $port       = (int) ($b['port'] ?? 0);

        checkLen($name, 'proxy_name', 'name');
        checkLen($clientName, 'client_name', 'clientName');
        checkLen($b['customDomain'] ?? $b['custom_domain'] ?? '', 'custom_domain', 'customDomain');
        if ($port < 0 || $port > 65535) {
            respond(['success' => false, 'error' => 'INVALID_PORT', 'max' => 65535], 400);
        }

        // 隧道类型白名单。stcp/xtcp 是 NexusLink 保留类型，本项目数据面暂不支持，
        // 放进来只会在下发时静默失败，不如在建的时候就说清楚。
        if (!in_array($type, KNOWN_PROTOCOLS, true)) {
            respond([
                'success' => false,
                'error'   => 'INVALID_PROTOCOL',
                'message' => '不支持的隧道类型',
                'allowed' => KNOWN_PROTOCOLS,
            ], 400);
        }

        // ---- 客户端配额校验：隧道数上限 + 流量余量 ----
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

        // ---- 节点存在性 + 协议能力校验（必须早于端口冲突） ----
        if ($nodeName !== '') {
            if (!hasColumn($db, 'nodes', 'protocols')) {
                // 老库还没迁移，不阻断（协议能力字段不存在就无从校验）
                $st = $db->prepare('SELECT id FROM nodes WHERE name = ?');
                $st->execute([$nodeName]);
                if (!$st->fetch()) {
                    respond([
                        'success' => false,
                        'error'   => 'NODE_NOT_FOUND',
                        'message' => "节点「{$nodeName}」不存在，请先在节点管理中创建",
                    ], 404);
                }
            } else {
                $st = $db->prepare('SELECT name, protocols FROM nodes WHERE name = ? LIMIT 1');
                $st->execute([$nodeName]);
                $node = $st->fetch();
                if (!$node) {
                    respond([
                        'success' => false,
                        'error'   => 'NODE_NOT_FOUND',
                        'message' => "节点「{$nodeName}」不存在，请先在节点管理中创建",
                    ], 404);
                }

                $nodeProtos = decodeProtocols($node['protocols'] ?? null);
                if (!in_array($type, $nodeProtos, true)) {
                    respond([
                        'success'       => false,
                        'error'         => 'NODE_PROTOCOL_UNSUPPORTED',
                        'message'       => sprintf(
                            '节点「%s」未声明支持 %s，无法承接该隧道',
                            $node['name'],
                            strtoupper($type)
                        ),
                        'nodeProtocols' => $nodeProtos,
                    ], 403);
                }
            }
        }

        // ---- 端口冲突校验（同类型下端口唯一） ----
        if ($port > 0) {
            $pc = $db->prepare('SELECT name FROM proxies WHERE port = ? AND type = ? LIMIT 1');
            $pc->execute([$port, $type]);
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
            $type,
            $port,
            $b['localAddr'] ?? $b['local_addr'] ?? '127.0.0.1',
            (int) ($b['localPort'] ?? $b['local_port'] ?? 0),
            $b['customDomain'] ?? $b['custom_domain'] ?? '',
            $b['status'] ?? 'online',
            $clientName,
            $nodeName,
        ]);

        // 同步客户端的隧道计数
        if ($clientName !== '') {
            self::syncClientProxyCount($db, $clientName);
        }

        respond(['success' => true, 'name' => $name]);
    }

    /** GET /proxies —— 隧道列表 */
    public static function index()
    {
        $db   = ltDb();
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

    /** DELETE /proxies/{name} */
    public static function destroy(string $name)
    {
        $db = ltDb();

        $st = $db->prepare('SELECT client_name FROM proxies WHERE name = ?');
        $st->execute([$name]);
        $row = $st->fetch();

        $db->prepare('DELETE FROM proxies WHERE name = ?')->execute([$name]);

        if ($row && $row['client_name'] !== '') {
            self::syncClientProxyCount($db, $row['client_name']);
        }
        respond(['success' => true]);
    }

    /** 重算并写回某客户端的隧道计数（建/删隧道后都要调一次） */
    private static function syncClientProxyCount(PDO $db, string $clientName): void
    {
        $db->prepare(
            'UPDATE clients SET proxy_count = (SELECT COUNT(*) FROM proxies WHERE client_name = ?) WHERE name = ?'
        )->execute([$clientName, $clientName]);
    }
}
