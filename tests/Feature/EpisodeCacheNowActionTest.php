<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function setEnableCacheForEpisodeTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForEpisodeTest(true);
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

/**
 * Series + Season + Episode owned by $user on $playlist.
 */
function makeCacheTestEpisode(User $user, Playlist $playlist, int $seasonNum, int $episodeNum, string $url, ?Series $series = null): Episode
{
    $series ??= Series::factory()->for($user)->for($playlist)->create(['tmdb_id' => 1399]);
    $season = Season::factory()->for($series)->create(['season_number' => $seasonNum]);

    return Episode::factory()->for($series)->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'season_id' => $season->id,
        'season' => $seasonNum,
        'episode_num' => $episodeNum,
        'url' => $url,
    ]);
}

function episodesManager(Series $series)
{
    return Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ListSeries::class,
    ]);
}

// --- episode row action ---

it('shows Cache Now on an episode with a cacheable URL', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    episodesManager($episode->series)->assertTableActionVisible('cache_now', $episode);
});

it('hides Cache Now and the Cached column when caching is off', function () {
    setEnableCacheForEpisodeTest(false);
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 2, 'https://example.com/s1e2.mp4');

    episodesManager($episode->series)
        ->assertTableActionHidden('cache_now', $episode)
        ->assertTableColumnHidden('is_cached');
});

it('hides Cache Now when the episode has no URL', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 3, '');

    episodesManager($episode->series)->assertTableActionHidden('cache_now', $episode);
});

it('renders Cache Now as the last row action, as a small icon button', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');
    $table = episodesManager($episode->series)->instance()->getTable();

    $actions = array_values(array_filter($table->getRecordActions(), fn ($action) => ! $action instanceof ActionGroup));
    $last = end($actions);

    expect($last->getName())->toBe('cache_now')
        ->and($last->isIconButton() || $last->isButton())->toBeTrue()
        ->and($last->isLabelHidden())->toBeTrue()
        ->and($last->getSize())->toBe('sm');
});

it('queues a download when Cache Now is clicked', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 4, 'https://example.com/s1e4.mp4');

    episodesManager($episode->series)
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Cache download queued');

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('says "Already cached" when a playable file exists', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 5, 'https://example.com/s1e5.mp4');
    $file = CachedContentFile::factory()->completed()->forItem($episode)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    episodesManager($episode->series)
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Already cached');
});

it('says "Already queued for caching" while a download is pending', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 6, 'https://example.com/s1e6.mp4');
    CachedContentFile::factory()->forItem($episode)->create();

    episodesManager($episode->series)
        ->callAction(TestAction::make('cache_now')->table($episode))
        ->assertNotified('Already queued for caching');
});

// --- series "Cache all episodes" ---

it('Cache all episodes queues every episode of the series', function () {
    $first = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');
    makeCacheTestEpisode($this->user, $this->playlist, 1, 2, 'https://example.com/s1e2.mp4', $first->series);
    makeCacheTestEpisode($this->user, $this->playlist, 2, 1, 'https://example.com/s2e1.mp4', $first->series);

    Livewire::test(ListSeries::class)
        ->callAction(TestAction::make('cache_all_episodes')->table($first->series))
        ->assertNotified('Queued 3 episodes for caching');

    expect(CachedContentFile::where('status', CachedContentFileStatus::Pending)->count())->toBe(3);
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 3);
});

it('places Cache all episodes directly above Delete on the series row actions', function () {
    $group = collect(SeriesResource::getTableActions())->first(fn ($action) => $action instanceof ActionGroup);
    $names = array_map(fn ($action) => $action->getName(), $group->getActions());

    expect(array_search('cache_all_episodes', $names, true))->toBe(array_search('delete', $names, true) - 1);
});

it('hides Cache all episodes when caching is off', function () {
    setEnableCacheForEpisodeTest(false);
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    Livewire::test(ListSeries::class)->assertTableActionHidden('cache_all_episodes', $episode->series);
});

// --- Episode::isCached() ---

it('isCached() is true only for a Completed row of this episode', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    expect($episode->isCached())->toBeFalse();

    CachedContentFile::factory()->completed()->forItem($episode)->create();

    expect($episode->isCached())->toBeTrue();
});

it('isCached() runs a single query when the series is already loaded', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4')->fresh(['series']);

    DB::enableQueryLog();
    $episode->isCached();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});
