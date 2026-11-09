<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.sports_league_ids')) {
            $this->migrator->add('general.sports_league_ids', null);
        }
    }
};
