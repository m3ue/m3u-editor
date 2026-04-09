<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.max_concurrent_floating_players')) {
            $this->migrator->add('general.max_concurrent_floating_players', null);
        }
    }
};
