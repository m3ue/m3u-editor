<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.admin_nav_active_preset')) {
            $this->migrator->add('general.admin_nav_active_preset', 'default');
        }

        if (! $this->migrator->exists('general.admin_nav_layout')) {
            $this->migrator->add('general.admin_nav_layout', null);
        }
    }
};
