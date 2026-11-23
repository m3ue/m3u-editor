<?php

/**
 * update_progress with content_type=dvr_recording: the client resumes a DVR
 * recording keyed by its numeric id, so the write must be limited to recordings
 * reachable through the credential's DVR settings, and must work for every
 * credential kind that can see DVR (playlist auth, guest auth, alias auth).
 */

use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::create([
        'name' => 'DVR Credential',
        'username' => 'dvr-progress-user',
        'password' => 'dvr-progress-pass',
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
        ->create();
});

function makeDvrProgressRecording($test, ?PlaylistAuth $auth = null, ?DvrSetting $setting = null): DvrRecording
{
    return DvrRecording::factory()
        ->for($setting ?? $test->setting, 'dvrSetting')
        ->for($test->user)
        ->for($test->channel)
        ->when($auth, fn ($factory) => $factory->for($auth, 'playlistAuth'))
        ->completed()
        ->create();
}

function postDvrProgress(string $username, string $password, int $recordingId): TestResponse
{
    return test()->postJson(route('xtream.api.player'), [
        'username' => $username,
        'password' => $password,
        'action' => 'update_progress',
        'content_type' => 'dvr_recording',
        'stream_id' => (string) $recordingId,
        'position_seconds' => '120',
        'duration_seconds' => '3600',
    ]);
}

it('stores dvr recording progress for a recording the credential can reach', function () {
    $recording = makeDvrProgressRecording($this, $this->auth);

    postDvrProgress('dvr-progress-user', 'dvr-progress-pass', $recording->id)->assertOk();

    $progress = ViewerWatchProgress::query()->where('content_type', 'dvr_recording')->sole();

    expect($progress->stream_id)->toBe($recording->id)
        ->and($progress->position_seconds)->toBe(120)
        ->and($progress->dvrRecording->is($recording))->toBeTrue();
});

it('rejects dvr recording progress for a recording on another dvr setting', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherSetting = DvrSetting::factory()->enabled()->for($this->user)->for($otherPlaylist)->create();
    $recording = makeDvrProgressRecording($this, setting: $otherSetting);

    postDvrProgress('dvr-progress-user', 'dvr-progress-pass', $recording->id)->assertNotFound();

    expect(ViewerWatchProgress::query()->where('content_type', 'dvr_recording')->exists())->toBeFalse();
});

it('rejects dvr recording progress for another guest credential recording', function () {
    $otherAuth = PlaylistAuth::create([
        'name' => 'Other Guest',
        'username' => 'other-guest',
        'password' => 'other-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($otherAuth->id);
    $recording = makeDvrProgressRecording($this, $otherAuth);

    postDvrProgress('dvr-progress-user', 'dvr-progress-pass', $recording->id)->assertNotFound();

    expect(ViewerWatchProgress::query()->where('content_type', 'dvr_recording')->exists())->toBeFalse();
});

it('stores dvr recording progress through playlist alias credentials', function () {
    $alias = PlaylistAlias::create([
        'name' => 'DVR Alias',
        'uuid' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'xtream_config' => null,
        'username' => 'dvr-alias-user',
        'password' => 'dvr-alias-pass',
    ]);
    PlaylistViewer::firstOrCreate([
        'viewerable_type' => $alias->getMorphClass(),
        'viewerable_id' => $alias->id,
        'is_admin' => true,
    ], [
        'ulid' => (string) Str::ulid(),
        'name' => 'Admin',
    ]);
    $recording = makeDvrProgressRecording($this);

    postDvrProgress('dvr-alias-user', 'dvr-alias-pass', $recording->id)->assertOk();

    expect(ViewerWatchProgress::query()->where('content_type', 'dvr_recording')->sole()->stream_id)
        ->toBe($recording->id);
});

it('leaves dvr recordings out of the general recently watched list', function () {
    $recording = makeDvrProgressRecording($this, $this->auth);
    postDvrProgress('dvr-progress-user', 'dvr-progress-pass', $recording->id)->assertOk();

    $this->getJson(route('xtream.api.player', [
        'username' => 'dvr-progress-user',
        'password' => 'dvr-progress-pass',
        'action' => 'get_recently_watched',
    ]))->assertOk()->assertExactJson([]);
});

it('returns dvr recording progress on request without channel metadata', function () {
    $recording = makeDvrProgressRecording($this, $this->auth);
    // A channel sharing the recording's id must not lend it a title.
    Channel::query()->whereKey($this->channel->id)->update(['id' => $recording->id, 'title' => 'Unrelated Channel']);
    postDvrProgress('dvr-progress-user', 'dvr-progress-pass', $recording->id)->assertOk();

    $rows = $this->getJson(route('xtream.api.player', [
        'username' => 'dvr-progress-user',
        'password' => 'dvr-progress-pass',
        'action' => 'get_recently_watched',
        'type' => 'dvr_recording',
    ]))->assertOk()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['stream_id'])->toBe($recording->id)
        ->and($rows[0]['position_seconds'])->toBe(120)
        ->and(json_encode($rows))->not->toContain('Unrelated Channel');
});
