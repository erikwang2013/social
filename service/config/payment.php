<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
/**
 * 支付渠道回调验签配置（M6b）。
 * 密钥一律走环境变量，默认空 = 渠道未配置，回调验签时返回 503 不处理。
 * 严禁在代码或本文件写死密钥。
 */
return [
    'wechat' => [
        // 微信支付平台证书 PEM（用于回调验签，与 Wechatpay-Serial 匹配）
        'platform_cert' => (string) (getenv('PAY_WECHAT_PLATFORM_CERT') ?: ''),
        // 微信支付 APIv3 密钥（32 字节，用于解密回调 resource）
        'api_v3_key' => (string) (getenv('PAY_WECHAT_API_V3_KEY') ?: ''),
    ],
    'alipay' => [
        // 支付宝开放平台 app_id（回调中核对）
        'app_id' => (string) (getenv('PAY_ALIPAY_APP_ID') ?: ''),
        // 支付宝公钥 PEM（用于 RSA2 验签，非应用私钥）
        'public_key' => (string) (getenv('PAY_ALIPAY_PUBLIC_KEY') ?: ''),
    ],
    'stripe' => [
        // Stripe webhook signing secret（whsec_ 开头）
        'webhook_secret' => (string) (getenv('PAY_STRIPE_WEBHOOK_SECRET') ?: ''),
    ],
    // 兜底定价映射 "currency:amount_cents" => coins（定价表后续切片接管；JSON 形式如 {"CNY:100":10}）
    'pricing' => (array) (json_decode((string) (getenv('PAY_PRICING_JSON') ?: ''), true) ?: []),
];
