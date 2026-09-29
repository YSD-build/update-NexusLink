<?php
/**
 * 仪表盘控制器
 * ===========================================================================
 */

final class DashboardController
{
    /** GET /dashboard/stats */
    public static function stats()
    {
        $db = ltDb();

        $totalUsers    = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $onlineNodes   = (int) $db->query("SELECT COUNT(*) FROM nodes WHERE status = 'online'")->fetchColumn();
        $activeTunnels = (int) $db->query("SELECT COUNT(*) FROM proxies WHERE status = 'online'")->fetchColumn();
        $todayTraffic  = (int) $db->query('SELECT COALESCE(SUM(bytes_in + bytes_out), 0) FROM clients')->fetchColumn();

        $rows = $db->query(
            'SELECT stat_date, bytes_in, bytes_out FROM traffic_stats ORDER BY stat_date DESC LIMIT 7'
        )->fetchAll();

        // 【不再编造趋势】
        // 原实现在 traffic_stats 为空时，拿 clients 的累计总量除以 7，再乘一个
        // 「0.6 + (i % 3) * 0.2」的摆动系数，凭空造出 7 天曲线。这条曲线看着很专业，
        // 但它描述的事情从未发生 —— 运营照着它判断容量会得出完全错误的结论。
        // 现在如实返回空数组，由前端显示空态。
        $trendIsReal = (bool) $rows;

        usort($rows, function ($a, $b) {
            return strcmp($a['stat_date'], $b['stat_date']);
        });

        respond([
            'success' => true,
            'data'    => [
                'totalUsers'    => $totalUsers,
                'onlineNodes'   => $onlineNodes,
                'activeTunnels' => $activeTunnels,
                'todayTraffic'  => $todayTraffic,
                // 前端据此决定画曲线还是画空态
                'trendIsReal'   => $trendIsReal,
                'trafficTrend'  => array_map(function ($r) {
                    return [
                        'date' => substr($r['stat_date'], 5),
                        'in'   => (int) $r['bytes_in'],
                        'out'  => (int) $r['bytes_out'],
                    ];
                }, $rows),
            ],
        ]);
    }
}
