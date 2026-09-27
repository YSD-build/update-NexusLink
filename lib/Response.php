<?php
/**
 * 蓝天内网穿透 · 响应工具
 * ===========================================================================
 * 本文件从原 db.php 抽出 respond() 与相关常量。
 *
 * 【重要】respond() 的实现保持**逐行等价**于原版，只在末尾增加了
 * 「请求日志（debug 模式）」这一可选行为，且不影响输出内容。
 * 这是为了确保拆分不改变任何既有接口的响应格式。
 * ===========================================================================
 */

if (defined('LT_RESPONSE_LOADED')) {
    return;
}
define('LT_RESPONSE_LOADED', 1);

/**
 * 输出 JSON 响应并结束请求。
 *
 * 注意：本函数以 exit 结尾 —— 调用它即代表「本请求处理完毕」，
 * 控制器里不需要再 return。
 */
if (!function_exists('respond')) {
    function respond($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Node-Token');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            exit;
        }

        // debug 模式下把响应码记进日志，便于排查「为什么这个接口 4xx 了」
        if (function_exists('ltDebug') && ltDebug() && $code >= 400) {
            ltLog('response', [
                'code' => $code,
                'path' => $_SERVER['REQUEST_URI'] ?? '',
                'err'  => $data['error'] ?? null,
            ]);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * 成功响应快捷方式。
 */
if (!function_exists('ok')) {
    function ok(array $data = []): void {
        respond(array_merge(['success' => true], $data));
    }
}

/**
 * 错误响应快捷方式。
 */
if (!function_exists('fail')) {
    function fail(string $error, string $message = '', int $code = 400, array $extra = []): void {
        $out = ['success' => false, 'error' => $error];
        if ($message !== '') {
            $out['message'] = $message;
        }
        respond(array_merge($out, $extra), $code);
    }
}

/**
 * 统一错误码常量。
 *
 * 【为什么要集中定义】
 * 前端要按错误码做分支处理（比如 UPDATE_SOURCE_NOT_CONFIGURED 时要
 * 引导用户去设置页），而错误码散落在各处字符串里时，很容易出现
 * 拼写漂移（NODE_TOKEN_REQUIRED vs NODE_TOKEN_MISSING）导致前端匹配不上。
 * 集中在这里，改名字只需改一处。
 */
final class Err
{
    public const BAD_REQUEST            = 'BAD_REQUEST';
    public const NOT_FOUND              = 'NOT_FOUND';
    public const UNAUTHORIZED           = 'UNAUTHORIZED';
    public const FORBIDDEN              = 'FORBIDDEN';
    public const CONFLICT               = 'CONFLICT';

    public const FIELD_TOO_LONG         = 'FIELD_TOO_LONG';
    public const NAME_REQUIRED          = 'NAME_REQUIRED';
    public const NAME_CONFLICT          = 'NAME_CONFLICT';
    public const TOKEN_CONFLICT         = 'TOKEN_CONFLICT';

    public const INVALID_CREDENTIALS    = 'INVALID_CREDENTIALS';
    public const ACCOUNT_BANNED         = 'ACCOUNT_BANNED';
    public const ACCOUNT_LOCKED         = 'ACCOUNT_LOCKED';
    public const PASSWORD_TOO_WEAK      = 'PASSWORD_TOO_WEAK';
    public const PASSWORD_REQUIRED      = 'PASSWORD_REQUIRED';
    public const INVALID_USERNAME       = 'INVALID_USERNAME';

    public const SCHEMA_NOT_READY       = 'SCHEMA_NOT_READY';
    public const NODE_TOKEN_REQUIRED    = 'NODE_TOKEN_REQUIRED';
    public const INVALID_NODE_TOKEN     = 'INVALID_NODE_TOKEN';

    public const UPDATE_SOURCE_NOT_CONFIGURED = 'UPDATE_SOURCE_NOT_CONFIGURED';
    public const INTERNAL_ERROR         = 'INTERNAL_ERROR';
}
