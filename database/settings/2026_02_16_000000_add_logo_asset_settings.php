<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.logo_cache_permanent')) {
            $this->migrator->add('general.logo_cache_permanent', false);
        }

        if (! $this->migrator->exists('general.logo_placeholder_url')) {
            $this->migrator->add('general.logo_placeholder_url', null);
        }

        if (! $this->migrator->exists('general.episode_placeholder_url')) {
            $this->migrator->add('general.episode_placeholder_url', null);
        }

        if (! $this->migrator->exists('general.vod_series_poster_placeholder_url')) {
            $this->migrator->add('general.vod_series_poster_placeholder_url', null);
        }

        if (! $this->migrator->exists('general.managed_logo_assets')) {
            $this->migrator->add('general.managed_logo_assets', []);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.logo_cache_permanent');
        $this->migrator->deleteIfExists('general.logo_placeholder_url');
        $this->migrator->deleteIfExists('general.episode_placeholder_url');
        $this->migrator->deleteIfExists('general.vod_series_poster_placeholder_url');
        $this->migrator->deleteIfExists('general.managed_logo_assets');
    }
};
