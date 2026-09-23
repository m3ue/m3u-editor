<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Concerns\HasDvrAndRequestFormHooks;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use App\Models\Playlist;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPlaylist extends EditRecord
{
    // use EditRecord\Concerns\HasWizard;
    use HasDvrAndRequestFormHooks;

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
        if ($playlist->networks()->exists() && NetworkResource::canAccess()) {
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
     * Populate dvr_/request_ prefixed fields from their owned relations.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Playlist $record */
        $record = $this->getRecord();

        return $this->fillDvrAndRequestFormData($data, $record);
    }

    /**
     * Strip dvr_/request_ prefixed fields so Filament doesn't try to save them to the playlists table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = $this->stripDvrAndRequestFormData($data);

        if (! auth()->user()?->canUseProviderAuthPassthrough()) {
            unset(
                $data['provider_auth_passthrough'],
                $data['provider_auth_passthrough_live'],
                $data['provider_auth_passthrough_vod'],
                $data['provider_auth_passthrough_series']
            );
        } elseif (! ($data['xtream'] ?? false)) {
            $data['provider_auth_passthrough'] = false;
        }

        return $data;
    }

    /**
     * Save dvr_/request_ prefixed fields back to their respective owned relations.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record->provider_auth_passthrough) {
            Playlist::query()
                ->whereKeyNot($record->getKey())
                ->where('provider_auth_passthrough', true)
                ->update([
                    'provider_auth_passthrough' => false,
                ]);
        }

        $this->saveDvrAndRequestFormData($record, $this->form->getRawState());
    }
}
