<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.auto_backup_database_delete_after_days')) {
            $this->migrator->add('general.auto_backup_database_delete_after_days', 0);
        }
    }
};
