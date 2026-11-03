<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.sports_espn_enabled')) {
            $this->migrator->add('general.sports_espn_enabled', false);
        }
        if (! $this->migrator->exists('general.sports_espn_api_url')) {
            $this->migrator->add('general.sports_espn_api_url', null);
        }
    }
};
