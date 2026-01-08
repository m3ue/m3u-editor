<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates existing default stream profiles from CRF mode to CBR mode
     * to prevent VBV underflow errors during live streaming.
     */
    public function up(): void
    {
        // Update Default Live Profile
        DB::table('stream_profiles')
            ->where('name', 'Default Live Profile')
            ->where('format', 'ts')
            ->update([
                'description' => 'Optimized for live streaming content with CBR encoding.',
                'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
                'updated_at' => now(),
            ]);

        // Update Default HLS Profile
        DB::table('stream_profiles')
            ->where('name', 'Default HLS Profile')
            ->where('format', 'm3u8')
            ->update([
                'description' => 'Optimized for live streaming with low latency, better buffering, and CBR encoding.',
                'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * Reverts default profiles back to CRF mode (not recommended).
     */
    public function down(): void
    {
        // Revert Default Live Profile
        DB::table('stream_profiles')
            ->where('name', 'Default Live Profile')
            ->where('format', 'ts')
            ->update([
                'description' => 'Optimized for live streaming content.',
                'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}',
                'updated_at' => now(),
            ]);

        // Revert Default HLS Profile
        DB::table('stream_profiles')
            ->where('name', 'Default HLS Profile')
            ->where('format', 'm3u8')
            ->update([
                'description' => 'Optimized for live streaming with low latency and better buffering.',
                'args' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
                'updated_at' => now(),
            ]);
    }
};
