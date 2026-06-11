<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageFeedback extends Model
{
    protected $table = 'message_feedback';

    protected $fillable = [
        'message_id',
        'device_id',
        'rating',
        'comment',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Check if a device has already rated a message.
     */
    public static function hasRated(int $messageId, string $deviceId): bool
    {
        return static::where('message_id', $messageId)
            ->where('device_id', $deviceId)
            ->exists();
    }

    /**
     * Get feedback stats for a message.
     */
    public static function stats(int $messageId): array
    {
        $counts = static::where('message_id', $messageId)
            ->selectRaw("rating, COUNT(*) as count")
            ->groupBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        return [
            'up' => $counts['up'] ?? 0,
            'down' => $counts['down'] ?? 0,
        ];
    }
}
