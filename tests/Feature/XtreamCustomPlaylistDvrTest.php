<?php

/**
 * Regression coverage for `resolveDvrSettingIds()` handling CustomPlaylist.
 *
 * Before this fix, `get_dvr_recordings` / `get_dvr_recording` / `cancel_dvr_recording` /
 * `delete_dvr_recording` read `$playlist->dvrSetting` directly. A CustomPlaylist has no
 * DvrSetting of its own when the recording was actually scheduled against its channels'
 * real source Playlist — so listing/cancelling/deleting through the CustomPlaylist a guest
 * actually authenticated against always came back empty/404, even though the same
 * recording was fully visible and manageable through the source Playlist directly.
 *
 * Locks in:
 *   1. A recording filed under the source Playlist's DvrSetting is visible via
 *      get_dvr_recordings/get_dvr_recording when authenticated against a CustomPlaylist
 *      whose channels include that recording's channel.
 *   2. cancel_dvr_recording and delete_dvr_recording work the same way through the
 *      CustomPlaylist.
 *   3. A CustomPlaylist with no channels from that source Playlist sees nothing.
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->user)->create();
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'News 24']);

    $this->setting = DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();

    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $this->customPlaylist->channels()->attach($this->channel->id, ['sort' => 0]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);
});

function customPlaylistDvrActionUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('lists a recording filed under the source playlist through a CustomPlaylist wrapping that channel', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    $response = $this->postJson(
        customPlaylistDvrActionUrl($this->user->name, $this->customPlaylist->uuid, 'get_dvr_recordings')
    )->assertOk();

    $uuids = collect($response->json())->pluck('uuid');
    expect($uuids)->toContain($recording->uuid);
});

it('fetches a single recording via get_dvr_recording through a CustomPlaylist', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    $this->postJson(
        customPlaylistDvrActionUrl($this->user->name, $this->customPlaylist->uuid, 'get_dvr_recording'),
        ['recording_id' => $recording->uuid]
    )->assertOk()->assertJsonPath('uuid', $recording->uuid);
});

it('cancels a recording through a CustomPlaylist wrapping the source channel', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled, 'proxy_network_id' => null]);

    $this->postJson(
        customPlaylistDvrActionUrl($this->user->name, $this->customPlaylist->uuid, 'cancel_dvr_recording'),
        ['recording_id' => $recording->uuid]
    )->assertOk()->assertJson(['success' => true]);

    expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Cancelled);
});

it('deletes a completed recording through a CustomPlaylist wrapping the source channel', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Completed]);

    $this->postJson(
        customPlaylistDvrActionUrl($this->user->name, $this->customPlaylist->uuid, 'delete_dvr_recording'),
        ['recording_id' => $recording->uuid]
    )->assertOk()->assertJson(['success' => true]);

    expect(DvrRecording::find($recording->id))->toBeNull();
});

it('denies DVR access through a CustomPlaylist with no channels from any DVR-enabled source playlist', function () {
    $otherCustomPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    // No source-playlist channels means resolveDvrSettingIds() finds nothing to grant
    // capability against, so this is a 403 (DVR access denied), not an empty 200 list —
    // the other playlist's recording must never be reachable here either way.
    $this->postJson(
        customPlaylistDvrActionUrl($this->user->name, $otherCustomPlaylist->uuid, 'get_dvr_recordings')
    )->assertStatus(403)->assertJson(['error' => 'DVR access denied']);
});
