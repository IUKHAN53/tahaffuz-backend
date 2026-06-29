<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class Gemini
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $chatModel = null,
        protected ?string $embedModel = null,
        protected ?string $baseUrl = null,
        protected int $timeout = 60,
    ) {
        $cfg = config('rag.gemini');
        $this->apiKey ??= $cfg['api_key'];
        $this->chatModel ??= $cfg['chat_model'];
        $this->embedModel ??= $cfg['embed_model'];
        $this->baseUrl ??= $cfg['base_url'];
        $this->timeout = $cfg['timeout'] ?? $this->timeout;

        if (! $this->apiKey) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }
    }

    /**
     * Embed a single string. Returns float[].
     */
    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        // Use cache for query embeddings to reduce API calls
        if ($taskType === 'RETRIEVAL_QUERY' && config('rag.cache.enabled', true)) {
            $cacheKey = 'embed:' . md5($text . $taskType);
            $ttl = (int) config('rag.cache.embed_ttl', 3600);

            return Cache::remember($cacheKey, $ttl, function () use ($text, $taskType) {
                return $this->embedUncached($text, $taskType);
            });
        }

        return $this->embedUncached($text, $taskType);
    }

    /**
     * Embed without caching - direct API call.
     */
    protected function embedUncached(string $text, string $taskType): array
    {
        $url = "{$this->baseUrl}/models/{$this->embedModel}:embedContent?key={$this->apiKey}";
        $resp = $this->postWithRetry($url, [
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => (int) config('rag.gemini.embed_dim', 768),
        ]);

        $vec = $resp->json('embedding.values');
        if (! is_array($vec) || count($vec) === 0) {
            throw new RuntimeException('Gemini embed returned no values.');
        }
        return array_map('floatval', $vec);
    }

    /**
     * Embed many strings via Gemini's batchEmbedContents endpoint. Batching is what
     * keeps free-tier ingestion under the rate cap — a 150-chunk document becomes a
     * handful of requests instead of 150. Returned vectors match the input order.
     *
     * @param  string[]  $texts
     * @return array<int, float[]>
     */
    public function embedMany(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        if (empty($texts)) {
            return [];
        }

        $dim = (int) config('rag.gemini.embed_dim', 768);
        // batchEmbedContents accepts at most 100 requests per call.
        $batchSize = max(1, min(100, (int) config('rag.gemini.embed_batch_size', 50)));
        $delayMs = (int) config('rag.gemini.embed_inter_batch_ms', 1500);
        $url = "{$this->baseUrl}/models/{$this->embedModel}:batchEmbedContents?key={$this->apiKey}";

        $out = [];
        foreach (array_chunk($texts, $batchSize) as $i => $batch) {
            if ($i > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $requests = array_map(fn ($t) => [
                'model' => "models/{$this->embedModel}",
                'content' => ['parts' => [['text' => $t]]],
                'taskType' => $taskType,
                'outputDimensionality' => $dim,
            ], $batch);

            $resp = $this->postWithRetry($url, ['requests' => $requests], 120);
            $embeddings = $resp->json('embeddings');

            if (! is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new RuntimeException(
                    'Gemini batch embed returned '
                    .(is_array($embeddings) ? count($embeddings) : 'no')
                    .' embeddings for '.count($batch).' inputs.'
                );
            }

            foreach ($embeddings as $e) {
                $vec = $e['values'] ?? null;
                if (! is_array($vec) || count($vec) === 0) {
                    throw new RuntimeException('Gemini batch embed returned an empty vector.');
                }
                $out[] = array_map('floatval', $vec);
            }
        }

        return $out;
    }

    /**
     * POST to Gemini with retry. Honors the server-provided retryDelay on HTTP 429
     * (free-tier rate limits) and backs off exponentially on 5xx / transport errors.
     * Non-429 4xx errors are not retried — they won't fix themselves.
     *
     * $maxWaitMs caps how long a single back-off may pause. Ingestion can afford a
     * patient wait (default 65s); interactive chat calls pass a short cap so a user
     * never sits behind a multi-minute retry loop.
     */
    protected function postWithRetry(
        string $url,
        array $payload,
        ?int $timeout = null,
        int $maxAttempts = 6,
        int $maxWaitMs = 65000,
    ): Response {
        $timeout ??= $this->timeout;
        $backoffMs = 2000;
        $last = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $resp = Http::timeout($timeout)->post($url, $payload);
            } catch (Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException("Gemini request failed (transport): {$e->getMessage()}");
                }
                usleep(min($backoffMs, $maxWaitMs) * 1000);
                $backoffMs = (int) min($backoffMs * 2, 30000);
                continue;
            }

            if ($resp->successful()) {
                return $resp;
            }

            $last = $resp;
            $status = $resp->status();

            // Non-429 client errors won't resolve with a retry.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt >= $maxAttempts) {
                break;
            }

            // On 429, wait as long as Gemini asks (capped by $maxWaitMs); else back off.
            $serverWaitMs = $status === 429 ? $this->retryDelayMs($resp) : 0;
            $waitMs = min($serverWaitMs > 0 ? $serverWaitMs : $backoffMs, $maxWaitMs);
            Log::warning('Gemini request retrying', ['status' => $status, 'attempt' => $attempt, 'wait_ms' => $waitMs]);
            usleep($waitMs * 1000);
            $backoffMs = (int) min($backoffMs * 2, 30000);
        }

        $status = $last?->status() ?? 0;
        $body = $last ? substr($last->body(), 0, 500) : '';
        throw new RuntimeException("Gemini request failed: HTTP {$status} {$body}");
    }

    /**
     * Extract the server's requested retry delay (in ms) from a 429 RetryInfo detail.
     */
    protected function retryDelayMs(Response $resp): int
    {
        foreach ((array) $resp->json('error.details', []) as $detail) {
            $type = (string) ($detail['@type'] ?? '');
            if (str_contains($type, 'RetryInfo') && isset($detail['retryDelay'])) {
                if (preg_match('/([0-9.]+)s/', (string) $detail['retryDelay'], $m)) {
                    return (int) ceil(((float) $m[1]) * 1000) + 1000; // +1s cushion
                }
            }
        }

        return 0;
    }

    /**
     * Generate a grounded reply.
     *
     * @param  array<int, array{role:string,content:string}>  $history  prior turns (oldest first)
     */
    public function generate(string $systemPrompt, array $history, string $userMessage, string $context, ?string $replyInstruction = null): array
    {
        $url = "{$this->baseUrl}/models/{$this->chatModel}:generateContent?key={$this->apiKey}";

        // Interactive call: a user is waiting, so retry a few times with a short
        // back-off cap rather than the patient schedule ingestion uses.
        $resp = $this->postWithRetry(
            $url,
            $this->generatePayload($systemPrompt, $history, $userMessage, $context, $replyInstruction),
            maxAttempts: 3,
            maxWaitMs: 8000,
        );

        $text = $resp->json('candidates.0.content.parts.0.text', '');
        return [
            'text' => trim((string) $text),
            'usage' => $resp->json('usageMetadata', []),
        ];
    }

    /**
     * Build the request body for a grounded chat turn. Shared by generate() and
     * the streaming endpoint.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     */
    public function generatePayload(string $systemPrompt, array $history, string $userMessage, string $context, ?string $replyInstruction = null): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }

        // The reply-language directive sits LAST, right after the question, so a
        // large (often Urdu) context block can't drift the answer's language.
        $tail = "CONTEXT:\n{$context}\n\nسوال: {$userMessage}";
        if ($replyInstruction !== null && $replyInstruction !== '') {
            $tail .= "\n\n[{$replyInstruction}]";
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $tail]],
        ];

        return [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
            'generationConfig' => $this->generationConfig([
                'temperature' => 0.2,
                'topP' => 0.9,
                // Reduced from 1024 for faster responses - most answers are under 400 tokens
                'maxOutputTokens' => 512,
            ]),
        ];
    }

    /**
     * Apply the configured thinking budget to a generationConfig block. Budget 0
     * disables thinking (the latency win for grounded answers); -1 omits the
     * field for models that don't accept it.
     */
    protected function generationConfig(array $config): array
    {
        $budget = (int) config('rag.gemini.thinking_budget', 0);
        if ($budget >= 0) {
            $config['thinkingConfig'] = ['thinkingBudget' => $budget];
        }

        return $config;
    }

    /**
     * Stream a grounded reply token-by-token. Yields plain text deltas as they
     * arrive from Gemini's Server-Sent Events stream.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     * @return \Generator<int, string>
     */
    public function generateStream(string $systemPrompt, array $history, string $userMessage, string $context, ?string $replyInstruction = null): \Generator
    {
        $url = "{$this->baseUrl}/models/{$this->chatModel}:streamGenerateContent?alt=sse&key={$this->apiKey}";
        $payload = $this->generatePayload($systemPrompt, $history, $userMessage, $context, $replyInstruction);

        $resp = Http::timeout($this->timeout)
            ->withOptions(['stream' => true])
            ->post($url, $payload);

        if ($resp->failed()) {
            throw new RuntimeException("Gemini stream failed: HTTP {$resp->status()}");
        }

        $body = $resp->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }
            // Normalize CRLF — Gemini's SSE stream delimits events with \r\n\r\n.
            $buffer .= str_replace("\r\n", "\n", $chunk);

            // SSE events are separated by a blank line.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $delta = $this->sseEventText($event);
                if ($delta !== '') {
                    yield $delta;
                }
            }
        }

        // Flush any trailing event that arrived without a closing blank line.
        $delta = $this->sseEventText($buffer);
        if ($delta !== '') {
            yield $delta;
        }
    }

    /**
     * Pull the text delta out of a single SSE event block.
     */
    protected function sseEventText(string $event): string
    {
        $out = '';
        foreach (explode("\n", $event) as $line) {
            $line = ltrim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            $decoded = json_decode($json, true);
            $out .= $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        return $out;
    }

    /**
     * Transcribe an audio file to text. Urdu / Pashto / Sindhi / Roman Urdu /
     * English capable. The transcript is ALWAYS faithful to the language the
     * speaker actually used — the app's $languageHint is only a weak tiebreaker
     * for genuinely ambiguous Latin speech (Roman Urdu vs English), never a
     * coercion (a Pashto speaker on an English-set app must still get Pashto).
     * Routes through the retry layer so a transient 429 no longer 500s the chat.
     */
    public function transcribe(string $audioPath, string $audioMime, ?string $languageHint = null): string
    {
        $bytes = @file_get_contents($audioPath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read audio file: {$audioPath}");
        }

        $model = (string) (config('rag.gemini.transcribe_model') ?: $this->chatModel);
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $prompt = 'Transcribe this audio verbatim in the EXACT language the speaker actually uses, '
            .'written in that language\'s own native script — Pashto in Pashto script (using its '
            .'letters ګ ړ ږ ښ ځ څ ډ ټ ڼ ې), Sindhi in Sindhi script, Urdu in Urdu script. The '
            .'speaker may use Pashto, Sindhi, Urdu, Punjabi, English, or Roman Urdu. NEVER translate '
            .'or convert the speech into a different language or script: if they speak Pashto, output '
            .'Pashto; if Sindhi, output Sindhi.';
        if ($languageHint === 'ps' || $languageHint === 'sd') {
            // The user explicitly chose Pashto/Sindhi — these are hard for STT
            // models (they tend to drift to Urdu), so treat the choice as a firm
            // directive, not a tiebreaker.
            $lang = $languageHint === 'ps' ? 'Pashto' : 'Sindhi';
            $prompt .= " The speaker is speaking {$lang}. Transcribe it in {$lang} using {$lang} "
                .'script. Do NOT output Urdu, Dari, or Persian — keep it in '.$lang.'.';
        } else {
            $hintName = match ($languageHint) {
                'ur' => 'Urdu',
                'fa' => 'Farsi (Persian)',
                'en' => 'English',
                default => null,
            };
            if ($hintName !== null) {
                // Tiebreaker ONLY — the actual spoken language always wins.
                $prompt .= " If (and only if) the spoken language is genuinely ambiguous, the app is "
                    ."currently set to {$hintName}; but the language actually spoken always takes "
                    .'priority over this setting.';
            }
        }
        $prompt .= ' Return ONLY the transcript text, no commentary.';

        $resp = $this->postWithRetry($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => [
                        'mimeType' => $this->normalizeAudioMime($audioMime),
                        'data' => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => $this->generationConfig(['temperature' => 0.0, 'maxOutputTokens' => 1024]),
        ], maxAttempts: 3, maxWaitMs: 8000);

        return trim((string) $resp->json('candidates.0.content.parts.0.text', ''));
    }

    /**
     * Map whatever MIME the upload arrived with onto a type Gemini accepts.
     * Expo records m4a (an MP4 container); PHP often reports that as video/mp4.
     */
    public function normalizeAudioMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match (true) {
            $mime === '' => 'audio/mp4',
            str_contains($mime, 'm4a'),
            str_contains($mime, 'mp4'),
            str_contains($mime, 'aac'),
            str_contains($mime, '3gp') => 'audio/mp4',
            str_contains($mime, 'mp3'),
            str_contains($mime, 'mpeg') => 'audio/mp3',
            str_contains($mime, 'wav') => 'audio/wav',
            str_contains($mime, 'ogg'),
            str_contains($mime, 'opus') => 'audio/ogg',
            str_contains($mime, 'flac') => 'audio/flac',
            default => $mime,
        };
    }

    /**
     * Generate from audio input in one shot (transcript + answer). Kept for
     * callers that don't need retrieval grounding; the chat pipeline uses the
     * transcribe-then-generate path instead.
     */
    /**
     * Read a photographed EPI child immunization card with Gemini Vision and
     * return the structured fields. Dates are handwritten so extraction is
     * best-effort — the app shows the result for the worker to correct.
     *
     * @return array<string, mixed>
     */
    public function extractCardData(string $imagePath, string $imageMime): array
    {
        $bytes = @file_get_contents($imagePath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read image file: {$imagePath}");
        }

        $model = (string) (config('rag.gemini.vision_model') ?: $this->chatModel);
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $prompt = 'FIRST, decide whether this image could be a Pakistani EPI child immunization / '
            .'vaccination card — the bilingual Urdu/English card, usually with a coloured vaccine grid '
            .'listing OPV, BCG, Penta, PCV, Rota, IPV, MR, etc., and a child/guardian info page. Set '
            .'"is_card" to true if it looks like such a card EVEN IF the photo is angled, blurry, partially '
            .'cropped, faded, or the handwriting is hard to read, and even if only one page is visible. '
            .'Only set "is_card" to false if the image is clearly something else entirely — a selfie or '
            .'person, a landscape, a screenshot, or an unrelated document with no vaccine grid or EPI '
            .'card layout. When in doubt, set it to true.'."\n\n"
            .'If it IS a card, extract its details into JSON. The right page has the child/guardian info and '
            .'card number; the left grid lists vaccines (OPV-0, Hep B, BCG, OPV-1, Rota-1, PCV-1, Penta-1, '
            .'OPV-2, Rota-2, PCV-2, Penta-2, OPV-3, IPV-1, PCV-3, Penta-3, IPV-2, Typhoid, MR-1, MR-2), each '
            .'with a handwritten VACCINATION DATE (given) and NEXT VACCINATION DATE (due). Read the '
            .'handwritten dates as best you can, formatted DD/MM/YY. Only include vaccines that have a date '
            .'written. sex is "boy" or "girl" (لڑکا = boy, لڑکی = girl). For any field you cannot read, use '
            .'an empty string.';

        $resp = $this->postWithRetry($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => [
                        'mimeType' => $imageMime,
                        'data' => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.0,
                // gemini-2.5-flash "thinks" by default, which silently consumes the
                // whole output budget on a vision call and returns an EMPTY body —
                // so every card was read as {} and rejected as "not a card". Disable
                // thinking (budget 0) and give the JSON room.
                'thinkingConfig' => ['thinkingBudget' => 0],
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'is_card' => ['type' => 'boolean'],
                        'child_name' => ['type' => 'string'],
                        'sex' => ['type' => 'string'],
                        'date_of_birth' => ['type' => 'string'],
                        'father_name' => ['type' => 'string'],
                        'mother_name' => ['type' => 'string'],
                        'card_number' => ['type' => 'string'],
                        'district' => ['type' => 'string'],
                        'town' => ['type' => 'string'],
                        'union_council' => ['type' => 'string'],
                        'next_due_date' => ['type' => 'string'],
                        'vaccines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'given_date' => ['type' => 'string'],
                                    'due_date' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], maxAttempts: 3, maxWaitMs: 12000);

        $raw = $resp->json('candidates.0.content.parts.0.text', '{}');
        $parsed = json_decode((string) $raw, true);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Extract durable, third-person facts about the worker or the specific child
     * from one chat exchange, for the memory layer. Returns a (possibly empty)
     * list of short statements. Cheap (flash-lite, thinking off); best-effort.
     *
     * @return array<int, string>
     */
    public function extractMemories(string $userText, string $assistantText): array
    {
        $model = (string) config('rag.gemini.extract_model', 'gemini-2.5-flash-lite');
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $prompt = "From the exchange below, extract any DURABLE facts about the specific CHILD being "
            ."discussed or about the health WORKER that would help answer their FUTURE questions — for "
            ."example the child's age or date of birth, vaccines the child has already received, a named "
            ."condition or reaction, or the worker's area, role, or a stated preference.\n\n"
            ."RULES:\n"
            ."- Only include facts the user EXPLICITLY stated. Do NOT infer, and do NOT include general "
            ."medical knowledge or anything the assistant merely explained.\n"
            ."- Write each as one short third-person statement, e.g. \"The child is 10 weeks old.\"\n"
            ."- If there is nothing durable worth remembering, return an empty array.\n\n"
            ."USER: {$userText}\nASSISTANT: {$assistantText}";

        $resp = $this->postWithRetry($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.0,
                'thinkingConfig' => ['thinkingBudget' => 0],
                'maxOutputTokens' => 512,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ], maxAttempts: 2, maxWaitMs: 6000);

        $raw = $resp->json('candidates.0.content.parts.0.text', '[]');
        $parsed = json_decode((string) $raw, true);

        if (! is_array($parsed)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($x) => is_string($x) ? trim($x) : '',
            $parsed,
        )));
    }

    /**
     * Decide whether the user wants to be DIRECTED TO A PLACE where they (or a
     * child) can GO to get vaccinated — a centre/site/clinic/nearest location —
     * as opposed to any other question. Used to route to the live site locator
     * instead of relying on brittle keyword matching. Cheap (flash-lite, thinking
     * off, tiny output); best-effort and defaults to false on any error.
     */
    public function classifyLocationRequest(string $userText): bool
    {
        $userText = trim($userText);
        if ($userText === '') {
            return false;
        }

        // The chat model (flash) — flash-lite is too weak for this nuanced
        // Urdu intent call (it swings between over-eager and over-conservative).
        $model = (string) (config('rag.gemini.classify_model') ?: $this->chatModel);
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $prompt = "You route messages for a Pakistani vaccination assistant (Urdu/English/Pashto/Sindhi). "
            ."Answer ONLY with JSON {\"wants_site_location\": true|false}.\n\n"
            ."TRUE = the user wants to be told a PHYSICAL PLACE / centre / site / clinic they can GO to in "
            ."order to get a vaccination — e.g. 'where can I get vaccinated', 'I live in X, where can I get "
            ."the vaccine', 'share the nearest location', 'which place in my area gives the shot'.\n"
            ."FALSE = anything else, ESPECIALLY where ON THE BODY / in the body a vaccine is injected "
            ."(arm, thigh, 'جسم میں کہاں'), the schedule/timing/age, doses, side effects/fever, cold "
            ."chain, eligibility, or general knowledge.\n\n"
            ."Examples:\n"
            ."'میں چشتی نگر میں رہتا ہوں کہاں سے ٹیکا لگوا سکتا ہوں' => true\n"
            ."'قریب ترین ویکسینیشن سینٹر کہاں ہے' => true\n"
            ."'لوکیشن شیئر کریں جہاں سے ٹیکا لگ سکے' => true\n"
            ."'ہمارے علاقے میں ٹیکا کہاں سے لگوائیں' => true\n"
            ."'ٹیکہ جسم میں کہاں لگایا جاتا ہے' => false\n"
            ."'ٹیکہ بازو میں لگتا ہے یا ران میں' => false\n"
            ."'خسرہ کا ٹیکہ کب لگتا ہے' => false\n"
            ."'ٹیکا لگنے کے بعد بخار کیوں ہوتا ہے' => false\n\n"
            ."Message: {$userText}";

        try {
            $resp = $this->postWithRetry($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.0,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                    'maxOutputTokens' => 64,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'object',
                        'properties' => ['wants_site_location' => ['type' => 'boolean']],
                        'required' => ['wants_site_location'],
                    ],
                ],
            ], maxAttempts: 2, maxWaitMs: 4000);

            $raw = $resp->json('candidates.0.content.parts.0.text', '{}');
            $parsed = json_decode((string) $raw, true);

            return (bool) ($parsed['wants_site_location'] ?? false);
        } catch (Throwable $e) {
            // Best-effort — the keyword fast-path already covers the common asks.
            return false;
        }
    }

    /**
     * When the user NAMES an area/place (any language or script), match it to one
     * of the known site areas — e.g. Urdu "چشتی نگر" -> "Chishti Nagar-7". Returns
     * the matched area string (guaranteed to be one of $areas) or null. Lets a
     * user find sites without GPS or registration. Best-effort, null on error.
     *
     * @param  array<int, string>  $areas
     */
    public function extractMentionedArea(string $userText, array $areas): ?string
    {
        $userText = trim($userText);
        if ($userText === '' || empty($areas)) {
            return null;
        }

        $model = (string) (config('rag.gemini.classify_model') ?: $this->chatModel);
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $list = implode("\n", array_map(fn ($a) => "- {$a}", $areas));
        $prompt = "A user is asking where to get vaccinated and may NAME an area/place — in any "
            ."language or script (e.g. Urdu 'چشتی نگر' = 'Chishti Nagar-7', 'گجرو' = a 'Gujro Zone'). "
            ."From the KNOWN AREAS list below, return the SINGLE entry the user is referring to, "
            ."matching across scripts and spellings; if they name a broader area pick the closest "
            ."listed match. If they do NOT name any place, or it is not in the list, return an empty "
            ."string. Return EXACTLY one of the listed strings, verbatim.\n\n"
            ."KNOWN AREAS:\n{$list}\n\nUSER MESSAGE: {$userText}";

        try {
            $resp = $this->postWithRetry($url, [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => 0.0,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                    'maxOutputTokens' => 64,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => [
                        'type' => 'object',
                        'properties' => ['area' => ['type' => 'string']],
                        'required' => ['area'],
                    ],
                ],
            ], maxAttempts: 2, maxWaitMs: 4000);

            $raw = $resp->json('candidates.0.content.parts.0.text', '{}');
            $parsed = json_decode((string) $raw, true);
            $area = is_array($parsed) ? trim((string) ($parsed['area'] ?? '')) : '';

            // Only accept an exact known area (guard against hallucinated names).
            return ($area !== '' && in_array($area, $areas, true)) ? $area : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function generateFromAudio(string $systemPrompt, string $audioPath, string $audioMime, string $context): array
    {
        $bytes = @file_get_contents($audioPath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read audio file: {$audioPath}");
        }

        $url = "{$this->baseUrl}/models/{$this->chatModel}:generateContent?key={$this->apiKey}";
        $resp = $this->postWithRetry($url, [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => "CONTEXT:\n{$context}\n\nصارف کا آڈیو سوال نیچے ہے۔ پہلے اسے سمجھیں، پھر صرف CONTEXT کی بنیاد پر جواب دیں۔"],
                    ['inlineData' => [
                        'mimeType' => $this->normalizeAudioMime($audioMime),
                        'data' => base64_encode($bytes),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.9,
                'maxOutputTokens' => 1024,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'transcript' => ['type' => 'string'],
                        'answer' => ['type' => 'string'],
                    ],
                    'required' => ['transcript', 'answer'],
                ],
            ],
        ], maxAttempts: 3, maxWaitMs: 8000);

        $raw = $resp->json('candidates.0.content.parts.0.text', '{}');
        $parsed = json_decode((string) $raw, true) ?: [];
        return [
            'transcript' => trim((string) ($parsed['transcript'] ?? '')),
            'text' => trim((string) ($parsed['answer'] ?? '')),
            'usage' => $resp->json('usageMetadata', []),
        ];
    }

    /**
     * Synthesize speech from text via Gemini's TTS model. Returns raw 16-bit
     * little-endian PCM bytes (mono, at the configured sample rate); the caller
     * wraps these in a WAV header. Gemini auto-detects the language from the
     * text, so feed Urdu *script* for an Urdu voice — Roman Urdu must be
     * transliterated first (see transliterateToUrduScript).
     */
    public function synthesizeSpeech(string $text, ?string $voice = null): string
    {
        $model = (string) config('rag.gemini.tts_model', 'gemini-2.5-flash-preview-tts');
        $voice ??= (string) config('rag.gemini.tts_voice', 'Kore');
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $text]],
            ]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
        ];

        // The TTS model occasionally returns a 200 with no audio part. That's a
        // transient empty response, not an HTTP error, so postWithRetry doesn't
        // catch it — retry the whole call a couple of times before giving up.
        $lastError = 'Gemini TTS returned no audio.';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $resp = $this->postWithRetry($url, $payload, maxAttempts: 3, maxWaitMs: 8000);

            $b64 = $resp->json('candidates.0.content.parts.0.inlineData.data');
            if (is_string($b64) && $b64 !== '') {
                $pcm = base64_decode($b64, true);
                if ($pcm !== false && $pcm !== '') {
                    return $pcm;
                }
                $lastError = 'Gemini TTS returned undecodable audio.';
            }

            if ($attempt < 3) {
                usleep(600 * 1000);
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Wrap raw little-endian 16-bit mono PCM in a minimal WAV (RIFF) header so
     * it plays in any standard audio player (ExoPlayer on Android included).
     */
    public static function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $byteRate = (int) ($sampleRate * $channels * ($bitsPerSample / 8));
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));
        $dataLen = strlen($pcm);

        $header = 'RIFF'
            .pack('V', 36 + $dataLen)
            .'WAVE'
            .'fmt '
            .pack('V', 16)              // PCM fmt chunk size
            .pack('v', 1)               // audio format = PCM
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataLen);

        return $header.$pcm;
    }

    /**
     * Transliterate Roman Urdu (Urdu written in Latin letters) into proper Urdu
     * (Arabic) script so the TTS voice pronounces it as Urdu, not as English.
     * Uses the cheap extract model. Falls back to the input on any failure.
     */
    public function transliterateToUrduScript(string $text): string
    {
        $model = (string) (config('rag.gemini.extract_model') ?: $this->chatModel);
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $resp = $this->postWithRetry($url, [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' =>
                    'Convert the following Roman Urdu (Urdu written in Latin letters) into natural Urdu '
                    .'(Arabic) script. Keep any English technical terms as-is. Output ONLY the converted '
                    ."text, with no quotes or commentary.\n\n".$text,
                ]],
            ]],
            'generationConfig' => $this->generationConfig(['temperature' => 0.0, 'maxOutputTokens' => 1024]),
        ], maxAttempts: 3, maxWaitMs: 8000);

        $out = trim((string) $resp->json('candidates.0.content.parts.0.text', ''));

        return $out !== '' ? $out : $text;
    }
}
