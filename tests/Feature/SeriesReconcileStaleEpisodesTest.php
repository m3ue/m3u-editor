<?php

/**
 * Series::fetchMetadata() previously only ever upserted episodes, never removed
 * any, so a provider that drops/renumbers episodes left permanently-dead rows
 * behind (stream URL 404s forever). This covers the fix: stale provider-sourced
 * episodes are reconciled away, but strictly scoped to the seasons the provider
 * actually answered for this fetch (see PR #1479, which reconciled the whole
 * series unconditionally - a truncated/partial provider response would have
 * been indistinguishable from a real removal and wiped a season we simply had
 * no fresh data for).
 */

use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.test',
            'username' => 'test-user',
            'password' => 'test-password',
        ],
    ]));
    $this->category = Category::factory()->for($this->user)->for($this->playlist)->create();
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $this->category->id,
        'source_series_id' => 123,
        'enabled' => true,
        'last_metadata_fetch' => null,
        'last_modified' => null,
    ]);
});

function reconcileEpisode(int $id, int $num): array
{
    return [
        'id' => $id,
        'episode_num' => $num,
        'title' => sprintf('S01E%02d - Episode %d', $num, $num),
        'container_extension' => 'mkv',
        'info' => [],
    ];
}

function reconcileSeriesInfo(array $episodesBySeason): array
{
    return [
        'info' => ['name' => 'Test Series'],
        'seasons' => [
            ['id' => 101, 'season_number' => 1, 'name' => 'Season One'],
            ['id' => 102, 'season_number' => 2, 'name' => 'Season Two'],
        ],
        'episodes' => $episodesBySeason,
    ];
}

/** Successive provider answers: first fetch gets $first, the next one $second. */
function fakeReconcileSequence(array $first, array $second): void
{
    Http::fake([
        'provider.test/player_api.php*' => Http::sequence()
            ->push(reconcileSeriesInfo($first))
            ->push(reconcileSeriesInfo($second)),
    ]);
}

it('removes episodes the provider no longer lists within a season it answered for', function () {
    fakeReconcileSequence(
        ['1' => [reconcileEpisode(1001, 1), reconcileEpisode(1002, 2), reconcileEpisode(1003, 3)]],
        // Provider drops episode 3 but still answers for season 1.
        ['1' => [reconcileEpisode(1001, 1), reconcileEpisode(1002, 2)]],
    );

    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    expect(Episode::where('series_id', $this->series->id)->count())->toBe(3);

    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    Http::assertSentCount(2);

    $remaining = Episode::where('series_id', $this->series->id)->orderBy('episode_num')->pluck('source_episode_id')->all();
    expect($remaining)->toBe([1001, 1002]);
});

it('removes a season that ends up with no episodes, when the provider still answered for it', function () {
    fakeReconcileSequence(
        ['1' => [reconcileEpisode(1001, 1)], '2' => [reconcileEpisode(2001, 1)]],
        // Provider still answers for season 2, but it's now empty.
        ['1' => [reconcileEpisode(1001, 1)], '2' => []],
    );

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    expect(Season::where('series_id', $this->series->id)->count())->toBe(2);

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect(Season::where('series_id', $this->series->id)->pluck('season_number')->all())->toBe([1])
        ->and(Episode::where('series_id', $this->series->id)->count())->toBe(1);
});

it('never touches a season the provider did not answer for this fetch', function () {
    fakeReconcileSequence(
        ['1' => [reconcileEpisode(1001, 1)], '2' => [reconcileEpisode(2001, 1)]],
        // Provider answer is missing season 2 entirely this time (truncated/rate-limited/glitchy
        // response) - this must NOT be interpreted as "season 2 was removed".
        ['1' => [reconcileEpisode(1001, 1)]],
    );

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    expect(Season::where('series_id', $this->series->id)->count())->toBe(2);
    expect(Episode::where('series_id', $this->series->id)->count())->toBe(2);

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    // Season 2 and its episode must survive untouched.
    expect(Season::where('series_id', $this->series->id)->pluck('season_number')->sort()->values()->all())->toBe([1, 2]);
    expect(Episode::where('series_id', $this->series->id)->count())->toBe(2);
});

it('never removes anything when the provider returns no episodes at all', function () {
    fakeReconcileSequence(
        ['1' => [reconcileEpisode(1001, 1), reconcileEpisode(1002, 2)]],
        // Empty (or broken) provider answer entirely: keep the catalogue as is.
        [],
    );

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect(Episode::where('series_id', $this->series->id)->count())->toBe(2)
        ->and(Season::where('series_id', $this->series->id)->count())->toBe(1);
});

it('keeps locally created episodes that have no provider id', function () {
    fakeReconcileSequence(
        ['1' => [reconcileEpisode(1001, 1)]],
        ['1' => [reconcileEpisode(1001, 1)]],
    );

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    $season = Season::where('series_id', $this->series->id)->firstOrFail();

    Episode::create([
        'title' => 'Local recording',
        'source_episode_id' => null,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'season_id' => $season->id,
        'episode_num' => 99,
        'season' => 1,
        'url' => 'http://local.test/recording.mkv',
        'import_batch_no' => 'manual',
    ]);

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect(Episode::where('series_id', $this->series->id)->whereNull('source_episode_id')->count())->toBe(1);
});
