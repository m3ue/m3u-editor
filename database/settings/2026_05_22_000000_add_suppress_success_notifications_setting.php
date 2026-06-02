<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.suppress_success_notifications')) {
            $this->migrator->add('general.suppress_success_notifications', false);
        }
    }
};
