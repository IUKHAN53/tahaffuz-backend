<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Message;
use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    /**
     * Get all bookmarks for a device.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
        ]);

        $bookmarks = Bookmark::forDevice($validated['device_id'])
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $bookmarks,
        ]);
    }

    /**
     * Toggle bookmark for a message.
     */
    public function toggle(Request $request)
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'exists:messages,id'],
            'device_id' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $isBookmarked = Bookmark::toggle(
            $validated['message_id'],
            $validated['device_id'],
            $validated['note'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => $isBookmarked ? 'Message bookmarked.' : 'Bookmark removed.',
            'data' => [
                'is_bookmarked' => $isBookmarked,
            ],
        ]);
    }

    /**
     * Check if a message is bookmarked.
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

        return response()->json([
            'success' => true,
            'data' => [
                'is_bookmarked' => Bookmark::isBookmarked($messageId, $deviceId),
            ],
        ]);
    }

    /**
     * Delete a bookmark.
     */
    public function destroy(Request $request, int $bookmarkId)
    {
        $deviceId = $request->query('device_id');

        if (!$deviceId) {
            return response()->json([
                'success' => false,
                'message' => 'device_id is required.',
            ], 400);
        }

        $bookmark = Bookmark::where('id', $bookmarkId)
            ->where('device_id', $deviceId)
            ->first();

        if (!$bookmark) {
            return response()->json([
                'success' => false,
                'message' => 'Bookmark not found.',
            ], 404);
        }

        $bookmark->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bookmark deleted.',
        ]);
    }
}
