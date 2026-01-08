<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.ffmpeg_codec_video')) {
            $this->migrator->add('general.ffmpeg_codec_video', config('proxy.ffmpeg_codec_video') ?? null);
        }
        if (! $this->migrator->exists('general.ffmpeg_codec_audio')) {
            $this->migrator->add('general.ffmpeg_codec_audio', config('proxy.ffmpeg_codec_audio') ?? null);
        }
        if (! $this->migrator->exists('general.ffmpeg_codec_subtitles')) {
            $this->migrator->add('general.ffmpeg_codec_subtitles', config('proxy.ffmpeg_codec_subtitles') ?? null);
        }
    }
};
