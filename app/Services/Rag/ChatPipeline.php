<?php

namespace App\Services\Rag;

use App\Models\Chat;
use App\Models\Chunk;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\ResponseScript;
use App\Services\Gemini;
use App\Services\Pinecone;
use App\Services\Whisper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class ChatPipeline
{
    protected ?PineconeVectorStore $pineconeStore = null;
    protected ?Whisper $whisper = null;

    public function __construct(
        protected Gemini $gemini,
        protected VectorStore $store,
    ) {
        // Initialize Pinecone if configured
        if (config('rag.providers.vector_store') === 'pinecone' && config('services.pinecone.api_key')) {
            try {
                $this->pineconeStore = new PineconeVectorStore(app(Pinecone::class));
            } catch (Throwable $e) {
                // Pinecone not available, fall back to hybrid search
            }
        }

        // Initialize Whisper if configured
        if (config('rag.providers.stt') === 'whisper' && config('services.openai.api_key')) {
            try {
                $this->whisper = app(Whisper::class);
            } catch (Throwable $e) {
                // Whisper not available, fall back to Gemini
            }
        }
    }

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

        // Detect the actual language from user's text - this overrides app preference
        // so answers match the language the question was asked in
        $effectiveLanguage = $this->detectLanguage($userText, $language);

        // First turn of a chat? Answers don't depend on history then, so they
        // are safe to serve from / store into the shared answer cache.
        $isFirstTurn = ! $chat->messages()->exists();

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $userText,
        ]);

        $this->maybeAssignTitle($chat, $userText);

        // Check for introduction/greeting queries
        $introResponse = $this->maybeIntroduction($userText, $effectiveLanguage);
        if ($introResponse !== null) {
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $introResponse,
                'citations' => [],
                'meta' => ['script' => 'introduction', 'language' => $effectiveLanguage],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            $chat->touch();
            return ['message' => $assistant, 'citations' => []];
        }

        // Serve a cached answer for repeated first-turn questions (suggestion
        // cards and FAQs) — skips embed + retrieval + generation entirely.
        if ($cached = $this->cachedAnswer($chat, $userText, $effectiveLanguage, $isFirstTurn)) {
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $cached['content'],
                'citations' => $cached['citations'],
                'meta' => ['cached' => true, 'language' => $effectiveLanguage],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            $chat->touch();
            return ['message' => $assistant, 'citations' => $cached['citations']];
        }

        $hits = $this->retrieve($chat->knowledge_base_id, $userText);

        if ($this->isWeak($hits, $userText)) {
            $assistant = $this->refuse($chat, $started, language: $effectiveLanguage, userText: $userText);
            return ['message' => $assistant, 'citations' => []];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $reply = $this->gemini->generate(
            $this->systemPrompt($effectiveLanguage),
            $history,
            $userText,
            $context,
            $this->replyInstruction($userText, $effectiveLanguage),
        );

        if ($reply['text'] !== '') {
            $this->storeAnswer($chat, $userText, $effectiveLanguage, $isFirstTurn, $reply['text'], $citations);
        }

        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($effectiveLanguage, $userText),
            'citations' => $citations,
            'meta' => ['usage' => $reply['usage'] ?? [], 'language' => $effectiveLanguage],
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

        // Detect the actual language from user's text
        $effectiveLanguage = $this->detectLanguage($userText, $language);

        $isFirstTurn = ! $chat->messages()->exists();

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $userText,
        ]);

        $this->maybeAssignTitle($chat, $userText);

        // Greetings/intro questions answer from the script, not retrieval.
        $introResponse = $this->maybeIntroduction($userText, $effectiveLanguage);
        if ($introResponse !== null) {
            $onDelta($introResponse);
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $introResponse,
                'citations' => [],
                'meta' => ['script' => 'introduction', 'language' => $effectiveLanguage, 'streamed' => true],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            $chat->touch();
            return ['message' => $assistant, 'citations' => []];
        }

        // Cached first-turn answer: emit it as a single delta — instant reply.
        if ($cached = $this->cachedAnswer($chat, $userText, $effectiveLanguage, $isFirstTurn)) {
            $onDelta($cached['content']);
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $cached['content'],
                'citations' => $cached['citations'],
                'meta' => ['cached' => true, 'language' => $effectiveLanguage, 'streamed' => true],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            $chat->touch();
            return ['message' => $assistant, 'citations' => $cached['citations']];
        }

        $hits = $this->retrieve($chat->knowledge_base_id, $userText);

        if ($this->isWeak($hits, $userText)) {
            $refusal = $this->refusalText($effectiveLanguage, $userText);
            $onDelta($refusal);
            $assistant = $this->refuse($chat, $started, language: $effectiveLanguage, userText: $userText);
            return ['message' => $assistant, 'citations' => []];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $full = '';
        $completed = false;
        try {
            foreach ($this->gemini->generateStream($this->systemPrompt($effectiveLanguage), $history, $userText, $context, $this->replyInstruction($userText, $effectiveLanguage)) as $delta) {
                $full .= $delta;
                $onDelta($delta);
            }
            $completed = true;
        } catch (Throwable $e) {
            // If nothing streamed yet, surface the failure; otherwise keep the
            // partial answer we already showed the user.
            if (trim($full) === '') {
                throw $e;
            }
        }

        $full = trim($full);
        // Only cache answers that streamed to completion — a partial answer
        // must never become the canonical cached reply.
        if ($completed && $full !== '') {
            $this->storeAnswer($chat, $userText, $effectiveLanguage, $isFirstTurn, $full, $citations);
        }

        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $full !== '' ? $full : $this->refusalText($effectiveLanguage, $userText),
            'citations' => $citations,
            'meta' => ['streamed' => true, 'language' => $effectiveLanguage],
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

        $transcript = trim($this->transcribe($audioPath, $audioMime, $language));

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

        // Detect the actual language from the transcript
        $effectiveLanguage = $this->detectLanguage($transcript, $language);

        Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_USER,
            'content' => $transcript,
            'meta' => ['source' => 'voice'],
        ]);

        $this->maybeAssignTitle($chat, $transcript);

        // Spoken greetings/intro questions answer from the script, not retrieval.
        $introResponse = $this->maybeIntroduction($transcript, $effectiveLanguage);
        if ($introResponse !== null) {
            $assistant = Message::create([
                'chat_id' => $chat->id,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $introResponse,
                'citations' => [],
                'meta' => ['script' => 'introduction', 'language' => $effectiveLanguage, 'source' => 'voice'],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            $chat->touch();
            return ['message' => $assistant, 'citations' => [], 'transcript' => $transcript];
        }

        $hits = $this->retrieve($chat->knowledge_base_id, $transcript);

        if ($this->isWeak($hits, $transcript)) {
            $assistant = $this->refuse($chat, $started, 'voice', language: $effectiveLanguage, userText: $transcript);
            return ['message' => $assistant, 'citations' => [], 'transcript' => $transcript];
        }

        [$context, $citations] = $this->buildContext($hits);
        $history = $this->history($chat, exclude: 1);

        $reply = $this->gemini->generate(
            $this->systemPrompt($effectiveLanguage),
            $history,
            $transcript,
            $context,
            $this->replyInstruction($transcript, $effectiveLanguage),
        );

        $assistant = Message::create([
            'chat_id' => $chat->id,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $reply['text'] !== '' ? $reply['text'] : $this->refusalText($effectiveLanguage, $transcript),
            'citations' => $citations,
            'meta' => ['usage' => $reply['usage'] ?? [], 'source' => 'voice', 'language' => $effectiveLanguage],
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $chat->touch();

        return ['message' => $assistant, 'citations' => $citations, 'transcript' => $transcript];
    }

    /**
     * Normalized cache key for a first-turn answer: same KB + same language +
     * same question (whitespace/case-folded) → same reply.
     */
    protected function answerCacheKey(int $knowledgeBaseId, string $userText, string $language): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $userText)));

        return "answer:{$knowledgeBaseId}:{$language}:".md5($normalized);
    }

    /**
     * Fetch a cached first-turn answer, or null. Cached answers are only used
     * on a chat's first turn, where no history can change the reply.
     *
     * @return array{content: string, citations: array}|null
     */
    protected function cachedAnswer(Chat $chat, string $userText, string $language, bool $isFirstTurn): ?array
    {
        if (! $isFirstTurn || ! config('rag.cache.enabled', true)) {
            return null;
        }

        $cached = Cache::get($this->answerCacheKey($chat->knowledge_base_id, $userText, $language));

        return is_array($cached) && isset($cached['content']) ? $cached : null;
    }

    /**
     * Store a successful first-turn answer for reuse. Refusals are never cached
     * (the knowledge base may grow) and follow-up turns depend on history.
     */
    protected function storeAnswer(Chat $chat, string $userText, string $language, bool $isFirstTurn, string $content, array $citations): void
    {
        if (! $isFirstTurn || ! config('rag.cache.enabled', true)) {
            return;
        }

        Cache::put(
            $this->answerCacheKey($chat->knowledge_base_id, $userText, $language),
            ['content' => $content, 'citations' => $citations],
            (int) config('rag.cache.answer_ttl', 21600),
        );
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

        // Use Pinecone if available for faster semantic search
        if ($this->pineconeStore !== null) {
            $minScore = (float) ($cfg['min_score'] ?? 0.55);
            // Cross-lingual queries (Latin text against the Urdu corpus) score
            // lower on cosine similarity than same-script queries; holding them
            // to the same threshold wrongly refuses valid English/Roman-Urdu
            // questions. Mirrors the BM25 relaxation in isWeak().
            if (! preg_match('/\p{Arabic}/u', $query)) {
                $minScore *= 0.75;
            }

            try {
                return $this->pineconeStore->search(
                    $knowledgeBaseId,
                    $queryVec,
                    $topK,
                    $minScore
                );
            } catch (Throwable $e) {
                // Fall back to hybrid search if Pinecone fails
            }
        }

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
     * Build the system prompt. Uses language-specific prompts for Pashto/Sindhi
     * to ensure natural translation from the Urdu knowledge base, not word-by-word.
     */
    protected function systemPrompt(?string $language): string
    {
        $basePrompt = (string) config('rag.system_prompt_ur');

        // Use dedicated prompts for Pashto and Sindhi to ensure proper translation
        return match ($language) {
            'ps'  => (string) config('rag.system_prompt_ps', $basePrompt),
            'sd'  => (string) config('rag.system_prompt_sd', $basePrompt),
            'en'  => $basePrompt . "\n\nIMPORTANT: The user is asking in English. Respond in clear, natural English. Do NOT translate word-by-word from Urdu - understand the content and explain it naturally in English.",
            'rud' => $basePrompt . "\n\nIMPORTANT: User is writing in Roman Urdu (Urdu with Latin letters). Reply in Roman Urdu only, not Urdu script.",
            'auto' => $basePrompt . "\n\nIMPORTANT: Detect the language of the user's question and respond in that SAME language. The knowledge base is in Urdu, so understand the Urdu content first, then formulate a natural response in the user's language. Do NOT translate word-by-word - provide natural, fluent responses. Supported languages include but are not limited to: Farsi/Persian, Punjabi, Arabic, Hindi, Turkish, Bengali, and others.",
            default => $basePrompt,
        };
    }

    /**
     * A short, mandatory reply-language directive placed right after the
     * question. With a large (usually Urdu) module in context, a rule buried in
     * the system prompt isn't enough — the answer drifts toward the context's
     * language. Urdu-script questions always win; otherwise the UI preference
     * disambiguates Latin text (English vs Roman Urdu).
     */
    protected function replyInstruction(string $userText, ?string $language): string
    {
        if (preg_match('/\p{Arabic}/u', $userText)) {
            // Detect Pashto-specific characters
            if (preg_match('/[ټډړږښځڅېګڼ]/u', $userText)) {
                return 'جواب باید په پښتو کې وي.';
            }
            // Detect Sindhi-specific characters
            if (preg_match('/[ڳڻڪھڀٺٽ]/u', $userText)) {
                return 'جواب سنڌيءَ ۾ هجڻ گهرجي.';
            }
            // No script-unique characters: honor the detected/app language
            // rather than forcing Urdu onto Pashto/Sindhi users.
            if ($language === 'ps') {
                return 'جواب باید په پښتو کې وي.';
            }
            if ($language === 'sd') {
                return 'جواب سنڌيءَ ۾ هجڻ گهرجي.';
            }
            return 'جواب لازمی طور پر اردو رسم الخط میں دیں۔';
        }

        return match ($language) {
            'ur'  => 'جواب لازمی طور پر اردو رسم الخط میں دیں۔',
            'rud' => 'Reply ONLY in Roman Urdu (Urdu written with Latin letters), not in Urdu script.',
            'en'  => 'Reply ONLY in English.',
            'ps'  => 'جواب باید په پښتو کې وي.',
            'sd'  => 'جواب سنڌيءَ ۾ هجڻ گهرجي.',
            'auto' => 'CRITICAL: Detect the language of the question above and reply in that EXACT same language and script. Do not reply in Urdu unless the question is in Urdu.',
            default => 'Reply in the exact same language and script as the question.',
        };
    }

    /**
     * Canned refusal used when retrieval finds nothing useful. Checks for admin-
     * configured script first, then falls back to hardcoded defaults. Urdu script
     * in the question always wins; otherwise we fall back to the UI preference.
     */
    protected function refusalText(?string $language, ?string $userText = null): string
    {
        // Detect language from script in user text
        $detectedLang = $language;
        if ($userText !== null && $userText !== '' && preg_match('/\p{Arabic}/u', $userText)) {
            if (preg_match('/[ټډړږښځڅېګڼ]/u', $userText)) {
                $detectedLang = 'ps';
            } elseif (preg_match('/[ڳڻڪھڀٺٽ]/u', $userText)) {
                $detectedLang = 'sd';
            } elseif (! in_array($language, ['ps', 'sd'], true)) {
                // Keep a ps/sd preference; otherwise Arabic script means Urdu.
                $detectedLang = 'ur';
            }
        }

        // Try to get admin-configured script
        $script = ResponseScript::getContentFor('no_answer', $detectedLang ?? 'ur');
        if ($script !== null) {
            return $script;
        }

        // Fall back to hardcoded defaults
        return match ($detectedLang) {
            'en'  => "Sorry, I don't have information about that. Please contact your supervisor.",
            'rud' => 'Maazrat, mere paas is baare mein maloomat nahi hain. Baraah-e-karam apne supervisor se rabta karein.',
            'ps'  => 'بخښنه غواړم، زه د دې په اړه معلومات نلرم. مهرباني وکړئ خپل سوپروایزر سره اړیکه ونیسئ.',
            'sd'  => 'معاف ڪجو، مون وٽ ان بابت معلومات ناهي. مهرباني ڪري پنهنجي سپروائيزر سان رابطو ڪريو.',
            // For auto-detected languages, use English as it's widely understood
            'auto' => "Sorry, I don't have information about that. Please contact your supervisor.",
            default => 'معذرت، میرے پاس اس بارے میں معلومات نہیں ہیں۔ براہ کرم اپنے سپروائزر سے رابطہ کریں۔',
        };
    }

    /**
     * Shown when a voice message produced no intelligible transcript.
     */
    protected function inaudibleText(?string $language): string
    {
        // Try to get admin-configured script
        $script = ResponseScript::getContentFor('inaudible', $language ?? 'ur');
        if ($script !== null) {
            return $script;
        }

        // Fall back to hardcoded defaults
        return match ($language) {
            'en'  => "Sorry, I couldn't hear that clearly. Please try recording again.",
            'rud' => 'Maazrat, awaaz saaf sunai nahi di. Baraah-e-karam dobaara record karein.',
            'ps'  => 'بخښنه، زه دا روښانه واورېدلی نه شم. مهرباني وکړئ بیا ریکارډ کړئ.',
            'sd'  => 'معاف ڪجو، مان اهو صاف ٻڌي نه سگهيس. مهرباني ڪري ٻيهر رڪارڊ ڪريو.',
            'auto' => "Sorry, I couldn't hear that clearly. Please try recording again.",
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

    /**
     * Detect the language from the user's text. Script-based detection takes
     * priority over the app preference, ensuring answers match the language
     * the question was asked in.
     *
     * Priority:
     * 1. Pashto script (has unique characters like ټډړږښځڅېګڼ)
     * 2. Sindhi script (has unique characters like ڳڻڪھڀٺٽ)
     * 3. Other Arabic scripts (Farsi, Punjabi Shahmukhi, Arabic, etc.) → 'auto'
     * 4. Urdu script (if app preference is ur)
     * 5. App preference (for Latin text: en vs rud)
     * 6. Other scripts (Cyrillic, Devanagari, CJK, etc.) → 'auto'
     */
    protected function detectLanguage(string $userText, ?string $appLanguage): string
    {
        // Check for Arabic script (includes Urdu, Pashto, Sindhi, Farsi, Arabic, Punjabi Shahmukhi)
        if (preg_match('/\p{Arabic}/u', $userText)) {
            // Pashto-specific characters
            if (preg_match('/[ټډړږښځڅېګڼ]/u', $userText)) {
                return 'ps';
            }
            // Sindhi-specific characters
            if (preg_match('/[ڳڻڪھڀٺٽ]/u', $userText)) {
                return 'sd';
            }
            // No script-unique characters — short Pashto/Sindhi sentences often
            // contain none, so trust the user's app language for any of the
            // three Arabic-script languages we support.
            if (in_array($appLanguage, ['ur', 'ps', 'sd'], true)) {
                return $appLanguage;
            }
            // Could be Farsi, Arabic, Punjabi Shahmukhi - let LLM auto-detect
            return 'auto';
        }

        // Check for other non-Latin scripts - let LLM auto-detect
        // Devanagari (Hindi, Sanskrit, Marathi)
        if (preg_match('/\p{Devanagari}/u', $userText)) {
            return 'auto';
        }
        // Gurmukhi (Punjabi)
        if (preg_match('/\p{Gurmukhi}/u', $userText)) {
            return 'auto';
        }
        // Bengali
        if (preg_match('/\p{Bengali}/u', $userText)) {
            return 'auto';
        }
        // Tamil
        if (preg_match('/\p{Tamil}/u', $userText)) {
            return 'auto';
        }
        // Cyrillic (Russian, etc.)
        if (preg_match('/\p{Cyrillic}/u', $userText)) {
            return 'auto';
        }
        // CJK (Chinese, Japanese, Korean)
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $userText)) {
            return 'auto';
        }

        // Latin text - use app preference to distinguish English vs Roman Urdu
        return $appLanguage ?? 'ur';
    }

    /**
     * Check if the query is asking for an introduction/greeting and return
     * the configured script if available. Returns null if not an intro query.
     */
    protected function maybeIntroduction(string $userText, ?string $language): ?string
    {
        $text = mb_strtolower(trim($userText));

        // Identity questions — a prefix match is safe ("who are you exactly?").
        $identityPatterns = [
            '/^(who are you|what are you|introduce yourself|tell me about yourself|what is tahaffuz|what is this)\b/i',
            '/^(تم کون ہو|آپ کون ہیں|تعارف|اپنا تعارف|تحفظ کیا ہے)/u',
            '/^(tum kaun ho|aap kaun hain|taaruf|apna taaruf|tahaffuz kya hai)\b/i',
            '/^(ته څوک یې|تاسو څوک یاست|ځان معرفي کړئ)/u',
            '/^(توهان ڪير آهيو|پاڻ جو تعارف)/u',
        ];

        // Bare greetings — must be the ENTIRE message, otherwise "Assalam,
        // polio vaccine kab lagti hai?" would get the intro instead of an answer.
        $greetingPatterns = [
            '/^(hello|hi|hey|salam|(assalam|asalam)([\s\-]*o[\s\-]*alaikum|u[\s\-]*alaikum)?|assalamualaikum)[\s!.،؟?]*$/iu',
            '/^(السلام علیکم(\s*ورحمۃ اللہ(\s*وبرکاتہ)?)?|سلام|اسلام علیکم)[\s!.،؟?]*$/u',
        ];

        $isIntro = false;
        foreach ($identityPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $isIntro = true;
                break;
            }
        }
        if (! $isIntro) {
            foreach ($greetingPatterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    $isIntro = true;
                    break;
                }
            }
        }

        if (! $isIntro) {
            return null;
        }

        // Detect language from script
        $detectedLang = $language;
        if (preg_match('/\p{Arabic}/u', $userText)) {
            if (preg_match('/[ټډړږښځڅېګڼ]/u', $userText)) {
                $detectedLang = 'ps';
            } elseif (preg_match('/[ڳڻڪھڀٺٽ]/u', $userText)) {
                $detectedLang = 'sd';
            } elseif (! in_array($language, ['ps', 'sd'], true)) {
                $detectedLang = 'ur';
            }
        }

        // Admin-configured script wins; otherwise fall back to a built-in
        // introduction so greetings never hit the refusal path.
        return ResponseScript::getContentFor('introduction', $detectedLang ?? 'ur')
            ?? $this->defaultIntroduction($detectedLang ?? 'ur');
    }

    /**
     * Built-in introduction used when no admin script is configured.
     */
    protected function defaultIntroduction(string $language): string
    {
        return match ($language) {
            'en'  => "Hello! I am Tahaffuz, your EPI training assistant. I can answer questions about vaccines, the cold chain, and immunization schedules. How can I help you today?",
            'rud' => 'Assalam-o-Alaikum! Main Tahaffuz hoon, aapka EPI training assistant. Main vaccine, cold chain, aur immunization schedule ke baare mein sawalon ka jawab de sakta hoon. Aaj main aapki kaise madad karoon?',
            'ps'  => 'سلام! زه "تحفظ" یم، ستاسو د EPI روزنې مرستندویه. زه د واکسینونو، کولډ چین، او د واکسینیشن مهالویش په اړه پوښتنو ته ځواب ورکولی شم. نن څنګه مرسته وکړم؟',
            'sd'  => 'السلام عليڪم! مان "تحفظ" آهيان، توهان جو EPI ٽريننگ اسسٽنٽ. مان ويڪسين، ڪولڊ چين، ۽ واڪسينيشن شيڊول بابت سوالن جا جواب ڏئي سگهان ٿو. اڄ ڪيئن مدد ڪريان؟',
            default => 'السلام علیکم! میں "تحفظ" ہوں — آپ کا EPI ٹریننگ معاون۔ میں ویکسین، کولڈ چین، اور حفاظتی ٹیکوں کے شیڈول کے بارے میں سوالات کا جواب دے سکتا ہوں۔ آج میں آپ کی کیسے مدد کروں؟',
        };
    }

    protected function transcribe(string $audioPath, string $audioMime, ?string $language = null): string
    {
        // Use Whisper if available for better multilingual transcription
        if ($this->whisper !== null) {
            try {
                $result = $this->whisper->transcribe($audioPath);
                return $result['text'] ?? '';
            } catch (Throwable $e) {
                // Fall back to Gemini if Whisper fails
            }
        }

        // Delegated to the Gemini service so transcription shares the same
        // 429-aware retry/back-off as every other call — a transient rate
        // limit no longer surfaces to the app as a 500.
        return $this->gemini->transcribe($audioPath, $audioMime, $language);
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
     * clean enough for grounding context. Cached for 1 hour to avoid repeated DB queries.
     */
    protected function moduleText(int $documentId): string
    {
        if (! config('rag.cache.enabled', true)) {
            return $this->moduleTextUncached($documentId);
        }

        return Cache::remember(
            "module_text:{$documentId}",
            3600,
            fn () => $this->moduleTextUncached($documentId)
        );
    }

    protected function moduleTextUncached(int $documentId): string
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
     * Last 4 turns (user/assistant alternating), excluding the most recent $exclude messages.
     * Reduced from 6 to 4 for faster context building and smaller prompts.
     *
     * @return array<int, array{role:string,content:string}>
     */
    protected function history(Chat $chat, int $exclude = 0): array
    {
        $msgs = $chat->messages()
            ->latest('created_at') // Uses index on [chat_id, created_at]
            ->skip($exclude)
            ->take(4)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        return $msgs->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();
    }
}
