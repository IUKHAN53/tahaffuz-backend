<?php
// One-off: export Module 6 (document id 4) chunks — content + embeddings —
// to a JSON file so they can be imported into another DB without re-embedding.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Chunk;

$id = (int) ($argv[1] ?? 4);
$chunks = Chunk::where('document_id', $id)
    ->orderBy('ordinal')
    ->get(['ordinal', 'content', 'token_estimate', 'embedding', 'embedding_norm']);

if ($chunks->isEmpty()) {
    fwrite(STDERR, "No chunks for document {$id}\n");
    exit(1);
}

$out = $chunks->map(fn ($c) => [
    'ordinal' => $c->ordinal,
    'content' => $c->content,
    'token_estimate' => $c->token_estimate,
    'embedding' => $c->embedding,
    'embedding_norm' => $c->embedding_norm,
])->all();

$path = __DIR__.'/module6_chunks.json';
file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE));
echo count($out).' chunks exported to '.$path.' ('.number_format(filesize($path))." bytes)\n";
