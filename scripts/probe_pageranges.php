<?php
/**
 * Probe Gemini's ability to follow page-range instructions on Module 6.
 * Asks for 3 disjoint page ranges and prints how many chars each returned.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$path = realpath(__DIR__ . '/../../docs/Module 6- Monitoring & Servelience.pdf');
$bytes = file_get_contents($path);
$b64 = base64_encode($bytes);

$cfg = config('rag.gemini');
$apiKey = $cfg['api_key'];
$model = $cfg['chat_model'];
$base = $cfg['base_url'];
$url = "{$base}/models/{$model}:generateContent?key={$apiKey}";

$ranges = [[1, 15], [16, 35], [36, 65]];
foreach ($ranges as [$a, $b]) {
    $prompt = "Extract ALL text from THIS DOCUMENT, but ONLY pages {$a} through {$b} (inclusive). "
            . "Skip every page outside that range entirely. "
            . "Preserve the original language and script (Urdu, Arabic, English, etc). "
            . "Do NOT translate. Do NOT summarize. Output plain text only — no markdown, no commentary. "
            . "Separate pages with two newlines.";
    $resp = Http::timeout(300)->post($url, [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => $prompt],
                ['inlineData' => ['mimeType' => 'application/pdf', 'data' => $b64]],
            ],
        ]],
        'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 32768],
    ]);
    $json = $resp->json();
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $finish = $json['candidates'][0]['finishReason'] ?? '?';
    $usage = $json['usageMetadata'] ?? [];
    echo "--- pages {$a}-{$b} ---\n";
    echo "HTTP {$resp->status()} finish={$finish} cand_tokens=" . ($usage['candidatesTokenCount'] ?? '?')
       . " thoughts=" . ($usage['thoughtsTokenCount'] ?? '?')
       . " chars=" . mb_strlen($text) . "\n";
    echo "head: " . str_replace(["\n","\r"], ' / ', mb_substr($text, 0, 240)) . "\n";
    echo "tail: " . str_replace(["\n","\r"], ' / ', mb_substr($text, -240)) . "\n\n";
}
