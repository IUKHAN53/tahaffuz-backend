<?php
/**
 * Probe what each PDF extraction path returns for Module 6.
 *   1. smalot/pdfparser (current fast-path)
 *   2. Page count via smalot
 *   3. Gemini direct call — log raw response (truncation reason, candidate, etc.)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Smalot\PdfParser\Parser as PdfParser;
use Illuminate\Support\Facades\Http;

$path = realpath(__DIR__ . '/../../docs/Module 6- Monitoring & Servelience.pdf');
echo "Path: {$path}\n";
echo "Size: " . filesize($path) . " bytes\n";

echo "\n--- smalot/pdfparser ---\n";
try {
    $parser = new PdfParser;
    $doc = $parser->parseFile($path);
    $pages = $doc->getPages();
    echo "Pages: " . count($pages) . "\n";
    $text = $doc->getText();
    echo "Total chars: " . mb_strlen($text) . "\n";
    $letters = preg_match_all('/\p{L}{3,}/u', $text);
    echo "3+letter words: {$letters}\n";
    echo "First 600 chars:\n" . mb_substr($text, 0, 600) . "\n";
    echo "Last 400 chars:\n" . mb_substr($text, -400) . "\n";
} catch (\Throwable $e) {
    echo "smalot failed: " . $e->getMessage() . "\n";
}

echo "\n--- Gemini direct (raw response) ---\n";
$cfg = config('rag.gemini');
$apiKey = $cfg['api_key'];
$model = $cfg['chat_model'];
$base = $cfg['base_url'];
echo "model: {$model}\n";
$bytes = file_get_contents($path);
$url = "{$base}/models/{$model}:generateContent?key={$apiKey}";
$resp = Http::timeout(300)->post($url, [
    'contents' => [[
        'role' => 'user',
        'parts' => [
            ['text' => "Extract ALL text from this document, page by page, preserving the original language and script (Urdu, Arabic, English, etc). Do NOT translate. Do NOT summarize. Output plain text only — no markdown, no commentary. Separate pages with two newlines."],
            ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode($bytes)]],
        ],
    ]],
    'generationConfig' => [
        'temperature' => 0.0,
        'maxOutputTokens' => 32768,
    ],
]);
echo "HTTP " . $resp->status() . "\n";
$json = $resp->json();
// dump useful metadata
echo "finishReason: " . ($json['candidates'][0]['finishReason'] ?? '?') . "\n";
echo "usageMetadata: " . json_encode($json['usageMetadata'] ?? []) . "\n";
echo "promptFeedback: " . json_encode($json['promptFeedback'] ?? []) . "\n";
$text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
echo "text chars: " . mb_strlen($text) . "\n";
echo "First 600 chars:\n" . mb_substr($text, 0, 600) . "\n";
echo "...\nLast 600 chars:\n" . mb_substr($text, -600) . "\n";
