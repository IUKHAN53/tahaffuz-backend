<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('source_path');
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->index(['knowledge_base_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
