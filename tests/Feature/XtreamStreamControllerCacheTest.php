<?php

use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Per project memory: Bus::fake() (NOT Queue::fake) catches dispatch()
    // calls from model event listeners like Playlist::factory -> SyncPipelineService.
    Bus::fake();
    // The cache-hit gate may consult the Xtream redirect path; prevent accidental
    // outbound HTTP during tests.
    Http::preventStrayRequests();
    Storage::fake(CachedContentFile::DISK);
});

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 * Mockery avoids the missing-properties error you'd get from `->save()` when other
 * required fields aren't set - mirrors the pattern in ProviderRequestDelayTest.
 */
function setEnableCache(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

it('redirects to the cache stream when enable_cache is on and a Completed row matches (VOD)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'tvdb_id' => null,
        'enabled' => true,
    ]);

    $cached = CachedContentFile::factory()->completed()->forItem($channel)->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
    ]);
    Storage::disk(CachedContentFile::DISK)->put($cached->file_path, 'bytes');

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirectContains("/cached-content/{$user->name}/{$playlist->uuid}/{$cached->uuid}.mp4");
});

it('falls through to the existing redirect when enable_cache is on but no Completed row matches (VOD)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '999',
        'enabled' => true,
    ]);

    // No CachedContentFile for this channel.

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    // Falls through to the existing PlaylistUrlService path - a redirect to some
    // external URL. Just assert it IS a redirect and the path is NOT the cache route.
    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('does NOT serve cache when enable_cache is off, even with a Completed row present ', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    // Completed row exists - but enable_cache is OFF. PR #1500's gap was gating
    // only lazy-dispatch; PR C gates serve too so disabling stops serving.
    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    $response = $this->get(
        "/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('does not dispatch DownloadCachedContentFile when enable_cache is off', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    $this->get("/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4");

    // Lazy-dispatch is intentionally off in PR C; this asserts the gate doesn't
    // accidentally fire a dispatch on cache miss when enable_cache is off.
    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('redirects to the cache stream when enable_cache is on and a Completed episode row matches', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    $cached = CachedContentFile::factory()->completed()->forItem($episode)->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
    ]);
    Storage::disk(CachedContentFile::DISK)->put($cached->file_path, 'bytes');

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirectContains("/cached-content/{$user->name}/{$playlist->uuid}/{$cached->uuid}.mp4");
});

it('does NOT serve cache for episodes when enable_cache is off ', function () {
    setEnableCache(false);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'season_number' => 1,
        'episode_number' => 5,
    ]);

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('falls through to the existing redirect when no Completed row matches and enable_cache is on (episode)', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->create(['playlist_id' => $playlist->id, 'enabled' => true]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $playlist->id]);
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $playlist->id,
        'tmdb_id' => '60625',
        'season' => 1,
        'episode_num' => 5,
    ]);

    // No cache row for this episode.

    $response = $this->get(
        "/series/{$user->name}/{$playlist->uuid}/{$episode->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

// --- cross-playlist sharing ---

it('cache-hit gate honors a Completed row from a same-user sharing sibling playlist', function () {
    // PlaylistA has the row and sharing ON; PlaylistB (same user, no
    // row) requests the same content. The gate must redirect to A's
    // cached uuid.
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlistB)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    $channelA = Channel::factory()->for($playlistA)->create(['user_id' => $user->id, 'tmdb_id' => '550', 'is_vod' => true]);
    $cached = CachedContentFile::factory()->completed()->forItem($channelA)->create();
    Storage::disk(CachedContentFile::DISK)->put($cached->file_path, 'bytes');

    $response = $this->get(
        "/movie/{$user->name}/{$playlistB->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirectContains("/cached-content/{$user->name}/{$playlistB->uuid}/{$cached->uuid}.mp4");
});

it('cache-hit gate does NOT honor a row from a sibling playlist with sharing OFF', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $playlistB = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlistB)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    $response = $this->get(
        "/movie/{$user->name}/{$playlistB->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('cache-hit gate never honors a row owned by a different user', function () {
    // Cross-USER sharing is forbidden - even if both users have sharing
    // ON, userB's request must NOT serve userA's cache.
    setEnableCache(true);

    $userA = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $userB = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($userB)->create();
    $channel = Channel::factory()->for($playlistB)->create([
        'tmdb_id' => '550',
        'enabled' => true,
    ]);

    CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    $response = $this->get(
        "/movie/{$userB->name}/{$playlistB->uuid}/{$channel->id}.mp4"
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location') ?? '';
    expect($location)->not->toContain('/cached-content/');
});

it('falls back to the live stream when the Completed row file is missing on disk', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($playlist)->create(['tmdb_id' => '550', 'enabled' => true]);
    CachedContentFile::factory()->completed()->forItem($channel)->create();
    // No file written to the cache disk (e.g. container rebuilt without a volume).

    $response = $this->get("/movie/{$user->name}/{$playlist->uuid}/{$channel->id}.mp4");

    $response->assertRedirect();
    expect($response->headers->get('Location') ?? '')->not->toContain('/cached-content/');
});

it('does not serve a same-TMDB release cached for a different channel in the same playlist', function () {
    setEnableCache(true);

    $user = User::factory()->create(['name' => 'testuser'.uniqid()]);
    $playlist = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $german = Channel::factory()->for($playlist)->create(['tmdb_id' => '550', 'enabled' => true]);
    $english = Channel::factory()->for($playlist)->create(['tmdb_id' => '550', 'enabled' => true]);
    $cached = CachedContentFile::factory()->completed()->forItem($german)->create();
    Storage::disk(CachedContentFile::DISK)->put($cached->file_path, 'bytes');

    $response = $this->get("/movie/{$user->name}/{$playlist->uuid}/{$english->id}.mp4");

    $response->assertRedirect();
    expect($response->headers->get('Location') ?? '')->not->toContain('/cached-content/');
});
