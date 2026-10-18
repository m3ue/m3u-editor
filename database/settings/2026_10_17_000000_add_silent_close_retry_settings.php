<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.silent_close_retry_enabled')) {
            $this->migrator->add('general.silent_close_retry_enabled', false);
        }

        if (! $this->migrator->exists('general.silent_close_retry_window')) {
            $this->migrator->add('general.silent_close_retry_window', 60);
        }
    }
};
