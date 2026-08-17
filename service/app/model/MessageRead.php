<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class MessageRead extends Model
{
    protected $primaryKey = ['conversation_id', 'user_id'];
    public $incrementing = false;
    protected $fillable = ['conversation_id', 'user_id', 'last_read_id'];

    // 已读游标单调递增：仅当新值更大时更新，无记录则插入
    public static function advance(int $cid, int $uid, int $lastReadId): void
    {
        $now = date('Y-m-d H:i:s');
        $affected = static::query()->where('conversation_id', $cid)->where('user_id', $uid)
            ->where('last_read_id', '<', $lastReadId)
            ->update(['last_read_id' => $lastReadId, 'updated_at' => $now]);
        if ($affected === 0 && !static::query()->where('conversation_id', $cid)->where('user_id', $uid)->exists()) {
            static::query()->updateOrInsert(
                ['conversation_id' => $cid, 'user_id' => $uid],
                ['last_read_id' => $lastReadId, 'updated_at' => $now]
            );
        }
    }
}
