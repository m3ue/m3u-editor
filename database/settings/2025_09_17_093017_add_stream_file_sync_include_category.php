<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.stream_file_sync_include_category')) {
            $this->migrator->add('general.stream_file_sync_include_category', false);
        }
    }
};
