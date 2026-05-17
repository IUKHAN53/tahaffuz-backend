<?php
/** Probe gemini-2.5-flash-lite on Module 6 pages 21-40 (one of yesterday's failed batches). */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$path = realpath(__DIR__ . '/../../docs/Module 6- Monitoring & Servelience.pdf');
$b64 = base64_encode(file_get_contents($path));

$cfg = config('rag.gemini');
$apiKey = $cfg['api_key'];
$model = $cfg['extract_model'];
$base = $cfg['base_url'];
$url = "{$base}/models/{$model}:generateContent?key={$apiKey}";
echo "Model: {$model}\n";

[$from, $to] = [21, 40];
$prompt = "Extract ALL text from THIS DOCUMENT, but ONLY pages {$from} through {$to} (inclusive). "
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
echo "HTTP " . $resp->status() . "\n";
$json = $resp->json();
echo "finish: " . ($json['candidates'][0]['finishReason'] ?? '?') . "\n";
echo "usage: " . json_encode($json['usageMetadata'] ?? []) . "\n";
$text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
echo "chars: " . mb_strlen($text) . "\n\n";
echo "head:\n" . mb_substr($text, 0, 600) . "\n\n";
echo "tail:\n" . mb_substr($text, -600) . "\n";
