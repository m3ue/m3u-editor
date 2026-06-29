<?php

namespace App\Livewire;

use App\Jobs\MonitorArrSearch;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\PlaylistAuth;
use App\Services\Arr\ArrService;
use App\Services\Arr\SonarrService;
use App\Services\TmdbService;
use App\Services\TvMazeService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Unified Sonarr/Radarr search & request UI for admin and guest panels.
 * Searches all enabled integrations simultaneously; content type (TV/movie)
 * is derived from the integration that returned each result.
 *
 * Also provides a TMDB-powered discover/browse mode when TMDB is configured.
 */
class ArrSearch extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * Guest-mode: restrict search to these specific integration IDs.
     *
     * @var array<int>
     */
    public array $guestIntegrationIds = [];

    /** Guest-mode: the PlaylistAuth ID for the authenticated guest. */
    public ?int $playlistAuthId = null;

    /**
     * Resolved once at mount from PlaylistAuth. True = forward directly to Arr,
     * false = hold for admin approval. Null when not in guest mode.
     */
    public ?bool $autoApproveRequests = null;

    public string $searchTerm = '';

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /**
     * Active genre filters. Empty means "show all".
     * Uses OR logic — a result is shown if it matches any selected genre.
     *
     * @var array<string>
     */
    public array $selectedGenres = [];

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

    public bool $autoOpen = false;

    /**
     * When true, suppress the visible search/results UI and only render the hidden detail slide-over.
     * Used by filmography pages that embed ArrSearch solely to handle filmography item clicks.
     */
    public bool $detailOnly = false;

    public function mount(array $guestIntegrationIds = [], bool $guestMode = false, bool $detailOnly = false, ?string $q = null, ?int $playlistAuthId = null): void
    {
        $this->guestIntegrationIds = $guestIntegrationIds;
        $this->guestMode = $guestMode;
        $this->playlistAuthId = $playlistAuthId;
        $this->detailOnly = $detailOnly;
        $this->queuePolling = $guestMode && ! $detailOnly;
        $this->tmdbConfigured = app(TmdbService::class)->isConfigured();

        if ($guestMode && $playlistAuthId) {
            $this->autoApproveRequests = PlaylistAuth::find($playlistAuthId)?->auto_approve_requests ?? false;
        }

        if ($q !== null && $q !== '') {
            $this->searchTerm = $q;
            $this->autoOpen = true;
            // Auto-trigger search so results are ready when the user lands on the page
            // (e.g. from a filmography click). The search will re-render via Livewire.
            $this->search();
        }

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

    // ── Genre filter ──────────────────────────────────────────────────────────

    /**
     * Toggle a genre on/off in the active filter.
     */
    public function toggleGenre(string $genre): void
    {
        if (in_array($genre, $this->selectedGenres, true)) {
            $this->selectedGenres = array_values(
                array_filter($this->selectedGenres, fn ($g) => $g !== $genre)
            );
        } else {
            $this->selectedGenres[] = $genre;
        }
    }

    /**
     * Sorted unique genres derived from the current result set.
     *
     * @return array<string>
     */
    public function getAvailableGenresProperty(): array
    {
        return collect($this->results)
            ->flatMap(fn ($r) => $r['genres'] ?? [])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Results filtered by the active genres (OR logic).
     * Preserves original array keys so openDetail($index)/request($index) remain valid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFilteredResultsProperty(): array
    {
        if (empty($this->selectedGenres)) {
            return $this->results;
        }

        return array_filter(
            $this->results,
            fn ($r) => ! empty(array_intersect($r['genres'] ?? [], $this->selectedGenres))
        );
    }

    // ── Guest request queuing ─────────────────────────────────────────────────

    /**
     * Write a pending MediaRequest row for a guest submission and notify.
     * Returns true when the request was queued (caller should return early).
     * Returns false when auto-approve is on or the guest context is missing (caller proceeds normally).
     *
     * @param  array<string, mixed>  $data  Fields for MediaRequest::create().
     */
    private function queueGuestRequest(array $data, string $notificationBody): bool
    {
        if (! $this->guestMode || ! $this->playlistAuthId || $this->autoApproveRequests) {
            return false;
        }

        MediaRequest::create(array_merge($data, [
            'playlist_auth_id' => $this->playlistAuthId,
            'status' => 'pending',
            'requested_at' => now(),
        ]));

        Notification::make()
            ->info()
            ->title(__('Request Submitted'))
            ->body($notificationBody)
            ->send();

        return true;
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function clearSearch(): void
    {
        $this->searchTerm = '';
        $this->results = [];
        $this->selectedGenres = [];
        $this->isSearching = false;
    }

    public function search(): void
    {
        if (strlen(trim($this->searchTerm)) < 2) {
            $this->results = [];
            $this->selectedGenres = [];

            return;
        }

        $this->isSearching = true;
        $this->results = [];
        $this->selectedGenres = [];

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

        // When arriving from a filmography click (autoOpen flag), immediately
        // open the first result's detail side-sheet so the user doesn't have
        // to click twice. Only fires once per mount — flag is cleared after.
        if ($this->autoOpen && count($this->results) > 0) {
            $this->autoOpen = false;
            $this->openDetail(0);
        }
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

        if ($this->queueGuestRequest(
            [
                'arr_integration_id' => $integration->id,
                'title' => $item['title'] ?? 'Unknown',
                'external_id' => (string) ($payload[$externalKey] ?? ''),
                'request_type' => $isSonarr ? 'series' : 'movie',
                'payload' => $payload,
            ],
            __(':title has been submitted and is awaiting admin approval.', [
                'title' => $item['title'] ?? 'Content',
            ])
        )) {
            return;
        }

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

            $radarrLibraryId = ! $isSonarr ? (int) ($result['data']['id'] ?? 0) : 0;
            if ($radarrLibraryId) {
                MonitorArrSearch::dispatch(
                    $integration->id,
                    $radarrLibraryId,
                    $item['title'] ?? 'Unknown',
                    auth()->id(),
                )->delay(now()->addSeconds(30));
            }

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
        $this->mountAction('showDetail');
    }

    public function showDetailAction(): Action
    {
        return Action::make('showDetail')
            ->slideOver()
            ->modalHeading(false)
            ->modalContent(fn () => view('livewire.partials.arr-detail', [
                'detailResult' => $this->detailResult,
                'detailEpisodes' => $this->detailEpisodes,
                'detailCast' => $this->detailCast,
                'detailReleases' => $this->detailReleases,
                'detailSonarrEpisodeStatus' => $this->detailSonarrEpisodeStatus,
                'detailSonarrEpisodeFileInfo' => $this->detailSonarrEpisodeFileInfo,
                'releasesLoading' => $this->releasesLoading,
                'detailReleasesLabel' => $this->detailReleasesLabel,
                'selectedSeasons' => $this->selectedSeasons,
                'guestMode' => $this->guestMode,
                'detailIndex' => $this->detailIndex,
                'detailIntegration' => $this->detailIntegration,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    public function confirmForceDownloadAction(): Action
    {
        return Action::make('confirmForceDownload')
            ->requiresConfirmation()
            ->modalHeading(__('Force Download?'))
            ->modalDescription(__('This release was rejected by your quality profile. Download anyway?'))
            ->color('danger')
            ->label(__('Download'))
            ->action(fn (array $arguments) => $this->downloadDetailRelease(
                $arguments['guid'] ?? '',
                (int) ($arguments['indexerId'] ?? 0),
            ));
    }

    #[Renderless]
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

            // Fall back to TMDB cast when TVMaze has none (e.g. newer/regional shows not yet indexed)
            if (empty($this->detailCast)) {
                $tmdbFallbackId = (int) ($this->detailResult['resolvedTmdbId'] ?? 0);
                if ($tmdbFallbackId) {
                    $this->detailCast = app(TmdbService::class)->getTvCast($tmdbFallbackId);
                }
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

            if ($this->detailIntegration->isRadarr()) {
                MonitorArrSearch::dispatch(
                    $this->detailIntegration->id,
                    $libraryId,
                    $this->detailResult['title'] ?? 'Unknown',
                    auth()->id(),
                )->delay(now()->addSeconds(30));
            }
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

        if ($this->queueGuestRequest(
            [
                'arr_integration_id' => $integration->id,
                'title' => $this->detailResult['title'] ?? 'Unknown',
                'external_id' => (string) $tvdbId,
                'request_type' => 'episode',
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'payload' => [
                    'tvdbId' => $tvdbId,
                    'seasonNumber' => $seasonNumber,
                    'episodeNumber' => $episodeNumber,
                    'qualityProfileId' => $integration->quality_profile_id,
                    'rootFolderPath' => $integration->root_folder_path,
                ],
            ],
            __('S:s E:e of ":title" has been submitted and is awaiting admin approval.', [
                's' => str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT),
                'e' => str_pad((string) $episodeNumber, 2, '0', STR_PAD_LEFT),
                'title' => $this->detailResult['title'] ?? 'the show',
            ])
        )) {
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

        if ($this->queueGuestRequest(
            [
                'arr_integration_id' => $integration->id,
                'title' => $item['title'] ?? 'Unknown',
                'external_id' => (string) ($item['tvdbId'] ?? ''),
                'request_type' => 'series',
                'payload' => $payload,
            ],
            __(':title has been submitted and is awaiting admin approval.', [
                'title' => $item['title'] ?? 'Content',
            ])
        )) {
            $this->closeDetail();

            return;
        }

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
    public function requestFromDiscover(int $tmdbId, string $mediaType, ?string $title = null): void
    {
        $resolved = $this->resolveDiscoverToArrResult($tmdbId, $mediaType, $title);

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
     * Tries all Radarr/Sonarr integrations, not just the first.
     * Falls back to a title search (verified by ID) when the tmdb:/tvdb: lookup returns nothing,
     * which is common for newer, foreign, or recently-added titles.
     *
     * @return array<string, mixed>|null
     */
    public function resolveDiscoverToArrResult(int $tmdbId, string $mediaType, ?string $title = null): ?array
    {
        $integrations = $this->integrationsForSearch;

        if ($mediaType === 'movie') {
            $radarrs = $integrations->filter(fn ($i) => $i->isRadarr());

            // Pass 1: tmdb: lookup across all Radarr integrations
            foreach ($radarrs as $radarr) {
                $items = ArrService::make($radarr)->search("tmdb:{$tmdbId}");
                if ($items[0] ?? null) {
                    return array_merge($items[0], [
                        'integrationId' => $radarr->id,
                        'integrationName' => $radarr->name,
                        'integrationType' => 'radarr',
                    ]);
                }
            }

            // Pass 2: title fallback — verify the returned item has the right tmdbId
            if ($title) {
                foreach ($radarrs as $radarr) {
                    $items = ArrService::make($radarr)->search($title);
                    $byId = collect($items)->first(fn ($i) => (int) ($i['tmdbId'] ?? 0) === $tmdbId);
                    $byTitle = collect($items)->first(fn ($i) => strtolower($i['title'] ?? '') === strtolower($title));
                    $singleResult = count($items) === 1 ? $items[0] : null;

                    if ($singleResult && ! $byId && ! $byTitle) {
                        Log::warning('ArrSearch: single-result title fallback used without ID verification', [
                            'tmdb_id' => $tmdbId,
                            'title' => $title,
                            'matched_title' => $singleResult['title'] ?? null,
                            'matched_tmdb_id' => $singleResult['tmdbId'] ?? null,
                            'integration_id' => $radarr->id,
                        ]);
                    }

                    $match = $byId ?? $byTitle ?? $singleResult;

                    if ($match) {
                        return array_merge($match, [
                            'integrationId' => $radarr->id,
                            'integrationName' => $radarr->name,
                            'integrationType' => 'radarr',
                        ]);
                    }
                }
            }

            return null;
        }

        // TV: TMDB → TVDB → Sonarr
        $sonarrs = $integrations->filter(fn ($i) => $i->isSonarr());

        if ($sonarrs->isEmpty()) {
            return null;
        }

        $externalIds = app(TmdbService::class)->getTvExternalIds($tmdbId);
        $tvdbId = $externalIds['tvdb_id'] ?? null;

        if ($tvdbId) {
            // Pass 1: tvdb: lookup across all Sonarr integrations
            foreach ($sonarrs as $sonarr) {
                $items = ArrService::make($sonarr)->search("tvdb:{$tvdbId}");
                if ($items[0] ?? null) {
                    return array_merge($items[0], [
                        'integrationId' => $sonarr->id,
                        'integrationName' => $sonarr->name,
                        'integrationType' => 'sonarr',
                        'resolvedTmdbId' => $tmdbId,
                    ]);
                }
            }
        }

        // Pass 2: title fallback — verify by tvdbId when available, then by exact title
        // (TMDB and TVDB sometimes disagree on the numeric ID for the same show)
        if ($title) {
            foreach ($sonarrs as $sonarr) {
                $items = ArrService::make($sonarr)->search($title);
                if ($tvdbId) {
                    $byId = collect($items)->first(fn ($i) => (int) ($i['tvdbId'] ?? 0) === (int) $tvdbId);
                    $byTitle = collect($items)->first(fn ($i) => strtolower($i['title'] ?? '') === strtolower($title));
                    $match = $byId ?? $byTitle;
                } else {
                    $singleResult = count($items) === 1 ? $items[0] : null;
                    if ($singleResult) {
                        Log::warning('ArrSearch: single-result TV title fallback used without ID verification', [
                            'tmdb_id' => $tmdbId,
                            'title' => $title,
                            'matched_title' => $singleResult['title'] ?? null,
                            'matched_tvdb_id' => $singleResult['tvdbId'] ?? null,
                            'integration_id' => $sonarr->id,
                        ]);
                    }
                    $match = $singleResult;
                }

                if ($match) {
                    return array_merge($match, [
                        'integrationId' => $sonarr->id,
                        'integrationName' => $sonarr->name,
                        'integrationType' => 'sonarr',
                        'resolvedTmdbId' => $tmdbId,
                    ]);
                }
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.arr-search');
    }
}
