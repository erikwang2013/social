<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 中文翻译文件 — 消息文本
 *
 * key 以应用模块为前缀:
 *   auth.*      认证相关
 *   captcha.*   验证码相关
 *   crud.*      增删改查通用
 *   profile.*   个人信息
 *   batch.*     批量操作
 *   security.*  安全/权限
 *   upload.*    上传/导入
 *   generic.*   通用提示
 */

return [
    // Auth
    'login_success' => '登录成功',
    'register_success' => '注册成功',
    'logout_success' => '已登出',
    'invalid_credentials' => '用户名或密码错误',
    'account_disabled' => '账号已被禁用',
    'account_locked' => '账号已被临时锁定，请15分钟后再试',
    'token_expired' => 'Token已过期或无效',
    'token_invalid' => 'Token已失效，请重新登录',
    'not_logged_in' => '未登录',
    'refresh_invalid' => '刷新令牌无效或已过期',
    'refresh_missing' => '缺少刷新令牌',
    'username_exists' => '用户名已存在',

    // Captcha
    'captcha_error' => '验证码错误，请重试',
    'captcha_generate_failed' => '验证码生成失败',
    'captcha_missing' => '缺少验证参数',
    'captcha_verify_pass' => '验证通过',
    'captcha_verify_fail' => '验证失败，请重试',

    // CRUD
    'create_success' => '创建成功',
    'update_success' => '更新成功',
    'delete_success' => '删除成功',
    'not_found' => '资源不存在',
    'already_exists' => '记录已存在',
    'user_not_found' => '用户不存在',
    'config_not_found' => '配置项不存在',
    'config_exists' => '配置项已存在',

    // Profile
    'profile_updated' => '个人信息更新成功',
    'password_changed' => '密码修改成功',
    'old_password_wrong' => '旧密码错误',
    'password_too_short' => '新密码长度 6-32 位',
    'password_required' => '请填写旧密码和新密码',

    // Batch
    'batch_delete_success' => '批量删除成功',
    'batch_enable_success' => '批量启用成功',
    'batch_disable_success' => '批量禁用成功',
    'no_selection' => '请选择',
    'no_user_selection' => '请选择要删除的用户',
    'no_user_selection_status' => '请选择用户',
    'invalid_status' => '状态值无效',
    'invalid_ids' => '无效的ID',

    // Security
    'rate_limited' => '请求过于频繁，请稍后再试',
    'permission_denied' => '无权限访问',
    'forbidden' => '403 Forbidden',
    'payload_too_large' => '413 Payload Too Large',
    'unsupported_media' => '415 Unsupported Media Type',
    'method_not_allowed' => '405 Method Not Allowed',
    'api_version_unsupported' => '不支持的API版本',
    'password_confirm_required' => '敏感操作需要输入密码确认',
    'password_confirm_failed' => '密码验证失败',

    // Upload/Import
    'upload_success' => '上传成功',
    'upload_no_file' => '请选择文件',
    'upload_failed' => '文件上传失败',
    'upload_invalid_type' => '不支持的文件类型',
    'upload_too_large' => '文件大小不能超过 10MB',
    'import_complete' => '导入完成',
    'import_no_data' => 'Excel 文件无数据',
    'import_missing_column' => '缺少必填列',
    'import_invalid_file' => '仅支持 .xlsx 或 .xls 文件',

    // Generic
    'success' => 'success',
    'error' => 'error',
    'server_error' => '服务器内部错误',
    'validation_failed' => '参数验证失败',
];
