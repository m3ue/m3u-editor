<?php

namespace App\Filament\Concerns;

use App\Enums\DvrSeriesMode;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistRequestSetting;

/**
 * Shared Filament edit-page hooks for the dvr_/request_ prefixed form fields
 * used on Playlist, CustomPlaylist, and MergedPlaylist edit pages. These fields
 * aren't real columns on the playlist tables — they're hydrated from and saved
 * back to the polymorphic-owned DvrSetting / PlaylistRequestSetting relations.
 */
trait HasDvrAndRequestFormHooks
{
    /**
     * Populate dvr_/request_ prefixed fields from the owner's DvrSetting/PlaylistRequestSetting.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillDvrAndRequestFormData(array $data, Playlist|CustomPlaylist|MergedPlaylist $record): array
    {
        $dvr = $record->dvrSetting;

        if ($dvr) {
            $data['dvr_enabled'] = $dvr->enabled;
            $data['dvr_output_format'] = $dvr->dvr_output_format ?? 'ts';
            $data['dvr_max_concurrent_recordings'] = $dvr->max_concurrent_recordings;
            $data['dvr_default_start_early_seconds'] = $dvr->default_start_early_seconds;
            $data['dvr_default_end_late_seconds'] = $dvr->default_end_late_seconds;
            $data['dvr_retention_days'] = $dvr->retention_days;
            $data['dvr_global_disk_quota_gb'] = $dvr->global_disk_quota_gb;
            $data['dvr_enable_metadata_enrichment'] = $dvr->enable_metadata_enrichment;
            $data['dvr_generate_nfo_files'] = $dvr->generate_nfo_files;
            $data['dvr_enable_comskip'] = $dvr->enable_comskip;
            $data['dvr_include_disabled_channels'] = $dvr->include_disabled_channels;
            $data['dvr_default_series_mode'] = $dvr->default_series_mode?->value ?? DvrSeriesMode::UniqueSe->value;
            $data['dvr_default_series_keep_last'] = $dvr->default_series_keep_last;
        } else {
            $data['dvr_enabled'] = false;
            $data['dvr_output_format'] = 'ts';
            $data['dvr_enable_metadata_enrichment'] = true;
            $data['dvr_generate_nfo_files'] = false;
            $data['dvr_enable_comskip'] = false;
            $data['dvr_include_disabled_channels'] = false;
            $data['dvr_default_series_mode'] = DvrSeriesMode::UniqueSe->value;
            $data['dvr_default_series_keep_last'] = null;
        }

        $data['request_enabled'] = $record->requestSetting?->enabled ?? false;

        return $data;
    }

    /**
     * Strip dvr_/request_ prefixed fields so Filament doesn't try to save them
     * to the playlist table itself.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripDvrAndRequestFormData(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'dvr_') || str_starts_with($key, 'request_')) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Save dvr_/request_ prefixed fields back to their respective owned relations.
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveDvrAndRequestFormData(Playlist|CustomPlaylist|MergedPlaylist $record, array $data): void
    {
        if (isset($data['dvr_enabled'])) {
            DvrSetting::updateOrCreate(
                DvrSetting::ownerAttributes($record),
                [
                    'user_id' => $record->user_id,
                    'enabled' => $data['dvr_enabled'] ?? false,
                    'use_proxy' => true,
                    'dvr_output_format' => $data['dvr_output_format'] ?? 'ts',
                    'max_concurrent_recordings' => $data['dvr_max_concurrent_recordings'] ?? 2,
                    'default_start_early_seconds' => $data['dvr_default_start_early_seconds'] ?? 30,
                    'default_end_late_seconds' => $data['dvr_default_end_late_seconds'] ?? 60,
                    'retention_days' => $data['dvr_retention_days'] ?? 0,
                    'global_disk_quota_gb' => $data['dvr_global_disk_quota_gb'] ?? 0,
                    'enable_metadata_enrichment' => $data['dvr_enable_metadata_enrichment'] ?? true,
                    'generate_nfo_files' => $data['dvr_generate_nfo_files'] ?? false,
                    'enable_comskip' => $data['dvr_enable_comskip'] ?? false,
                    'include_disabled_channels' => $data['dvr_include_disabled_channels'] ?? false,
                    'default_series_mode' => $data['dvr_default_series_mode'] ?? DvrSeriesMode::UniqueSe->value,
                    'default_series_keep_last' => ($data['dvr_default_series_keep_last'] > 0) ? $data['dvr_default_series_keep_last'] : null,
                ]
            );
        }

        if (isset($data['request_enabled'])) {
            PlaylistRequestSetting::updateOrCreate(
                PlaylistRequestSetting::ownerAttributes($record),
                [
                    'user_id' => $record->user_id,
                    'enabled' => $data['request_enabled'] ?? false,
                ]
            );
        }
    }
}
