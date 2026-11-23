<?php

/**
 * Regression coverage for #1388 — DvrStreamController::stream()/hlsPlaylist()/edl()
 * (direct recording playback/download) previously scoped recordings by `user_id`
 * only, unlike every other direct DVR action which additionally filters by
 * `playlist_auth_id`. That let one guest credential stream, fetch the EDL for,
 * or reach the HLS playlist of another guest's recording on the same playlist
 * just by knowing its UUID. These routes also performed no DVR capability check
 * at all (global config, playlist-level DvrSetting::$enabled).
 *
 * Locks in:
 *   1. A guest credential can stream/edl its own recording.
 *   2. A guest credential cannot stream/edl a sibling credential's recording —
 *      it 404s the same way a non-existent recording would (no existence leak).
 *   3. The playlist owner (owner_auth) retains full visibility across all
 *      credentials' recordings — intentional, mirrors the Xtream DVR actions.
 *   4. Both routes reject when the playlist-level DvrSetting is disabled, or
 *      DVR is disabled globally via config, even for the recording's owner.
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('dvr');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->authA = PlaylistAuth::create([
        'name' => 'Credential A',
        'username' => 'credential-a',
        'password' => 'password-a',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->authB = PlaylistAuth::create([
        'name' => 'Credential B',
        'username' => 'credential-b',
        'password' => 'password-b',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);

    $this->playlist->playlistAuths()->attach([$this->authA->id, $this->authB->id]);

    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()->for($this->playlist)->for($this->group)->create(['enabled' => true]);

    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();

    Storage::disk('dvr')->put('recordings/show/episode.ts', str_repeat('x', 1024));
});

function makeStreamableRecording(object $ctx, ?int $playlistAuthId): DvrRecording
{
    return DvrRecording::factory()
        ->completed()
        ->for($ctx->setting, 'dvrSetting')
        ->for($ctx->user)
        ->create([
            'playlist_auth_id' => $playlistAuthId,
            'file_path' => 'recordings/show/episode.ts',
        ]);
}

/**
 * `dvr.recording.stream` uses an optional {format?} segment. Omitting it makes
 * Laravel's route() helper generate a trailing-dot URL (".../uuid.") that the
 * route itself won't match — every real caller (DvrRecording::getStreamUrls(),
 * DvrVodIntegrationService) always passes format explicitly, so tests must too.
 */
function dvrStreamUrl(string $username, string $password, string $uuid, string $format = 'ts'): string
{
    return route('dvr.recording.stream', [
        'username' => $username,
        'password' => $password,
        'uuid' => $uuid,
        'format' => $format,
    ]);
}

it('lets a guest credential stream its own recording', function () {
    $recording = makeStreamableRecording($this, $this->authA->id);

    $response = $this->get(dvrStreamUrl('credential-a', 'password-a', $recording->uuid));

    $response->assertOk();
});

it('404s when a guest credential requests a sibling credential\'s recording (no existence leak)', function () {
    $recording = makeStreamableRecording($this, $this->authB->id);

    $response = $this->get(dvrStreamUrl('credential-a', 'password-a', $recording->uuid));

    $response->assertNotFound();
});

it('404s a sibling credential\'s recording with the same status code as a genuinely nonexistent uuid', function () {
    // Ownership mismatch and non-existence must be indistinguishable to the
    // caller — both fall through the same query and abort(404, ...) call, so
    // a sibling's recording never reveals that it exists.
    $recording = makeStreamableRecording($this, $this->authB->id);

    $forbidden = $this->get(dvrStreamUrl('credential-a', 'password-a', $recording->uuid));
    $missing = $this->get(dvrStreamUrl('credential-a', 'password-a', (string) Str::uuid()));

    expect($forbidden->getStatusCode())->toBe($missing->getStatusCode())->toBe(404);
});

it('lets the playlist owner (owner_auth) stream any credential\'s recording', function () {
    $recording = makeStreamableRecording($this, $this->authA->id);

    $response = $this->get(dvrStreamUrl($this->user->name, $this->playlist->uuid, $recording->uuid));

    $response->assertOk();
});

it('rejects streaming when the playlist-level DvrSetting is disabled, even for the owning guest', function () {
    $recording = makeStreamableRecording($this, $this->authA->id);
    $this->setting->update(['enabled' => false]);

    $response = $this->get(dvrStreamUrl('credential-a', 'password-a', $recording->uuid));

    $response->assertNotFound();
});

it('rejects streaming when DVR is disabled globally via config, even for the owning guest', function () {
    $recording = makeStreamableRecording($this, $this->authA->id);
    config(['dvr.dvr_enabled' => false]);

    $response = $this->get(dvrStreamUrl('credential-a', 'password-a', $recording->uuid));

    $response->assertNotFound();
});

it('404s the EDL endpoint when a guest credential requests a sibling credential\'s recording', function () {
    $recording = makeStreamableRecording($this, $this->authB->id);

    $response = $this->get(route('dvr.recording.edl', [
        'username' => 'credential-a',
        'password' => 'password-a',
        'uuid' => $recording->uuid,
    ]));

    $response->assertNotFound();
});

it('lets a guest credential fetch the EDL for its own recording', function () {
    $recording = makeStreamableRecording($this, $this->authA->id);

    $response = $this->get(route('dvr.recording.edl', [
        'username' => 'credential-a',
        'password' => 'password-a',
        'uuid' => $recording->uuid,
    ]));

    $response->assertOk()->assertJson([]);
});

it('404s the HLS playlist endpoint when a guest credential requests a sibling credential\'s in-progress recording', function () {
    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->authB->id, 'proxy_network_id' => 'network-1']);

    $response = $this->get(route('dvr.recording.hls.playlist', [
        'username' => 'credential-a',
        'password' => 'password-a',
        'uuid' => $recording->uuid,
    ]));

    $response->assertNotFound();
});

it('keeps serving the HLS playlist while a recording is post-processing and the broadcast still exists', function () {
    Http::fake(['*' => Http::response("#EXTM3U\n#EXTINF:6.0,\nlive000001.ts\n")]);

    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::PostProcessing,
            'playlist_auth_id' => $this->authA->id,
            'proxy_network_id' => 'network-1',
        ]);

    $this->get(route('dvr.recording.hls.playlist', [
        'username' => 'credential-a',
        'password' => 'password-a',
        'uuid' => $recording->uuid,
    ]))
        ->assertOk()
        ->assertSee('/broadcast/network-1/segment/live000001.ts', false);
});

it('404s the HLS playlist once post-processing has cleaned up the broadcast', function () {
    Http::fake();

    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::PostProcessing,
            'playlist_auth_id' => $this->authA->id,
            'proxy_network_id' => null,
        ]);

    $this->get(route('dvr.recording.hls.playlist', [
        'username' => 'credential-a',
        'password' => 'password-a',
        'uuid' => $recording->uuid,
    ]))->assertNotFound();

    Http::assertNothingSent();
});
