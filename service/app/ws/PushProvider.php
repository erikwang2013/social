<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\ws;

/** 离线消息推送抽象：M3 用 NullPushProvider，厂商接入只需新实现类并在 Deliverer::$pushProviderClass 切换 */
interface PushProvider
{
    public function send(int $uid, array $payload): void;
}
