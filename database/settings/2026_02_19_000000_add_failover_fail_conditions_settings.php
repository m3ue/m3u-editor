<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.failover_fail_conditions_enabled')) {
            $this->migrator->add('general.failover_fail_conditions_enabled', false);
        }
        if (! $this->migrator->exists('general.failover_fail_conditions')) {
            $this->migrator->add('general.failover_fail_conditions', []);
        }
        if (! $this->migrator->exists('general.failover_fail_conditions_timeout')) {
            $this->migrator->add('general.failover_fail_conditions_timeout', 5);
        }
    }
};
