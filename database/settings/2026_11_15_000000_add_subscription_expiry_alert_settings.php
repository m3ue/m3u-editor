<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.alerts_on_subscription_expiring')) {
            $this->migrator->add('general.alerts_on_subscription_expiring', false);
        }

        if (! $this->migrator->exists('general.subscription_expiry_warning_days')) {
            $this->migrator->add('general.subscription_expiry_warning_days', 7);
        }
    }
};
