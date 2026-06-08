<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class Whisper
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.openai.api_key') ?? '';

        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }
    }

    /**
     * Transcribe audio file to text using Whisper API.
     *
     * Supports: mp3, mp4, mpeg, mpga, m4a, wav, webm
     * Max file size: 25 MB
     *
     * @param string $filePath Path to the audio file
     * @param string|null $language ISO-639-1 language code (e.g., 'ur', 'ps', 'sd', 'en')
     *                              null = auto-detect
     * @return array{text: string, language: string, duration: float}
     */
    public function transcribe(
        string $filePath,
        ?string $language = null,
        string $model = 'whisper-1'
    ): array {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Audio file not found: {$filePath}");
        }

        $data = [
            'model' => $model,
            'response_format' => 'verbose_json',
        ];

        // Language codes for our target languages:
        // - Urdu: 'ur'
        // - Pashto: 'ps'
        // - Sindhi: 'sd'
        // - English: 'en'
        if ($language !== null) {
            // Map our internal language codes to ISO-639-1
            $langMap = [
                'ur' => 'ur',
                'urd' => 'ur',
                'rud' => 'ur', // Roman Urdu -> Urdu
                'ps' => 'ps',
                'pashto' => 'ps',
                'sd' => 'sd',
                'sindhi' => 'sd',
                'en' => 'en',
                'english' => 'en',
            ];
            $data['language'] = $langMap[strtolower($language)] ?? $language;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/audio/transcriptions", $data);

        if (! $response->successful()) {
            Log::error('Whisper transcription failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Whisper transcription failed: ' . $response->body());
        }

        $result = $response->json();

        return [
            'text' => $result['text'] ?? '',
            'language' => $result['language'] ?? $language ?? 'unknown',
            'duration' => $result['duration'] ?? 0.0,
        ];
    }

    /**
     * Translate audio to English text.
     *
     * @param string $filePath Path to the audio file
     * @return array{text: string, duration: float}
     */
    public function translate(string $filePath, string $model = 'whisper-1'): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Audio file not found: {$filePath}");
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
        ])
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/audio/translations", [
                'model' => $model,
                'response_format' => 'verbose_json',
            ]);

        if (! $response->successful()) {
            Log::error('Whisper translation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Whisper translation failed: ' . $response->body());
        }

        $result = $response->json();

        return [
            'text' => $result['text'] ?? '',
            'duration' => $result['duration'] ?? 0.0,
        ];
    }

    /**
     * Check if Whisper API is available.
     */
    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }
}
