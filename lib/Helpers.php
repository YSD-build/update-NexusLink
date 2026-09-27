<?php
/**
 * 蓝天内网穿透 · 通用工具函数
 * ===========================================================================
 * 从原 api.php 抽出的与业务无关的纯工具。
 *
 * 【关于函数名冲突】
 * 原 api.php 里的这些函数是全局函数（checkLen / normalizeProtocols ...），
 * 控制器迁移期间两边可能同时存在。所以这里每一个都用 function_exists
 * 包裹 —— 谁先定义谁生效，且不会因为重复定义触发致命错误。
 * 等全部控制器迁移完毕后，api.php 里的旧定义会被删除，只剩这里的。
 * ===========================================================================
 */

if (defined('LT_HELPERS_LOADED')) {
    return;
}
define('LT_HELPERS_LOADED', 1);

/** 字段长度上限，与 sql/init.sql 中各 varchar 定义保持一致 */
if (!defined('LIMITS')) {
    define('LIMITS', [
        'client_name'   => 64,
        'client_token'  => 128,
        'proxy_name'    => 64,
        'node_name'     => 64,
        'user_name'     => 64,
        'api_key'       => 128,
        'email'         => 128,
        'custom_domain' => 190,
    ]);
}

/**
 * 校验字符串字段长度，超限直接返回 400 并说明原因。
 * 避免超长输入穿透到 MySQL 层触发 500，让调用方拿到可读错误。
 */
if (!function_exists('checkLen')) {
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
}

/** 下线指定客户端的全部隧道，并标记为断开 */
if (!function_exists('offlineClientTunnels')) {
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
}

/* ==================== 协议能力 ==================== */

if (!defined('KNOWN_PROTOCOLS')) {
    /**
     * 节点可声明的协议全集。
     * 顺序即界面上协议按钮的展示顺序，也决定了入库时的排序 —— 顺序固定，
     * 保证 ["tcp","http"] 和 ["http","tcp"] 存成同一个字符串，比对不会误判。
     */
    define('KNOWN_PROTOCOLS', ['tcp', 'udp', 'http', 'https']);
}

if (!function_exists('normalizeProtocols')) {
    /**
     * 规范化协议数组：过滤非法值 + 去重 + 按固定顺序排序。
     *
     * @param mixed $raw 任意输入（数组 / JSON 字符串 / 逗号分隔字符串）
     * @return array 规范化后的协议名数组
     */
    function normalizeProtocols($raw): array
    {
        if (is_string($raw)) {
            $t = trim($raw);
            if ($t === '') {
                return [];
            }
            $j = json_decode($t, true);
            $raw = is_array($j) ? $j : array_map('trim', explode(',', $t));
        }
        if (!is_array($raw)) {
            return [];
        }
        $set = [];
        foreach ($raw as $v) {
            $v = strtolower(trim((string) $v));
            if (in_array($v, KNOWN_PROTOCOLS, true)) {
                $set[$v] = true;
            }
        }
        // 按 KNOWN_PROTOCOLS 的固定顺序输出，保证入库字符串稳定
        $out = [];
        foreach (KNOWN_PROTOCOLS as $p) {
            if (isset($set[$p])) {
                $out[] = $p;
            }
        }
        return $out;
    }
}

if (!function_exists('encodeProtocols')) {
    /** 把协议数组编码成数据库存储形式 */
    function encodeProtocols(array $arr): string
    {
        return json_encode(array_values($arr), JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('decodeProtocols')) {
    /** 把数据库里的 protocols 字段解成数组；解析失败时视为「四协议全支持」 */
    function decodeProtocols($raw): array
    {
        if (is_array($raw)) {
            return normalizeProtocols($raw);
        }
        $s = trim((string) $raw);
        if ($s === '' || $s === '[]') {
            return KNOWN_PROTOCOLS;
        }
        $j = json_decode($s, true);
        if (!is_array($j) || empty($j)) {
            return KNOWN_PROTOCOLS;
        }
        $p = normalizeProtocols($j);
        return empty($p) ? KNOWN_PROTOCOLS : $p;
    }
}

if (!function_exists('newNodeToken')) {
    /** 生成节点心跳凭据 */
    function newNodeToken(): string
    {
        return 'nt_' . bin2hex(random_bytes(16));
    }
}

/* ==================== 日志 ==================== */

if (!function_exists('ltLog')) {
    /**
     * 写一条结构化日志到 storage/logs/。
     *
     * 【为什么不用 error_log】
     * 线上是宝塔面板，PHP 错误日志和站点日志混在一起，排查时噪音极大。
     * 单独落盘按天分文件，且只记我们关心的字段。
     *
     * 只在 debug 模式或显式调用时写入 —— 生产环境高频写盘会拖慢接口。
     * 日志写入失败绝不影响请求（@ 抑制）。
     *
     * @param string $channel 渠道名（如 'auth' / 'update' / 'response'）
     */
    function ltLog(string $channel, array $data = []): void
    {
        try {
            $dir = __DIR__ . '/../storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $line = json_encode([
                'at'   => date('c'),
                'ch'   => $channel,
                'ip'   => function_exists('Req') && class_exists('Req') ? Req::ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            @file_put_contents(
                $dir . '/' . $channel . '-' . date('Y-m-d') . '.log',
                $line . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable $e) {
            // 日志失败绝不影响业务
        }
    }
}

/* ==================== 数据库 ==================== */

if (!function_exists('ltDb')) {
    /**
     * 取当前请求的 PDO 连接。
     *
     * 【为什么不直接用全局 $db】
     * 原 api.php 里是 `$db = pdo();` 一行，然后在两千行里到处引用 $db。
     * 拆到控制器后，每个方法内部引用一个「上层的局部变量」是隐式的、
     * 不可见的依赖 —— 静态分析和 IDE 都追踪不到它从哪来。
     * 包装成函数后依赖变成显式的：调用者一眼能看到「这里要连数据库」。
     * pdo() 内部已有单例，这里不需要再缓存。
     */
    function ltDb(): PDO
    {
        return pdo();
    }
}

/* ==================== 数组 / 输出辅助 ==================== */

if (!function_exists('arrGet')) {
    /** 安全取数组值（支持默认值） */
    function arrGet(array $a, string $key, $default = null)
    {
        return array_key_exists($key, $a) ? $a[$key] : $default;
    }
}

if (!function_exists('clamp')) {
    /** 把数值限制在 [min, max] 内 */
    function clamp($v, $min, $max)
    {
        $f = (float) $v;
        if ($f < $min) { $f = $min; }
        if ($f > $max) { $f = $max; }
        return $f;
    }
}

if (!function_exists('ltTrim')) {
    /**
     * 去首尾空白（含全角空格 U+3000）。
     *
     * 【为什么不叫 mb_trim】
     * 有些 PHP 发行版/扩展里已存在 mb_trim，重名会致命错误。
     * 用项目前缀 lt 规避。
     */
    function ltTrim(string $s): string
    {
        return preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $s) ?? trim($s);
    }
}
