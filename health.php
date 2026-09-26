<?php
/**
 * 蓝天内网穿透 · 部署自检
 * ===========================================================================
 * 访问 http://你的域名/__health 即可得到本机部署的完整诊断报告。
 *
 * 设计目标：把「部署失败」从猜测变成一次查询。
 * 输出为 JSON，包含：
 *   - 前端产物：index.html / build-meta.json / assets 下的 JS 数量
 *   - 后端入口：index.php / api.php / config.php / db.php / updater.php
 *   - PHP 扩展：pdo_mysql / mbstring / json / zlib / zip
 *   - 数据库：连通性 + 逐表存在性（缺表时直接点名）
 *   - 运行时：目录可写性、解析后的文档根目录、当前部署版本号
 *
 * 返回体关键字段：
 *   ok       —— 所有 critical 项是否全部通过
 *   version  —— 当前部署的构建版本（来自 build-meta.json）
 *   summary  —— 中文结论，一眼看懂
 *   checks[] —— 逐项明细
 *
 * 本文件不需要登录即可访问，也不包含任何敏感信息（不回显数据库口令）。
 * ===========================================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$root = __DIR__;
$checks = [];

$add = function (string $name, bool $ok, string $detail = '', bool $critical = false) use (&$checks) {
    $checks[] = compact('name', 'ok', 'detail', 'critical');
};

// ---------------- 1. 前端产物 ----------------
$idx = $root . '/index.html';
$add('前端入口 index.html', is_file($idx), is_file($idx) ? '存在' : '缺失 → 构建产物未上传到站点根目录', true);

$metaPath = $root . '/build-meta.json';
$meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
$add('版本清单 build-meta.json', is_file($metaPath), is_file($metaPath) ? '存在' : '缺失 → 前端产物不完整', false);

$assetsDir = $root . '/assets';
$jsCount = is_dir($assetsDir) ? count(glob($assetsDir . '/*.js')) : 0;
$add('构建资源 assets/', $jsCount > 0, $jsCount > 0 ? "{$jsCount} 个 js 文件" : '缺失或为空 → 请确认 assets/ 目录已上传', true);

// ---------------- 2. 后端入口 ----------------
foreach ([
    'index.php'  => '统一入口',
    'api.php'    => '接口实现',
    'config.php' => '配置文件',
    'db.php'     => '数据库库',
    'updater.php' => '更新引擎',
] as $f => $label) {
    $add("{$label} {$f}", is_file($root . '/' . $f), is_file($root . '/' . $f) ? '存在' : '缺失 → 源码未完整上传', $f !== 'updater.php');
}

// ---------------- 3. PHP 扩展 ----------------
foreach (['pdo_mysql', 'mbstring', 'json', 'zlib', 'zip'] as $ext) {
    $on = extension_loaded($ext);
    $hint = '';
    if (!$on) {
        $hint = $ext === 'zip'
            ? '未启用 → 本地包更新不可用（远程更新不受影响）'
            : '未启用 → 系统无法正常运行，请在宝塔 PHP 设置中安装';
    }
    $add("PHP 扩展 {$ext}", $on, $on ? '已启用' : $hint, $ext !== 'zip');
}

// ---------------- 4. 数据库 ----------------
$cfgFile = $root . '/config.php';
$dbOk = false;
$dbDetail = '';

if (!is_file($cfgFile)) {
    $dbDetail = 'config.php 不存在';
} else {
    try {
        $cfg = require $cfgFile;
        $db = $cfg['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            $db['port'] ?? 3306,
            $db['name'] ?? 'lantian',
            $db['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $dbOk = true;

        // 逐表检查
        $need = ['users', 'clients', 'proxies', 'nodes', 'api_keys', 'sessions', 'downloads'];
        $has = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $has[] = $t;
        }
        $missing = array_values(array_diff($need, $has));
        $dbDetail = '连接成功，共 ' . count($has) . ' 张表';

        if ($missing) {
            $dbOk = false;
            $dbDetail .= '；缺失数据表：' . implode('、', $missing)
                       . ' → 请导入 sql/init.sql';
        } else {
            $dbDetail .= '，全部必需表就绪';
        }
    } catch (Throwable $e) {
        // 不回显口令，只给可读原因
        $msg = $e->getMessage();
        if (stripos($msg, 'Access denied') !== false) {
            $dbDetail = '账号或密码错误 → 检查 config.php 的 db.user / db.pass';
        } elseif (stripos($msg, 'Unknown database') !== false) {
            $dbDetail = '数据库不存在 → 请先在宝塔中创建数据库';
        } elseif (stripos($msg, 'Connection refused') !== false || stripos($msg, 'timed out') !== false) {
            $dbDetail = '无法连接数据库服务 → 确认 MySQL 已启动';
        } else {
            $dbDetail = '连接失败：' . mb_substr($msg, 0, 120);
        }
    }
}
$add('数据库连接', $dbOk, $dbDetail, true);

// ---------------- 5. 运行时 ----------------
foreach (['storage', 'storage/backup', 'storage/packages'] as $dir) {
    $p = $root . '/' . $dir;
    if (!is_dir($p)) {
        @mkdir($p, 0755, true);
    }
    $w = is_dir($p) && is_writable($p);
    $add("目录可写 {$dir}", $w, $w ? '可写' : '不可写 → 更新与备份功能不可用', false);
}

$add('文档根目录', true,
    '文档根 = ' . $root . '（零配置模式，应为站点根目录）', false);

// ---------------- 6. 汇总 ----------------
$criticalFail = array_values(array_filter($checks, fn($c) => $c['critical'] && !$c['ok']));

echo json_encode([
    'ok'       => count($criticalFail) === 0,
    'version'  => ($meta['appVersion'] ?? '未知') . ' (' . ($meta['version'] ?? '') . ')',
    'php'      => PHP_VERSION,
    'server'   => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'host'     => $_SERVER['HTTP_HOST'] ?? '',
    'mode'     => '零配置（hash 路由 + PATH_INFO 接口）',
    'summary'  => count($criticalFail) === 0
        ? '全部关键项通过，部署正常'
        : '存在 ' . count($criticalFail) . ' 项关键问题：'
          . implode('；', array_map(fn($c) => $c['name'] . ' - ' . $c['detail'], $criticalFail)),
    'checks'   => $checks,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
