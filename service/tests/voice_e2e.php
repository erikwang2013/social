<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// VOICE E2E 冒烟：API 版本化 + 语音消息 + 1v1 通话信令 + 语聊房（黑盒 HTTP+WS，需要 php start.php start 运行中 + Redis + m4.sql 已应用）
// 测试用户在 dev 库留痕（uniqid 邮箱无碰撞，无删除用户接口）；样本 /tmp/voice_e2e_sample.m4a 用毕 unlink

function http(string $method, string $path, array $json = [], string $token = '', array $headers = []): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
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

/** 原始响应（语音文件读取）：返回 [status, body]。按 Content-Length 读取，
 * 不依赖 EOF —— 版本门 400 响应无 Connection: close（Http.php 提前 return），EOF 读取会挂起 */
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

/** multipart 上传：field=voice 文件上传 */
function httpUpload(string $path, string $file, string $token, array $headers = []): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    $boundary = '----e2e' . uniqid();
    $data = file_get_contents($file);
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"voice\"; filename=\"sample.m4a\"\r\nContent-Type: audio/m4a\r\n\r\n$data\r\n--$boundary--\r\n";
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

/** 极简 WS 客户端：手写握手 + masked 帧编解码 */
final class WsClient
{
    private $sock;
    /** 握手响应后同段到达的帧（服务端 101 与 ready 可能同 TCP 段） */
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
        // 只读到头部结束符：服务端就绪帧可能与 101 响应同段到达，fread 整读会吞掉首帧
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

    /** 过滤接收直到指定 type（跳过 ping/pong），超时返回 null */
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
        $b0 = ord($hdr[0]);
        $len = ord($hdr[1]) & 0x7f;
        if ($len === 126) {
            $len = unpack('n', fread($this->sock, 2))[1];
        } elseif ($len === 127) {
            $hi = unpack('N', fread($this->sock, 4))[1];
            $lo = unpack('N', fread($this->sock, 4))[1];
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
        if (($b0 & 0x0f) !== 1) {
            return null;
        }
        $dec = json_decode($payload, true);
        return is_array($dec) ? $dec : null;
    }

