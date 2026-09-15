<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_dynamic_group_cache')) {
            $this->migrator->add('general.enable_dynamic_group_cache', false);
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_location')) {
            $this->migrator->add('general.dynamic_group_cache_location', null);
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_lazy_load')) {
            $this->migrator->add('general.dynamic_group_cache_lazy_load', false);
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_schedule')) {
            $this->migrator->add('general.dynamic_group_cache_schedule', '0 3 * * *');
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_max_concurrent_downloads')) {
            $this->migrator->add('general.dynamic_group_cache_max_concurrent_downloads', 2);
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_retry_cooldown_minutes')) {
            $this->migrator->add('general.dynamic_group_cache_retry_cooldown_minutes', 360);
        }

        if (! $this->migrator->exists('general.dynamic_group_cache_failure_cooldown_hours')) {
            $this->migrator->add('general.dynamic_group_cache_failure_cooldown_hours', 24);
        }
    }
};
