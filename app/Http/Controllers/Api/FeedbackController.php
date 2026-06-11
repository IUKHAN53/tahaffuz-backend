<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    /**
     * Submit feedback for a message.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
            'device_id' => ['required', 'string', 'max:64'],
            'rating' => ['required', Rule::in(['up', 'down'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Check if already rated
        if (MessageFeedback::hasRated($validated['message_id'], $validated['device_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'You have already rated this message.',
            ], 409);
        }

        $feedback = MessageFeedback::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully.',
            'data' => [
                'id' => $feedback->id,
                'stats' => MessageFeedback::stats($validated['message_id']),
            ],
        ]);
    }

    /**
     * Get feedback stats for a message.
     */
    public function show(int $messageId)
    {
        $message = Message::find($messageId);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => MessageFeedback::stats($messageId),
        ]);
    }

    /**
     * Check if device has rated a message.
     */
    public function check(Request $request, int $messageId)
    {
        $deviceId = $request->query('device_id');

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'device_id is required.',
            ], 400);
        }

        $feedback = MessageFeedback::where('message_id', $messageId)
            ->where('device_id', $deviceId)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_rated' => $feedback !== null,
                'rating' => $feedback?->rating,
            ],
        ]);
    }
}
