<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bookmark extends Model
{
    protected $fillable = [
        'message_id',
        'device_id',
        'note',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get all bookmarks for a device.
     */
    public static function forDevice(string $deviceId)
    {
        return static::where('device_id', $deviceId)
            ->with(['message.chat'])
            ->orderByDesc('created_at');
    }

    /**
     * Check if a message is bookmarked by a device.
     */
    public static function isBookmarked(int $messageId, string $deviceId): bool
    {
        return static::where('message_id', $messageId)
            ->where('device_id', $deviceId)
            ->exists();
    }

    /**
     * Toggle bookmark for a message.
     */
    public static function toggle(int $messageId, string $deviceId, ?string $note = null): bool
    {
        $existing = static::where('message_id', $messageId)
            ->where('device_id', $deviceId)
            ->first();

        if ($existing) {
            $existing->delete();
            return false; // unbookmarked
        }

        static::create([
            'message_id' => $messageId,
            'device_id' => $deviceId,
            'note' => $note,
        ]);

        return true; // bookmarked
    }
}
