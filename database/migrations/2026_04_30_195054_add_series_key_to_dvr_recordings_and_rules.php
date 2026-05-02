<?php

use App\Support\SeriesKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds series_key + normalized_title columns to dvr_recordings and dvr_recording_rules.
     *
     * series_key groups all recordings/rules belonging to the same logical "show"
     * within a single DVR setting. It is the new dedup and keep_last grouping key.
     *
     * Format (Phase 1): `setting:{id}|title:{normalized_title}`
     */
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->string('series_key', 191)->nullable()->after('series_title');
            $table->string('normalized_title', 191)->nullable()->after('series_key');
            $table->index('series_key', 'dvr_recording_rules_series_key_idx');
        });

        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->string('series_key', 191)->nullable()->after('title');
            $table->string('normalized_title', 191)->nullable()->after('series_key');
            $table->index(
                ['series_key', 'programme_start'],
                'dvr_recordings_series_key_programme_start_idx'
            );
        });

        $this->backfillRules();
        $this->backfillRecordings();
    }

    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->dropIndex('dvr_recording_rules_series_key_idx');
            $table->dropColumn(['series_key', 'normalized_title']);
        });

        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->dropIndex('dvr_recordings_series_key_programme_start_idx');
            $table->dropColumn(['series_key', 'normalized_title']);
        });
    }

    /**
     * Backfill series_key + normalized_title for existing rules.
     *
     * Series rules use series_title; Once/Manual rules don't have a meaningful
     * "series" identity at the rule level so they are left null and resolve their
     * key from the recording's title at schedule time.
     */
    private function backfillRules(): void
    {
        DB::table('dvr_recording_rules')
            ->where('type', 'series')
            ->whereNotNull('series_title')
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (object $row): void {
                $normalized = SeriesKey::normalize($row->series_title);
                if ($normalized === '') {
                    return;
                }

                DB::table('dvr_recording_rules')
                    ->where('id', $row->id)
                    ->update([
                        'series_key' => "setting:{$row->dvr_setting_id}|title:{$normalized}",
                        'normalized_title' => $normalized,
                    ]);
            });
    }

    /**
     * Backfill series_key + normalized_title for existing recordings.
     *
     * Every recording has a title and a dvr_setting_id, so we always derive a key.
     */
    private function backfillRecordings(): void
    {
        DB::table('dvr_recordings')
            ->whereNotNull('title')
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (object $row): void {
                $normalized = SeriesKey::normalize($row->title);
                if ($normalized === '') {
                    return;
                }

                DB::table('dvr_recordings')
                    ->where('id', $row->id)
                    ->update([
                        'series_key' => "setting:{$row->dvr_setting_id}|title:{$normalized}",
                        'normalized_title' => $normalized,
                    ]);
            });
    }
};
