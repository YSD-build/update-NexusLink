<?php
/**
 * API 密钥控制器（管理员专属）
 * ===========================================================================
 * API 密钥等同于长期凭据，仅管理员可管理。
 */

final class ApiKeysController
{
    /** GET /api-keys */
    public static function index()
    {
        $db   = ltDb();
        $rows = $db->query(
            'SELECT `key`, note, scopes, created_at AS createdAt, last_used AS lastUsed
             FROM api_keys ORDER BY id DESC'
        )->fetchAll();

        foreach ($rows as &$r) {
            $sc = json_decode((string) $r['scopes'], true);
            $r['scopes'] = is_array($sc) && $sc ? $sc : ['admin'];
        }
        unset($r);

        respond(['success' => true, 'keys' => $rows]);
    }

    /** POST /api-keys */
    public static function create()
    {
        $db = ltDb();
        $b  = Req::body();

        $key = trim($b['key'] ?? '');
        if ($key === '') {
            $key = 'lt_' . bin2hex(random_bytes(14));
        }
        checkLen($key, 'api_key', 'key');

        $st = $db->prepare('SELECT id FROM api_keys WHERE `key` = ?');
        $st->execute([$key]);
        if ($st->fetch()) {
            respond(['success' => false, 'error' => 'CONFLICT'], 409);
        }

        // scopes 控制该密钥权限；缺省为全权 admin
        $scopes = $b['scopes'] ?? ['admin'];
        if (!is_array($scopes) || !$scopes) {
            $scopes = ['admin'];
        }
        $scopes = array_values(array_filter(array_map('strval', $scopes)));

        $db->prepare('INSERT INTO api_keys (`key`, note, scopes, created_at) VALUES (?, ?, ?, CURDATE())')
           ->execute([$key, $b['note'] ?? '', json_encode($scopes, JSON_UNESCAPED_UNICODE)]);

        respond(['success' => true, 'key' => $key, 'scopes' => $scopes]);
    }

    /** DELETE /api-keys/{key} —— 注意这里 {key} 是密钥本身，不是数字 id */
    public static function destroy(string $key)
    {
        $db = ltDb();
        $db->prepare('DELETE FROM api_keys WHERE `key` = ?')->execute([$key]);
        respond(['success' => true]);
    }
}
