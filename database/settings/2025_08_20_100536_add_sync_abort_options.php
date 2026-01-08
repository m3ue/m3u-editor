<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.invalidate_import')) {
            $this->migrator->add('general.invalidate_import', false);
        }
        if (! $this->migrator->exists('general.invalidate_import_threshold')) {
            $this->migrator->add('general.invalidate_import_threshold', 100);
        }
    }
};
