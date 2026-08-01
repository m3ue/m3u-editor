<?php

namespace App\Livewire;

use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
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
use App\Services\AIOStreamsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
            $catalogs = $catalogs->filter(fn (array $c) => $selected->has($c['id'].'_'.$c['type']));
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

    /**
     * Maps a Stremio meta object (movie or series, from AIOStreamsService::fetchMeta())
     * onto the Xtream-style `info` json shape VodResource's form/infolist actually reads
     * (see VodResource.php's "VOD Settings" fieldset) — previously only `plot` was ever
     * populated here, leaving genre/cast/director/rating/runtime/images blank for every
     * AIOStreams-added movie regardless of how much metadata AIOStreams returned.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildAioMetaInfo(array $meta): array
    {
        $castNames = collect((array) ($meta['cast'] ?? []))
            ->map(fn ($member) => is_array($member) ? ($member['name'] ?? null) : $member)
            ->filter()
            ->implode(', ');

        $runtimeMinutes = null;
        if (! empty($meta['runtime']) && preg_match('/(\d+)/', (string) $meta['runtime'], $matches)) {
            $runtimeMinutes = (int) $matches[1];
        }

        return array_filter([
            'name' => $meta['name'] ?? null,
            'plot' => $meta['description'] ?? null,
            'description' => $meta['description'] ?? null,
            'genre' => ! empty($meta['genres']) ? implode(', ', (array) $meta['genres']) : null,
            'director' => ! empty($meta['director']) ? implode(', ', (array) $meta['director']) : null,
            'actors' => $castNames ?: null,
            'cast' => $castNames ?: null,
            'episode_run_time' => $runtimeMinutes,
            'release_date' => $meta['releaseInfo'] ?? null,
            'movie_image' => $meta['poster'] ?? null,
            'cover_big' => $meta['poster'] ?? null,
            'backdrop_path' => ! empty($meta['background']) ? [$meta['background']] : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * An episode with no known release date is treated as already aired — we
     * can't tell otherwise, and AIOStreams addons won't have streams for it
     * either way once it's actually requested.
     *
     * @param  array<string, mixed>  $episodeVideo
     */
    public static function hasEpisodeAired(array $episodeVideo): bool
    {
        $released = $episodeVideo['released'] ?? null;

        if (! $released) {
            return true;
        }

        try {
            return Carbon::parse($released)->isPast();
        } catch (\Exception) {
            return true;
        }
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
            ->modalContent(fn () => view('livewire.partials.aiostreams-detail', [
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

            if ($episodeVideo && ! $this->hasEpisodeAired($episodeVideo)) {
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
     * Add the currently-viewed movie to the integration's library playlist as
     * a custom VOD channel, then kick off async stream resolution. Admin-only —
     * adding content to a shared playlist is a different trust level than
     * browsing/watching, which guests can already do.
     */
    public function addMovieToLibrary(): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'movie' || ! $this->detailId || ! $this->detailResult) {
            return;
        }

        $existing = Channel::where('aio_integration_id', $integration->id)
            ->where('aio_item_id', $this->detailId)
            ->where('aio_type', 'movie')
            ->first();

        if ($existing) {
            Notification::make()->warning()->title(__('Already added to library'))->send();

            return;
        }

        $playlist = $integration->getOrCreatePlaylist();
        $meta = $this->detailResult;
        $rating = is_numeric($meta['imdbRating'] ?? null) ? (float) $meta['imdbRating'] : null;

        $channel = Channel::create([
            'user_id' => $integration->user_id,
            'playlist_id' => $playlist->id,
            'name' => $meta['name'] ?? 'Unknown',
            'title' => $meta['name'] ?? null,
            'logo' => $meta['poster'] ?? null,
            'is_custom' => true,
            'is_vod' => true,
            'enabled' => true,
            // AIOStreams VODs manage their own internal failover chain and can't be
            // probed (see VodResource's disabled probe_enabled/can_merge toggles for
            // aio_integration_id rows) — set both explicitly rather than relying on
            // column defaults, which default to true.
            'can_merge' => false,
            'probe_enabled' => false,
            'year' => is_numeric($meta['releaseInfo'] ?? null) ? (int) $meta['releaseInfo'] : null,
            'rating' => $rating,
            'rating_5based' => $rating ? round($rating / 2, 1) : null,
            'info' => $this->buildAioMetaInfo($meta),
            'aio_integration_id' => $integration->id,
            'aio_item_id' => $this->detailId,
            'aio_type' => 'movie',
            'aio_resolution_status' => 'pending',
        ]);

        ResolveAioStreamsChannel::dispatch($channel->id);
        NotifyAioStreamsResolutionComplete::dispatch([$channel->id], [], $integration->user_id, $channel->title ?? $channel->name)
            ->delay(now()->addSeconds(15));

        Notification::make()->success()->title(__('Added — resolving stream, check back shortly'))->send();
    }

    /**
     * Add the currently-viewed series to the library as a custom Series row.
     * Seasons/episodes are created lazily via addEpisodeToLibrary() only as a
     * user actually adds one, rather than eagerly fetching every episode's
     * streams up front (which would multiply AIOStreams requests per series).
     */
    public function addSeriesToLibrary(): void
    {
        if ($this->guestMode) {
            return;
        }

        $integration = $this->integration;
        if (! $integration || $this->detailType !== 'series' || ! $this->detailId || ! $this->detailResult) {
            return;
        }

        if ($this->findOrCreateAioSeries($integration, $this->detailId, $this->detailResult)->wasRecentlyCreated === false) {
            Notification::make()->warning()->title(__('Already added to library'))->send();

            return;
        }

        Notification::make()->success()->title(__('Series added — add individual episodes to resolve their streams'))->send();
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

        $series = $this->findOrCreateAioSeries($integration, $this->detailId, $this->detailResult);

        $episodeRow = $this->createAioEpisode($series, $season, $episode);

        if (! $episodeRow) {
            Notification::make()->warning()->title(__('Episode already added'))->send();

            return;
        }

        if ($episodeRow->enabled) {
            NotifyAioStreamsResolutionComplete::dispatch([], [$episodeRow->id], $integration->user_id, $series->name)
                ->delay(now()->addSeconds(15));
        }

        Notification::make()->success()->title(__('Episode added — resolving stream, check back shortly'))->send();
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

        $series = $this->findOrCreateAioSeries($integration, $this->detailId, $this->detailResult);

        $added = 0;
        $skipped = 0;
        $dispatchedIds = [];

        foreach (array_keys($this->selectedEpisodes) as $key) {
            [$season, $episode] = array_map('intval', explode(':', $key));

            $episodeRow = $this->createAioEpisode($series, $season, $episode);

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

    /**
     * Create the custom Episode (+ Season, on demand) row for one episode of
     * the given series and dispatch its stream resolution job. Returns null
     * without doing anything if the episode was already added.
     */
    private function createAioEpisode(Series $series, int $season, int $episode): ?Episode
    {
        $aioItemId = "{$this->detailId}:{$season}:{$episode}";

        if ($series->episodes()->where('aio_item_id', $aioItemId)->exists()) {
            return null;
        }

        // Stremio meta doesn't expose per-season posters, so fall back to the
        // series' own poster rather than leaving the season card blank.
        $seasonCover = $series->cover_big ?? $series->cover ?? null;

        $seasonRow = $series->seasons()->firstOrCreate(
            ['season_number' => $season],
            array_filter([
                'name' => 'Season '.str_pad((string) $season, 2, '0', STR_PAD_LEFT),
                'user_id' => $series->user_id,
                'playlist_id' => $series->playlist_id,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'cover' => $seasonCover,
                'cover_big' => $seasonCover,
            ], fn ($value) => $value !== null)
        );

        $episodeVideo = collect($this->detailEpisodesBySeason[$season] ?? [])
            ->first(fn (array $v) => (int) ($v['episode'] ?? 0) === $episode)
            ?? collect(Cache::get($this->episodeCacheKey($this->detailId)) ?? [])
                ->first(fn (array $v) => (int) ($v['season'] ?? 0) === $season && (int) ($v['episode'] ?? 0) === $episode);

        $released = $episodeVideo['released'] ?? $episodeVideo['firstAired'] ?? null;
        $airDate = null;
        if ($released) {
            try {
                $airDate = Carbon::parse($released);
            } catch (\Exception) {
                $airDate = null;
            }
        }
        $hasAired = ! $airDate || $airDate->isPast();

        $plot = $episodeVideo['overview'] ?? null;
        $thumbnail = $episodeVideo['thumbnail'] ?? null;

        $episodeRow = $series->episodes()->create([
            'title' => $episodeVideo['title'] ?? $episodeVideo['name'] ?? "Episode {$episode}",
            'user_id' => $series->user_id,
            'playlist_id' => $series->playlist_id,
            'season_id' => $seasonRow->id,
            'episode_num' => $episode,
            'season' => $season,
            'import_batch_no' => Str::orderedUuid()->toString(),
            'is_custom' => true,
            'aio_item_id' => $aioItemId,
            'aio_air_date' => $airDate,
            // Unaired episodes have no stream (and usually no plot/poster) yet, so
            // don't surface them as if they were playable until they've actually aired.
            'enabled' => $hasAired,
            // AIOStreams episodes manage their own internal failover chain and can't be
            // probed (see EpisodesRelationManager's disabled probe_enabled toggle) — set
            // explicitly rather than relying on the column default, which defaults to true.
            'probe_enabled' => false,
            'plot' => $plot,
            'cover' => $thumbnail,
            'info' => array_filter([
                'plot' => $plot,
                'movie_image' => $thumbnail,
                'cover_big' => $thumbnail,
                'release_date' => $released,
            ], fn ($value) => $value !== null && $value !== ''),
            // Streams can't exist for an episode that hasn't aired yet — resolving
            // now would just burn the empty-result retry budget and permanently
            // mark it 'failed'. Leave it 'scheduled' for the upcoming-episodes
            // command (routes/console.php) to pick up once aio_air_date passes.
            'aio_resolution_status' => $hasAired ? 'pending' : 'scheduled',
        ]);

        if ($hasAired) {
            ResolveAioStreamsEpisode::dispatch($episodeRow->id);
        }

        return $episodeRow;
    }

    private function findOrCreateAioSeries(MediaServerIntegration $integration, string $itemId, array $meta): Series
    {
        $playlist = $integration->getOrCreatePlaylist();
        $castNames = collect((array) ($meta['cast'] ?? []))
            ->map(fn ($member) => is_array($member) ? ($member['name'] ?? null) : $member)
            ->filter()
            ->implode(', ');

        return Series::firstOrCreate(
            [
                'aio_integration_id' => $integration->id,
                'aio_item_id' => $itemId,
                'aio_type' => 'series',
            ],
            array_filter([
                'name' => $meta['name'] ?? 'Unknown',
                'plot' => $meta['description'] ?? null,
                'cover' => $meta['poster'] ?? null,
                'genre' => ! empty($meta['genres']) ? implode(', ', (array) $meta['genres']) : null,
                'director' => ! empty($meta['director']) ? implode(', ', (array) $meta['director']) : null,
                'cast' => $castNames ?: null,
                'rating' => $meta['imdbRating'] ?? null,
                'rating_5based' => is_numeric($meta['imdbRating'] ?? null) ? round(((float) $meta['imdbRating']) / 2, 1) : null,
                'release_date' => $meta['releaseInfo'] ?? null,
                'backdrop_path' => ! empty($meta['background']) ? [$meta['background']] : null,
                'user_id' => $integration->user_id,
                'playlist_id' => $playlist->id,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'enabled' => true,
                'aio_resolution_status' => 'resolved',
            ], fn ($value) => $value !== null && $value !== '')
        );
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
        $url = $stream['url'] ?? null;
        if (! $url) {
            Notification::make()->danger()->title(__('This source has no playable URL'))->send();

            return;
        }

        $format = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'mp4';

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
            'url' => $url,
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
