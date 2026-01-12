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
        Schema::table('networks', function (Blueprint $table) {
            // Broadcast enable/disable
            $table->boolean('broadcast_enabled')->default(false)->after('schedule_generated_at');

            // Output format settings
            $table->string('output_format', 10)->default('hls')->after('broadcast_enabled'); // 'hls' or 'mpegts'
            $table->unsignedSmallInteger('segment_duration')->default(6)->after('output_format'); // seconds
            $table->unsignedSmallInteger('hls_list_size')->default(10)->after('segment_duration'); // segments to keep

            // Transcoding settings (passed to media server)
            $table->boolean('transcode_on_server')->default(true)->after('hls_list_size');
            $table->unsignedInteger('video_bitrate')->nullable()->after('transcode_on_server'); // kbps, null = source
            $table->unsignedSmallInteger('audio_bitrate')->default(192)->after('video_bitrate'); // kbps
            $table->string('video_resolution', 20)->nullable()->after('audio_bitrate'); // e.g., '1920x1080', null = source

            // Broadcast state tracking
            $table->timestamp('broadcast_started_at')->nullable()->after('video_resolution');
            $table->unsignedInteger('broadcast_pid')->nullable()->after('broadcast_started_at'); // FFmpeg process ID
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn([
                'broadcast_enabled',
                'output_format',
                'segment_duration',
                'hls_list_size',
                'transcode_on_server',
                'video_bitrate',
                'audio_bitrate',
                'video_resolution',
                'broadcast_started_at',
                'broadcast_pid',
            ]);
        });
    }
};
