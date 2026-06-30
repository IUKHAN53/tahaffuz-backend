<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('response_scripts', function (Blueprint $table) {
            if (! Schema::hasColumn('response_scripts', 'content_fa')) {
                $table->text('content_fa')->nullable()->after('content_en'); // Farsi
            }
        });

        // Roman Urdu is no longer a supported language.
        if (Schema::hasColumn('response_scripts', 'content_rud')) {
            Schema::table('response_scripts', function (Blueprint $table) {
                $table->dropColumn('content_rud');
            });
        }
    }

    public function down(): void
    {
        Schema::table('response_scripts', function (Blueprint $table) {
            if (! Schema::hasColumn('response_scripts', 'content_rud')) {
                $table->text('content_rud')->nullable()->after('content_en');
            }
            if (Schema::hasColumn('response_scripts', 'content_fa')) {
                $table->dropColumn('content_fa');
            }
        });
    }
};
