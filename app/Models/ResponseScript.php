<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ResponseScript extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'content_ur',
        'content_en',
        'content_rud',
        'content_ps',
        'content_sd',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get content for the specified language, falling back to Urdu.
     */
    public function getContent(string $language): string
    {
        $field = "content_{$language}";
        $value = $this->{$field} ?? null;

        // Return language-specific content if present, otherwise fall back to
        // Urdu. A blank per-language field (empty string from the admin form)
        // counts as "not provided" — `??` alone would wrongly return the blank.
        return ($value !== null && trim($value) !== '') ? $value : (string) $this->content_ur;
    }

    /**
     * Get a script by key with caching.
     */
    public static function getByKey(string $key): ?self
    {
        return Cache::remember("response_script:{$key}", 300, function () use ($key) {
            return static::where('key', $key)->where('is_active', true)->first();
        });
    }

    /**
     * Get content for a key in the specified language.
     * Returns null if script doesn't exist.
     */
    public static function getContentFor(string $key, string $language): ?string
    {
        $script = static::getByKey($key);

        return $script?->getContent($language);
    }

    /**
     * Clear the cache when a script is updated.
     */
    protected static function booted(): void
    {
        static::saved(function (self $script) {
            Cache::forget("response_script:{$script->key}");
        });

        static::deleted(function (self $script) {
            Cache::forget("response_script:{$script->key}");
        });
    }
}
