<?php
/**
 * 数据库连接与通用响应工具
 */

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $c;
}

function pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = cfg()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);
    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        respond(['success' => false, 'error' => 'DB_CONNECT_FAIL: ' . $e->getMessage()], 500);
    }
    return $pdo;
}

function respond($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** 取 Bearer token 或 X-API-Key */
function bearerToken(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
        return $m[1];
    }
    return $_SERVER['HTTP_X_API_KEY'] ?? '';
}

/**
 * 当前请求的鉴权主体（由 requireAuth 填充）。
 * ['type' => 'session'|'apikey', 'username' => string, 'role' => string, 'scopes' => array]
 */
$GLOBALS['__auth_principal'] = null;

/** 取当前鉴权主体；未鉴权时返回 null */
function currentPrincipal(): ?array {
    return $GLOBALS['__auth_principal'] ?? null;
}

/**
 * 校验登录态或 API Key，失败直接 401。
 * 成功后把主体信息写入 $GLOBALS['__auth_principal']，供 requireRole() 判断权限。
 *
 * 注意：session 走 users.role；API Key 走 api_keys.scopes（JSON 数组，默认 ["admin"]）。
 */
function requireAuth(): void {
    $token = bearerToken();
    if ($token === '') {
        respond(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
    }
    $db = pdo();

    // 1) Web 会话：带上真实角色
    //    【修复】同时校验 expires_at。旧实现只看 token 是否存在，
    //    会话一旦建立就永久有效，会话超时设置形同虚设。
    //    老库可能尚无 expires_at 列，故用能力探测决定是否带上该条件。
    $hasExpiry = hasColumn($db, 'sessions', 'expires_at');
    $sql = $hasExpiry
        ? 'SELECT s.token, s.username, u.role, u.status, s.expires_at
             FROM sessions s
             JOIN users u ON u.username = s.username
            WHERE s.token = ?'
        : 'SELECT s.token, s.username, u.role, u.status, NULL AS expires_at
             FROM sessions s
             JOIN users u ON u.username = s.username
            WHERE s.token = ?';
    $st = $db->prepare($sql);
    $st->execute([$token]);
    $row = $st->fetch();
    if ($row) {
        // 过期判定：过期的会话直接删除，避免垃圾数据堆积
        if ($hasExpiry && !empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            try {
                $db->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
            } catch (Throwable $e) {
            }
            respond(['success' => false, 'error' => 'SESSION_EXPIRED'], 401);
        }

        if (($row['status'] ?? 'active') !== 'active') {
            respond(['success' => false, 'error' => 'ACCOUNT_DISABLED'], 403);
        }

        // 滑动续期：距上次活动超过 1 分钟才写库，避免每个请求都 UPDATE
        if ($hasExpiry) {
            try {
                $db->prepare(
                    'UPDATE sessions SET last_active = NOW()
                      WHERE token = ? AND (last_active IS NULL OR last_active < NOW() - INTERVAL 60 SECOND)'
                )->execute([$token]);
            } catch (Throwable $e) {
            }
        }

        $GLOBALS['__auth_principal'] = [
            'type'     => 'session',
            'username' => $row['username'],
            'role'     => $row['role'] ?: 'user',
            'scopes'   => [],
        ];
        return;
    }

    // 2) API Key：按其 scopes 授权
    //    兼容旧库：若 api_keys 尚无 scopes 字段（老版本初始化脚本），
    //    退化为「仅取 key」，并按管理员处理，避免 SQL 报错导致 500。
    $hasScopes = apiKeysHasScopes($db);
    $st = $db->prepare(
        $hasScopes
            ? 'SELECT `key`, scopes FROM api_keys WHERE `key` = ?'
            : 'SELECT `key`, NULL AS scopes FROM api_keys WHERE `key` = ?'
    );
    $st->execute([$token]);
    $k = $st->fetch();
    if ($k) {
        $db->prepare('UPDATE api_keys SET last_used = ? WHERE `key` = ?')
           ->execute([date('Y-m-d H:i'), $token]);
        $scopes = json_decode((string) $k['scopes'] ?: '["admin"]', true);
        if (!is_array($scopes)) {
            $scopes = ['admin'];
        }
        $GLOBALS['__auth_principal'] = [
            'type'     => 'apikey',
            'username' => 'api-key',
            'role'     => in_array('admin', $scopes, true) ? 'admin' : 'user',
            'scopes'   => $scopes,
        ];
        return;
    }

    respond(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
}

/**
 * 探测 api_keys 表是否存在 scopes 字段（带进程内缓存，避免每次请求都查 information_schema）。
 * 任何异常都返回 false，保证鉴权流程永不因 schema 探测而 500。
 */
function apiKeysHasScopes(PDO $db): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $st = $db->query("SHOW COLUMNS FROM api_keys LIKE 'scopes'");
        $cached = (bool) $st->fetch();
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/* ===========================================================================
 * 自动迁移（schema 自愈）
 * ---------------------------------------------------------------------------
 * 【为什么必须有这个函数】
 *
 * 在线更新的本质是「覆盖文件」，不是「导库」。老用户点了「立即更新」之后，
 * 新版的 api.php 会去 SELECT nodes.protocols —— 而老库根本没有这一列，
 * 于是整个控制台 500 白屏，用户完全不知道发生了什么，也回不去。
 *
 * 所以：文件下发之后必须由代码自己把缺的列补上。这就是本函数存在的全部理由。
 *
 * 【设计约束】
 *   1. 绝不抛异常。迁移失败也要让登录能进去 —— 进去了才有机会看诊断信息。
 *      所以每条 ALTER 独立 try/catch，失败静默跳过。
 *   2. 进程内只跑一次。用 static 标记，避免同一请求里重复探测。
 *   3. 用 SHOW COLUMNS 探测而不是 information_schema 全表扫描，走的是轻量路径。
 *   4. 有缓存文件时完全跳过探测，避免每请求都吃一次 SHOW COLUMNS 的开销。
 * ===========================================================================
 */

/** 迁移状态缓存文件路径 */
function schemaCacheFile(): string {
    return __DIR__ . '/storage/schema.version';
}

/**
 * 当前代码期望的 schema 版本。每次新增列就 +1。
 * 缓存文件里记录的数字与之相等时，说明已迁移过，直接跳过全部探测。
 */
function schemaTargetVersion(): int {
    return 2;
}

/**
 * 确保数据库结构与当前代码匹配。幂等，可在每次请求开头无脑调用。
 */
function ensureSchema(PDO $db): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        // 1) 快路径：缓存文件已是最新版本 → 什么都不做
        $cache = schemaCacheFile();
        if (is_file($cache)) {
            $v = (int) trim((string) @file_get_contents($cache));
            if ($v >= schemaTargetVersion()) {
                return;
            }
        }

        // 2) 目标列清单：col => DDL 片段
        $nodeCols = [
            'protocols'   => "VARCHAR(64) NOT NULL DEFAULT '[\"tcp\",\"udp\",\"http\",\"https\"]'",
            'node_token'  => "VARCHAR(128) NOT NULL DEFAULT ''",
            'last_seen'   => 'DATETIME NULL DEFAULT NULL',
            'cpu'         => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'mem'         => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
            'conn_count'  => 'INT NOT NULL DEFAULT 0',
            'probe_ok'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'probe_at'    => 'DATETIME NULL DEFAULT NULL',
        ];
        $sessionCols = [
            'expires_at'  => 'DATETIME NULL DEFAULT NULL',
            'last_active' => 'DATETIME NULL DEFAULT NULL',
        ];

        // 3) 缺表先建表（settings 是 v1.1.0 才有的新表）
        try {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                   skey VARCHAR(64) PRIMARY KEY,
                   svalue TEXT,
                   updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (Throwable $e) {
            // 忽略：可能无 CREATE 权限，但不影响已有功能
        }

        // 4) 逐列探测 + 补齐
        ltEnsureColumns($db, 'nodes', $nodeCols);
        ltEnsureColumns($db, 'sessions', $sessionCols);

        // 5) 历史数据修正：本次新增的列必须有合理默认值，否则界面读出来是空的
        try {
            $db->exec(
                "UPDATE nodes SET protocols = '[\"tcp\",\"udp\",\"http\",\"https\"]'
                  WHERE protocols IS NULL OR protocols = '' OR protocols = '[]'"
            );
        } catch (Throwable $e) {
        }

        // 6) 写缓存，下次请求走快路径
        $dir = __DIR__ . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($cache, (string) schemaTargetVersion());
        }
    } catch (Throwable $e) {
        // 兜底：绝不让迁移问题阻断登录
    }
}

