<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    /**
     * Register a push notification token.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:expo,fcm,apns'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $pushToken = PushToken::register(
            $validated['device_id'],
            $validated['token'],
            $validated['platform'] ?? 'expo',
            $validated['language'] ?? 'ur'
        );

        return response()->json([
            'success' => true,
            'message' => 'Push token registered successfully.',
            'data' => [
                'id' => $pushToken->id,
                'tips_enabled' => $pushToken->tips_enabled,
            ],
        ]);
    }

    /**
     * Update push notification preferences.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'tips_enabled' => ['required', 'boolean'],
            'language' => ['nullable', 'string', 'max:10'],
        ]);

        $pushToken = PushToken::where('device_id', $validated['device_id'])->first();

        if (!$pushToken) {
            return response()->json([
                'success' => false,
                'message' => 'Device not registered for push notifications.',
            ], 404);
        }

        $pushToken->update([
            'tips_enabled' => $validated['tips_enabled'],
            'language' => $validated['language'] ?? $pushToken->language,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated successfully.',
        ]);
    }

    /**
     * Unregister a push token.
     */
    public function unregister(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
        ]);

        PushToken::where('device_id', $validated['device_id'])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Push token unregistered.',
        ]);
    }
}
