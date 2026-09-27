<?php
/**
 * 客户端下载控制器
 * ===========================================================================
 * 只读资源：下载清单由后台维护，前台只需要能列出。
 */

final class DownloadsController
{
    /** GET /downloads */
    public static function index()
    {
        $db   = ltDb();
        $rows = $db->query(
            'SELECT id, name, version, platform, arch, size, url, updated_at AS updatedAt
             FROM downloads ORDER BY platform, arch'
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['id'] = (string) $r['id'];
        }
        unset($r);

        respond(['success' => true, 'downloads' => $rows]);
    }
}
