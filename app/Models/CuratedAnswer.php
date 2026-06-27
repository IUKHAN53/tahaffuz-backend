<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-curated answers — the feedback loop. When a worker's question closely
 * matches a curated question, the assistant returns the approved answer instead
 * of going to RAG. Admins create these from failed queries / negative feedback.
 */
class CuratedAnswer extends Model
{
    protected $fillable = ['question', 'answer', 'language', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('curated_answers:active');
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Best curated answer for a user message, or null. Matches when the curated
     * question is contained in the user's text or is ≥ 80% similar.
     */
    public static function match(string $userText, ?string $language): ?string
    {
        $active = Cache::remember('curated_answers:active', 300, fn () => static::where('is_active', true)->get());
        if ($active->isEmpty()) {
            return null;
        }

        $norm = fn (string $s): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)) ?? '');
        $u = $norm($userText);
        if ($u === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($active as $row) {
            if ($row->language && $language && $row->language !== $language) {
                continue;
            }
            $q = $norm($row->question);
            if ($q === '') {
                continue;
            }
            if (str_contains($u, $q) || str_contains($q, $u)) {
                return $row->answer;
            }
            similar_text($u, $q, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $row->answer;
            }
        }

        return $bestScore >= 80.0 ? $best : null;
    }
}
