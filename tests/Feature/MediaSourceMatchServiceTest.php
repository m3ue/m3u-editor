<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\MediaSourceMatch;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->service = app(MediaSourceMatchService::class);

    $this->user = User::factory()->create();
    $this->provider = Playlist::factory()
        ->for($this->user)
        ->create(['prefer_media_server_sources' => true]);
});

function makeMediaIntegration(User $user, string $type, Playlist $mediaPlaylist): MediaServerIntegration
{
    return MediaServerIntegration::factory()->for($user)->create([
        'type' => $type,
        'enabled' => true,
        'playlist_id' => $mediaPlaylist->id,
    ]);
}

it('matches a provider movie on TMDB', function () {
    $media = Playlist::factory()->for($this->user)->create();
    $integration = makeMediaIntegration($this->user, 'emby', $media);

    Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    $providerChannel = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(1)
        ->and($counts['episodes'])->toBe(0)
        ->and(MediaSourceMatch::where('playlist_id', $this->provider->id)->count())->toBe(1);

    $match = MediaSourceMatch::firstOrFail();
    expect($match->channel_id)->toBe($providerChannel->id)
        ->and($match->media_channel_id)->toBe(Channel::where('playlist_id', $media->id)->first()->id)
        ->and($match->media_server_integration_id)->toBe($integration->id)
        ->and($match->match_key)->toBe('tmdb:603');
});

it('matches a provider movie on IMDB when TMDB is missing', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);

    Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'imdb_id' => 'tt0133093',
    ]);
    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => null, 'imdb_id' => 'TT0133093',
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(1)
        ->and(MediaSourceMatch::firstOrFail()->match_key)->toBe('imdb:tt0133093');
});

it('matches an episode on series TMDB plus season and episode number', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'jellyfin', $media);

    $mediaSeries = Series::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'tmdb_id' => 1399,
    ]);
    $mediaEpisode = Episode::factory()->for($mediaSeries)->create([
        'playlist_id' => $media->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'season' => 1,
        'episode_num' => 2,
    ]);

    $providerSeries = Series::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'tmdb_id' => 1399,
    ]);
    $providerEpisode = Episode::factory()->for($providerSeries)->create([
        'playlist_id' => $this->provider->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'season' => 1,
        'episode_num' => 2,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['episodes'])->toBe(1)
        ->and($counts['movies'])->toBe(0);

    $match = MediaSourceMatch::firstOrFail();
    expect($match->episode_id)->toBe($providerEpisode->id)
        ->and($match->media_episode_id)->toBe($mediaEpisode->id)
        ->and($match->match_key)->toBe('series-tmdb:1399:s1:e2');
});

it('falls back to the series TVDB id for episode matching', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'jellyfin', $media);

    $mediaSeries = Series::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'tmdb_id' => null, 'tvdb_id' => 364642,
    ]);
    Episode::factory()->for($mediaSeries)->create([
        'playlist_id' => $media->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'season' => 2,
        'episode_num' => 5,
    ]);

    $providerSeries = Series::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'tmdb_id' => null, 'tvdb_id' => 364642,
    ]);
    Episode::factory()->for($providerSeries)->create([
        'playlist_id' => $this->provider->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'season' => 2,
        'episode_num' => 5,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['episodes'])->toBe(1)
        ->and(MediaSourceMatch::firstOrFail()->match_key)->toBe('series-tvdb:364642:s2:e5');
});

it('matches Plex integrations', function () {
    $plexMedia = Playlist::factory()->for($this->user)->create();
    $integration = makeMediaIntegration($this->user, 'plex', $plexMedia);
    $plexChannel = Channel::factory()->for($plexMedia)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    $match = MediaSourceMatch::firstOrFail();
    expect($counts['movies'])->toBe(1)
        ->and($match->media_channel_id)->toBe($plexChannel->id)
        ->and($match->media_server_integration_id)->toBe($integration->id);
});

it('ignores WebDAV integrations', function () {
    $webdavMedia = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'webdav', $webdavMedia);
    Channel::factory()->for($webdavMedia)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 604,
    ]);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 604,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(0)
        ->and(MediaSourceMatch::count())->toBe(0);
});

it("ignores another user's integrations", function () {
    $otherUser = User::factory()->create();
    $otherMedia = Playlist::factory()->for($otherUser)->create();
    makeMediaIntegration($otherUser, 'emby', $otherMedia);
    Channel::factory()->for($otherMedia)->for($otherUser)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(0)
        ->and(MediaSourceMatch::count())->toBe(0);
});

it('ignores a disabled integration', function () {
    $media = Playlist::factory()->for($this->user)->create();
    MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => false,
        'playlist_id' => $media->id,
    ]);
    Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(0)
        ->and(MediaSourceMatch::count())->toBe(0);
});

it('ignores a disabled media item', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);

    Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => false, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(0)
        ->and(MediaSourceMatch::count())->toBe(0);
});

it('deletes match rows when the toggle is off', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);
    Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $this->service->rebuildForPlaylist($this->provider);
    expect(MediaSourceMatch::count())->toBe(1);

    $this->provider->update(['prefer_media_server_sources' => false]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts)->toBe(['movies' => 0, 'episodes' => 0, 'changed' => true])
        ->and(MediaSourceMatch::count())->toBe(0);
});

it('replaces stale rows on rebuild', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);

    $providerChannel = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    $oldMediaChannel = Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $this->service->rebuildForPlaylist($this->provider);
    expect(MediaSourceMatch::firstOrFail()->media_channel_id)->toBe($oldMediaChannel->id);

    // The media server now exposes a different channel for the same movie.
    $newMediaChannel = Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    $oldMediaChannel->forceFill(['enabled' => false])->save();

    $this->service->rebuildForPlaylist($this->provider);

    $match = MediaSourceMatch::firstOrFail();
    expect(MediaSourceMatch::count())->toBe(1)
        ->and($match->channel_id)->toBe($providerChannel->id)
        ->and($match->media_channel_id)->toBe($newMediaChannel->id);
});

it('cascades match rows when the media channel is deleted', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);

    $mediaChannel = Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $this->service->rebuildForPlaylist($this->provider);
    expect(MediaSourceMatch::count())->toBe(1);

    $mediaChannel->delete();

    expect(MediaSourceMatch::count())->toBe(0);
});

it('does not match the provider playlist against itself when it is a media-server playlist', function () {
    // A media-server playlist with the toggle on must not match its own rows.
    $selfIntegration = makeMediaIntegration($this->user, 'emby', $this->provider);

    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $counts = $this->service->rebuildForPlaylist($this->provider);

    expect($counts['movies'])->toBe(0)
        ->and($selfIntegration->exists)->toBeTrue()
        ->and(MediaSourceMatch::count())->toBe(0);
});

it('reports whether a rebuild changed the stored matches', function () {
    $media = Playlist::factory()->for($this->user)->create();
    makeMediaIntegration($this->user, 'emby', $media);
    $mediaChannel = Channel::factory()->for($media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    expect($this->service->rebuildForPlaylist($this->provider)['changed'])->toBeTrue()
        ->and($this->service->rebuildForPlaylist($this->provider)['changed'])->toBeFalse();

    $mediaChannel->forceFill(['enabled' => false])->save();

    expect($this->service->rebuildForPlaylist($this->provider)['changed'])->toBeTrue()
        ->and(MediaSourceMatch::count())->toBe(0);
});
