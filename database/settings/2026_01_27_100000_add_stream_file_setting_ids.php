<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Default Series Stream File Setting profile ID
        if (! $this->migrator->exists('general.default_series_stream_file_setting_id')) {
            $this->migrator->add('general.default_series_stream_file_setting_id', null);
        }

        // Default VOD Stream File Setting profile ID
        if (! $this->migrator->exists('general.default_vod_stream_file_setting_id')) {
            $this->migrator->add('general.default_vod_stream_file_setting_id', null);
        }
    }
};
