<?php
/**
 * 会话管理控制器（管理员专属）
 * ===========================================================================
 * 用于排查「谁还连着」以及一键强制所有人重新登录。
 */

final class SessionsController
{
    /** GET /sessions —— 当前在线会话列表 */
    public static function index()
    {
        $db = ltDb();

        // 老库可能还没有 expires_at / last_active 列，按列存在性拼 SQL，
        // 避免整表查询直接 500。
        if (!hasColumn($db, 'sessions', 'expires_at')) {
            $rows = $db->query(
                'SELECT token, username, created_at AS createdAt,
                        NULL AS expiresAt, NULL AS lastActive
                   FROM sessions ORDER BY created_at DESC LIMIT 200'
            )->fetchAll();
        } else {
            $rows = $db->query(
                'SELECT token, username, created_at AS createdAt,
                        expires_at AS expiresAt, last_active AS lastActive
                   FROM sessions ORDER BY created_at DESC LIMIT 200'
            )->fetchAll();
        }

        // 只回显 token 前缀：完整 token 等同凭据，不该在列表里泄露
        foreach ($rows as &$r) {
            $r['token'] = substr((string) $r['token'], 0, 8) . '…';
        }
        unset($r);

        respond(['success' => true, 'sessions' => $rows]);
    }

    /**
     * DELETE /sessions —— 清空全部会话（强制所有人重新登录）
     *
     * 默认保留调用者自己的会话，避免管理员点了按钮把自己也踢出去；
     * 需要连自己一起清时带 ?all=1。
     */
    public static function clear()
    {
        $db = ltDb();

        $before = (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();

        $keepSelf = Req::queryBool('all') === false;   // 未传 all=1 时保留自己
        $ownToken = bearerToken();

        if ($keepSelf && $ownToken !== '') {
            $db->prepare('DELETE FROM sessions WHERE token <> ?')->execute([$ownToken]);
        } else {
            $db->exec('DELETE FROM sessions');
        }

        $after = (int) $db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();

        respond([
            'success'   => true,
            'cleared'   => max(0, $before - $after),
            'remaining' => $after,
            'keptSelf'  => $keepSelf,
        ]);
    }
}
