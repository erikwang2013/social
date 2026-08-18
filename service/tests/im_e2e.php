<?php
// IM E2E 冒烟：双用户 WS 收发/已读/撤回/离线队列（黑盒 HTTP+WS，需要 php start.php start 运行中 + Redis）
// 测试用户在 dev 库留痕（uniqid 邮箱无碰撞，无删除用户接口）

function http(string $method, string $path, array $json = [], string $token = ''): array
{
    $sock = stream_socket_client('tcp://127.0.0.1:8788', $errno, $errstr, 5);
    if (!$sock) {
        throw new RuntimeException("http connect failed: $errstr");
    }
    stream_set_timeout($sock, 5); // 防服务端不关连接时永久挂起
    $body = $json === [] ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $headers = "Host: 127.0.0.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close";
    if ($token !== '') {
        $headers .= "\r\nAuthorization: Bearer $token";
    }
    fwrite($sock, "$method $path HTTP/1.1\r\n$headers\r\n\r\n$body");
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
    /** 握手响应后同段到达的帧（服务端 101 与 ready/离线帧可能同 TCP 段） */
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

// ---- 注册/登录 ----
$pass = 'e2e-pass';
$aRes = http('POST', '/api/v1/auth/register', ['email' => uniqid() . '@e2e.a', 'password' => $pass, 'nickname' => 'E2E-A']);
check($aRes['code'] === 0, 'A 注册');
$bRes = http('POST', '/api/v1/auth/register', ['email' => uniqid() . '@e2e.b', 'password' => $pass, 'nickname' => 'E2E-B']);
check($bRes['code'] === 0, 'B 注册');
$aTok = $aRes['data']['access_token'];
$bTok = $bRes['data']['access_token'];
$meB = http('GET', '/api/v1/auth/me', [], $bTok);
$bId = (int) $meB['data']['user']['id'];
check($bId > 0, 'B 取到 user id');

// A 建私聊会话
$conv = http('POST', '/api/v1/im/conversations', ['type' => 1, 'member_ids' => [$bId]], $aTok);
check($conv['code'] === 0, 'A 建私聊会话');
$cid = (int) $conv['data']['id'];

// ---- 在线收发 ----
$wa = new WsClient('127.0.0.1', 8789, $aTok);
$wb = new WsClient('127.0.0.1', 8789, $bTok);
check($wa->recvType('ready') !== null && $wb->recvType('ready') !== null, 'A/B 握手就绪');

$cmid = 'e2e-' . uniqid();
$wa->send(['type' => 'send', 'seq' => 1, 'data' => ['conversation_id' => $cid, 'client_msg_id' => $cmid, 'content' => 'hello']]);
$ack = $wa->recvType('ack');
check($ack !== null && $ack['data']['client_msg_id'] === $cmid, 'A 收到 ack（client_msg_id 一致）');
$mid = (int) $ack['data']['message_id'];
$env = $wb->recvType('message');
check($env !== null && $env['data']['client_msg_id'] === $cmid && (int) $env['data']['message_id'] === $mid, 'B 收到 message 帧且 client_msg_id 一致');

$wb->send(['type' => 'read', 'data' => ['conversation_id' => $cid, 'last_read_id' => $mid]]);
$rd = $wa->recvType('read');
check($rd !== null && (int) $rd['data']['user_id'] === $bId && (int) $rd['data']['last_read_id'] === $mid, 'A 收到 read 帧');

$wa->send(['type' => 'recall', 'data' => ['message_id' => $mid]]);
$rc = $wb->recvType('recall');
check($rc !== null && (int) $rc['data']['message_id'] === $mid, 'B 收到 recall 帧');

$hist = http('GET', '/api/v1/im/conversations/' . $cid . '/messages', [], $bTok);
$hit = null;
foreach ($hist['data']['list'] ?? [] as $row) {
    if ((int) $row['id'] === $mid) {
        $hit = $row;
    }
}
check($hit !== null && (int) $hit['recall_status'] === 1, 'B 历史消息 recall_status=1');
$wa->close();
$wb->close();

// ---- 离线队列 ----
$wb = new WsClient('127.0.0.1', 8789, $bTok);
$wb->recvType('ready');
$wb->close();
usleep(500000); // 等 onClose 清理连接表

$wa = new WsClient('127.0.0.1', 8789, $aTok);
$wa->recvType('ready');
$cmid2 = 'e2e-off-' . uniqid();
$wa->send(['type' => 'send', 'seq' => 2, 'data' => ['conversation_id' => $cid, 'client_msg_id' => $cmid2, 'content' => 'offline msg']]);
$ack2 = $wa->recvType('ack');
check($ack2 !== null && $ack2['data']['client_msg_id'] === $cmid2, 'A 离线期间发送成功');
$wa->close();

$wb = new WsClient('127.0.0.1', 8789, $bTok);
$wb->recvType('ready');
$off = $wb->recvType('message', 5000);
check($off !== null && $off['data']['client_msg_id'] === $cmid2, 'B 重连后收到离线队列帧');
$wb->close();

echo "IM E2E OK\n";
