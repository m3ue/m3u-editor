<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.alerts_on_sync_invalidated')) {
            $this->migrator->add('general.alerts_on_sync_invalidated', false);
        }
    }
};
