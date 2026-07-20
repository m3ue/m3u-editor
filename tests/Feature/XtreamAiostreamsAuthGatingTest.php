<?php

/**
 * AIOStreams now follows the same auth-gating model as DVR/Requests: the parent
 * playlist (Playlist/CustomPlaylist/MergedPlaylist) must have AIOStreams enabled
 * (aiostreams_integration_id set to an enabled MediaServerIntegration), and a
 * PlaylistAuth can only toggle a guest's access on or off — it can no longer
 * point at a different integration than its assigned playlist.
 */

use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

function aiostreamsAuthUrl(string $username, string $password): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'panel',
    ]);
}

it('does not advertise aiostreams when the auth toggle is off, even though the playlist has it enabled', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $integration->id,
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'aiostreams_enabled' => false,
        'username' => 'aios-off-user',
        'password' => 'aios-off-pass',
    ]);
    $auth->assignTo($playlist);

    $response = $this->getJson(aiostreamsAuthUrl('aios-off-user', 'aios-off-pass'));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))->not->toContain('aiostreams');
});

it('does not advertise aiostreams when the auth toggle is on but the parent playlist has no integration', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => null,
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'aiostreams_enabled' => true,
        'username' => 'aios-no-playlist-user',
        'password' => 'aios-no-playlist-pass',
    ]);
    $auth->assignTo($playlist);

    $response = $this->getJson(aiostreamsAuthUrl('aios-no-playlist-user', 'aios-no-playlist-pass'));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))->not->toContain('aiostreams');
});

it('advertises aiostreams only when both the parent playlist and the auth toggle allow it', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $integration->id,
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'aiostreams_enabled' => true,
        'username' => 'aios-on-user',
        'password' => 'aios-on-pass',
    ]);
    $auth->assignTo($playlist);

    $response = $this->getJson(aiostreamsAuthUrl('aios-on-user', 'aios-on-pass'));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))->toContain('aiostreams')
        ->and($response->json('m3u_editor.aiostreams.0.id'))->toBe($integration->id);
});

it('advertises aiostreams for a merged playlist auth once the merged playlist itself carries the integration', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $integration->id,
    ]);

    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'aiostreams_enabled' => true,
        'username' => 'aios-merged-user',
        'password' => 'aios-merged-pass',
    ]);
    $auth->assignTo($merged);

    $response = $this->getJson(aiostreamsAuthUrl('aios-merged-user', 'aios-merged-pass'));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))->toContain('aiostreams');
});

it('advertises aiostreams for owner/alias credentials without requiring an auth toggle', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
    ]);
    $playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $integration->id,
        'uuid' => 'owner-aios-uuid',
    ]);

    $response = $this->getJson(aiostreamsAuthUrl($this->user->name, 'owner-aios-uuid'));

    $response->assertOk();
    expect($response->json('m3u_editor.features'))->toContain('aiostreams');
});
