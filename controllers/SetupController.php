<?php
/**
 * 蓝天内网穿透 · 初始化引导控制器
 * ===========================================================================
 * 【最高危设计，改动前必读】
 *
 * 这两个接口必须在鉴权之外，因为它们的存在前提就是「users 表是空的」——
 * 也就是说此刻系统里没有任何人能登录。
 *
 * 如果把初始化接口放进 requireAuth() 之后，就会出现死锁：
 *   想创建管理员 → 需要先登录 → 没有管理员可登录 → 永远进不去
 *
 * 拆分为控制器时，这一点通过「路由表里不挂任何中间件」来表达 ——
 * 看一眼 routes.php 就能确认它们确实是公开的。
 * ===========================================================================
 */

final class SetupController
{
    /**
     * GET /v1/setup/status —— 是否已完成初始化（公开）
     */
    public static function status(): void
    {
        $db = pdo();
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

        respond([
            'success'     => true,
            'initialized' => $count > 0,
            'userCount'   => $count,
            // 老库缺 sessions.expires_at 时提示前端，避免用户看到「会话超时」设了没用
            'schemaReady' => hasColumn($db, 'nodes', 'protocols'),
            // v1.1.5：把新特性可用性也报出来，前端据此决定是否显示 2FA 入口
            'features'    => self::features($db),
        ]);
    }

    /**
     * POST /v1/setup/admin —— 创建首个管理员（公开 + 重入保护）
     */
    public static function createAdmin(): void
    {
        $db = pdo();
        $b  = body();

        // ---- 重入保护第一层：users 表非空则一律拒绝 ----
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            respond([
                'success' => false,
                'error'   => 'ALREADY_INITIALIZED',
                'message' => '系统已完成初始化，请直接登录',
            ], 403);
        }

        $u = trim((string) ($b['username'] ?? ''));
        $p = (string) ($b['password'] ?? '');
        $email = trim((string) ($b['email'] ?? ''));
        $realName = trim((string) ($b['realName'] ?? $b['real_name'] ?? ''));

        checkLen($u, 'user_name', '账号');
        checkLen($email, 'email', '邮箱');

        // 账号字符白名单：避免出现前后空格、全角字符导致的「明明输对了却登不上」
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $u)) {
            respond([
                'success' => false,
                'error'   => 'INVALID_USERNAME',
                'message' => '账号只能用字母、数字、下划线、点、@、连字符，长度 3-64',
            ], 400);
        }

        // 密码强度：8 位起步。这是全站权限最高的一把钥匙，不设上限但设下限
        if (mb_strlen($p) < 8) {
            respond([
                'success' => false,
                'error'   => 'PASSWORD_TOO_WEAK',
                'message' => '密码至少 8 位',
                'min'     => 8,
            ], 400);
        }

        $hash = password_hash($p, PASSWORD_DEFAULT);

        try {
            $db->prepare(
                'INSERT INTO users
                   (username, password, email, role, status, `group`,
                    max_tunnels, max_bandwidth, traffic_total, real_name, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
            )->execute([
                $u,
                $hash,
                $email,
                'admin',
                'active',
                '管理员',
                9999,
                1000,
                0,
                $realName !== '' ? $realName : '系统管理员',
            ]);
        } catch (PDOException $e) {
            // ---- 重入保护第二层：并发兜底 ----
            // 两个请求同时通过上面的 COUNT 检查时，users.username 的 UNIQUE 约束会拦住后者。
            // 这里把 1062 翻译成同一语义，前端看到的行为与顺序执行一致。
            if (($e->errorInfo[1] ?? 0) === 1062) {
                respond([
                    'success' => false,
                    'error'   => 'ALREADY_INITIALIZED',
                    'message' => '系统已完成初始化（并发检测）',
                ], 403);
            }
            respond([
                'success' => false,
                'error'   => 'CREATE_FAILED',
                'message' => '创建失败：' . $e->getMessage(),
            ], 500);
        }

        // 建成即登录：直接下发会话，省掉一次「刚设完密码又要输一遍」
        $token = bin2hex(random_bytes(32));
        $timeout = settingsGet($db, 'sessionTimeout', 60);
        $timeout = is_numeric($timeout) ? (int) $timeout : 60;
        if ($timeout <= 0) {
            $timeout = 60;
        }

        // 与旧实现保持一致：单位是分钟
        if (hasColumn($db, 'sessions', 'expires_at')) {
            $db->prepare(
                'INSERT INTO sessions (token, username, created_at, expires_at, last_active)
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
            )->execute([$token, $u, $timeout]);
        } else {
            $db->prepare('INSERT INTO sessions (token, username) VALUES (?, ?)')
               ->execute([$token, $u]);
        }

        if (function_exists('ltLog')) {
            ltLog('setup', ['event' => 'admin_created', 'username' => $u, 'ip' => Req::ip()]);
        }

        respond([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'username' => $u,
                'role'     => 'admin',
                'email'    => $email,
                'realName' => $realName !== '' ? $realName : '系统管理员',
            ],
        ]);
    }

    /**
     * 探测本环境支持哪些 v1.1.5 新特性。
     *
     * 前端据此决定 UI：比如 2FA 表没建起来时就不要显示 2FA 开关 ——
     * 显示了却点不动，比不显示更让人困惑。
     */
    private static function features(PDO $db): array
    {
        $has = function (string $t) use ($db): bool {
            if (function_exists('schemaTableExists')) {
                return schemaTableExists($db, $t);
            }
            try {
                $st = $db->query("SHOW TABLES LIKE " . $db->quote($t));
                return (bool) ($st && $st->fetch());
            } catch (Throwable $e) {
                return false;
            }
        };

        return [
            'twoFactor'    => $has('user_2fa'),
            'loginLogs'    => $has('login_logs'),
            'loginLock'    => $has('login_attempts'),
            'agents'       => $has('agents'),
            'captcha'      => function_exists('imagecreatetruecolor'),
        ];
    }
}
