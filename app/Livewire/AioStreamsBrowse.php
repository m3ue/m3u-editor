<?php

namespace App\Livewire;

use App\Filament\Actions\AioStreamsRescanAction;
use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use App\Http\Controllers\MediaServerProxyController;
use App\Jobs\AddAioStreamsItemsToLibrary;
use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\ViewerWatchProgress;
use App\Services\AioStreamsLibraryService;
use App\Services\AIOStreamsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Browse/watch UI for a single AIOStreams (Stremio addon) integration.
 * Reused unchanged by the admin "Browse Catalog" page and the guest panel,
 * mirroring how ArrSearch is shared between RequestContent and GuestRequestContent.
 */
class AioStreamsBrowse extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public int $integrationId;

    public bool $guestMode = false;

    public ?int $playlistAuthId = null;

    public string $searchTerm = '';

    public string $typeFilter = 'all';

    public bool $isSearching = false;

    /** @var array<int, array<string, mixed>> */
    public array $searchResults = [];

    public bool $showDetail = false;

    /** @var array<string, mixed>|null Raw Stremio meta object. */
    public ?array $detailResult = null;

    public ?string $detailType = null;

    public ?string $detailId = null;

    /**
     * Available season numbers for the current series — lightweight, just for
     * rendering the season tab buttons. The actual per-episode data is loaded
     * lazily per season (see loadSeasonEpisodes()), not all at once.
     *
     * @var array<int, int>
     */
    public array $detailSeasons = [];

    /**
     * Episodes for ONLY the currently selected season — intentionally never holds
     * more than one season's worth of data, to keep Livewire's serialized state
     * (and the rendered DOM) bounded regardless of how many seasons/episodes a
     * series has.
     *
     * @var array<int, array<int, array<string, mixed>>> keyed by season number
     */
    public array $detailEpisodesBySeason = [];

    public ?int $detailSelectedSeason = null;

    /**
     * Episodes checked for bulk-add, keyed as "{season}:{episode}" so
     * selections made in one season survive switching to another.
     *
     * @var array<string, bool>
     */
    public array $selectedEpisodes = [];

    /** @var array<int, array<string, mixed>> */
    public array $streamChoices = [];

    /** @var array<string, mixed>|null */
    public ?array $pendingWatchContext = null;

    public bool $streamsLoading = false;

    public bool $streamsFailed = false;

    /**
     * Season/episode currently being resolved into streams — set at the top of
     * playStream() regardless of entry point, so retryLoadStreams() always knows
     * what to re-fetch.
     */
    public ?int $resumeSeason = null;

    public ?int $resumeEpisode = null;

    /**
     * Only populated by resumeWatch(), since the per-episode video list (which
     * would normally supply this) is intentionally never fetched for a resume —
     * see resumeWatch()'s docblock.
     */
    public ?string $resumeEpisodeTitle = null;

    public function mount(int $integrationId, bool $guestMode = false, ?int $playlistAuthId = null): void
    {
        $this->integrationId = $integrationId;
        $this->guestMode = $guestMode;
        $this->playlistAuthId = $playlistAuthId;
    }

    public function getIntegrationProperty(): ?MediaServerIntegration
    {
        return MediaServerIntegration::query()
            ->where('id', $this->integrationId)
            ->where('type', 'aiostreams')
            ->first();
    }

    /**
     * Catalogs for the current type filter, rendered as one deferred
     * AioStreamsCatalogRow per catalog. Cheap — reads directly off the
     * integration's cached manifest, no HTTP involved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCatalogsProperty(): array
    {
        return $this->enabledCatalogs();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function enabledCatalogs(): array
    {
        $integration = $this->integration;
        if (! $integration) {
            return [];
        }

        $catalogs = collect($integration->aiostreams_catalogs ?? []);

        if (! $integration->aiostreams_enable_all_catalogs) {
            $selected = collect($integration->aiostreams_selected_catalog_ids ?? [])->flip();
            $catalogs = $catalogs->filter(fn (array $c) => $selected->has(MediaServerIntegration::aiostreamsCatalogKey($c)));
        }

        if ($this->typeFilter !== 'all') {
            $catalogs = $catalogs->where('type', $this->typeFilter);
        }

        return $catalogs->values()->all();
    }

    public function updatedTypeFilter(): void
    {
        if (mb_strlen(trim($this->searchTerm)) >= 2) {
            $this->search();
        }
    }

    /**
     * Catalog cards (in both the search grid and each AioStreamsCatalogRow child)
     * dispatch this instead of calling openDetail() directly, since a card rendered
     * inside a child row component can't call a parent component method directly.
     */
    #[On('openAioDetail')]
    public function handleOpenAioDetail(string $type, string $id): void
    {
        $this->openDetail($type, $id);
    }

    public function search(): void
    {
        $term = trim($this->searchTerm);
        if (mb_strlen($term) < 2) {
            $this->searchResults = [];

            return;
        }

        $integration = $this->integration;
        if (! $integration) {
            return;
        }

        $this->isSearching = true;

        $service = AIOStreamsService::make($integration);
        $results = [];

        foreach ($this->enabledCatalogs() as $catalog) {
            if (empty($catalog['searchable'])) {
                continue;
            }

            try {
                $data = $service->fetchCatalog($catalog['type'], $catalog['id'], extra: ['search' => $term]);
                foreach ($data['metas'] ?? [] as $item) {
                    $results[] = $item;
                }
            } catch (\Exception) {
                continue;
            }
        }

        $this->searchResults = collect($results)->unique('id')->values()->all();
        $this->isSearching = false;
    }

    public function clearSearch(): void
    {
        $this->searchTerm = '';
        $this->searchResults = [];
    }

    public function openDetail(string $type, string $id): void
    {
        if (! $this->loadDetail($type, $id)) {
            return;
        }

        $this->showDetail = true;
        $this->mountAction('showDetail');
    }

    /**
     * Resume a Continue Watching item. Opens the source-picker modal INSTANTLY
     * using only the fields already saved on the progress row itself — zero
     * network calls — since fetching full Stremio meta (and, for a series, its
     * whole episode list) before the modal could open was the cause of a large
     * click-to-open delay. The actual stream lookup is kicked off afterwards by
     * loadResumeStreams(), triggered client-side via wire:init once the modal
     * is already visible.
     */
    public function resumeWatch(int $progressId): void
    {
        $viewer = $this->resolveViewer();
        $progress = $viewer
            ? ViewerWatchProgress::query()
                ->aiostreams()
                ->where('playlist_viewer_id', $viewer->id)
                ->where('aio_integration_id', $this->integrationId)
                ->find($progressId)
            : null;

        if (! $progress) {
            return;
        }

        $this->detailType = $progress->season_number ? 'series' : 'movie';
        $this->detailId = $progress->aio_item_id;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->selectedEpisodes = [];
        $this->streamChoices = [];
        $this->pendingWatchContext = null;

        $this->detailResult = [
            'name' => $progress->title,
            'poster' => $progress->thumbnail_url,
            'background' => $progress->backdrop_url,
            'imdbRating' => $progress->rating,
            'releaseInfo' => $progress->year,
            'description' => $progress->plot,
        ];

        $this->resumeSeason = $progress->season_number;
        $this->resumeEpisode = $progress->episode_number;
        $this->resumeEpisodeTitle = $progress->episode_title;
        $this->streamsLoading = true;
        $this->streamsFailed = false;

        $this->showDetail = true;
        $this->mountAction('showDetail');
    }

    /**
     * Fires once, client-side, right after the resume modal opens (via wire:init
     * in the Blade partial) — this is what actually looks up playable streams,
     * deferred so it never blocks the modal from opening.
     *
     * Deliberately #[Renderless]: this call has no originating click for
     * Filament's focus-trap to anchor to, and a normal (rendered) Livewire
     * request re-diffs this component's ENTIRE DOM — including the catalog grid
     * sitting behind the modal — which was enough on its own (independent of the
     * earlier double-mountAction() bug) to tear down and reinitialize every lazy
     * AioStreamsCatalogRow's x-intersect binding, forcing them all to load at
     * once and jump-scrolling the page to wherever that landed. Renderless skips
     * the re-render/morph entirely; the fetched streams are instead pushed to
     * the client via a dispatched event and rendered by Alpine, isolated from
     * the rest of the page. See aiostreams-detail.blade.php.
     */
    #[Renderless]
    public function loadResumeStreams(): void
    {
        $this->playStream($this->resumeSeason, $this->resumeEpisode);

        // playStream() derives episode_title from the per-episode video list,
        // which resumeWatch() intentionally never fetches — fall back to what
        // was already saved on the progress row.
        if ($this->pendingWatchContext) {
            $this->pendingWatchContext['episode_title'] ??= $this->resumeEpisodeTitle;
        }

        $this->dispatch('aio-streams-loaded', failed: $this->streamsFailed, streams: $this->streamChoices);
    }

    #[Renderless]
    public function retryLoadStreams(): void
    {
        $this->loadResumeStreams();
    }

    /**
     * Fetch Stremio meta for an item and populate the detail-related component state.
     * Shared by openDetail() (which additionally opens the slide-over) and
     * resumeWatch() (which plays immediately instead).
     */
    private function loadDetail(string $type, string $id): bool
    {
        $integration = $this->integration;
        if (! $integration) {
            return false;
        }

        $response = AIOStreamsService::make($integration)->fetchMeta($type, $id);
        $meta = $response['meta'] ?? null;

        if (! $meta) {
            Notification::make()->danger()->title(__('Failed to load details'))->send();

            return false;
        }

        $this->detailType = $type;
        $this->detailId = $id;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->selectedEpisodes = [];
        $this->streamChoices = [];
        $this->pendingWatchContext = null;
        $this->resumeSeason = null;
        $this->resumeEpisode = null;
        $this->resumeEpisodeTitle = null;
        $this->streamsLoading = false;
        $this->streamsFailed = false;

        if ($type === 'series' && ! empty($meta['videos'])) {
            Cache::put($this->episodeCacheKey($id), $meta['videos'], now()->addMinutes(10));

            $this->detailSeasons = collect($meta['videos'])
                ->map(fn (array $video) => (int) ($video['season'] ?? 0))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (! empty($this->detailSeasons)) {
                $this->loadSeasonEpisodes($this->detailSeasons[0]);
            }
        }

        // The full per-episode video list is never kept in $detailResult — it's
        // sourced from the cache above (or skipped entirely for resumeWatch) and
        // would otherwise bloat every subsequent Livewire request for this component.
        unset($meta['videos']);
        $this->detailResult = $meta;

        return true;
    }

    private function episodeCacheKey(string $id): string
    {
        return "aiostreams:videos:{$this->integrationId}:{$id}";
    }

    /**
     * Populate $detailEpisodesBySeason with ONLY the given season's episodes,
     * replacing whatever season was previously loaded — this is what keeps the
     * component's state (and the rendered DOM) bounded regardless of series length.
     * Reads from the per-item cache populated in loadDetail(); if it's expired,
     * transparently re-fetches the meta to rebuild it.
     */
    private function loadSeasonEpisodes(int $season): void
    {
        if (! $this->detailType || ! $this->detailId) {
            return;
        }

        $videos = Cache::get($this->episodeCacheKey($this->detailId));

        if ($videos === null) {
            $integration = $this->integration;
            if (! $integration) {
                return;
            }

            $response = AIOStreamsService::make($integration)->fetchMeta($this->detailType, $this->detailId);
            $videos = $response['meta']['videos'] ?? [];
            Cache::put($this->episodeCacheKey($this->detailId), $videos, now()->addMinutes(10));
        }

        $episodes = collect($videos)
            ->filter(fn (array $video) => (int) ($video['season'] ?? 0) === $season)
            ->map(fn (array $video) => $this->normalizeEpisodeVideo($video))
            ->values()
            ->all();

        $this->detailEpisodesBySeason = [$season => $episodes];
        $this->detailSelectedSeason = $season;
    }

    /**
     * Normalize a Stremio "video" (episode) object into a consistent shape — addons
     * vary in whether they use title/name, overview/description, thumbnail/poster.
     *
     * @param  array<string, mixed>  $video
     * @return array<string, mixed>
     */
    private function normalizeEpisodeVideo(array $video): array
    {
        return [
            'episode' => (int) ($video['episode'] ?? 0),
            'title' => $video['title'] ?? $video['name'] ?? null,
            'overview' => $video['overview'] ?? $video['description'] ?? null,
            'thumbnail' => $video['thumbnail'] ?? $video['poster'] ?? null,
            'released' => $video['released'] ?? $video['firstAired'] ?? null,
        ];
    }

    public function showDetailAction(): Action
    {
        return Action::make('showDetail')
            ->slideOver()
            ->modalHeading(false)
            // Filament's focus trap auto-focuses the modal's first focusable element
            // when it opens, and the browser's default focus() scrolls that element
            // into view. The modal is teleported to the end of <body> (after every
            // catalog row), so if the trap activates before the teleported node's
            // fixed positioning has taken effect, that scroll lands on the still
            // in-flow node at the bottom of the page — force-loading every lazy
            // catalog row along the way. Disabling autofocus removes the trigger.
            ->modalAutofocus(false)
            ->registerModalActions([
                fn (): Action => $this->makeAddToLibraryAction(),
            ])
            ->modalContent(fn (Action $action) => view('livewire.partials.aiostreams-detail', [
                'addToLibraryAction' => $action->getModalAction('addToLibrary'),
                'detailResult' => $this->detailResult,
                'detailType' => $this->detailType,
                'detailSeasons' => $this->detailSeasons,
                'detailEpisodesBySeason' => $this->detailEpisodesBySeason,
                'detailSelectedSeason' => $this->detailSelectedSeason,
                'streamChoices' => $this->streamChoices,
                'streamsLoading' => $this->streamsLoading,
                'streamsFailed' => $this->streamsFailed,
                'resumeSeason' => $this->resumeSeason,
                'resumeEpisode' => $this->resumeEpisode,
                'resumeEpisodeTitle' => $this->resumeEpisodeTitle,
                'guestMode' => $this->guestMode,
                'selectedEpisodes' => $this->selectedEpisodes,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelAction(false);
    }

    #[Renderless]
    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailResult = null;
        $this->detailType = null;
        $this->detailId = null;
        $this->detailSeasons = [];
        $this->detailEpisodesBySeason = [];
        $this->detailSelectedSeason = null;
        $this->selectedEpisodes = [];
        $this->streamChoices = [];
        $this->pendingWatchContext = null;
        $this->resumeSeason = null;
        $this->resumeEpisode = null;
        $this->resumeEpisodeTitle = null;
        $this->streamsLoading = false;
        $this->streamsFailed = false;
        $this->unmountAction();
    }

    public function selectSeason(int $season): void
    {
        if ($season === $this->detailSelectedSeason) {
            return;
        }

        $this->loadSeasonEpisodes($season);
    }

    public function playStream(?int $season = null, ?int $episode = null): void
    {
        // Remembered unconditionally (even on failure) so retryLoadStreams() always
        // knows what to re-fetch, regardless of whether this call came from a real
        // click or from the resume flow's wire:init.
        $this->resumeSeason = $season;
        $this->resumeEpisode = $episode;
        $this->streamsLoading = true;
        $this->streamsFailed = false;

        $integration = $this->integration;
        if (! $integration || ! $this->detailResult || ! $this->detailType || ! $this->detailId) {
            $this->streamsLoading = false;
            $this->streamsFailed = true;

            return;
        }

        $streamLookupId = $this->detailId;
        $episodeVideo = null;

        if ($this->detailType === 'series' && $season !== null && $episode !== null) {
            $streamLookupId = "{$this->detailId}:{$season}:{$episode}";
            $episodeVideo = collect($this->detailEpisodesBySeason[$season] ?? [])
                ->first(fn (array $v) => (int) ($v['episode'] ?? 0) === $episode);

            if ($episodeVideo && ! AioStreamsLibraryService::hasEpisodeAired($episodeVideo)) {
                $this->streamsLoading = false;
                $this->streamsFailed = true;
                Notification::make()->warning()->title(__('This episode has not aired yet'))->send();

                return;
            }
        }

        try {
            $data = AIOStreamsService::make($integration)->fetchStreams($this->detailType, $streamLookupId);
        } catch (\Exception $e) {
            $this->streamsLoading = false;
            $this->streamsFailed = true;
            Notification::make()->danger()->title(__('Failed to load streams'))->body($e->getMessage())->send();

            return;
        }

        $streams = $data['streams'] ?? [];
        if (empty($streams)) {
            $this->streamsLoading = false;
            $this->streamsFailed = true;
            Notification::make()->warning()->title(__('No playable streams found'))->send();

            return;
        }

        // Never let a raw resolved URL (often carrying the debrid account's own auth
        // token) reach $streamChoices — it's a public Livewire property, so anything
        // stored on it is serialized into the wire snapshot embedded in the page and
        // into the 'aio-streams-loaded' dispatch, both visible to the browser well
        // before the user picks a source. Proxy every candidate up front, the same
        // way AIOStreamsProxyController::stream() does for the Xtream-style catalog
        // endpoint. The original extension is captured now (from the raw URL) since
        // the proxy route itself carries no file extension.
        $rawUrlsByIndex = [];
        foreach ($streams as $index => $stream) {
            if (is_string($stream['url'] ?? null) && $stream['url'] !== '') {
                $rawUrlsByIndex[$index] = $stream['url'];
            }
        }
        $proxiedUrlsByIndex = MediaServerProxyController::generateAioStreamsLiveProxyUrls($this->integrationId, $rawUrlsByIndex);
        foreach ($streams as $index => &$stream) {
            if (isset($proxiedUrlsByIndex[$index])) {
                $stream['format'] = pathinfo(parse_url($stream['url'], PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'mp4';
                $stream['url'] = $proxiedUrlsByIndex[$index];
            } else {
                unset($stream['url']);
            }
        }
        unset($stream);

        // Always show the source picker rather than auto-playing a lone result — a
        // single "stream" is frequently a trailer/error placeholder from the addon
        // rather than a real playable source, so let the user confirm the choice.
        $this->streamChoices = $streams;
        $this->pendingWatchContext = $this->buildWatchContext($season, $episode, $episodeVideo);
        $this->streamsLoading = false;

        // Deliberately NOT re-mounting 'showDetail' here. The modal is always
        // already open by the time playStream() runs — via openDetail()'s picker,
        // the movie Watch button, or wire:init on the resume flow — and
        // mountAction() pushes onto Filament's mounted-actions stack
        // unconditionally rather than checking if it's already mounted. Doing so
        // anyway previously double-mounted the action; for the wire:init call
        // (no click to anchor Filament's focus-trap to) that made the underlying
        // page jump-scroll to the bottom, force-loading every lazy catalog row.
    }

    /**
     * "Add to Library" button for the title open in the detail slide-over. When
     * the user has match profiles it first asks which one the title should use
     * (the integration default is preselected); with none it adds straight away,
     * like before profiles existed.
     */
    public function makeAddToLibraryAction(): Action
    {
        return Action::make('addToLibrary')
            ->label(fn (): string => $this->detailType === 'series' ? __('Add Series to Library') : __('Add to Library'))
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->outlined()
            ->extraAttributes(fn (): array => $this->detailType === 'series' ? ['class' => 'w-full'] : [])
            // Only movies and series can be stored; other addon types (tv, channel, ...) can only be browsed.
            ->hidden(fn (): bool => $this->guestMode || ! in_array($this->detailType, ['movie', 'series'], true))
            ->modalHeading(fn (): string => __('Add ":name" to Library', ['name' => $this->detailResult['name'] ?? __('Unknown')]))
            ->modalWidth(Width::Medium)
            ->modalHidden(fn (): bool => ! $this->hasMatchProfiles())
            ->modalSubmitActionLabel(__('Add to Library'))
            ->schema([
                $this->libraryProfileSelect(),
            ])
            ->action(function (array $data): void {
                $profileId = $this->profileIdFromData($data);

                if ($this->detailType === 'series') {
                    $this->addSeriesToLibrary($profileId);
                } else {
                    $this->addMovieToLibrary($profileId);
                }
            });
    }

    /**
     * Add the titles checked on the catalog/search cards. The selection lives in
     * the browser (Alpine) and arrives as the action's `items` argument; each
     * item's meta is fetched by a queued job, since a large selection is many
     * AIOStreams requests.
     */
    public function bulkAddToLibraryAction(): Action
    {
        return Action::make('bulkAddToLibrary')
            ->hidden(fn (): bool => $this->guestMode)
            ->modalHeading(function (array $arguments): string {
                $count = count($this->bulkItemsFromArguments($arguments));

                return trans_choice('Add :count title to Library|Add :count titles to Library', $count, ['count' => $count]);
            })
            ->modalDescription(__('Titles already in the library are skipped. Streams are resolved in the background.'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('Add to Library'))
            ->schema(fn (array $arguments): array => [
                $this->libraryProfileSelect()
                    ->visible(fn (): bool => $this->hasMatchProfiles())
                    ->helperText(__('Applies to every selected title. The integration default uses its movie or series profile per title.')),
                Toggle::make('include_latest_season')
                    ->label(__('Include latest season'))
                    ->helperText(__('Also add every episode of each series\' most recent season (up to :max series per add). Otherwise only the series is added, and you pick episodes later.', ['max' => AddAioStreamsItemsToLibrary::MAX_SERIES_WITH_LATEST_SEASON]))
                    ->default(false)
                    ->visible(fn (): bool => collect($this->bulkItemsFromArguments($arguments))->contains('type', 'series')),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $integration = $this->integration;
                $items = $this->bulkItemsFromArguments($arguments);

                if (! $integration || $items === []) {
                    return;
                }

                $seriesCount = collect($items)->where('type', 'series')->count();

                if (($data['include_latest_season'] ?? false) && $seriesCount > AddAioStreamsItemsToLibrary::MAX_SERIES_WITH_LATEST_SEASON) {
                    Notification::make()
                        ->danger()
                        ->title(__('Too many series to include their latest season'))
                        ->body(__(':count series selected, the limit with "Include latest season" is :max. Turn it off or select fewer series.', [
                            'count' => $seriesCount,
                            'max' => AddAioStreamsItemsToLibrary::MAX_SERIES_WITH_LATEST_SEASON,
                        ]))
                        ->send();

                    $action->halt();
                }

                AddAioStreamsItemsToLibrary::dispatch(
                    $integration->id,
                    $items,
                    $this->profileIdFromData($data),
                    (bool) ($data['include_latest_season'] ?? false),
                );

                $this->dispatch('aio-selection-cleared');

                Notification::make()
                    ->success()
                    ->title(trans_choice('Adding :count title to the library|Adding :count titles to the library', count($items), ['count' => count($items)]))
                    ->body(__('You\'ll get a notification when it\'s done.'))
                    ->send();
            });
    }

    /**
     * The bulk selection sent from the browser, reduced to valid
     * `{type, id}` pairs (deduplicated, capped) - names and anything else the
     * client sent are dropped, the job reads titles from AIOStreams itself.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, array{type: string, id: string}>
     */
    private function bulkItemsFromArguments(array $arguments): array
    {
        return collect((array) ($arguments['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item)
                && in_array($item['type'] ?? null, ['movie', 'series'], true)
                && is_string($item['id'] ?? null)
                && filled($item['id']))
            ->map(fn (array $item): array => ['type' => $item['type'], 'id' => $item['id']])
            ->unique(fn (array $item): string => "{$item['type']}:{$item['id']}")
            ->take(AddAioStreamsItemsToLibrary::MAX_ITEMS)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function profileIdFromData(array $data): ?int
    {
        return filled($data['profile_id'] ?? null) ? (int) $data['profile_id'] : null;
    }

    private function libraryProfileSelect(): Select
    {
        return AioStreamsRescanAction::profileSelect()
            ->placeholder(__('Integration default'))
            ->helperText(__('Rules for which streams the auto-matcher picks. Change it later from the title\'s rescan action.'));
    }

    private function hasMatchProfiles(): bool
    {
        return AioStreamsMatchProfileResource::profileOptions() !== [];
    }

    /**
     * Add the currently-viewed movie to the integration's library playlist as
     * a custom VOD channel, then kick off async stream resolution. Admin-only:
     * adding content to a shared playlist is a different trust level than
     * browsing/watching, which guests can already do.
     */
    public function addMovieToLibrary(?int $profileId = null): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'movie' || ! $this->detailId || ! $this->detailResult) {
            return;
        }

        $channel = $this->library()->addMovie($integration, $this->detailId, $this->detailResult, $profileId);

        if (! $channel) {
            Notification::make()->warning()->title(__('Already added to library'))->send();

            return;
        }

        NotifyAioStreamsResolutionComplete::dispatch([$channel->id], [], $integration->user_id, $channel->title ?? $channel->name)
            ->delay(now()->addSeconds(15));

        Notification::make()->success()->title(__('Added - resolving stream, check back shortly'))->send();
    }

    /**
     * Add the currently-viewed series to the library as a custom Series row.
     * Seasons/episodes are created lazily via addEpisodeToLibrary() only as a
     * user actually adds one, rather than eagerly fetching every episode's
     * streams up front (which would multiply AIOStreams requests per series).
     */
    public function addSeriesToLibrary(?int $profileId = null): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'series' || ! $this->detailId || ! $this->detailResult) {
            return;
        }

        $series = $this->library()->findOrCreateSeries($integration, $this->detailId, $this->detailResult, $profileId);

        if ($series->wasRecentlyCreated === false) {
            Notification::make()->warning()->title(__('Already added to library'))->send();

            return;
        }

        $this->library()->queueTmdbEnrichment($integration, series: $series);

        Notification::make()->success()->title(__('Series added - add individual episodes to resolve their streams'))->send();
    }

    /**
     * Add a single episode of the currently-viewed series to the library,
     * creating the parent Series/Season rows on demand if needed, then kick
     * off async stream resolution for that episode.
     */
    public function addEpisodeToLibrary(int $season, int $episode): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'series' || ! $this->detailId || ! $this->detailResult) {
            return;
        }

        $series = $this->library()->findOrCreateSeries($integration, $this->detailId, $this->detailResult);

        $episodeRow = $this->library()->createEpisode($series, $season, $episode, $this->episodeVideo($season, $episode));

        if (! $episodeRow) {
            Notification::make()->warning()->title(__('Episode already added'))->send();

            return;
        }

        if ($episodeRow->enabled) {
            NotifyAioStreamsResolutionComplete::dispatch([], [$episodeRow->id], $integration->user_id, $series->name)
                ->delay(now()->addSeconds(15));
        }

        $this->library()->queueTmdbEnrichment($integration, series: $series);

        Notification::make()->success()->title(__('Episode added - resolving stream, check back shortly'))->send();
    }

    public function toggleEpisodeSelected(int $season, int $episode): void
    {
        $key = "{$season}:{$episode}";

        if (isset($this->selectedEpisodes[$key])) {
            unset($this->selectedEpisodes[$key]);
        } else {
            $this->selectedEpisodes[$key] = true;
        }
    }

    /**
     * Toggle all episodes of the currently-displayed season: if every episode
     * in it is already selected, clear them; otherwise select them all.
     * Selections from other seasons (made before switching tabs) are untouched.
     */
    public function toggleSelectAllForSeason(int $season): void
    {
        $episodeNumbers = collect($this->detailEpisodesBySeason[$season] ?? [])
            ->map(fn (array $v) => (int) ($v['episode'] ?? 0));

        $allSelected = $episodeNumbers->every(fn (int $ep) => isset($this->selectedEpisodes["{$season}:{$ep}"]));

        foreach ($episodeNumbers as $ep) {
            $key = "{$season}:{$ep}";
            if ($allSelected) {
                unset($this->selectedEpisodes[$key]);
            } else {
                $this->selectedEpisodes[$key] = true;
            }
        }
    }

    public function getSelectedEpisodesCountProperty(): int
    {
        return count($this->selectedEpisodes);
    }

    /**
     * Add every checked episode (possibly spanning multiple seasons) to the
     * library in one action, then clear the selection. Reports a single
     * summary notification rather than one per episode.
     */
    public function addSelectedEpisodesToLibrary(): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'series' || ! $this->detailId || ! $this->detailResult || empty($this->selectedEpisodes)) {
            return;
        }

        $series = $this->library()->findOrCreateSeries($integration, $this->detailId, $this->detailResult);

        $added = 0;
        $skipped = 0;
        $dispatchedIds = [];

        foreach (array_keys($this->selectedEpisodes) as $key) {
            [$season, $episode] = array_map('intval', explode(':', $key));

            $episodeRow = $this->library()->createEpisode($series, $season, $episode, $this->episodeVideo($season, $episode));

            if ($episodeRow) {
                $added++;
                if ($episodeRow->enabled) {
                    $dispatchedIds[] = $episodeRow->id;
                }
            } else {
                $skipped++;
            }
        }

        $this->selectedEpisodes = [];

        if ($added > 0) {
            $this->library()->queueTmdbEnrichment($integration, series: $series);
        }

        if (! empty($dispatchedIds)) {
            NotifyAioStreamsResolutionComplete::dispatch([], $dispatchedIds, $integration->user_id, $series->name)
                ->delay(now()->addSeconds(15));
        }

        $message = trans_choice(':count episode added — resolving streams, check back shortly|:count episodes added — resolving streams, check back shortly', $added, ['count' => $added]);
        if ($skipped > 0) {
            $message .= ' '.__('(:count already in library)', ['count' => $skipped]);
        }

        if ($added > 0) {
            Notification::make()->success()->title($message)->send();
        } else {
            Notification::make()->warning()->title(__('Selected episodes are already in the library'))->send();
        }
    }

    private function library(): AioStreamsLibraryService
    {
        return app(AioStreamsLibraryService::class);
    }

    /**
     * The Stremio video object for one episode: from the season shown in the
     * slide-over, else the cached full episode list.
     *
     * @return array<string, mixed>|null
     */
    private function episodeVideo(int $season, int $episode): ?array
    {
        return collect($this->detailEpisodesBySeason[$season] ?? [])
            ->first(fn (array $v) => (int) ($v['episode'] ?? 0) === $episode)
            ?? collect(Cache::get($this->episodeCacheKey((string) $this->detailId)) ?? [])
                ->first(fn (array $v) => (int) ($v['season'] ?? 0) === $season && (int) ($v['episode'] ?? 0) === $episode);
    }

    public function playChosenStream(int $index): void
    {
        if (! isset($this->streamChoices[$index])) {
            return;
        }

        $this->dispatchPlay($this->streamChoices[$index], $this->pendingWatchContext ?? []);
        $this->streamChoices = [];
        $this->pendingWatchContext = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWatchContext(?int $season, ?int $episode, ?array $episodeVideo): array
    {
        $meta = $this->detailResult ?? [];

        return [
            'aio_item_id' => $this->detailId,
            'aio_integration_id' => $this->integrationId,
            'title' => $meta['name'] ?? null,
            'episode_title' => $episodeVideo['title'] ?? null,
            'season_number' => $season,
            'episode_number' => $episode,
            'thumbnail_url' => $episodeVideo['thumbnail'] ?? $meta['poster'] ?? null,
            'backdrop_url' => $meta['background'] ?? null,
            'rating' => $meta['imdbRating'] ?? null,
            'year' => $meta['releaseInfo'] ?? $meta['year'] ?? null,
            'plot' => $episodeVideo['overview'] ?? $meta['description'] ?? null,
        ];
    }

    private function dispatchPlay(array $stream, array $context): void
    {
        // $stream['url'] is already a proxied URL and 'format' the extension
        // captured from the raw one — see playStream(), which proxies the whole
        // batch before it's ever stored on $streamChoices (a public property, so
        // whatever's in it reaches the browser before a source is even chosen).
        $proxiedUrl = $stream['url'] ?? null;
        if (! $proxiedUrl) {
            Notification::make()->danger()->title(__('This source has no playable URL'))->send();

            return;
        }

        $format = $stream['format'] ?? 'mp4';

        $title = $context['title'] ?? __('Unknown');
        $displayTitle = $title;

        if (! empty($context['episode_number'])) {
            $displayTitle .= ' - S'.str_pad((string) $context['season_number'], 2, '0', STR_PAD_LEFT)
                .'E'.str_pad((string) $context['episode_number'], 2, '0', STR_PAD_LEFT);

            if (! empty($context['episode_title'])) {
                $displayTitle .= ' - '.$context['episode_title'];
            }
        }

        $playerId = 'aiostreams-'.$context['aio_item_id'];
        if (! empty($context['episode_number'])) {
            $playerId .= '-'.$context['season_number'].'x'.$context['episode_number'];
        }

        $this->dispatch('openFloatingStream', array_merge($context, [
            'id' => $playerId,
            'content_type' => 'aiostreams',
            'title' => $title,
            'display_title' => $displayTitle,
            'logo' => $context['thumbnail_url'] ?? null,
            'url' => $proxiedUrl,
            'format' => $format,
            'type' => 'aiostreams',
        ]));

        $this->closeDetail();
    }

    /**
     * @return Collection<int, ViewerWatchProgress>
     */
    public function getContinueWatchingProperty(): Collection
    {
        $viewer = $this->resolveViewer();
        if (! $viewer) {
            return new Collection;
        }

        return ViewerWatchProgress::query()
            ->aiostreams()
            ->where('playlist_viewer_id', $viewer->id)
            ->where('aio_integration_id', $this->integrationId)
            ->where('completed', false)
            ->where('position_seconds', '>=', 30)
            ->orderByDesc('last_watched_at')
            ->limit(12)
            ->get();
    }

    private function resolveViewer(): ?PlaylistViewer
    {
        $integration = $this->integration;
        if (! $integration) {
            return null;
        }

        $playlist = null;
        foreach ([Playlist::class, CustomPlaylist::class, MergedPlaylist::class] as $model) {
            $playlist = $model::where('user_id', $integration->user_id)
                ->where('aiostreams_integration_id', $integration->id)
                ->first();
            if ($playlist) {
                break;
            }
        }
        $playlist ??= Playlist::where('user_id', $integration->user_id)->first();

        if (! $playlist) {
            return null;
        }

        if ($this->guestMode) {
            if (! $this->playlistAuthId) {
                return null;
            }

            $auth = PlaylistAuth::find($this->playlistAuthId);
            if (! $auth) {
                return null;
            }

            return PlaylistViewer::where('playlist_auth_id', $auth->id)
                ->where('viewerable_type', $playlist->getMorphClass())
                ->where('viewerable_id', $playlist->id)
                ->first();
        }

        if (! auth()->check()) {
            return null;
        }

        return PlaylistViewer::where('viewerable_type', $playlist->getMorphClass())
            ->where('viewerable_id', $playlist->id)
            ->where('is_admin', true)
            ->first();
    }

    public function render()
    {
        return view('livewire.aio-streams-browse');
    }
}
