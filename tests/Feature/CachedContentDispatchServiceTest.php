<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForDispatchTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

/**
 * A VOD channel with a playable URL on its own user + playlist.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeCacheableChannel(?Playlist $playlist = null, array $attributes = []): Channel
{
    $playlist ??= Playlist::factory()->create();

    return Channel::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
        'url' => 'https://provider.example.com/movie/u/p/1.mkv',
    ], $attributes));
}

/**
 * An episode (with parent series) that has a playable URL.
 *
 * @param  array<string, mixed>  $seriesAttributes
 */
function makeCacheableEpisode(?Playlist $playlist = null, array $seriesAttributes = [], int $season = 1, int $episodeNum = 1): Episode
{
    $playlist ??= Playlist::factory()->create();
    $series = Series::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => 1399,
    ], $seriesAttributes));

    return Episode::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season' => $season,
        'episode_num' => $episodeNum,
        'url' => "https://provider.example.com/series/u/p/{$season}{$episodeNum}.mkv",
    ]);
}

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    setEnableCacheForDispatchTest(true);
});

// --- dispatch(): new rows ---

it('creates one Pending row keyed to the channel and queues one download', function () {
    $channel = makeCacheableChannel();

    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    $row = CachedContentFile::sole();
    expect($result)->toBe(CacheDispatchResult::Queued)
        ->and($row->status)->toBe(CachedContentFileStatus::Pending)
        ->and($row->cacheable_type)->toBe($channel->getMorphClass())
        ->and($row->cacheable_id)->toBe($channel->id)
        ->and($row->playlist_id)->toBe($channel->playlist_id)
        ->and($row->user_id)->toBe($channel->user_id)
        ->and($row->title)->toBe($channel->display_title);

    Bus::assertDispatched(DownloadCachedContentFile::class, fn (DownloadCachedContentFile $job) => $job->cachedContentFileId === $row->id);
});

it('stamps the playlist owner even when another user triggers the dispatch', function () {
    $channel = makeCacheableChannel();
    Auth::login(User::factory()->create(['is_admin' => true]));

    app(CachedContentDispatchService::class)->dispatch($channel);

    expect(CachedContentFile::sole()->user_id)->toBe($channel->user_id);
});

it('includes tvdb_id in the fingerprint', function () {
    $channel = makeCacheableChannel(attributes: ['tmdb_id' => null, 'tvdb_id' => 81189]);

    app(CachedContentDispatchService::class)->dispatch($channel);

    expect(CachedContentFile::sole()->content_fingerprint)->toBe('movie::81189:::');
});

it('gives two channels with the same TMDB id in one playlist their own rows', function () {
    $playlist = Playlist::factory()->create();
    $german = makeCacheableChannel($playlist);
    $english = makeCacheableChannel($playlist);
    $service = app(CachedContentDispatchService::class);

    expect($service->dispatch($german))->toBe(CacheDispatchResult::Queued)
        ->and($service->dispatch($english))->toBe(CacheDispatchResult::Queued)
        ->and(CachedContentFile::count())->toBe(2);
});

it('gives two unmatched movies in one playlist distinct fingerprints and rows', function () {
    $playlist = Playlist::factory()->create();
    $a = makeCacheableChannel($playlist, ['tmdb_id' => null]);
    $b = makeCacheableChannel($playlist, ['tmdb_id' => null]);
    $service = app(CachedContentDispatchService::class);

    $service->dispatch($a);
    $service->dispatch($b);

    expect(CachedContentFile::pluck('content_fingerprint')->unique())->toHaveCount(2);
});

it('uses the parent series ids and season/episode for episode identity', function () {
    $episode = makeCacheableEpisode(seriesAttributes: ['tmdb_id' => 1399, 'tvdb_id' => 121361], season: 2, episodeNum: 5);

    app(CachedContentDispatchService::class)->dispatch($episode);

    $row = CachedContentFile::sole();
    expect($row->content_type)->toBe('episode')
        ->and($row->tmdb_id)->toBe('1399')
        ->and($row->tvdb_id)->toBe('121361')
        ->and($row->season_number)->toBe(2)
        ->and($row->episode_number)->toBe(5)
        ->and($row->content_fingerprint)->toBe($episode->cacheFingerprint())
        ->and($row->title)->toContain('S02E05');
});

