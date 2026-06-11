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
        Schema::create('daily_tips', function (Blueprint $table) {
            $table->id();
            $table->string('title_ur');
            $table->string('title_en')->nullable();
            $table->text('content_ur');
            $table->text('content_en')->nullable();
            $table->string('category')->nullable(); // e.g., 'cold_chain', 'vaccine', 'safety'
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        // Also create a table to store device push tokens
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->string('token')->unique();
            $table->string('platform')->default('expo'); // expo, fcm, apns
            $table->string('language', 10)->default('ur');
            $table->boolean('tips_enabled')->default(true);
            $table->timestamps();

            $table->index(['tips_enabled', 'platform']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
        Schema::dropIfExists('daily_tips');
    }
};
