<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.copilot_url')) {
            $this->migrator->add('general.copilot_url', null);
        }
    }
};
