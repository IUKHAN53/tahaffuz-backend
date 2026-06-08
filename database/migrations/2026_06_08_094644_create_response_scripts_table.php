<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('response_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'introduction', 'no_answer', 'greeting'
            $table->string('name'); // Human-readable name for admin
            $table->string('description')->nullable(); // Help text for admin
            $table->text('content_ur'); // Urdu (required, default)
            $table->text('content_en')->nullable(); // English
            $table->text('content_rud')->nullable(); // Roman Urdu
            $table->text('content_ps')->nullable(); // Pashto
            $table->text('content_sd')->nullable(); // Sindhi
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('response_scripts');
    }
};
