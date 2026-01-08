<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // TMDB integration settings
        if (! $this->migrator->exists('general.tmdb_api_key')) {
            $this->migrator->add('general.tmdb_api_key', null);
        }
        if (! $this->migrator->exists('general.tmdb_auto_lookup_on_import')) {
            $this->migrator->add('general.tmdb_auto_lookup_on_import', false);
        }
        if (! $this->migrator->exists('general.tmdb_rate_limit')) {
            $this->migrator->add('general.tmdb_rate_limit', 40);
        }
        if (! $this->migrator->exists('general.tmdb_language')) {
            $this->migrator->add('general.tmdb_language', 'en-US');
        }
        if (! $this->migrator->exists('general.tmdb_confidence_threshold')) {
            $this->migrator->add('general.tmdb_confidence_threshold', 80);
        }
    }
};
