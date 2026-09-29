<?php
/**
 * 对接说明控制器（v1.1.5 新增）
 * ===========================================================================
 * 【设计原则：文档由代码生成，而不是手写】
 *
 * 手写的接口文档必然会烂 —— 加接口时忘了改文档，半年后文档里一半的
 * 接口已经不存在，照着文档对接的人会浪费大量时间在「为什么 404」上。
 *
 * 本控制器直接遍历 Router 里已注册的路由表来生成清单。接口改了这个页面
 * 自动跟着变，不可能出现「文档说这样、实际是那样」。
 *
 * 唯一需要人工维护的是每个接口的「说明文字」和「参数说明」，
 * 它们定义在下面的 $DOC 里，按 method + pattern 索引。
 * 新增接口即使忘了写说明，也会以「未标注」出现在清单里 ——
 * 漏写会显得刺眼，而不是静默消失。
 * ===========================================================================
 */

final class DocsController
{
    /**
     * 人工维护的接口描述。
     *
     * 键 = "METHOD 路径"，与 routes.php 里的完全一致。
     */
    private const DOC = [
        // ---- 初始化引导 ----
        'GET /v1/setup/status'   => ['初始化状态', '公开', '查看站点是否已完成初始化，以及本版本支持哪些能力。'],
        'POST /v1/setup/admin'   => ['创建管理员', '公开', '站点首次部署时创建第一个管理员账号。已初始化后调用会被拒绝。'],

        // ---- 认证 ----
        'POST /v1/auth/login'          => ['登录', '公开', '账号密码登录。开启 2FA 的账号返回 ticket，需继续调用 /auth/2fa/verify。连续失败会触发验证码与账号锁定。'],
        'POST /v1/auth/logout'         => ['登出', '需登录', '使当前 token 立即失效。'],
        'GET /v1/auth/2fa/status'     => ['2FA 状态', '需登录', '当前账号是否已开启双因素认证，以及剩余备用码数量。'],
        'POST /v1/auth/2fa/setup'      => ['初始化 2FA', '需登录', '生成 TOTP 密钥与 otpauth:// 链接，供验证器 App 扫码。此时尚未生效。'],
        'POST /v1/auth/2fa/enable'     => ['启用 2FA', '需登录', '提交验证器当前生成的 6 位码，校验通过后 2FA 正式生效，同时下发备用码。'],
        'POST /v1/auth/2fa/disable'    => ['关闭 2FA', '需登录', '关闭双因素认证。需提交当前密码。'],
        'POST /v1/auth/2fa/verify'     => ['2FA 校验', '公开', '登录第二阶段。提交登录返回的 ticket 与 6 位动态码（或备用码），换取正式 session。'],
        'GET /v1/auth/captcha'        => ['图形验证码', '公开', '获取验证码图片。连续登录失败后必须携带。返回签名式 capSid，无需 Cookie。'],
        'GET /v1/auth/logs'           => ['登录日志', '需登录', '最近的登录记录，含成功/失败、来源 IP。'],

        // ---- 心跳 ----
        'POST /v1/nodes/heartbeat' => ['节点心跳', '节点凭据', '节点上报存活与负载。请求头需携带 X-Node-Token，值取自创建节点时返回的 nodeToken。'],

        // ---- 仪表盘 ----
        'GET /v1/dashboard/stats' => ['仪表盘统计', '需登录', '用户数、在线节点数、活跃隧道数与近 7 日流量趋势。趋势为空时 trendIsReal 为 false，前端应显示空态而非画线。'],

        // ---- 客户端 ----
        'GET /v1/clients'              => ['客户端列表', '需登录', '列出全部客户端。出于安全考虑不返回 token，需要查看请用 PUT /v1/clients/{name}。'],
        'POST /v1/clients'              => ['创建客户端', '需登录', '创建隧道客户端凭据。name 与 token 都必须提供，且不得与现有记录冲突。'],
        'POST /v1/clients/{id}/report'  => ['流量上报', '需登录', '节点回传该客户端的实际用量。mode=delta 累加（默认），mode=absolute 覆盖。超配额会自动下线其全部隧道。'],
        'GET /v1/clients/{id}/traffic' => ['客户端流量', '需登录', '单个客户端的流量明细与配额使用率。'],
        'PUT /v1/clients/{id}'         => ['更新客户端', '需登录', '轮换 token 或调整配额。轮换 token 后旧连接会立即失效。'],
        'DELETE /v1/clients/{id}'         => ['删除客户端', '需登录', '删除客户端及其名下全部隧道。'],

        // ---- 隧道 ----
        'GET /v1/proxies'              => ['隧道列表', '需登录', '列出全部隧道及其状态。'],
        'POST /v1/proxies'              => ['创建隧道', '需登录', '创建隧道。会依次校验客户端配额、节点存在性与协议能力、端口冲突。'],
        'POST /v1/proxies/close'        => ['下线隧道', '需登录', '把隧道置为离线（NexusLink 官方接口）。'],
        'POST /v1/proxies/open'         => ['重新上线', '需登录', '恢复被误停的隧道。若客户端配额已用尽会拒绝上线。'],
        'POST /v1/proxies/close-client' => ['批量下线', '需登录', '下线某个客户端名下的全部隧道。'],
        'DELETE /v1/proxies/{id}'         => ['删除隧道', '需登录', '删除隧道并同步客户端的隧道计数。'],

        // ---- 节点 ----
        'GET /v1/nodes'           => ['节点列表', '需登录', '列出全部分发节点。带 ?withToken=1（仅管理员）可同时取回 nodeToken。响应含 lastSeenAgo / heartbeatStale 诊断字段。'],
        'GET /v1/nodes/{id}'      => ['节点详情', '需登录', '查看单个节点，含协议能力与最近心跳时间。'],
        'POST /v1/nodes'           => ['创建节点', '管理员', '创建分发节点。响应中的 nodeToken 只出现这一次，请立即保存到节点端配置。'],
        'POST /v1/nodes/{id}/token' => ['查看/轮换凭据', '管理员', '不传参数返回当前 nodeToken；传 {"rotate":true} 生成新凭据并让旧的立即失效。'],
        'POST /v1/nodes/{id}/probe' => ['连通性探测', '管理员', '从服务端主动探测节点端口是否可达，返回延迟。只判断端口通不通，不改变节点的在线状态。'],
        'PUT /v1/nodes/{id}'      => ['更新节点', '管理员', '修改节点信息、状态或协议能力。取消某个协议时，若节点上仍有该协议的隧道会被拒绝。'],
        'DELETE /v1/nodes/{id}'      => ['删除节点', '管理员', '删除节点，其上隧道会被置为离线（不会删除隧道记录）。'],

        // ---- 用户 ----
        'GET /v1/users'      => ['用户列表', '管理员/代理商', '列出用户。'],
        'GET /v1/users/{id}' => ['用户详情', '管理员/代理商', '查看单个用户，含其客户端数与隧道数，便于判断额度是否够用。'],
        'POST /v1/users'      => ['创建用户', '管理员/代理商', '创建账号。密码必须显式提供且不少于 8 位，不会被默认成 admin。'],
        'PUT /v1/users/{id}' => ['更新用户', '管理员/代理商', '修改用户资料与额度。'],
        'DELETE /v1/users/{id}' => ['删除用户', '管理员/代理商', '删除账号。'],

        // ---- 代理商 ----
        'GET /v1/agent/overview'     => ['代理商业绩', '需登录', '名下用户数、已分配额度、剩余额度与流量用量。'],
        'GET /v1/agent/users'        => ['名下用户', '需登录', '代理商只能看到自己名下的下级用户。'],
        'POST /v1/agent/users'        => ['创建下级', '需登录', '为自己名下创建下级用户，受代理商剩余额度限制。'],
        'PUT /v1/agent/users/{id}'   => ['调整下级', '需登录', '调整下级用户的额度与状态。只能操作自己名下的人。'],
        'DELETE /v1/agent/users/{id}'   => ['删除下级', '需登录', '删除自己名下的下级用户。'],
        'GET /v1/agent/quota'        => ['我的额度', '需登录', '查看自己的代理商额度。管理员调用时返回全部代理商列表。'],
        'PUT /v1/agent/quota'        => ['分配额度', '管理员', '为指定账号分配代理商额度。代理商无法自行修改额度。'],
        'GET /v1/agents'             => ['代理商列表', '管理员', '列出全部代理商及其名下用户数。'],
        'POST /v1/agents'             => ['授予代理资格', '管理员', '把已存在的用户提升为代理商。要求先建账号再授权，避免出现「密码谁设的」问题。'],
        'PUT /v1/agents/{id}'        => ['修改代理商', '管理员', '调整代理商的额度、佣金比例与状态。'],
        'DELETE /v1/agents/{id}'        => ['撤销代理资格', '管理员', '撤销代理商身份。账号与名下用户都会保留。'],

        // ---- API 密钥 ----
        'GET /v1/api-keys'      => ['密钥列表', '管理员', '列出全部 API 密钥。'],
        'POST /v1/api-keys'      => ['创建密钥', '管理员', '创建 API 密钥。不传 key 时自动生成 lt_ 前缀的随机串。'],
        'DELETE /v1/api-keys/{id}' => ['删除密钥', '管理员', '删除指定密钥，立即失效。'],

        // ---- 更新 ----
        'GET /v1/update/status'      => ['更新状态', '管理员', '当前版本、更新源地址、历史记录与本地包列表，以及运行环境自检（ZipArchive / curl / storage 可写性）。'],
        'GET /v1/update/check'       => ['检查更新', '管理员', '拉取远程清单并比对版本。响应中的 resolvedUrl 是钉住版本后的实际来源地址。'],
        'POST /v1/update/apply'       => ['执行更新', '管理员', '从远程清单下载并应用更新。传 {"dryRun":true} 可先预演不落盘。'],
        'POST /v1/update/apply-local' => ['本地包更新', '管理员', '应用放在 storage/packages/ 下的本地升级包，适合无法访问外网的环境。'],

        // ---- 其他 ----
        'GET /v1/downloads'  => ['客户端下载', '公开', '各平台客户端下载地址清单。'],
        'GET /v1/settings'   => ['读取设置', '需登录', '读取系统设置。返回值已与默认值合并，字段始终完整。'],
        'PUT /v1/settings'   => ['保存设置', '管理员', '保存系统设置。只有白名单内的键会被写入，未知键静默忽略。'],
        'GET /v1/sessions'   => ['会话列表', '需登录', '当前在线会话。token 只回显前 8 位。'],
        'DELETE /v1/sessions'   => ['清空会话', '管理员', '强制所有人重新登录。默认保留自己的会话，带 ?all=1 可连自己一起清。'],

        // ---- 对接说明自身 ----
        'GET /v1/docs/endpoints' => ['接口清单', '公开', '返回系统全部接口的元信息，含三种可用的 baseUrl。清单由路由表实时生成，不会与代码脱节。'],
        'GET /v1/docs/openapi'   => ['OpenAPI 描述', '公开', 'OpenAPI 3.0.3 描述文件，可直接导入 Postman / Apifox / Swagger UI。'],
    ];

