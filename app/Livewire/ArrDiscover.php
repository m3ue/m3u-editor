<?php

namespace App\Livewire;

use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use App\Services\TmdbService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy-loaded discover/browse panel powered by TMDB.
 * Rendered as a child of ArrSearch; card clicks dispatch the
 * 'request-from-discover' event which ArrSearch handles.
 *
 * Using #[Lazy] means Livewire renders a placeholder first, then loads this
 * component independently — so ArrSearch (and its search bar) is never blocked.
 */
#[Lazy]
class ArrDiscover extends Component
{
    public bool $guestMode = false;

    /** @var array<int> */
    public array $guestIntegrationIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $trendingItems = [];

    /** @var array<int, array<string, mixed>> */
    public array $popularMovies = [];

    /** @var array<int, array<string, mixed>> */
    public array $popularTv = [];

    /** @var array<int, array<string, mixed>> */
    public array $upcomingMovies = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $movieGenres = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $tvGenres = [];

    /** @var array<int> */
    public array $browseGenreIds = [];

    public ?string $browseGenreType = null;

    public int $browsePage = 1;

    public int $browseTotalPages = 0;

    /** @var array<int, array<string, mixed>> */
    public array $browseResults = [];

    public bool $browseLoading = false;

    public bool $loadFailed = false;

    // ── Browse Filters ────────────────────────────────────────────────────────

    public string $sortBy = 'popularity';

    public ?int $yearFrom = null;

    public ?int $yearTo = null;

    public float $minRating = 0;

    public int $minVoteCount = 0;

    public ?int $minRuntime = null;

    public ?int $maxRuntime = null;

    public string $originalLanguage = '';

    public string $watchRegion = 'US';

    /** @var array<int, int> */
    public array $selectedProviders = [];

    /** @var array<int, int> */
    public array $tvStatuses = [];

    /** @var array<int, array{id: int, name: string, logo: string|null}> */
    public array $availableProviders = [];

    public function mount(bool $guestMode = false, array $guestIntegrationIds = []): void
    {
        $this->guestMode = $guestMode;
        $this->guestIntegrationIds = $guestIntegrationIds;

        $this->fetchDiscover();
    }

    public function reload(): void
    {
        $this->loadFailed = false;
        $this->fetchDiscover();
    }

    private function fetchDiscover(): void
    {
        $tmdb = app(TmdbService::class);

        if (! $tmdb->isConfigured()) {
            return;
        }

        try {
            $libraryIds = $this->loadLibraryTmdbIds();

            $crossRef = function (array $items) use ($libraryIds): array {
                return array_map(function ($item) use ($libraryIds) {
                    $tmdbId = (int) ($item['tmdb_id'] ?? 0);
                    $item['existsInLibrary'] = array_key_exists($tmdbId, $libraryIds);
                    $item['isDownloaded'] = $libraryIds[$tmdbId] ?? false;

                    return $item;
                }, $items);
            };

            $this->trendingItems = $crossRef($tmdb->getTrending());
            $this->popularMovies = $crossRef($tmdb->getPopularMovies());
            $this->popularTv = $crossRef($tmdb->getPopularTv());
            $this->upcomingMovies = $crossRef($tmdb->getUpcomingMovies());
            $this->movieGenres = $tmdb->getMovieGenres();
            $this->tvGenres = $tmdb->getTvGenres();
        } catch (\Exception) {
            $this->loadFailed = true;
        }
    }

    /**
     * @return Collection<int, ArrIntegration>
     */
    public function getIntegrationsProperty(): Collection
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

    /**
     * Toggle a genre chip on/off. Switching tabs (movie ↔ TV) clears the
     * selection first since the genre ID sets are different.
     * Uses TMDB OR logic (pipe-separated) so results match ANY selected genre.
     */
    public function toggleBrowseGenre(int $genreId, string $type): void
    {
        if ($this->browseGenreType !== null && $this->browseGenreType !== $type) {
            $this->browseGenreIds = [];
        }

        $this->browseGenreType = $type;

        $key = array_search($genreId, $this->browseGenreIds);
        if ($key !== false) {
            array_splice($this->browseGenreIds, (int) $key, 1);
        } else {
            $this->browseGenreIds[] = $genreId;
        }

        if (empty($this->browseGenreIds)) {
            $this->clearBrowse();

            return;
        }

        $this->browsePage = 1;
        $this->browseTotalPages = 0;
        $this->browseLoading = true;
        $this->browseResults = [];
        $this->fetchBrowseResults();
    }

    public function clearBrowse(): void
    {
        $this->browseGenreIds = [];
        $this->browseGenreType = null;
        $this->browsePage = 1;
        $this->browseTotalPages = 0;
        $this->browseResults = [];
        $this->browseLoading = false;
    }

    public function goToBrowsePage(int $page): void
    {
        if (empty($this->browseGenreIds) || $this->browseGenreType === null) {
            return;
        }

        $this->browsePage = max(1, min($page, $this->browseTotalPages ?: $page));
        $this->fetchBrowseResults();
    }

    public function reloadBrowse(): void
    {
        if (! empty($this->browseGenreIds) && $this->browseGenreType !== null) {
            $this->fetchBrowseResults();
        }
    }

