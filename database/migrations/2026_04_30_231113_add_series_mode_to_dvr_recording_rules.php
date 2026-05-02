<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds series_mode column to dvr_recording_rules.
     *
     * Replaces the boolean new_only flag with a three-mode enum:
     *   all        — record every matching programme (default)
     *   new_flag   — record only when epg_programme.is_new = true
     *   unique_se  — skip if (series_key, season, episode) already recorded
     *
     * Backfill: new_only=true → series_mode=new_flag, else series_mode=all.
     * A subsequent release can drop the new_only column.
     */
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->string('series_mode', 20)->default('all')->after('new_only');
        });

        DB::table('dvr_recording_rules')
            ->where('new_only', true)
            ->update(['series_mode' => 'new_flag']);

        DB::table('dvr_recording_rules')
            ->where('new_only', false)
            ->update(['series_mode' => 'all']);
    }

    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->dropColumn('series_mode');
        });
    }
};
