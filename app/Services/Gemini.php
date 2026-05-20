<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
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
     */
    protected function postWithRetry(string $url, array $payload, ?int $timeout = null, int $maxAttempts = 6): Response
    {
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
                usleep($backoffMs * 1000);
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

            // On 429, wait exactly as long as Gemini asks (capped); else back off.
            $serverWaitMs = $status === 429 ? $this->retryDelayMs($resp) : 0;
            $waitMs = $serverWaitMs > 0 ? min($serverWaitMs, 65000) : $backoffMs;
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
    public function generate(string $systemPrompt, array $history, string $userMessage, string $context): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['content']]],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [[
                'text' => "CONTEXT:\n{$context}\n\nسوال: {$userMessage}",
            ]],
        ];

        $url = "{$this->baseUrl}/models/{$this->chatModel}:generateContent?key={$this->apiKey}";
        $resp = Http::timeout($this->timeout)
            ->retry(2, 1000, throw: false)
            ->post($url, [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.2,
                    'topP' => 0.9,
                    'maxOutputTokens' => 1024,
                ],
            ]);

        if ($resp->failed()) {
            throw new RuntimeException("Gemini generate failed: HTTP {$resp->status()} {$resp->body()}");
        }

        $text = $resp->json('candidates.0.content.parts.0.text', '');
        return [
            'text' => trim((string) $text),
            'usage' => $resp->json('usageMetadata', []),
        ];
    }

    /**
     * Generate from audio input (m4a/wav/mp3/ogg). Gemini 2.5 Flash handles Urdu audio
     * directly; returns transcript-grounded answer in one call.
     */
    public function generateFromAudio(string $systemPrompt, string $audioPath, string $audioMime, string $context): array
    {
        $bytes = @file_get_contents($audioPath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read audio file: {$audioPath}");
        }
        $b64 = base64_encode($bytes);

        $url = "{$this->baseUrl}/models/{$this->chatModel}:generateContent?key={$this->apiKey}";
        $resp = Http::timeout($this->timeout)
            ->retry(2, 1000, throw: false)
            ->post($url, [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['text' => "CONTEXT:\n{$context}\n\nصارف کا آڈیو سوال نیچے ہے۔ پہلے اسے سمجھیں، پھر صرف CONTEXT کی بنیاد پر جواب دیں۔"],
                        ['inlineData' => ['mimeType' => $audioMime, 'data' => $b64]],
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
            ]);

        if ($resp->failed()) {
            throw new RuntimeException("Gemini audio generate failed: HTTP {$resp->status()} {$resp->body()}");
        }

        $raw = $resp->json('candidates.0.content.parts.0.text', '{}');
        $parsed = json_decode((string) $raw, true) ?: [];
        return [
            'transcript' => trim((string) ($parsed['transcript'] ?? '')),
            'text' => trim((string) ($parsed['answer'] ?? '')),
            'usage' => $resp->json('usageMetadata', []),
        ];
    }
}
