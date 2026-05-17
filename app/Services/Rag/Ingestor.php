<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Models\Document;
use App\Services\Gemini;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Ingestor
{
    public function __construct(
        protected TextExtractor $extractor,
        protected Chunker $chunker,
        protected Gemini $gemini,
    ) {}

    public function ingest(Document $doc): void
    {
        $doc->update([
            'status' => Document::STATUS_PROCESSING,
            'error' => null,
        ]);

        try {
            $absPath = $this->resolvePath($doc->source_path);
            $text = $this->extractor->extract($absPath, $doc->mime_type);

            if (trim($text) === '') {
                throw new \RuntimeException('Extracted text was empty.');
            }

            $size = (int) config('rag.chunking.chars', 900);
            $overlap = (int) config('rag.chunking.overlap', 120);
            $pieces = $this->chunker->chunk($text, $size, $overlap);

            DB::transaction(function () use ($doc, $pieces) {
                Chunk::where('document_id', $doc->id)->delete();

                foreach ($pieces as $i => $piece) {
                    $vec = $this->gemini->embed($piece, 'RETRIEVAL_DOCUMENT');
                    Chunk::create([
                        'knowledge_base_id' => $doc->knowledge_base_id,
                        'document_id' => $doc->id,
                        'ordinal' => $i,
                        'content' => $piece,
                        'token_estimate' => (int) ceil(mb_strlen($piece) / 4),
                        'embedding' => $vec,
                        'embedding_norm' => VectorStore::norm($vec),
                    ]);
                }

                $doc->update([
                    'status' => Document::STATUS_READY,
                    'chunk_count' => count($pieces),
                    'ingested_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            Log::error('Ingestion failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            $doc->update([
                'status' => Document::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function resolvePath(string $sourcePath): string
    {
        if (is_file($sourcePath)) {
            return $sourcePath;
        }
        $disk = Storage::disk('local');
        if ($disk->exists($sourcePath)) {
            return $disk->path($sourcePath);
        }
        return $sourcePath;
    }
}
