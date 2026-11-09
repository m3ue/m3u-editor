<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.proxy_track_watch_history')) {
            $this->migrator->add('general.proxy_track_watch_history', false);
        }
        if (! $this->migrator->exists('general.proxy_watch_history_retention_days')) {
            $this->migrator->add('general.proxy_watch_history_retention_days', 30);
        }
    }
};
