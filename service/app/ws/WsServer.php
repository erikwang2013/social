<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use app\common\JwtHelper;

/**
 * ws worker handler（webman process handler：实例方法由 worker_bind 绑到 Worker 回调）。
 * 握手鉴权（?token=JWT）→ 注册连接；消息转交 ActionHandler（Task 4）。
 */
class WsServer
{
    private static ?Worker $worker = null;

    public function __construct(string $nodeId = '')
    {
        ConnectionRegistry::setNodeId($nodeId);
    }

    public function onWorkerStart(Worker $worker): void
    {
        self::$worker = $worker;
    }

    public function onWebSocketConnect(TcpConnection $conn, \Workerman\Protocols\Http\Request $request): void
    {
        $payload = null;
        $token = (string) $request->get('token', '');
        if ($token !== '') {
            $payload = JwtHelper::decode($token);
        }
        if (!$payload || ($payload->type ?? '') !== 'access' || JwtHelper::isRevoked($payload->jti)) {
            $conn->close();
            return;
        }
        $uid = (int) $payload->sub;
        $oldFd = ConnectionRegistry::attach($conn->id, $uid);
        if ($oldFd !== null) {
            self::kick($oldFd);
        }
        $conn->send(Envelope::encode(Envelope::T_READY, ['uid' => $uid]));
        self::drainOffline($conn, $uid);
    }

    /** 连接就绪后冲刷离线队列（先 ready 后帧，客户端按序处理；单连接策略下无并发消费） */
    private static function drainOffline(TcpConnection $conn, int $uid): void
    {
        WsRedis::call(function ($r) use ($conn, $uid) {
            $frames = $r->lrange('im:offline:' . $uid, 0, -1) ?: [];
            if ($frames !== []) {
                $r->del('im:offline:' . $uid);
            }
            foreach ($frames as $frame) {
                $conn->send($frame);
            }
            return true;
        });
    }

    public function onMessage(TcpConnection $conn, string $data): void
    {
        $uid = ConnectionRegistry::uidFor($conn->id);
        if ($uid === null) {
            $conn->close();
            return;
        }
        $env = Envelope::decode($data);
        if ($env === null) {
            $conn->send(Envelope::encode(Envelope::T_ERROR, ['msg' => 'invalid frame']));
            return;
        }
        ActionHandler::handle($conn->id, $uid, $env);
    }

    public function onClose(TcpConnection $conn): void
    {
        $uid = ConnectionRegistry::uidFor($conn->id);
        ConnectionRegistry::detach($conn->id);
        if ($uid !== null) {
            static $cc = null;
            $cc ??= new \app\call\CallCenter();
            $cc->onDisconnect($uid);
            static $rc = null;
            $rc ??= new \app\room\RoomCenter();
            $rc->onDisconnect($uid);
        }
    }

    /** 推帧给本机 fd（仅本 worker 的 connections 可达） */
    public static function sendToFd(int $fd, string $frame): void
    {
        $conn = self::$worker?->connections[$fd] ?? null;
        if ($conn !== null) {
            $conn->send($frame);
        }
    }

    private static function kick(int $fd): void
    {
        self::sendToFd($fd, Envelope::encode(Envelope::T_KICKED));
        $conn = self::$worker?->connections[$fd] ?? null;
        if ($conn !== null) {
            $conn->close();
        }
    }
}
