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

    /** Direct lookup (uncached) — kept for callers that need the model. */
    public static function getByKey(string $key): ?self
    {
        return static::where('key', $key)->where('is_active', true)->first();
    }

    /**
     * Get content for a key in the specified language, falling back to Urdu.
     * Caches a plain ARRAY (never Eloquent models — those deserialize as
     * __PHP_Incomplete_Class from the database cache and crash under load).
     */
    public static function getContentFor(string $key, string $language): ?string
    {
        $all = Cache::remember('response_scripts:active', 300, function () {
            return static::where('is_active', true)->get()
                ->keyBy('key')
                ->map(fn (self $s) => [
                    'content_ur' => $s->content_ur,
                    'content_en' => $s->content_en,
                    'content_rud' => $s->content_rud,
                    'content_ps' => $s->content_ps,
                    'content_sd' => $s->content_sd,
                ])
                ->toArray();
        });

        $row = $all[$key] ?? null;
        if (! $row) {
            return null;
        }
        $val = $row["content_{$language}"] ?? null;

        return ($val !== null && trim((string) $val) !== '') ? $val : (($row['content_ur'] ?? '') ?: null);
    }

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('response_scripts:active');
        static::saved($flush);
        static::deleted($flush);
    }
}
