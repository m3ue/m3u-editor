<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Tables\CacheStateColumn;
use App\Models\ArrCacheMovie;
use App\Models\ArrIntegration;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistListener -> dispatch(ProcessM3uImport).
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake(CachedContentFile::DISK);

    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
});

function csMovie(Playlist $playlist, int $tmdbId = 603): Channel
{
    return Channel::factory()->for($playlist)->for($playlist->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => $tmdbId,
        'url' => "https://provider.example.com/movie/{$tmdbId}.mkv",
    ]);
}

/**
 * An enabled Emby integration holding the same movie, then the match
 * rebuild that playback reads.
 */
function csOnMediaServer(Playlist $playlist, int $tmdbId = 603): void
{
    $media = Playlist::factory()->for($playlist->user)->create();
    MediaServerIntegration::factory()->for($playlist->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $media->id,
    ]);
    Channel::factory()->for($media)->for($playlist->user)->create(['enabled' => true, 'is_vod' => true, 'tmdb_id' => $tmdbId]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($playlist);
}

function csWaitingOnArr(Channel|Episode $item): CachedContentFile
{
    return CachedContentFile::factory()->forItem($item)->create([
        ...CachedContentFile::buildAttributes($item),
        'status' => CachedContentFileStatus::Pending,
        'source' => 'arr',
        'arr_integration_id' => ArrIntegration::factory()->radarr()->cacheEnabled()->cacheFailback()->create(['user_id' => $item->user_id])->id,
        'arr_requested_at' => now(),
    ]);
}

function csCleanupRadarr(User $user, bool $cleanup = true): ArrIntegration
{
    return ArrIntegration::factory()->radarr()->cacheEnabled()->create(['user_id' => $user->id, 'cache_cleanup' => $cleanup]);
}

it('shows a local cache file as Cached', function () {
    $movie = csMovie($this->playlist);
    CachedContentFile::factory()->forItem($movie)->completed()->create();

    expect(CacheStateColumn::stateFor($movie))->toBe(CacheStateColumn::CACHED);
});

it('shows a media server copy as Media server', function () {
    $movie = csMovie($this->playlist);
    csOnMediaServer($this->playlist);

    expect(CacheStateColumn::stateFor($movie))->toBe(CacheStateColumn::MEDIA_SERVER);
});

it('prefers the local file when the media server also has the title', function () {
    $movie = csMovie($this->playlist);
    csOnMediaServer($this->playlist);
    CachedContentFile::factory()->forItem($movie)->completed()->create();

    expect(CacheStateColumn::stateFor($movie))->toBe(CacheStateColumn::CACHED);
});

it('shows a pending arr request as waiting, for movies and episodes', function () {
    $movie = csMovie($this->playlist);
    $series = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true]);
    $episode = Episode::factory()->for($series)->for($this->playlist)->for($this->user)->create(['enabled' => true]);

    csWaitingOnArr($movie);
    csWaitingOnArr($episode);

    expect(CacheStateColumn::stateFor($movie))->toBe(CacheStateColumn::WAITING_ON_ARR)
        ->and(CacheStateColumn::stateFor($episode))->toBe(CacheStateColumn::WAITING_ON_ARR);
});

it('shows neither state as not cached', function () {
    $movie = csMovie($this->playlist);
    // A provider download that isn't finished yet is not cached either.
    CachedContentFile::factory()->forItem(csMovie($this->playlist, 604))->create(['status' => CachedContentFileStatus::Pending]);

    expect(CacheStateColumn::stateFor($movie))->toBe(CacheStateColumn::NOT_CACHED);
});

it('ignores media server copies when the playlist does not prefer them', function () {
    $movie = csMovie($this->playlist);
    csOnMediaServer($this->playlist);
    $this->playlist->updateQuietly(['prefer_media_server_sources' => false]);

    expect(CacheStateColumn::stateFor($movie->fresh()))->toBe(CacheStateColumn::NOT_CACHED);
});

it('only marks movies the owner\'s Radarr cleanup will remove', function () {
    ArrCacheMovie::factory()->create(['arr_integration_id' => csCleanupRadarr($this->user)->id, 'tmdb_id' => 603]);
    ArrCacheMovie::factory()->create(['arr_integration_id' => csCleanupRadarr(User::factory()->create())->id, 'tmdb_id' => 604]);
    ArrCacheMovie::factory()->create(['arr_integration_id' => csCleanupRadarr($this->user, cleanup: false)->id, 'tmdb_id' => 607]);

    expect(ArrCacheMovie::isTracked($this->user->id, 603))->toBeTrue()
        // Another user's tracked movie, an untracked pre-existing one, and
        // one left behind after cleanup was turned off.
        ->and(ArrCacheMovie::isTracked($this->user->id, 604))->toBeFalse()
        ->and(ArrCacheMovie::isTracked($this->user->id, 605))->toBeFalse()
        ->and(ArrCacheMovie::isTracked($this->user->id, 607))->toBeFalse()
        ->and(ArrCacheMovie::anyVisibleTo($this->user))->toBeTrue()
        ->and(ArrCacheMovie::anyVisibleTo(User::factory()->create()))->toBeFalse();
});

it('renders every state in the VOD table', function () {
    $cached = csMovie($this->playlist, 601);
    CachedContentFile::factory()->forItem($cached)->completed()->create();
    $media = csMovie($this->playlist, 603);
    csOnMediaServer($this->playlist, 603);
    $waiting = csMovie($this->playlist, 604);
    csWaitingOnArr($waiting);
    $neither = csMovie($this->playlist, 605);
    $tracked = csMovie($this->playlist, 606);
    ArrCacheMovie::factory()->create(['arr_integration_id' => csCleanupRadarr($this->user)->id, 'tmdb_id' => 606]);

    $this->actingAs($this->user);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->assertTableColumnStateSet('is_cached', CacheStateColumn::CACHED, $cached)
        ->assertTableColumnStateSet('is_cached', CacheStateColumn::MEDIA_SERVER, $media)
        ->assertTableColumnStateSet('is_cached', CacheStateColumn::WAITING_ON_ARR, $waiting)
        ->assertTableColumnStateSet('is_cached', CacheStateColumn::NOT_CACHED, $neither)
        ->assertTableColumnStateSet('radarr_managed', true, $tracked)
        ->assertTableColumnStateSet('radarr_managed', false, $neither);
});

it('counts each series\' locally cached episodes in the Series table', function () {
    $series = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true]);
    $episodes = Episode::factory()->count(4)->for($series)->for($this->playlist)->for($this->user)->create(['enabled' => true]);
    CachedContentFile::factory()->forItem($episodes[0])->completed()->create();
    CachedContentFile::factory()->forItem($episodes[1])->completed()->create();
    // Not cached yet: a running download and a request waiting on Sonarr.
    CachedContentFile::factory()->forItem($episodes[2])->downloading()->create();
    csWaitingOnArr($episodes[3]);

    $empty = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true]);
    Episode::factory()->for($empty)->for($this->playlist)->for($this->user)->create(['enabled' => true]);

    $this->actingAs($this->user);

    Livewire::test(ListSeries::class)
        ->loadTable()
        ->assertTableColumnStateSet('cached_episodes_count', 2, $series)
        ->assertTableColumnStateSet('cached_episodes_count', 0, $empty);
});
