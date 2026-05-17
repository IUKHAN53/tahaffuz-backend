<?php
/** Test paged extraction on Module 6 only — print sizes per batch & total. */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Rag\TextExtractor;
use App\Services\Rag\Chunker;

$path = realpath(__DIR__ . '/../../docs/Module 6- Monitoring & Servelience.pdf');
$t0 = microtime(true);
$text = app(TextExtractor::class)->extract($path, 'application/pdf');
$dt = round(microtime(true) - $t0, 1);

echo "Extracted chars: " . mb_strlen($text) . " in {$dt}s\n";
echo "First 400 chars: " . mb_substr($text, 0, 400) . "\n";
echo "---\nLast 400 chars: " . mb_substr($text, -400) . "\n";

$chunks = (new Chunker)->chunk($text, 900, 120);
echo "---\nChunks: " . count($chunks) . "\n";
