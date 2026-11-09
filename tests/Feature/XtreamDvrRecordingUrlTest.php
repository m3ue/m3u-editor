<?php

/**
 * Regression coverage for m3u-tv#334 - DVR recordings played in the web UI but
 * 404'd in the TV app. The Xtream DVR payload built stream_url/live_url/edl_url
 * from the raw APP_URL, dropping the port and ignoring the URL override, so a
 * Docker install (APP_URL=http://host, APP_PORT=36400) handed clients
 * http://host/dvr/... on port 80. These URLs must use the same base URL as
 * every other Xtream stream URL: the URL override, or the host the client
 * reached the API on.
 */

use App\Enums\DvrRecordingStatus;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

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
        ->create(['enabled' => true]);

    $this->setting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create(['enable_comskip' => true, 'dvr_output_format' => 'mp4']);
});

/**
 * Mirror a Docker install (APP_URL without the port) serving a real HTTP
 * request: drop the console-only forced root URL so url('') follows the
 * request host, and the cached proxy singleton, which resolves its base URL
 * once on construction.
 */
function useXtreamDvrUrlOverride(?string $override = null): void
{
    config([
        'app.url' => 'http://192.168.1.50',
        'app.port' => 36400,
        'proxy.url_override' => $override,
    ]);
    URL::forceRootUrl(null);
    app()->forgetInstance('proxy');
    ProxyFacade::clearResolvedInstance('proxy');
}

/**
 * Call get_dvr_recordings the way a client on the LAN would, on host:port.
 */
function xtreamDvrUrlRecordingsResponse($test): array
{
    $url = 'http://192.168.1.50:36400/player_api.php?'.http_build_query([
        'username' => 'dvr-user',
        'password' => 'dvr-pass',
        'action' => 'get_dvr_recordings',
    ]);

    return $test->postJson($url)->assertOk()->json();
}

function makeXtreamDvrUrlRecording($test, DvrRecordingStatus $status): DvrRecording
{
    return DvrRecording::factory()
        ->for($test->setting, 'dvrSetting')
        ->for($test->user)
        ->for($test->channel)
        ->for($test->auth, 'playlistAuth')
        ->when(
            $status === DvrRecordingStatus::Completed,
            fn ($factory) => $factory->completed(),
            fn ($factory) => $factory->recording(),
        )
        ->create();
}

it('builds completed recording stream and edl urls on the host and port the client used', function () {
    useXtreamDvrUrlOverride();
    $recording = makeXtreamDvrUrlRecording($this, DvrRecordingStatus::Completed);

    $item = xtreamDvrUrlRecordingsResponse($this)[0];

    expect($item['stream_url'])->toBe("http://192.168.1.50:36400/dvr/dvr-user/dvr-pass/{$recording->uuid}.mp4")
        ->and($item['edl_url'])->toBe("http://192.168.1.50:36400/dvr/dvr-user/dvr-pass/{$recording->uuid}/edl");
});

it('builds in-progress recording live urls on the host and port the client used', function () {
    useXtreamDvrUrlOverride();
    $recording = makeXtreamDvrUrlRecording($this, DvrRecordingStatus::Recording);

    $item = xtreamDvrUrlRecordingsResponse($this)[0];

    expect($item['live_url'])->toBe("http://192.168.1.50:36400/dvr/dvr-user/dvr-pass/{$recording->uuid}/live.m3u8")
        ->and($item['stream_url'])->toBeNull();
});

it('uses the url override for recording urls when one is set', function () {
    useXtreamDvrUrlOverride('https://tv.example.com/');
    $recording = makeXtreamDvrUrlRecording($this, DvrRecordingStatus::Completed);

    $item = xtreamDvrUrlRecordingsResponse($this)[0];

    expect($item['stream_url'])->toBe("https://tv.example.com/dvr/dvr-user/dvr-pass/{$recording->uuid}.mp4");
});
