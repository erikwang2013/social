-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
-- ============================================================
-- 全量安装脚本 (MySQL 8+ / utf8mb4)
-- 合并自: service/database/migrations/m1.sql + m2.sql + m3.sql + m4.sql
--         + admin/database/migrations/open_admin.sql
-- 顺序: 基础表 → 关注/通知 → 会话/消息 → 语音/通话 → 后台管理表
-- ============================================================

-- ############### 一、基础表 (m1) ###############
CREATE TABLE IF NOT EXISTS social_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_user_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  nickname VARCHAR(64) NOT NULL DEFAULT '',
  avatar VARCHAR(255) NOT NULL DEFAULT '',
  bio VARCHAR(255) NOT NULL DEFAULT '',
  gender TINYINT NOT NULL DEFAULT 0 COMMENT '0保密 1男 2女',
  birthday DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  like_count INT UNSIGNED NOT NULL DEFAULT 0,
  comment_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_user_id (user_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  content VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_likes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_post_user (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ############### 二、关注/通知 (m2) ###############
-- 注: 原 m2.sql 的 id 缺 AUTO_INCREMENT(写入报 1364),此处已修正
CREATE TABLE IF NOT EXISTS `social_follows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `follower_id` BIGINT UNSIGNED NOT NULL COMMENT '关注者',
  `followee_id` BIGINT UNSIGNED NOT NULL COMMENT '被关注者',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follower_followee` (`follower_id`,`followee_id`),
  KEY `idx_followee` (`followee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='关注关系';

CREATE TABLE IF NOT EXISTS `social_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收者',
  `actor_id` BIGINT UNSIGNED NOT NULL COMMENT '触发者',
  `type` VARCHAR(32) NOT NULL COMMENT 'like|comment|follow',
  `ref_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'post|user',
  `ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` VARCHAR(500) NOT NULL DEFAULT '',
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知';

-- ############### 三、会话/消息 (m3) ###############
CREATE TABLE IF NOT EXISTS `social_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` TINYINT NOT NULL DEFAULT 1 COMMENT '1私聊 2群聊',
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '群名（私聊空）',
  `owner_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '群主（私聊0）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0解散',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), KEY `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话';
CREATE TABLE IF NOT EXISTS `social_conversation_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` TINYINT NOT NULL DEFAULT 0 COMMENT '0成员 1群主',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1在群 0退出',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_cid_uid` (`conversation_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会话成员';
CREATE TABLE IF NOT EXISTS `social_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `client_msg_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '客户端幂等ID',
  `type` TINYINT NOT NULL DEFAULT 1 COMMENT '1文本 2图片',
  `content` TEXT NULL COMMENT '文本内容',
  `image_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '图片URL',
  `recall_status` TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 1已撤回',
  `recall_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_cmid` (`client_msg_id`),
  KEY `idx_cid_id` (`conversation_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息';
CREATE TABLE IF NOT EXISTS `social_message_reads` (
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`conversation_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已读游标';
CREATE TABLE IF NOT EXISTS `social_device_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL COMMENT 'android/ios/harmonyos',
  `token` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_uid_platform` (`user_id`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备推送令牌';

-- ############### 四、语音/通话 (m4) ###############
-- 注: ALTER 无 IF NOT EXISTS,重复执行会报重复列,仅用于全新安装
ALTER TABLE `social_messages`
  ADD COLUMN `voice_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '语音URL',
  ADD COLUMN `voice_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '语音时长(秒)';
-- type 语义扩展：1文本 2图片 3语音
CREATE TABLE IF NOT EXISTS `social_call_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `caller_id` BIGINT UNSIGNED NOT NULL,
  `callee_id` BIGINT UNSIGNED NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1呼叫中 2接通 3未接 4取消 5结束',
  `started_at` TIMESTAMP NULL COMMENT '接通时间',
  `ended_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), KEY `idx_callee` (`callee_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='1v1通话记录';
CREATE TABLE IF NOT EXISTS `social_voice_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1开 0关',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), KEY `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房';
CREATE TABLE IF NOT EXISTS `social_voice_room_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` TINYINT NOT NULL DEFAULT 0 COMMENT '0听众 1麦位',
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_room_uid` (`room_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='语聊房成员';
CREATE TABLE IF NOT EXISTS `social_live_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1直播中 0已结束',
  `push_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'RTMP推流地址',
  `play_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'HLS播放地址',
  `started_at` TIMESTAMP NULL, `ended_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`), KEY `idx_status_updated` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='直播间';

-- ############### 五、后台管理表 (open_admin) ###############
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `erik_admin_permission`;

CREATE TABLE `erik_admin_permission` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `parent_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '父级权限ID，0表示顶级',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '权限名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '权限标识，格式: 模块.操作（如 user.create）',
  `type` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '类型: 1=菜单 2=按钮 3=API接口',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '菜单图标（仅type=1时使用）',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '前端路由路径（仅type=1时使用）',
  `sort` int unsigned NOT NULL DEFAULT '0' COMMENT '排序值，越小越靠前',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort` (`sort`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限表';

INSERT INTO `erik_admin_permission` (`id`, `parent_id`, `name`, `slug`, `type`, `icon`, `path`, `sort`, `created_at`, `updated_at`) VALUES
('21000000000000001', '0', '仪表盘', 'dashboard', 1, 'dashboard', '/dashboard', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000002', '0', '用户管理', 'user', 1, 'people', '/admin/user', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000003', '0', '角色管理', 'role', 1, 'shield', '/admin/role', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000004', '0', '权限管理', 'permission', 1, 'lock', '/admin/permission', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000005', '0', '系统配置', 'config', 1, 'settings', '/admin/config', 5, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000006', '0', '操作日志', 'log', 1, 'article', '/admin/log', 6, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000011', '21000000000000002', '批量删除', 'batch.destroy', 2, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000012', '21000000000000002', '批量启用/禁用', 'batch.status', 2, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000013', '21000000000000002', '导入用户', 'import.users', 2, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000014', '21000000000000002', '导出Excel', 'export.excel', 2, '', '', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000015', '21000000000000002', '导出PDF', 'export.pdf', 2, '', '', 5, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000016', '21000000000000002', '文件上传', 'upload', 2, '', '', 6, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000021', '21000000000000001', '查看仪表盘', 'get.admin/dashboard', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000031', '21000000000000002', '查看用户', 'get.admin/user', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000032', '21000000000000002', '创建用户', 'post.admin/user', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000033', '21000000000000002', '更新用户', 'put.admin/user', 3, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000034', '21000000000000002', '删除用户', 'delete.admin/user', 3, '', '', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000035', '21000000000000002', '批量删除用户', 'post.admin/user/batch/destroy', 3, '', '', 5, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000036', '21000000000000002', '批量启禁用', 'post.admin/user/batch/status', 3, '', '', 6, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000041', '21000000000000003', '查看角色', 'get.admin/role', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000042', '21000000000000003', '创建角色', 'post.admin/role', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000043', '21000000000000003', '更新角色', 'put.admin/role', 3, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000044', '21000000000000003', '删除角色', 'delete.admin/role', 3, '', '', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000051', '21000000000000004', '查看权限', 'get.admin/permission', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000052', '21000000000000004', '创建权限', 'post.admin/permission', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000053', '21000000000000004', '更新权限', 'put.admin/permission', 3, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000054', '21000000000000004', '删除权限', 'delete.admin/permission', 3, '', '', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000061', '21000000000000005', '查看配置', 'get.admin/config', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000062', '21000000000000005', '创建配置', 'post.admin/config', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000063', '21000000000000005', '更新配置', 'put.admin/config', 3, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000064', '21000000000000005', '删除配置', 'delete.admin/config', 3, '', '', 4, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000071', '21000000000000006', '查看日志', 'get.admin/log', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000081', '0', '个人中心-更新信息', 'put.admin/profile', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000082', '0', '个人中心-修改密码', 'put.admin/profile/password', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000083', '0', '个人中心-登出', 'post.admin/profile/logout', 3, '', '', 3, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000091', '0', '导出Excel', 'post.admin/export/excel', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000092', '0', '导出PDF', 'post.admin/export/pdf', 3, '', '', 2, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000093', '0', '导入用户', 'post.admin/import/users', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42'),
('21000000000000094', '0', '文件上传', 'post.admin/upload', 3, '', '', 1, '2026-06-12 09:09:42', '2026-06-12 09:09:42');

DROP TABLE IF EXISTS `erik_admin_role`;

CREATE TABLE `erik_admin_role` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色名称',
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色标识，用于权限判断',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '角色描述',
  `status` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '状态: 0=禁用 1=启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色表';

INSERT INTO `erik_admin_role` (`id`, `name`, `slug`, `description`, `status`, `created_at`, `updated_at`) VALUES
('10000000000000001', '超级管理员', 'super_admin', '系统超级管理员，拥有所有权限', 1, '2026-06-12 09:03:51', '2026-06-12 09:03:51');

DROP TABLE IF EXISTS `erik_admin_role_permission`;

CREATE TABLE `erik_admin_role_permission` (
  `role_id` bigint unsigned NOT NULL COMMENT '角色ID',
  `permission_id` bigint unsigned NOT NULL COMMENT '权限ID',
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色权限关联表';

INSERT INTO `erik_admin_role_permission` (`role_id`, `permission_id`) VALUES
('10000000000000001', '21000000000000001'),
('10000000000000001', '21000000000000002'),
('10000000000000001', '21000000000000003'),
('10000000000000001', '21000000000000004'),
('10000000000000001', '21000000000000005'),
('10000000000000001', '21000000000000006'),
('10000000000000001', '21000000000000011'),
('10000000000000001', '21000000000000012'),
('10000000000000001', '21000000000000013'),
('10000000000000001', '21000000000000014'),
('10000000000000001', '21000000000000015'),
('10000000000000001', '21000000000000016'),
('10000000000000001', '21000000000000021'),
('10000000000000001', '21000000000000031'),
('10000000000000001', '21000000000000032'),
('10000000000000001', '21000000000000033'),
('10000000000000001', '21000000000000034'),
('10000000000000001', '21000000000000035'),
('10000000000000001', '21000000000000036'),
('10000000000000001', '21000000000000041'),
('10000000000000001', '21000000000000042'),
('10000000000000001', '21000000000000043'),
('10000000000000001', '21000000000000044'),
('10000000000000001', '21000000000000051'),
('10000000000000001', '21000000000000052'),
('10000000000000001', '21000000000000053'),
('10000000000000001', '21000000000000054'),
('10000000000000001', '21000000000000061'),
('10000000000000001', '21000000000000062'),
('10000000000000001', '21000000000000063'),
('10000000000000001', '21000000000000064'),
('10000000000000001', '21000000000000071'),
('10000000000000001', '21000000000000081'),
('10000000000000001', '21000000000000082'),
('10000000000000001', '21000000000000083'),
('10000000000000001', '21000000000000091'),
('10000000000000001', '21000000000000092'),
('10000000000000001', '21000000000000093'),
('10000000000000001', '21000000000000094');

DROP TABLE IF EXISTS `erik_admin_user`;

CREATE TABLE `erik_admin_user` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '用户名',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码（bcrypt哈希）',
  `real_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '真实姓名',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像URL',
  `email` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邮箱（加密存储）',
  `phone` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机号（加密存储）',
  `id_card` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '身份证号（加密存储）',
  `status` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '状态: 0=禁用 1=启用',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除标记',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理用户表';

DROP TABLE IF EXISTS `erik_admin_user_role`;

CREATE TABLE `erik_admin_user_role` (
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID',
  `role_id` bigint unsigned NOT NULL COMMENT '角色ID',
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户角色关联表';

DROP TABLE IF EXISTS `erik_operation_log`;

CREATE TABLE `erik_operation_log` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `user_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '操作用户ID',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作动作，如 admin.user.store',
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '请求方法: GET|POST|PUT|DELETE',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '请求路径',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作IP',
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web' COMMENT '操作来源端: ipados|macos|windows|linux|ios|android|harmonyos|web',
  `input` text COLLATE utf8mb4_unicode_ci COMMENT '请求参数（敏感字段已脱敏）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志表';

DROP TABLE IF EXISTS `erik_system_config`;

CREATE TABLE `erik_system_config` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '配置分组标识',
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键名',
  `value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string' COMMENT '值类型: string|int|bool|json|array',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '配置项说明',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- -------------------------------------------
-- 默认管理员账号 erik / admin888
-- -------------------------------------------
INSERT INTO `erik_admin_user` (`id`, `username`, `password`, `real_name`, `status`, `created_at`, `updated_at`) VALUES
('21000000000000100', 'erik', '$2y$10$OMKpk/QJFvFg.785anhTR.n038Gmwf.yx9sOPcbS/AgIt3ChKXswG', 'erik', 1, NOW(), NOW());

INSERT INTO `erik_admin_user_role` (`user_id`, `role_id`) VALUES
('21000000000000100', '10000000000000001');

SET FOREIGN_KEY_CHECKS = 1;

-- ############### 虚拟经济 (M6a) ###############
CREATE TABLE IF NOT EXISTS social_wallets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  coins BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '虚拟币余额',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='虚拟币钱包';

CREATE TABLE IF NOT EXISTS social_currency_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'recharge/gift_sent/gift_received/admin_adjust',
  amount BIGINT NOT NULL COMMENT '有符号余额变更',
  balance_after BIGINT NOT NULL COMMENT '变更后余额',
  ref_type VARCHAR(32) NULL COMMENT '外部引用类型（如 iap_apple）',
  ref_id VARCHAR(64) NULL COMMENT '外部引用 ID（事务号/订单号）',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_ref (ref_type, ref_id),
  KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资金流水';

CREATE TABLE IF NOT EXISTS social_gift_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  coins_price BIGINT UNSIGNED NOT NULL DEFAULT 1,
  effect_key VARCHAR(32) NOT NULL DEFAULT '',
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1上架 0下架',
  sort INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='礼物目录';

CREATE TABLE IF NOT EXISTS social_gifts_given (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_uid BIGINT UNSIGNED NOT NULL,
  to_uid BIGINT UNSIGNED NOT NULL,
  room_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  room_type TINYINT NOT NULL DEFAULT 1 COMMENT '1直播 2语聊',
  gift_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  coins_total BIGINT UNSIGNED NOT NULL,
  client_ref VARCHAR(64) NULL COMMENT '客户端幂等键（重试去重）',
  created_at TIMESTAMP NULL,
  UNIQUE KEY uniq_client_ref (client_ref),
  KEY idx_from (from_uid, created_at),
  KEY idx_to (to_uid, created_at),
  KEY idx_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='送礼记录';

CREATE TABLE IF NOT EXISTS social_streamer_earnings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  streamer_uid BIGINT UNSIGNED NOT NULL,
  gift_given_id BIGINT UNSIGNED NOT NULL UNIQUE,
  ratio INT UNSIGNED NOT NULL COMMENT '主播分成比例（百分数，如 70）',
  coins_amount BIGINT UNSIGNED NOT NULL COMMENT '入账币值',
  created_at TIMESTAMP NULL,
  KEY idx_streamer (streamer_uid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主播分成入账';

CREATE TABLE IF NOT EXISTS social_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(16) NOT NULL COMMENT 'apple/google/huawei',
  sku VARCHAR(128) NOT NULL,
  coins BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1上架 0下架',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_platform_sku (platform, sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IAP 商品（SKU↔币值）';
