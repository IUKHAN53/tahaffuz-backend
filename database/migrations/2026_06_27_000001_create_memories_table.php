<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->unsignedBigInteger('worker_id')->nullable();
            // child_fact = from the scanned card (replaced when a new card is scanned);
            // fact = durable detail learned from the conversation.
            $table->string('kind', 32)->default('fact');
            $table->text('content');
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
