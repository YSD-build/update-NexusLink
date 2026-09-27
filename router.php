<?php
/**
 * 蓝天内网穿透 · PHP 内置服务器路由（仅用于本地开发 / 自动化测试）
 *
 * 【为什么需要这个文件】
 * 生产环境靠 nginx 的 `location ~ [^/]\.php(/|$)` + `fastcgi_split_path_info`
 * 来处理 PATH_INFO 形式（/api.php/v1/xxx）。PHP 内置服务器（php -S）没有这套机制，
 * 会把 /api.php/v1/xxx 当成「文件路径」直接 404，所以测试环境必须用路由脚本补上。
 *
 * 【必须兼容的三种 URL 形式】（零配置部署铁律，缺一不可）
 *   1. /api.php/v1/xxx          —— 生产主用，nginx 默认配置即支持
 *   2. /api/v1/xxx              —— nginx 加伪静态后可用的干净形式
 *   3. /index.php/api/v1/xxx    —— 部分主机把入口约束成 index.php 的形式
 *
 * 【实现要点】
 * 内置服务器的路由器脚本里 require 目标 PHP 文件时，该文件看到的是我们
 * 改过的 $_SERVER —— 所以必须在 require 之前把 PATH_INFO 设成 api.php 期望的样子：
 *      /api.php/v1/nodes  →  PATH_INFO = /v1/nodes
 *       /api/v1/nodes      →  PATH_INFO = /v1/nodes
 * 否则 api.php 解析不出 $path，会走首页兜底（表现为「返回一坨 HTML」）。
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$entry = null;
$pathInfo = null;

if (preg_match('#^/api\.php(/.*)?$#', $uri, $m)) {
    // 形式1：/api.php/v1/xxx
    $entry = '/api.php';
    $pathInfo = $m[1] ?? '';
} elseif (preg_match('#^/index\.php(/.*)?$#', $uri, $m)) {
    // 形式3：/index.php/api/v1/xxx —— index.php 内部会再转发给 api.php
    $entry = '/index.php';
    $pathInfo = $m[1] ?? '';
} elseif (preg_match('#^/api(/.*)?$#', $uri, $m)) {
    // 形式2：/api/v1/xxx —— 干净形式，直接映射到 api.php。
    // 注意要把 /api 前缀摘掉，剩余的 /v1/xxx 才是 api.php 要的 PATH_INFO。
    $entry = '/api.php';
    $pathInfo = $m[1] ?? '';
}

if ($entry !== null) {
    $target = __DIR__ . $entry;
    if (is_file($target)) {
        $pathInfo = rtrim($pathInfo, '/');
        $_SERVER['SCRIPT_NAME']     = $entry;
        $_SERVER['SCRIPT_FILENAME'] = $target;
        $_SERVER['PHP_SELF']        = $entry;
        $_SERVER['PATH_INFO']       = $pathInfo === '' ? '/' : $pathInfo;
        require $target;
        return true;
    }
}

// 静态资源直出
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

// 其余交给前端（SPA / hash 路由）
$index = __DIR__ . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(404);
echo 'Not Found';
return true;
