<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.force_video_player_proxy')) {
            $this->migrator->add('general.force_video_player_proxy', false);
        }
    }
};
