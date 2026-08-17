-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
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
