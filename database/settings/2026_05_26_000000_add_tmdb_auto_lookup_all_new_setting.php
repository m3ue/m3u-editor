<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tmdb_auto_lookup_all_new')) {
            $this->migrator->add('general.tmdb_auto_lookup_all_new', 'enabled');
        }
    }
};