/**
 * 逐列探测并补齐。单列失败不影响其它列。
 */
function ltEnsureColumns(PDO $db, string $table, array $cols): void {
    foreach ($cols as $col => $ddl) {
        try {
            $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            if ($st && $st->fetch()) {
                continue; // 已存在
            }
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) {
            // 单列失败继续，避免一列报错导致后续列全都不加
        }
    }
}

/**
 * 探测某个表是否有某列。供接口层做「老库兜底」判断用。
 */
function hasColumn(PDO $db, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
        $cache[$k] = (bool) ($st && $st->fetch());
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

/**
 * 要求当前主体具备指定角色之一，否则 403。
 * 必须在 requireAuth() 之后调用。
 *
 * @param string ...$roles 允许的角色，如 requireRole('admin')
 */
function requireRole(string ...$roles): void {
    $p = currentPrincipal();
    if ($p === null) {
        respond(['success' => false, 'error' => 'UNAUTHORIZED'], 401);
    }
    // 管理员放行一切
    if ($p['role'] === 'admin') {
        return;
    }
    // API Key 可按 scope 精细授权（例：scopes=["clients:write"]）
    foreach ($roles as $r) {
        if ($p['role'] === $r) {
            return;
        }
        if (in_array($r, $p['scopes'], true)) {
            return;
        }
    }
    respond([
        'success' => false,
        'error'   => 'FORBIDDEN',
        'message' => '当前角色无权执行该操作',
        'role'    => $p['role'],
        'need'    => $roles,
    ], 403);
}
