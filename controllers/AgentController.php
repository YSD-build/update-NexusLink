<?php
/**
 * 代理商控制台控制器（v1.1.5 新增）
 * ===========================================================================
 * 【定位】分销商：管下级用户与额度。
 *
 * 与「用户管理」(UsersController) 的区别：
 *   · UsersController 是平台方视角 —— 管所有人，能改角色、改状态
 *   · 本控制器是代理商视角   —— 只能看/改「自己名下」的用户与额度
 *
 * 【数据隔离是第一要务】
 * 代理商绝不能看到别人名下的用户。所有查询都必须带 owner_agent 条件，
 * 且 owner_agent 只能取自当前登录主体，不能从前端参数取 —— 否则改一个
 * query 参数就能越权读全平台数据。
 *
 * 【额度模型：按用户上限，不是数字池】
 * agents 表定义的不是「一个可分配的总数」，而是三道上限：
 *     max_users            可发展的下级用户数
 *     max_tunnels_per_user 每个下级最多能开多少条隧道
 *     max_traffic_per_user 每个下级的流量上限
 *     total_traffic_quota  名下所有下级共享的流量池
 *     commission_rate      佣金比例（百分比）
 *
 * 这个模型比「数字池」更适合分销场景：代理商不需要做减法记账，
 * 平台也避免了「额度被谁占着」的对账难题。超额判断发生在
 * 「创建下级」和「给下级调额」两个动作上。
 * ===========================================================================
 */

final class AgentController
{
    /* ------------------------------------------------------------------ */
    /* 代理商视角：管理自己名下的下级                                           */
    /* ------------------------------------------------------------------ */

    /** GET /agent/overview —— 代理商业绩概览 */
    public static function overview()
    {
        $db      = ltDb();
        $isAdmin = Auth::isAdmin();
        $name    = Auth::username();

        // 平台直属用户 = owner_agent 为空的用户。
        // 管理员看全平台，代理商看自己名下 —— 用同一个接口，避免前端写两套。
        if ($isAdmin) {
            $userCount  = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $agentCount = (int) $db->query('SELECT COUNT(*) FROM agents')->fetchColumn();
        } else {
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE owner_agent = ?');
            $st->execute([$name]);
            $userCount = (int) $st->fetchColumn();
            $agentCount = 0;
        }

        // 已分配出去的隧道总额度
        if ($isAdmin) {
            $tunnelQuota = (int) $db->query('SELECT COALESCE(SUM(max_tunnels),0) FROM users')->fetchColumn();
            $trafficUsed = (int) $db->query('SELECT COALESCE(SUM(traffic_used),0) FROM users')->fetchColumn();
        } else {
            $st = $db->prepare('SELECT COALESCE(SUM(max_tunnels),0) FROM users WHERE owner_agent = ?');
            $st->execute([$name]);
            $tunnelQuota = (int) $st->fetchColumn();

            $st = $db->prepare('SELECT COALESCE(SUM(traffic_used),0) FROM users WHERE owner_agent = ?');
            $st->execute([$name]);
            $trafficUsed = (int) $st->fetchColumn();
        }

        // 代理商自身的额度设定（管理员没有 agents 记录，此时给 0 表示「不受限」）
        $limits = self::limitsOf($db, $name);

        respond([
            'success' => true,
            'data'    => [
                'agent'      => $name,
                'isAdmin'    => $isAdmin,
                'userCount'  => $userCount,
                'maxUsers'   => $limits['maxUsers'],
                'agentCount' => $agentCount,
                'tunnelQuota' => $tunnelQuota,
                'trafficUsed' => $trafficUsed,
                'trafficQuota' => $limits['totalTrafficQuota'],
                'commissionRate' => $limits['commissionRate'],
                'tunnelsPerUser' => $limits['maxTunnelsPerUser'],
                'trafficPerUser' => $limits['maxTrafficPerUser'],
                // 下级名额还剩几个（管理员为 -1 表示不限）
                'usersLeft'  => $isAdmin ? -1 : max(0, $limits['maxUsers'] - $userCount),
            ],
        ]);
    }

