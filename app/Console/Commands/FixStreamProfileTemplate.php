<?php

namespace App\Console\Commands;

use App\Models\StreamProfile;
use Illuminate\Console\Command;

class FixStreamProfileTemplate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stream-profile:fix-template {id : The stream profile ID to fix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix empty FFmpeg template for a stream profile';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $profileId = $this->argument('id');

        $profile = StreamProfile::find($profileId);

        if (! $profile) {
            $this->error("Stream profile with ID {$profileId} not found");

            return self::FAILURE;
        }

        $this->info('Current profile:');
        $this->line("  ID: {$profile->id}");
        $this->line("  Name: {$profile->name}");
        $this->line("  Format: {$profile->format}");
        $this->line('  Args: '.($profile->args ?: '(empty)'));

        if (! empty($profile->args)) {
            $this->warn('Profile already has an FFmpeg template. Overwrite?');
            if (! $this->confirm('Continue?')) {
                return self::SUCCESS;
            }
        }

        // Set the correct template based on format
        if ($profile->format === 'm3u8') {
            $template = '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -hls_time 2 -hls_list_size 30 -hls_flags program_date_time -hls_segment_filename segment_%03d.ts -f hls {output_args|index.m3u8}';
        } else {
            $template = '-fflags +genpts+discardcorrupt+igndts -i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}';
        }

        $profile->args = $template;
        $profile->save();

        $this->info('✅ Profile updated successfully!');
        $this->line("  New template: {$template}");

        return self::SUCCESS;
    }
}
