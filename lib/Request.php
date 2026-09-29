<?php
/**
 * 蓝天内网穿透 · 请求解析
 * ===========================================================================
 * 从原 api.php 顶部的路径解析段抽出。
 *
 * 【必须严格保持的兼容性】
 * 原实现支持三种 URL 形式，且有一套「层层兜底」的剥离逻辑：
 *   /api.php/v1/auth/login        ← 前端默认走这个，nginx 天然支持
 *   /api/v1/auth/login            ← 需要 try_files，为兼容保留
 *   /index.php/api/v1/auth/login  ← PATH_INFO 变体
 *   /v1/auth/login                ← 直连
 *
 * 这套逻辑看起来繁琐，但每一层都对应一个真实部署环境的差异。
 * 拆分时**逐行照搬**，不做任何"简化"。
 * ===========================================================================
 */

if (defined('LT_REQUEST_LOADED')) {
    return;
}
define('LT_REQUEST_LOADED', 1);

final class Req
{
    /** 当前请求方法（大写） */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * 解析出规范化后的 API 路径，形如 /v1/auth/login。
     *
     * 逐层剥离策略（与原 api.php 完全一致）：
     *   1) 有 PATH_INFO 就用它
     *   2) 否则从 REQUEST_URI 里剥掉入口文件名及之前的内容
     *   3) 再兜底剥掉开头的 /api
     *   4) 最后从任意位置截取 /v1/...
     */
    public static function apiPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rtrim($uri, '/');
        $path = $uri;

        // 1) PHP 已解析出 PATH_INFO（形如 /api.php/v1/auth/login）
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if ($pathInfo !== '') {
            $path = $pathInfo;
        }

        // 2) 剥掉入口文件名及其前面的所有内容
        if (preg_match('#/(?:api|index)\.php(/.+)$#', $path, $m)) {
            $path = $m[1];
        } else {
            // 3) 兜底：剥掉开头的 /api 前缀
            $path = preg_replace('#^/api(?=/|$)#', '', $path);
        }

        // 4) 最后兜底：若仍未以 /v1 开头，尝试从任意位置截取
        if (strpos($path, '/v1/') !== 0 && $path !== '/v1') {
            $pos = strpos($path, '/v1/');
            $path = ($pos !== false) ? substr($path, $pos) : $path;
        }

        return $path;
    }

    /**
     * 把 /v1/{resource}/{id}/{action} 切成段。
     *
     * 【返回结构与原实现一致】原代码用三个变量承载：
     *   $segs[1] = resource, $segs[2] = id, $segs[3] = action
     * 这里保留 $segs 便于控制器做更细的判断（个别接口确实用到了 $segs[4]）。
     */
    public static function segs(): array
    {
        $path = self::apiPath();
        return array_values(array_filter(explode('/', $path), 'strlen'));
    }

    public static function resource(): string
    {
        $s = self::segs();
        return $s[1] ?? '';
    }

    public static function id(): ?string
    {
        $s = self::segs();
        return $s[2] ?? null;
    }

    public static function action(): ?string
    {
        $s = self::segs();
        return $s[3] ?? null;
    }

    /** 路径是否是合法的 v1 请求 */
    public static function isValidV1(): bool
    {
        $s = self::segs();
        return count($s) >= 2 && $s[0] === 'v1';
    }

    /**
     * 读取 JSON 请求体。
     *
     * 与原 body() 行为一致：解析失败返回空数组（而不是抛异常），
     * 由调用方按缺字段处理 —— 这样「畸形 JSON」会得到 400 参数错误，
     * 而不是 500。
     */
    public static function body(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $raw  = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        $cached = is_array($data) ? $data : [];
        return $cached;
    }

    /** 取查询参数 */
    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /** 取查询参数的布尔值（'1' / 'true' / 'yes' 视为真） */
    public static function queryBool(string $key): bool
    {
        $v = $_GET[$key] ?? null;
        if ($v === null) {
            return false;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** 取请求头（大小写不敏感） */
    public static function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? $default);
    }

    /** 客户端 IP（优先取反代头） */
    public static function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            $v = $_SERVER[$k] ?? '';
            if ($v !== '') {
                // X-Forwarded-For 可能是 "client, proxy1, proxy2"
                $first = trim(explode(',', $v)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }
        return '0.0.0.0';
    }

    /** User-Agent（截断到 255 以适配库字段） */
    public static function ua(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
