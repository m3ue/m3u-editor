<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.invalidate_import_retry_backoff')) {
            $this->migrator->add('general.invalidate_import_retry_backoff', 'balanced');
        }
    }
};
