<?php

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

function providerEpisode(int $id, int $num): array
{
    return [
        'id' => $id,
        'episode_num' => $num,
        'title' => sprintf('S01E%02d - Episode %d', $num, $num),
        'container_extension' => 'mkv',
        'info' => [],
    ];
}

function seriesInfo(array $episodesBySeason): array
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
function fakeSeriesInfoSequence(array $first, array $second): void
{
    Http::fake([
        'provider.test/player_api.php*' => Http::sequence()
            ->push(seriesInfo($first))
            ->push(seriesInfo($second)),
    ]);
}

it('removes episodes the provider no longer lists', function () {
    fakeSeriesInfoSequence(
        ['1' => [providerEpisode(1001, 1), providerEpisode(1002, 2), providerEpisode(1003, 3)]],
        // The provider drops episode 3 (removed or renumbered upstream).
        ['1' => [providerEpisode(1001, 1), providerEpisode(1002, 2)]],
    );
    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    expect(Episode::where('series_id', $this->series->id)->count())->toBe(3);

    expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();
    Http::assertSentCount(2);

    $remaining = Episode::where('series_id', $this->series->id)->orderBy('episode_num')->pluck('source_episode_id')->all();
    expect($remaining)->toBe([1001, 1002]);
});

it('removes seasons that end up without episodes', function () {
    fakeSeriesInfoSequence(
        ['1' => [providerEpisode(1001, 1)], '2' => [providerEpisode(2001, 1)]],
        ['1' => [providerEpisode(1001, 1)]],
    );
    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    expect(Season::where('series_id', $this->series->id)->count())->toBe(2);

    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect(Season::where('series_id', $this->series->id)->pluck('season_number')->all())->toBe([1])
        ->and(Episode::where('series_id', $this->series->id)->count())->toBe(1);
});

it('never removes anything when the provider returns no episodes', function () {
    fakeSeriesInfoSequence(
        ['1' => [providerEpisode(1001, 1), providerEpisode(1002, 2)]],
        // Empty (or broken) provider answer: keep the catalogue as is.
        [],
    );
    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);
    $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect(Episode::where('series_id', $this->series->id)->count())->toBe(2)
        ->and(Season::where('series_id', $this->series->id)->count())->toBe(1);
});

it('keeps locally created episodes that have no provider id', function () {
    fakeSeriesInfoSequence(
        ['1' => [providerEpisode(1001, 1)]],
        ['1' => [providerEpisode(1001, 1)]],
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
