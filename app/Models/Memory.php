<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Memory extends Model
{
    public const KIND_CHILD = 'child_fact';

    public const KIND_FACT = 'fact';

    protected $fillable = [
        'device_id',
        'worker_id',
        'kind',
        'content',
        'source_chat_id',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];
}
