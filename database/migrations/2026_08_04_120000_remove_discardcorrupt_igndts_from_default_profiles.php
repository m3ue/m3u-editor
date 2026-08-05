<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Removes `+discardcorrupt+igndts` from the shipped default stream profiles.
     *
     * `+igndts` discards DTS on H.264 streams with B-frames, and `+discardcorrupt`
     * can drop reference frames — together these were causing video to silently
     * stall (while audio kept playing) on long-running live/DVR sessions. See
     * https://github.com/m3ue/m3u-editor/issues/1363.
     *
     * Only rows whose `args` still exactly match the previously-shipped default
     * string are touched, so any user who customized their "Default Live/HLS
     * Profile" (different bitrate, preset, etc.) is left alone.
     */
    public function up(): void
    {
        $updates = [
            [
                'name' => 'Default Live Profile',
                'format' => 'ts',
                'from' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
                'to' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
            ],
            [
                'name' => 'Default HLS Profile',
                'format' => 'm3u8',
                'from' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
                'to' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
            ],
            [
                'name' => 'Default HLS fMP4 Profile',
                'format' => 'm3u8',
                'from' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -hls_segment_type fmp4 -f hls {output_args|index.m3u8}',
                'to' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -hls_segment_type fmp4 -f hls {output_args|index.m3u8}',
            ],
        ];

        foreach ($updates as $update) {
            DB::table('stream_profiles')
                ->where('name', $update['name'])
                ->where('format', $update['format'])
                ->where('args', $update['from'])
                ->update([
                    'args' => $update['to'],
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverts the default profiles back to including `+discardcorrupt+igndts`
     * (not recommended — see up() for why these flags were removed).
     */
    public function down(): void
    {
        $updates = [
            [
                'name' => 'Default Live Profile',
                'format' => 'ts',
                'from' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
                'to' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -f mpegts {output_args|pipe:1}',
            ],
            [
                'name' => 'Default HLS Profile',
                'format' => 'm3u8',
                'from' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
                'to' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -f hls {output_args|index.m3u8}',
            ],
            [
                'name' => 'Default HLS fMP4 Profile',
                'format' => 'm3u8',
                'from' => '-fflags +genpts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -hls_segment_type fmp4 -f hls {output_args|index.m3u8}',
                'to' => '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -b:v {bitrate|2000k} -maxrate {maxrate|2500k} -bufsize {bufsize|2500k} -c:a aac -b:a {audio_bitrate|128k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -hls_segment_type fmp4 -f hls {output_args|index.m3u8}',
            ],
        ];

        foreach ($updates as $update) {
            DB::table('stream_profiles')
                ->where('name', $update['name'])
                ->where('format', $update['format'])
                ->where('args', $update['from'])
                ->update([
                    'args' => $update['to'],
                    'updated_at' => now(),
                ]);
        }
    }
};