    /** 公开接口（不需要任何凭据）—— 用于在文档页上分区展示 */
    private const PUBLIC_PATHS = [
        'GET /v1/setup/status',
        'POST /v1/setup/admin',
        'POST /v1/auth/login',
        'POST /v1/auth/2fa/verify',
        'GET /v1/auth/captcha',
        'POST /v1/nodes/heartbeat',
        'GET /v1/downloads',
        'GET /v1/docs/endpoints',
        'GET /v1/docs/openapi',
    ];

    /**
     * GET /docs/endpoints —— 接口清单
     *
     * 【为什么带上三个 baseUrl】
     * 零配置部署下，同一份代码可能通过三种 URL 形式被访问：
     *   /api.php/v1/...
     *   /api/v1/...          （nginx 伪静态把 /api 指到 api.php）
     *   /index.php/api/v1/...（宝塔默认站点入口形式）
     * 对接方经常卡在「该用哪个地址」，这里一次性全都告诉他。
     */
    public static function endpoints()
    {
        $routes = Router::all();
        $list   = [];

        foreach ($routes as $r) {
            $key = $r['method'] . ' ' . $r['pattern'];
            $doc = self::DOC[$key] ?? null;

            $list[] = [
                'method'      => $r['method'],
                'path'        => $r['pattern'],
                // 带 {id} 的路径在文档里统一展示成 :id，更贴近 OpenAPI 习惯
                'displayPath' => preg_replace('#\{([a-z_]+)\}#i', '{$1}', $r['pattern']),
                'title'       => $doc[0] ?? '（未标注）',
                'auth'        => $doc[1] ?? self::inferAuth($r['middlewares']),
                'description' => $doc[2] ?? '',
                'middlewares' => $r['middlewares'],
                'public'      => in_array($key, self::PUBLIC_PATHS, true),
            ];
        }

        // 统计一下有多少接口还没写说明 —— 这个数字应该永远是 0，
        // 不为 0 说明 routes.php 加了新接口但忘了更新上面的 DOC 表。
        $undocumented = count(array_filter($list, function ($x) {
            return $x['title'] === '（未标注）';
        }));

        $origin = self::origin();

        respond([
            'success' => true,
            'version' => currentBuildVersion(),
            'baseUrls' => [
                'standard'  => $origin . '/api.php/v1',
                'rewrite'   => $origin . '/api/v1',
                'indexPhp'  => $origin . '/index.php/api/v1',
            ],
            'auth' => [
                'bearer'    => 'Authorization: Bearer <token>',
                'apiKey'    => 'X-API-Key: <key>',
                'nodeToken' => 'X-Node-Token: <nodeToken>（仅心跳接口）',
            ],
            'stats' => [
                'total'        => count($list),
                'public'       => count(array_filter($list, function ($x) { return $x['public']; })),
                'undocumented' => $undocumented,
            ],
            'endpoints' => $list,
        ]);
    }

