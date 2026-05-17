<?php

namespace App\Services\Rag;

use App\Models\Chunk;
use Illuminate\Support\Facades\DB;

class VectorStore
{
    /**
     * Pure cosine top-k.
     *
     * @param  array<int, float>  $queryVec
     * @return array<int, array{chunk: Chunk, score: float}>
     */
    public function search(int $knowledgeBaseId, array $queryVec, int $topK = 6, float $minScore = 0.0): array
    {
        $qNorm = self::norm($queryVec);
        if ($qNorm === 0.0) {
            return [];
        }

        $hits = [];

        Chunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding')
            ->select(['id', 'document_id', 'knowledge_base_id', 'ordinal', 'content', 'embedding', 'embedding_norm'])
            ->chunkById(500, function ($rows) use ($queryVec, $qNorm, $topK, $minScore, &$hits) {
                foreach ($rows as $chunk) {
                    $vec = $chunk->embedding;
                    if (! is_array($vec) || count($vec) === 0) {
                        continue;
                    }
                    $cNorm = $chunk->embedding_norm ?: self::norm($vec);
                    if ($cNorm === 0.0) {
                        continue;
                    }
                    $score = self::dot($queryVec, $vec) / ($qNorm * $cNorm);
                    if ($score < $minScore) {
                        continue;
                    }
                    $hits[] = ['chunk' => $chunk, 'score' => $score];
                }
            });

        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($hits, 0, $topK);
    }

    /**
     * BM25 keyword search via SQLite FTS5.
     *
     * @return array<int, array{chunk_id: int, score: float}>
     */
    public function keywordSearch(int $knowledgeBaseId, string $query, int $topK = 12): array
    {
        $tokens = $this->fts5Tokens($query);
        if ($tokens === '') {
            return [];
        }

        try {
            $rows = DB::select(
                "SELECT chunks.id as chunk_id, bm25(chunks_fts) as score
                 FROM chunks_fts
                 JOIN chunks ON chunks.id = chunks_fts.rowid
                 WHERE chunks_fts MATCH ? AND chunks.knowledge_base_id = ?
                 ORDER BY score
                 LIMIT ?",
                [$tokens, $knowledgeBaseId, $topK],
            );
        } catch (\Throwable $e) {
            // Bad FTS5 syntax (e.g. user input with reserved chars) — degrade gracefully.
            return [];
        }

        // bm25 returns a NEGATIVE distance-like score (lower = more relevant).
        // Flip to a positive 0..1 by min-max within the result set.
        if (empty($rows)) {
            return [];
        }
        $raw = array_map(fn ($r) => -1.0 * (float) $r->score, $rows);
        $max = max($raw);
        $min = min($raw);
        $range = max(1e-9, $max - $min);

        $out = [];
        foreach ($rows as $i => $r) {
            $norm = ($raw[$i] - $min) / $range;
            $out[] = ['chunk_id' => (int) $r->chunk_id, 'score' => $norm];
        }
        return $out;
    }

    /**
     * Hybrid retrieval: cosine + BM25 fused via Reciprocal Rank Fusion (RRF).
     *
     * @param  array<int, float>  $queryVec
     * @return array<int, array{chunk: Chunk, score: float, vec_score: float, kw_score: float}>
     */
    public function hybridSearch(
        int $knowledgeBaseId,
        array $queryVec,
        string $queryText,
        int $topK = 6,
        int $candidatePool = 20,
        float $vecWeight = 0.6,
        float $kwWeight = 0.4,
        int $rrfK = 60,
    ): array {
        $vecHits = $this->search($knowledgeBaseId, $queryVec, $candidatePool, 0.0);
        $kwHits = $this->keywordSearch($knowledgeBaseId, $queryText, $candidatePool);

        // Build rank maps (1-indexed).
        $vecRank = [];
        $vecScore = [];
        foreach ($vecHits as $i => $h) {
            $vecRank[$h['chunk']->id] = $i + 1;
            $vecScore[$h['chunk']->id] = $h['score'];
        }
        $kwRank = [];
        $kwScore = [];
        foreach ($kwHits as $i => $h) {
            $kwRank[$h['chunk_id']] = $i + 1;
            $kwScore[$h['chunk_id']] = $h['score'];
        }

        $allIds = array_unique(array_merge(array_keys($vecRank), array_keys($kwRank)));
        if (empty($allIds)) {
            return [];
        }

        $fused = [];
        foreach ($allIds as $id) {
            $vScore = isset($vecRank[$id]) ? 1.0 / ($rrfK + $vecRank[$id]) : 0.0;
            $kScore = isset($kwRank[$id]) ? 1.0 / ($rrfK + $kwRank[$id]) : 0.0;
            $fused[$id] = [
                'rrf' => $vecWeight * $vScore + $kwWeight * $kScore,
                'vec_score' => $vecScore[$id] ?? 0.0,
                'kw_score' => $kwScore[$id] ?? 0.0,
            ];
        }

        uasort($fused, fn ($a, $b) => $b['rrf'] <=> $a['rrf']);
        $topIds = array_slice(array_keys($fused), 0, $topK);

        $chunks = Chunk::with('document:id,title')->whereIn('id', $topIds)->get()->keyBy('id');

        $out = [];
        foreach ($topIds as $id) {
            if (! isset($chunks[$id])) {
                continue;
            }
            $out[] = [
                'chunk' => $chunks[$id],
                'score' => $fused[$id]['rrf'],
                'vec_score' => $fused[$id]['vec_score'],
                'kw_score' => $fused[$id]['kw_score'],
            ];
        }
        return $out;
    }

    /**
     * Convert free text into FTS5-safe MATCH query: each word as OR'd phrase.
     */
    protected function fts5Tokens(string $query): string
    {
        // Strip punctuation that breaks FTS5 syntax; keep letters/digits across scripts.
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?? '';
        $words = preg_split('/\s+/u', trim($cleaned)) ?: [];
        $words = array_values(array_filter($words, fn ($w) => mb_strlen($w) >= 2));
        if (empty($words)) {
            return '';
        }
        // Quote each word to avoid keyword collisions, OR them together.
        $quoted = array_map(fn ($w) => '"'.str_replace('"', '', $w).'"', $words);
        return implode(' OR ', $quoted);
    }

    public static function norm(array $vec): float
    {
        $sum = 0.0;
        foreach ($vec as $v) {
            $sum += $v * $v;
        }
        return sqrt($sum);
    }

    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }
}
