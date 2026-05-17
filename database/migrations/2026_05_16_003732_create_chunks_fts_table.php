<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite FTS5 virtual table backed by chunks. content='chunks' makes it an
        // external-content index — rows live in chunks, FTS5 just stores the index.
        DB::statement('DROP TABLE IF EXISTS chunks_fts');
        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE chunks_fts USING fts5(
                content_text,
                content='chunks',
                content_rowid='id',
                tokenize='unicode61 remove_diacritics 2'
            )
        SQL);

        // Triggers keep FTS in sync with chunks.
        DB::statement(<<<'SQL'
            CREATE TRIGGER chunks_ai AFTER INSERT ON chunks BEGIN
                INSERT INTO chunks_fts(rowid, content_text) VALUES (new.id, new.content);
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER chunks_ad AFTER DELETE ON chunks BEGIN
                INSERT INTO chunks_fts(chunks_fts, rowid, content_text) VALUES('delete', old.id, old.content);
            END
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER chunks_au AFTER UPDATE ON chunks BEGIN
                INSERT INTO chunks_fts(chunks_fts, rowid, content_text) VALUES('delete', old.id, old.content);
                INSERT INTO chunks_fts(rowid, content_text) VALUES (new.id, new.content);
            END
        SQL);

        // Backfill from existing chunks.
        DB::statement('INSERT INTO chunks_fts(rowid, content_text) SELECT id, content FROM chunks');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS chunks_ai');
        DB::statement('DROP TRIGGER IF EXISTS chunks_ad');
        DB::statement('DROP TRIGGER IF EXISTS chunks_au');
        DB::statement('DROP TABLE IF EXISTS chunks_fts');
    }
};
