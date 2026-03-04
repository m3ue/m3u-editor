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
            if (! Schema::hasColumn('networks', 'broadcast_enabled')) {
                $table->boolean('broadcast_enabled')->default(false)->after('schedule_generated_at');
            }

            // Output format settings
            if (! Schema::hasColumn('networks', 'output_format')) {
                $table->string('output_format', 10)->default('hls')->after('broadcast_enabled');
            }
            if (! Schema::hasColumn('networks', 'segment_duration')) {
                $table->unsignedSmallInteger('segment_duration')->default(6)->after('output_format');
            }
            if (! Schema::hasColumn('networks', 'hls_list_size')) {
                $table->unsignedSmallInteger('hls_list_size')->default(10)->after('segment_duration');
            }

            // Transcoding settings (passed to media server)
            if (! Schema::hasColumn('networks', 'transcode_on_server')) {
                $table->boolean('transcode_on_server')->default(true)->after('hls_list_size');
            }
            if (! Schema::hasColumn('networks', 'video_bitrate')) {
                $table->unsignedInteger('video_bitrate')->nullable()->after('transcode_on_server');
            }
            if (! Schema::hasColumn('networks', 'audio_bitrate')) {
                $table->unsignedSmallInteger('audio_bitrate')->default(192)->after('video_bitrate');
            }
            if (! Schema::hasColumn('networks', 'video_resolution')) {
                $table->string('video_resolution', 20)->nullable()->after('audio_bitrate');
            }

            // Broadcast state tracking
            if (! Schema::hasColumn('networks', 'broadcast_started_at')) {
                $table->timestamp('broadcast_started_at')->nullable()->after('video_resolution');
            }
            if (! Schema::hasColumn('networks', 'broadcast_pid')) {
                $table->unsignedInteger('broadcast_pid')->nullable()->after('broadcast_started_at');
            }
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
