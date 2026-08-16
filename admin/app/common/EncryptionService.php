<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\common;

use Erikwang2013\Encryption\EncryptionManager;
use Erikwang2013\Encryption\EncryptionManagerFactory;

/**
 * API 敏感数据加解密服务
 * 用于接口传输层的敏感字段加解密
 */
class EncryptionService
{
    /**
     * 传输层解密：RSA 非对称 → AES 对称 → 明文，逐级回退。
     * 前端使用 RSA-2048 公钥加密后 Base64 传输。
     */
    public static function decryptTransmission(string $raw): string
    {
        if ($raw === '') return '';

        // 1. RSA 非对称解密
        $rsaKey = config('encryption.rsa_private_key', '');
        if ($rsaKey !== '') {
            try {
                $privateKey = base64_decode($rsaKey, true);
                $ciphertext = base64_decode($raw, true);
                if ($privateKey && $ciphertext && openssl_private_decrypt(
                    $ciphertext,
                    $decrypted,
                    $privateKey,
                    OPENSSL_PKCS1_PADDING
                ) && $decrypted !== '' && mb_check_encoding($decrypted, 'UTF-8')) {
                    return $decrypted;
                }
            } catch (\Throwable) {}
        }

        // 2. AES 对称解密（旧版兼容）
        try {
            $result = self::decrypt($raw);
            if ($result !== '') return $result;
        } catch (\Throwable) {}

        // 3. 明文回退
        return $raw;
    }

    private static ?EncryptionManager $instance = null;

    private static function getInstance(): EncryptionManager
    {
        if (self::$instance === null) {
            $config = config('encryption', []);
            $key = $config['key'] ?? 'open-admin-api-encryption-key32b';

            // EncryptionManagerFactory 要求主密钥恰好 32 字节
            if (strlen($key) !== 32) {
                $key = str_pad(substr($key, 0, 32), 32, "\0");
            }

            self::$instance = EncryptionManagerFactory::fromMasterKey(
                $key,
                'aes-256-cbc-hmac'
            );
        }
        return self::$instance;
    }

    public static function encrypt(string $value): string
    {
        if (empty($value)) return '';
        return self::getInstance()->encrypt($value);
    }

    public static function decrypt(string $value): string
    {
        if (empty($value)) return '';
        return self::getInstance()->decrypt($value);
    }

    /**
     * 手机号脱敏: 138****1234
     */
    public static function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) < 7) return $phone;
        return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
    }

    /**
     * 邮箱脱敏: a***@example.com
     */
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return $email;
        $name = $parts[0];
        return (mb_strlen($name) > 2 ? $name[0] . '***' : $name[0] . '**') . '@' . $parts[1];
    }
}
