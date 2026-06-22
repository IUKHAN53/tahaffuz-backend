<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scanned EPI child immunization cards. Dates are kept as strings —
        // they come from messy handwriting and may not parse cleanly.
        Schema::create('vaccination_cards', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->index();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('child_name')->nullable();
            $table->string('sex')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('card_number')->nullable()->index();
            $table->string('district')->nullable();
            $table->string('town')->nullable();
            $table->string('union_council')->nullable();
            // [{ name, given_date, due_date }]
            $table->json('vaccines')->nullable();
            $table->string('next_due_date')->nullable();
            $table->json('raw_extract')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaccination_cards');
    }
};
