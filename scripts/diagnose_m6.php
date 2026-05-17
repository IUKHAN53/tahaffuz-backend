<?php
/**
 * Diagnose why Module 6 chunks get filtered out.
 *
 * Run from backend/:  php scripts/diagnose_m6.php
 *
 * Prints:
 *   - raw paragraph & post-split chunk counts per module
 *   - which isUseful() rule rejected each rejected chunk
 *   - a small sample of rejected chunks per module
 *
 * Output is sent to STDOUT only. No DB writes.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\Rag\Chunker;
use App\Services\Rag\TextExtractor;

$extractor = app(TextExtractor::class);
$chunker = new Chunker();

function classify(string $text): string {
    $t = trim($text);
    $len = mb_strlen($t);
    if ($len < 80) return 'len<80';
    $letters = preg_match_all('/\p{L}/u', $t);
    if ($letters < 40) return 'letters<40';
    if ($letters / $len < 0.35) return 'ratio<0.35';
    if (! preg_match('/\p{L}{3,}/u', $t)) return 'no-word';
    return 'KEEP';
}

$docs = Document::orderBy('id')->get();
foreach ($docs as $doc) {
    $path = $doc->source_path;
    if (! is_file($path)) {
        fwrite(STDERR, "[skip] {$doc->title}: missing {$path}\n");
        continue;
    }

    $text = $extractor->extract($path, $doc->mime_type);

    // Replicate Chunker::chunk() up to (but not through) isUseful, so we can classify.
    $size = (int) config('rag.chunking.chars', 900);
    $overlap = (int) config('rag.chunking.overlap', 120);
    $paragraphs = preg_split("/\n{2,}/u", trim($text)) ?: [];

    $chunks = [];
    $buffer = '';
    $flush = function () use (&$buffer, &$chunks) {
        $b = trim($buffer);
        if ($b !== '') $chunks[] = $b;
        $buffer = '';
    };
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') continue;
        if (mb_strlen($buffer) + mb_strlen($para) + 2 <= $size) {
            $buffer .= ($buffer === '' ? '' : "\n\n") . $para;
            continue;
        }
        $flush();
        if (mb_strlen($para) <= $size) {
            $buffer = $para;
            continue;
        }
        $start = 0; $len = mb_strlen($para);
        while ($start < $len) {
            $piece = mb_substr($para, $start, $size);
            $chunks[] = trim($piece);
            if ($start + $size >= $len) break;
            $start += max(1, $size - $overlap);
        }
    }
    $flush();

    $tally = ['KEEP'=>0,'len<80'=>0,'letters<40'=>0,'ratio<0.35'=>0,'no-word'=>0];
    $samples = ['len<80'=>[], 'letters<40'=>[], 'ratio<0.35'=>[], 'no-word'=>[]];
    foreach ($chunks as $c) {
        $cls = classify($c);
        $tally[$cls]++;
        if ($cls !== 'KEEP' && count($samples[$cls]) < 3) {
            $samples[$cls][] = mb_substr($c, 0, 220);
        }
    }

    echo str_repeat('=', 70) . "\n";
    echo "DOC #{$doc->id}: {$doc->title}\n";
    echo "  extracted chars: " . mb_strlen($text) . "\n";
    echo "  paragraphs (after blank-split): " . count($paragraphs) . "\n";
    echo "  raw chunks (pre-filter): " . count($chunks) . "\n";
    foreach ($tally as $k => $v) echo "    {$k}: {$v}\n";
    foreach ($samples as $rule => $exs) {
        if (empty($exs)) continue;
        echo "  -- rejected by [{$rule}] samples --\n";
        foreach ($exs as $i => $ex) {
            echo "    [{$i}] " . str_replace(["\n","\r"], ' / ', $ex) . "\n";
        }
    }
}
