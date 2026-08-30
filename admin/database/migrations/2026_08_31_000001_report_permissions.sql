-- M6d 报表权限种子：菜单 + 4 项 API 权限（type=3，slug 为 method.path 供 AdminPermission 校验）+ super_admin 授权
INSERT INTO `erik_admin_permission` (`id`, `parent_id`, `name`, `slug`, `type`, `icon`, `path`, `sort`, `created_at`, `updated_at`) VALUES
('21000000000000007', '0', '报表', 'report', 1, 'bar_chart', '/admin/report', 7, NOW(), NOW()),
('30000000000000021', '21000000000000007', '查看用户报表', 'get.admin/report/users', 3, '', '', 1, NOW(), NOW()),
('30000000000000022', '21000000000000007', '查看支付报表', 'get.admin/report/payments', 3, '', '', 2, NOW(), NOW()),
('30000000000000023', '21000000000000007', '查看提现报表', 'get.admin/report/withdrawals', 3, '', '', 3, NOW(), NOW()),
('30000000000000024', '21000000000000007', '导出报表', 'post.admin/report/export', 3, '', '', 4, NOW(), NOW());

INSERT INTO `erik_admin_role_permission` (`role_id`, `permission_id`) VALUES
('10000000000000001', '21000000000000007'),
('10000000000000001', '30000000000000021'),
('10000000000000001', '30000000000000022'),
('10000000000000001', '30000000000000023'),
('10000000000000001', '30000000000000024');
