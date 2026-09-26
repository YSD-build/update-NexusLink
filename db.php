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
    $st = $db->prepare(
        'SELECT s.username, u.role, u.status
           FROM sessions s
           JOIN users u ON u.username = s.username
          WHERE s.token = ?'
    );
    $st->execute([$token]);
    $row = $st->fetch();
    if ($row) {
        if (($row['status'] ?? 'active') !== 'active') {
            respond(['success' => false, 'error' => 'ACCOUNT_DISABLED'], 403);
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
