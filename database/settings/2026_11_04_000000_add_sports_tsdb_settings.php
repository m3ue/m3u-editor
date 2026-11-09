<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.sports_tsdb_enabled')) {
            $this->migrator->add('general.sports_tsdb_enabled', false);
        }
        if (! $this->migrator->exists('general.sports_tsdb_api_key')) {
            $this->migrator->add('general.sports_tsdb_api_key', null);
        }
    }
};
