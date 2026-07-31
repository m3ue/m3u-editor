<?php

/**
 * Regression coverage for Xtream `cancel_dvr_recording` / `delete_dvr_recording`.
 *
 * Locks in:
 *   1. Cancelling a recording that never started (Scheduled, no footage) marks it
 *      Cancelled immediately — nothing for post-processing to do.
 *   2. Cancelling a recording that already captured footage (Recording, has a
 *      proxy_network_id) routes it through post-processing instead — it ends up
 *      PostProcessing, not Cancelled, so it can become a playable Completed
 *      recording (see DvrRecorderServiceTest for the service-level coverage).
 *   3. delete_dvr_recording accepts Completed/Failed/Cancelled/PostProcessing —
 *      PostProcessing is included because the TV app's "Delete recording" choice
 *      calls cancel_dvr_recording then delete_dvr_recording back-to-back, and a
 *      footage-having recording is already in PostProcessing by the time the
 *      delete call arrives.
 *   4. delete_dvr_recording still rejects Scheduled/Recording (nothing has told
 *      the proxy to stop yet).
 *   5. delete_dvr_recording releases the proxy broadcast itself when deleting a
 *      PostProcessing recording — PostProcessDvrRecording (which normally owns
 *      that cleanup) hasn't had a chance to run yet in the cancel-then-delete
 *      flow, so without this the proxy-side segment files would be orphaned.
 */

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\DvrPostProcessorService;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'testuser_'.Str::random(5);
    $this->password = 'testpass';

    PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);

    $this->playlist->playlistAuths()->attach(
        PlaylistAuth::where('username', $this->username)->first()
    );

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

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);
});

function xtreamActionUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('cancels a scheduled (never-started) recording immediately with no post-processing', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => DvrRecordingStatus::Scheduled, 'proxy_network_id' => null]);

    $response = $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'cancel_dvr_recording'),
        ['recording_id' => $recording->uuid]
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Cancelled);

    Queue::assertNotPushed(PostProcessDvrRecording::class);
});

it('cancels a recording with captured footage into PostProcessing, not Cancelled', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Recording,
            'proxy_network_id' => 'test-network-id',
        ]);

    $response = $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'cancel_dvr_recording'),
        ['recording_id' => $recording->uuid]
    );

    $response->assertOk()->assertJson(['success' => true]);
    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::PostProcessing);
    expect($fresh->proxy_network_id)->toBe('test-network-id');
    expect($fresh->user_cancelled)->toBeTrue();

    Queue::assertPushed(PostProcessDvrRecording::class);
});

it('deletes a PostProcessing recording — the state a cancelled in-progress recording is in when "Delete recording" chains cancel then delete', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::PostProcessing,
            'user_cancelled' => true,
        ]);

    $response = $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'delete_dvr_recording'),
        ['recording_id' => $recording->uuid]
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect(DvrRecording::find($recording->id))->toBeNull();
});

it('releases the proxy broadcast when deleting a PostProcessing recording that still holds a proxy_network_id', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::PostProcessing,
            'proxy_network_id' => 'test-network-id',
            'user_cancelled' => true,
        ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldNotReceive('stopDvrBroadcast');
    $proxy->shouldReceive('cleanupDvrBroadcast')->once()->with('test-network-id')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);

    $response = $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'delete_dvr_recording'),
        ['recording_id' => $recording->uuid]
    );

    $response->assertOk()->assertJson(['success' => true]);
    expect(DvrRecording::find($recording->id))->toBeNull();
});

it('reproduces the TV app "Delete recording" flow — cancel then immediate delete releases the proxy broadcast exactly once, before PostProcessDvrRecording ever runs', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create([
            'status' => DvrRecordingStatus::Recording,
            'proxy_network_id' => 'test-network-id',
        ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->once()->with('test-network-id')->andReturn(true);
    $proxy->shouldReceive('cleanupDvrBroadcast')->once()->with('test-network-id')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);

    // AppShell._cancelAndDeleteRecording: cancel_dvr_recording immediately
    // followed by delete_dvr_recording, well before the queue worker could
    // ever pick up the delayed/callback-triggered post-processing job.
    $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'cancel_dvr_recording'),
        ['recording_id' => $recording->uuid]
    )->assertOk();

    $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'delete_dvr_recording'),
        ['recording_id' => $recording->uuid]
    )->assertOk()->assertJson(['success' => true]);

    expect(DvrRecording::find($recording->id))->toBeNull();

    // The safety-net job was queued by cancel()'s finalizeStop() but the row is
    // already gone. Running it now must be a graceful no-op — in particular it
    // must NOT call cleanupDvrBroadcast() again (the ->once() expectation above
    // would fail the test if it did).
    Queue::assertPushed(
        PostProcessDvrRecording::class,
        fn (PostProcessDvrRecording $job) => $job->recordingId === $recording->id
    );
    (new PostProcessDvrRecording($recording->id))->handle(app(DvrPostProcessorService::class));
});

it('rejects deleting a Scheduled or Recording recording — nothing has told the proxy to stop yet', function (DvrRecordingStatus $status) {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->create(['status' => $status]);

    $response = $this->postJson(
        xtreamActionUrl($this->username, $this->password, 'delete_dvr_recording'),
        ['recording_id' => $recording->uuid]
    );

    $response->assertNotFound();
    expect(DvrRecording::find($recording->id))->not->toBeNull();
})->with([DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]);
