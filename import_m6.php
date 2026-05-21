<?php
// One-off: import Module 6 chunks from module6_chunks.json (exported elsewhere)
// straight into this DB — no Gemini calls, embeddings come from the file.
// Mirrors Ingestor's DB-write transaction so the FTS triggers stay in sync.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\VectorStore;
use Illuminate\Support\Facades\DB;

$id = (int) ($argv[1] ?? 4);
$doc = Document::find($id);
if (! $doc) {
    fwrite(STDERR, "Document {$id} not found\n");
    exit(1);
}

$path = __DIR__.'/module6_chunks.json';
$data = json_decode((string) file_get_contents($path), true);
if (! is_array($data) || empty($data)) {
    fwrite(STDERR, "No chunk data in {$path}\n");
    exit(1);
}

echo '['.date('H:i:s')."] importing ".count($data)." chunks into #{$id}: {$doc->title}\n";

DB::transaction(function () use ($doc, $data) {
    Chunk::where('document_id', $doc->id)->delete();

    foreach ($data as $row) {
        $vec = $row['embedding'] ?? [];
        Chunk::create([
            'knowledge_base_id' => $doc->knowledge_base_id,
            'document_id' => $doc->id,
            'ordinal' => $row['ordinal'],
            'content' => $row['content'],
            'token_estimate' => $row['token_estimate'] ?? (int) ceil(mb_strlen((string) $row['content']) / 4),
            'embedding' => $vec,
            'embedding_norm' => $row['embedding_norm'] ?? VectorStore::norm($vec),
        ]);
    }

    $doc->update([
        'status' => Document::STATUS_READY,
        'chunk_count' => count($data),
        'ingested_at' => now(),
        'error' => null,
    ]);
});

$fresh = $doc->fresh();
echo '['.date('H:i:s')."] DONE status={$fresh->status} chunks=".$fresh->chunks()->count()."\n";
