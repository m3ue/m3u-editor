<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\MergeEpisodes;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\UnmergeEpisodes;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SeriesFailover;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([
        PlaylistCreated::class,
        PlaylistUpdated::class,
        PlaylistDeleted::class,
    ]);
    Notification::fake();
});

function runMergeEpisodes(...$arguments): void
{
    Episode::withoutEvents(fn () => (new MergeEpisodes(...$arguments))->handle());
}

function runUnmergeEpisodes(...$arguments): void
{
    Episode::withoutEvents(fn () => (new UnmergeEpisodes(...$arguments))->handle());
}

it('merges episodes by episode tmdb id when present', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $seriesA = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist1->id,
        'tmdb_id' => 111,
    ]);
    $seriesB = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist2->id,
        'tmdb_id' => 222,
    ]);

    $master = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'series_id' => $seriesA->id,
        'tmdb_id' => 9001,
        'season' => 1,
        'episode_num' => 1,
    ]);
    $failover = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'series_id' => $seriesB->id,
        'tmdb_id' => 9001,
        'season' => 9,
        'episode_num' => 9,
    ]);

    runMergeEpisodes(
        user: $user,
        playlists: collect([
            ['playlist_failover_id' => $playlist1->id],
            ['playlist_failover_id' => $playlist2->id],
        ]),
        playlistId: $playlist1->id,
    );

    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $master->id,
        'episode_failover_id' => $failover->id,
    ]);
});

it('falls back to series tmdb id season and episode number', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $seriesA = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist1->id,
        'tmdb_id' => 333,
    ]);
    $seriesB = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist2->id,
        'tmdb_id' => 333,
    ]);

    $master = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'series_id' => $seriesA->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 1,
    ]);
    $failover = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'series_id' => $seriesB->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 1,
    ]);
    $differentEpisode = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'series_id' => $seriesB->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 2,
    ]);

    runMergeEpisodes(
        user: $user,
        playlists: collect([
            ['playlist_failover_id' => $playlist1->id],
            ['playlist_failover_id' => $playlist2->id],
        ]),
        playlistId: $playlist1->id,
    );

    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $master->id,
        'episode_failover_id' => $failover->id,
    ]);
    $this->assertDatabaseMissing('episode_failovers', [
        'episode_id' => $master->id,
        'episode_failover_id' => $differentEpisode->id,
    ]);
});

it('does not merge by series tmdb id alone', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $seriesA = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist1->id,
        'tmdb_id' => 444,
    ]);
    $seriesB = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist2->id,
        'tmdb_id' => 444,
    ]);

    Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'series_id' => $seriesA->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 1,
    ]);
    Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'series_id' => $seriesB->id,
        'tmdb_id' => null,
        'season' => 1,
        'episode_num' => 2,
    ]);

    runMergeEpisodes(
        user: $user,
        playlists: collect([
            ['playlist_failover_id' => $playlist1->id],
            ['playlist_failover_id' => $playlist2->id],
        ]),
        playlistId: $playlist1->id,
    );

    $this->assertDatabaseCount('episode_failovers', 0);
});

it('unmerges episode failovers for a playlist and reactivates disabled failovers', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $otherPlaylist = Playlist::factory()->for($user)->createQuietly();
    $series = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist->id,
    ]);
    $otherSeries = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $otherPlaylist->id,
    ]);

    $master = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
    ]);
    $failover = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'enabled' => false,
    ]);
    $otherMaster = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $otherPlaylist->id,
        'series_id' => $otherSeries->id,
    ]);
    $otherFailover = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $otherPlaylist->id,
        'series_id' => $otherSeries->id,
        'enabled' => false,
    ]);

    EpisodeFailover::create([
        'user_id' => $user->id,
        'episode_id' => $master->id,
        'episode_failover_id' => $failover->id,
        'sort' => 1,
    ]);
    EpisodeFailover::create([
        'user_id' => $user->id,
        'episode_id' => $otherMaster->id,
        'episode_failover_id' => $otherFailover->id,
        'sort' => 1,
    ]);

    runUnmergeEpisodes(
        user: $user,
        playlistId: $playlist->id,
        reactivateEpisodes: true,
    );

    $this->assertDatabaseMissing('episode_failovers', [
        'episode_id' => $master->id,
        'episode_failover_id' => $failover->id,
    ]);
    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $otherMaster->id,
        'episode_failover_id' => $otherFailover->id,
    ]);
    $this->assertTrue($failover->refresh()->enabled);
    $this->assertFalse($otherFailover->refresh()->enabled);
});

