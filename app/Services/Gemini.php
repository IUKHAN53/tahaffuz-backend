<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

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
        $resp = Http::timeout($this->timeout)
            ->retry(2, 500, throw: false)
            ->post($url, [
                'content' => ['parts' => [['text' => $text]]],
                'taskType' => $taskType,
                'outputDimensionality' => (int) config('rag.gemini.embed_dim', 768),
            ]);

        if ($resp->failed()) {
            throw new RuntimeException("Gemini embed failed: HTTP {$resp->status()} {$resp->body()}");
        }

        $vec = $resp->json('embedding.values');
        if (! is_array($vec) || count($vec) === 0) {
            throw new RuntimeException('Gemini embed returned no values.');
        }
        return array_map('floatval', $vec);
    }

    /**
     * Embed many strings sequentially. Gemini's batchEmbedContents is supported but this
     * loop keeps memory steady and lets us retry per-chunk on failure.
     */
    public function embedMany(array $texts, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        $out = [];
        foreach ($texts as $t) {
            $out[] = $this->embed($t, $taskType);
        }
        return $out;
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
