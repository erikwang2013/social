-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
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
