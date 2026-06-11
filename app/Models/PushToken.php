<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushToken extends Model
{
    protected $fillable = [
        'device_id',
        'token',
        'platform',
        'language',
        'tips_enabled',
    ];

    protected $casts = [
        'tips_enabled' => 'boolean',
    ];

    /**
     * Register or update a device's push token.
     */
    public static function register(
        string $deviceId,
        string $token,
        string $platform = 'expo',
        string $language = 'ur'
    ): self {
        return static::updateOrCreate(
            ['device_id' => $deviceId],
            [
                'token' => $token,
                'platform' => $platform,
                'language' => $language,
            ]
        );
    }

    /**
     * Get all tokens for devices with tips enabled.
     */
    public static function forTips(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('tips_enabled', true);
    }
}
