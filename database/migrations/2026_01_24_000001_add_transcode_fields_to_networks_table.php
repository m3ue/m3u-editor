<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->string('video_codec')->nullable()->after('video_bitrate');
            $table->string('audio_codec')->nullable()->after('video_codec');
            $table->string('transcode_preset')->nullable()->after('audio_codec');
            $table->string('hwaccel')->nullable()->after('transcode_preset');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['video_codec', 'audio_codec', 'transcode_preset', 'hwaccel']);
        });
    }
};
