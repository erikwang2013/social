<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// LIVE E2E 冒烟：REST 开播 → WS 进房 → 弹幕 → 上下麦 → 关播链路（黑盒 HTTP+WS，需要 php start.php start 运行中 + Redis）
// 测试用户在 dev 库留痕（uniqid 邮箱无碰撞，无删除用户接口）

function http(string $method, string $path, array $json = [], string $token = ''): array
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
    fwrite($sock, "$method $path HTTP/1.1\r\n$hs\r\n\r\n$body");
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

/** 极简 WS 客户端：手写握手 + masked 帧编解码（与 voice_e2e 同款） */
final class WsClient
{
    private $sock;
    private string $pending = '';

    public function __construct(string $host, int $port, string $token)
    {
        $sock = stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);
        if (!$sock) {
            throw new RuntimeException("ws connect $host:$port failed: $errstr");
        }
        $key = base64_encode(random_bytes(16));
        $req = "GET /?token=" . rawurlencode($token) . " HTTP/1.1\r\n"
            . "Host: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $req);
        stream_set_timeout($sock, 5);
        $resp = '';
        while (!str_contains($resp, "\r\n\r\n") && !feof($sock)) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $resp .= $chunk;
        }
        if (!str_contains($resp, '101')) {
            fclose($sock);
            throw new RuntimeException("ws handshake failed: $resp");
        }
        $pos = strpos($resp, "\r\n\r\n");
        $this->pending = substr($resp, $pos + 4);
        stream_set_timeout($sock, 0);
        $this->sock = $sock;
    }

    public function send(array $env): void
    {
        fwrite($this->sock, self::masked(json_encode($env, JSON_UNESCAPED_UNICODE)));
    }

    public function recvType(string $type, int $timeoutMs = 3000): ?array
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $deadline) {
            $env = $this->recv(200);
            if ($env && ($env['type'] ?? '') === $type) {
                return $env;
            }
        }
        return null;
    }

    public function close(): void
    {
        fclose($this->sock);
    }

    private function recv(int $timeoutMs): ?array
    {
        if ($this->pending !== '') {
            $buf = $this->pending;
            $this->pending = '';
            return $this->decodeFrame($buf);
        }
        stream_set_timeout($this->sock, 0, $timeoutMs * 1000);
        $hdr = fread($this->sock, 2);
        if ($hdr === false || strlen($hdr) < 2) {
            return null;
        }
        $len = ord($hdr[1]) & 0x7f;
        if ($len === 126) {
            $ext = fread($this->sock, 2);
            if ($ext === false || strlen($ext) < 2) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->sock, 8);
            if ($ext === false || strlen($ext) < 8) {
                return null;
            }
            $hi = unpack('N', substr($ext, 0, 4))[1];
            $lo = unpack('N', substr($ext, 4, 4))[1];
            $len = $hi * 4294967296 + $lo;
        }
        $payload = '';
        while (strlen($payload) < $len) {
            $chunk = fread($this->sock, $len - strlen($payload));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $payload .= $chunk;
        }
        if ((ord($hdr[0]) & 0x0f) !== 1) {
            return null;
        }
        $dec = json_decode($payload, true);
        return is_array($dec) ? $dec : null;
    }

    private function decodeFrame(string $buf): ?array
    {
        if (strlen($buf) < 2) {
            $this->pending = $buf;
            return null;
        }
        $len = ord($buf[1]) & 0x7f;
        $off = 2;
        if ($len === 126 && strlen($buf) >= 4) {
            $len = unpack('n', substr($buf, 2, 2))[1];
            $off = 4;
        } elseif ($len === 127 && strlen($buf) >= 10) {
            $hi = unpack('N', substr($buf, 2, 4))[1];
            $lo = unpack('N', substr($buf, 6, 4))[1];
            $len = $hi * 4294967296 + $lo;
            $off = 10;
        }
        if (strlen($buf) < $off + $len) {
            $this->pending = $buf;
            return null;
        }
        $this->pending = substr($buf, $off + $len);
        $dec = json_decode(substr($buf, $off, $len), true);
        return is_array($dec) ? $dec : null;
    }

    private static function masked(string $payload): string
    {
        $len = strlen($payload);
        $mask = random_bytes(4);
        $hdr = chr(0x81);
        if ($len < 126) {
            $hdr .= chr(0x80 | $len);
        } else {
            $hdr .= chr(0x80 | 126) . pack('n', $len);
        }
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $payload[$i] ^ $mask[$i % 4];
        }
        return $hdr . $mask . $out;
    }
}

$pass = 'e2e-pass';
$aRes = http('POST', '/api/v1/auth/register', ['email' => uniqid() . '@e2e.la', 'password' => $pass, 'nickname' => 'E2E-LA']);
check($aRes['code'] === 0 && isset($aRes['data']['access_token']), 'A 注册成功');
$aTok = $aRes['data']['access_token'];
$aId = (int) (http('GET', '/api/v1/auth/me', [], $aTok)['data']['user']['id'] ?? 0);
$bRes = http('POST', '/api/v1/auth/register', ['email' => uniqid() . '@e2e.lb', 'password' => $pass, 'nickname' => 'E2E-LB']);
check($bRes['code'] === 0, 'B 注册成功');
$bTok = $bRes['data']['access_token'];
$bId = (int) (http('GET', '/api/v1/auth/me', [], $bTok)['data']['user']['id'] ?? 0);
check($aId > 0 && $bId > 0, 'A/B 用户 id 就绪');

