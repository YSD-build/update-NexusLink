<?php
/**
 * 用户管理控制器（管理员专属）
 * ===========================================================================
 */

final class UsersController
{
    /** GET /users */
    public static function index()
    {
        $db   = ltDb();
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

    /** GET /users/{id} —— 单个用户详情 */
    public static function show(string $id)
    {
        $db = ltDb();
        $st = $db->prepare(
            'SELECT id, username, email, role, status, `group`,
                    max_tunnels AS maxTunnels, max_bandwidth AS maxBandwidth,
                    traffic_used AS trafficUsed, traffic_total AS trafficTotal,
                    real_name AS realName, owner_agent AS ownerAgent,
                    created_at AS createdAt
             FROM users WHERE id = ?'
        );
        $st->execute([(int) $id]);
        $r = $st->fetch();
        if (!$r) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $r['id'] = (string) $r['id'];
        foreach (['maxTunnels', 'maxBandwidth', 'trafficUsed', 'trafficTotal'] as $k) {
            $r[$k] = (int) $r[$k];
        }

        // 顺带给出该用户当前的实际资源占用，省得前端再发两次请求。
        // 用户配的额度够不够用，看这两个数字最直观。
        $st = $db->prepare('SELECT COUNT(*) FROM clients WHERE name = ?');
        $st->execute([$r['username']]);
        $r['clientCount'] = (int) $st->fetchColumn();

        $st = $db->prepare('SELECT COUNT(*) FROM proxies WHERE client_name = ?');
        $st->execute([$r['username']]);
        $r['tunnelCount'] = (int) $st->fetchColumn();

        respond(['success' => true, 'user' => $r]);
    }

    /** POST /users */
    public static function create()
    {
        $db = ltDb();
        $b  = Req::body();

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

        // 【已移除默认密码】原实现是 $b['password'] ?? 'admin' ——
        // 管理员漏填密码时会静默创建一个密码为 admin 的账号，等于开后门。
        // 现在强制要求显式提供，且同样要求 8 位起步。
        $pw = (string) ($b['password'] ?? '');
        if ($pw === '') {
            respond([
                'success' => false,
                'error'   => 'PASSWORD_REQUIRED',
                'message' => '请为新用户设置密码',
            ], 400);
        }
        if (mb_strlen($pw) < 8) {
            respond([
                'success' => false,
                'error'   => 'PASSWORD_TOO_WEAK',
                'message' => '密码至少 8 位',
                'min'     => 8,
            ], 400);
        }

        $hash = password_hash($pw, PASSWORD_BCRYPT);
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

    /** PUT /users/{id} */
    public static function update(string $id)
    {
        $db = ltDb();
        $b  = Req::body();

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

    /** DELETE /users/{id} */
    public static function destroy(string $id)
    {
        $db = ltDb();
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $id]);
        respond(['success' => true]);
    }
}
