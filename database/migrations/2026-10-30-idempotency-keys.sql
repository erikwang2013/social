-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
--
-- 一次性迁移：幂等键由「全局唯一」改为「按用户/发送者唯一」，
-- 与服务层 where(user_id|from_uid|sender_id, client_ref|client_msg_id) 的查重查询一致。
-- 旧键约束的列集合是新键的子集，旧键唯一可推出新键唯一，新键下不会出现旧数据无法满足的重复行，
-- 因此重建索引安全。索引名保持不变，DROP + ADD 同名索引可重复执行。
--
-- 用法: mysql -u<user> -p social < database/migrations/2026-10-30-idempotency-keys.sql

ALTER TABLE `social_currency_transactions` DROP INDEX `uniq_ref`,
  ADD UNIQUE KEY `uniq_ref` (`user_id`, `ref_type`, `ref_id`);

ALTER TABLE `social_gifts_given` DROP INDEX `uniq_client_ref`,
  ADD UNIQUE KEY `uniq_client_ref` (`from_uid`, `client_ref`);

ALTER TABLE `social_messages` DROP INDEX `uk_cmid`,
  ADD UNIQUE KEY `uk_cmid` (`sender_id`, `client_msg_id`);

ALTER TABLE `social_payments` DROP INDEX `uniq_client_ref`,
  ADD UNIQUE KEY `uniq_client_ref` (`user_id`, `client_ref`);

ALTER TABLE `social_withdrawals` DROP INDEX `uniq_client_ref`,
  ADD UNIQUE KEY `uniq_client_ref` (`user_id`, `client_ref`);

-- ref_id 扩容：platform:client_ref / platform:transaction_id 等复合引用可能超过 64。
ALTER TABLE `social_currency_transactions`
  MODIFY `ref_id` VARCHAR(191) NULL COMMENT '外部引用 ID（事务号/订单号）';

-- IAP 凭证全局去重：receipt_key = platform:transaction_id（不含 sku），
-- 同一商店交易无论账号/请求 SKU 都只能入账一次。
CREATE TABLE IF NOT EXISTS `social_iap_receipts` (
  `receipt_key` VARCHAR(191) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(16) NOT NULL DEFAULT '',
  `sku` VARCHAR(128) NOT NULL DEFAULT '',
  `transaction_id` VARCHAR(191) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`receipt_key`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IAP 凭证全局去重';

-- 回填旧格式 platform:sku:transaction_id 流水的凭证门（新格式是 platform:client_ref，2 段，被 LIKE 过滤）。
-- tx 取第二冒号之后的全部内容，保留其内部冒号；receipt_key 不含 sku。
-- INSERT IGNORE 使重复执行安全。
INSERT IGNORE INTO `social_iap_receipts`
  (`receipt_key`, `user_id`, `platform`, `sku`, `transaction_id`, `created_at`, `updated_at`)
SELECT CONCAT(platform, ':', tx), user_id, platform, sku, tx, created_at, updated_at
FROM (
  SELECT
    SUBSTRING_INDEX(ref_id, ':', 1) AS platform,
    SUBSTRING_INDEX(SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1)) + 2), ':', 1) AS sku,
    SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1))
      + LENGTH(SUBSTRING_INDEX(SUBSTRING(ref_id, LENGTH(SUBSTRING_INDEX(ref_id, ':', 1)) + 2), ':', 1)) + 3) AS tx,
    user_id, created_at, updated_at
  FROM `social_currency_transactions`
  WHERE ref_type = 'iap' AND ref_id IS NOT NULL AND ref_id LIKE '%:%:%'
) AS legacy;
