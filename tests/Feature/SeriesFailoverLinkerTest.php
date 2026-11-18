<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SeriesFailover;
use App\Models\User;
use App\Services\SeriesFailoverLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class, PlaylistDeleted::class]);
    Bus::fake([ProcessM3uImportSeriesEpisodes::class]);
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->primary = Playlist::factory()->for($this->user)->createQuietly();
    $this->backup = Playlist::factory()->for($this->user)->createQuietly();
});

/**
 * @param  array<int, array{0: int, 1: int}>  $numbers  season, episode_num
 * @return array{series: Series, episodes: array<int, Episode>}
 */
function seriesFailoverLinkerMakeSeries(User $user, Playlist $playlist, array $numbers, array $attributes = []): array
{
    $series = Series::factory()->for($user)->createQuietly(['playlist_id' => $playlist->id, ...$attributes]);

    $episodes = array_map(fn (array $number): Episode => Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'tmdb_id' => null,
        'season' => $number[0],
        'episode_num' => $number[1],
    ]), $numbers);

    return ['series' => $series, 'episodes' => $episodes];
}

function seriesFailoverLinkerQuietLink(Series $master, Series $failover, int $sort = 1): void
{
    SeriesFailover::withoutEvents(fn () => SeriesFailover::create([
        'user_id' => $master->user_id,
        'series_id' => $master->id,
        'series_failover_id' => $failover->id,
        'sort' => $sort,
    ]));
}

it('links episodes when a series failover is added and unlinks them when it is removed', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1], [1, 2]]);
    $b = seriesFailoverLinkerMakeSeries($this->user, $this->backup, [[1, 1], [1, 2]]);

    $link = SeriesFailover::create([
        'series_id' => $a['series']->id,
        'series_failover_id' => $b['series']->id,
        'sort' => 1,
    ]);

    expect($link->user_id)->toBe($this->user->id)
        ->and($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id])
        ->and($a['episodes'][1]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][1]->id]);

    $link->delete();

    $this->assertDatabaseCount('episode_failovers', 0);
});

it('keeps episode failover order in step with series failover order', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);
    $b = seriesFailoverLinkerMakeSeries($this->user, $this->backup, [[1, 1]]);
    $third = Playlist::factory()->for($this->user)->createQuietly();
    $c = seriesFailoverLinkerMakeSeries($this->user, $third, [[1, 1]]);

    $toB = SeriesFailover::create(['series_id' => $a['series']->id, 'series_failover_id' => $b['series']->id, 'sort' => 1]);
    $toC = SeriesFailover::create(['series_id' => $a['series']->id, 'series_failover_id' => $c['series']->id, 'sort' => 2]);

    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id, $c['episodes'][0]->id]);

    $toC->update(['sort' => 1]);
    $toB->update(['sort' => 2]);

    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$c['episodes'][0]->id, $b['episodes'][0]->id]);
});

it('refuses to link a series to itself', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);

    SeriesFailover::create(['series_id' => $a['series']->id, 'series_failover_id' => $a['series']->id, 'sort' => 1]);

    $this->assertDatabaseCount('series_failovers', 0);
});

it('repairs a missing episode link at play time, at most once per cooldown', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1], [1, 2]]);
    $b = seriesFailoverLinkerMakeSeries($this->user, $this->backup, [[1, 1]]);
    seriesFailoverLinkerQuietLink($a['series'], $b['series']);

    $linker = app(SeriesFailoverLinker::class);
    $linker->repairEpisode($a['episodes'][0]);

    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id]);

    // The backup gains S1E2 later; within the cooldown the next request doesn't re-check.
    $late = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->backup->id,
        'series_id' => $b['series']->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 2,
    ]);

    $linker->repairEpisode($a['episodes'][1]);
    $linker->repairEpisode($a['episodes'][1]);

    expect($a['episodes'][1]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$late->id]);
    Bus::assertNotDispatched(ProcessM3uImportSeriesEpisodes::class);
});

it('queues an episode fetch for a linked backup series that has no episodes yet', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);
    $b = seriesFailoverLinkerMakeSeries($this->user, $this->backup, [], ['enabled' => false]);
    seriesFailoverLinkerQuietLink($a['series'], $b['series']);

    app(SeriesFailoverLinker::class)->repairEpisode($a['episodes'][0]);

    Bus::assertDispatched(ProcessM3uImportSeriesEpisodes::class, fn (ProcessM3uImportSeriesEpisodes $job): bool => $job->playlistSeries?->id === $b['series']->id
        && $job->sync_stream_files === false);
    $this->assertDatabaseCount('episode_failovers', 0);
});

it('does nothing at play time for an episode that already has failovers or whose series has none', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);

    app(SeriesFailoverLinker::class)->repairEpisode($a['episodes'][0]);

    $this->assertDatabaseCount('episode_failovers', 0);
    Bus::assertNotDispatched(ProcessM3uImportSeriesEpisodes::class);
});

it('adds failover series from the Series edit page and links their episodes', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);
    $b = seriesFailoverLinkerMakeSeries($this->user, $this->backup, [[1, 1]]);

    Livewire::test(EditSeries::class, ['record' => $a['series']->getRouteKey()])
        ->assertFormFieldExists('failovers')
        ->set('data.failovers', ['new' => ['series_failover_id' => $b['series']->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('series_failovers', [
        'series_id' => $a['series']->id,
        'series_failover_id' => $b['series']->id,
        'user_id' => $this->user->id,
    ]);
    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id]);
});

it('hides failover series for AIOStreams series', function () {
    $a = seriesFailoverLinkerMakeSeries($this->user, $this->primary, [[1, 1]]);
    $integration = MediaServerIntegration::factory()->create(['user_id' => $this->user->id]);
    $a['series']->updateQuietly(['aio_integration_id' => $integration->id]);

    Livewire::test(EditSeries::class, ['record' => $a['series']->getRouteKey()])
        ->assertFormFieldHidden('failovers');
});
