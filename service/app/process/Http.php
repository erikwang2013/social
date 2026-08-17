<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\process;

use Psr\Log\LoggerInterface;
use Webman\App;
use Workerman\Protocols\Http\Response;

/**
 * http worker handler。onMessage 在路由分发前做 API 版本化路径重写：
 * /api/xxx + X-Api-Version: vN → /api/vN/xxx（webman 中间件晚于路由分发，故在此层处理，见 M4 spec §2）
 */
class Http extends App
{
    public function __construct(
        string $requestClass = \support\Request::class,
        ?LoggerInterface $logger = null,
        string $appPath = '',
        string $publicPath = ''
    ) {
        parent::__construct($requestClass, $logger ?? \support\Log::channel('default'), $appPath, $publicPath);
    }

    public function onMessage($connection, $request)
    {
        $path = $request->path();
        if (str_starts_with($path, '/api/') && !preg_match('#^/api/v\d+#', $path)) {
            $version = (string) $request->header('X-Api-Version', 'v1');
            if (!preg_match('/^v\d+$/', $version)) {
                $body = json_encode(['code' => 400, 'message' => '非法 API 版本', 'lang_key' => 'api.version_invalid'], JSON_UNESCAPED_UNICODE);
                $connection->send(new Response(400, ['Content-Type' => 'application/json'], $body));
                return;
            }
            $newPath = '/api/' . $version . substr($path, 4);
            $buffer = $request->rawBuffer();
            $nl = strpos($buffer, "\r\n");
            $line = substr($buffer, 0, $nl);
            $buffer = str_replace($path, $newPath, $line) . substr($buffer, $nl);
            $request = new (get_class($request))($buffer);
        }
        parent::onMessage($connection, $request);
    }
}
