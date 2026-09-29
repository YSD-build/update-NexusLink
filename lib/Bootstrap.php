<?php
/**
 * 蓝天内网穿透 · 统一引导
 * ===========================================================================
 * 本文件负责把散落的依赖组装起来，是所有请求的第一站。
 *
 * 拆分前：api.php 一个文件 2157 行，混杂了路由解析、鉴权、业务逻辑、
 *         工具函数、SSRF 防护等所有东西，改任何一处都要在两千行里翻找。
 *
 * 拆分后：
 *   lib/Bootstrap.php   ← 本文件，只做加载与全局初始化
 *   db.php              ← 连接 + schema 自愈（保持不动，兼容老部署）
 *   lib/Response.php    ← respond() / 错误码
 *   lib/Request.php     ← 请求解析
 *   lib/Auth.php        ← 会话与权限
 *   lib/Router.php      ← 路由注册与匹配
 *   lib/Helpers.php     ← 通用工具
 *   controllers/*.php   ← 按资源划分的业务逻辑
 *   routes.php          ← 路由表（集中可读，一眼看全系统有哪些接口）
 *   api.php             ← 只剩「解析请求 → 路由分发」
 *
 * 【兼容性铁律】拆分过程中以下行为必须完全不变：
 *   1. 三种 URL 形式继续可用
 *      /api.php/v1/auth/login · /api/v1/auth/login · /index.php/api/v1/auth/login
 *   2. 所有接口的响应 JSON 结构与错误码不变（前端不需要跟着改）
 *   3. 零配置部署 —— 不引入任何需要 nginx 伪静态的路径
 * ===========================================================================
 */

// ---------------------------------------------------------------------------
// 1) 基础依赖
//
// db.php 用 require 而非 require_once：历史上某些部署环境里
// include_path 配置异常会导致 realpath 解析不一致，require_once 误判为
// 「已加载」。这里用 require + 幂等函数定义不变式（PHP 重复定义函数会致命错误，
// 所以下面每个文件都在开头检查标记常量）。
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';

// ---------------------------------------------------------------------------
// 2) 加载顺序有讲究
//
// Response / Request 在最前 —— 它们在 Bootstrap 的错误处理里就可能被调用
// （比如配置读取失败时要能返回一个规范 JSON 错误，而不是 PHP 白屏）。
// ---------------------------------------------------------------------------
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Router.php';

// ---------------------------------------------------------------------------
// 3) 全局错误处理
//
// 【为什么必须有】
// 默认的 PHP 致命错误会输出 HTML 或空白，而前端期待的是 JSON。
// 一旦发生「500 白屏但 curl 看到 HTML」，排查会非常痛苦 ——
// 你无法从响应里知道是哪个文件哪一行炸的。
//
// 这里统一转成 JSON，并在 debug 打开时带上文件与行号。
// ---------------------------------------------------------------------------
$__ltDebug = false;
try {
    $__ltDebug = (bool) (cfg()['debug'] ?? false);
} catch (Throwable $e) {
    $__ltDebug = false;
}

set_exception_handler(function (Throwable $e) use ($__ltDebug) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
    }
    $out = [
        'success' => false,
        'error'   => 'INTERNAL_ERROR',
        'message' => '服务器内部错误',
    ];
    if ($__ltDebug) {
        $out['debug'] = [
            'type' => get_class($e),
            'msg'  => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

set_error_handler(function ($no, $str, $file, $line) use ($__ltDebug) {
    // 只处理真正的错误，忽略 @ 抑制与 notice/deprecated
    if (!(error_reporting() & $no)) {
        return false;
    }
    if (in_array($no, [E_NOTICE, E_DEPRECATED, E_USER_NOTICE, E_USER_DEPRECATED, E_WARNING], true)) {
        return false; // 交给默认处理（写日志），不阻断请求
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

/** 供控制器引用的调试开关 */
function ltDebug(): bool {
    static $d = null;
    if ($d === null) {
        try {
            $d = (bool) (cfg()['debug'] ?? false);
        } catch (Throwable $e) {
            $d = false;
        }
    }
    return $d;
}

/**
 * 加载全部控制器。
 *
 * 用 glob 而非手写列表：新增控制器时只要把文件丢进 controllers/ 即可，
 * 不会出现「忘了加 require」这种低级问题。
 * 控制器类内部用 Router 静态注册路由，加载即完成注册。
 */
function ltLoadControllers(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $dir = __DIR__ . '/../controllers';
    if (!is_dir($dir)) {
        return;
    }
    $files = glob($dir . '/*.php') ?: [];
    sort($files);
    foreach ($files as $f) {
        require_once $f;
    }
}
