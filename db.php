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
                // 【v1.1.5 修】用 PHP 生成的时间字符串，与写入端同一时间源。
                //
                // 原来全程用 NOW() 其实**不会**错位（比较发生在 MySQL 内部，
                // 两边的时间基准相同）。但写入端已改为 PHP 时间，若这里还
                // 按 MySQL 的 NOW() 算，在时区不一致时会出现「last_active
                // 永远小于 NOW()-60 秒」或反之，导致每个请求都 UPDATE（白
                // 白加锁）或永远不 UPDATE（滑动续期失效）。统一时间源才能
                // 让这个 60 秒节流真正按预期工作。
                $ltNow = date('Y-m-d H:i:s');
                $ltOld = date('Y-m-d H:i:s', time() - 60);
                $db->prepare(
                    'UPDATE sessions SET last_active = ?
                      WHERE token = ? AND (last_active IS NULL OR last_active < ?)'
                )->execute([$ltNow, $token, $ltOld]);
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
    // 版本号变更历史：
    //   1 → 2  nodes 新增 protocols/node_token 等 8 列，sessions 新增 expires_at/last_active
    //   2 → 3  指纹机制引入（不再只比版本号）；表存在性纳入指纹
    //   3 → 4  v1.1.5：schemaTableDDL 补全核心表；users 新增 owner_agent
    //
    // 【为什么每加一列都要升版本】
    // 版本号参与指纹计算，升版本等于给所有已部署实例发一次「强制重扫」信号。
    // 不升的话，那些缓存指纹恰好还匹配的老库不会主动重扫，新列要等到
    // 用户手动删缓存才会被补上。
    return 4;
}

/**
 * 全部必需表的建表语句。
 *
 * 与 sql/init.sql 保持一致 —— 那边是首次部署用的完整脚本，这里是
 * 「运行期自愈」用的最小集合。两处都改，别只改一边。
 *
 * 注意：这里只负责「把表建出来」，不含种子数据，也不含后续新增的列
 * （列由 ltEnsureColumns 负责补）。
 */
