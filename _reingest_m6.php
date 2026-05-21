<?php
// One-off: re-ingest Module 6 (document id 4) into the RAG store.
// Run detached on the VPS; safe to delete afterwards.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Document;
use App\Services\Rag\Ingestor;

$id = (int) ($argv[1] ?? 4);
$doc = Document::find($id);
if (! $doc) {
    fwrite(STDERR, "Document {$id} not found\n");
    exit(1);
}

echo '['.date('H:i:s')."] re-ingesting #{$id}: {$doc->title}\n";
flush();

$t0 = microtime(true);
try {
    app(Ingestor::class)->ingest($doc);
    $fresh = $doc->fresh();
    echo '['.date('H:i:s')."] DONE status={$fresh->status} chunks={$fresh->chunk_count} "
        .'elapsed='.round(microtime(true) - $t0).'s'."\n";
} catch (\Throwable $e) {
    echo '['.date('H:i:s')."] FAILED: ".$e->getMessage()."\n";
    exit(1);
}