it('exposes ordered failover episodes on the episode model', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $series = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist->id,
        'tmdb_id' => 555,
    ]);

    $master = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
    ]);
    $late = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
    ]);
    $early = Episode::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
    ]);

    EpisodeFailover::create([
        'user_id' => $user->id,
        'episode_id' => $master->id,
        'episode_failover_id' => $late->id,
        'sort' => 2,
    ]);
    EpisodeFailover::create([
        'user_id' => $user->id,
        'episode_id' => $master->id,
        'episode_failover_id' => $early->id,
        'sort' => 1,
    ]);

    $this->assertSame([$early->id, $late->id], $master->failoverEpisodes()->pluck('episodes.id')->all());
});

it('keeps an episode that backs up another playlist eligible as a master in its own merge', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->createQuietly();
    $playlistB = Playlist::factory()->for($user)->createQuietly();
    $playlistC = Playlist::factory()->for($user)->createQuietly();

    $episodeFor = function (Playlist $playlist) use ($user): Episode {
        $series = Series::factory()->for($user)->createQuietly([
            'playlist_id' => $playlist->id,
            'tmdb_id' => 4242,
        ]);

        return Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
            'tmdb_id' => null,
            'season' => 1,
            'episode_num' => 1,
        ]);
    };

    $episodeA = $episodeFor($playlistA);
    $episodeB = $episodeFor($playlistB);
    $episodeC = $episodeFor($playlistC);

    // Created by B's merge (playlists B + A, B preferred).
    EpisodeFailover::create([
        'user_id' => $user->id,
        'episode_id' => $episodeB->id,
        'episode_failover_id' => $episodeA->id,
        'sort' => 1,
    ]);

    runMergeEpisodes(
        user: $user,
        playlists: collect([['playlist_failover_id' => $playlistA->id], ['playlist_failover_id' => $playlistC->id]]),
        playlistId: $playlistA->id,
    );

    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $episodeA->id,
        'episode_failover_id' => $episodeC->id,
    ]);
    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $episodeB->id,
        'episode_failover_id' => $episodeA->id,
    ]);
});

/**
 * @param  array<string, mixed>  $seriesAttributes
 * @param  array<int, array{0: int, 1: int, 2?: int|null}>  $episodes  season, episode_num, optional tmdb_id
 * @return array{series: Series, episodes: array<int, Episode>}
 */
function mergeEpisodesMakeSeries(User $user, Playlist $playlist, array $seriesAttributes, array $episodes): array
{
    $series = Series::factory()->for($user)->createQuietly([
        'playlist_id' => $playlist->id,
        'tmdb_id' => null,
        'tvdb_id' => null,
        ...$seriesAttributes,
    ]);

    $created = [];
    foreach ($episodes as $episode) {
        $created[] = Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
            'season' => $episode[0],
            'episode_num' => $episode[1],
            'tmdb_id' => $episode[2] ?? null,
        ]);
    }

    return ['series' => $series, 'episodes' => $created];
}

function mergeEpisodesPlaylists(Playlist ...$playlists): Collection
{
    return collect($playlists)->map(fn (Playlist $playlist): array => ['playlist_failover_id' => $playlist->id]);
}

it('links series by tmdb id and pairs episodes when only one side has episode tmdb ids', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $backup = Playlist::factory()->for($user)->createQuietly();

    $a = mergeEpisodesMakeSeries($user, $primary, ['tmdb_id' => 500], [[1, 1, 7001], [1, 2, 7002]]);
    $b = mergeEpisodesMakeSeries($user, $backup, ['tmdb_id' => 500], [[1, 1], [1, 2], [1, 3]]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($primary, $backup), playlistId: $primary->id);

    $this->assertDatabaseHas('series_failovers', [
        'series_id' => $a['series']->id,
        'series_failover_id' => $b['series']->id,
        'sort' => 1,
    ]);
    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id])
        ->and($a['episodes'][1]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][1]->id]);
    $this->assertDatabaseCount('episode_failovers', 2);
});

it('links series by tvdb id when tmdb id is missing', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $backup = Playlist::factory()->for($user)->createQuietly();

    $a = mergeEpisodesMakeSeries($user, $primary, ['tvdb_id' => '81189'], [[2, 5]]);
    $b = mergeEpisodesMakeSeries($user, $backup, ['tvdb_id' => '81189'], [[2, 5]]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($primary, $backup), playlistId: $primary->id);

    $this->assertDatabaseHas('series_failovers', [
        'series_id' => $a['series']->id,
        'series_failover_id' => $b['series']->id,
    ]);
    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $a['episodes'][0]->id,
        'episode_failover_id' => $b['episodes'][0]->id,
    ]);
});