    /** GET /agent/users —— 名下的下级用户 */
    public static function users()
    {
        $db  = ltDb();
        $sql = 'SELECT u.id, u.username, u.email, u.role, u.status, u.`group`,
                       u.max_tunnels AS maxTunnels, u.max_bandwidth AS maxBandwidth,
                       u.traffic_used AS trafficUsed, u.traffic_total AS trafficTotal,
                       u.real_name AS realName, u.owner_agent AS ownerAgent,
                       u.created_at AS createdAt,
                       (SELECT COUNT(*) FROM proxies p WHERE p.client_name = u.username) AS tunnelCount
                FROM users u';

        if (Auth::isAdmin()) {
            $rows = $db->query($sql . ' ORDER BY u.id DESC')->fetchAll();
        } else {
            $st = $db->prepare($sql . ' WHERE u.owner_agent = ? ORDER BY u.id DESC');
            $st->execute([Auth::username()]);
            $rows = $st->fetchAll();
        }

        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
            foreach (['maxTunnels', 'maxBandwidth', 'trafficUsed', 'trafficTotal', 'tunnelCount'] as $k) {
                $r[$k] = (int) $r[$k];
            }
        }
        unset($r);

        respond(['success' => true, 'users' => $rows]);
    }

    /** POST /agent/users —— 代理商创建下级用户 */
    public static function createUser()
    {
        $db  = ltDb();
        $b   = Req::body();
        $me  = Auth::username();
        $admin = Auth::isAdmin();

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

        if (!$admin) {
            $limits = self::limitsOf($db, $me);

            // 1) 下级名额
            $st = $db->prepare('SELECT COUNT(*) FROM users WHERE owner_agent = ?');
            $st->execute([$me]);
            $current = (int) $st->fetchColumn();
            if ($limits['maxUsers'] > 0 && $current >= $limits['maxUsers']) {
                respond([
                    'success' => false,
                    'error'   => 'AGENT_USER_LIMIT',
                    'message' => sprintf('下级用户名额已满（%d / %d）', $current, $limits['maxUsers']),
                    'maxUsers' => $limits['maxUsers'],
                ], 403);
            }

            // 2) 单用户额度不得超代理商被允许的上限。
            //    这里用钳制而不是报错 —— 代理商想给下级多开点时，直接按平台
            //    允许的最大值执行更顺手，且不会削弱管控（上限还是那个上限）。
            $wantTunnels = (int) ($b['maxTunnels'] ?? $limits['maxTunnelsPerUser']);
            if ($limits['maxTunnelsPerUser'] > 0 && $wantTunnels > $limits['maxTunnelsPerUser']) {
                $wantTunnels = $limits['maxTunnelsPerUser'];
            }
            $wantTraffic = (int) ($b['trafficTotal'] ?? $limits['maxTrafficPerUser']);
            if ($limits['maxTrafficPerUser'] > 0 && $wantTraffic > $limits['maxTrafficPerUser']) {
                $wantTraffic = $limits['maxTrafficPerUser'];
            }
        } else {
            $wantTunnels = (int) ($b['maxTunnels'] ?? 5);
            $wantTraffic = (int) ($b['trafficTotal'] ?? 0);
        }

        $owner = $admin ? (string) ($b['ownerAgent'] ?? '') : $me;

        $db->prepare(
            'INSERT INTO users
               (username, password, email, role, status, `group`, max_tunnels, max_bandwidth,
                traffic_total, real_name, owner_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        )->execute([
            $u,
            password_hash($pw, PASSWORD_BCRYPT),
            $b['email'] ?? '',
            'user',                                     // 永远不能通过本接口提权
            $b['status'] ?? 'active',
            $b['group'] ?? ($admin ? '正式用户' : '代理商用户'),
            $wantTunnels,
            (int) ($b['maxBandwidth'] ?? 16),
            $wantTraffic,
            $b['realName'] ?? '-',
            $owner,
        ]);

        respond([
            'success' => true,
            'id'      => $db->lastInsertId(),
            'ownerAgent' => $owner,
            'maxTunnels' => $wantTunnels,
        ]);
    }

    /** PUT /agent/users/{id} —— 改下级用户的额度/状态 */
    public static function updateUser(string $id)
    {
        $db = ltDb();
        $b  = Req::body();

        $st = $db->prepare(
            'SELECT id, username, owner_agent AS ownerAgent, max_tunnels AS maxTunnels,
                    traffic_total AS trafficTotal, real_name AS realName, email, status, `group`
             FROM users WHERE id = ?'
        );
        $st->execute([(int) $id]);
        $target = $st->fetch();
        if (!$target) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        // 越权防线：代理商只能改自己名下的人。
        // 这里返回 404 而不是 403 —— 403 等于告诉对方「这个 id 存在但你没权限」，
        // 攻击者可以据此枚举出全平台的用户 id。
        if (!Auth::isAdmin() && (string) $target['ownerAgent'] !== Auth::username()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $newTunnels = (int) ($b['maxTunnels'] ?? $target['maxTunnels']);
        $newTraffic = (int) ($b['trafficTotal'] ?? $target['trafficTotal']);

        if (!Auth::isAdmin()) {
            $limits = self::limitsOf($db, Auth::username());
            if ($limits['maxTunnelsPerUser'] > 0 && $newTunnels > $limits['maxTunnelsPerUser']) {
                respond([
                    'success' => false,
                    'error'   => 'AGENT_QUOTA_EXCEEDED',
                    'message' => sprintf('单个下级最多 %d 条隧道', $limits['maxTunnelsPerUser']),
                    'max'     => $limits['maxTunnelsPerUser'],
                ], 403);
            }
            if ($limits['maxTrafficPerUser'] > 0 && $newTraffic > $limits['maxTrafficPerUser']) {
                respond([
                    'success' => false,
                    'error'   => 'AGENT_QUOTA_EXCEEDED',
                    'message' => '超出单个下级的流量限额',
                ], 403);
            }
        }

        // 只允许改「额度类」字段。角色、归属、用户名不在此列 ——
        // 代理商不该有能力把人改到自己名下或者提权。
        $db->prepare(
            'UPDATE users SET max_tunnels = ?, max_bandwidth = ?, status = ?, `group` = ?,
                    real_name = ?, email = ?, traffic_total = ?
             WHERE id = ?'
        )->execute([
            $newTunnels,
            (int) ($b['maxBandwidth'] ?? 16),
            $b['status'] ?? $target['status'],
            $b['group'] ?? $target['group'],
            $b['realName'] ?? $target['realName'],
            $b['email'] ?? $target['email'],
            $newTraffic,
            (int) $id,
        ]);

        respond(['success' => true]);
    }

    /**
     * DELETE /agent/users/{id} —— 删除下级
     *
     * 删除用户时会连带清掉他的客户端与隧道吗？不会 ——
     * users 表与 clients/proxies 表之间没有外键约束，且 username 与
     * client name 未必对应。这里只删账号，运营若需要清理资源
     * 应到客户端/隧道页面处理。返回提示说明这一点，避免误会。
     */
    public static function deleteUser(string $id)
    {
        $db = ltDb();

        $st = $db->prepare('SELECT username, owner_agent AS ownerAgent FROM users WHERE id = ?');
        $st->execute([(int) $id]);
        $target = $st->fetch();
        if (!$target) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }
        if (!Auth::isAdmin() && (string) $target['ownerAgent'] !== Auth::username()) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $db->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $id]);
        // 同时失效其登录会话，否则被删账号手上的 token 还能继续用
        $db->prepare('DELETE FROM sessions WHERE username = ?')->execute([$target['username']]);

        respond([
            'success' => true,
            'notice'  => '账号及其登录会话已删除。该账号名下的客户端与隧道不会自动清除，如需回收请到对应页面处理。',
        ]);
    }

    /** GET /agent/quota —— 查看自己的额度（管理员返回全部代理商） */
    public static function quota()
    {
        $db = ltDb();

        if (Auth::isAdmin()) {
            respond(['success' => true, 'agents' => self::listAgents($db)]);
        }

        $st = $db->prepare(
            'SELECT username, display_name AS displayName, max_users AS maxUsers,
                    max_tunnels_per_user AS maxTunnelsPerUser,
                    max_traffic_per_user AS maxTrafficPerUser,
                    total_traffic_quota AS totalTrafficQuota,
                    commission_rate AS commissionRate, status, note,
                    created_at AS createdAt
             FROM agents WHERE username = ? LIMIT 1'
        );
        $st->execute([Auth::username()]);
        $r = $st->fetch();

        if (!$r) {
            // 尚未在 agents 表登记：如实返回 0，不要编造一个额度
            respond([
                'success' => true,
                'quota'   => [
                    'username'          => Auth::username(),
                    'maxUsers'          => 0,
                    'maxTunnelsPerUser' => 0,
                    'maxTrafficPerUser' => 0,
                    'totalTrafficQuota' => 0,
                    'commissionRate'    => 0,
                    'status'            => 'active',
                    'note'              => '尚未分配代理商额度，请联系平台管理员',
                ],
            ]);
        }

        foreach (['maxUsers', 'maxTunnelsPerUser', 'maxTrafficPerUser', 'totalTrafficQuota'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['commissionRate'] = (float) $r['commissionRate'];

        respond(['success' => true, 'quota' => $r]);
    }

    /** PUT /agent/quota —— 分配额度（仅管理员） */
    public static function saveQuota()
    {
        $db = ltDb();
        $b  = Req::body();

        if (!Auth::isAdmin()) {
            respond([
                'success' => false,
                'error'   => 'FORBIDDEN',
                'message' => '额度由平台管理员分配，代理商无法自行修改',
            ], 403);
        }

        $username = trim((string) ($b['username'] ?? ''));
        if ($username === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }

        // 要求账号真实存在：凭空给一个不存在的用户名配额度，
        // 会留下一条永不被使用的 agents 记录，日后对账时非常困惑。
        $st = $db->prepare('SELECT id FROM users WHERE username = ?');
        $st->execute([$username]);
        if (!$st->fetch()) {
            respond([
                'success' => false,
                'error'   => 'USER_NOT_FOUND',
                'message' => '该账号不存在，请先在用户管理中创建',
            ], 404);
        }

        $fields = [
            'max_users'            => (int) ($b['maxUsers'] ?? 50),
            'max_tunnels_per_user' => (int) ($b['maxTunnelsPerUser'] ?? 5),
            'max_traffic_per_user' => (int) ($b['maxTrafficPerUser'] ?? 0),
            'total_traffic_quota'  => (int) ($b['totalTrafficQuota'] ?? 0),
            'commission_rate'      => (float) ($b['commissionRate'] ?? 0),
            'status'               => (string) ($b['status'] ?? 'active'),
            'note'                 => (string) ($b['note'] ?? ''),
        ];

        $st = $db->prepare('SELECT id FROM agents WHERE username = ?');
        $st->execute([$username]);
        $exist = $st->fetch();

        if ($exist) {
            $db->prepare(
                'UPDATE agents SET max_users = ?, max_tunnels_per_user = ?, max_traffic_per_user = ?,
                        total_traffic_quota = ?, commission_rate = ?, status = ?, note = ?
                 WHERE username = ?'
            )->execute(array_merge(array_values($fields), [$username]));
        } else {
            $db->prepare(
                'INSERT INTO agents
                   (username, display_name, max_users, max_tunnels_per_user, max_traffic_per_user,
                    total_traffic_quota, commission_rate, status, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
            )->execute(array_merge(
                [$username, (string) ($b['displayName'] ?? '')],
                array_values($fields)
            ));
            // 建了额度记录也顺手给角色，避免「有额度但看不到控制台」
            $db->prepare("UPDATE users SET role = 'agent' WHERE username = ?")->execute([$username]);
        }

        respond(['success' => true, 'username' => $username]);
    }

    /* ------------------------------------------------------------------ */
    /* 平台视角：管理代理商本身（仅管理员）                                     */
    /* ------------------------------------------------------------------ */

    /** GET /agents */
    public static function index()
    {
        respond(['success' => true, 'agents' => self::listAgents(ltDb())]);
    }

    /**
     * POST /agents —— 把某个用户提升为代理商
     *
     * 【为什么不在这里建账号】
     * 代理商首先得是个能登录的用户。如果本接口顺手创建用户，就会出现
     * 「代理商密码谁设的」这类问题。所以要求先建用户，再来这里授权。
     * 好处是账号生命周期只有一个入口（UsersController），不会分叉。
     */
    public static function create()
    {
        $db = ltDb();
        $b  = Req::body();

        $username = trim((string) ($b['username'] ?? ''));
        if ($username === '') {
            respond(['success' => false, 'error' => 'NAME_REQUIRED'], 400);
        }

        $st = $db->prepare('SELECT id, username, role FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $user = $st->fetch();
        if (!$user) {
            respond([
                'success' => false,
                'error'   => 'USER_NOT_FOUND',
                'message' => '请先在用户管理中创建该账号，再授予代理商身份',
            ], 404);
        }

        $st = $db->prepare('SELECT id FROM agents WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT', 'message' => '该账号已是代理商'], 409);
        }

        $db->prepare(
            'INSERT INTO agents
               (username, display_name, max_users, max_tunnels_per_user, max_traffic_per_user,
                total_traffic_quota, commission_rate, status, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
        )->execute([
            $username,
            (string) ($b['displayName'] ?? ''),
            (int) ($b['maxUsers'] ?? 50),
            (int) ($b['maxTunnelsPerUser'] ?? 5),
            (int) ($b['maxTrafficPerUser'] ?? 0),
            (int) ($b['totalTrafficQuota'] ?? 0),
            (float) ($b['commissionRate'] ?? 0),
            (string) ($b['status'] ?? 'active'),
            (string) ($b['note'] ?? ''),
        ]);

        // 同步 users.role —— 权限判定读的是 Auth 里的 role，
        // 只写 agents 表而不改 role 会导致「已是代理商但登录后看不到控制台」。
        $db->prepare("UPDATE users SET role = 'agent' WHERE username = ?")->execute([$username]);

        respond([
            'success'  => true,
            'username' => $username,
            'notice'   => '已授予代理商身份，该账号下次登录即可看到代理商控制台',
        ]);
    }

    /** PUT /agents/{id} */
    public static function update(string $id)
    {
        $db = ltDb();
        $b  = Req::body();

        $st = $db->prepare('SELECT id, username FROM agents WHERE id = ?');
        $st->execute([(int) $id]);
        $a = $st->fetch();
        if (!$a) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $fields = [];
        $params = [];
        foreach ([
            'displayName'       => 'display_name',
            'maxUsers'          => 'max_users',
            'maxTunnelsPerUser' => 'max_tunnels_per_user',
            'maxTrafficPerUser' => 'max_traffic_per_user',
            'totalTrafficQuota' => 'total_traffic_quota',
            'commissionRate'    => 'commission_rate',
            'status'            => 'status',
            'note'              => 'note',
        ] as $in => $col) {
            if (array_key_exists($in, $b)) {
                $fields[] = "`$col` = ?";
                $params[] = $b[$in];
            }
        }

        if ($fields) {
            $params[] = (int) $id;
            $db->prepare('UPDATE agents SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        respond(['success' => true]);
    }

    /** DELETE /agents/{id} —— 撤销代理商身份（账号保留，降级为普通用户） */
    public static function destroy(string $id)
    {
        $db = ltDb();

        $st = $db->prepare('SELECT username FROM agents WHERE id = ?');
        $st->execute([(int) $id]);
        $username = $st->fetchColumn();
        if (!$username) {
            respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        $db->prepare('DELETE FROM agents WHERE id = ?')->execute([(int) $id]);

        // 【不删账号也不删下级用户】
        // 删账号会连带丢失其名下的用户；直接清空 owner_agent 也会让那些用户
        // 变成「孤儿」—— 平台上没人管、代理商也看不到。这里只降角色，
        // 归属关系保留，管理员在用户管理里能继续看到并处置。
        $db->prepare("UPDATE users SET role = 'user' WHERE username = ?")->execute([$username]);

        $st = $db->prepare('SELECT COUNT(*) FROM users WHERE owner_agent = ?');
        $st->execute([$username]);
        $orphans = (int) $st->fetchColumn();

        respond([
            'success' => true,
            'notice'  => $orphans > 0
                ? "已撤销代理商身份，其名下 {$orphans} 个用户仍保留原归属，可在用户管理中重新分配"
                : '已撤销代理商身份',
            'orphanUsers' => $orphans,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** 代理商列表（管理员用，含名下用户数） */
    private static function listAgents(PDO $db): array
    {
        $rows = $db->query(
            'SELECT a.id, a.username, a.display_name AS displayName,
                    a.max_users AS maxUsers, a.max_tunnels_per_user AS maxTunnelsPerUser,
                    a.max_traffic_per_user AS maxTrafficPerUser,
                    a.total_traffic_quota AS totalTrafficQuota,
                    a.commission_rate AS commissionRate, a.status, a.note,
                    a.created_at AS createdAt,
                    u.email, u.real_name AS realName,
                    (SELECT COUNT(*) FROM users x WHERE x.owner_agent = a.username) AS userCount
             FROM agents a
             LEFT JOIN users u ON u.username = a.username
             ORDER BY a.id'
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
            foreach ([
                'maxUsers', 'maxTunnelsPerUser', 'maxTrafficPerUser',
                'totalTrafficQuota', 'userCount',
            ] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['commissionRate'] = (float) $r['commissionRate'];
            $r['usersLeft'] = max(0, $r['maxUsers'] - $r['userCount']);
        }
        unset($r);

        return $rows;
    }

    /**
     * 取某账号的代理商额度设定。
     *
     * 【为什么返回一份「全 0」而不是 null】
     * 0 在本模型里的语义是「不限制」（见各处的 `> 0` 判断），
     * 与「没有记录」是同一种效果。这样调用方不需要到处判空。
     * 管理员账号通常没有 agents 记录，因此天然不受限。
     */
    private static function limitsOf(PDO $db, string $username): array
    {
        $st = $db->prepare(
            'SELECT max_users, max_tunnels_per_user, max_traffic_per_user,
                    total_traffic_quota, commission_rate
             FROM agents WHERE username = ? LIMIT 1'
        );
        $st->execute([$username]);
        $r = $st->fetch();

        if (!$r) {
            return [
                'maxUsers'          => 0,
                'maxTunnelsPerUser' => 0,
                'maxTrafficPerUser' => 0,
                'totalTrafficQuota' => 0,
                'commissionRate'    => 0.0,
            ];
        }

        return [
            'maxUsers'          => (int) $r['max_users'],
            'maxTunnelsPerUser' => (int) $r['max_tunnels_per_user'],
            'maxTrafficPerUser' => (int) $r['max_traffic_per_user'],
            'totalTrafficQuota' => (int) $r['total_traffic_quota'],
            'commissionRate'    => (float) $r['commission_rate'],
        ];
    }
}
