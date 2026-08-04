<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site vaccine session days from the SHR UC schedule: BCG and MR are
 * multi-dose vials opened only on fixed weekdays, so each fix site holds its
 * BCG session on one day and MR on another. Day codes mon..sun; NULL = not
 * scheduled / unknown. Editable in the admin's Timing dialog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('bcg_day', 3)->nullable()->after('break_end');
            $table->string('mr_day', 3)->nullable()->after('bcg_day');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['bcg_day', 'mr_day']);
        });
    }
};