// --- dispatch(): items that can't be cached ---

it('returns Disabled and writes nothing when enable_cache is off', function () {
    setEnableCacheForDispatchTest(false);

    $result = app(CachedContentDispatchService::class)->dispatch(makeCacheableChannel());

    expect($result)->toBe(CacheDispatchResult::Disabled)
        ->and(CachedContentFile::count())->toBe(0);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('refuses live (non-VOD) channels', function () {
    $channel = makeCacheableChannel(attributes: ['is_vod' => false, 'url' => 'https://provider.example.com/live/u/p/1.ts']);

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Unavailable)
        ->and(CachedContentFile::count())->toBe(0);
});

it('refuses HLS sources', function () {
    $channel = makeCacheableChannel(attributes: ['url' => 'https://provider.example.com/movie/1/index.m3u8']);

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Unavailable);
});

it('refuses items with no source URL', function () {
    $channel = makeCacheableChannel(attributes: ['url' => '']);

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Unavailable);
});

it('uses url_custom over url when checking cacheability', function () {
    $channel = makeCacheableChannel(attributes: [
        'url' => 'https://provider.example.com/movie/1/index.m3u8',
        'url_custom' => 'https://mirror.example.com/movie/1.mkv',
    ]);

    expect($channel->cacheSourceUrl())->toBe('https://mirror.example.com/movie/1.mkv')
        ->and(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Queued);
});

// --- dispatch(): existing rows ---

