-- ========================================
-- NexusLink 内网穿透平台 数据库
-- 版本: v0.1.0
-- ========================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 用户表
-- ----------------------------
DROP TABLE IF EXISTS `nl_users`;
CREATE TABLE `nl_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `email` varchar(100) DEFAULT '' COMMENT '邮箱',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `nickname` varchar(50) DEFAULT '' COMMENT '昵称',
  `role` varchar(20) NOT NULL DEFAULT 'user' COMMENT '角色: user, admin',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态: 1正常, 0禁用',
  `traffic` bigint NOT NULL DEFAULT '0' COMMENT '已用流量(字节)',
  `traffic_limit` bigint NOT NULL DEFAULT '0' COMMENT '流量限制(0不限)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ----------------------------
-- 节点表
-- ----------------------------
DROP TABLE IF EXISTS `nl_nodes`;
CREATE TABLE `nl_nodes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '节点名称',
  `host` varchar(255) NOT NULL COMMENT '节点地址',
  `port` int NOT NULL DEFAULT '7000' COMMENT '服务端口',
  `token` varchar(255) NOT NULL COMMENT '节点Token',
  `location` varchar(100) DEFAULT '' COMMENT '机房位置',
  `description` varchar(500) DEFAULT '' COMMENT '描述',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态: 1在线, 0离线',
  `max_ports` int NOT NULL DEFAULT '100' COMMENT '最大端口数',
  `min_port` int NOT NULL DEFAULT '10000' COMMENT '最小端口',
  `max_port` int NOT NULL DEFAULT '60000' COMMENT '最大端口',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='节点表';

-- ----------------------------
-- 隧道表
-- ----------------------------
DROP TABLE IF EXISTS `nl_tunnels`;
CREATE TABLE `nl_tunnels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `node_id` int unsigned NOT NULL COMMENT '节点ID',
  `name` varchar(100) NOT NULL COMMENT '隧道名称',
  `type` varchar(20) NOT NULL DEFAULT 'tcp' COMMENT '类型: tcp, udp',
  `local_addr` varchar(255) NOT NULL DEFAULT '127.0.0.1' COMMENT '本地地址',
  `local_port` int NOT NULL COMMENT '本地端口',
  `remote_port` int NOT NULL COMMENT '远程端口',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态: 1启用, 0禁用',
  `traffic` bigint NOT NULL DEFAULT '0' COMMENT '累计流量',
  `traffic_today` bigint NOT NULL DEFAULT '0' COMMENT '今日流量',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `node_id` (`node_id`),
  UNIQUE KEY `node_port` (`node_id`,`remote_port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='隧道表';

-- ----------------------------
-- 流量日志表
-- ----------------------------
DROP TABLE IF EXISTS `nl_traffic_logs`;
CREATE TABLE `nl_traffic_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tunnel_id` int unsigned NOT NULL COMMENT '隧道ID',
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `bytes_in` bigint NOT NULL DEFAULT '0' COMMENT '入站流量',
  `bytes_out` bigint NOT NULL DEFAULT '0' COMMENT '出站流量',
  `log_date` varchar(20) NOT NULL COMMENT '日期 YYYY-MM-DD',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `tunnel_id` (`tunnel_id`),
  KEY `user_id` (`user_id`),
  KEY `log_date` (`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流量日志表';

-- ----------------------------
-- 插入默认管理员
-- 账号: admin  密码: admin123
-- ----------------------------
INSERT INTO `nl_users` (`username`, `password`, `nickname`, `role`, `status`) VALUES
('admin', '$2y$10$Xxx8jDqMQUhUgtUgcuLyjuO/y91QC3um2/kuWxQ5/ZVv3AGcVDOxC', '管理员', 'admin', 1);

-- 注意: 上面的密码哈希是 password_hash('admin123', PASSWORD_BCRYPT)
-- 如果导入后登录失败，请使用以下SQL重新设置密码:
-- UPDATE nl_users SET password = '你的哈希值' WHERE username = 'admin';

SET FOREIGN_KEY_CHECKS = 1;
