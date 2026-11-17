<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\QueueCachedContentDownloads;
use App\Models\CachedContentFile;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
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

// --- episode bulk "Cache selected episodes" ---

it('Cache selected episodes queues only the selected episodes, including ones on other table pages', function () {
    $first = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');
    $series = $first->series;
    $others = collect(range(2, 30))->map(fn (int $num): Episode => makeCacheTestEpisode($this->user, $this->playlist, 1, $num, "https://example.com/s1e{$num}.mp4", $series));
    $unselected = makeCacheTestEpisode($this->user, $this->playlist, 2, 1, 'https://example.com/s2e1.mp4', $series);

    // 30 selected episodes with 25 per page: the last 5 are only on page 2.
    $selected = $others->prepend($first)->pluck('id')->sort()->values();

    episodesManager($series)
        ->selectTableRecords($selected->all())
        ->callAction(TestAction::make('cache_selected')->table()->bulk())
        ->assertNotified('Caching started');

    Bus::assertDispatched(QueueCachedContentDownloads::class, fn (QueueCachedContentDownloads $job): bool => $job->type === 'episodes'
        && collect($job->ids)->sort()->values()->all() === $selected->all());

    Bus::dispatched(QueueCachedContentDownloads::class)->first()->handle(app(CachedContentDispatchService::class));

    expect(CachedContentFile::count())->toBe(30)
        ->and($unselected->cachedContentFile()->exists())->toBeFalse();
});

it('Cache selected episodes skips already cached episodes and other users\' episodes', function () {
    $cached = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');
    $fresh = makeCacheTestEpisode($this->user, $this->playlist, 1, 2, 'https://example.com/s1e2.mp4', $cached->series);
    $file = CachedContentFile::factory()->completed()->forItem($cached)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    $otherUser = User::factory()->create();
    $foreign = makeCacheTestEpisode($otherUser, Playlist::factory()->for($otherUser)->create(), 1, 1, 'https://example.com/x.mp4');

    (new QueueCachedContentDownloads('episodes', [$cached->id, $fresh->id, $foreign->id], $this->user->id))
        ->handle(app(CachedContentDispatchService::class));

    expect(CachedContentFile::count())->toBe(2)
        ->and($fresh->cachedContentFile()->exists())->toBeTrue()
        ->and($foreign->cachedContentFile()->exists())->toBeFalse();
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 1);
});

it('hides Cache selected episodes when caching is off', function () {
    setEnableCacheForEpisodeTest(false);
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    episodesManager($episode->series)->assertActionHidden(TestAction::make('cache_selected')->table()->bulk());
});

// --- series "Cache seasons" ---

it('Cache seasons queues only the chosen season', function () {
    $first = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');
    $series = $first->series;
    $seasonTwo = Season::factory()->for($series)->create(['season_number' => 2]);
    $chosen = collect([1, 2])->map(fn (int $num): Episode => Episode::factory()->for($series)->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season_id' => $seasonTwo->id,
        'season' => 2,
        'episode_num' => $num,
        'url' => "https://example.com/s2e{$num}.mp4",
    ]));

    Livewire::test(ListSeries::class)
        ->callAction(TestAction::make('cache_seasons')->table($series), ['seasons' => [2]])
        ->assertHasNoFormErrors()
        ->assertNotified('Queued 2 episodes for caching');

    expect(CachedContentFile::count())->toBe(2)
        ->and($first->cachedContentFile()->exists())->toBeFalse()
        ->and($chosen->every(fn (Episode $episode): bool => $episode->cachedContentFile()->exists()))->toBeTrue();
});

it('Cache seasons requires at least one season', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    Livewire::test(ListSeries::class)
        ->callAction(TestAction::make('cache_seasons')->table($episode->series), ['seasons' => []])
        ->assertHasFormErrors(['seasons' => 'required']);

    expect(CachedContentFile::count())->toBe(0);
});

it('Cache seasons is available from the series view page', function () {
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    Livewire::test(ViewSeries::class, ['record' => $episode->series_id])
        ->callAction('cache_seasons', ['seasons' => [1]])
        ->assertNotified('Queued 1 episode for caching');

    expect($episode->cachedContentFile()->exists())->toBeTrue();
});

it('disables the series cache actions until the series has episodes', function () {
    $empty = Series::factory()->for($this->user)->for($this->playlist)->create();
    $withEpisodes = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4')->series;

    Livewire::test(ListSeries::class)
        ->assertActionDisabled(TestAction::make('cache_seasons')->table($empty))
        ->assertActionDisabled(TestAction::make('cache_all_episodes')->table($empty))
        ->assertActionEnabled(TestAction::make('cache_seasons')->table($withEpisodes))
        ->assertActionEnabled(TestAction::make('cache_all_episodes')->table($withEpisodes));

    Livewire::test(ViewSeries::class, ['record' => $empty->id])
        ->assertActionDisabled('cache_seasons');

    Livewire::test(ViewSeries::class, ['record' => $withEpisodes->id])
        ->assertActionEnabled('cache_seasons');
});

it('checks the series list cache actions without a query per row', function () {
    $series = collect(range(1, 5))->map(fn (int $num): Series => makeCacheTestEpisode($this->user, $this->playlist, 1, $num, "https://example.com/{$num}.mp4")->series);

    $component = Livewire::test(ListSeries::class);

    DB::enableQueryLog();
    $series->each(fn (Series $record) => $component->assertActionEnabled(TestAction::make('cache_seasons')->table($record)));
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_starts_with($sql, 'select exists'));
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});

it('hides Cache seasons when caching is off', function () {
    setEnableCacheForEpisodeTest(false);
    $episode = makeCacheTestEpisode($this->user, $this->playlist, 1, 1, 'https://example.com/s1e1.mp4');

    Livewire::test(ListSeries::class)->assertTableActionHidden('cache_seasons', $episode->series);
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
