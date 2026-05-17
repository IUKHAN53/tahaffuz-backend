<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Services\Rag\ChatPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(protected ChatPipeline $pipeline) {}

    public function show(Request $request, Chat $chat): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if ($deviceId === '' || $chat->device_id !== $deviceId) {
            abort(403);
        }

        return response()->json([
            'chat' => [
                'id' => $chat->id,
                'title' => $chat->title,
                'knowledge_base_id' => $chat->knowledge_base_id,
                'language' => $chat->language,
                'created_at' => $chat->created_at,
                'updated_at' => $chat->updated_at,
            ],
            'messages' => $chat->messages()->get(['id', 'role', 'content', 'citations', 'latency_ms', 'created_at']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if (mb_strlen($deviceId) < 8) {
            abort(400, 'device_id required');
        }

        $chats = Chat::query()
            ->where('device_id', $deviceId)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->withCount('messages')
            ->get(['id', 'title', 'language', 'knowledge_base_id', 'created_at', 'updated_at']);

        return response()->json([
            'chats' => $chats->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title ?? 'نئی گفتگو',
                'language' => $c->language,
                'message_count' => $c->messages_count,
                'updated_at' => $c->updated_at,
            ]),
        ]);
    }

    public function destroy(Request $request, Chat $chat): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if ($deviceId === '' || $chat->device_id !== $deviceId) {
            abort(403);
        }
        $chat->delete();
        return response()->json(['ok' => true]);
    }

    public function text(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'chat_id' => ['nullable', 'integer'],
            'knowledge_base_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'language' => ['nullable', 'string', 'in:en,ur'],
        ]);

        $chat = $this->pipeline->findOrCreateChat(
            $data['device_id'],
            $data['knowledge_base_id'] ?? null,
            $data['chat_id'] ?? null,
        );

        $result = $this->pipeline->answerText($chat, trim($data['message']), $data['language'] ?? null);

        return response()->json([
            'chat_id' => $chat->id,
            'reply' => [
                'id' => $result['message']->id,
                'content' => $result['message']->content,
                'citations' => $result['citations'],
                'latency_ms' => $result['message']->latency_ms,
            ],
        ]);
    }

    public function audio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'chat_id' => ['nullable', 'integer'],
            'knowledge_base_id' => ['nullable', 'integer'],
            'audio' => ['required', 'file', 'mimes:m4a,mp3,mp4,wav,ogg,webm,3gp,aac', 'max:10240'],
            'language' => ['nullable', 'string', 'in:en,ur'],
        ]);

        $chat = $this->pipeline->findOrCreateChat(
            $data['device_id'],
            $data['knowledge_base_id'] ?? null,
            $data['chat_id'] ?? null,
        );

        $file = $request->file('audio');
        $mime = $file->getMimeType() ?: 'audio/m4a';

        $result = $this->pipeline->answerAudio($chat, $file->getRealPath(), $mime, $data['language'] ?? null);

        return response()->json([
            'chat_id' => $chat->id,
            'transcript' => $result['transcript'],
            'reply' => [
                'id' => $result['message']->id,
                'content' => $result['message']->content,
                'citations' => $result['citations'],
                'latency_ms' => $result['message']->latency_ms,
            ],
        ]);
    }
}
