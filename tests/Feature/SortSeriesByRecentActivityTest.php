<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\SortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->travelTo(now()->setDate(2026, 9, 27)->startOfDay());

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $this->service = new SortService;

    $this->makeSeries = function (?string $releaseDate, ?Category $category = null): Series {
        return Series::factory()->for($this->user)->for($this->playlist)->for($category ?? $this->category, 'category')->create([
            'release_date' => $releaseDate,
            'sort' => 99,
        ]);
    };

    $this->makeEpisode = function (Series $series, array $attributes = []): Episode {
        $season = Season::factory()->for($this->user)->for($this->playlist)->for($series)->create(['category_id' => $series->category_id]);

        return Episode::factory()->for($this->user)->for($this->playlist)->for($series)->for($season)->create($attributes);
    };
});

it('ranks a series with a newer episode ahead of a series that premiered more recently', function () {
    // Series A premiered in 2026 and its latest episode was in March; series B
    // premiered in 2025 but got a new season in September - B is more active.
    $seriesA = ($this->makeSeries)('2026-01-10');
    ($this->makeEpisode)($seriesA, ['info' => ['release_date' => '2026-03-15']]);
    $seriesB = ($this->makeSeries)('2025-01-01');
    ($this->makeEpisode)($seriesB, ['info' => ['release_date' => '2025-02-01']]);
    ($this->makeEpisode)($seriesB, ['info' => ['release_date' => '2026-09-10']]);

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'DESC');

    expect((int) $seriesB->refresh()->sort)->toBe(1)
        ->and((int) $seriesA->refresh()->sort)->toBe(2);

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'ASC');

    expect((int) $seriesA->refresh()->sort)->toBe(1)
        ->and((int) $seriesB->refresh()->sort)->toBe(2);
});

it('reads TMDB and AIOStreams episode air dates, ignoring unaired episodes', function () {
    $tmdb = ($this->makeSeries)(null);
    ($this->makeEpisode)($tmdb, ['info' => ['releasedate' => '2026-06-01']]);
    $aio = ($this->makeSeries)(null);
    ($this->makeEpisode)($aio, ['aio_air_date' => '2026-08-01 20:00:00']);
    // Only upcoming episodes (after "today"), so it falls back to its own release date.
    $upcoming = ($this->makeSeries)('2020-05-05');
    ($this->makeEpisode)($upcoming, ['aio_air_date' => '2026-12-01 20:00:00', 'info' => ['release_date' => '2026-12-01']]);

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'DESC');

    expect((int) $aio->refresh()->sort)->toBe(1)
        ->and((int) $tmdb->refresh()->sort)->toBe(2)
        ->and((int) $upcoming->refresh()->sort)->toBe(3);
});

it('falls back to the series release date and sinks undated series in both directions', function () {
    $withEpisode = ($this->makeSeries)('2010-01-01');
    ($this->makeEpisode)($withEpisode, ['info' => ['release_date' => '2024-01-01']]);
    $releaseDateOnly = ($this->makeSeries)('2022-01-01');
    $undated = ($this->makeSeries)(null);
    ($this->makeEpisode)($undated, ['info' => ['release_date' => null]]);

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'DESC');

    expect((int) $withEpisode->refresh()->sort)->toBe(1)
        ->and((int) $releaseDateOnly->refresh()->sort)->toBe(2)
        ->and((int) $undated->refresh()->sort)->toBe(3);

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'ASC');

    expect((int) $releaseDateOnly->refresh()->sort)->toBe(1)
        ->and((int) $withEpisode->refresh()->sort)->toBe(2)
        ->and((int) $undated->refresh()->sort)->toBe(3);
});

it('applies the same date cleanup to the series release date fallback', function () {
    // Emby/Jellyfin store PremiereDate as a full date-time; it should compare
    // on its date alone, and ties fall back to id order.
    $datetime = ($this->makeSeries)('2024-05-01T00:00:00.0000000Z');
    $dateOnly = ($this->makeSeries)('2024-05-01');
    // A future premiere with no aired episodes has no activity yet.
    $upcoming = ($this->makeSeries)('2027-01-01');

    $this->service->bulkSortPlaylistSeriesByRecentActivity($this->playlist, 'DESC');

    expect($datetime->refresh()->sort)->toBe(1)
        ->and($dateOnly->refresh()->sort)->toBe(2)
        ->and($upcoming->refresh()->sort)->toBe(3);
});

it('only re-sorts series in the given category', function () {
    $other = Category::factory()->for($this->user)->for($this->playlist)->create();
    $old = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($old, ['info' => ['release_date' => '2001-01-01']]);
    $new = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($new, ['info' => ['release_date' => '2025-01-01']]);
    $untouched = ($this->makeSeries)('2026-01-01', $other);

    $this->service->bulkSortCategorySeriesByRecentActivity($this->category, 'DESC');

    expect((int) $new->refresh()->sort)->toBe(1)
        ->and((int) $old->refresh()->sort)->toBe(2)
        ->and((int) $untouched->refresh()->sort)->toBe(99);
});

it('runs recent activity rules from the playlist sort config', function () {
    $old = ($this->makeSeries)('2024-01-01');
    $new = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($new, ['info' => ['release_date' => '2026-09-01']]);

    $this->playlist->update(['sort_alpha_config' => [
        ['enabled' => true, 'target' => 'series_categories', 'group' => ['all'], 'column' => 'recent_activity', 'sort' => 'DESC'],
    ]]);

    (new RunPlaylistSortAlpha($this->playlist->refresh()))->handle();

    expect((int) $new->refresh()->sort)->toBe(1)
        ->and((int) $old->refresh()->sort)->toBe(2);
});

it('sorts categories by recent activity via the bulk action', function () {
    $old = ($this->makeSeries)('2024-01-01');
    $new = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($new, ['info' => ['release_date' => '2026-09-01']]);

    Livewire::test(ListCategories::class)
        ->loadTable()
        ->callTableBulkAction('sort_release_date_bulk', [$this->category], ['column' => 'recent_activity', 'sort' => 'DESC'])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Series Sorted by Most Recent Activity');

    expect((int) $new->refresh()->sort)->toBe(1)
        ->and((int) $old->refresh()->sort)->toBe(2);
});

it('sorts a playlist by recent activity via the Series list header action', function () {
    $old = ($this->makeSeries)('2024-01-01');
    $new = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($new, ['info' => ['release_date' => '2026-09-01']]);

    Livewire::test(ListSeries::class)
        ->callAction('sort_release_date', ['playlist' => $this->playlist->id, 'column' => 'recent_activity', 'sort' => 'DESC'])
        ->assertHasNoActionErrors()
        ->assertNotified('Series Sorted by Most Recent Activity');

    expect((int) $new->refresh()->sort)->toBe(1)
        ->and((int) $old->refresh()->sort)->toBe(2);
});

it('keeps release date as the default sort method on the Series list header action', function () {
    $older = ($this->makeSeries)('2000-01-01');
    ($this->makeEpisode)($older, ['info' => ['release_date' => '2026-09-01']]);
    $newer = ($this->makeSeries)('2024-01-01');

    Livewire::test(ListSeries::class)
        ->callAction('sort_release_date', ['playlist' => $this->playlist->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Series Sorted by Release Date');

    expect((int) $newer->refresh()->sort)->toBe(1)
        ->and((int) $older->refresh()->sort)->toBe(2);
});
