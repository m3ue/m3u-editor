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
        Schema::table('network_content', function (Blueprint $table) {
            // Per-item overrides for the Network's preferred_audio_track/preferred_subtitle_track.
            // Same shape (ISO 639 code or a media-server-resolved stream index) — null means
            // "use the network-level default" for this item.
            $table->string('preferred_audio_track')->nullable()->after('weight');
            $table->string('preferred_subtitle_track')->nullable()->after('preferred_audio_track');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_content', function (Blueprint $table) {
            $table->dropColumn(['preferred_audio_track', 'preferred_subtitle_track']);
        });
    }
};
