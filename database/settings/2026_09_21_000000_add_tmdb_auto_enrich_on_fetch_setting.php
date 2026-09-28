<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tmdb_auto_enrich_on_fetch')) {
            $this->migrator->add('general.tmdb_auto_enrich_on_fetch', false);
        }
    }
};