    /**
     * GET /docs/openapi —— OpenAPI 3.0 描述
     *
     * 有了它就能直接导入 Postman / Apifox / Swagger UI，
     * 对接方不用手敲几十个请求。
     */
    public static function openapi()
    {
        $origin = self::origin();
        $paths  = [];

        foreach (Router::all() as $r) {
            $key  = $r['method'] . ' ' . $r['pattern'];
            $doc  = self::DOC[$key] ?? null;
            if ($r['method'] === 'ANY') {
                continue;
            }

            // OpenAPI 用 {id} 语法，与我们的 pattern 一致，直接沿用
            $path = $r['pattern'];

            $paths[$path][strtolower($r['method'])] = [
                'summary'     => $doc[0] ?? '（未标注）',
                'description' => $doc[2] ?? '',
                'tags'        => [self::tagOf($path)],
                'security'    => in_array($key, self::PUBLIC_PATHS, true)
                    ? []
                    : [['bearerAuth' => []]],
                'responses'   => [
                    '200' => ['description' => '成功'],
                    '400' => ['description' => '参数错误'],
                    '401' => ['description' => '未登录或凭据无效'],
                    '403' => ['description' => '权限不足'],
                    '404' => ['description' => '资源不存在'],
                ],
            ];
        }

        respond([
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => '蓝天内网穿透 API',
                'description' => '基于 NexusLink 数据面的内网穿透管理平台接口。'
                               . '所有响应均为 JSON，成功时 success 为 true。',
                'version'     => currentBuildVersion(),
            ],
            'servers' => [
                ['url' => $origin . '/api.php/v1', 'description' => '标准形式（通用）'],
                ['url' => $origin . '/api/v1',     'description' => '伪静态形式（需 nginx 配置）'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKey'     => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                    'nodeToken'  => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Node-Token'],
                ],
            ],
            'paths' => $paths,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * 从中间件推断鉴权要求（DOC 里没写时的兜底）
     *
     * v1.2.0 起系统只有 admin / user 两种角色生效（sponsor 不额外开权限），
     * 所以非 admin 一律显示「需登录」。
     * 原先非 admin 显示「管理员/代理商」是因为 role:admin,agent 那批路由 ——
     * 代理商角色已移除，那条分支会误导读者以为还存在代理商接口。
     */
    private static function inferAuth(array $mw): string
    {
        foreach ($mw as $m) {
            if (strpos($m, 'role:') === 0) {
                $roles = substr($m, 5);
                return $roles === 'admin' ? '管理员' : '需登录';
            }
            if ($m === 'auth') {
                return '需登录';
            }
        }
        return '公开';
    }

    /** 从路径推 tag，让 Swagger UI 里能按资源分组 */
    private static function tagOf(string $path): string
    {
        if (preg_match('#^/v1/([a-z0-9\-]+)#', $path, $m)) {
            return $m[1];
        }
        return 'other';
    }

    /**
     * 推断站点根地址。
     *
     * 【为什么要剥掉 /api.php 和 /api】
     * 请求 URI 可能是 /api.php/v1/docs/openapi，直接拼出来会得到
     * 「https://host/api.php/v1/api.php/v1」这种地址。
     * 这里统一剥到站点根，再按三种形式各拼一次。
     */
    private static function origin(): string
    {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $path = (string) $_SERVER['REQUEST_URI'];
        $path = explode('?', $path)[0];

        // 剥掉 api.php / index.php 之后的所有内容
        $path = preg_replace('#/(api|index)\.php.*$#', '', $path);
        $path = rtrim($path, '/');

        return $scheme . '://' . $host . $path;
    }
}
