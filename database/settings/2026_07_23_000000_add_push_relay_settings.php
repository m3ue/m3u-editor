<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.push_relay_enabled')) {
            $this->migrator->add('general.push_relay_enabled', true);
        }
    }
};
