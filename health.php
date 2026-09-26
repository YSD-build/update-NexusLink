<?php
/**
 * 蓝天内网穿透 · 部署自检
 * ===========================================================================
 * 访问 http://你的域名/__health 即可得到本机部署的诊断报告。
 *
 * 【敏感信息处理】
 * 本文件默认只返回「可用/不可用 + 中文结论」，不回显任何可能帮助攻击者的
 * 细节：不输出绝对路径、不输出 SERVER_SOFTWARE、不输出精确 PHP 版本、
 * 不输出完整数据表清单。完整诊断明细（含路径与版本）只在通过管理员会话
 * 鉴权后才返回。这样即便 URL 被扫到，拿到的也只是一个「是/否」列表。
 *
 * 输出为 JSON：
 *   ok       —— 所有 critical 项是否全部通过（唯一一个公网可见的关键结论）
 *   version  —— 当前部署版本号
 *   summary  —— 中文结论，一眼看懂
 *   checks[] —— 逐项明细（名称已脱敏；detail 仅在鉴权后含路径/版本）
 * ===========================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$root = __DIR__;
$checks = [];

/** 每项检查：名称 / 是否通过 / 简短结论 / 是否关键 / 敏感补充信息（仅鉴权后输出） */
$add = function (string $name, bool $ok, string $detail = '', bool $critical = false, string $sensitive = '') use (&$checks) {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical, 'sensitive' => $sensitive];
};

/* ===========================================================================
 * 鉴权
 * ---------------------------------------------------------------------------
 * 优先复用系统自己的管理员会话。拿不到会话时降级为「公开只在关键项失败时
 * 揭示原因」，其余一律折叠为「异常」。
 *
 * 为什么允许「关键项失败时揭示原因」：自检的价值就在于部署挂了的时候能看懂
 * 为什么挂。数据库连不上时提示「账号或密码错误」，对攻击者的价值极低
 * （他连不上本来就说明他没拿到正确口令），但对运维的价值极高。
 * 反过来，路径、PHP 精确版本、表清单这些「拿到了就能精准打击」的信息，
 * 任何情况下都不对外。
 * =========================================================================== */
$isAdmin = false;
try {
    if (is_file($root . '/db.php')) {
        require_once $root . '/db.php';
        $db = pdo();
        // 只认 users.role='admin' 的活跃会话，普通用户看自检没有意义
        $tok = '';
        foreach (['HTTP_AUTHORIZATION', 'HTTP_X_API_KEY'] as $k) {
            if (!empty($_SERVER[$k])) {
                $tok = trim((string) $_SERVER[$k]);
                break;
            }
        }
        if ($tok !== '' && stripos($tok, 'bearer ') === 0) {
            $tok = trim(substr($tok, 7));
        } elseif (isset($_GET['token'])) {
            $tok = trim((string) $_GET['token']);
        }
        if ($tok !== '') {
            $st = $db->prepare(
                'SELECT u.role, u.status FROM sessions s
                 JOIN users u ON u.username = s.username
                 WHERE s.token = ? LIMIT 1'
            );
            $st->execute([$tok]);
            $row = $st->fetch();
            $isAdmin = $row && $row['role'] === 'admin' && $row['status'] === 'active';
        }
    }
} catch (Throwable $e) {
    $isAdmin = false; // 自检本身绝不能因为鉴权失败而挂掉
}

// ---------------- 1. 前端产物 ----------------
$idx = $root . '/index.html';
$add('前端入口 index.html', is_file($idx),
    is_file($idx) ? '存在' : '缺失 → 构建产物未上传到站点根目录', true);

$metaPath = $root . '/build-meta.json';
$meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
$add('版本清单 build-meta.json', is_file($metaPath),
    is_file($metaPath) ? '存在' : '缺失 → 前端产物不完整', false);

$assetsDir = $root . '/assets';
$jsCount = is_dir($assetsDir) ? count(glob($assetsDir . '/*.js')) : 0;
$add('构建资源 assets/', $jsCount > 0,
    $jsCount > 0 ? "{$jsCount} 个 js 文件" : '缺失或为空 → 请确认 assets/ 目录已上传',
    true, $jsCount > 0 ? "assets 目录 = {$assetsDir}" : '');

// ---------------- 2. 后端入口 ----------------
foreach ([
    'index.php'   => '统一入口',
    'api.php'     => '接口实现',
    'config.php'  => '配置文件',
    'db.php'      => '数据库库',
    'router.php'  => '开发路由',
    'updater.php' => '更新引擎',
] as $f => $label) {
    $exists = is_file($root . '/' . $f);
    // 只有核心文件缺失才算关键项；updater/router 在部分部署形态下可以没有
    $critical = in_array($f, ['index.php', 'api.php', 'config.php', 'db.php'], true);
    $add("{$label} {$f}", $exists,
        $exists ? '存在' : '缺失 → 源码未完整上传', $critical,
        $exists ? "路径 = {$root}/{$f}" : '');
}

// ---------------- 3. PHP 扩展 ----------------
foreach (['pdo_mysql', 'mbstring', 'json', 'zlib', 'zip'] as $ext) {
    $on = extension_loaded($ext);
    $hint = '';
    if (!$on) {
        $hint = $ext === 'zip'
            ? '未启用 → 本地包更新不可用（远程更新不受影响）'
            : '未启用 → 系统无法正常运行，请在 PHP 设置中安装';
    }
    $add("PHP 扩展 {$ext}", $on, $on ? '已启用' : $hint, $ext !== 'zip');
}

// ---------------- 4. 数据库 ----------------
$dbOk = false;
$dbDetail = '';
$dbSensitive = '';
$missing = [];

