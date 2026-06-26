<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function __construct(protected MemoryService $memory) {}

    /** GET /api/memories?device_id=&chat_id= — what the assistant remembers. */
    public function index(Request $request): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if (mb_strlen($deviceId) < 8) {
            abort(400, 'device_id required');
        }
        $chatId = $request->query('chat_id');

        $items = $this->memory->list($deviceId, $chatId !== null ? (int) $chatId : null);

        return response()->json([
            'scope' => $this->memory->scope(),
            'memories' => $items->map(fn ($m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'content' => $m->content,
                'chat_id' => $m->chat_id,
            ])->values(),
        ]);
    }

    /** DELETE /api/memories?device_id=&chat_id= — forget everything (or one chat). */
    public function destroy(Request $request): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if (mb_strlen($deviceId) < 8) {
            abort(400, 'device_id required');
        }
        $chatId = $request->query('chat_id');

        $cleared = $this->memory->clear($deviceId, $chatId !== null ? (int) $chatId : null);

        return response()->json(['cleared' => $cleared]);
    }
}
