<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Series stream file sync name filtering options
        if (! $this->migrator->exists('general.stream_file_sync_name_filter_enabled')) {
            $this->migrator->add('general.stream_file_sync_name_filter_enabled', false);
        }
        if (! $this->migrator->exists('general.stream_file_sync_name_filter_patterns')) {
            $this->migrator->add('general.stream_file_sync_name_filter_patterns', null);
        }

        // VOD stream file sync name filtering options
        if (! $this->migrator->exists('general.vod_stream_file_sync_name_filter_enabled')) {
            $this->migrator->add('general.vod_stream_file_sync_name_filter_enabled', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_name_filter_patterns')) {
            $this->migrator->add('general.vod_stream_file_sync_name_filter_patterns', null);
        }
    }
};
