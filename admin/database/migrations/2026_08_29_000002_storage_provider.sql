-- M6c CDN 存储服务商：表 + 本地服务商种子 + 5 项权限种子（+ super_admin 授权）
DROP TABLE IF EXISTS `erik_storage_provider`;

CREATE TABLE `erik_storage_provider` (
  `id` bigint unsigned NOT NULL COMMENT '主键ID，由snowflake生成',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '服务商名称（展示用）',
  `driver` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 's3' COMMENT 'local|s3',
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'S3 endpoint（R2/OSS/COS/B2）',
  `region` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'AccessKey（encryptable 加密）',
  `secret` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'SecretKey（encryptable 加密）',
  `bucket` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cdn_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'CDN 公开读域名',
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT '活动服务商（唯一 1）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDN存储服务商';

INSERT INTO `erik_storage_provider` (`id`, `name`, `driver`, `is_active`) VALUES
('30000000000000001', '本地存储', 'local', 1);

-- 权限种子（5 项，type=3 API接口；列名以 erik_admin_permission 建表为准：icon/path/sort）
INSERT INTO `erik_admin_permission` (`id`, `parent_id`, `name`, `slug`, `type`, `icon`, `path`, `sort`, `created_at`, `updated_at`) VALUES
('30000000000000011', '21000000000000005', 'CDN 服务商', 'get.admin/storage/providers', 3, '', '', 5, NOW(), NOW()),
('30000000000000012', '21000000000000005', '创建服务商', 'post.admin/storage/providers', 3, '', '', 6, NOW(), NOW()),
('30000000000000013', '21000000000000005', '更新服务商', 'put.admin/storage/providers', 3, '', '', 7, NOW(), NOW()),
('30000000000000014', '21000000000000005', '删除服务商', 'delete.admin/storage/providers', 3, '', '', 8, NOW(), NOW()),
('30000000000000015', '21000000000000005', '激活服务商', 'post.admin/storage/providers/activate', 3, '', '', 9, NOW(), NOW());

-- super_admin 授权（AdminPermission 按 slug 校验，缺关联则 403）
INSERT INTO `erik_admin_role_permission` (`role_id`, `permission_id`) VALUES
('10000000000000001', '30000000000000011'),
('10000000000000001', '30000000000000012'),
('10000000000000001', '30000000000000013'),
('10000000000000001', '30000000000000014'),
('10000000000000001', '30000000000000015');
