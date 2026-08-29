<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace app\model;

use Erikwang2013\Encryptable\Encryptable;
use Illuminate\Database\Eloquent\Model;

class StorageProvider extends Model
{
    protected $table = 'storage_provider'; // 连接前缀 erik_ → erik_storage_provider
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['name', 'driver', 'endpoint', 'region', 'key', 'secret', 'bucket', 'cdn_url', 'enabled', 'is_active'];
    protected $hidden = ['key', 'secret']; // API 永不回显明文
    protected $casts = [
        'key' => Encryptable::class,
        'secret' => Encryptable::class,
        'enabled' => 'integer',
        'is_active' => 'integer',
    ];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