it('returns AlreadyQueued without a second row while a download is Pending', function () {
    $channel = makeCacheableChannel();
    CachedContentFile::factory()->forItem($channel)->create();

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::AlreadyQueued)
        ->and(CachedContentFile::count())->toBe(1);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('returns AlreadyCached when the Completed file is on disk', function () {
    $channel = makeCacheableChannel();
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::AlreadyCached);
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('re-queues a Completed row whose file is missing on disk', function () {
    $channel = makeCacheableChannel();
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create();

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Queued);

    $file->refresh();
    expect($file->status)->toBe(CachedContentFileStatus::Pending)
        ->and($file->file_path)->toBeNull();
    Bus::assertDispatched(DownloadCachedContentFile::class, fn ($job) => $job->cachedContentFileId === $file->id);
});

it('re-queues a Failed row and clears its failure details', function () {
    $channel = makeCacheableChannel();
    $file = CachedContentFile::factory()->failed()->forItem($channel)->create(['last_error_message' => 'boom']);

    expect(app(CachedContentDispatchService::class)->dispatch($channel))->toBe(CacheDispatchResult::Queued);

    $file->refresh();
    expect($file->status)->toBe(CachedContentFileStatus::Pending)
        ->and($file->failure_count)->toBe(0)
        ->and($file->last_error_message)->toBeNull();
});

// --- dispatch(): cross-playlist sharing ---

it('returns AlreadyCached when a sibling playlist shares a playable copy', function () {
    $user = User::factory()->create();
    $sharing = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $other = Playlist::factory()->for($user)->create();
    $source = CachedContentFile::factory()->completed()->forItem(makeCacheableChannel($sharing))->create();
    Storage::disk(CachedContentFile::DISK)->put($source->file_path, 'bytes');

    expect(app(CachedContentDispatchService::class)->dispatch(makeCacheableChannel($other)))->toBe(CacheDispatchResult::AlreadyCached)
        ->and(CachedContentFile::count())->toBe(1);
});

it('downloads its own copy when the sibling playlist does not share', function () {
    $user = User::factory()->create();
    $notSharing = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $other = Playlist::factory()->for($user)->create();
    $source = CachedContentFile::factory()->completed()->forItem(makeCacheableChannel($notSharing))->create();
    Storage::disk(CachedContentFile::DISK)->put($source->file_path, 'bytes');

    expect(app(CachedContentDispatchService::class)->dispatch(makeCacheableChannel($other)))->toBe(CacheDispatchResult::Queued)
        ->and(CachedContentFile::count())->toBe(2);
});

it('never reuses another user copy', function () {
    $sharing = Playlist::factory()->create(['share_cache_across_playlists' => true]);
    $source = CachedContentFile::factory()->completed()->forItem(makeCacheableChannel($sharing))->create();
    Storage::disk(CachedContentFile::DISK)->put($source->file_path, 'bytes');

    expect(app(CachedContentDispatchService::class)->dispatch(makeCacheableChannel()))->toBe(CacheDispatchResult::Queued);
});

// --- dispatchSeries() ---

it('queues every episode of a series and skips ones already queued', function () {
    $playlist = Playlist::factory()->create();
    $first = makeCacheableEpisode($playlist, season: 1, episodeNum: 1);
    foreach ([2, 3] as $num) {
        Episode::factory()->create([
            'user_id' => $playlist->user_id,
            'playlist_id' => $playlist->id,
            'series_id' => $first->series_id,
            'season' => 1,
            'episode_num' => $num,
            'url' => "https://provider.example.com/series/u/p/1{$num}.mkv",
        ]);
    }
    CachedContentFile::factory()->forItem($first)->create();

    $counts = app(CachedContentDispatchService::class)->dispatchSeries($first->series);

    expect($counts[CacheDispatchResult::Queued->value])->toBe(2)
        ->and($counts[CacheDispatchResult::AlreadyQueued->value])->toBe(1)
        ->and(CachedContentFile::count())->toBe(3);
});

it('dispatchSeries reports Disabled and writes nothing when enable_cache is off', function () {
    setEnableCacheForDispatchTest(false);
    $episode = makeCacheableEpisode();

    $counts = app(CachedContentDispatchService::class)->dispatchSeries($episode->series);

    expect($counts[CacheDispatchResult::Disabled->value])->toBe(1)
        ->and(CachedContentFile::count())->toBe(0);
});

// --- requeue() ---

it('requeue() returns false when the source item no longer exists', function () {
    $channel = makeCacheableChannel();
    $file = CachedContentFile::factory()->failed()->forItem($channel)->create();
    $channel->delete();

    expect(app(CachedContentDispatchService::class)->requeue($file->fresh()))->toBeFalse();
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// --- notifications ---

it('maps each dispatch result to a notification', function (CacheDispatchResult $result, string $status, string $title) {
    $notification = CachedContentDispatchService::cacheNowNotification(new Channel, $result);

    expect($notification->getStatus())->toBe($status)
        ->and($notification->getTitle())->toBe($title);
})->with([
    [CacheDispatchResult::Queued, 'success', 'Cache download queued'],
    [CacheDispatchResult::AlreadyCached, 'info', 'Already cached'],
    [CacheDispatchResult::AlreadyQueued, 'info', 'Already queued for caching'],
    [CacheDispatchResult::Disabled, 'warning', 'Could not queue cache'],
    [CacheDispatchResult::Unavailable, 'danger', 'Could not queue cache'],
]);

it('uses episode-specific copy for episodes', function () {
    $notification = CachedContentDispatchService::cacheNowNotification(new Episode, CacheDispatchResult::AlreadyCached);

    expect($notification->getBody())->toContain('episode');
});

it('summarizes a series run', function () {
    $notification = CachedContentDispatchService::seriesNotification([
        CacheDispatchResult::Queued->value => 3,
        CacheDispatchResult::AlreadyCached->value => 1,
        CacheDispatchResult::AlreadyQueued->value => 1,
        CacheDispatchResult::Unavailable->value => 2,
        CacheDispatchResult::Disabled->value => 0,
    ]);

    expect($notification->getStatus())->toBe('success')
        ->and($notification->getTitle())->toBe('Queued 3 episodes for caching')
        ->and($notification->getBody())->toBe('2 already cached or queued, 2 without a cacheable source.');
});
