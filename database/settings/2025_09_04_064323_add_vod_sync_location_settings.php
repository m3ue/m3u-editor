<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.vod_stream_file_sync_enabled')) {
            $this->migrator->add('general.vod_stream_file_sync_enabled', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_include_series')) {
            $this->migrator->add('general.vod_stream_file_sync_include_series', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_include_season')) {
            $this->migrator->add('general.vod_stream_file_sync_include_season', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_location')) {
            $this->migrator->add('general.vod_stream_file_sync_location', null);
        }
    }
};
