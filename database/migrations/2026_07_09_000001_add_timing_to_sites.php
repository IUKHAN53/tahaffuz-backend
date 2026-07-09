<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured opening hours for vaccination sites, shown on the app's site
 * cards. Days are a set (outreach sites may run non-consecutive days, e.g.
 * Mon/Wed/Fri) and open/close are per-site times. All columns are nullable:
 * NULL means "standard EPI hours" (Mon–Sat 9:00–14:00, see Site::DEFAULT_*),
 * so nothing needs backfilling and clearing a site resets it to the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // JSON array of day codes, e.g. ["mon","tue","wed","thu","fri","sat"].
            $table->text('timing_days')->nullable()->after('longitude');
            // 24h "HH:MM" strings — driver-proof and trivially comparable.
            $table->string('open_time', 5)->nullable()->after('timing_days');
            $table->string('close_time', 5)->nullable()->after('open_time');
            // Optional mid-day break (e.g. prayer/lunch). NULL = no break.
            $table->string('break_start', 5)->nullable()->after('close_time');
            $table->string('break_end', 5)->nullable()->after('break_start');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['timing_days', 'open_time', 'close_time', 'break_start', 'break_end']);
        });
    }
};