    /** 从原始字节流解出首个服务端帧（未 masked），余量回存 pending */
    private function decodeFrame(string $buf): ?array
    {
        if (strlen($buf) < 2) {
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

// ---- 生成 1s m4a 样本 ----
$sample = '/tmp/voice_e2e_sample.m4a';
exec('ffmpeg -f lavfi -i anullsrc=r=8000:cl=mono -t 1 -c:a aac -b:a 32k -y ' . escapeshellarg($sample) . ' 2>/dev/null', $_, $ffCode);
check($ffCode === 0 && is_file($sample), 'ffmpeg 生成 1s m4a 样本');

$v1 = ['X-Api-Version' => 'v1'];

// ---- 版本化 ----
$pass = 'e2e-pass';
// 1. 无版本路径 + X-Api-Version: v1 → 重写到 /api/v1/auth/register（版本化生效）
$aRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.a', 'password' => $pass, 'nickname' => 'E2E-VA'], '', $v1);
check($aRes['code'] === 0 && isset($aRes['data']['access_token']), '无版本路径 /api/auth/register + v1 头成功');
$aTok = $aRes['data']['access_token'];
$bRes = http('POST', '/api/auth/register', ['email' => uniqid() . '@e2e.b', 'password' => $pass, 'nickname' => 'E2E-VB'], '', $v1);
check($bRes['code'] === 0, 'B 注册成功');
$bTok = $bRes['data']['access_token'];
// 2. 旧路径 /api/v1/auth/me 无版本头兼容
$meB = http('GET', '/api/v1/auth/me', [], $bTok);
$bId = (int) ($meB['data']['user']['id'] ?? 0);
$meA = http('GET', '/api/v1/auth/me', [], $aTok);
$aId = (int) ($meA['data']['user']['id'] ?? 0);
check($aId > 0 && $bId > 0, '旧路径 /api/v1/auth/me 兼容（无版本头）');
// 3. 非法版本 → 400 + api.version_invalid
[$badStatus, $badBody] = httpRaw('/api/auth/me', $aTok, ['X-Api-Version' => 'banana']);
check($badStatus === 400 && str_contains($badBody, 'api.version_invalid'), '非法版本 400 + api.version_invalid');

// ---- 语音消息 ----
// 4. 上传语音（版本化路径 + v1 头）→ voice_url / voice_duration=1；GET 回读 200
$up = httpUpload('/api/im/voice', $sample, $aTok, $v1);
$voiceUrl = (string) ($up['data']['voice_url'] ?? '');
check($up['code'] === 0 && preg_match('#^/voice/[a-f0-9]{32}\.m4a$#', $voiceUrl) && (int) ($up['data']['voice_duration'] ?? 0) === 1, '上传语音 voice_url + voice_duration=1');
[$fileStatus, $fileBody] = httpRaw('/api/v1' . $voiceUrl, $aTok, $v1);
check($fileStatus === 200 && strlen($fileBody) > 0, 'GET voice_url → 200 音频');

// 会话 + 语音消息收发
$conv = http('POST', '/api/v1/im/conversations', ['type' => 1, 'member_ids' => [$bId]], $aTok, $v1);
check($conv['code'] === 0, 'A 建私聊会话');
$cid = (int) $conv['data']['id'];

$wa = new WsClient('127.0.0.1', 8789, $aTok);
$wb = new WsClient('127.0.0.1', 8789, $bTok);
check($wa->recvType('ready') !== null && $wb->recvType('ready') !== null, 'A/B 握手就绪');

$cmid = 'e2e-v-' . uniqid();
$wa->send(['type' => 'send', 'seq' => 1, 'data' => ['conversation_id' => $cid, 'client_msg_id' => $cmid, 'type' => 3, 'voice_url' => $voiceUrl, 'voice_duration' => 1]]);
$ack = $wa->recvType('ack');
check($ack !== null && $ack['data']['client_msg_id'] === $cmid, 'A 收到语音消息 ack');
$mid = (int) $ack['data']['message_id'];
// 5. B 收 message 帧 type=3 带 voice_url
$env = $wb->recvType('message');
check($env !== null && (int) $env['data']['type'] === 3 && $env['data']['voice_url'] === $voiceUrl && (int) $env['data']['voice_duration'] === 1, 'B 收到 message 帧 type=3 带 voice_url');

// 6. B 拉历史 → voice_duration=1
$hist = http('GET', '/api/v1/im/conversations/' . $cid . '/messages', [], $bTok, $v1);
$hit = null;
foreach ($hist['data']['list'] ?? [] as $row) {
    if ((int) $row['id'] === $mid) {
        $hit = $row;
    }
}
check($hit !== null && (int) $hit['type'] === 3 && (int) $hit['voice_duration'] === 1 && $hit['voice_url'] === $voiceUrl, 'B 历史语音消息 voice_duration=1');

// ---- 1v1 通话信令 ----
$wa->send(['type' => 'call_invite', 'data' => ['to_user_id' => $bId]]);
$invA = $wa->recvType('call_invite');
$invB = $wb->recvType('call_invite');
$callId = (int) ($invA['data']['call_id'] ?? 0);
check($invA !== null && $invB !== null && $callId > 0 && (int) $invB['data']['call_id'] === $callId, 'call_invite 双方收到同一 call_id');
$wb->send(['type' => 'call_accept', 'data' => ['call_id' => $callId]]);
$acc = $wa->recvType('call_accept');
check($acc !== null && (int) $acc['data']['call_id'] === $callId, 'B call_accept → A 收到');
// 7. offer 转发断言（A 发，B 收）
$wa->send(['type' => 'call_offer', 'data' => ['call_id' => $callId, 'sdp' => 'e2e-offer-sdp']]);
$off = $wb->recvType('call_offer');
check($off !== null && (int) $off['data']['call_id'] === $callId && $off['data']['sdp'] === 'e2e-offer-sdp', 'call_offer 转发（A 发 B 收）');
$wb->send(['type' => 'call_answer', 'data' => ['call_id' => $callId, 'sdp' => 'e2e-answer-sdp']]);
$ans = $wa->recvType('call_answer');
check($ans !== null && $ans['data']['sdp'] === 'e2e-answer-sdp', 'call_answer 转发（B 发 A 收）');
$wa->send(['type' => 'call_hangup', 'data' => ['call_id' => $callId]]);
// 服务端契约（CallCenterTest 同款断言）：hangup 仅推给对端，挂断方本地知晓不回显
$huB = $wb->recvType('call_hangup');
$huA = $wa->recvType('call_hangup', 800);
check($huB !== null && (int) $huB['data']['call_id'] === $callId && $huA === null, 'call_hangup 推给对端 B（挂断方 A 无回显）');
// 8. 通话历史 → 该记录 status=5
$calls = http('GET', '/api/voice/calls', [], $aTok, $v1);
$hit = null;
foreach ($calls['data']['list'] ?? [] as $row) {
    if ((int) $row['caller_id'] === $aId && (int) $row['callee_id'] === $bId && (int) $row['status'] === 5) {
        $hit = $row;
    }
}
check($hit !== null, '通话历史记录 status=5');

// ---- 语聊房 ----
// 9. 建房 → join → up_mic → 详情成员 2/麦位 2 → leave → 房主 leave → 列表不再出现
$room = http('POST', '/api/v1/voice/rooms', ['name' => 'e2e-room-' . uniqid()], $aTok, $v1);
check($room['code'] === 0, 'A 建房成功');
$roomId = (int) $room['data']['room_id'];
$wa->send(['type' => 'room_join', 'data' => ['room_id' => $roomId]]);
$rjA = $wa->recvType('room_join');
check($rjA !== null && (int) $rjA['data']['user_id'] === $aId, 'A room_join 广播（房主自带麦位）');
$wb->send(['type' => 'room_join', 'data' => ['room_id' => $roomId]]);
$rjB = $wa->recvType('room_join');
check($rjB !== null && (int) $rjB['data']['user_id'] === $bId, 'B room_join 广播给 A');
$wa->send(['type' => 'room_up_mic', 'data' => ['room_id' => $roomId]]);
$umA = $wb->recvType('room_up_mic');
check($umA !== null && (int) $umA['data']['user_id'] === $aId, 'A room_up_mic 广播给 B');
$wa->recvType('room_up_mic'); // A 也收到自己的 up_mic 广播，先消费再等 B 的
$wb->send(['type' => 'room_up_mic', 'data' => ['room_id' => $roomId]]);
$umB = $wa->recvType('room_up_mic');
check($umB !== null && (int) $umB['data']['user_id'] === $bId, 'B room_up_mic 广播给 A');
$detail = http('GET', '/api/v1/voice/rooms/' . $roomId, [], $aTok, $v1);
$members = $detail['data']['members'] ?? [];
$micNum = 0;
foreach ($members as $m) {
    if ((int) $m['role'] === 1) {
        $micNum++;
    }
}
check(count($members) === 2 && $micNum === 2, '房间详情成员数 2、麦位 2');
$wb->send(['type' => 'room_leave', 'data' => ['room_id' => $roomId]]);
$rl = $wa->recvType('room_leave');
check($rl !== null && (int) $rl['data']['user_id'] === $bId, 'B room_leave 广播给 A');
$wa->send(['type' => 'room_leave', 'data' => ['room_id' => $roomId]]);
// 房主离房关房由 WS worker 异步落库，轮询列表直到消失（leave 无回显帧）
$gone = false;
for ($i = 0; $i < 10 && !$gone; $i++) {
    $rooms = http('GET', '/api/v1/voice/rooms', [], $aTok, $v1);
    $gone = true;
    foreach ($rooms['data']['list'] ?? [] as $r) {
        if ((int) $r['id'] === $roomId) {
            $gone = false;
        }
    }
    if (!$gone) {
        usleep(300000);
    }
}
check($gone, '房主 leave 后房间列表不再出现该房间');

$wa->close();
$wb->close();
unlink($sample);
echo "VOICE E2E OK\n";
