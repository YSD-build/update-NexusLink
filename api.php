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

/* ===========================================================================
 * 自动迁移
 * ---------------------------------------------------------------------------
 * 在线更新只覆盖文件、不导数据库。若老库缺 nodes.protocols 之类的列，
 * 下面的 SELECT 会直接 500，用户看到的是白屏而不是「请升级数据库」。
 * 所以这里在鉴权与路由之前先让 schema 自愈一次。失败也不抛异常。
 * =========================================================================== */
ensureSchema($db);

/*
 * 节点离线清扫。
 *
 * 放在这么靠前是为了让「在线/离线」在任何接口被访问时都尽量新鲜 ——
 * 用户打开总览页看到的节点状态不该是十分钟前的快照。
 * 函数内部有 30 秒限流，所以高频请求下实际开销接近于零。
 */
sweepOfflineNodes($db);

/* ==================== 公共工具 ==================== */
/*
 * 【为什么放在这么靠前】
 *
 * LIMITS 是 const、checkLen() / offlineClientTunnels() 是函数声明。
 * 下面的「初始化引导」接口在 requireAuth() 之前执行，而它要调用 checkLen()，
 * 所以这些定义必须出现在它上面。放在文件末尾会导致
 * "Undefined constant LIMITS" 致命错误 —— 而且只在初始化时才暴露出来。
 */

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

/* ==================== 协议能力 ==================== */

/**
 * 节点可声明的协议全集。
 * 顺序即界面上协议按钮的展示顺序，也决定了入库时的排序 —— 顺序固定，
 * 保证 ["tcp","http"] 和 ["http","tcp"] 存成同一个字符串，比对不会误判。
 */
const KNOWN_PROTOCOLS = ['tcp', 'udp', 'http', 'https'];

/**
 * 规范化协议数组：过滤非法值 + 去重 + 按固定顺序排序。
 *
 * @param mixed $raw 任意输入（数组 / JSON 字符串 / 逗号分隔字符串）
 * @return array 规范化后的协议名数组
 */
function normalizeProtocols($raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $set = [];
    foreach ($raw as $p) {
        $p = strtolower(trim((string) $p));
        if (in_array($p, KNOWN_PROTOCOLS, true)) {
            $set[$p] = true;
        }
    }
    return array_values(array_filter(KNOWN_PROTOCOLS, function ($p) use ($set) {
        return isset($set[$p]);
    }));
}

/** 把协议数组编码成数据库存储形式 */
function encodeProtocols(array $protocols): string
{
    return json_encode(array_values($protocols), JSON_UNESCAPED_SLASHES);
}

/** 把数据库里的 protocols 字段解成数组；解析失败时视为「四协议全支持」 */
function decodeProtocols(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return KNOWN_PROTOCOLS;
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return KNOWN_PROTOCOLS;
    }
    $norm = normalizeProtocols($arr);
    // 空数组说明数据是坏的，按「未知 = 全支持」处理，避免节点变得不可用
    return $norm ?: KNOWN_PROTOCOLS;
}

/** 生成节点心跳凭据 */
function newNodeToken(): string
{
    return 'nt_' . bin2hex(random_bytes(16));
}

/**
 * 清扫超时未心跳的节点，把它们的 status 置为 offline。
 *
 * 【为什么用「清扫」而不是「读取时判断」】
 * 如果在 GET /nodes 时临时按 last_seen 算一次"是不是算离线"，那么
 * status 字段就变成了永远停在 'online' 的装饰品 —— 任何直接读库的地方
 * （比如 dashboard 的在线节点计数、隧道的节点过滤）都会得到错误结论。
 * 让 status 成为真实、持久、唯一的状态来源，超时判断只在这一处发生。
 *
 * 【限流】
 * 每个请求都 UPDATE 一次太浪费（且会和行锁打架），所以用 storage 里的
 * 时间戳文件限流，默认 30 秒最多扫一次。
 */
