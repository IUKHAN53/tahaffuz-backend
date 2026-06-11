<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyTip extends Model
{
    protected $fillable = [
        'title_ur',
        'title_en',
        'content_ur',
        'content_en',
        'category',
        'is_active',
        'last_sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_sent_at' => 'datetime',
    ];

    /**
     * Get a random active tip that hasn't been sent recently.
     */
    public static function getNextTip(): ?self
    {
        return static::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('last_sent_at')
                    ->orWhere('last_sent_at', '<', now()->subDays(7));
            })
            ->inRandomOrder()
            ->first();
    }

    /**
     * Get the title in the specified language.
     */
    public function getTitle(string $lang = 'ur'): string
    {
        return $lang === 'en' ? ($this->title_en ?? $this->title_ur) : $this->title_ur;
    }

    /**
     * Get the content in the specified language.
     */
    public function getContent(string $lang = 'ur'): string
    {
        return $lang === 'en' ? ($this->content_en ?? $this->content_ur) : $this->content_ur;
    }
}
