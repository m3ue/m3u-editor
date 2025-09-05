<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (!$this->migrator->exists('general.auto_backup_database')) {
            $this->migrator->add('general.auto_backup_database', false);
        }
        if (!$this->migrator->exists('general.auto_backup_database_schedule')) {
            $this->migrator->add('general.auto_backup_database_schedule', '0 3 * * *');
        }
        if (!$this->migrator->exists('general.auto_backup_database_max_backups')) {
            $this->migrator->add('general.auto_backup_database_max_backups', 5);
        }
    }
};
