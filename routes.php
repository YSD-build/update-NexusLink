<?php
/**
 * 蓝天内网穿透 · 路由表
 * ===========================================================================
 * 【本文件的价值】
 * 拆分前，要回答「这个系统有哪些接口」必须通读 api.php 的 2157 行，
 * 在 15 个 `if ($resource === 'xxx')` 大段里逐层追踪 $method/$id/$action。
 *
 * 现在，本文件就是系统的完整接口清单。
 *
 * 【注册顺序 = 匹配顺序】（非常重要）
 * Router 自上而下匹配，第一个命中即执行。因此：
 *   · 具体路径必须写在通配路径之前
 *     例：/v1/nodes/heartbeat 要排在 /v1/nodes/{id} 之前，
 *         否则 heartbeat 会被当成 {id} 吃掉
 *   · 这是原 api.php 里一段长注释在反复提醒的坑，现在由顺序自然表达
 *
 * 【中间件】
 *   'auth'             必须已登录
 *   'role:admin'       必须是管理员（隐含 auth）
 *   'role:admin,agent' 多角色之一
 * ===========================================================================
 */

// Router 是全局类，直接用类名调用（本文件不声明 namespace）

/* ===========================================================================
 * 初始化引导（公开接口）
 * 站点首次部署时访问，用来引导创建管理员。必须在鉴权之外。
 * =========================================================================== */
Router::get ('/v1/setup/status', [SetupController::class, 'status']);
Router::post('/v1/setup/admin',  [SetupController::class, 'createAdmin']);

/* ===========================================================================
 * 认证与登录安全
 * =========================================================================== */
Router::post('/v1/auth/login',        [AuthController::class, 'login']);
Router::post('/v1/auth/logout',       [AuthController::class, 'logout'], ['auth']);

// 2FA —— 具体路径在通用路径之前
Router::get ('/v1/auth/2fa/status',   [AuthController::class, 'status2FA'], ['auth']);
Router::post('/v1/auth/2fa/setup',    [AuthController::class, 'setup2FA'],  ['auth']);
Router::post('/v1/auth/2fa/enable',   [AuthController::class, 'enable2FA'], ['auth']);
Router::post('/v1/auth/2fa/disable',  [AuthController::class, 'disable2FA'],['auth']);
Router::post('/v1/auth/2fa/verify',   [AuthController::class, 'verify2FA']); // 登录第二阶段，无 auth

Router::get ('/v1/auth/captcha',      [AuthController::class, 'captcha']);
Router::get ('/v1/auth/logs',         [AuthController::class, 'logs'], ['auth']);

/* ===========================================================================
 * 节点心跳（公开，走节点自身凭据 X-Node-Token）
 *
 * ⚠️ 必须排在 /v1/nodes/{id} 之前 —— 否则 heartbeat 会被当作 id。
 * =========================================================================== */
Router::post('/v1/nodes/heartbeat', [NodesController::class, 'heartbeat']);

/* ===========================================================================
 * 仪表盘
 * =========================================================================== */
Router::get('/v1/dashboard/stats', [DashboardController::class, 'stats'], ['auth']);

/* ===========================================================================
 * 客户端凭据
 *
 * 【原实现语义】整段用 `if ($resource === 'clients')` 包裹，
 * 内部自行判断 method + $id 组合，包括 /clients/{id}/traffic 这类子路径。
 * 这里拆成显式路由，但保持同样的处理结果。
 * =========================================================================== */
Router::get   ('/v1/clients',                [ClientsController::class, 'index'],  ['auth']);
Router::post  ('/v1/clients',                [ClientsController::class, 'create'], ['auth']);
Router::post  ('/v1/clients/{id}/report',    [ClientsController::class, 'report'], ['auth']);
Router::get   ('/v1/clients/{id}/traffic',   [ClientsController::class, 'traffic'],['auth']);
Router::put   ('/v1/clients/{id}',           [ClientsController::class, 'update'], ['auth']);
Router::delete('/v1/clients/{id}',           [ClientsController::class, 'destroy'],['auth']);

/* ===========================================================================
 * 隧道 / 代理
 *
 * 子动作路径（close / open / close-client）必须排在 /{id} 之前。
 * =========================================================================== */
Router::get   ('/v1/proxies',               [ProxiesController::class, 'index'],      ['auth']);
Router::post  ('/v1/proxies/close',         [ProxiesController::class, 'close'],      ['auth']);
Router::post  ('/v1/proxies/open',          [ProxiesController::class, 'open'],       ['auth']);
Router::post  ('/v1/proxies/close-client',  [ProxiesController::class, 'closeClient'],['auth']);
Router::post  ('/v1/proxies',               [ProxiesController::class, 'create'],     ['auth']);
Router::delete('/v1/proxies/{id}',          [ProxiesController::class, 'destroy'],    ['auth']);

/* ===========================================================================
 * 分发节点
 *
 * 顺序要点：{id}/token 与 {id}/probe 都是具体子路径，
 * 必须排在 /v1/nodes/{id} 之前。
 * =========================================================================== */
