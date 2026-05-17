<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->text('content');
            $table->unsignedInteger('token_estimate')->default(0);
            $table->json('embedding')->nullable();
            $table->double('embedding_norm')->nullable();
            $table->timestamps();

            $table->index(['knowledge_base_id']);
            $table->index(['document_id', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
