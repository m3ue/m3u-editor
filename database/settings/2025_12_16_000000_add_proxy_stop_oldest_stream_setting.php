<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.proxy_stop_oldest_on_limit')) {
            $this->migrator->add('general.proxy_stop_oldest_on_limit', false);
        }
    }
};
