<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

/** 无操作推送：应用内通知 + 轮询降级兜底（已确认决策） */
class NullPushProvider implements PushProvider
{
    public function send(int $uid, array $payload): void
    {
    }
}
