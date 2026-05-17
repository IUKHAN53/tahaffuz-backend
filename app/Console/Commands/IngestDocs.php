<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Services\Rag\Ingestor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rag:ingest {--kb=epi-pakistan : Knowledge base slug} {--dir= : Directory of files to ingest} {--reingest : Re-ingest documents that are already READY}')]
#[Description('Scan a directory and ingest PDF/DOCX/TXT files into a knowledge base.')]
class IngestDocs extends Command
{
    public function handle(Ingestor $ingestor): int
    {
        $slug = (string) $this->option('kb');
        $dir = $this->option('dir') ?: base_path('../docs');
        $reingest = (bool) $this->option('reingest');

        $kb = KnowledgeBase::where('slug', $slug)->first();
        if (! $kb) {
            $this->error("Knowledge base [{$slug}] not found.");
            return self::FAILURE;
        }

        $dir = realpath($dir) ?: $dir;
        if (! is_dir($dir)) {
            $this->error("Directory not found: {$dir}");
            return self::FAILURE;
        }

        $files = collect(glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*'))
            ->filter(fn ($p) => is_file($p) && in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['pdf', 'docx', 'txt', 'md']));

        if ($files->isEmpty()) {
            $this->warn("No supported files in {$dir}");
            return self::SUCCESS;
        }

        $this->info("Ingesting {$files->count()} file(s) into [{$kb->slug}] from {$dir}");

        foreach ($files as $path) {
            $title = pathinfo($path, PATHINFO_FILENAME);
            $doc = Document::firstOrCreate(
                ['knowledge_base_id' => $kb->id, 'source_path' => $path],
                [
                    'title' => $title,
                    'mime_type' => function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null,
                    'size_bytes' => filesize($path) ?: 0,
                    'status' => Document::STATUS_PENDING,
                ]
            );

            if ($doc->status === Document::STATUS_READY && ! $reingest) {
                $this->line("  · skip (already ready): {$title}");
                continue;
            }

            $this->line("  → ingesting: {$title}");
            try {
                $ingestor->ingest($doc->fresh());
                $this->info("    ok ({$doc->fresh()->chunk_count} chunks)");
            } catch (\Throwable $e) {
                $this->error("    FAILED: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
