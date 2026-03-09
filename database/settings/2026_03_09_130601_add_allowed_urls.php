<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.allowed_urls')) {
            $this->migrator->add('general.allowed_urls', []);
        }
    }
};
