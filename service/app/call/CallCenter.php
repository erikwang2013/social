<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\call;

use app\ws\Envelope;
use app\ws\WsRedis;
use Workerman\Timer;

class CallCenter
{
    public const RING_TIMEOUT = 30;

    /** @var callable(int $uid, array $frame): void */
    private $sendFn;
    /** @var callable(array $row): void 落库（status: 2接通 3未接 4取消 5结束） */
    private $recordFn;

    public function __construct(?callable $sendFn = null, ?callable $recordFn = null)
    {
        $this->sendFn = $sendFn ?? fn(int $uid, array $frame) => \app\ws\Deliverer::pushToMember($uid, Envelope::encode($frame['type'] ?? '', $frame['data'] ?? []), false);
        $this->recordFn = $recordFn ?? fn(array $row) => \app\model\CallRecord::query()->insert($row);
    }

    public function start(int $caller, int $callee): int
    {
        if (!WsRedis::call(fn($r) => $r->setnx('im:callbusy:' . $caller, 1))) {
            throw new \RuntimeException('already_in_call');
        }
        if (!WsRedis::call(fn($r) => $r->setnx('im:callbusy:' . $callee, 1))) {
            WsRedis::call(fn($r) => $r->del('im:callbusy:' . $caller));
            throw new \RuntimeException('already_in_call');
        }
        WsRedis::call(function ($r) use ($caller, $callee) {
            $r->expire('im:callbusy:' . $caller, 300);
            $r->expire('im:callbusy:' . $callee, 300);
        });
        $callId = (int) WsRedis::call(fn($r) => $r->incr('im:callseq'));
        WsRedis::call(function ($r) use ($callId, $caller, $callee) {
            $r->hset('im:call:' . $callId, 'status', CallState::RINGING);
            $r->hset('im:call:' . $callId, 'caller', $caller);
            $r->hset('im:call:' . $callId, 'callee', $callee);
            $r->hset('im:call:' . $callId, 'offer_at', time());
            $r->set('im:callby:' . $caller, $callId);
            $r->set('im:callby:' . $callee, $callId);
        });
        ($this->sendFn)($callee, ['type' => Envelope::T_CALL_INVITE, 'data' => ['call_id' => $callId, 'caller_id' => $caller]]);
        ($this->sendFn)($caller, ['type' => Envelope::T_CALL_INVITE, 'data' => ['call_id' => $callId, 'callee_id' => $callee]]);
        if (\Workerman\Worker::getAllWorkers()) { // CLI 测试无 loop，仅生产调度 30s 超时
            Timer::add(self::RING_TIMEOUT + 1, fn() => $this->timeoutIfPending($callId), [], false);
        }
        return $callId;
    }

