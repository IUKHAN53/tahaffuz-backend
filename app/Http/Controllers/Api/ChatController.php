<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Services\Rag\ChatPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
            'language' => ['nullable', 'string', 'in:en,ur,fa,ps,sd'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $chat = $this->pipeline->findOrCreateChat(
            $data['device_id'],
            $data['knowledge_base_id'] ?? null,
            $data['chat_id'] ?? null,
        );

        try {
            $result = $this->pipeline->answerText($chat, trim($data['message']), $data['language'] ?? null, $this->locationFrom($data));
        } catch (Throwable $e) {
            return $this->serviceError($e, $data['language'] ?? null, $chat->id);
        }

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

    /**
     * Streamed text turn. Emits Server-Sent Events: a `meta` event with the
     * chat id, a run of `delta` events as the answer is generated, then a
     * final `done` event (or `error`). The app falls back to /chat/text if
     * anything here misbehaves, so this path is purely an enhancement.
     */
    public function stream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'chat_id' => ['nullable', 'integer'],
            'knowledge_base_id' => ['nullable', 'integer'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'language' => ['nullable', 'string', 'in:en,ur,fa,ps,sd'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $chat = $this->pipeline->findOrCreateChat(
            $data['device_id'],
            $data['knowledge_base_id'] ?? null,
            $data['chat_id'] ?? null,
        );

        $language = $data['language'] ?? null;
        $message = trim($data['message']);
        $location = $this->locationFrom($data);

        return response()->stream(function () use ($chat, $message, $language, $location) {
            $send = function (string $event, array $payload): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            $send('meta', ['chat_id' => $chat->id]);

            try {
                $result = $this->pipeline->answerTextStreamed(
                    $chat,
                    $message,
                    $language,
                    fn (string $delta) => $send('delta', ['text' => $delta]),
                    $location,
                    fn (string $key) => $send('status', ['key' => $key]),
                );

                $send('done', [
                    'chat_id' => $chat->id,
                    'reply' => [
                        'id' => $result['message']->id,
                        'content' => $result['message']->content,
                        'citations' => $result['citations'],
                        'latency_ms' => $result['message']->latency_ms,
                    ],
                ]);
            } catch (Throwable $e) {
                Log::error('Chat stream failed', ['chat_id' => $chat->id, 'error' => $e->getMessage()]);
                $send('error', ['error' => $this->errorMessage($language)]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function audio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'chat_id' => ['nullable', 'integer'],
            'knowledge_base_id' => ['nullable', 'integer'],
            'audio' => ['required', 'file', 'mimes:m4a,mp3,mp4,wav,ogg,webm,3gp,aac', 'max:10240'],
            'language' => ['nullable', 'string', 'in:en,ur,fa,ps,sd'],
        ]);

        $chat = $this->pipeline->findOrCreateChat(
            $data['device_id'],
            $data['knowledge_base_id'] ?? null,
            $data['chat_id'] ?? null,
        );

        $file = $request->file('audio');
        $mime = $file->getMimeType() ?: 'audio/m4a';

        try {
            $result = $this->pipeline->answerAudio($chat, $file->getRealPath(), $mime, $data['language'] ?? null);
        } catch (Throwable $e) {
            return $this->serviceError($e, $data['language'] ?? null, $chat->id);
        }

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

    /**
     * Build the {lat, lng} location array from validated request data, or null
     * when the app didn't send coordinates.
     *
     * @param  array<string, mixed>  $data
     * @return array{lat: float, lng: float}|null
     */
    protected function locationFrom(array $data): ?array
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        return ['lat' => (float) $data['latitude'], 'lng' => (float) $data['longitude']];
    }

    /**
     * Turn an upstream failure (Gemini outage, exhausted quota, etc.) into a
     * clean localized JSON error the app can show — never a raw 500 stack.
     */
    protected function serviceError(Throwable $e, ?string $language, int $chatId): JsonResponse
    {
        Log::error('Chat request failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

        return response()->json(['error' => $this->errorMessage($language), 'chat_id' => $chatId], 503);
    }

    /**
     * Localized "try again" message for upstream failures.
     */
    protected function errorMessage(?string $language): string
    {
        return match ($language) {
            'en'  => 'The assistant is busy right now. Please try again in a moment.',
            'fa'  => 'دستیار در حال حاضر مشغول است. لطفاً چند لحظه دیگر دوباره تلاش کنید.',
            'ps'  => 'مرستندویه اوس بوخت دی. مهرباني وکړئ یو شیبه وروسته بیا هڅه وکړئ.',
            'sd'  => 'مددگار هن وقت مصروف آهي. مهرباني ڪري ٿوري دير کانپوءِ ٻيهر ڪوشش ڪريو.',
            default => 'معاون اس وقت مصروف ہے۔ براہ کرم تھوڑی دیر بعد دوبارہ کوشش کریں۔',
        };
    }
}
