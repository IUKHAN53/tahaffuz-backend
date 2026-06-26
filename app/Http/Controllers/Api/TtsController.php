<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EdgeTts;
use App\Services\ElevenLabs;
use App\Services\Gemini;
use App\Services\OpenAiTts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TtsController extends Controller
{
    protected ?ElevenLabs $elevenLabs = null;

    protected ?EdgeTts $edgeTts = null;

    protected ?OpenAiTts $openAiTts = null;

    public function __construct(protected Gemini $gemini)
    {
        // Primary TTS: Microsoft Edge neural voices (free, no quota, has Pashto).
        if (config('rag.edge_tts.enabled', true)) {
            try {
                $svc = app(EdgeTts::class);
                if ($svc->isAvailable()) {
                    $this->edgeTts = $svc;
                }
            } catch (Throwable $e) {
                // edge-tts unavailable — fall through to Gemini/ElevenLabs.
            }
        }

        // OpenAI TTS: the only Sindhi voice, and a reliable cross-language
        // fallback when Edge fails.
        if (config('rag.openai_tts.enabled', true)) {
            try {
                $svc = app(OpenAiTts::class);
                if ($svc->isAvailable()) {
                    $this->openAiTts = $svc;
                }
            } catch (Throwable $e) {
                // OpenAI unavailable — Sindhi will 502 to on-device.
            }
        }

        // Initialize ElevenLabs if configured
        if (config('rag.providers.tts') === 'elevenlabs' && config('services.elevenlabs.api_key')) {
            try {
                $this->elevenLabs = app(ElevenLabs::class);
            } catch (Throwable $e) {
                // ElevenLabs not available, fall back to Gemini
            }
        }
    }

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
        // Keep latency sane — answers are short.
        $text = mb_substr($text, 0, 2000);

        // Resolve the language from the hint and the text's own script.
        $lang = $this->resolveLang($text, $lang);

        // Sindhi: OpenAI is the only model that voices it. No Edge/Gemini path.
        if ($lang === 'sd') {
            $oa = $this->speakWithOpenAi($text, $lang);

            // Nothing else can do Sindhi — 502 lets the app use its on-device voice.
            return $oa ?? response()->json([
                'error' => 'tts_unavailable',
                'reason' => 'language_unsupported',
            ], 502);
        }

        // Primary for en/ur/rud/ps: free Microsoft Edge neural voices.
        if ($this->edgeTts !== null) {
            $edge = $this->speakWithEdge($text, $lang);
            if ($edge !== null) {
                return $edge;
            }
        }

        // Fallback: OpenAI (reliable, all these languages).
        $oa = $this->speakWithOpenAi($text, $lang);
        if ($oa !== null) {
            return $oa;
        }

        // Pashto has no remaining server option — on-device.
        if ($lang === 'ps') {
            return response()->json([
                'error' => 'tts_unavailable',
                'reason' => 'edge_failed',
            ], 502);
        }

        // Last resort for en/ur/rud: ElevenLabs (if configured) else Gemini.
        if ($this->elevenLabs !== null) {
            return $this->speakWithElevenLabs($text, $lang);
        }

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
     * Synthesize speech using ElevenLabs for beautiful multilingual voices.
     */
    protected function speakWithElevenLabs(string $text, string $lang): Response
    {
        $voiceId = config('services.elevenlabs.voice_id');
        $disk = Storage::disk('local');

        // Cache key for ElevenLabs
        $key = sha1(implode('|', ['elevenlabs', $voiceId, $lang, $text]));
        $path = "tts-cache/{$key}.mp3";

        if (! $disk->exists($path)) {
            try {
                $speechText = $this->speechText($text, $lang);
                $mp3 = $this->elevenLabs->synthesize($speechText, $voiceId);
                $disk->put($path, $mp3);
            } catch (Throwable $e) {
                Log::warning('ElevenLabs TTS failed, falling back to Gemini', ['error' => $e->getMessage()]);

                // Fall back to Gemini TTS
                return $this->speakWithGemini($text, $lang);
            }
        }

        $mp3 = $disk->get($path);

        return response($mp3, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen((string) $mp3),
            'Cache-Control' => 'public, max-age=604800',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Fallback to Gemini TTS.
     */
    protected function speakWithGemini(string $text, string $lang): Response
    {
        $voice = (string) config('rag.gemini.tts_voice', 'Kore');
        $model = (string) config('rag.gemini.tts_model', 'gemini-2.5-flash-preview-tts');
        $sampleRate = (int) config('rag.gemini.tts_sample_rate', 24000);
        $disk = Storage::disk('local');

        $key = sha1(implode('|', [$model, $voice, $lang, $text]));
        $path = "tts-cache/{$key}.wav";

        if (! $disk->exists($path)) {
            try {
                $speechText = $this->speechText($text, $lang);
                $pcm = $this->gemini->synthesizeSpeech($speechText, $voice);
                $wav = Gemini::pcmToWav($pcm, $sampleRate);
                $disk->put($path, $wav);
            } catch (Throwable $e) {
                Log::warning('Gemini TTS fallback also failed', ['error' => $e->getMessage()]);
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
     * Synthesize with a Microsoft Edge neural voice. Returns null on any
     * failure so the caller can fall back. Cached on disk per (voice, text).
     */
    protected function speakWithEdge(string $text, string $lang): ?Response
    {
        $voice = (string) config("rag.edge_tts.voices.{$lang}", '');
        if ($voice === '' || $this->edgeTts === null) {
            return null;
        }

        $disk = Storage::disk('local');
        $key = sha1(implode('|', ['edge', $voice, $lang, $text]));
        $path = "tts-cache/{$key}.mp3";

        if (! $disk->exists($path)) {
            try {
                $speechText = $this->speechText($text, $lang);
                $mp3 = $this->edgeTts->synthesize($speechText, $voice);
                $disk->put($path, $mp3);
            } catch (Throwable $e) {
                Log::warning('Edge TTS failed', ['lang' => $lang, 'error' => $e->getMessage()]);

                return null;
            }
        }

        $mp3 = $disk->get($path);

        return response($mp3, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen((string) $mp3),
            'Cache-Control' => 'public, max-age=604800',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Synthesize with OpenAI's multilingual TTS (gpt-4o-mini-tts). The only
     * voice for Sindhi; also a fallback for the other languages. Returns null
     * on failure. Cached on disk per (voice, lang, text).
     */
    protected function speakWithOpenAi(string $text, string $lang): ?Response
    {
        if ($this->openAiTts === null) {
            return null;
        }

        $voice = (string) config('rag.openai_tts.voice', 'alloy');
        $disk = Storage::disk('local');
        $key = sha1(implode('|', ['openai', $voice, $lang, $text]));
        $path = "tts-cache/{$key}.mp3";

        if (! $disk->exists($path)) {
            try {
                $speechText = $this->speechText($text, $lang);
                $mp3 = $this->openAiTts->synthesize($speechText, $voice);
                $disk->put($path, $mp3);
            } catch (Throwable $e) {
                Log::warning('OpenAI TTS failed', ['lang' => $lang, 'error' => $e->getMessage()]);

                return null;
            }
        }

        $mp3 = $disk->get($path);

        return response($mp3, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen((string) $mp3),
            'Cache-Control' => 'public, max-age=604800',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Resolve the effective language from the request hint and the text's own
     * script. Script-unique Pashto/Sindhi letters win; otherwise a valid hint
     * is honored; otherwise Arabic script means Urdu, and the rest is English.
     */
    protected function resolveLang(string $text, string $hint): string
    {
        if (preg_match('/[ټډړږښځڅېګڼ]/u', $text)) {
            return 'ps';
        }
        if (preg_match('/[ڳڻڪھڀٺٽ۾]/u', $text)) {
            return 'sd';
        }

        // Arabic-script text is Urdu/Farsi/Pashto/Sindhi — never English. A
        // contradicting hint (e.g. the app UI is set to English while the answer
        // came back in Urdu) must NOT override the script, or an English voice
        // ends up reading Urdu letters as gibberish. Honor only a same-script hint.
        if (preg_match('/\p{Arabic}/u', $text)) {
            return in_array($hint, ['ur', 'fa', 'ps', 'sd'], true) ? $hint : 'ur';
        }

        // Latin script: honor a valid hint, otherwise English.
        if (in_array($hint, ['en', 'ur', 'fa', 'ps', 'sd'], true)) {
            return $hint;
        }

        return 'en';
    }

    /**
     * Decide what text to actually feed the voice. All supported languages are
     * spoken verbatim in their own script.
     */
    protected function speechText(string $text, string $lang): string
    {
        return $text;
    }
}
