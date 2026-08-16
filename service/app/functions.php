<?php
/**
 * Here is your custom functions.
 */

if (!function_exists('redisAvailable')) {
    function redisAvailable(): bool
    {
        try {
            $r = new \Redis();
            $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
            return $r->ping() === true || $r->ping() === '+PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
