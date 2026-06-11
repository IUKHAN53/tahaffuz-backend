<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Search messages by content.
     */
    public function messages(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string', 'max:64'],
            'query' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $messages = Message::whereHas('chat', function ($q) use ($validated) {
                $q->where('device_id', $validated['device_id']);
            })
            ->where('content', 'like', '%' . $validated['query'] . '%')
            ->with('chat:id,title')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'chat_id', 'role', 'content', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => $messages,
        ]);
    }
}
