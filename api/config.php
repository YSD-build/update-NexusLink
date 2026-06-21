<?php
/**
 * NexusLink 平台配置文件
 * 请根据实际情况修改以下配置
 */

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'nexuslink');
define('DB_USER', 'root');
define('DB_PASS', 'password');
define('DB_CHARSET', 'utf8mb4');

// JWT配置
define('JWT_SECRET', 'nexuslink-platform-secret-key-2024-change-me');
define('JWT_EXPIRE', 86400); // 24小时

// 站点配置
define('SITE_NAME', 'NexusLink 内网穿透平台');
define('SITE_URL', 'http://localhost');

// 版本配置
define('CURRENT_VERSION', 'v0.2.3');
define('VERSION_CODE', 2026062201);
define('VERSION_DATE', '2026-06-22');

// 更新配置
define('UPDATE_ENABLED', true);              // 是否启用自动更新
define('UPDATE_CHANNEL', 'github');          // 更新渠道：github, custom
define('GITHUB_REPO', 'YSD-build/update-NexusLink'); // GitHub 仓库（用户名/仓库名）
define('GITHUB_TOKEN', '');                  // GitHub Token（私有仓库需要，AES-256加密存储）
define('UPDATE_SOURCE', '');                 // 自定义更新源地址（UPDATE_CHANNEL=custom 时使用）
define('UPDATE_BACKUP', true);               // 更新前是否备份

// 邮件配置
define('MAIL_ENABLE', false); // 是否启用邮件功能
define('MAIL_HOST', 'smtp.example.com');
define('MAIL_PORT', 465);
define('MAIL_USER', 'noreply@example.com');
define('MAIL_PASS', 'password');
define('MAIL_FROM', 'noreply@example.com');
define('MAIL_FROM_NAME', 'NexusLink');
define('MAIL_SECURE', 'ssl'); // ssl, tls, 或空

// 表前缀
define('TABLE_PREFIX', 'nl_');

// 默认流量限制（字节）- 100GB
define('DEFAULT_TRAFFIC_LIMIT', 107374182400);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
