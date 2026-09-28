<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_cache')) {
            $this->migrator->add('general.enable_cache', false);
        }

        if (! $this->migrator->exists('general.cache_retention_mode')) {
            $this->migrator->add('general.cache_retention_mode', 'automatic');
        }

        if (! $this->migrator->exists('general.default_share_cache_across_playlists')) {
            $this->migrator->add('general.default_share_cache_across_playlists', false);
        }
    }
};
