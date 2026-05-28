<?php

namespace App\Services\Rag;

use App\Models\Chat;
use App\Models\Chunk;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Services\Gemini;
use Illuminate\Support\Str;
use Throwable;

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
     * @param  string|null  $language  'en' | 'ur' | 'rud' preference; null = pure auto-detect.
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

        if ($this->isWeak($hits, $userText)) {
            $assistant = $this->refuse($chat, $started, language: $language, userText: $userText);
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
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($language, $userText),
            'citations' => $citations,
            'meta' => ['usage' => $reply['usage'] ?? []] + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $chat->touch();

        return ['message' => $assistant, 'citations' => $citations];
    }

    /**
     * Streaming variant of answerText. Calls $onDelta(text) for each chunk as it
     * arrives from Gemini, then persists the completed assistant message.
     *
     * @param  callable(string):void  $onDelta
     * @return array{message: Message, citations: array}
     */
    public function answerTextStreamed(Chat $chat, string $userText, ?string $language, callable $onDelta): array
    {
        $started = microtime(true);

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $userText,
        ]);

        $this->maybeAssignTitle($chat, $userText);

        $hits = $this->retrieve($chat->knowledge_base_id, $userText);

        if ($this->isWeak($hits, $userText)) {
            $refusal = $this->refusalText($language, $userText);
            $onDelta($refusal);
            $assistant = $this->refuse($chat, $started, language: $language, userText: $userText);
            return ['message' => $assistant, 'citations' => []];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $full = '';
        try {
            foreach ($this->gemini->generateStream($this->systemPrompt($language), $history, $userText, $context) as $delta) {
                $full .= $delta;
                $onDelta($delta);
            }
        } catch (Throwable $e) {
            // If nothing streamed yet, surface the failure; otherwise keep the
            // partial answer we already showed the user.
            if (trim($full) === '') {
                throw $e;
            }
        }

        $full = trim($full);
        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $full !== '' ? $full : $this->refusalText($language, $userText),
            'citations' => $citations,
            'meta' => ['streamed' => true] + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $chat->touch();

        return ['message' => $assistant, 'citations' => $citations];
    }

    /**
     * Run an audio turn: transcribe → embed transcript → retrieve → generate.
     *
     * @return array{message: Message, citations: array, transcript: string}
     */
    public function answerAudio(Chat $chat, string $audioPath, string $audioMime, ?string $language = null): array
    {
        $started = microtime(true);

        $transcript = trim($this->transcribe($audioPath, $audioMime));

        // Nothing intelligible came back (silent clip, mic glitch). Reply with a
        // gentle prompt to try again instead of embedding an empty query.
        if (mb_strlen($transcript) < 2) {
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $this->inaudibleText($language),
                'citations' => [],
                'meta' => ['source' => 'voice', 'inaudible' => true],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            return ['message' => $assistant, 'citations' => [], 'transcript' => ''];
        }

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $transcript,
            'meta' => ['source' => 'voice'],
        ]);

        $this->maybeAssignTitle($chat, $transcript);

        $hits = $this->retrieve($chat->knowledge_base_id, $transcript);

        if ($this->isWeak($hits, $transcript)) {
            $assistant = $this->refuse($chat, $started, 'voice', language: $language, userText: $transcript);
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
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($language, $transcript),
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

        // In full-module mode the hits exist only to rank which modules are
        // relevant, so pull a wider set than the handful we'd cite directly.
        $topK = ($cfg['full_module'] ?? true)
            ? (int) ($cfg['routing_top_k'] ?? 12)
            : (int) ($cfg['top_k'] ?? 6);

        return $this->store->hybridSearch(
            $knowledgeBaseId,
            $queryVec,
            $query,
            $topK,
            (int) ($cfg['candidate_pool'] ?? 24),
            (float) ($cfg['vec_weight'] ?? 0.55),
            (float) ($cfg['kw_weight'] ?? 0.45),
        );
    }

    protected function isWeak(array $hits, string $query): bool
    {
        if (empty($hits)) {
            return true;
        }

        $floor = (float) config('rag.retrieval.rrf_floor', 0.012);

        // The corpus is Urdu-script. A query written entirely in Latin letters
        // (English or Roman Urdu) cannot meaningfully hit the BM25 keyword
        // index, so only the multilingual vector signal fires. Holding such a
        // query to the both-signals floor wrongly refuses valid questions, so
        // relax the floor to a vector-only threshold.
        if (! preg_match('/\p{Arabic}/u', $query)) {
            $floor *= 0.6;
        }

        return ($hits[0]['score'] ?? 0.0) < $floor;
    }

    protected function refuse(Chat $chat, float $started, ?string $source = null, ?string $language = null, ?string $userText = null): Message
    {
        return Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $this->refusalText($language, $userText),
            'citations' => [],
            'meta' => ['refused' => true]
                + ($source ? ['source' => $source] : [])
                + ($language ? ['language' => $language] : []),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * Build the system prompt. The base prompt already tells the model to match
     * the user's language and script (English / Urdu / Roman Urdu). $language is
     * only a *preference* used to break ties when the input language is
     * ambiguous — it never overrides what the user actually wrote.
     */
    protected function systemPrompt(?string $language): string
    {
        $base = (string) config('rag.system_prompt_ur');

        return $base.match ($language) {
            'en'  => "\n\nاگر سوال کی زبان واضح نہ ہو تو انگریزی کو ترجیح دیں۔",
            'ur'  => "\n\nاگر سوال کی زبان واضح نہ ہو تو اردو رسم الخط کو ترجیح دیں۔",
            'rud' => "\n\nاگر سوال کی زبان واضح نہ ہو تو رومن اردو کو ترجیح دیں۔",
            default => '',
        };
    }

    /**
     * Canned refusal used when retrieval finds nothing useful. Urdu script in
     * the question always wins; otherwise we fall back to the UI preference.
     */
    protected function refusalText(?string $language, ?string $userText = null): string
    {
        if ($userText !== null && $userText !== '' && preg_match('/\p{Arabic}/u', $userText)) {
            return 'معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔';
        }

        return match ($language) {
            'en'  => "Sorry, I don't have information about that. Please contact your supervisor.",
            'rud' => 'Maazrat, mere paas is baare mein maloomat nahi hain. Baraah-e-karam apne supervisor se rabta karein.',
            default => 'معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔',
        };
    }

    /**
     * Shown when a voice message produced no intelligible transcript.
     */
    protected function inaudibleText(?string $language): string
    {
        return match ($language) {
            'en'  => "Sorry, I couldn't hear that clearly. Please try recording again.",
            'rud' => 'Maazrat, awaaz saaf sunai nahi di. Baraah-e-karam dobaara record karein.',
            default => 'معذرت، آواز واضح سنائی نہیں دی۔ براہ کرم دوبارہ ریکارڈ کریں۔',
        };
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
        // Delegated to the Gemini service so transcription shares the same
        // 429-aware retry/back-off as every other call — a transient rate
        // limit no longer surfaces to the app as a 500.
        return $this->gemini->transcribe($audioPath, $audioMime);
    }

    /**
     * Build the grounding context from retrieval hits. In full-module mode the
     * hits route the query to the most relevant module(s) and we feed each
     * module's FULL text (within a token budget); otherwise we fall back to the
     * scattered-chunks behaviour.
     *
     * @param  array<int, array{chunk: \App\Models\Chunk, score: float}>  $hits
     * @return array{0: string, 1: array}
     */
    protected function buildContext(array $hits): array
    {
        if (empty($hits)) {
            return ['', []];
        }

        if (config('rag.retrieval.full_module', true)) {
            return $this->buildModuleContext($hits);
        }

        return $this->buildChunkContext($hits);
    }

    /**
     * Rank modules by their best-matching chunk, then feed each module's full
     * text (its chunks in order) until the token budget or module cap is hit.
     * The top-ranked module is always included even if it alone exceeds the
     * budget; subsequent modules are added greedily only while they still fit.
     *
     * @param  array<int, array{chunk: \App\Models\Chunk, score: float}>  $hits
     * @return array{0: string, 1: array}
     */
    protected function buildModuleContext(array $hits): array
    {
        $budget = (int) config('rag.retrieval.module_token_budget', 120000);
        $maxModules = (int) config('rag.retrieval.max_modules', 3);

        // Best (highest-scoring) hit per module — used both to rank modules and
        // as the citation snippet for that module.
        $best = [];
        foreach ($hits as $hit) {
            $id = $hit['chunk']->document_id;
            if (! isset($best[$id]) || $hit['score'] > $best[$id]['score']) {
                $best[$id] = [
                    'score' => $hit['score'],
                    'chunk' => $hit['chunk'],
                    'title' => $hit['chunk']->document?->title ?? "Doc {$id}",
                ];
            }
        }
        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score']);

        $blocks = [];
        $citations = [];
        $usedTokens = 0;
        foreach ($best as $docId => $info) {
            if (count($blocks) >= $maxModules) {
                break;
            }

            $text = $this->moduleText($docId);
            if ($text === '') {
                continue;
            }

            $tokens = (int) ceil(mb_strlen($text) / 4);
            // Always seed with the top module; only gate the additions after it.
            if (! empty($blocks) && $usedTokens + $tokens > $budget) {
                continue;
            }
            $usedTokens += $tokens;

            $blocks[] = "[DOC: {$info['title']}]\n".$text;
            $citations[] = [
                'chunk_id' => $info['chunk']->id,
                'document_id' => $docId,
                'document_title' => $info['title'],
                'ordinal' => $info['chunk']->ordinal,
                'score' => round($info['score'], 4),
                'snippet' => Str::limit(trim((string) $info['chunk']->content), 240),
            ];
        }

        return [implode("\n\n---\n\n", $blocks), $citations];
    }

    /**
     * Reconstruct a module's full text from its chunks in document order. The
     * chunker is paragraph-aware (chunks rarely overlap), so a plain join is
     * clean enough for grounding context.
     */
    protected function moduleText(int $documentId): string
    {
        return Chunk::where('document_id', $documentId)
            ->orderBy('ordinal')
            ->pluck('content')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->implode("\n\n");
    }

    /**
     * Original behaviour: feed each retrieved chunk as its own context block.
     *
     * @param  array<int, array{chunk: \App\Models\Chunk, score: float}>  $hits
     * @return array{0: string, 1: array}
     */
    protected function buildChunkContext(array $hits): array
    {
        $blocks = [];
        $citations = [];
        foreach ($hits as $hit) {
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
