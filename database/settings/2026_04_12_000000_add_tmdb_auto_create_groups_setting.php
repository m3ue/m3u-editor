<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tmdb_auto_create_groups')) {
            $this->migrator->add('general.tmdb_auto_create_groups', false);
        }
    }
};
