<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            // The chat a conversation fact belongs to. NULL = device-level
            // (card-scanned "current child"), always available regardless of scope.
            $table->unsignedBigInteger('chat_id')->nullable()->after('worker_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropColumn('chat_id');
        });
    }
};
