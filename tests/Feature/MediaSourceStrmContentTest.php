<?php

use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->provider = Playlist::factory()->for($this->user)->create([
        'prefer_media_server_sources' => true,
    ]);
    $this->media = Playlist::factory()->for($this->user)->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $this->media->id,
    ]);

    $this->syncDir = sys_get_temp_dir().'/strm-rework-'.uniqid();
    mkdir($this->syncDir, 0777, true);

    // Real settings: the defaults (no global stream-file setting, no legacy
    // sync location) are what the per-channel StreamFileSetting needs to win
    // the priority chain. A Mockery mock of spatie Settings explodes on its
    // typed $mapper property.
    $this->settings = app(GeneralSettings::class);
});

afterEach(function () {
    if (is_dir($this->syncDir)) {
        foreach (glob($this->syncDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->syncDir);
    }
});

function makeOriginalUrlSetting(User $user): StreamFileSetting
{
    return StreamFileSetting::factory()->for($user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => null, // overridden per-channel via sync_settings['sync_location'] fallback
        'url_type' => 'original',
    ]);
}

function strmFilesIn(string $dir): array
{
    $files = glob($dir.'/*.strm') ?: [];

    return array_combine($files, array_map('file_get_contents', $files));
}

it('writes the media URL into the VOD strm file when url_type is original and the toggle is on', function () {
    $setting = makeOriginalUrlSetting($this->user);
    $setting->update(['location' => $this->syncDir]);

    $mediaMovie = Channel::factory()->for($this->media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
        'url' => 'http://app.test/media-server/1/stream/abc.mkv',
    ]);
    $providerMovie = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
        'title' => 'Rework Movie',
        'name' => 'Rework Movie',
        'url' => 'http://provider.test/movie/123.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($this->provider);

    (new SyncVodStrmFiles(channel: $providerMovie))->handle($this->settings);

    $files = strmFilesIn($this->syncDir);

    expect($files)->not->toBeEmpty()
        ->and(array_values($files)[0])->toBe('http://app.test/media-server/1/stream/abc.mkv');
});

it('writes the provider URL into the VOD strm file when the toggle is off', function () {
    $setting = makeOriginalUrlSetting($this->user);
    $setting->update(['location' => $this->syncDir]);

    $this->provider->update(['prefer_media_server_sources' => false]);

    Channel::factory()->for($this->media)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
        'url' => 'http://app.test/media-server/1/stream/abc.mkv',
    ]);
    $providerMovie = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
        'title' => 'Rework Movie',
        'name' => 'Rework Movie',
        'url' => 'http://provider.test/movie/123.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($this->provider);

    (new SyncVodStrmFiles(channel: $providerMovie))->handle($this->settings);

    $files = strmFilesIn($this->syncDir);

    expect($files)->not->toBeEmpty()
        ->and(array_values($files)[0])->toBe('http://provider.test/movie/123.mkv');
});

it('writes the media episode URL into the series strm file when matched', function () {
    $setting = makeOriginalUrlSetting($this->user);
    $setting->update(['location' => $this->syncDir, 'type' => 'series']);

    $mediaSeries = Series::factory()->for($this->media)->for($this->user)->create(['enabled' => true, 'tmdb_id' => 1399]);
    $mediaSeason = Season::factory()->create(['series_id' => $mediaSeries->id, 'playlist_id' => $this->media->id, 'season_number' => 1]);
    Episode::factory()->for($this->media)->for($this->user)->create([
        'series_id' => $mediaSeries->id,
        'season_id' => $mediaSeason->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'url' => 'http://app.test/media-server/2/stream/def.mp4',
    ]);

    $providerSeries = Series::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true, 'tmdb_id' => 1399, 'stream_file_setting_id' => $setting->id,
    ]);
    $providerSeason = Season::factory()->create(['series_id' => $providerSeries->id, 'playlist_id' => $this->provider->id, 'season_number' => 1]);
    $providerEpisode = Episode::factory()->for($this->provider)->for($this->user)->create([
        'series_id' => $providerSeries->id,
        'season_id' => $providerSeason->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'title' => 'Pilot',
        'url' => 'http://provider.test/series/1/1.mp4',
        'container_extension' => 'mp4',
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($this->provider);

    (new SyncSeriesStrmFiles(series: $providerSeries))->handle($this->settings);

    $files = strmFilesIn($this->syncDir);

    expect($files)->not->toBeEmpty()
        ->and(array_values($files)[0])->toBe('http://app.test/media-server/2/stream/def.mp4');
});
