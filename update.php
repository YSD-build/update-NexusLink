<?php
/**
 * 安全网页更新入口（替代原“从第三方 CDN 直拷 index.php”的脚本）
 *
 * 安全要点：
 *  1. 仅接受 POST 请求，杜绝爬虫/扫描/误触发的 GET/HEAD 执行。
 *  2. 必须携带正确 key（部署配置中的 UPDATE_KEY），使用 hash_equals 防时序攻击。
 *  3. 复用 api/updater.php 的 Updater 类，更新源固定为本项目 GitHub Release（nexuslink-platform.zip），
 *     不再信任任何第三方 CDN，消除供应链投毒风险。
 *
 * 部署：在 api/config.php 中定义 define('UPDATE_KEY', '一段随机长字符串');
 *       未配置或 key 不匹配时一律拒绝。
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/updater.php';

// 1. 仅 POST 可触发更新
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('拒绝：更新仅接受 POST 请求');
}

// 2. 密钥校验
$key = (string) ($_POST['key'] ?? '');
$expected = defined('UPDATE_KEY') ? (string) UPDATE_KEY : '';
if ($expected === '' || !function_exists('hash_equals') || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit('拒绝：缺少或错误的更新密钥');
}

// 3. 复用 Updater，从本项目 GitHub Release 拉取更新
$updater = new Updater();
$result = $updater->doUpdate();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
