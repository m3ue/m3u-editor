<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.invalidate_import_group_threshold')) {
            $this->migrator->add('general.invalidate_import_group_threshold', 50);
        }

        if (! $this->migrator->exists('general.invalidate_import_series_threshold')) {
            $this->migrator->add('general.invalidate_import_series_threshold', 100);
        }
    }
};