Router::get   ('/v1/nodes',            [NodesController::class, 'index'],   ['auth']);
Router::post  ('/v1/nodes',            [NodesController::class, 'create'],  ['role:admin']);
Router::post  ('/v1/nodes/{id}/probe', [NodesController::class, 'probe'],   ['role:admin']);
Router::post  ('/v1/nodes/{id}/token', [NodesController::class, 'token'],   ['role:admin']);
Router::get   ('/v1/nodes/{id}',       [NodesController::class, 'show'],    ['auth']);
Router::put   ('/v1/nodes/{id}',       [NodesController::class, 'update'],  ['role:admin']);
Router::delete('/v1/nodes/{id}',       [NodesController::class, 'destroy'], ['role:admin']);

/* ===========================================================================
 * 用户管理
 *
 * ⚠️ 这里必须是 role:admin。
 * v1.1.5 时期是 role:admin,agent —— 因为当时代理商也要管理自己名下的用户。
 * 代理商角色已整体移除（v1.2.0），若继续放着 agent，
 * 任何残留的 agent 账号都能直连 /v1/users 管理全平台用户（含改 role 提权）。
 * =========================================================================== */
Router::get   ('/v1/users',      [UsersController::class, 'index'],  ['role:admin']);
Router::post  ('/v1/users',      [UsersController::class, 'create'], ['role:admin']);
Router::get   ('/v1/users/{id}', [UsersController::class, 'show'],   ['role:admin']);
Router::put   ('/v1/users/{id}', [UsersController::class, 'update'], ['role:admin']);
Router::delete('/v1/users/{id}', [UsersController::class, 'destroy'],['role:admin']);

/* ===========================================================================
 * 【已移除】代理商路由（v1.2.0）
 *
 * 原先这里有两组共 11 条路由，指向 AgentController：
 *   /v1/agent/*   7 条 —— 代理商查看自己的业绩、下级用户、配额
 *   /v1/agents/*  4 条 —— 管理员增删改代理商实体
 *
 * 代理商角色已从系统里彻底移除，控制器文件也一并删除。
 * 路由留在这里会命中 Router 的 class_exists 保护，返回 500
 * ROUTE_HANDLER_MISSING，所以必须连注册一起删掉。
 *
 * 数据库侧：agents 表与 users.owner_agent 列**物理保留**（逻辑下线），
 * 以便回滚；代码不再读写它们。存量 agent 账号由
 * tools/migrate-drop-agent.php 统一禁用。
 * =========================================================================== */

/* ===========================================================================
 * API 密钥
 * =========================================================================== */
Router::get   ('/v1/api-keys',      [ApiKeysController::class, 'index'],  ['role:admin']);
Router::post  ('/v1/api-keys',      [ApiKeysController::class, 'create'], ['role:admin']);
Router::delete('/v1/api-keys/{id}', [ApiKeysController::class, 'destroy'],['role:admin']);

/* ===========================================================================
 * 在线更新（仅管理员）
 * =========================================================================== */
Router::get ('/v1/update/status',     [UpdateController::class, 'status'],  ['role:admin']);
Router::get ('/v1/update/check',      [UpdateController::class, 'check'],   ['role:admin']);
Router::post('/v1/update/apply',      [UpdateController::class, 'apply'],   ['role:admin']);
Router::post('/v1/update/apply-local',[UpdateController::class, 'applyLocal'],['role:admin']);

/* ===========================================================================
 * 客户端下载
 * =========================================================================== */
Router::get('/v1/downloads', [DownloadsController::class, 'index']);

/* ===========================================================================
 * 系统设置
 *
 * GET 读配置需要登录；PUT 写配置仅管理员。
 * =========================================================================== */
Router::get('/v1/settings', [SettingsController::class, 'index'],  ['auth']);
Router::put('/v1/settings', [SettingsController::class, 'update'], ['role:admin']);

/* ===========================================================================
 * 会话管理
 * =========================================================================== */
Router::get   ('/v1/sessions', [SessionsController::class, 'index'], ['auth']);
Router::delete('/v1/sessions', [SessionsController::class, 'clear'], ['role:admin']);

/* ===========================================================================
 * 对接说明（v1.1.5 新增）
 *
 * 文档元数据由后端输出，避免文档与代码脱节 ——
 * 接口改了这个页面自动跟着变，不会出现「文档说这样、实际是那样」。
 * =========================================================================== */
Router::get('/v1/docs/endpoints', [DocsController::class, 'endpoints']);
Router::get('/v1/docs/openapi',   [DocsController::class, 'openapi']);

/* ===========================================================================
 * 兜底：未匹配任何路由
 * =========================================================================== */
Router::fallback(function (string $path) {
    respond([
        'success' => false,
        'error'   => 'NOT_FOUND',
        'message' => '接口不存在：' . (Req::method()) . ' ' . $path,
        'hint'    => '可用接口清单见 GET /api.php/v1/docs/endpoints',
    ], 404);
});
