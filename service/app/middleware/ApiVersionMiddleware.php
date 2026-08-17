<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class ApiVersionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();
        if (!str_starts_with($path, '/api/') || preg_match('#^/api/v\d+/#', $path)) {
            return $handler($request);
        }
        $version = (string) $request->header('X-Api-Version', 'v1');
        if (!preg_match('/^v\d+$/', $version)) {
            // json() helper's 2nd arg is JSON flags, not status — build the 400 explicitly
            return new Response(400, ['Content-Type' => 'application/json'], json_encode(
                ['code' => 400, 'message' => '非法 API 版本', 'lang_key' => 'api.version_invalid'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }
        // ponytail: no Request::withPath() in this workerman version; rebuild from rawBuffer (lossless round-trip)
        $buffer = preg_replace('#^(\S+) /[^ ]*#', '$1 /api/' . $version . substr($path, 4), $request->rawBuffer(), 1);
        return $handler(new (get_class($request))($buffer));
    }
}
