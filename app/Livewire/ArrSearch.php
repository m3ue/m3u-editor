<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use App\Services\Arr\SonarrService;
use App\Services\TmdbService;
use App\Services\TvMazeService;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Unified Sonarr/Radarr search & request UI for admin and guest panels.
 * Searches all enabled integrations simultaneously; content type (TV/movie)
 * is derived from the integration that returned each result.
 *
 * Also provides a TMDB-powered discover/browse mode when TMDB is configured.
 */
class ArrSearch extends Component
{
    /**
     * Guest-mode: restrict search to these specific integration IDs.
     *
     * @var array<int>
     */
    public array $guestIntegrationIds = [];

    public string $searchTerm = '';

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    public bool $isSearching = false;

    public bool $guestMode = false;

    /** @var array<int, array<string, mixed>> */
    public array $queue = [];

    public bool $queuePolling = false;

    public bool $showDetail = false;

    /** @var array<string, mixed>|null */
    public ?array $detailResult = null;

    public ?int $detailIndex = null;

    public ?int $detailIntegrationId = null;

    /** @var array<int, bool> keyed by seasonNumber */
    public array $selectedSeasons = [];

    /**
     * Episodes fetched from TV Maze, keyed by seasonNumber.
     *
     * @var array<int, array<int, array{seasonNumber: int, episodeNumber: int, title: string, airDate: ?string, overview: ?string}>>
     */
    public array $detailEpisodes = [];

    /**
     * Cast fetched from TV Maze.
     *
     * @var array<int, array{actor: string, character: string, photo: ?string}>
     */
    public array $detailCast = [];

    /**
     * Interactive search releases for the currently open detail panel.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $detailReleases = [];

    /**
     * Per-episode hasFile status fetched from Sonarr for an in-library series.
     * Keyed seasonNumber => [episodeNumber => hasFile].
     *
     * @var array<int, array<int, bool>>
     */
    public array $detailSonarrEpisodeStatus = [];

    /**
     * Per-episode file quality and size, keyed seasonNumber => [episodeNumber => {quality, size}].
     * Only populated for episodes that have a file and Sonarr embeds episodeFile in the response.
     *
     * @var array<int, array<int, array{quality: ?string, size: ?int}>>
     */
    public array $detailSonarrEpisodeFileInfo = [];

    public bool $releasesLoading = false;

    /** Sonarr episode ID when the interactive search accordion is showing episode-level results. */
    public ?int $detailEpisodeId = null;

    /** Season number for the episode-level search context, used to re-run on Refresh. */
    public ?int $detailEpisodeSeason = null;

    /** Episode number for the episode-level search context, used to re-run on Refresh. */
    public ?int $detailEpisodeNumber = null;

    /** Human-readable label shown in the accordion header when in episode context, e.g. "S01E05". */
    public ?string $detailReleasesLabel = null;

    // ── Discover ──────────────────────────────────────────────────────────────

    public bool $tmdbConfigured = false;

    public function mount(array $guestIntegrationIds = [], bool $guestMode = false): void
    {
        $this->guestIntegrationIds = $guestIntegrationIds;
        $this->guestMode = $guestMode;
        $this->queuePolling = $guestMode;
        $this->tmdbConfigured = app(TmdbService::class)->isConfigured();

        if ($guestMode) {
            $this->loadQueue();
        }
    }

    /**
     * Integration used by the currently open detail panel.
     */
    public function getDetailIntegrationProperty(): ?ArrIntegration
    {
        if (! $this->detailIntegrationId) {
            return null;
        }

        return ArrIntegration::find($this->detailIntegrationId);
    }