it('pairs by episode tmdb id across numbering differences and skips conflicting or ambiguous numbers', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $backup = Playlist::factory()->for($user)->createQuietly();

    $a = mergeEpisodesMakeSeries($user, $primary, ['tmdb_id' => 600], [
        [0, 1, 8001], // special numbered differently by the backup
        [1, 1, 8002], // backup has a different episode at S1E1
        [1, 2],       // backup lists S1E2 twice
    ]);
    $b = mergeEpisodesMakeSeries($user, $backup, ['tmdb_id' => 600], [
        [1, 99, 8001],
        [1, 1, 9999],
        [1, 2],
        [1, 2],
    ]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($primary, $backup), playlistId: $primary->id);

    expect($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id])
        ->and($a['episodes'][1]->failoverEpisodes()->count())->toBe(0)
        ->and($a['episodes'][2]->failoverEpisodes()->count())->toBe(0);
});

it('orders failovers by playlist priority and is stable when run again', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $first = Playlist::factory()->for($user)->createQuietly();
    $second = Playlist::factory()->for($user)->createQuietly();

    $a = mergeEpisodesMakeSeries($user, $primary, ['tmdb_id' => 700], [[1, 1]]);
    $c = mergeEpisodesMakeSeries($user, $second, ['tmdb_id' => 700], [[1, 1]]);
    $b = mergeEpisodesMakeSeries($user, $first, ['tmdb_id' => 700], [[1, 1]]);

    $merge = fn () => runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($first, $second), playlistId: $primary->id);
    $merge();
    $merge();

    expect($a['series']->failoverSeries()->pluck('series.id')->all())->toBe([$b['series']->id, $c['series']->id])
        ->and($a['episodes'][0]->failoverEpisodes()->pluck('episodes.id')->all())->toBe([$b['episodes'][0]->id, $c['episodes'][0]->id]);
    $this->assertDatabaseCount('series_failovers', 2);
    $this->assertDatabaseCount('episode_failovers', 2);
});

it('moves links to the new master on a forced re-merge with a different preferred playlist', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->createQuietly();
    $playlistB = Playlist::factory()->for($user)->createQuietly();

    $a = mergeEpisodesMakeSeries($user, $playlistA, ['tmdb_id' => 800], [[1, 1]]);
    $b = mergeEpisodesMakeSeries($user, $playlistB, ['tmdb_id' => 800], [[1, 1]]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($playlistB), playlistId: $playlistA->id);

    // Without force, the existing master stays put.
    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($playlistA), playlistId: $playlistB->id);
    $this->assertDatabaseHas('series_failovers', ['series_id' => $a['series']->id, 'series_failover_id' => $b['series']->id]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($playlistA), playlistId: $playlistB->id, forceCompleteRemerge: true);

    $this->assertDatabaseCount('series_failovers', 1);
    $this->assertDatabaseHas('series_failovers', ['series_id' => $b['series']->id, 'series_failover_id' => $a['series']->id]);
    $this->assertDatabaseCount('episode_failovers', 1);
    $this->assertDatabaseHas('episode_failovers', [
        'episode_id' => $b['episodes'][0]->id,
        'episode_failover_id' => $a['episodes'][0]->id,
    ]);
});

it('removes series links when unmerging', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $backup = Playlist::factory()->for($user)->createQuietly();

    mergeEpisodesMakeSeries($user, $primary, ['tmdb_id' => 900], [[1, 1]]);
    mergeEpisodesMakeSeries($user, $backup, ['tmdb_id' => 900], [[1, 1]]);

    runMergeEpisodes(user: $user, playlists: mergeEpisodesPlaylists($backup), playlistId: $primary->id);
    $this->assertDatabaseCount('series_failovers', 1);

    runUnmergeEpisodes(user: $user, playlistId: $primary->id);

    $this->assertDatabaseCount('series_failovers', 0);
    $this->assertDatabaseCount('episode_failovers', 0);
});

it('makes disabled series linked as a failover eligible for a metadata fetch', function () {
    $user = User::factory()->create();
    $primary = Playlist::factory()->for($user)->createQuietly();
    $backup = Playlist::factory()->for($user)->createQuietly();

    $primarySeries = Series::factory()->for($user)->createQuietly(['playlist_id' => $primary->id, 'enabled' => true]);
    $linkedBackup = Series::factory()->for($user)->createQuietly(['playlist_id' => $backup->id, 'enabled' => false]);
    $unlinkedBackup = Series::factory()->for($user)->createQuietly(['playlist_id' => $backup->id, 'enabled' => false]);

    SeriesFailover::create([
        'user_id' => $user->id,
        'series_id' => $primarySeries->id,
        'series_failover_id' => $linkedBackup->id,
        'sort' => 1,
    ]);

    $eligibleIds = ProcessM3uImportSeriesEpisodes::eligibleSeriesQuery($user->id, $backup->id, true)->pluck('id')->all();

    expect($eligibleIds)->toContain($linkedBackup->id)
        ->not->toContain($unlinkedBackup->id);
});