    public function accept(int $callId, int $uid): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], CallState::ACCEPTED) || (int) $row['callee'] !== $uid) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        WsRedis::call(function ($r) use ($callId, $now) {
            $r->hset('im:call:' . $callId, 'status', CallState::ACCEPTED);
            $r->hset('im:call:' . $callId, 'started_at', $now); // finish() 落 status=5 行时带回
        });
        ($this->recordFn)(['caller_id' => (int) $row['caller'], 'callee_id' => (int) $row['callee'], 'status' => 2, 'started_at' => $now]);
        ($this->sendFn)((int) $row['caller'], ['type' => Envelope::T_CALL_ACCEPT, 'data' => ['call_id' => $callId]]);
    }

    public function reject(int $callId, int $uid): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], 'REJECTED') || (int) $row['callee'] !== $uid) {
            return;
        }
        $this->finish($callId, 'REJECTED', 3);
        ($this->sendFn)((int) $row['caller'], ['type' => Envelope::T_CALL_REJECT, 'data' => ['call_id' => $callId]]);
    }

    public function cancel(int $callId, int $uid): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], 'CANCELED') || (int) $row['caller'] !== $uid) {
            return;
        }
        $this->finish($callId, 'CANCELED', 4);
        ($this->sendFn)((int) $row['callee'], ['type' => Envelope::T_CALL_CANCEL, 'data' => ['call_id' => $callId]]);
    }

    public function hangup(int $callId, int $uid): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], CallState::ENDED)) {
            return;
        }
        $this->finish($callId, CallState::ENDED, 5);
        $other = (int) $row['caller'] === $uid ? (int) $row['callee'] : (int) $row['caller'];
        ($this->sendFn)($other, ['type' => Envelope::T_CALL_HANGUP, 'data' => ['call_id' => $callId]]);
    }

    /** 30s 未接 → 双端推 call_timeout，落库 status=3 */
    public function timeoutIfPending(int $callId): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], 'MISSED')) {
            return;
        }
        $this->finish($callId, 'MISSED', 3);
        ($this->sendFn)((int) $row['caller'], ['type' => Envelope::T_CALL_TIMEOUT, 'data' => ['call_id' => $callId]]);
        ($this->sendFn)((int) $row['callee'], ['type' => Envelope::T_CALL_TIMEOUT, 'data' => ['call_id' => $callId]]);
    }

    /** P2P ICE 15s 未连通 → 双端推 call_failed 并结束，落库 status=5（媒体面客户端检测，服务端收帧即终局） */
    public function failed(int $callId, int $uid): void
    {
        $row = $this->row($callId);
        if ($row === null || !CallState::can($row['status'], CallState::FAILED)) {
            return;
        }
        if ((int) $row['caller'] !== $uid && (int) $row['callee'] !== $uid) {
            return;
        }
        $this->finish($callId, CallState::FAILED, 5);
        ($this->sendFn)((int) $row['callee'], ['type' => Envelope::T_CALL_FAILED, 'data' => ['call_id' => $callId]]);
        ($this->sendFn)((int) $row['caller'], ['type' => Envelope::T_CALL_FAILED, 'data' => ['call_id' => $callId]]);
    }

    /** WS 掉线 → 推对方 call_hangup 并结束（ponytail: 不做重连恢复） */
    public function onDisconnect(int $uid): void
    {
        $callId = (int) WsRedis::call(fn($r) => $r->get('im:callby:' . $uid));
        if ($callId <= 0) {
            return;
        }
        $row = $this->row($callId);
        if ($row === null || $row['status'] === CallState::ENDED) {
            return;
        }
        $this->finish($callId, CallState::ENDED, 5);
        $other = (int) $row['caller'] === $uid ? (int) $row['callee'] : (int) $row['caller'];
        ($this->sendFn)($other, ['type' => Envelope::T_CALL_HANGUP, 'data' => ['call_id' => $callId]]);
    }

    /** offer/answer/ice 仅转发（媒体面 P2P，不经服务端） */
    public function relay(int $callId, int $uid, string $frameType, array $data): void
    {
        $row = $this->row($callId);
        if ($row === null) {
            return;
        }
        $other = (int) $row['caller'] === $uid ? (int) $row['callee'] : (int) $row['caller'];
        ($this->sendFn)($other, ['type' => $frameType, 'data' => ['call_id' => $callId] + $data]);
    }

    private function row(int $callId): ?array
    {
        $row = WsRedis::call(fn($r) => $r->hgetall('im:call:' . $callId));
        return ($row === null || $row === []) ? null : $row;
    }

    private function finish(int $callId, string $to, int $status): void
    {
        WsRedis::call(function ($r) use ($callId, $to, $status) {
            $r->hset('im:call:' . $callId, 'status', $to);
            $r->hset('im:call:' . $callId, 'ended_at', time());
        });
        $row = $this->row($callId);
        if ($row === null) {
            return;
        }
        ($this->recordFn)([
            'caller_id' => (int) $row['caller'],
            'callee_id' => (int) $row['callee'],
            'status' => $status,
            'started_at' => $row['started_at'] ?? null,
            'ended_at' => date('Y-m-d H:i:s'),
        ]);
        WsRedis::call(function ($r) use ($row) {
            $r->del('im:callbusy:' . $row['caller']);
            $r->del('im:callbusy:' . $row['callee']);
            $r->del('im:callby:' . $row['caller']);
            $r->del('im:callby:' . $row['callee']);
        });
    }
}
