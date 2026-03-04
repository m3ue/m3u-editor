<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Track HLS segment sequence and discontinuity sequence for seamless content transitions.
     * This allows FFmpeg processes to continue segment numbering across programme changes,
     * preventing HLS players from looping or stalling when content transitions occur.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Track the next segment number to use (incremented as segments are created)
            // This ensures segment numbering continues across programme transitions
            $table->unsignedBigInteger('broadcast_segment_sequence')->default(0)->after('broadcast_error');

            // Track the discontinuity sequence (incremented each time content changes)
            // This is used to properly set EXT-X-DISCONTINUITY-SEQUENCE in the playlist
            $table->unsignedInteger('broadcast_discontinuity_sequence')->default(0)->after('broadcast_segment_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['broadcast_segment_sequence', 'broadcast_discontinuity_sequence']);
        });
    }
};
