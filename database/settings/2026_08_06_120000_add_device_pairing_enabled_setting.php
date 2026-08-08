<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.device_pairing_enabled')) {
            $this->migrator->add('general.device_pairing_enabled', true);
        }
    }
};
