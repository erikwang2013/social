<?php
namespace app\common;

use Webman\Config;
use Firebase\JWT\JWT;

class JwtHelper
{
    private static function config(): array
    {
        return Config::get('plugin.erikwang2013.jwt.jwt', []);
    }

    private static function alg(array $cfg): string
    {
        return $cfg['alg'] ?? $cfg['algorithm'] ?? 'HS256';
    }

    public static function encode(int $userId, string $type, int $ttl): string
    {
        $cfg = self::config();
        return JWT::encode([
            'sub' => $userId,
            'type' => $type,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + $ttl,
            'iss' => $cfg['issuer'],
        ], $cfg['secret_key'], self::alg($cfg));
    }

    public static function decode(string $token): ?object
    {
        try {
            $cfg = self::config();
            $payload = JWT::decode($token, new \Firebase\JWT\Key($cfg['secret_key'], self::alg($cfg)));
            if (($payload->iss ?? '') !== $cfg['issuer']) {
                return null;
            }
            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** jti blacklist：登出后将 jti 写入 Redis，TTL 与 token 剩余期一致 */
    public static function revoke(string $jti, int $ttl): void
    {
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
            $redis->setex('jwt:blacklist:' . $jti, $ttl, '1');
        } catch (\Throwable $e) {
            // Redis 不可用时静默失败，避免登出接口报错
        }
    }

    public static function isRevoked(string $jti): bool
    {
        try {
            $redis = new \Redis();
            $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
            return (bool) $redis->exists('jwt:blacklist:' . $jti);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
