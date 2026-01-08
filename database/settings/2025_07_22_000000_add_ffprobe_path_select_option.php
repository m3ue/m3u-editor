<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.ffprobe_path')) {
            $this->migrator->add('general.ffprobe_path', config('proxy.ffprobe_path') ?? 'jellyfin-ffprobe');
        }
    }
};