    /**
     * Fetch TMDB discover results for the current genre selection and page.
     * Shared by toggleBrowseGenre, goToBrowsePage, and reloadBrowse.
     */
    private function fetchBrowseResults(): void
    {
        $type = $this->browseGenreType;
        $tmdb = app(TmdbService::class);
        $libraryIds = $this->loadLibraryTmdbIds();

        $this->availableProviders = $tmdb->getWatchProviders($type === 'tv' ? 'tv' : 'movie', $this->watchRegion ?: 'US');

        $params = array_merge(
            ['with_genres' => implode(',', $this->browseGenreIds), 'page' => $this->browsePage],
            $this->buildFilterParams($type)
        );

        $response = $type === 'tv'
            ? $tmdb->discoverTv($params)
            : $tmdb->discoverMovies($params);

        $this->browseResults = array_map(function ($item) use ($libraryIds) {
            $tmdbId = (int) ($item['tmdb_id'] ?? 0);
            $item['existsInLibrary'] = array_key_exists($tmdbId, $libraryIds);
            $item['isDownloaded'] = $libraryIds[$tmdbId] ?? false;

            return $item;
        }, $response['results']);

        $this->browseTotalPages = $response['total_pages'];
        $this->browseLoading = false;
    }

    public function resetFilters(): void
    {
        $this->sortBy = 'popularity';
        $this->yearFrom = null;
        $this->yearTo = null;
        $this->minRating = 0;
        $this->minVoteCount = 0;
        $this->minRuntime = null;
        $this->maxRuntime = null;
        $this->originalLanguage = '';
        $this->selectedProviders = [];
        $this->tvStatuses = [];
        $this->reloadBrowse();
    }

    public function toggleProvider(int $providerId): void
    {
        $key = array_search($providerId, $this->selectedProviders);

        if ($key !== false) {
            array_splice($this->selectedProviders, $key, 1);
        } else {
            $this->selectedProviders[] = $providerId;
        }
    }

    public function toggleTvStatus(int $status): void
    {
        $key = array_search($status, $this->tvStatuses);

        if ($key !== false) {
            array_splice($this->tvStatuses, $key, 1);
        } else {
            $this->tvStatuses[] = $status;
        }
    }

    public function updatedSortBy(): void
    {
        $this->reloadBrowse();
    }

    public function updatedOriginalLanguage(): void
    {
        $this->reloadBrowse();
    }

    public function updatedWatchRegion(): void
    {
        if (! empty($this->browseGenreIds) && $this->browseGenreType !== null) {
            $tmdb = app(TmdbService::class);
            $this->availableProviders = $tmdb->getWatchProviders(
                $this->browseGenreType === 'tv' ? 'tv' : 'movie',
                $this->watchRegion ?: 'US'
            );
        }

        $this->reloadBrowse();
    }

    /**
     * Dispatch to the parent ArrSearch component to open the detail modal.
     * Passes the title so ArrSearch can fall back to a title search if the
     * tmdb:/tvdb: lookup returns nothing (common for newer or foreign titles).
     */
    public function requestFromDiscover(int $tmdbId, string $mediaType): void
    {
        $allItems = array_merge(
            $this->trendingItems,
            $this->popularMovies,
            $this->popularTv,
            $this->upcomingMovies,
            $this->browseResults,
        );

        $title = null;
        foreach ($allItems as $item) {
            if ((int) ($item['tmdb_id'] ?? 0) === $tmdbId) {
                $title = $item['title'] ?? null;
                break;
            }
        }

        $this->dispatch('request-from-discover', tmdbId: $tmdbId, mediaType: $mediaType, title: $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilterParams(string $type): array
    {
        $params = [];

        $sortMap = [
            'popularity' => 'popularity.desc',
            'rating' => 'vote_average.desc',
            'votes' => 'vote_count.desc',
            'newest' => $type === 'tv' ? 'first_air_date.desc' : 'primary_release_date.desc',
            'oldest' => $type === 'tv' ? 'first_air_date.asc' : 'primary_release_date.asc',
            'revenue' => 'revenue.desc',
        ];
        $params['sort_by'] = $sortMap[$this->sortBy] ?? 'popularity.desc';

        if ($this->yearFrom) {
            $params[$type === 'tv' ? 'first_air_date.gte' : 'primary_release_date.gte'] = $this->yearFrom.'-01-01';
        }

        if ($this->yearTo) {
            $params[$type === 'tv' ? 'first_air_date.lte' : 'primary_release_date.lte'] = $this->yearTo.'-12-31';
        }

        if ($this->minRating > 0) {
            $params['vote_average.gte'] = $this->minRating;
        }

        $voteCountFloor = $this->minVoteCount > 0 ? $this->minVoteCount : ($this->minRating > 0 ? 50 : 0);

        if ($voteCountFloor > 0) {
            $params['vote_count.gte'] = $voteCountFloor;
        }

        if ($this->minRuntime !== null && $this->minRuntime > 0) {
            $params['with_runtime.gte'] = $this->minRuntime;
        }

        if ($this->maxRuntime !== null && $this->maxRuntime > 0) {
            $params['with_runtime.lte'] = $this->maxRuntime;
        }

        if ($this->originalLanguage !== '') {
            $params['with_original_language'] = $this->originalLanguage;
        }

        if (! empty($this->selectedProviders)) {
            $params['with_watch_providers'] = implode('|', $this->selectedProviders);
            $params['watch_region'] = $this->watchRegion ?: 'US';
        }

        if ($type === 'tv' && ! empty($this->tvStatuses)) {
            $params['with_status'] = implode('|', $this->tvStatuses);
        }

        return $params;
    }

    private function loadLibraryTmdbIds(): array
    {
        $map = [];

        foreach ($this->integrations as $integration) {
            $cacheKey = "arr_library_tmdb_ids_{$integration->id}";
            $integrationMap = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($integration) {
                try {
                    return ArrService::make($integration)->fetchLibraryTmdbIds();
                } catch (\Exception) {
                    return [];
                }
            });

            foreach ($integrationMap as $tmdbId => $isDownloaded) {
                $map[$tmdbId] = ($map[$tmdbId] ?? false) || $isDownloaded;
            }
        }

        return $map;
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.partials.arr-discover-placeholder');
    }

    public function render(): View
    {
        return view('livewire.arr-discover');
    }
}
