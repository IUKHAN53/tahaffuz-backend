<?php

namespace App\Services\Rag;

use App\Models\Chat;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Services\Gemini;
use Illuminate\Support\Str;

class ChatPipeline
{
    public function __construct(
        protected Gemini $gemini,
        protected VectorStore $store,
    ) {}

    public function findOrCreateChat(string $deviceId, ?int $kbId = null, ?int $chatId = null): Chat
    {
        if ($chatId) {
            $chat = Chat::where('id', $chatId)->where('device_id', $deviceId)->first();
            if ($chat) {
                return $chat;
            }
        }

        $kb = $kbId
            ? KnowledgeBase::find($kbId)
            : (KnowledgeBase::where('is_default', true)->first() ?? KnowledgeBase::query()->first());

        if (! $kb) {
            throw new \RuntimeException('No knowledge base configured.');
        }

        return Chat::create([
            'knowledge_base_id' => $kb->id,
            'device_id' => $deviceId,
            'language' => $kb->language,
        ]);
    }

    /**
     * Run a text turn end-to-end.
     *
     * @param  string|null  $language  'en' or 'ur' to force reply language; null = auto-detect.
     * @return array{message: Message, citations: array}
     */
    public function answerText(Chat $chat, string $userText, ?string $language = null): array
    {
        $started = microtime(true);

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $userText,
        ]);

        $this->maybeAssignTitle($chat, $userText);

        $hits = $this->retrieve($chat->knowledge_base_id, $userText);

        if ($this->isWeak($hits)) {
            $assistant = $this->refuse($chat, $started, language: $language);
            return ['message' => $assistant, 'citations' => []];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $reply = $this->gemini->generate(
            $this->systemPrompt($language),
            $history,
            $userText,
            $context,
        );

        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($language),
            'citations' => $citations,
            'meta' => ['usage' => $reply['usage'] ?? []] + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $chat->touch();

        return ['message' => $assistant, 'citations' => $citations];
    }

    /**
     * Run an audio turn — Gemini transcribes + answers, but we pre-retrieve context first
     * by transcribing the audio with a quick text-only call. Simpler: do it in one shot via
     * generateFromAudio, with context already built from a "best-effort" retrieval that
     * uses a placeholder query (the audio is sent inline so the model uses the transcript itself).
     *
     * Trade-off: retrieval can't see the question text up front. Workaround: use the prior
     * user turn (if any) as an embedding seed; else fall back to embedding KB summary.
     *
     * For prototype quality this is acceptable; we can split into transcribe-then-answer later.
     *
     * @return array{message: Message, citations: array, transcript: string}
     */
    public function answerAudio(Chat $chat, string $audioPath, string $audioMime, ?string $language = null): array
    {
        $started = microtime(true);

        $transcript = $this->transcribe($audioPath, $audioMime);

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $transcript,
            'meta' => ['source' => 'voice'],
        ]);

        $this->maybeAssignTitle($chat, $transcript);

        $hits = $this->retrieve($chat->knowledge_base_id, $transcript);

        if ($this->isWeak($hits)) {
            $assistant = $this->refuse($chat, $started, 'voice', language: $language);
            return ['message' => $assistant, 'citations' => [], 'transcript' => $transcript];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $reply = $this->gemini->generate(
            $this->systemPrompt($language),
            $history,
            $transcript,
            $context,
        );

        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($language),
            'citations' => $citations,
            'meta' => ['usage' => $reply['usage'] ?? [], 'source' => 'voice'] + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $chat->touch();

        return ['message' => $assistant, 'citations' => $citations, 'transcript' => $transcript];
    }

    /**
     * @return array<int, array{chunk: \App\Models\Chunk, score: float, vec_score?: float, kw_score?: float}>
     */
    protected function retrieve(int $knowledgeBaseId, string $query): array
    {
        $queryVec = $this->gemini->embed($query, 'RETRIEVAL_QUERY');
        $cfg = config('rag.retrieval');

        return $this->store->hybridSearch(
            $knowledgeBaseId,
            $queryVec,
            $query,
            (int) ($cfg['top_k'] ?? 6),
            (int) ($cfg['candidate_pool'] ?? 24),
            (float) ($cfg['vec_weight'] ?? 0.55),
            (float) ($cfg['kw_weight'] ?? 0.45),
        );
    }

    protected function isWeak(array $hits): bool
    {
        if (empty($hits)) {
            return true;
        }
        $floor = (float) config('rag.retrieval.rrf_floor', 0.012);
        return ($hits[0]['score'] ?? 0.0) < $floor;
    }

    protected function refuse(Chat $chat, float $started, ?string $source = null, ?string $language = null): Message
    {
        return Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $this->refusalText($language),
            'citations' => [],
            'meta' => ['refused' => true]
                + ($source ? ['source' => $source] : [])
                + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * Build the system prompt. When $language is set, append a hard rule so the
     * model ignores the user's input language and always replies in $language.
     */
    protected function systemPrompt(?string $language): string
    {
        $base = (string) config('rag.system_prompt_ur');
        if ($language === 'en') {
            return $base."\n\nLANGUAGE OVERRIDE: Always reply in English, regardless of the language of the question. Translate any Urdu source content into clear English in your answer. Keep numbers, dates, and temperatures exactly as written.";
        }
        if ($language === 'ur') {
            return $base."\n\nLANGUAGE OVERRIDE: ہمیشہ اردو میں جواب دیں چاہے سوال انگریزی میں ہو۔";
        }
        return $base;
    }

    protected function refusalText(?string $language): string
    {
        if ($language === 'en') {
            return "Sorry, I don't have information about that. Please contact your supervisor.";
        }
        return 'معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔';
    }

    protected function maybeAssignTitle(Chat $chat, string $userText): void
    {
        if ($chat->title) {
            return;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $userText) ?? '');
        $title = mb_substr($title, 0, 64);
        if ($title !== '') {
            $chat->update(['title' => $title]);
        }
    }

    protected function transcribe(string $audioPath, string $audioMime): string
    {
        // Use Gemini for transcription — Urdu-capable, free tier.
        $bytes = file_get_contents($audioPath);
        if ($bytes === false) {
            throw new \RuntimeException("Cannot read audio: {$audioPath}");
        }
        $cfg = config('rag.gemini');
        $url = "{$cfg['base_url']}/models/{$cfg['chat_model']}:generateContent?key={$cfg['api_key']}";
        $resp = \Illuminate\Support\Facades\Http::timeout(60)->retry(2, 1000, throw: false)->post($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Transcribe this audio verbatim in the original language (Urdu or English). Return ONLY the transcript text, no commentary.'],
                    ['inlineData' => ['mimeType' => $audioMime, 'data' => base64_encode($bytes)]],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 1024],
        ]);
        if ($resp->failed()) {
            throw new \RuntimeException("Transcription failed: HTTP {$resp->status()}");
        }
        return trim((string) $resp->json('candidates.0.content.parts.0.text', ''));
    }

    /**
     * @param  array<int, array{chunk: \App\Models\Chunk, score: float}>  $hits
     * @return array{0: string, 1: array}
     */
    protected function buildContext(array $hits): array
    {
        if (empty($hits)) {
            return ['', []];
        }

        $blocks = [];
        $citations = [];
        foreach ($hits as $i => $hit) {
            $chunk = $hit['chunk'];
            $doc = $chunk->document;
            $title = $doc?->title ?? "Doc {$chunk->document_id}";
            $blocks[] = "[DOC: {$title}]\n".trim((string) $chunk->content);
            $citations[] = [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'document_title' => $title,
                'ordinal' => $chunk->ordinal,
                'score' => round($hit['score'], 4),
                'snippet' => Str::limit(trim((string) $chunk->content), 240),
            ];
        }
        return [implode("\n\n---\n\n", $blocks), $citations];
    }

    /**
     * Last 6 turns (user/assistant alternating), excluding the most recent $exclude messages.
     *
     * @return array<int, array{role:string,content:string}>
     */
    protected function history(Chat $chat, int $exclude = 0): array
    {
        $msgs = $chat->messages()
            ->latest('created_at')
            ->skip($exclude)
            ->take(6)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        return $msgs->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();
    }
}
