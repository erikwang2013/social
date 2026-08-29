<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// STORAGE E2E 冒烟：图片上传 → Storage（local 落盘 / s3 传桶）→ URL 前缀与活动服务商匹配（黑盒 HTTP，需要 php start.php start 运行中 + Redis + open_admin 库 erik_storage_provider 种子已落）
// 测试用户在 dev 库留痕（uniqid 邮箱无碰撞，无删除用户接口）；local 落盘文件用毕 unlink
require __DIR__ . '/../vendor/autoload.php';

function http(string $method, string $path, array $json = [], string $token = '', array $headers = []): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    stream_set_timeout($sock, 5);
    $body = $json === [] ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $hs = "Host: 127.0.0.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close";
    if ($token !== '') {
        $hs .= "\r\nAuthorization: Bearer $token";
    }
    foreach ($headers as $k => $v) {
        $hs .= "\r\n$k: $v";
    }
    fwrite($sock, "$method $path HTTP/1.1\r\n$hs\r\n\r\n$body");
    $resp = '';
    while (!feof($sock)) {
        $resp .= fread($sock, 8192);
    }
    fclose($sock);
    $parts = explode("\r\n\r\n", $resp, 2);
    return json_decode($parts[1] ?? '', true) ?? [];
}

/** 原始响应（静态文件读取）：返回 [status, body]，按 Content-Length 读取 */
function httpRaw(string $path, string $token, array $headers = []): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    stream_set_timeout($sock, 5);
    $hs = "Host: 127.0.0.1\r\nConnection: close";
    if ($token !== '') {
        $hs .= "\r\nAuthorization: Bearer $token";
    }
    foreach ($headers as $k => $v) {
        $hs .= "\r\n$k: $v";
    }
    fwrite($sock, "GET $path HTTP/1.1\r\n$hs\r\n\r\n");
    $hdr = '';
    while (!str_contains($hdr, "\r\n\r\n") && !feof($sock)) {
        $hdr .= fread($sock, 8192);
    }
    [$head, $body] = explode("\r\n\r\n", $hdr, 2);
    $status = (int) explode(' ', $head, 3)[1];
    $len = 0;
    if (preg_match('/Content-Length:\s*(\d+)/i', $head, $m)) {
        $len = (int) $m[1];
    }
    while (strlen($body) < $len && !feof($sock)) {
        $chunk = fread($sock, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    fclose($sock);
    return [$status, $body];
}

/** multipart 上传：field=image 文件上传 */
function httpUpload(string $path, string $file, string $token, array $headers = []): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    stream_set_timeout($sock, 5);
    $boundary = '----e2e' . uniqid();
    $data = file_get_contents($file);
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"t.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n$data\r\n--$boundary--\r\n";
    $hs = "Host: 127.0.0.1\r\nContent-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: " . strlen($body) . "\r\nConnection: close";
    if ($token !== '') {
        $hs .= "\r\nAuthorization: Bearer $token";
    }
    foreach ($headers as $k => $v) {
        $hs .= "\r\n$k: $v";
    }
    fwrite($sock, "POST $path HTTP/1.1\r\n$hs\r\n\r\n$body");
    $resp = '';
    while (!feof($sock)) {
        $resp .= fread($sock, 8192);
    }
    fclose($sock);
    $parts = explode("\r\n\r\n", $resp, 2);
    return json_decode($parts[1] ?? '', true) ?? [];
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

/** 活动服务商（open_admin 库直读，root 免密）：返回 [driver, cdn_url] */
function activeProvider(): array
{
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=open_admin;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $row = $pdo->query('SELECT driver, cdn_url FROM erik_storage_provider WHERE is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('open_admin 无活动服务商');
    }
    return [$row['driver'], (string) $row['cdn_url']];
}

// ---- 生成 1px JPEG 样本 ----
$sample = '/tmp/storage_e2e_sample.jpg';
register_shutdown_function(static function () use ($sample): void {
    @unlink($sample);
});
$img = imagecreatetruecolor(64, 64);
imagefill($img, 0, 0, imagecolorallocate($img, 80, 160, 240));
imagejpeg($img, $sample, 90);
imagedestroy($img);
check(is_file($sample), '生成 64x64 JPEG 样本');

$v1 = ['X-Api-Version' => 'v1'];

// ---- 登录 ----
$aRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.a', 'password' => 'e2e-pass', 'nickname' => 'E2E-STORE'], '', $v1);
check($aRes['code'] === 0 && isset($aRes['data']['access_token']), '注册成功拿到 access_token');
$tok = $aRes['data']['access_token'];

// ---- 上传图片：URL 前缀与活动服务商匹配 ----
[$driver, $cdnUrl] = activeProvider();
$up = httpUpload('/api/im/image', $sample, $tok, $v1);
check($up['code'] === 0, '上传图片 code=0');
$url = (string) ($up['data']['url'] ?? '');
check($url !== '' && ($up['data']['width'] ?? 0) === 64 && ($up['data']['height'] ?? 0) === 64, '返回 url + 宽高 64x64');

if ($driver === 'local') {
    check((bool) preg_match('#^/upload/\d{4}-\d{2}-\d{2}/[a-f0-9]{32}\.jpg$#', $url), "local 活动 → 相对 URL /upload/...（$url）");
    $diskFile = public_path() . $url;
    check(is_file($diskFile) && filesize($diskFile) > 0, '文件已落盘 public' . $url);
    register_shutdown_function(static function () use ($diskFile): void {
        @unlink($diskFile);
    });
    [$st, $body] = httpRaw($url, $tok, $v1);
    check($st === 200 && strlen($body) > 0, 'GET 静态 URL → 200 图片字节');
} else {
    check(str_starts_with($url, rtrim($cdnUrl, '/') . '/'), "s3 活动 → CDN 绝对 URL 前缀匹配（$url）");
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    check(is_string($resp) && strlen($resp) > 0, 'GET CDN URL → 200 图片字节');
}

echo "STORAGE E2E OK\n";
