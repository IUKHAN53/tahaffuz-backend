<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class Pinecone
{
    protected string $apiKey;
    protected string $host;
    protected string $index;

    public function __construct(
        ?string $apiKey = null,
        ?string $host = null,
        ?string $index = null
    ) {
        $this->apiKey = $apiKey ?? config('services.pinecone.api_key') ?? '';
        $this->host = $host ?? config('services.pinecone.host') ?? '';
        $this->index = $index ?? config('services.pinecone.index') ?? 'tahaffuz';

        if (empty($this->apiKey)) {
            throw new RuntimeException('PINECONE_API_KEY is not configured.');
        }
        if (empty($this->host)) {
            throw new RuntimeException('PINECONE_HOST is not configured.');
        }
    }

    /**
     * Upsert vectors into Pinecone.
     *
     * @param array $vectors Array of ['id' => string, 'values' => float[], 'metadata' => array]
     */
    public function upsert(array $vectors, string $namespace = ''): array
    {
        $payload = ['vectors' => $vectors];
        if ($namespace !== '') {
            $payload['namespace'] = $namespace;
        }

        $response = Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->host}/vectors/upsert", $payload);

        if (! $response->successful()) {
            Log::error('Pinecone upsert failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Pinecone upsert failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Query vectors by similarity.
     *
     * @param array $vector Query vector (float[])
     * @param int $topK Number of results
     * @param array $filter Optional metadata filter
     */
    public function query(
        array $vector,
        int $topK = 6,
        string $namespace = '',
        array $filter = [],
        bool $includeMetadata = true,
        bool $includeValues = false
    ): array {
        $payload = [
            'vector' => $vector,
            'topK' => $topK,
            'includeMetadata' => $includeMetadata,
            'includeValues' => $includeValues,
        ];

        if ($namespace !== '') {
            $payload['namespace'] = $namespace;
        }
        if (! empty($filter)) {
            $payload['filter'] = $filter;
        }

        $response = Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->host}/query", $payload);

        if (! $response->successful()) {
            Log::error('Pinecone query failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Pinecone query failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Delete vectors by IDs.
     */
    public function delete(array $ids, string $namespace = ''): array
    {
        $payload = ['ids' => $ids];
        if ($namespace !== '') {
            $payload['namespace'] = $namespace;
        }

        $response = Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->host}/vectors/delete", $payload);

        if (! $response->successful()) {
            Log::error('Pinecone delete failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Pinecone delete failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Delete all vectors in a namespace.
     */
    public function deleteAll(string $namespace = ''): array
    {
        $payload = ['deleteAll' => true];
        if ($namespace !== '') {
            $payload['namespace'] = $namespace;
        }

        $response = Http::withHeaders([
            'Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->host}/vectors/delete", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Pinecone deleteAll failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get index statistics.
     */
    public function describeIndexStats(): array
    {
        $response = Http::withHeaders([
            'Api-Key' => $this->apiKey,
        ])->timeout(30)->post("{$this->host}/describe_index_stats", []);

        if (! $response->successful()) {
            throw new RuntimeException('Pinecone describeIndexStats failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Check if Pinecone is properly configured and reachable.
     */
    public function isAvailable(): bool
    {
        try {
            $this->describeIndexStats();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