    /**
     * Integrations to search.
     * Admin: all enabled integrations for the authenticated user.
     * Guest: only integrations in $guestIntegrationIds.
     *
     * @return Collection<int, ArrIntegration>
     */
    public function getIntegrationsForSearchProperty(): Collection
    {
        if ($this->guestMode) {
            if (empty($this->guestIntegrationIds)) {
                return collect();
            }

            return ArrIntegration::whereIn('id', $this->guestIntegrationIds)
                ->where('enabled', true)
                ->orderBy('name')
                ->get();
        }

        return ArrIntegration::query()
            ->where('user_id', auth()->id())
            ->where('enabled', true)
            ->orderBy('name')
            ->get();
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function search(): void
    {
        if (strlen(trim($this->searchTerm)) < 2) {
            $this->results = [];

            return;
        }

        $this->isSearching = true;
        $this->results = [];

        $integrations = $this->integrationsForSearch;

        if ($integrations->isEmpty()) {
            $this->isSearching = false;

            return;
        }

        $services = $integrations->mapWithKeys(
            fn ($i) => [(string) $i->id => ArrService::make($i)]
        );

        try {
            $responses = Http::pool(function (Pool $pool) use ($integrations, $services) {
                foreach ($integrations as $integration) {
                    $pool->as((string) $integration->id)
                        ->withHeaders(['X-Api-Key' => $integration->api_key])
                        ->timeout(15)
                        ->get(
                            rtrim($integration->base_url, '/').'/api/v3'.$services[(string) $integration->id]->getSearchEndpoint(),
                            ['term' => $this->searchTerm]
                        );
                }
            });
        } catch (\Exception $e) {
            Log::warning('ArrSearch: pool request failed', ['error' => $e->getMessage()]);
            $this->isSearching = false;

            return;
        }

        foreach ($integrations as $integration) {
            $response = $responses[(string) $integration->id] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                Log::warning('ArrSearch: integration search failed', ['integration_id' => $integration->id]);

                continue;
            }

            $items = $services[(string) $integration->id]->parseSearchResponse($response);

            foreach ($items as $item) {
                $this->results[] = array_merge($item, [
                    'integrationId' => $integration->id,
                    'integrationName' => $integration->name,
                    'integrationType' => $integration->type,
                ]);
            }
        }

        $this->isSearching = false;
    }

