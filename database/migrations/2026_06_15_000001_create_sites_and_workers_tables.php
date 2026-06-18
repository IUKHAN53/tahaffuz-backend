<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fixed + outreach vaccination sites with coordinates (from the TKF
        // forms site list). Used to tell a worker their nearest site by GPS.
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('district');
            $table->string('union_council');
            $table->string('fix_site');
            $table->string('outreach_site')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('union_council');
            $table->index('district');
        });

        // Registered field workers, keyed by the app's device id. Captures the
        // worker's area (district/town/UC) so answers can be scoped to it.
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('designation')->nullable();
            $table->string('district')->nullable();
            $table->string('town')->nullable();
            $table->string('union_council')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
        Schema::dropIfExists('sites');
    }
};
