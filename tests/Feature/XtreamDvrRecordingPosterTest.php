<?php

/**
 * get_dvr_recordings carries a poster for recordings that made it into the
 * library (the movie's poster, or the series cover for an episode) and the
 * source channel's icon, so clients can show recordings as poster cards.
 */

use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'app.url' => 'http://192.168.1.50',
        'app.port' => 36400,
        'proxy.url_override' => null,
    ]);
    URL::forceRootUrl(null);
    app()->forgetInstance('proxy');
    ProxyFacade::clearResolvedInstance('proxy');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::create([
        'name' => 'DVR Credential',
        'username' => 'dvr-user',
        'password' => 'dvr-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->auth->id);

    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for(Group::factory()->for($this->user))
        ->create(['enabled' => true, 'logo' => 'https://example.com/channel-logo.png']);

    $setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();

    $this->recording = DvrRecording::factory()
        ->completed()
        ->for($setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->auth, 'playlistAuth')
        ->create();
});

function xtreamDvrPosterRecording($test): array
{
    $url = 'http://192.168.1.50:36400/player_api.php?'.http_build_query([
        'username' => 'dvr-user',
        'password' => 'dvr-pass',
        'action' => 'get_dvr_recordings',
    ]);

    return $test->postJson($url)->assertOk()->json()[0];
}

it('sends the movie poster, made absolute when it is generated art', function () {
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'logo' => 'dvr/artwork/poster/abc?signature=xyz',
        'dvr_recording_id' => $this->recording->id,
    ]);

    $item = xtreamDvrPosterRecording($this);

    expect($item['poster'])->toBe('http://192.168.1.50:36400/dvr/artwork/poster/abc?signature=xyz')
        ->and($item['channel_icon'])->toBe('https://example.com/channel-logo.png');
});

it('sends the series cover for an episode recording', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'cover' => 'https://image.tmdb.org/t/p/w500/cover.jpg',
    ]);
    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'dvr_recording_id' => $this->recording->id,
    ]);

    expect(xtreamDvrPosterRecording($this)['poster'])->toBe('https://image.tmdb.org/t/p/w500/cover.jpg');
});

it('sends no poster before the recording is in the library', function () {
    expect(xtreamDvrPosterRecording($this)['poster'])->toBeNull();
});