// 1. A 开播 → room_id + 已签真实 id 的 push/play URL
$room = http('POST', '/api/v1/live/rooms', ['title' => 'E2E 直播 ' . uniqid()], $aTok);
$roomId = (int) ($room['data']['room_id'] ?? 0);
check($room['code'] === 0 && $roomId > 0, 'A 开播成功');
check(preg_match('#^rtmp://[^/]+/live/' . $roomId . '$#', (string) ($room['data']['push_url'] ?? '')) === 1, 'push_url 为 rtmp 且含真实 room_id');
check(preg_match('#^http://[^/]+/hls/' . $roomId . '\.m3u8$#', (string) ($room['data']['play_url'] ?? '')) === 1, 'play_url 为 hls 且含真实 room_id');

// 2. A/B WS 进房 → 双方收到 live_join 广播
$wa = new WsClient('127.0.0.1', 8789, $aTok);
$wb = new WsClient('127.0.0.1', 8789, $bTok);
check($wa->recvType('ready') !== null && $wb->recvType('ready') !== null, 'A/B 握手就绪');
$wa->send(['type' => 'live_join', 'data' => ['room_id' => $roomId]]);
check(($j = $wa->recvType('live_join')) !== null && (int) $j['data']['user_id'] === $aId, 'A live_join 广播（房主进房）');
$wb->send(['type' => 'live_join', 'data' => ['room_id' => $roomId]]);
$jA = $wa->recvType('live_join');
$jB = $wb->recvType('live_join');
check($jA !== null && (int) $jA['data']['user_id'] === $bId, 'B live_join 广播给 A');
check($jB !== null && (int) $jB['data']['user_id'] === $bId, 'B 收到自己的 live_join');

// 3. 详情：在线 2；push_url 仅房主可见
$detail = http('GET', '/api/v1/live/rooms/' . $roomId, [], $aTok);
check((int) ($detail['data']['online_count'] ?? 0) === 2, '详情 online_count=2');
check((string) ($detail['data']['push_url'] ?? '') !== '', '详情房主可见 push_url');
$detailB = http('GET', '/api/v1/live/rooms/' . $roomId, [], $bTok);
check((string) ($detailB['data']['push_url'] ?? 'x') === '', '详情观众 push_url 为空');

// 4. B 弹幕 → A/B 均收到 danmaku 帧，详情弹幕列表含内容
$wb->send(['type' => 'danmaku_send', 'data' => ['room_id' => $roomId, 'content' => 'E2E 弹幕']]);
$dmA = $wa->recvType('danmaku');
$dmB = $wb->recvType('danmaku');
check($dmA !== null && (string) ($dmA['data']['content'] ?? '') === 'E2E 弹幕', 'A 收到 danmaku 帧');
check($dmB !== null && (int) $dmB['data']['user_id'] === $bId, 'B 收到自己的 danmaku 帧');
$detail = http('GET', '/api/v1/live/rooms/' . $roomId, [], $aTok);
$last = (string) ($detail['data']['danmaku'][0]['content'] ?? '');
check($last === 'E2E 弹幕', '详情弹幕列表含最新弹幕');

// 5. B 上麦 → A 收 live_mic_up，详情 mic_users 含 B；B 下麦 → A 收 live_mic_down
$wb->send(['type' => 'live_mic_up', 'data' => ['room_id' => $roomId]]);
check(($mu = $wa->recvType('live_mic_up')) !== null && (int) $mu['data']['user_id'] === $bId, 'B live_mic_up 广播给 A');
$wb->recvType('live_mic_up');
$detail = http('GET', '/api/v1/live/rooms/' . $roomId, [], $aTok);
check(in_array($bId, array_map('intval', $detail['data']['mic_users'] ?? []), true), '详情 mic_users 含 B');
$wb->send(['type' => 'live_mic_down', 'data' => ['room_id' => $roomId]]);
check(($md = $wa->recvType('live_mic_down')) !== null && (int) $md['data']['user_id'] === $bId, 'B live_mic_down 广播给 A');

// 6. B 退房 → A 收 live_leave（B 自己无回显）；B 重进
$wb->send(['type' => 'live_leave', 'data' => ['room_id' => $roomId]]);
check(($lv = $wa->recvType('live_leave')) !== null && (int) $lv['data']['user_id'] === $bId, 'B live_leave 广播给 A');
$wb->send(['type' => 'live_join', 'data' => ['room_id' => $roomId]]);
check(($rj = $wa->recvType('live_join')) !== null && (int) $rj['data']['user_id'] === $bId, 'B 重进房 live_join 广播给 A');

// 7. 非房主关播 → 403
$closeB = http('POST', '/api/v1/live/rooms/' . $roomId . '/close', [], $bTok);
check($closeB['code'] === 403, 'B 关播被拒 403');

// 8. A 关播 → A/B 均收 live_closed；详情 404；列表不再出现
$close = http('POST', '/api/v1/live/rooms/' . $roomId . '/close', [], $aTok);
check($close['code'] === 0, 'A 关播成功');
check($wa->recvType('live_closed') !== null, 'A 收到 live_closed');
check($wb->recvType('live_closed') !== null, 'B 收到 live_closed');
$detail = http('GET', '/api/v1/live/rooms/' . $roomId, [], $aTok);
check(($detail['code'] ?? -1) === 404, '关播后详情 404');
$rooms = http('GET', '/api/v1/live/rooms', [], $aTok);
$gone = true;
foreach ($rooms['data']['list'] ?? [] as $r) {
    if ((int) $r['id'] === $roomId) {
        $gone = false;
    }
}
check($gone, '关播后列表不再出现该房间');

$wa->close();
$wb->close();
echo "LIVE E2E OK\n";
