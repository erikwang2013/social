<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * 数据库敏感字段加解密插件配置
 *
 * 与 admin 端（open_admin 库 erik_storage_provider）共用同一密钥体系：
 * service 读活动存储服务商时需解密 key/secret，密钥与算法必须与 admin 一致。
 * 生产环境在两端 .env 配置相同的 ENCRYPTABLE_KEY（32 字节）。
 *
 * Webman—plugin 统一布局: 顶层 key/cipher/previous_keys
 */
return [
    'key' => getenv('ENCRYPTABLE_KEY') ?: 'open-admin-db-encryption-key-32b',
    'cipher' => getenv('ENCRYPTABLE_CIPHER') ?: 'aes-128-ecb',
    'previous_keys' => Erikwang2013\Encryptable\Support\PreviousKeysParser::parse(getenv('ENCRYPTION_PREVIOUS_KEYS') ?: ''),
];
