<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.xtream_api_message')) {
            $this->migrator->add('general.xtream_api_message', null);
        }
    }
};
