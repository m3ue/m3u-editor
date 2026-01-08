<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.ffmpeg_path')) {
            $this->migrator->add('general.ffmpeg_path', config('proxy.ffmpeg_path') ?? null);
        }
    }
};
