-- ============================================
-- NexusLink 平台数据库更新 SQL
-- 执行前请备份数据库！
-- ============================================

-- 1. 添加邮箱验证字段（如果还没有的话）
ALTER TABLE `nl_users` ADD COLUMN IF NOT EXISTS `email_verified` tinyint NOT NULL DEFAULT 0 COMMENT '邮箱验证状态: 0未验证, 1已验证' AFTER `status`;

-- 2. 创建签到记录表
CREATE TABLE IF NOT EXISTS `nl_checkins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT '用户ID',
  `checkin_date` date NOT NULL COMMENT '签到日期',
  `traffic_reward` bigint NOT NULL DEFAULT 0 COMMENT '奖励流量(字节)',
  `continuous_days` int NOT NULL DEFAULT 1 COMMENT '连续签到天数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_date` (`user_id`, `checkin_date`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='签到记录表';

-- ============================================
-- 执行完成！
-- ============================================
