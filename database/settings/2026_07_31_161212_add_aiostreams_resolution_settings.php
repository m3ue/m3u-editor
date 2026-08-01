<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.aiostreams_rate_limit')) {
            $this->migrator->add('general.aiostreams_rate_limit', 10);
        }
        if (! $this->migrator->exists('general.aiostreams_max_failover_candidates')) {
            $this->migrator->add('general.aiostreams_max_failover_candidates', 3);
        }
    }
};
