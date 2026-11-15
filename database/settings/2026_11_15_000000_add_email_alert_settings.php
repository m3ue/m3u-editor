<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.email_alerts_enabled')) {
            $this->migrator->add('general.email_alerts_enabled', false);
        }
        if (! $this->migrator->exists('general.email_alerts_recipients')) {
            $this->migrator->add('general.email_alerts_recipients', null);
        }
    }
};
