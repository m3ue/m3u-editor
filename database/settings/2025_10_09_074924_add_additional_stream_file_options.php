<?php

use App\Settings\GeneralSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Series stream file sync filename options
        if (! $this->migrator->exists('general.stream_file_sync_filename_year')) {
            $this->migrator->add('general.stream_file_sync_filename_year', false);
        }
        if (! $this->migrator->exists('general.stream_file_sync_filename_resolution')) {
            $this->migrator->add('general.stream_file_sync_filename_resolution', false);
        }
        if (! $this->migrator->exists('general.stream_file_sync_filename_codec')) {
            $this->migrator->add('general.stream_file_sync_filename_codec', false);
        }
        if (! $this->migrator->exists('general.stream_file_sync_filename_tmdb_id')) {
            $this->migrator->add('general.stream_file_sync_filename_tmdb_id', false);
        }
        if (! $this->migrator->exists('general.stream_file_sync_tmdb_id_format')) {
            $this->migrator->add('general.stream_file_sync_tmdb_id_format', 'square');
        }
        if (! $this->migrator->exists('general.stream_file_sync_clean_special_chars')) {
            $this->migrator->add('general.stream_file_sync_clean_special_chars', true);
        }
        if (! $this->migrator->exists('general.stream_file_sync_remove_consecutive_chars')) {
            $this->migrator->add('general.stream_file_sync_remove_consecutive_chars', true);
        }
        if (! $this->migrator->exists('general.stream_file_sync_replace_char')) {
            $this->migrator->add('general.stream_file_sync_replace_char', 'space');
        }

        // Convert existing boolean values to array format for Series path structure
        if (! $this->migrator->exists('general.stream_file_sync_path_structure')) {
            $settings = app(GeneralSettings::class);
            $structure = [];
            if ($settings->stream_file_sync_include_category) {
                $structure[] = 'category';
            }
            if ($settings->stream_file_sync_include_series) {
                $structure[] = 'series';
            }
            if ($settings->stream_file_sync_include_season) {
                $structure[] = 'season';
            }
            $this->migrator->add('general.stream_file_sync_path_structure', $structure);
        }

        // Convert existing boolean values to array format for Series filename metadata
        if (! $this->migrator->exists('general.stream_file_sync_filename_metadata')) {
            $settings = app(GeneralSettings::class);
            $metadata = [];
            if ($settings->stream_file_sync_filename_year) {
                $metadata[] = 'year';
            }
            if ($settings->stream_file_sync_filename_resolution) {
                $metadata[] = 'resolution';
            }
            if ($settings->stream_file_sync_filename_codec) {
                $metadata[] = 'codec';
            }
            if ($settings->stream_file_sync_filename_tmdb_id) {
                $metadata[] = 'tmdb_id';
            }
            $this->migrator->add('general.stream_file_sync_filename_metadata', $metadata);
        }

        // VOD stream file sync filename options
        if (! $this->migrator->exists('general.vod_stream_file_sync_filename_year')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_year', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_filename_resolution')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_resolution', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_filename_codec')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_codec', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_filename_tmdb_id')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_tmdb_id', false);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_tmdb_id_format')) {
            $this->migrator->add('general.vod_stream_file_sync_tmdb_id_format', 'square');
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_clean_special_chars')) {
            $this->migrator->add('general.vod_stream_file_sync_clean_special_chars', true);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_remove_consecutive_chars')) {
            $this->migrator->add('general.vod_stream_file_sync_remove_consecutive_chars', true);
        }
        if (! $this->migrator->exists('general.vod_stream_file_sync_replace_char')) {
            $this->migrator->add('general.vod_stream_file_sync_replace_char', 'space');
        }

        // Convert existing boolean values to array format for VOD path structure
        if (! $this->migrator->exists('general.vod_stream_file_sync_path_structure')) {
            $settings = app(GeneralSettings::class);
            $structure = [];
            if ($settings->vod_stream_file_sync_include_season) {
                $structure[] = 'group';
            }
            $this->migrator->add('general.vod_stream_file_sync_path_structure', $structure);
        }

        // Convert existing boolean values to array format for VOD filename metadata
        if (! $this->migrator->exists('general.vod_stream_file_sync_filename_metadata')) {
            $settings = app(GeneralSettings::class);
            $metadata = [];
            if ($settings->vod_stream_file_sync_filename_year) {
                $metadata[] = 'year';
            }
            if ($settings->vod_stream_file_sync_filename_resolution) {
                $metadata[] = 'resolution';
            }
            if ($settings->vod_stream_file_sync_filename_codec) {
                $metadata[] = 'codec';
            }
            if ($settings->vod_stream_file_sync_filename_tmdb_id) {
                $metadata[] = 'tmdb_id';
            }
            $this->migrator->add('general.vod_stream_file_sync_filename_metadata', $metadata);
        }
    }
};
