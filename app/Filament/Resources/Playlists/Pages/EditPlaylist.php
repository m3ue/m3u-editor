<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use App\Models\DvrSetting;
use App\Models\Playlist;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPlaylist extends EditRecord
{
    // use EditRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Playlist $playlist */
        $playlist = $this->getRecord();

        // If this playlist belongs to a media server integration, redirect to edit that instead
        if ($integration = $playlist->mediaServerIntegration) {
            $this->redirect(MediaServerIntegrationResource::getUrl('edit', ['record' => $integration->id]));

            return;
        }

        // If this playlist has networks (is a network playlist), redirect to the networks list
        if ($playlist->networks()->exists()) {
            $this->redirect(NetworkResource::getUrl('index'));

            return;
        }
    }

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label(__('View Playlist'))
                ->icon('heroicon-m-eye')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('view'));
                }),
            ...PlaylistResource::getHeaderActions(),
        ];
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }

    /**
     * Populate dvr_ prefixed fields from the dvrSetting HasOne relationship.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Playlist $record */
        $record = $this->getRecord();
        $dvr = $record->dvrSetting;

        if ($dvr) {
            $data['dvr_enabled'] = $dvr->enabled;
            $data['dvr_use_proxy'] = $dvr->use_proxy;
            $data['dvr_ffmpeg_path'] = $dvr->ffmpeg_path;
            $data['dvr_storage_path'] = $dvr->storage_path;
            $data['dvr_max_concurrent_recordings'] = $dvr->max_concurrent_recordings;
            $data['dvr_default_start_early_seconds'] = $dvr->default_start_early_seconds;
            $data['dvr_default_end_late_seconds'] = $dvr->default_end_late_seconds;
            $data['dvr_retention_days'] = $dvr->retention_days;
            $data['dvr_global_disk_quota_gb'] = $dvr->global_disk_quota_gb;
            $data['dvr_enable_metadata_enrichment'] = $dvr->enable_metadata_enrichment;
            $data['dvr_tmdb_api_key'] = $dvr->tmdb_api_key;
        } else {
            $data['dvr_enabled'] = false;
            $data['dvr_use_proxy'] = false;
            $data['dvr_enable_metadata_enrichment'] = true;
        }

        return $data;
    }

    /**
     * Strip dvr_ prefixed fields so Filament doesn't try to save them to the playlists table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (str_starts_with($key, 'dvr_')) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Save dvr_ prefixed fields back to the dvrSetting HasOne relationship.
     */
    protected function afterSave(): void
    {
        /** @var Playlist $record */
        $record = $this->getRecord();
        $data = $this->form->getRawState();

        if (! isset($data['dvr_enabled'])) {
            return;
        }

        DvrSetting::updateOrCreate(
            ['playlist_id' => $record->id],
            [
                'user_id' => $record->user_id,
                'enabled' => $data['dvr_enabled'] ?? false,
                'use_proxy' => $data['dvr_use_proxy'] ?? false,
                'ffmpeg_path' => $data['dvr_ffmpeg_path'] ?? null,
                'storage_path' => $data['dvr_storage_path'] ?? null,
                'max_concurrent_recordings' => $data['dvr_max_concurrent_recordings'] ?? 2,
                'default_start_early_seconds' => $data['dvr_default_start_early_seconds'] ?? 30,
                'default_end_late_seconds' => $data['dvr_default_end_late_seconds'] ?? 60,
                'retention_days' => $data['dvr_retention_days'] ?? 0,
                'global_disk_quota_gb' => $data['dvr_global_disk_quota_gb'] ?? 0,
                'enable_metadata_enrichment' => $data['dvr_enable_metadata_enrichment'] ?? true,
                'tmdb_api_key' => $data['dvr_tmdb_api_key'] ?? null,
            ]
        );
    }
}
