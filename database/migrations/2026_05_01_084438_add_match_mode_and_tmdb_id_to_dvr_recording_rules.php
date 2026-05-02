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
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->string('match_mode', 20)->default('contains')->after('series_mode');
            $table->string('tmdb_id', 50)->nullable()->after('match_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->dropColumn(['match_mode', 'tmdb_id']);
        });
    }
};
