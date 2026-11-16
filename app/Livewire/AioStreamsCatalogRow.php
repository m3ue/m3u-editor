<?php

namespace App\Livewire;

use App\Models\MediaServerIntegration;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * A single AIOStreams catalog section. Renders its section/heading immediately
 * (cheap - no HTTP), but defers the catalog fetch itself until the browser reports
 * (via Alpine's x-intersect) that the row has scrolled into view, so pages with many
 * catalogs don't pay for every catalog's round trip up front.
 *
 * Further pages load on demand through "Load more", which asks AIOStreams for the
 * next page using the Stremio `skip` extra (an item offset into the catalog).
 */
class AioStreamsCatalogRow extends Component
{
    public int $integrationId;

    public string $catalogId;

    public string $catalogType;

    public string $catalogName;

    /** @var array<int, array<string, mixed>> */
    public array $items = [];

    public bool $loaded = false;

    public bool $loadFailed = false;

    /**
     * Offset of the next page, counted in items AIOStreams has returned (before
     * de-duplication), since that is what the addon's `skip` extra pages by.
     */
    public int $skip = 0;

    public bool $hasMore = false;

    public function mount(int $integrationId, string $catalogId, string $catalogType, string $catalogName): void
    {
        $this->integrationId = $integrationId;
        $this->catalogId = $catalogId;
        $this->catalogType = $catalogType;
        $this->catalogName = $catalogName;
    }

    /**
     * Triggered client-side once the row scrolls into view. Guarded so repeated
     * intersection events (e.g. scrolling past and back) don't refetch.
     */
    public function loadIfVisible(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $page = $this->fetchPage(0);

        if ($page === null) {
            $this->loadFailed = true;

            return;
        }

        $this->appendPage($page);
    }

    /**
     * Fetch and append the next page of the catalog.
     */
    public function loadMore(): void
    {
        if (! $this->loaded || $this->loadFailed || ! $this->hasMore) {
            return;
        }

        $page = $this->fetchPage($this->skip);

        if ($page === null) {
            Notification::make()->danger()->title(__('Failed to load more items'))->send();

            return;
        }

        $this->appendPage($page);
    }

    /**
     * Append a page's items, skipping ones already shown. A page that is empty or
     * adds nothing new ends paging, so a catalog that ignores `skip` (and keeps
     * returning its first page) can't loop forever.
     *
     * @param  array<int, array<string, mixed>>  $page
     */
    protected function appendPage(array $page): void
    {
        $this->skip += count($page);

        $knownIds = array_flip(array_column($this->items, 'id'));
        $newItems = array_values(array_filter(
            $page,
            fn (array $item): bool => ! isset($knownIds[$item['id'] ?? null]),
        ));

        $this->items = [...$this->items, ...$newItems];
        $this->hasMore = $newItems !== [];
    }

    /**
     * Fetch one page of metas, cached per offset for five minutes.
     *
     * @return array<int, array<string, mixed>>|null Null when the fetch failed.
     */
    protected function fetchPage(int $skip): ?array
    {
        $cacheKey = "aiostreams_row_{$this->integrationId}_{$this->catalogId}_{$this->catalogType}";
        if ($skip > 0) {
            $cacheKey .= "_skip{$skip}";
        }

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $integration = MediaServerIntegration::query()
            ->where('id', $this->integrationId)
            ->where('type', 'aiostreams')
            ->first();

        if (! $integration || ! $integration->manifest_base_url) {
            return null;
        }

        $path = "catalog/{$this->catalogType}/{$this->catalogId}";
        if ($skip > 0) {
            $path .= "/skip={$skip}";
        }

        try {
            $response = Http::timeout(15)->get("{$integration->manifest_base_url}/{$path}.json");
        } catch (\Exception) {
            return null;
        }

        $items = $response->successful()
            ? array_values(array_filter(
                $response->json()['metas'] ?? [],
                fn (mixed $item): bool => is_array($item) && filled($item['id'] ?? null),
            ))
            : [];

        Cache::put($cacheKey, $items, now()->addMinutes(5));

        return $items;
    }

    public function render(): View
    {
        return view('livewire.aio-streams-catalog-row');
    }
}