    /**
     * Request a single result. Routes to the correct integration based on the result.
     */
    public function request(int $index, ?int $qualityProfileId = null, ?string $rootFolderPath = null): void
    {
        if (! isset($this->results[$index])) {
            return;
        }

        $item = $this->results[$index];
        $integration = ArrIntegration::find($item['integrationId'] ?? null);

        if (! $integration) {
            return;
        }

        $isSonarr = $integration->isSonarr();

        $payload = [
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'images' => $item['images'] ?? [],
            'qualityProfileId' => $qualityProfileId ?? $integration->quality_profile_id,
            'rootFolderPath' => $rootFolderPath ?? $integration->root_folder_path,
            'searchForMissingEpisodes' => true,
            'searchForMovie' => true,
        ];

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

            $this->loadQueue();
        } else {
            Notification::make()
                ->danger()
                ->title(__('Request Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }
    }

    public function openDetail(int $index): void
    {
        if (! isset($this->results[$index])) {
            return;
        }

        $this->detailIndex = $index;
        $this->detailResult = $this->results[$index];
        $this->detailIntegrationId = (int) ($this->detailResult['integrationId'] ?? 0) ?: null;

        // Pre-check all seasons except specials (season 0)
        $this->selectedSeasons = collect($this->detailResult['seasons'] ?? [])
            ->mapWithKeys(fn ($s) => [(int) $s['seasonNumber'] => (int) $s['seasonNumber'] !== 0])
            ->all();

        $this->detailEpisodes = [];
        $this->detailCast = [];
        $this->detailReleases = [];
        $this->detailEpisodeId = null;
        $this->detailEpisodeSeason = null;
        $this->detailEpisodeNumber = null;
        $this->detailReleasesLabel = null;
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailResult = null;
        $this->detailIndex = null;
        $this->detailIntegrationId = null;
        $this->selectedSeasons = [];
        $this->detailEpisodes = [];
        $this->detailCast = [];
        $this->detailReleases = [];
        $this->detailSonarrEpisodeStatus = [];
        $this->detailSonarrEpisodeFileInfo = [];
        $this->releasesLoading = false;
        $this->detailEpisodeId = null;
        $this->detailEpisodeSeason = null;
        $this->detailEpisodeNumber = null;
        $this->detailReleasesLabel = null;
    }

    /**
     * Fetch episode data from TV Maze for the currently open detail panel.
     * Triggered by Alpine after the panel opens so it doesn't block the slide-over appearing.
     */
    public function loadDetailEpisodes(): void
    {
        if (! $this->detailResult) {
            return;
        }

        if ($this->detailIntegration?->isSonarr()) {
            $tvdbId = (int) ($this->detailResult['tvdbId'] ?? 0);

            if (! $tvdbId) {
                return;
            }

            try {
                $data = app(TvMazeService::class)->fetchSeriesData($tvdbId);
                $this->detailEpisodes = $data['episodes'];
                $this->detailCast = $data['cast'];
            } catch (\Exception) {
                $this->detailEpisodes = [];
                $this->detailCast = [];
            }

            // Fetch authoritative per-episode hasFile status from Sonarr when the
            // series is already in the library — this is the reliable source for
            // download status, not the lookup's statistics fields.
            $libraryId = (int) ($this->detailResult['libraryId'] ?? 0);

            if ($libraryId) {
                $service = ArrService::make($this->detailIntegration);

                if ($service->supportsEpisodes()) {
                    /** @var SonarrService $service */
                    try {
                        $episodeData = $service->fetchEpisodeData($libraryId);
                        $this->detailSonarrEpisodeStatus = $episodeData['status'];
                        $this->detailSonarrEpisodeFileInfo = $episodeData['fileInfo'];
                    } catch (\Exception) {
                        $this->detailSonarrEpisodeStatus = [];
                        $this->detailSonarrEpisodeFileInfo = [];
                    }
                }
            }

            return;
        }

        if ($this->detailIntegration?->isRadarr()) {
            $tmdbId = (int) ($this->detailResult['tmdbId'] ?? 0);

            if (! $tmdbId) {
                return;
            }

            $this->detailCast = app(TmdbService::class)->getMovieCast($tmdbId);
        }
    }

    /**
     * Trigger an automatic search in Sonarr/Radarr for a library item.
     * Admin only — not available in guest mode.
     */
    public function triggerAutomaticSearch(): void
    {
        if ($this->guestMode || ! $this->detailResult || ! $this->detailIntegration) {
            return;
        }

        $libraryId = $this->resolveLibraryId();

        if (! $libraryId) {
            Notification::make()
                ->warning()
                ->title(__('Not in Library'))
                ->body(__('This title must be added to the library before triggering a search.'))
                ->send();

            return;
        }

        $result = ArrService::make($this->detailIntegration)->triggerAutomaticSearch($libraryId);

        if ($result['ok']) {
            Notification::make()
                ->success()
                ->title(__('Search Triggered'))
                ->body(__('Sonarr/Radarr is now searching for releases of :title.', [
                    'title' => $this->detailResult['title'] ?? 'this title',
                ]))
                ->send();
        } else {
            Notification::make()
                ->danger()
                ->title(__('Search Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }
    }

    /**
     * Fetch interactive search releases for the currently open detail panel.
     * Admin only — not available in guest mode.
     */
    public function loadDetailReleases(): void
    {
        if ($this->guestMode || ! $this->detailResult || ! $this->detailIntegration) {
            return;
        }

        // If in episode context, refresh the episode-specific releases instead.
        if ($this->detailEpisodeSeason !== null && $this->detailEpisodeNumber !== null) {
            $this->loadEpisodeReleases($this->detailEpisodeSeason, $this->detailEpisodeNumber);

            return;
        }

        // Series/movie level search — clear any stale episode context.
        $this->detailEpisodeId = null;
        $this->detailEpisodeSeason = null;
        $this->detailEpisodeNumber = null;
        $this->detailReleasesLabel = null;

        $libraryId = $this->resolveLibraryId();

        if (! $libraryId) {
            Notification::make()
                ->warning()
                ->title(__('Not in Library'))
                ->body(__('Add this title to the library first to perform an interactive search.'))
                ->send();

            return;
        }

        $this->releasesLoading = true;
        $this->detailReleases = [];

        try {
            $this->detailReleases = ArrService::make($this->detailIntegration)->fetchReleases($libraryId);
        } catch (\Exception) {
            Notification::make()
                ->danger()
                ->title(__('Search Failed'))
                ->body(__('Could not fetch releases from the indexer.'))
                ->send();
        }

        $this->releasesLoading = false;
    }

    /**
     * Load interactive search releases for a specific episode (Sonarr only).
     * Adds the series to Sonarr without searching if it is not yet in the library,
     * then fetches episode-level releases via /release?seriesId=X&episodeId=Y.
     * Admin only — not available in guest mode.
     */
    public function loadEpisodeReleases(int $seasonNumber, int $episodeNumber): void
    {
        if ($this->guestMode || ! $this->detailResult || ! $this->detailIntegration) {
            return;
        }

        if (! $this->detailIntegration->isSonarr()) {
            return;
        }

        $tvdbId = (int) ($this->detailResult['tvdbId'] ?? 0);

        if (! $tvdbId) {
            return;
        }

        $this->releasesLoading = true;
        $this->detailReleases = [];
        $this->detailEpisodeId = null;

        $libraryId = $this->resolveLibraryId();
        $seriesJustAdded = false;

        if (! $libraryId) {
            $item = $this->detailResult;
            $seasons = collect($item['seasons'] ?? [])
                ->map(fn ($s) => ['seasonNumber' => (int) $s['seasonNumber'], 'monitored' => false])
                ->all();

            $result = ArrService::make($this->detailIntegration)->add([
                'tvdbId' => $tvdbId,
                'title' => $item['title'] ?? null,
                'titleSlug' => $item['titleSlug'] ?? null,
                'seasons' => $seasons,
                'searchForMissingEpisodes' => false,
            ]);

            if (! $result['ok']) {
                Notification::make()
                    ->danger()
                    ->title(__('Failed to Add Series'))
                    ->body($result['error'] ?? 'Unknown error')
                    ->send();

                $this->releasesLoading = false;

                return;
            }

            $libraryId = (int) ($result['data']['id'] ?? 0);
            $seriesJustAdded = true;

            if ($libraryId) {
                $this->detailResult['libraryId'] = $libraryId;
                $this->detailResult['existsInLibrary'] = true;

                if ($this->detailIndex !== null && isset($this->results[$this->detailIndex])) {
                    $this->results[$this->detailIndex]['libraryId'] = $libraryId;
                    $this->results[$this->detailIndex]['existsInLibrary'] = true;
                }
            }
        }

        if (! $libraryId) {
            Notification::make()
                ->danger()
                ->title(__('Could Not Add Series'))
                ->body(__('Unable to resolve the series in Sonarr.'))
                ->send();

            $this->releasesLoading = false;

            return;
        }

        try {
            /** @var SonarrService $service */
            $service = ArrService::make($this->detailIntegration);
            $episodeId = $service->resolveEpisodeId($libraryId, $seasonNumber, $episodeNumber, $seriesJustAdded);

            if (! $episodeId) {
                Notification::make()
                    ->warning()
                    ->title(__('Episode Not Ready'))
                    ->body(__('Sonarr is still indexing the series. Please try again in a moment.'))
                    ->send();

                $this->releasesLoading = false;

                return;
            }

            $this->detailEpisodeId = $episodeId;
            $this->detailEpisodeSeason = $seasonNumber;
            $this->detailEpisodeNumber = $episodeNumber;
            $s = str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT);
            $e = str_pad((string) $episodeNumber, 2, '0', STR_PAD_LEFT);
            $this->detailReleasesLabel = "S{$s}E{$e}";

            $this->detailReleases = $service->fetchEpisodeReleases($libraryId, $episodeId);
        } catch (\Exception) {
            Notification::make()
                ->danger()
                ->title(__('Search Failed'))
                ->body(__('Could not fetch episode releases from the indexer.'))
                ->send();
        }

        $this->releasesLoading = false;
    }

    /**
     * Add a Radarr movie to the library without triggering an automatic search,
     * then immediately open the interactive search so the user can pick a specific release.
     * Admin only — not available in guest mode.
     */
    public function addForInteractiveSearch(): void
    {
        if ($this->guestMode || ! $this->detailResult || ! $this->detailIntegration) {
            return;
        }

        if (! $this->detailIntegration->isRadarr()) {
            return;
        }

        $item = $this->detailResult;

        $result = ArrService::make($this->detailIntegration)->add([
            'tmdbId' => $item['tmdbId'] ?? null,
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'images' => $item['images'] ?? [],
            'qualityProfileId' => $this->detailIntegration->quality_profile_id,
            'rootFolderPath' => $this->detailIntegration->root_folder_path,
            'minimumAvailability' => 'released',
            'searchForMovie' => false,
        ]);

        if (! $result['ok']) {
            Notification::make()
                ->danger()
                ->title(__('Failed to Add Movie'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();

            return;
        }

        // Update local state so resolveLibraryId() finds the new ID without a round-trip
        $newId = $result['data']['id'] ?? null;

        if ($newId) {
            $this->detailResult['libraryId'] = (int) $newId;
            $this->detailResult['existsInLibrary'] = true;

            if ($this->detailIndex !== null && isset($this->results[$this->detailIndex])) {
                $this->results[$this->detailIndex]['libraryId'] = (int) $newId;
                $this->results[$this->detailIndex]['existsInLibrary'] = true;
            }
        }

        $this->loadDetailReleases();
    }

    /**
     * Download a specific release selected from the interactive search.
     * Admin only — not available in guest mode.
     */
    public function downloadDetailRelease(string $guid, int $indexerId): void
    {
        if ($this->guestMode || ! $this->detailResult || ! $this->detailIntegration) {
            return;
        }

        $libraryId = $this->resolveLibraryId();

        if (! $libraryId) {
            return;
        }

        $isSonarr = $this->detailIntegration->isSonarr();

        $payload = [
            'guid' => $guid,
            'indexerId' => $indexerId,
            $isSonarr ? 'seriesId' : 'movieId' => $libraryId,
        ];

        if ($isSonarr && $this->detailEpisodeId !== null) {
            $payload['episodeId'] = $this->detailEpisodeId;
        }

        $result = ArrService::make($this->detailIntegration)->downloadRelease($payload);

        if ($result['ok']) {
            Notification::make()
                ->success()
                ->title(__('Download Started'))
                ->body(__('The release has been sent to your download client.'))
                ->send();

            $this->detailReleases = [];
        } else {
            Notification::make()
                ->danger()
                ->title(__('Download Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }
    }

    /**
     * Resolve the Sonarr/Radarr internal library ID for the current detail result.
     * Uses the cached libraryId from the search result, falling back to checkExists().
     */
    private function resolveLibraryId(): ?int
    {
        if (! $this->detailResult || ! $this->detailIntegration) {
            return null;
        }

        $libraryId = $this->detailResult['libraryId'] ?? null;

        if ($libraryId) {
            return (int) $libraryId;
        }

        $isSonarr = $this->detailIntegration->isSonarr();
        $externalId = $isSonarr
            ? (int) ($this->detailResult['tvdbId'] ?? 0)
            : (int) ($this->detailResult['tmdbId'] ?? 0);

        if (! $externalId) {
            return null;
        }

        try {
            $check = ArrService::make($this->detailIntegration)->checkExists($externalId);

            return $check['exists'] ? ($check['id'] ?? null) : null;
        } catch (\Exception) {
            return null;
        }
    }

    public function toggleAllSeasons(bool $checked): void
    {
        $this->selectedSeasons = collect($this->selectedSeasons)
            ->map(fn () => $checked)
            ->all();
    }

    /**
     * Request a single episode from the detail panel (Sonarr only).
     * Adds the series with all seasons unmonitored if it is not yet in the library,
     * then monitors and searches the specific episode.
     */
    public function requestEpisode(int $seasonNumber, int $episodeNumber): void
    {
        $integration = $this->detailIntegration;

        if (! $integration || ! $this->detailResult || ! $integration->isSonarr()) {
            return;
        }

        $tvdbId = (int) ($this->detailResult['tvdbId'] ?? 0);

        if (! $tvdbId) {
            return;
        }

        /** @var SonarrService $sonarrService */
        $sonarrService = ArrService::make($integration);
        $result = $sonarrService->requestEpisode(
            $tvdbId,
            $seasonNumber,
            $episodeNumber,
            [
                'qualityProfileId' => $integration->quality_profile_id,
                'rootFolderPath' => $integration->root_folder_path,
            ]
        );

        if ($result['queued'] ?? false) {
            // Series was just added — Sonarr is still indexing episodes.
            // A queued job will monitor + search once they're available.
            Notification::make()
                ->info()
                ->title(__('Series Added — Episode Queued'))
                ->body(__('":title" was added to Sonarr. S:s E:e will be queued for download once indexing completes.', [
                    'title' => $this->detailResult['title'] ?? 'the show',
                    's' => str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT),
                    'e' => str_pad((string) $episodeNumber, 2, '0', STR_PAD_LEFT),
                ]))
                ->send();
        } elseif ($result['ok'] ?? false) {
            Notification::make()
                ->success()
                ->title(__('Episode Requested'))
                ->body(__('S:s E:e of ":title" has been queued for download.', [
                    's' => str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT),
                    'e' => str_pad((string) $episodeNumber, 2, '0', STR_PAD_LEFT),
                    'title' => $this->detailResult['title'] ?? 'the show',
                ]))
                ->send();

            $this->loadQueue();
        } else {
            Notification::make()
                ->danger()
                ->title(__('Request Failed'))
                ->body($result['error'] ?? 'Unknown error')
                ->send();
        }
    }

    /**
     * Request a Sonarr series from the detail panel with season-level granularity.
     * Sends all seasons from the lookup result, each with an explicit monitored flag.
     */
    public function requestDetail(): void
    {
        $integration = $this->detailIntegration;
        if (! $integration || ! $this->detailResult || ! $integration->isSonarr()) {
            return;
        }

        // Build full seasons array with explicit monitored flag per season
        $seasons = collect($this->detailResult['seasons'] ?? [])
            ->map(fn ($s) => [
                'seasonNumber' => (int) $s['seasonNumber'],
                'monitored' => (bool) ($this->selectedSeasons[(int) $s['seasonNumber']] ?? false),
            ])
            ->values()
            ->all();

        $monitoredCount = collect($seasons)->where('monitored', true)->count();

        if ($monitoredCount === 0) {
            Notification::make()
                ->warning()
                ->title(__('No Seasons Selected'))
                ->body(__('Please select at least one season to request.'))
                ->send();

            return;
        }

        $item = $this->detailResult;

        $payload = [
            'tvdbId' => $item['tvdbId'] ?? null,
            'title' => $item['title'] ?? null,
            'titleSlug' => $item['titleSlug'] ?? null,
            'qualityProfileId' => $integration->quality_profile_id,
            'rootFolderPath' => $integration->root_folder_path,
            'seasons' => $seasons,
            'searchForMissingEpisodes' => true,
        ];

        $result = ArrService::make($integration)->add($payload);

        if ($result['ok']) {
            Notification::make()
                ->success()
                ->title(__('Request Submitted'))
                ->body(__(':title has been added to :server and will begin searching for :count selected season(s).', [
                    'title' => $item['title'] ?? 'Content',
                    'server' => $integration->name,
                    'count' => $monitoredCount,
                ]))
                ->send();

            $this->closeDetail();
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
        if (! $this->guestMode) {
            $this->dispatch('refreshArrQueue');

            return;
        }

        $allItems = [];

        foreach ($this->integrationsForSearch as $integration) {
            try {
                $items = ArrService::make($integration)->fetchQueue();
                foreach ($items as $item) {
                    $allItems[] = array_merge($item, ['server' => $integration->name]);
                }
            } catch (\Exception) {
                // Skip failed integrations silently
            }
        }

        $this->queue = $allItems;
    }

    public function getQueuePollIntervalProperty(): int
    {
        return $this->queuePolling ? 5 : 0;
    }

    // ── Discover ──────────────────────────────────────────────────────────────

    #[On('request-from-discover')]
    public function requestFromDiscover(int $tmdbId, string $mediaType): void
    {
        $resolved = $this->resolveDiscoverToArrResult($tmdbId, $mediaType);

        if (! $resolved) {
            Notification::make()
                ->warning()
                ->title(__('Not Found'))
                ->body(__('Could not find this title on your Sonarr/Radarr servers. Try searching for it directly.'))
                ->send();

            return;
        }

        // Append to results so the existing openDetail()/request() machinery can use the index
        $idx = count($this->results);
        $this->results[$idx] = $resolved;
        $this->openDetail($idx);
    }

    /**
     * Resolve a TMDB discover item to an Arr-native result shape.
     * Movies go through Radarr's tmdb: lookup; TV goes through TMDB external IDs → Sonarr tvdb: lookup.
     *
     * @return array<string, mixed>|null
     */
    public function resolveDiscoverToArrResult(int $tmdbId, string $mediaType): ?array
    {
        $integrations = $this->integrationsForSearch;

        if ($mediaType === 'movie') {
            $radarr = $integrations->first(fn ($i) => $i->isRadarr());

            if (! $radarr) {
                return null;
            }

            $items = ArrService::make($radarr)->search("tmdb:{$tmdbId}");
            $item = $items[0] ?? null;

            if (! $item) {
                return null;
            }

            return array_merge($item, [
                'integrationId' => $radarr->id,
                'integrationName' => $radarr->name,
                'integrationType' => 'radarr',
            ]);
        }

        // TV: TMDB → TVDB → Sonarr
        $sonarr = $integrations->first(fn ($i) => $i->isSonarr());

        if (! $sonarr) {
            return null;
        }

        $externalIds = app(TmdbService::class)->getTvExternalIds($tmdbId);
        $tvdbId = $externalIds['tvdb_id'] ?? null;

        if (! $tvdbId) {
            return null;
        }

        $items = ArrService::make($sonarr)->search("tvdb:{$tvdbId}");
        $item = $items[0] ?? null;

        if (! $item) {
            return null;
        }

        return array_merge($item, [
            'integrationId' => $sonarr->id,
            'integrationName' => $sonarr->name,
            'integrationType' => 'sonarr',
        ]);
    }

    public function render()
    {
        return view('livewire.arr-search');
    }
}