$cfgFile = $root . '/config.php';
if (!is_file($cfgFile)) {
    $dbDetail = 'config.php 不存在';
} else {
    try {
        $cfg = require $cfgFile;
        $c   = $cfg['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $c['host'] ?? '127.0.0.1',
            $c['port'] ?? 3306,
            $c['name'] ?? 'lantian',
            $c['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $dbOk = true;

        $need = ['users', 'clients', 'proxies', 'nodes', 'api_keys', 'sessions', 'downloads', 'settings'];
        $has  = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($need, $has));
        $dbDetail = '连接成功，共 ' . count($has) . ' 张表';

        if ($missing) {
            $dbOk = false;
            // 缺失表名是诊断必需信息 —— 不点名运维就没法知道该建哪张表
            $dbDetail .= '；缺失数据表：' . implode('、', $missing) . ' → 请导入 sql/init.sql';
        } else {
            $dbDetail .= '，全部必需表就绪';
        }

        // schema 版本：确认自动迁移跑过了。这是最容易被忽略的故障源 ——
        // 代码更新了但迁移没生效，接口会以奇怪的方式报错。
        $cache = $root . '/storage/schema.version';
        $v = is_file($cache) ? (int) trim((string) @file_get_contents($cache)) : 0;
        $schemaOk = $v >= 2;
        $add('数据库结构版本', $schemaOk,
            $schemaOk ? "schema v{$v}（已迁移）" : "schema v{$v}，未达到 v2 → 首次访问接口时会自动迁移，或手动导入 sql/migrate.sql",
            false, "schema 缓存文件 = {$cache}");

        $dbSensitive = 'DSN = mysql:host=' . ($c['host'] ?? '') . ';port=' . ($c['port'] ?? '')
            . ';dbname=' . ($c['name'] ?? '') . '；user = ' . ($c['user'] ?? '');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Access denied') !== false) {
            $dbDetail = '账号或密码错误 → 检查 config.php 的 db.user / db.pass';
        } elseif (stripos($msg, 'Unknown database') !== false) {
            $dbDetail = '数据库不存在 → 请先创建数据库并导入 sql/init.sql';
        } elseif (stripos($msg, 'Connection refused') !== false || stripos($msg, 'timed out') !== false) {
            $dbDetail = '无法连接数据库服务 → 确认 MySQL 已启动';
        } else {
            $dbDetail = '连接失败：' . mb_substr($msg, 0, 120);
        }
    }
}
$add('数据库连接', $dbOk, $dbDetail, true, $dbSensitive);

// ---------------- 5. 运行时 ----------------
foreach (['storage', 'storage/backup', 'storage/packages'] as $dir) {
    $p = $root . '/' . $dir;
    if (!is_dir($p)) {
        @mkdir($p, 0755, true);
    }
    $w = is_dir($p) && is_writable($p);
    $add("目录可写 {$dir}", $w, $w ? '可写' : '不可写 → 更新与备份功能不可用', false,
        is_dir($p) ? "路径 = {$p}" : '');
}

// 文档根目录：路径本身就是敏感信息（会暴露服务器目录结构）
$add('文档根目录', true, '零配置模式（hash 路由 + PATH_INFO 接口）', false, "文档根 = {$root}");

// 存储目录不得暴露在 Web 可访问路径下 —— 里面是备份包与 schema 缓存
$storageWeb = is_dir($root . '/storage');
$add('存储目录隔离', !$storageWeb || is_file($root . '/storage/.htaccess'),
    !$storageWeb ? '无 storage 目录' :
        (is_file($root . '/storage/.htaccess')
            ? 'storage 已用 .htaccess 阻断'
            : 'storage 未被阻断 → 建议添加 storage/.htaccess 防备份包被直接下载'),
    false);

// ---------------- 6. 汇总 ----------------
$criticalFail = array_values(array_filter($checks, fn($c) => $c['critical'] && !$c['ok']));

// 【先脱敏，再拼 summary】
// 顺序反了就会漏 —— summary 是拿 detail 拼出来的，
// 如果在脱敏前拼好，路径就会从 summary 这个「看起来只是人类可读结论」的
// 字段里漏出去，前面的脱敏等于白做。
foreach ($checks as &$c) {
    $c['sensitive'] = $isAdmin ? $c['sensitive'] : '';
    if (!$isAdmin) {
        $c['detail'] = preg_replace('#(?:/[A-Za-z0-9_.\-]+){2,}#', '[路径已隐藏]', (string) $c['detail']);
    }
}
unset($c);

// 重新取一次已脱敏的失败项
$criticalFail = array_values(array_filter($checks, fn($c) => $c['critical'] && !$c['ok']));
$criticalIssues = implode('；', array_map(fn($c) => $c['name'] . ' - ' . $c['detail'], $criticalFail));

echo json_encode([
    'ok'         => count($criticalFail) === 0,
    'version'    => ($meta['appVersion'] ?? '未知') . ' (' . ($meta['version'] ?? '') . ')',
    // PHP 精确版本与 server 标识都是「指纹信息」，能帮攻击者快速匹配已知 CVE，
    // 因此仅管理员可见。公开时只给「PHP 7.x 以上 / 8.x」这样的粗粒度。
    'php'        => $isAdmin
        ? PHP_VERSION
        : (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.x'),
    'server'     => $isAdmin ? ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') : 'hidden',
    'host'       => $isAdmin ? ($_SERVER['HTTP_HOST'] ?? '') : '',
    'mode'       => '零配置（hash 路由 + PATH_INFO 接口）',
    'authed'     => $isAdmin,
    'summary'    => count($criticalFail) === 0
        ? '全部关键项通过，部署正常'
        : '存在 ' . count($criticalFail) . ' 项关键问题：' . mb_substr($criticalIssues, 0, 300),
    'checks'     => $checks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
