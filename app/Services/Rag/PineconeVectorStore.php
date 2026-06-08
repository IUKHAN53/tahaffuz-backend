<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use App\Services\Pinecone;
use Illuminate\Support\Facades\Log;

class PineconeVectorStore
{
    public function __construct(protected Pinecone $pinecone) {}

    /**
     * Search for similar chunks using Pinecone.
     *
     * @param int $knowledgeBaseId
     * @param array<int, float> $queryVec
     * @param int $topK
     * @param float $minScore
     * @return array<int, array{chunk: Chunk, score: float}>
     */
    public function search(int $knowledgeBaseId, array $queryVec, int $topK = 6, float $minScore = 0.0): array
    {
        $namespace = "kb_{$knowledgeBaseId}";

        try {
            $result = $this->pinecone->query(
                vector: $queryVec,
                topK: $topK,
                namespace: $namespace,
                filter: [],
                includeMetadata: true
            );
        } catch (\Throwable $e) {
            Log::error('Pinecone search failed', ['error' => $e->getMessage()]);
            return [];
        }

        $matches = $result['matches'] ?? [];
        if (empty($matches)) {
            return [];
        }

        // Filter by minimum score and collect chunk IDs
        $chunkIds = [];
        $scoreMap = [];
        foreach ($matches as $match) {
            $score = $match['score'] ?? 0;
            if ($score < $minScore) {
                continue;
            }
            $chunkId = (int) ($match['metadata']['chunk_id'] ?? $match['id'] ?? 0);
            if ($chunkId > 0) {
                $chunkIds[] = $chunkId;
                $scoreMap[$chunkId] = $score;
            }
        }

        if (empty($chunkIds)) {
            return [];
        }

        // Fetch actual chunk models
        $chunks = Chunk::with('document:id,title')
            ->whereIn('id', $chunkIds)
            ->get()
            ->keyBy('id');

        // Build result array maintaining Pinecone's ranking
        $hits = [];
        foreach ($chunkIds as $id) {
            if (isset($chunks[$id])) {
                $hits[] = [
                    'chunk' => $chunks[$id],
                    'score' => $scoreMap[$id],
                ];
            }
        }

        return $hits;
    }

    /**
     * Index a chunk into Pinecone.
     *
     * @param Chunk $chunk
     * @param array<int, float> $embedding
     */
    public function index(Chunk $chunk, array $embedding): void
    {
        $namespace = "kb_{$chunk->knowledge_base_id}";

        $vector = [
            'id' => "chunk_{$chunk->id}",
            'values' => $embedding,
            'metadata' => [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'knowledge_base_id' => $chunk->knowledge_base_id,
                'ordinal' => $chunk->ordinal,
                'content_preview' => mb_substr($chunk->content, 0, 200),
            ],
        ];

        $this->pinecone->upsert([$vector], $namespace);
    }

    /**
     * Index multiple chunks at once.
     *
     * @param array<Chunk> $chunks
     * @param array<int, array<int, float>> $embeddings Keyed by chunk ID
     */
    public function indexMany(array $chunks, array $embeddings): void
    {
        if (empty($chunks)) {
            return;
        }

        // Group by knowledge base for namespacing
        $byKb = [];
        foreach ($chunks as $chunk) {
            $kbId = $chunk->knowledge_base_id;
            if (! isset($byKb[$kbId])) {
                $byKb[$kbId] = [];
            }
            if (isset($embeddings[$chunk->id])) {
                $byKb[$kbId][] = [
                    'id' => "chunk_{$chunk->id}",
                    'values' => $embeddings[$chunk->id],
                    'metadata' => [
                        'chunk_id' => $chunk->id,
                        'document_id' => $chunk->document_id,
                        'knowledge_base_id' => $chunk->knowledge_base_id,
                        'ordinal' => $chunk->ordinal,
                        'content_preview' => mb_substr($chunk->content, 0, 200),
                    ],
                ];
            }
        }

        // Upsert per namespace
        foreach ($byKb as $kbId => $vectors) {
            if (! empty($vectors)) {
                // Pinecone allows max 100 vectors per upsert
                foreach (array_chunk($vectors, 100) as $batch) {
                    $this->pinecone->upsert($batch, "kb_{$kbId}");
                }
            }
        }
    }

    /**
     * Delete a chunk from Pinecone.
     */
    public function delete(Chunk $chunk): void
    {
        $namespace = "kb_{$chunk->knowledge_base_id}";
        $this->pinecone->delete(["chunk_{$chunk->id}"], $namespace);
    }

    /**
     * Delete all chunks for a knowledge base.
     */
    public function deleteKnowledgeBase(int $knowledgeBaseId): void
    {
        $this->pinecone->deleteAll("kb_{$knowledgeBaseId}");
    }

    /**
     * Check if Pinecone is available.
     */
    public function isAvailable(): bool
    {
        return $this->pinecone->isAvailable();
    }

    /**
     * Get index statistics.
     */
    public function getStats(): array
    {
        return $this->pinecone->describeIndexStats();
    }
}
