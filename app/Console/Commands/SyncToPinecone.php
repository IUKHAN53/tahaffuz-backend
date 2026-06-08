<?php

namespace App\Console\Commands;

use App\Models\Chunk;
use App\Models\KnowledgeBase;
use App\Services\Pinecone;
use App\Services\Rag\PineconeVectorStore;
use Illuminate\Console\Command;

class SyncToPinecone extends Command
{
    protected $signature = 'pinecone:sync
                            {--kb= : Knowledge base ID to sync (all if not specified)}
                            {--fresh : Delete all vectors before syncing}';

    protected $description = 'Sync existing chunk embeddings to Pinecone';

    public function handle(): int
    {
        if (! config('services.pinecone.api_key') || ! config('services.pinecone.host')) {
            $this->error('Pinecone is not configured. Set PINECONE_API_KEY and PINECONE_HOST in .env');
            return self::FAILURE;
        }

        try {
            $pinecone = app(Pinecone::class);
            $store = new PineconeVectorStore($pinecone);
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Pinecone: ' . $e->getMessage());
            return self::FAILURE;
        }

        $kbId = $this->option('kb');
        $fresh = $this->option('fresh');

        // Get knowledge bases to sync
        $kbs = $kbId
            ? KnowledgeBase::where('id', $kbId)->get()
            : KnowledgeBase::all();

        if ($kbs->isEmpty()) {
            $this->warn('No knowledge bases found.');
            return self::SUCCESS;
        }

        foreach ($kbs as $kb) {
            $this->info("Syncing knowledge base: {$kb->name} (ID: {$kb->id})");

            if ($fresh) {
                $this->warn("  Deleting all vectors for kb_{$kb->id}...");
                try {
                    $store->deleteKnowledgeBase($kb->id);
                } catch (\Throwable $e) {
                    $this->warn("  Could not delete namespace: " . $e->getMessage());
                }
            }

            // Get chunks with embeddings
            $chunks = Chunk::where('knowledge_base_id', $kb->id)
                ->whereNotNull('embedding')
                ->get();

            if ($chunks->isEmpty()) {
                $this->warn("  No chunks with embeddings found.");
                continue;
            }

            $this->info("  Found {$chunks->count()} chunks to sync.");

            // Build embeddings array keyed by chunk ID
            $embeddings = [];
            foreach ($chunks as $chunk) {
                if (is_array($chunk->embedding) && count($chunk->embedding) > 0) {
                    $embeddings[$chunk->id] = $chunk->embedding;
                }
            }

            // Sync in batches
            $bar = $this->output->createProgressBar(count($embeddings));
            $bar->start();

            $batchSize = 100;
            $batches = array_chunk($chunks->all(), $batchSize);

            foreach ($batches as $batch) {
                $batchEmbeddings = [];
                foreach ($batch as $chunk) {
                    if (isset($embeddings[$chunk->id])) {
                        $batchEmbeddings[$chunk->id] = $embeddings[$chunk->id];
                    }
                }

                if (! empty($batchEmbeddings)) {
                    try {
                        $store->indexMany($batch, $batchEmbeddings);
                    } catch (\Throwable $e) {
                        $this->newLine();
                        $this->error("  Batch failed: " . $e->getMessage());
                    }
                }

                $bar->advance(count($batch));
            }

            $bar->finish();
            $this->newLine();
            $this->info("  Completed syncing {$kb->name}");
        }

        // Show stats
        try {
            $stats = $store->getStats();
            $this->newLine();
            $this->info('Pinecone Index Stats:');
            $this->table(
                ['Namespace', 'Vector Count'],
                collect($stats['namespaces'] ?? [])->map(fn ($v, $k) => [$k, $v['vectorCount'] ?? 0])->values()->all()
            );
        } catch (\Throwable $e) {
            $this->warn('Could not fetch stats: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
