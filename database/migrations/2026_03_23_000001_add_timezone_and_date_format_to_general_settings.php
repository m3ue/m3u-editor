<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.app_timezone')) {
            $this->migrator->add('general.app_timezone', null);
        }

        if (! $this->migrator->exists('general.date_format')) {
            $this->migrator->add('general.date_format', null);
        }
    }
};
