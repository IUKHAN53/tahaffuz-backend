<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curated_answers', function (Blueprint $table) {
            $table->id();
            // The (representative) question and the approved answer. Admins add
            // these from failed queries / thumbs-down feedback to fix answers.
            $table->text('question');
            $table->text('answer');
            $table->string('language', 8)->nullable(); // null = any language
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curated_answers');
    }
};
