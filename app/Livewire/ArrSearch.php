<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Shared Sonarr/Radarr search & request UI for admin and guest panels.
 * Renders a search box, result grid, and queue panel with optional polling.
 */
class ArrSearch extends Component
{
    public ?int $integrationId = null;

    public string $searchTerm = '';

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    public bool $isSearching = false;

    public bool $guestMode = false;

    /** @var array<int, array<string, mixed>> */
    public array $queue = [];

    public bool $queuePolling = true;

    public function mount(?int $integrationId = null, bool $guestMode = false): void
    {
        $this->integrationId = $integrationId;
        $this->guestMode = $guestMode;

        $this->loadQueue();
    }

    public function getIntegrationProperty(): ?ArrIntegration
    {
        if (! $this->integrationId) {
            return null;
        }

        return ArrIntegration::find($this->integrationId);
    }

    /**
     * Re-fetch the queue when the active integration changes.
     */
    public function updatedIntegrationId(): void
    {
        $this->results = [];
        $this->searchTerm = '';
        $this->loadQueue();
    }

    /**
     * Debounced search trigger.
     */
    public function updatedSearchTerm(): void
    {
        $this->search();
    }

    public function search(): void
    {
        $integration = $this->integration;
        if (! $integration || strlen(trim($this->searchTerm)) < 2) {
            $this->results = [];

            return;
        }

        $this->isSearching = true;

        try {
            $this->results = ArrService::make($integration)->search($this->searchTerm);
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title(__('Search Failed'))
                ->body($e->getMessage())
                ->send();

            $this->results = [];
        }

        $this->isSearching = false;
    }

    /**
     * Request a single result. Honours integration defaults if no override passed.
     *
     * @param  int  $index  index into $this->results
     */
    public function request(int $index, ?int $qualityProfileId = null, ?string $rootFolderPath = null): void
    {
        $integration = $this->integration;
        if (! $integration) {
            return;
        }

        if (! isset($this->results[$index])) {
            return;
        }

        $item = $this->results[$index];
        $isSonarr = $integration->isSonarr();

        $payload = [
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'qualityProfileId' => $qualityProfileId ?? $integration->quality_profile_id,
            'rootFolderPath' => $rootFolderPath ?? $integration->root_folder_path,
            'searchForMissingEpisodes' => true,
            'searchForMovie' => true,
        ];

        // External ID key differs per platform
        $externalKey = $isSonarr ? 'tvdbId' : 'tmdbId';
        $payload[$externalKey] = $item[$externalKey] ?? null;

        $result = ArrService::make($integration)->add($payload);

        if ($result['ok']) {
            Notification::make()
                ->success()
                ->title(__('Request Submitted'))
                ->body(__(':title has been added to :server and will search for releases.', [
                    'title' => $item['title'] ?? 'Content',
                    'server' => $integration->name,
                ]))
                ->send();

            // Refresh queue shortly after to show the new download
            $this->loadQueue();
        } else {
            Notification::make()
                ->danger()
                ->title(__('Request Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }
    }

    public function loadQueue(): void
    {
        $integration = $this->integration;
        if (! $integration) {
            $this->queue = [];

            return;
        }

        try {
            $this->queue = ArrService::make($integration)->fetchQueue();
        } catch (\Exception $e) {
            $this->queue = [];
        }
    }

    public function getQueuePollIntervalProperty(): int
    {
        return $this->queuePolling ? 5 : 0;
    }

    public function render()
    {
        return view('livewire.arr-search');
    }
}
