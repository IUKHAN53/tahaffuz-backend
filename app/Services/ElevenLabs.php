<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ElevenLabs
{
    protected string $apiKey;
    protected string $voiceId;
    protected string $model;
    protected string $baseUrl = 'https://api.elevenlabs.io/v1';

    // Popular multilingual voices
    public const VOICES = [
        'rachel' => '21m00Tcm4TlvDq8ikWAM',      // Female, calm
        'domi' => 'AZnzlk1XvdvUeBnXmlld',        // Female, strong
        'bella' => 'EXAVITQu4vr4xnSDxMaL',       // Female, soft
        'antoni' => 'ErXwobaYiN019PkySvjV',      // Male, well-rounded
        'elli' => 'MF3mGyEYCl7XYWbV9V6O',        // Female, emotional
        'josh' => 'TxGEqnHWrfWFTfGW9XjX',        // Male, deep
        'arnold' => 'VR6AewLTigWG4xSOukaG',      // Male, crisp
        'adam' => 'pNInz6obpgDQGcFmaJgB',        // Male, deep
        'sam' => 'yoZ06aMxZJJ28mfd3POQ',         // Male, raspy
    ];

    public function __construct(
        ?string $apiKey = null,
        ?string $voiceId = null,
        ?string $model = null
    ) {
        $this->apiKey = $apiKey ?? config('services.elevenlabs.api_key') ?? '';
        $this->voiceId = $voiceId ?? config('services.elevenlabs.voice_id') ?? self::VOICES['rachel'];
        $this->model = $model ?? config('services.elevenlabs.model') ?? 'eleven_multilingual_v2';

        if (empty($this->apiKey)) {
            throw new RuntimeException('ELEVENLABS_API_KEY is not configured.');
        }
    }

    /**
     * Convert text to speech.
     *
     * @param string $text Text to synthesize (max 5000 chars)
     * @param string|null $voiceId Override default voice
     * @return string Raw audio data (MP3)
     */
    public function synthesize(
        string $text,
        ?string $voiceId = null,
        array $voiceSettings = []
    ): string {
        $voice = $voiceId ?? $this->voiceId;
        $text = mb_substr($text, 0, 5000);

        $payload = [
            'text' => $text,
            'model_id' => $this->model,
            'voice_settings' => array_merge([
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
                'use_speaker_boost' => true,
            ], $voiceSettings),
        ];

        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'audio/mpeg',
        ])
            ->timeout(30)
            ->post("{$this->baseUrl}/text-to-speech/{$voice}", $payload);

        if (! $response->successful()) {
            Log::error('ElevenLabs synthesis failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('ElevenLabs synthesis failed: ' . $response->body());
        }

        return $response->body();
    }

    /**
     * Stream text to speech (returns generator for chunked response).
     *
     * @param string $text Text to synthesize
     * @param string|null $voiceId Override default voice
     * @return \Generator<string> Chunks of audio data
     */
    public function synthesizeStream(string $text, ?string $voiceId = null): \Generator
    {
        $voice = $voiceId ?? $this->voiceId;
        $text = mb_substr($text, 0, 5000);

        $payload = [
            'text' => $text,
            'model_id' => $this->model,
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
            ],
        ];

        $ch = curl_init("{$this->baseUrl}/text-to-speech/{$voice}/stream");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'xi-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: audio/mpeg',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buffer) {
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);

        $buffer = '';
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("ElevenLabs stream failed with status {$httpCode}");
        }

        yield $buffer;
    }

    /**
     * Get list of available voices.
     */
    public function getVoices(): array
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->timeout(15)->get("{$this->baseUrl}/voices");

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch ElevenLabs voices: ' . $response->body());
        }

        return $response->json()['voices'] ?? [];
    }

    /**
     * Get subscription/usage info.
     */
    public function getSubscription(): array
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->timeout(15)->get("{$this->baseUrl}/user/subscription");

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch ElevenLabs subscription: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Check if ElevenLabs is properly configured.
     */
    public function isAvailable(): bool
    {
        try {
            $this->getSubscription();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the default voice ID.
     */
    public function getVoiceId(): string
    {
        return $this->voiceId;
    }
}
