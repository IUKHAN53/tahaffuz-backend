<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gemini;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TtsController extends Controller
{
    public function __construct(protected Gemini $gemini) {}

    /**
     * GET /api/tts?text=…&lang=en|ur|rud&voice=…
     *
     * Returns synthesized speech as audio/wav. The app points an audio player
     * straight at this URL, so it must be a GET. Results are cached on disk by
     * the request so repeated phrases never re-hit the (rate-limited) TTS model.
     *
     * Language handling:
     *  - Urdu-script text  → synthesized as-is (Urdu voice).
     *  - lang=rud (Roman)  → transliterated to Urdu script first, else the voice
     *                        pronounces the Latin letters as English.
     *  - English / other   → synthesized as-is.
     */
    public function speak(Request $request): Response
    {
        $text = trim((string) $request->query('text', ''));
        $lang = (string) $request->query('lang', '');
        $voice = (string) $request->query('voice', '') ?: (string) config('rag.gemini.tts_voice', 'Kore');

        if ($text === '') {
            return response()->json(['error' => 'text is required'], 422);
        }
        // Guard the free-tier quota and keep latency sane — answers are short.
        $text = mb_substr($text, 0, 2000);

        $model = (string) config('rag.gemini.tts_model', 'gemini-2.5-flash-preview-tts');
        $sampleRate = (int) config('rag.gemini.tts_sample_rate', 24000);

        // Cache key is built from the *original* request so a cache hit skips
        // both transliteration and synthesis entirely.
        $key = sha1(implode('|', [$model, $voice, $lang, $text]));
        $path = "tts-cache/{$key}.wav";
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            try {
                $speechText = $this->speechText($text, $lang);
                $pcm = $this->gemini->synthesizeSpeech($speechText, $voice);
                $wav = Gemini::pcmToWav($pcm, $sampleRate);
                $disk->put($path, $wav);
            } catch (Throwable $e) {
                Log::warning('TTS synthesis failed', ['error' => $e->getMessage()]);

                // The app falls back to its on-device voice when this fails.
                return response()->json(['error' => 'tts_unavailable'], 502);
            }
        }

        $wav = $disk->get($path);

        return response($wav, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Length' => (string) strlen((string) $wav),
            'Cache-Control' => 'public, max-age=604800',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Decide what text to actually feed the voice. Urdu script wins regardless
     * of the hint; Roman Urdu is transliterated to Urdu script; everything else
     * is spoken verbatim.
     */
    protected function speechText(string $text, string $lang): string
    {
        if (preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        if ($lang === 'rud') {
            return $this->gemini->transliterateToUrduScript($text);
        }

        return $text;
    }
}
