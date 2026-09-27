<?php
/**
 * 蓝天内网穿透 · 统一入口
 * ===========================================================================
 * 【零配置部署说明】
 *
 * 本文件放在网站根目录，是整站的唯一入口：
 *   - /api.php/v1/xxx   → API 请求，交给 api.php 处理
 *   - 其它所有路径       → 返回前端页面 index.html
 *
 * 因此**不需要任何 nginx 伪静态 / try_files / rewrite 规则**：
 *   · /api.php/v1/... 走 nginx 默认的 PHP 处理段
 *     （location ~ [^/]\.php(/|$) + fastcgi_split_path_info，宝塔默认就有）
 *   · 前端使用 hash 路由（/#/nodes），URL 里的路径部分永远只有 "/"，
 *     不会产生服务端找不到的路径，因此不存在 404 问题
 *
 * 宝塔部署只需要做两件事：
 *   1. 网站目录 / 运行目录都设为站点根目录（即本文件所在目录）
 *   2. 关闭「防跨站攻击（open_basedir）」（因为 db.php 等文件在根目录，不受影响，
 *      但关闭可避免宝塔默认限制带来的意外问题）
 * ===========================================================================
 */

// ---------------------------------------------------------------------------
// 0) 老版本自举
//
// index.php 在旧引擎的白名单里（会被落盘），所以新版 index.php 是最可靠的
// 「自举触发点」：用户升级后打开面板首页，前端会立刻请求 /api.php/v1/...，
// 但首页本身也可能先被访问到。这里先补一次，双保险。
//
// 具体原因见 lib/Boot.php 顶部注释（鸡生蛋问题）。
// ---------------------------------------------------------------------------
$__ltBootFile = __DIR__ . '/lib/Boot.php';
if (is_file($__ltBootFile)) {
    require_once $__ltBootFile;
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ---------------------------------------------------------------------------
// 1) API 请求 → 交给 api.php
//    兼容 /api.php/v1/... 与 /api/v1/... 两种形式
// ---------------------------------------------------------------------------
if (preg_match('#^/(api|index)\.php(/|$)#', $uri) || strpos($uri, '/api/') === 0) {
    require __DIR__ . '/api.php';
    exit;
}

// ---------------------------------------------------------------------------
// 2) 静态资源 → 直接输出（PHP 内置服务器用；nginx 环境由 nginx 自行处理）
// ---------------------------------------------------------------------------
if ($uri !== '/' && $uri !== '/index.html') {
    $file = realpath(__DIR__ . $uri);
    $root = realpath(__DIR__);
    // 必须落在站点根目录之内：补分隔符比较，避免 /www/root2 被误判为在 /www/root 内
    if ($file !== false && $root !== false
        && is_file($file)
        && ($file === $root || strpos($file . '/', rtrim($root, '/') . '/') === 0)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimes = [
            'js' => 'text/javascript; charset=utf-8',
            'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json; charset=utf-8',
        ];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));

        // 内容哈希命名的资源可长期强缓存；入口与清单禁止缓存
        if (strpos($uri, '/assets/') === 0) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } elseif ($uri === '/build-meta.json' || $uri === '/favicon.svg') {
            header('Cache-Control: no-store, no-cache, must-revalidate');
        } else {
            header('Cache-Control: public, max-age=86400');
        }

        header('ETag: "' . md5_file($file) . '"');
        readfile($file);
        exit;
    }
}

// ---------------------------------------------------------------------------
// 3) 部署自检 → /__health（无需登录）
//    零配置部署下这是排查问题的第一入口
// ---------------------------------------------------------------------------
if ($uri === '/__health') {
    require __DIR__ . '/health.php';
    exit;
}

// ---------------------------------------------------------------------------
// 4) 其它所有请求 → 前端页面
//    hash 路由下，浏览器请求的路径永远只有 "/"，子路由写在 # 之后不发给服务器
// ---------------------------------------------------------------------------
$index = __DIR__ . '/index.html';
if (!is_file($index)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>前端产物缺失</h1>';
    echo '<p>未找到 <code>index.html</code>。请确认已把构建产物上传到站点根目录。</p>';
    echo '<p>当前目录：<code>' . htmlspecialchars(__DIR__) . '</code></p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($index);
