<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
/**
 * IAP 凭证校验渠道配置（M6a 阶段3）。
 * 密钥一律走环境变量，默认空 = 渠道未配置，校验时返回 503 不发起网络请求。
 * 严禁在代码或本文件写死密钥。
 */
return [
    'apple' => [
        // App Store Connect 共享密钥（in-app purchase shared secret）
        'shared_secret' => (string) (getenv('IAP_APPLE_SHARED_SECRET') ?: ''),
        // App 的 bundle id（预留，用于核对 receipt 归属）
        'bundle_id' => (string) (getenv('IAP_APPLE_BUNDLE_ID') ?: ''),
    ],
    'google' => [
        // Google Play 应用包名
        'package' => (string) (getenv('IAP_GOOGLE_PACKAGE') ?: ''),
        // 服务账号邮箱（google service account）
        'service_account_email' => (string) (getenv('IAP_GOOGLE_SA_EMAIL') ?: ''),
        // 服务账号私钥（PEM，换行用 \n 转义）
        'private_key' => (string) (getenv('IAP_GOOGLE_SA_PRIVATE_KEY') ?: ''),
    ],
    'huawei' => [
        // 华为 AGC 应用 appid / app secret
        'app_id' => (string) (getenv('IAP_HUAWEI_APP_ID') ?: ''),
        'app_secret' => (string) (getenv('IAP_HUAWEI_APP_SECRET') ?: ''),
        // 华为应用包名
        'package' => (string) (getenv('IAP_HUAWEI_PACKAGE') ?: ''),
        // IAP 订单查询端点（海外默认；国内环境可用 orders-iap.cloud.huawei.com.cn 覆盖）
        'endpoint' => (string) (getenv('IAP_HUAWEI_ENDPOINT') ?: 'https://orders-dre.iap.hicloud.com'),
    ],
    // 校验 HTTP 超时（秒）
    'timeout' => (int) (getenv('IAP_TIMEOUT') ?: 10),
];
