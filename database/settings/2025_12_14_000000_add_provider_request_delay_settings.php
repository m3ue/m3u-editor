<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.enable_provider_request_delay')) {
            $this->migrator->add('general.enable_provider_request_delay', false);
        }
        if (! $this->migrator->exists('general.provider_request_delay_ms')) {
            $this->migrator->add('general.provider_request_delay_ms', 500);
        }
        if (! $this->migrator->exists('general.provider_max_concurrent_requests')) {
            $this->migrator->add('general.provider_max_concurrent_requests', 2);
        }
    }

    public function down(): void
    {
        $this->migrator->delete('general.enable_provider_request_delay');
        $this->migrator->delete('general.provider_request_delay_ms');
        $this->migrator->delete('general.provider_max_concurrent_requests');
    }
};
