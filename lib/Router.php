<?php
/**
 * 蓝天内网穿透 · 路由
 * ===========================================================================
 * 【设计目标】
 * 拆分前，路由判定散落在 api.php 里的 15 个 `if ($resource === 'xxx')` 大段，
 * 每个段内部再自己判断 $method / $id / $action，形成三层嵌套的隐式路由。
 * 想知道「系统总共有哪些接口」必须通读两千行。
 *
 * 现在改成显式注册：
 *
 *   Router::get('/v1/nodes', [NodesController::class, 'index']);
 *   Router::post('/v1/nodes/{id}/probe', [NodesController::class, 'probe']);
 *
 * 好处：
 *   1. 所有接口在 routes.php 里一览无余
 *   2. 「带 {id}/{action} 的具体路由必须排在通用路由前面」这个坑，
 *      由路由表从上到下匹配的顺序自然表达，不再需要靠注释提醒
 *      （原 api.php 里就有一段长注释在解释这个顺序问题）
 *   3. 中间件（鉴权/角色/限流）可以声明式挂载，不再散落在各分支里
 *
 * 【兼容】匹配语义与原来完全一致：
 *   路径形如 /v1/{resource}/{id}/{action}，{id} 可为数字或字面量（如 heartbeat），
 *   字面量路由优先于 {id} 通配。
 * ===========================================================================
 */

/** 防止重复定义 */
if (defined('LT_ROUTER_LOADED')) {
    return;
}
define('LT_ROUTER_LOADED', 1);

final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, keys:array, handler:callable|array, middlewares:array}> */
    private static array $routes = [];

    /** @var callable|null 全局兜底（未匹配到任何路由时） */
    private static $fallback = null;

    /**
     * 注册一条路由。
     *
     * @param string          $method      GET/POST/PUT/DELETE/ANY
     * @param string          $pattern     形如 /v1/nodes/{id}/probe
     * @param callable|array  $handler     [Controller::class, 'method']
     * @param array           $middlewares 形如 ['auth', 'role:admin']
     */
    public static function add(string $method, string $pattern, $handler, array $middlewares = []): void
    {
        [$regex, $keys] = self::compile($pattern);
        self::$routes[] = [
            'method'      => strtoupper($method),
            'pattern'     => $pattern,
            'regex'       => $regex,
            'keys'        => $keys,
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public static function get(string $p, $h, array $mw = []): void    { self::add('GET', $p, $h, $mw); }
    public static function post(string $p, $h, array $mw = []): void   { self::add('POST', $p, $h, $mw); }
    public static function put(string $p, $h, array $mw = []): void    { self::add('PUT', $p, $h, $mw); }
    public static function delete(string $p, $h, array $mw = []): void { self::add('DELETE', $p, $h, $mw); }

    /**
     * 注册一个「任意方法」的路由。
     *
     * 有些资源的历史实现把 GET/POST/PUT/DELETE 全写在同一个 if 块里，
     * 拆分时用 any() 保持行为一致，避免因为拆得太细而改变语义。
     */
    public static function any(string $p, $h, array $mw = []): void { self::add('ANY', $p, $h, $mw); }

    public static function fallback(callable $fn): void
    {
        self::$fallback = $fn;
    }

    /**
     * 把 /v1/nodes/{id}/probe 编译成正则。
     *
     * {id} 默认匹配「非斜杠且非空」的任意字符 —— 与原来的
     * explode('/') 后取段的行为一致（原实现里 $id 可以是 'heartbeat' 这种字面量，
     * 也可以是 '3'）。
     *
     * @return array{0:string, 1:array}
     */
    private static function compile(string $pattern): array
    {
        $keys = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($m) use (&$keys) {
                $keys[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );
        return ['#^' . $regex . '$#', $keys];
    }

    /**
     * 把请求路径按路由表匹配并执行。
     *
     * 【顺序语义】严格按注册顺序自上而下匹配，第一个命中即执行。
     * 因此 routes.php 里必须把「具体路径」写在「通配路径」之前，
     * 例如 /v1/nodes/heartbeat 要排在 /v1/nodes/{id} 前面。
     * 这一点与原 api.php 手写 if 的执行顺序语义完全一致。
     */
    public static function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);

        // 先做一次 CORS 预检的统一处理（原 respond() 里也有，双保险）
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Node-Token');
            http_response_code(204);
            exit;
        }

        foreach (self::$routes as $r) {
            if ($r['method'] !== 'ANY' && $r['method'] !== $method) {
                continue;
            }
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }

            array_shift($m); // 去掉整体匹配
            $params = [];
            foreach ($r['keys'] as $i => $k) {
                $params[$k] = $m[$i] ?? null;
            }

            // 执行中间件
            foreach ($r['middlewares'] as $mw) {
                self::runMiddleware($mw, $params);
            }

            // 执行处理器
            $handler = $r['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                // [Controller::class, 'method']
                $cls = $handler[0];
                $fn  = $handler[1];
                if (!class_exists($cls)) {
                    respond([
                        'success' => false,
                        'error'   => 'ROUTE_HANDLER_MISSING',
                        'message' => "控制器 {$cls} 未加载",
                    ], 500);
                }
                $obj = new $cls();

                // 【为什么按顺序展开而不是传数组】
                // 控制器方法签名写成 destroy(string $name) 比 destroy(array $p)
                // 清晰得多 —— 参数名和数量就是一份自解释的契约。
                // 路径里 {id} 的出现顺序即实参顺序。
                $args = array_values($params);
                $obj->$fn(...$args);
                return;
            }

            if (is_callable($handler)) {
                $handler($params);
                return;
            }

            respond([
                'success' => false,
                'error'   => 'ROUTE_HANDLER_INVALID',
                'message' => '路由处理器不可调用',
            ], 500);
        }

        // 未匹配
        if (self::$fallback !== null) {
            (self::$fallback)($path);
            return;
        }
        respond([
            'success' => false,
            'error'   => 'NOT_FOUND',
            'message' => "未找到接口: {$method} {$path}",
        ], 404);
    }

    /**
     * 中间件执行。
     *
     * 支持的写法：
     *   'auth'               → 必须已登录（等价原 requireAuth()）
     *   'role:admin'         → 必须是 admin（等价原 requireRole('admin')）
     *   'role:admin,agent'   → 多角色之一
     */
    private static function runMiddleware(string $mw, array $params): void
    {
        $mw = trim($mw);
        if ($mw === '' || $mw === 'auth') {
            if ($mw === 'auth') {
                requireAuth();
            }
            return;
        }

        if (strpos($mw, 'role:') === 0) {
            // role 检查隐含 auth —— 不可能未登录却有角色
            requireAuth();
            $roles = array_filter(array_map('trim', explode(',', substr($mw, 5))));
            if (!empty($roles)) {
                requireRole(...$roles);
            }
            return;
        }

        // 未知中间件：静默跳过而不是报错，避免新增中间件时把老接口打挂
    }

    /** 供诊断用：列出全部已注册路由 */
    public static function all(): array
    {
        $out = [];
        foreach (self::$routes as $r) {
            $out[] = [
                'method'      => $r['method'],
                'pattern'     => $r['pattern'],
                'middlewares' => $r['middlewares'],
            ];
        }
        return $out;
    }
}
