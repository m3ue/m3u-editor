<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.proxy_normalize_live_output')) {
            $this->migrator->add('general.proxy_normalize_live_output', null);
        }
        if (! $this->migrator->exists('general.proxy_normalization_encoder')) {
            $this->migrator->add('general.proxy_normalization_encoder', 'cpu');
        }
    }
};
