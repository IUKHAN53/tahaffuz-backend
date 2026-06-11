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
        Schema::create('message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 64)->index();
            $table->enum('rating', ['up', 'down']); // thumbs up or down
            $table->text('comment')->nullable(); // optional feedback text
            $table->timestamps();

            $table->unique(['message_id', 'device_id']); // one feedback per message per device
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_feedback');
    }
};
