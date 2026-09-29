<?php
/**
 * 系统设置控制器
 * ===========================================================================
 * 读设置所有人可用；改设置锁定为管理员。
 *
 * 【白名单即安全边界】
 * 只有出现在 SETTING_DEFAULTS 里的键才会被读写。表里历史遗留的脏键
 * 不返回，PUT 传来的未知键静默忽略 —— 这样即使有人在数据库里手动塞了
 * 一行 'debugMode' => true，也不会通过接口被读出来或被前端改写。
 * ===========================================================================
 */

final class SettingsController
{
    /**
     * 取设置默认值表。
     *
     * 常量正式定义在 api.php（因为它要在 requireAuth 之前可用）。
     * 这里做一次兜底，保证控制器被单独加载（如未来的 CLI 工具）时不会致命错误。
     */
    private static function defaults(): array
    {
        if (defined('SETTING_DEFAULTS')) {
            return SETTING_DEFAULTS;
        }
        return [
            'title'              => '蓝天内网穿透',
            'webAddr'            => '0.0.0.0',
            'webPort'            => 8080,
            'sessionTimeout'     => 60,      // 分钟
            'allowRegister'      => false,
            'requireRealName'    => false,
            'defaultBandwidth'   => 16,      // Mbps
            'defaultTunnels'     => 5,
            'nodeOfflineSeconds' => 90,      // 心跳超时判定为离线的秒数
        ];
    }

    /** GET /settings */
    public static function index()
    {
        $db  = ltDb();
        $def = self::defaults();
        $out = $def;

        // 【修复】原实现返回的是一份硬编码数组，PUT 则直接 respond success 什么都不写。
        // 表现为「保存成功 → 刷新 → 全部还原」，用户以为是自己没点到。
        // 现在真实读表，并与默认值合并，保证任何时候都返回完整字段集。
        try {
            foreach ($db->query('SELECT skey, svalue FROM settings')->fetchAll() as $r) {
                if (!array_key_exists($r['skey'], $def)) {
                    continue; // 表里的历史脏键不返回
                }
                $v = json_decode((string) $r['svalue'], true);
                $out[$r['skey']] = json_last_error() === JSON_ERROR_NONE ? $v : $r['svalue'];
            }
        } catch (Throwable $e) {
            // 表不存在时直接返回默认值，不报错
        }

        respond(['success' => true, 'settings' => $out]);
    }

    /** PUT /settings */
    public static function update()
    {
        $db  = ltDb();
        $b   = Req::body();
        $def = self::defaults();

        if (!is_array($b) || !$b) {
            respond([
                'success' => false,
                'error'   => 'EMPTY_BODY',
                'message' => '没有需要保存的设置项',
            ], 400);
        }

        $saved = [];
        $stmt  = $db->prepare(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        );

        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $def)) {
                continue; // 白名单外的键静默忽略
            }

            // 类型校正：前端传的都是字符串/数字混用，这里按默认值类型归一化，
            // 避免 sessionTimeout 被存成 "60 分钟" 之类的脏值
            $d = $def[$k];
            if (is_int($d)) {
                $v = (int) $v;
            } elseif (is_bool($d)) {
                $v = in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
            } else {
                $v = (string) $v;
            }

            // ---- 业务边界校验 ----
            // 这些范围不是猜的：sessionTimeout 上限 10080 = 7 天，
            // 超过就没有「会话」的意义了；nodeOfflineSeconds 下限 30 秒，
            // 再短会把正常抖动误判成离线，运营侧会看到节点疯狂闪烁。
            if ($k === 'sessionTimeout' && ($v < 1 || $v > 10080)) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_VALUE',
                    'message' => '会话超时需在 1-10080 分钟之间',
                ], 400);
            }
            if ($k === 'webPort' && ($v < 1 || $v > 65535)) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_VALUE',
                    'message' => '端口需在 1-65535 之间',
                ], 400);
            }
            if ($k === 'nodeOfflineSeconds' && ($v < 30 || $v > 3600)) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_VALUE',
                    'message' => '心跳超时需在 30-3600 秒之间',
                ], 400);
            }
            if ($k === 'title' && mb_strlen($v) > 64) {
                respond([
                    'success' => false,
                    'error'   => 'INVALID_VALUE',
                    'message' => '站点名称不超过 64 字',
                ], 400);
            }

            $stmt->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
            $saved[$k] = $v;
        }

        respond(['success' => true, 'saved' => $saved, 'count' => count($saved)]);
    }
}