function schemaTableDDL(): array {
    return [
        // ------------------------------------------------------------------
        // 【核心表】users / sessions / nodes / clients / proxies / api_keys
        //
        // 这几张表原先不在本函数里 —— 因为历史部署都靠 sql/init.sql 先建好。
        // 但这留下了一个死角：如果某个老库被人误删了 users 表（真发生过，
        // 见「settings 表被删不重建」那次排查），自愈逻辑只会补列、
        // 不知道该怎么建表，站点直接白屏。
        //
        // 现在全部收进来，字段定义与 sql/init.sql 严格保持一致。
        // 任何一张表缺了都能自愈，全新部署也不再需要先跑 SQL 脚本。
        // ------------------------------------------------------------------

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(128) DEFAULT '',
            role VARCHAR(16) DEFAULT 'user',
            status VARCHAR(16) DEFAULT 'active',
            `group` VARCHAR(64) DEFAULT '正式用户',
            max_tunnels INT DEFAULT 5,
            max_bandwidth INT DEFAULT 16,
            traffic_used BIGINT DEFAULT 0,
            traffic_total BIGINT DEFAULT 0,
            real_name VARCHAR(64) DEFAULT '-',
            created_at DATE,
            INDEX idx_status (status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'sessions' => "CREATE TABLE IF NOT EXISTS sessions (
            token VARCHAR(128) PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL DEFAULT NULL,
            last_active DATETIME NULL DEFAULT NULL,
            INDEX idx_user (username),
            INDEX idx_expires (expires_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'nodes' => "CREATE TABLE IF NOT EXISTS nodes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            host VARCHAR(64) DEFAULT '',
            port INT DEFAULT 7000,
            status VARCHAR(16) DEFAULT 'offline',
            clients INT DEFAULT 0,
            tunnels INT DEFAULT 0,
            max_tunnels INT DEFAULT 500,
            region VARCHAR(64) DEFAULT '',
            version VARCHAR(32) DEFAULT 'v0.5.1',
            protocols VARCHAR(64) NOT NULL DEFAULT '[\"tcp\",\"udp\",\"http\",\"https\"]',
            node_token VARCHAR(128) NOT NULL DEFAULT '',
            last_seen DATETIME NULL DEFAULT NULL,
            last_seen_ts BIGINT NULL DEFAULT NULL,
            cpu DECIMAL(5,2) NOT NULL DEFAULT 0,
            mem DECIMAL(5,2) NOT NULL DEFAULT 0,
            conn_count INT NOT NULL DEFAULT 0,
            probe_ok TINYINT(1) NOT NULL DEFAULT 0,
            probe_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uk_name (name)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'clients' => "CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) UNIQUE NOT NULL,
            token VARCHAR(128) DEFAULT '',
            bytes_in BIGINT DEFAULT 0,
            bytes_out BIGINT DEFAULT 0,
            connected TINYINT(1) DEFAULT 0,
            proxy_count INT DEFAULT 0,
            max_tunnels INT DEFAULT 3,
            max_traffic_bytes BIGINT DEFAULT 1073741824
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'proxies' => "CREATE TABLE IF NOT EXISTS proxies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            type VARCHAR(16) DEFAULT 'tcp',
            port INT DEFAULT 0,
            local_addr VARCHAR(64) DEFAULT '127.0.0.1',
            local_port INT DEFAULT 0,
            custom_domain VARCHAR(128) DEFAULT '',
            status VARCHAR(16) DEFAULT 'offline',
            client_name VARCHAR(64) DEFAULT '',
            node_name VARCHAR(64) DEFAULT '',
            created_at DATE,
            UNIQUE KEY uk_name (name),
            INDEX idx_client (client_name),
            INDEX idx_node (node_name),
            INDEX idx_status (status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'api_keys' => "CREATE TABLE IF NOT EXISTS api_keys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            `key` VARCHAR(128) UNIQUE NOT NULL,
            note VARCHAR(128) DEFAULT '',
            scopes VARCHAR(255) NOT NULL DEFAULT '[\"admin\"]',
            created_at DATE,
            last_used DATETIME NULL DEFAULT NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'downloads' => "CREATE TABLE IF NOT EXISTS downloads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            version VARCHAR(32) DEFAULT '',
            platform VARCHAR(32) DEFAULT '',
            arch VARCHAR(32) DEFAULT '',
            size BIGINT DEFAULT 0,
            url VARCHAR(255) DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'traffic_stats' => "CREATE TABLE IF NOT EXISTS traffic_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            bytes_in BIGINT DEFAULT 0,
            bytes_out BIGINT DEFAULT 0,
            UNIQUE KEY uk_date (stat_date)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ------------------------------------------------------------------
        // 【v1.1.0+ 新增表】
        // ------------------------------------------------------------------

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(64) PRIMARY KEY,
            svalue TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'login_logs' => "CREATE TABLE IF NOT EXISTS login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            ip VARCHAR(64) DEFAULT '',
            ua VARCHAR(255) DEFAULT '',
            result VARCHAR(32) DEFAULT '',
            detail VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_time (username, created_at),
            INDEX idx_ip_time (ip, created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL DEFAULT '',
            ip VARCHAR(64) NOT NULL DEFAULT '',
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_time (username, created_at),
            INDEX idx_ip_time (ip, created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'user_2fa' => "CREATE TABLE IF NOT EXISTS user_2fa (
            username VARCHAR(64) PRIMARY KEY,
            secret VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            backup_codes TEXT,
            confirmed_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // agents —— 代理商（分销商）额度与身份。
        //
        // 【列名以本表为准，控制器必须对齐】
        // 额度模型是「按用户上限」而不是「一个可分配的数字池」：
        //   max_users            可发展的下级用户数
        //   max_tunnels_per_user 每个下级最多能开多少条隧道
        //   max_traffic_per_user 每个下级的流量上限
        //   total_traffic_quota  该代理商名下的总流量池
        //   commission_rate      佣金比例（百分比）
        'agents' => "CREATE TABLE IF NOT EXISTS agents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) UNIQUE NOT NULL,
            display_name VARCHAR(64) DEFAULT '',
            max_users INT NOT NULL DEFAULT 50,
            max_tunnels_per_user INT NOT NULL DEFAULT 5,
            max_traffic_per_user BIGINT NOT NULL DEFAULT 0,
            total_traffic_quota BIGINT NOT NULL DEFAULT 0,
            commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            note VARCHAR(255) DEFAULT '',
            created_at DATE,
            INDEX idx_status (status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/**
 * 读取某张表实际存在的列名（小写、排序）。
 *
 * 【为什么需要这个】
 * 见下方 schemaFingerprint() 的说明。
 */
function schemaActualColumns(PDO $db, string $table): array {
    try {
        $st = $db->query("SHOW COLUMNS FROM `{$table}`");
        if (!$st) {
            return [];
        }
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $f = strtolower((string) ($row['Field'] ?? ''));
            if ($f !== '') {
                $cols[] = $f;
            }
        }
        sort($cols);
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 计算「期望的表结构」指纹 —— 用版本号 + 关键列清单一起做 sha1。
 *
 * ---------------------------------------------------------------------------
 * 【为什么不能只比版本号】（这是 v1.1.5 修掉的一个真实缺陷）
 *
 * 旧实现：缓存文件里只写一个数字 `2`，只要它 >= schemaTargetVersion() 就
 * 直接 return，**完全跳过补列探测**。
 *
 * 问题在于：那个数字只代表「代码曾经跑过一次迁移」，不代表「表结构现在
 * 真的是对的」。下面这些情况都会让表结构悄悄损坏，而版本号纹丝不动：
 *
 *   · 运维手工 DROP 了某一列（排查问题时的常见操作）
 *   · 从旧备份恢复数据库，而 storage/ 目录是新的
 *   · 换机迁移时只导了库、没清 storage/
 *   · ALTER 当时因权限不足静默失败（ltEnsureColumns 吞掉了异常）
 *
 * 一旦发生，节点心跳会永远返回 SCHEMA_NOT_READY，或者更糟 ——
 * 接口层 SELECT 一个不存在的列直接 500 白屏，而用户完全不知道为什么，
 * 因为「代码版本和缓存版本明明是对上的」。
 *
 * 实测复现：在 storage/schema.version = 2 的站点上执行
 *   ALTER TABLE nodes DROP COLUMN node_token
 * 之后无论怎么刷新，列都不会被补回来，心跳恒返回
 *   {"error":"SCHEMA_NOT_READY","message":"缺少 nodes.node_token"}
 *
 * 【修法】
 * 指纹 = sha1(版本号 + nodes/sessions 的实际列签名 + 期望列清单)。
 * 只要实际列与期望不符，指纹就对不上，迁移会强制重跑一遍。
 * 每请求多两次 SHOW COLUMNS —— 相比「表结构悄悄坏掉」的代价，完全值得。
 * 且 ensureSchema 进程内只跑一次，实际开销可忽略。
 * ---------------------------------------------------------------------------
 */
function schemaFingerprint(PDO $db): string {
    $expectNodes = [
        'protocols', 'node_token', 'last_seen', 'last_seen_ts', 'cpu', 'mem',
        'conn_count', 'probe_ok', 'probe_at',
    ];
    $expectSession = ['expires_at', 'last_active'];
    $expectUsers   = ['owner_agent'];

    $actualNodes   = schemaActualColumns($db, 'nodes');
    $actualSession = schemaActualColumns($db, 'sessions');
    $actualUsers   = schemaActualColumns($db, 'users');

    // 【v1.1.5 补】把「表是否存在」也纳入指纹。
    //   只比列的话，整张表被删（或从未建起来，比如首次部署仅导入了部分 SQL）
    //   时指纹不会有任何变化，快路径会一直跳过，表就永远建不出来。
    //
    //   注意：这里的表清单必须与 schemaTableDDL() 的键**完全一致**，
    //   否则新加入的表不会被纳入指纹，也就失去了自愈能力。
    $tables = [];
    foreach (array_keys(schemaTableDDL()) as $t) {
        $tables[] = $t . '=' . (schemaTableExists($db, $t) ? '1' : '0');
    }

    return sha1(implode('|', [
        'v' . schemaTargetVersion(),
        'T:' . implode(',', $tables),
        'N:' . implode(',', $actualNodes),
        'E:' . implode(',', $expectNodes),
        'S:' . implode(',', $actualSession),
        'F:' . implode(',', $expectSession),
        'U:' . implode(',', $actualUsers),
        'G:' . implode(',', $expectUsers),
    ]));
}

/**
 * 探测表是否存在（走 information_schema，比 SHOW TABLES LIKE 更可靠）。
 */
function schemaTableExists(PDO $db, string $table): bool {
    try {
        $st = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute([$table]);
        return ((int) $st->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return true; // 探测不了就假定存在，避免误判导致反复建表
    }
}

/**
 * 判断「期望的列」是否都已存在于实际列中。
 * 用于在指纹不匹配时，进一步判断是真的缺列，还是仅仅多了无关的列。
 */
function schemaColumnsMissing(array $actual, array $expected): array {
    $map = array_flip($actual);
    $miss = [];
    foreach ($expected as $c) {
        if (!isset($map[$c])) {
            $miss[] = $c;
        }
    }
    return $miss;
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
        // 1) 快路径：缓存指纹与当前「版本号 + 实际表结构」完全一致 → 什么都不做
        //
        //    【v1.1.5 修正】旧实现只比对版本号数字，导致表结构被破坏
        //    （手工删列 / 恢复旧备份 / ALTER 因权限失败）后永远不会自愈。
        //    现在缓存里存的是完整指纹，实际列与期望列对不上就会强制重跑。
        $cache = schemaCacheFile();
        $fingerprint = schemaFingerprint($db);
        if (is_file($cache)) {
            $cached = trim((string) @file_get_contents($cache));
            if ($cached === $fingerprint) {
                return;
            }
        }

        // 2) 目标列清单：col => DDL 片段
        $nodeCols = [
            'protocols'   => "VARCHAR(64) NOT NULL DEFAULT '[\"tcp\",\"udp\",\"http\",\"https\"]'",
            'node_token'  => "VARCHAR(128) NOT NULL DEFAULT ''",
            'last_seen'   => 'DATETIME NULL DEFAULT NULL',
            // 心跳绝对时间戳（Unix 秒）。
            // 存在的理由：离线判定若用 last_seen（DATETIME）+ PHP 的
            // strtotime()，会在「MySQL 用 UTC、PHP 用 Asia/Shanghai」的
            // 宝塔常见组合下恒定偏 8 小时 → 节点一直显示离线。
            // BIGINT 而非 INT：虽然 2038 前 INT 够用，但没理由留这个坑。
            'last_seen_ts' => 'BIGINT NULL DEFAULT NULL',
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
        // v1.1.5：代理商体系需要知道「这个用户归谁管」。
        // 老库没有这一列，补上后所有既有用户归属为空（= 平台直属），
        // 语义正确：它们本来就不属于任何代理商。
        $userCols = [
            'owner_agent' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ];

        // 3) 缺表先建表
        //
        //    【v1.1.5 扩展】原来只建 settings 一张表。但「表不存在」和
        //    「缺列」一样会让接口 500，而且同样属于「代码升级了、库没跟上」
        //    的典型场景。现在统一按清单补建，任何一张缺失都会自愈。
        //
        //    用 CREATE TABLE IF NOT EXISTS，已存在的表不受影响。
        $tableDDL = schemaTableDDL();
        $missingTables = [];
        foreach ($tableDDL as $tname => $ddl) {
            if (schemaTableExists($db, $tname)) {
                continue;
            }
            try {
                $db->exec($ddl);
            } catch (Throwable $e) {
                // 无 CREATE 权限等情况：记下来，但不阻断登录
            }
            if (!schemaTableExists($db, $tname)) {
                $missingTables[] = $tname;
            }
        }

        // 4) 逐列探测 + 补齐（返回仍然缺失的列）
        $stillMissing = array_merge(
            ltEnsureColumns($db, 'nodes', $nodeCols),
            ltEnsureColumns($db, 'sessions', $sessionCols),
            ltEnsureColumns($db, 'users', $userCols)
        );

        // 5) 历史数据修正：本次新增的列必须有合理默认值，否则界面读出来是空的
        try {
            $db->exec(
                "UPDATE nodes SET protocols = '[\"tcp\",\"udp\",\"http\",\"https\"]'
                  WHERE protocols IS NULL OR protocols = '' OR protocols = '[]'"
            );
        } catch (Throwable $e) {
        }

        // 6) 写缓存，下次请求走快路径
        //
        //    【关键】只有在「期望的列全部到位」时才写缓存。
        //    若还有列没补上（多半是无 ALTER 权限），绝不能把当前的（残缺）
        //    指纹写进去 —— 否则下次请求会判定「已是最新」直接跳过，
        //    于是这个残缺状态被永久固化，用户再也等不到自愈。
        //    不写缓存 = 每个请求都重试一次补列，一旦权限修好就自动恢复。
        $dir = __DIR__ . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            if (empty($stillMissing) && empty($missingTables)) {
                @file_put_contents($cache, schemaFingerprint($db));
                // 结构已完好，清掉可能残留的诊断标记
                @unlink($dir . '/schema.incomplete');
            } else {
                // 补列/建表没成功：写一个「诊断标记」供 /__health 与后台诊断读取
                @file_put_contents(
                    $dir . '/schema.incomplete',
                    json_encode([
                        'at'            => date('c'),
                        'missingCols'   => $stillMissing,
                        'missingTables' => $missingTables,
                        'hint'          => '数据库账号可能缺少 ALTER / CREATE 权限，请检查',
                    ], JSON_UNESCAPED_UNICODE)
                );
            }
        }
    } catch (Throwable $e) {
        // 兜底：绝不让迁移问题阻断登录
    }
}

/**
 * 逐列探测并补齐。单列失败不影响其它列。
 *
 * @return string[] 补列后**仍然缺失**的列名（正常情况为空数组）
 */
function ltEnsureColumns(PDO $db, string $table, array $cols): array {
    $failed = [];
    foreach ($cols as $col => $ddl) {
        try {
            $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            if ($st && $st->fetch()) {
                continue; // 已存在
            }
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
        } catch (Throwable $e) {
            // 单列失败继续，避免一列报错导致后续列全都不加。
            // 但要记下来 —— 这个信息决定「能不能写迁移缓存」。
            $failed[] = $table . '.' . $col;
        }
    }

    // 复核：ALTER 没抛异常也不代表列真的加上了（部分驱动/权限场景会静默失败），
    // 所以这里再探测一次，以实际结果为准。
    foreach ($cols as $col => $ddl) {
        if (in_array($table . '.' . $col, $failed, true)) {
            continue;
        }
        try {
            $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col));
            if (!$st || !$st->fetch()) {
                $failed[] = $table . '.' . $col;
            }
        } catch (Throwable $e) {
            $failed[] = $table . '.' . $col;
        }
    }

    return array_values(array_unique($failed));
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
