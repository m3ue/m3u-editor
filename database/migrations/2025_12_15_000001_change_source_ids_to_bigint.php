<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change source_*_id columns from integer to bigint.
 *
 * The crc32() function returns values from 0 to 4,294,967,295 (unsigned 32-bit).
 * PostgreSQL's integer type is signed (-2,147,483,648 to 2,147,483,647), so values
 * above 2,147,483,647 cause "Numeric value out of range" errors.
 *
 * This migration changes the affected columns to bigint to accommodate the full
 * range of crc32() values.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Change episodes.source_episode_id to bigint
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('source_episode_id')->nullable()->change();
        });

        // Change series.source_series_id to bigint (same issue)
        Schema::table('series', function (Blueprint $table) {
            $table->unsignedBigInteger('source_series_id')->nullable()->change();
        });

        // Change seasons.source_season_id to bigint if it exists and uses crc32
        if (Schema::hasColumn('seasons', 'source_season_id')) {
            Schema::table('seasons', function (Blueprint $table) {
                $table->unsignedBigInteger('source_season_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to unsigned integer (may cause data loss for large values)
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedInteger('source_episode_id')->nullable()->change();
        });

        Schema::table('series', function (Blueprint $table) {
            $table->unsignedInteger('source_series_id')->nullable()->change();
        });

        if (Schema::hasColumn('seasons', 'source_season_id')) {
            Schema::table('seasons', function (Blueprint $table) {
                $table->unsignedInteger('source_season_id')->nullable()->change();
            });
        }
    }
};