function sweepOfflineNodes(PDO $db): int
{
    if (!hasColumn($db, 'nodes', 'last_seen')) {
        return 0; // 老库尚未迁移，不折腾
    }

    // 限流：30 秒一次
    $lock = __DIR__ . '/storage/sweep.lock';
    $now  = time();
    if (is_file($lock)) {
        $last = (int) @file_get_contents($lock);
        if ($last > 0 && $now - $last < 30) {
            return 0;
        }
    }
    @file_put_contents($lock, (string) $now);

    $timeout = (int) settingsGet($db, 'nodeOfflineSeconds', 90);
    if ($timeout < 30) {
        $timeout = 30;
    }
    if ($timeout > 3600) {
        $timeout = 3600;
    }

    try {
        $st = $db->prepare(
            "UPDATE nodes SET status = 'offline'
              WHERE status IN ('online','busy')
                AND (last_seen IS NULL OR last_seen < NOW() - INTERVAL ? SECOND)"
        );
        $st->execute([$timeout]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * 允许读写的设置项白名单及其默认值。
 *
 * 【为什么要有白名单】
 * 入参直接拼进 settings 表，任何未列出的键都不该被写入 ——
 * 否则调用方（哪怕是管理员）可以往表里塞任意 key，日积月累变成一张垃圾表。
 * 同时白名单也充当「合法的默认值定义」，读接口用它兜底，表里缺哪个键都能返回完整配置。
 *
 * 【位置】必须在最前面：初始化接口（requireAuth 之前）和 settingsGet() 都要用。
 */
const SETTING_DEFAULTS = [
    'title'              => '蓝天内网穿透',
    'webAddr'            => '0.0.0.0',
    'webPort'            => 8080,
    'sessionTimeout'     => 60,      // 分钟
    'allowRegister'      => false,
    'requireRealName'    => false,
    'defaultBandwidth'   => 16,      // Mbps
    'defaultTunnels'     => 5,
    'nodeOfflineSeconds' => 90,      // 心跳超时判定为离线的秒数
];

/**
 * 读取系统设置项（带进程内缓存）。
 *
 * 值以 JSON 编码存储，因此 "60" 会读回 int 60、'"蓝天"' 会读回 string、"true" 会读回 bool。
 * 表不存在或键缺失时返回 $default，调用方永远拿到可用值。
 */
function settingsGet(PDO $db, string $key, $default = null) {
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach ($db->query('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
                $all[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable $e) {
            $all = [];
        }
    }
    if (!array_key_exists($key, $all)) {
        return $default;
    }
    $v = json_decode((string) $all[$key], true);
    return json_last_error() === JSON_ERROR_NONE ? $v : $all[$key];
}

/* ==================== 节点主动探测（SSRF 防护） ==================== */

/**
 * 判断一个 IPv4 地址是否属于「不允许被主动探测」的保留段。
 *
 * 【为什么要拦】
 * 探测接口是管理员点一下、服务器主动去连接任意 host:port 的功能。
 * 如果不做限制，一旦管理员账号被盗（或本身不可信），攻击者就能把本机
 * 变成一个扫描内网的跳板：填写 127.0.0.1:6379 探 Redis、填 169.254.169.254
 * 读云厂商元数据服务拿临时凭据 —— 这是 SSRF 最经典的两种利用方式。
 *
 * 【拦哪些】
 *   0.0.0.0/8        本网络
 *   10.0.0.0/8       私有
 *   100.64.0.0/10    运营商级 NAT（CGNAT）
 *   127.0.0.0/8      回环
 *   169.254.0.0/16   链路本地（含云元数据 169.254.169.254）
 *   172.16.0.0/12    私有
 *   192.0.0.0/24     协议保留
 *   192.168.0.0/16   私有
 *   198.18.0.0/15    基准测试
 *   224.0.0.0/4      组播
 *   240.0.0.0/4      保留（含 255.255.255.255）
 *
 * @return bool true = 是内网/保留地址，应当拒绝
 */
function isBlockedProbeTarget(string $ip): bool
{
    // IPv6 一律拒绝：本项目节点全部是 IPv4，放行 v6 只会扩大攻击面
    if (strpos($ip, ':') !== false) {
        return true;
    }
    $n = ip2long($ip);
    if ($n === false) {
        return true; // 解析不了就不放行
    }
    $n = sprintf('%u', $n); // 统一成无符号字符串比较，避免 32 位环境下溢出

    $ranges = [
        ['0.0.0.0',      8],
        ['10.0.0.0',     8],
        ['100.64.0.0',  10],
        ['127.0.0.0',    8],
        ['169.254.0.0', 16],
        ['172.16.0.0',  12],
        ['192.0.0.0',   24],
        ['192.168.0.0', 16],
        ['198.18.0.0',  15],
        ['224.0.0.0',    4],
        ['240.0.0.0',    4],
    ];

    foreach ($ranges as [$base, $bits]) {
        $bn = sprintf('%u', ip2long($base));
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        $mask = sprintf('%u', $mask & 0xFFFFFFFF);
        if ((int) $n >> (32 - $bits) === (int) $bn >> (32 - $bits)) {
            return true;
        }
    }
    return false;
}

/**
 * 解析节点的 host 并校验是否可探测。
 *
 * 【域名解析也要挡】
 * 只检查字面量 IP 是不够的 —— 攻击者可以注册一个 owner 可控的域名，
 * 把它 A 记录解析到 127.0.0.1，字面量检查就绕过去了。
 * 所以域名必须先解析，拿到真实 IP 再做保留段判断。
 *
 * @return array{ok: bool, ip: string, reason: string}
 */
function resolveProbeTarget(string $host): array
{
    $host = trim($host);
    if ($host === '') {
        return ['ok' => false, 'ip' => '', 'reason' => '节点地址为空'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (isBlockedProbeTarget($host)) {
            return ['ok' => false, 'ip' => $host, 'reason' => '目标属于内网/保留地址段，已拒绝探测'];
        }
        return ['ok' => true, 'ip' => $host, 'reason' => ''];
    }

    // 单标签主机名（localhost、intranet 之类）不走域名格式校验 —— 它们的
    // 危险在于「解析结果不可控」，而不是格式。放开格式但强制走解析 + 保留段
    // 判断，比直接按格式拒绝更准确：真的解析到公网 IP 的合法内网域名不该被拦。
    $isSingleLabel = strpos($host, '.') === false;

    if (!$isSingleLabel
        && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)+$/', $host)) {
        return ['ok' => false, 'ip' => '', 'reason' => '节点地址格式不合法'];
    }

    $ips = @gethostbynamel($host);
    if (!$ips) {
        return ['ok' => false, 'ip' => '', 'reason' => '域名无法解析'];
    }
    // 解析出多个地址时，只要有一个落在保留段就整体拒绝：
    // 否则 DNS 轮询会让探测结果忽好忽坏，也让绕过变得可能
    foreach ($ips as $ip) {
        if (isBlockedProbeTarget($ip)) {
            return ['ok' => false, 'ip' => $ip, 'reason' => '域名解析到内网/保留地址段，已拒绝探测'];
        }
    }
    return ['ok' => true, 'ip' => $ips[0], 'reason' => ''];
}

/**
 * 对节点做一次 TCP 连通性探测，返回耗时。
 *
 * 【为什么用 stream_socket_client 而不是 curl】
 * 这里只想知道「端口能不能在一秒内完成 TCP 握手」，不需要 HTTP 语义。
 * curl 会发请求、等响应、跟重定向，既慢又会把节点上跑的非 HTTP 服务
 * （比如隧道端口）误判成失败。裸 TCP 握手才是「节点还活着吗」的正确问法。
 *
 * @return array{ok: bool, latencyMs: int, reason: string}
 */
function probeTcp(string $ip, int $port, float $timeout = 2.0): array
{
    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';

    $fp = @stream_socket_client(
        "tcp://{$ip}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($fp === false) {
        return [
            'ok'        => false,
            'latencyMs' => $ms,
            'reason'    => $errstr !== '' ? $errstr : '连接被拒绝或超时',
        ];
    }
    // 立刻关掉：探测不该在节点上留半开连接
    @stream_socket_shutdown($fp, STREAM_SHUT_RDWR);
    @fclose($fp);

    return ['ok' => true, 'latencyMs' => $ms, 'reason' => ''];
}

/* ==================== 初始化引导（公开接口） ==================== */
/*
 * 【为什么这两个接口必须在 requireAuth() 之前】
 *
 * 它们的存在前提就是「users 表是空的」，也就是说此刻系统里没有任何人能登录。
 * 如果把初始化接口也放进 requireAuth() 之后，就会出现死锁：
 *   想创建管理员 → 需要先登录 → 没有管理员可登录 → 永远进不去。
 * 这是本版本最高危的一处设计，改动此处请务必确认这一点。
 */

/** GET /v1/setup/status —— 是否已完成初始化（公开） */
if ($resource === 'setup' && $id === 'status' && $method === 'GET') {
    $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    respond([
        'success'     => true,
        'initialized' => $count > 0,
        'userCount'   => $count,
        // 老库缺 sessions.expires_at 时提示前端，避免用户看到「会话超时」设了没用
        'schemaReady' => hasColumn($db, 'nodes', 'protocols'),
    ]);
}

/** POST /v1/setup/admin —— 创建首个管理员（公开 + 重入保护） */
if ($resource === 'setup' && $id === 'admin' && $method === 'POST') {
    // ---- 重入保护第一层：users 表非空则一律拒绝 ----
    $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        respond([
            'success' => false,
            'error'   => 'ALREADY_INITIALIZED',
            'message' => '系统已完成初始化，如需新增账号请登录后到「用户管理」操作',
        ], 403);
    }

    $b = body();
    $u = trim((string) ($b['username'] ?? ''));
    $p = (string) ($b['password'] ?? '');
    $p2 = (string) ($b['passwordConfirm'] ?? $b['password_confirm'] ?? $p);
    $email = trim((string) ($b['email'] ?? ''));
    $realName = trim((string) ($b['realName'] ?? $b['real_name'] ?? ''));

    if ($u === '') {
        respond(['success' => false, 'error' => 'NAME_REQUIRED', 'message' => '请填写管理员账号'], 400);
    }
    checkLen($u, 'user_name', 'username');
    checkLen($email, 'email', 'email');

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
    if ($p !== $p2) {
        respond(['success' => false, 'error' => 'PASSWORD_MISMATCH', 'message' => '两次输入的密码不一致'], 400);
    }

    $hash = password_hash($p, PASSWORD_BCRYPT);

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
        respond(['success' => false, 'error' => 'CREATE_FAILED', 'message' => '创建失败：' . $e->getMessage()], 500);
    }

    // 建成即登录：直接下发会话，省掉一次「刚设完密码又要输一遍」
    $token = bin2hex(random_bytes(32));
    $timeout = settingsGet($db, 'sessionTimeout', 60);
    $timeout = is_numeric($timeout) ? (int) $timeout : 60;
    if ($timeout <= 0) {
        $timeout = 60;
    }
    $db->prepare(
        'INSERT INTO sessions (token, username, created_at, expires_at, last_active)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
    )->execute([$token, $u, $timeout]);

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

/* ==================== 节点心跳（公开接口，走节点自身凭据） ==================== */
/*
 * 【为什么在 requireAuth() 之前】
 * 心跳的发起方是「节点上的服务端进程」，它没有、也不该有控制台账号。
 * 它唯一的凭据是创建节点时下发的那串 node_token（X-Node-Token 头）。
 * 如果这个接口也要求管理员登录，节点就永远无法上报状态。
 */
if ($resource === 'nodes' && $id === 'heartbeat' && $method === 'POST') {
    $b = body();
    $nt = trim((string) (
        $_SERVER['HTTP_X_NODE_TOKEN']
        ?? $b['token']
        ?? $b['nodeToken']
        ?? ''
    ));

    if ($nt === '') {
        respond(['success' => false, 'error' => 'NODE_TOKEN_REQUIRED', 'message' => '缺少 X-Node-Token 请求头'], 401);
    }
    if (!hasColumn($db, 'nodes', 'node_token')) {
        respond(['success' => false, 'error' => 'SCHEMA_NOT_READY', 'message' => '数据库尚未完成迁移，缺少 nodes.node_token'], 500);
    }

    $st = $db->prepare('SELECT id, name FROM nodes WHERE node_token = ? LIMIT 1');
    $st->execute([$nt]);
    $node = $st->fetch();
    if (!$node) {
        respond(['success' => false, 'error' => 'INVALID_NODE_TOKEN'], 401);
    }

    $num = function ($v, $min = 0, $max = 100000) {
        $f = (float) $v;
        if ($f < $min) { $f = $min; }
        if ($f > $max) { $f = $max; }
        return $f;
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

    respond([
        'success'   => true,
        'node'      => $node['name'],
        'serverTime' => date('c'),
        'interval'  => max(10, (int) settingsGet($db, 'nodeOfflineSeconds', 90) / 3),
    ]);
}

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

    // 【修复】写入过期时间。旧实现只插 token + username，会话永久有效，
    // 「系统设置 → 会话超时」改了也没有任何作用。
    // 老库可能还没有 expires_at 列（尚未迁移），所以这里做一次能力探测。
    $timeout  = settingsGet($db, 'sessionTimeout', 60);
    $timeout  = is_numeric($timeout) ? (int) $timeout : 60;
    if ($timeout <= 0) {
        $timeout = 60;
    }
    if (hasColumn($db, 'sessions', 'expires_at')) {
        $db->prepare(
            'INSERT INTO sessions (token, username, created_at, expires_at, last_active)
             VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
        )->execute([$token, $u, $timeout]);
    } else {
        $db->prepare('INSERT INTO sessions (token, username) VALUES (?, ?)')->execute([$token, $u]);
    }

    respond([
        'success' => true,
        'token'   => $token,
        'expiresIn' => $timeout * 60,
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

/* ==================== 仪表盘 ==================== */

if ($resource === 'dashboard' && $id === 'stats') {
    $totalUsers    = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $onlineNodes   = (int) $db->query("SELECT COUNT(*) FROM nodes WHERE status = 'online'")->fetchColumn();
    $activeTunnels = (int) $db->query("SELECT COUNT(*) FROM proxies WHERE status = 'online'")->fetchColumn();
    $todayTraffic  = (int) $db->query('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM clients')->fetchColumn();

    $rows = $db->query(
        'SELECT stat_date, bytes_in, bytes_out FROM traffic_stats ORDER BY stat_date DESC LIMIT 7'
    )->fetchAll();

    // 【不再编造趋势】
    // 原实现在 traffic_stats 为空时，拿 clients 的累计总量除以 7，再乘一个
    // 「0.6 + (i % 3) * 0.2」的摆动系数，凭空造出 7 天曲线。这条曲线看着很专业，
    // 但它描述的事情从未发生 —— 运营照着它判断容量会得出完全错误的结论。
    // 现在如实返回空数组，由前端显示空态。
    $trendIsReal = (bool) $rows;

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
            // 前端据此决定画曲线还是画空态
            'trendIsReal'   => $trendIsReal,
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

        // ---- 节点存在性 + 协议能力校验 ----
        // 【为什么必须在端口冲突之前】
        // 否则「节点压根不存在」的请求会先撞上端口冲突，返回一个误导性的
        // PORT_CONFLICT，运营就会跑去改端口，而真正的问题是节点名写错了。
        // 校验顺序即报错优先级：先回答「这个节点能不能接」，再回答「端口占没占」。
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
                        'success'        => false,
                        'error'          => 'NODE_PROTOCOL_UNSUPPORTED',
                        'message'        => sprintf(
                            '节点「%s」未声明支持 %s，无法承接该隧道',
                            $node['name'],
                            strtoupper($type)
                        ),
                        'nodeProtocols'  => $nodeProtos,
                    ], 403);
                }
            }
        }

        // 端口冲突校验（同类型下端口唯一）
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
    // 心跳接口走独立的节点凭据鉴权，必须放在这里之前
    if ($id === 'heartbeat') {
        // 见下方「节点心跳」段
    } elseif ($method !== 'GET') {
        // 读所有人可用；增删改节点影响全局路由，锁定为管理员
        requireRole('admin');
    }

    if ($method === 'GET' && $id === null) {
        // 老库可能缺这些列（尚未迁移），按列存在性拼 SQL，避免整表查询 500
        $hasNew = hasColumn($db, 'nodes', 'protocols');
        $cols = 'id, name, host, port, status, clients, tunnels,
                 max_tunnels AS maxTunnels, region, version';
        $cols .= $hasNew
            ? ', protocols, last_seen AS lastSeen, cpu, mem, conn_count AS connCount, probe_ok AS probeOk, probe_at AS probeAt'
            : ", '[\"tcp\",\"udp\",\"http\",\"https\"]' AS protocols, NULL AS lastSeen,
               0 AS cpu, 0 AS mem, 0 AS connCount, 0 AS probeOk, NULL AS probeAt";

        $rows = $db->query("SELECT {$cols} FROM nodes ORDER BY id")->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
            foreach (['port', 'clients', 'tunnels', 'maxTunnels', 'connCount'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['cpu']      = (float) $r['cpu'];
            $r['mem']      = (float) $r['mem'];
            $r['protocols'] = decodeProtocols($r['protocols'] ?? null);
            $r['probeOk']  = (bool) $r['probeOk'];
            // node_token 永不回显：它是节点的长期凭据，泄露即可被用来冒充节点上报。
            // 只在创建时一次性返回明文，之后无处可查（忘了就轮换）。
        }
        unset($r);
        respond(['success' => true, 'nodes' => $rows]);
    }

    // POST /nodes/{id}/probe —— 主动探测节点连通性（仅管理员）
    //
    // 【为什么这段必须放在「新建节点」之前】
    // 下面有个不分 id 的 `if ($method === 'POST')` 分支负责建节点。
    // PHP 是从上往下执行、命中即 return 的，如果把探测放在它后面，
    // POST /nodes/3/probe 会先被建节点分支吃掉，那里读不到 name 就返回
    // NAME_REQUIRED —— 看起来像「参数校验失败」，实际是路由被截胡。
    // 所有带 {id}/{action} 的具体路由，都要排在同名资源的通用 POST 前面。
    if ($method === 'POST' && $id !== null && $id !== 'heartbeat' && $action === 'probe') {
        requireRole('admin');

        $st = $db->prepare('SELECT id, name, host, port FROM nodes WHERE id = ?');
        $st->execute([(int) $id]);
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
        $why = $tgt['reason'];

        if ($tgt['ok']) {
            // 整体别让 PHP 卡住：2 秒连接 + 少量收尾，给 5 秒上限足够
            @set_time_limit(5);
            $r   = probeTcp($tgt['ip'], $port, 2.0);
            $ok  = $r['ok'];
            $ms  = $r['latencyMs'];
            $why = $r['reason'];
        }

        // 落库探测结果。注意这里【不】改 status：
        // status 是「心跳是否还在」的事实，探测只回答「端口通不通」，
        // 两者混在一起会让离线判定失去唯一来源。前端分开展示。
        if (hasColumn($db, 'nodes', 'probe_ok')) {
            $db->prepare('UPDATE nodes SET probe_ok = ?, probe_at = NOW() WHERE id = ?')
               ->execute([$ok ? 1 : 0, (int) $id]);
        }

        respond([
            'success'   => $ok,
            'node'      => $node['name'],
            'target'    => $tgt['ip'] !== '' ? $tgt['ip'] . ':' . $port : $node['host'] . ':' . $port,
            'latencyMs' => $ms,
            'message'   => $ok ? '节点端口可达' : ('探测失败：' . ($why !== '' ? $why : '不可达')),
        ], $ok ? 200 : 502);
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

        respond([
            'success'   => true,
            'id'        => $db->lastInsertId(),
            'protocols' => $proto,
            // 明文 node_token 只在这一刻出现，请立即交到节点手上
            'nodeToken' => $token,
            'notice'    => $token !== ''
                ? '请立即保存该 nodeToken，它不会再次显示'
                : '当前数据库缺少 protocols/node_token 字段，请检查自动迁移是否成功',
        ]);
    }

    // PUT /nodes/{id} —— 更新节点信息 / 状态 / 协议能力
    if ($method === 'PUT' && $id !== null) {
        $b = body();

        $st = $db->prepare('SELECT id, name FROM nodes WHERE id = ?');
        $st->execute([(int) $id]);
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
                        '该节点上还有 %d 条隧道使用了被取消的协议，请先迁移或删除它们',
                        count($conflicts)
                    ),
                    'conflicts' => $conflicts,
                ], 403);
            }
        }

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

        if (array_key_exists('protocols', $b) && hasColumn($db, 'nodes', 'protocols')) {
            $fields[] = 'protocols = ?';
            $params[] = encodeProtocols(normalizeProtocols($b['protocols']));
        }

        if (array_key_exists('rotateToken', $b) && $b['rotateToken'] && hasColumn($db, 'nodes', 'node_token')) {
            $fields[] = 'node_token = ?';
            $params[] = newNodeToken();
        }

        if ($fields) {
            $params[] = (int) $id;
            $db->prepare('UPDATE nodes SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        // 轮换时把新 token 回显一次
        $resp = ['success' => true];
        if (array_key_exists('rotateToken', $b) && $b['rotateToken']) {
            $nt = $db->prepare('SELECT node_token FROM nodes WHERE id = ?');
            $nt->execute([(int) $id]);
            $resp['nodeToken'] = (string) $nt->fetchColumn();
            $resp['notice']    = 'nodeToken 已轮换，旧凭据立即失效，请更新节点配置';
        }
        respond($resp);
    }

    if ($method === 'DELETE' && $id !== null) {
        $st = $db->prepare('SELECT name FROM nodes WHERE id = ?');
        $st->execute([(int) $id]);
        $nm = $st->fetchColumn();

        $db->prepare('DELETE FROM nodes WHERE id = ?')->execute([(int) $id]);

        // 节点被移除后，其上隧道的 node_name 变成悬空引用，一并下线
        $affected = 0;
        if ($nm) {
            $u = $db->prepare("UPDATE proxies SET status = 'offline' WHERE node_name = ?");
            $u->execute([$nm]);
            $affected = $u->rowCount();
        }
        respond(['success' => true, 'tunnelsOfflined' => $affected]);
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
            'hasUpdate'      => $remote !== '' && $remote !== $current,
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
        // 【修复】原实现返回的是一份硬编码数组，PUT 则直接 respond success 什么都不写。
        // 表现为「保存成功 → 刷新 → 全部还原」，用户以为是自己没点到。
        // 现在真实读表，并与默认值合并，保证任何时候都返回完整字段集。
        $out = SETTING_DEFAULTS;
        try {
            foreach ($db->query('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
                if (!array_key_exists($r['skey'], SETTING_DEFAULTS)) {
                    continue; // 表里的历史脏键不返回
                }
                $v = json_decode((string) $r['svalue'], true);
                $out[$r['skey']] = json_last_error() === JSON_ERROR_NONE ? $v : $r['svalue'];
            }
        } catch (Throwable $e) {
            // 表不存在时直接返回默认值，不报错
        }
        respond(['success' => true, 'settings' => $out]);
    }

    if ($method === 'PUT') {
        $b = body();
        if (!is_array($b) || !$b) {
            respond(['success' => false, 'error' => 'EMPTY_BODY', 'message' => '没有需要保存的设置项'], 400);
        }

        $saved = [];
        $stmt = $db->prepare(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );

        foreach ($b as $k => $v) {
            if (!array_key_exists($k, SETTING_DEFAULTS)) {
                continue; // 白名单外的键静默忽略
            }

            // 类型校正：前端传的都是字符串/数字混用，这里按默认值类型归一化，
            // 避免 sessionTimeout 被存成 "60 分钟" 之类的脏值
            $def = SETTING_DEFAULTS[$k];
            if (is_int($def)) {
                $v = (int) $v;
            } elseif (is_bool($def)) {
                $v = in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
            } else {
                $v = (string) $v;
            }

            // 业务边界校验
            if ($k === 'sessionTimeout' && ($v < 1 || $v > 10080)) {
                respond(['success' => false, 'error' => 'INVALID_VALUE', 'message' => '会话超时需在 1-10080 分钟之间'], 400);
            }
            if ($k === 'webPort' && ($v < 1 || $v > 65535)) {
                respond(['success' => false, 'error' => 'INVALID_VALUE', 'message' => '端口需在 1-65535 之间'], 400);
            }
            if ($k === 'nodeOfflineSeconds' && ($v < 30 || $v > 3600)) {
                respond(['success' => false, 'error' => 'INVALID_VALUE', 'message' => '心跳超时需在 30-3600 秒之间'], 400);
            }
            if ($k === 'title' && mb_strlen($v) > 64) {
                respond(['success' => false, 'error' => 'INVALID_VALUE', 'message' => '站点名称不超过 64 字'], 400);
            }

            $stmt->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
            $saved[$k] = $v;
        }

        respond(['success' => true, 'saved' => $saved, 'count' => count($saved)]);
    }
}

/* ==================== 会话管理 ==================== */

if ($resource === 'sessions') {
    requireRole('admin');

    // GET /sessions —— 当前在线会话（便于排查「谁还连着」）
    if ($method === 'GET') {
        if (!hasColumn($db, 'sessions', 'expires_at')) {
            $rows = $db->query(
                'SELECT token, username, created_at AS createdAt,
                        NULL AS expiresAt, NULL AS lastActive
                   FROM sessions ORDER BY created_at DESC LIMIT 200'
            )->fetchAll();
        } else {
            $rows = $db->query(
                'SELECT token, username, created_at AS createdAt,
                        expires_at AS expiresAt, last_active AS lastActive
                   FROM sessions ORDER BY created_at DESC LIMIT 200'
            )->fetchAll();
        }
        // 只回显 token 前缀：完整 token 等同凭据，不该在列表里泄露
        foreach ($rows as &$r) {
            $r['token'] = substr((string) $r['token'], 0, 8) . '…';
        }
        unset($r);
        respond(['success' => true, 'sessions' => $rows]);
    }

    // DELETE /sessions —— 清空全部会话（强制所有人重新登录）
    // 前端「危险操作 → 清空会话」之前只是弹个提示让用户自己去数据库执行 TRUNCATE，
    // 现在改成真实接口。
    if ($method === 'DELETE') {
        $me = currentPrincipal();
        $before = (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();

        // 默认保留自己的会话，避免管理员点了按钮把自己也踢出去
        $keepSelf = !isset($_GET['all']) || $_GET['all'] !== '1';
        $ownToken = bearerToken();

        if ($keepSelf && $ownToken !== '') {
            $db->prepare('DELETE FROM sessions WHERE token <> ?')->execute([$ownToken]);
        } else {
            $db->exec('DELETE FROM sessions');
        }

        $after = (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
        respond([
            'success'   => true,
            'cleared'   => max(0, $before - $after),
            'remaining' => $after,
            'keptSelf'  => $keepSelf,
        ]);
    }
}

/* ==================== 系统设置（旧位置已迁移至上方） ==================== */

respond(['success' => false, 'error' => 'NOT_FOUND'], 404);
