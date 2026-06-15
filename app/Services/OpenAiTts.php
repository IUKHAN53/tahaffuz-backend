<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Text-to-speech via OpenAI's multilingual model (gpt-4o-mini-tts). Unlike the
 * per-language voice catalogs (Edge/Azure), this is a single model that reads
 * whatever script it's given — so it's the only usable source of a Sindhi
 * voice. Used for Sindhi, and as a reliable fallback for the other languages.
 */
class OpenAiTts
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $model = null,
        protected ?string $voice = null,
        protected int $timeout = 30,
    ) {
        $this->apiKey ??= (string) config('services.openai.api_key', '');
        $this->model ??= (string) config('rag.openai_tts.model', 'gpt-4o-mini-tts');
        $this->voice ??= (string) config('rag.openai_tts.voice', 'alloy');
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Synthesize $text to MP3 bytes.
     */
    public function synthesize(string $text, ?string $voice = null): string
    {
        $resp = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => $this->model,
                'input' => $text,
                'voice' => $voice ?: $this->voice,
                'response_format' => 'mp3',
            ]);

        if (! $resp->successful()) {
            throw new RuntimeException('OpenAI TTS failed: HTTP '.$resp->status().' '.substr($resp->body(), 0, 200));
        }

        $mp3 = $resp->body();
        if (strlen($mp3) < 512) {
            throw new RuntimeException('OpenAI TTS returned no audio.');
        }

        return $mp3;
    }
}
