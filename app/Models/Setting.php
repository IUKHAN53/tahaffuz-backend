<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple admin-editable key/value settings (cached). Used for things like the
 * memory scope toggle.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting:{$key}", 300, function () use ($key, $default) {
            return static::query()->whereKey($key)->value('value') ?? $default;
        });
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    protected static function booted(): void
    {
        static::saved(fn (self $s) => Cache::forget("setting:{$s->key}"));
        static::deleted(fn (self $s) => Cache::forget("setting:{$s->key}"));
    }
}
