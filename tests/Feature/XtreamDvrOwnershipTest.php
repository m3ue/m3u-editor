<?php

/**
 * Regression coverage for #1328 — Xtream DVR dispatch didn't check PlaylistAuth
 * ownership. Before this fix, `get_dvr_recordings`, `get_dvr_recording`,
 * `cancel_dvr_recording`, and `delete_dvr_recording` scoped only by
 * `dvr_setting_id` (the playlist), so any two PlaylistAuth credentials sharing
 * one playlist could view, cancel, or delete each other's DVR recordings.
 *
 * Locks in:
 *   1. `schedule_dvr` / `create_dvr_series_rule` stamp the created rule with the
 *      requesting credential's playlist_auth_id.
 *   2. `get_dvr_recordings` / `get_dvr_recording` only return recordings owned by
 *      the requesting PlaylistAuth credential — a sibling credential's recordings
 *      are invisible.
 *   3. `cancel_dvr_recording` / `delete_dvr_recording` 404 (same as "not found",
 *      not "forbidden" — consistent with the rest of this API not leaking
 *      existence) when the recording belongs to a different credential.
 *   4. The playlist owner (owner_auth, no PlaylistAuth) retains full visibility
 *      across all credentials' recordings — this is an intentional exception,
 *      not a regression.
 *   5. A PlaylistAuth credential without dvr_enabled cannot dispatch any DVR
 *      action at all, matching the same flag already enforced for feature
 *      advertisement (canAdvertiseDvrFeature).
 */

use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrSeriesMode;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

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

function dvrActionUrl(string $username, string $password, string $action): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]);
}

it('stamps schedule_dvr and create_dvr_series_rule with the requesting credential', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'schedule_dvr'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Evening News',
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule->playlist_auth_id)->toBe($this->authA->id);

    $response = $this->postJson(dvrActionUrl('credential-b', 'password-b', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Breaking News',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule->playlist_auth_id)->toBe($this->authB->id);
});

it('stores new_flag series_mode as new_flag, not rewriting it to all', function () {
    // Regression for the legacy new_only→series_mode migration in
    // DvrRecordingRule::saving: new_only defaults false and the API historically
    // never set it, so every new_flag rule was rewritten to all on save.
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'New Flag Show',
        'series_mode' => 'new_flag',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));

    expect($rule->series_mode)->toBe(DvrSeriesMode::NewFlag);
    expect($rule->new_only)->toBeTrue();
});

it('does not let new_only rewrite a unique_se rule to new_flag', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Unique Se Show',
        'series_mode' => 'unique_se',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));

    expect($rule->series_mode)->toBe(DvrSeriesMode::UniqueSe);
    expect($rule->new_only)->toBeFalse();
});

it('updates only fields present on update_dvr_series_rule, leaving others untouched', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Editable Show',
        'series_mode' => 'all',
        'keep_last' => 3,
        'priority' => 40,
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule->series_mode)->toBe(DvrSeriesMode::All);
    expect($rule->keep_last)->toBe(3);
    expect($rule->priority)->toBe(40);

    // Update only series_mode + priority; keep_last/channel/match_mode must be untouched.
    $update = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'update_dvr_series_rule'), [
        'rule_id' => (string) $rule->id,
        'series_mode' => 'new_flag',
        'priority' => 60,
    ])->assertOk();
    expect($update->json('rule_id'))->toBe($rule->id);

    $rule->refresh();
    expect($rule->series_mode)->toBe(DvrSeriesMode::NewFlag);
    expect($rule->new_only)->toBeTrue(); // lockstep with series_mode
    expect($rule->priority)->toBe(60);
    expect($rule->keep_last)->toBe(3); // unspecified field unchanged
    expect($rule->channel_id)->toBe($this->channel->id); // unspecified field unchanged
    expect($rule->match_mode)->toBe(DvrMatchMode::Contains); // unspecified field unchanged
});

it('updates match_mode and start/end padding on update_dvr_series_rule', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Padding Show',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'update_dvr_series_rule'), [
        'rule_id' => (string) $rule->id,
        'match_mode' => 'exact',
        'start_early_seconds' => '300',
        'end_late_seconds' => '600',
    ])->assertOk();

    $rule->refresh();
    expect($rule->match_mode)->toBe(DvrMatchMode::Exact);
    expect($rule->start_early_seconds)->toBe(300);
    expect($rule->end_late_seconds)->toBe(600);
});

it('switches a pinned rule to any-channel with a blank channel_id on update', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Unpin Show',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule->channel_id)->toBe($this->channel->id);

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'update_dvr_series_rule'), [
        'rule_id' => (string) $rule->id,
        'channel_id' => '',
    ])->assertOk();

    $rule->refresh();
    expect($rule->channel_id)->toBeNull();
});

it('404s update_dvr_series_rule for a rule owned by another credential', function () {
    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'create_dvr_series_rule'), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Owned Show',
    ])->assertOk();

    $rule = DvrRecordingRule::find($response->json('rule_id'));

    $this->postJson(dvrActionUrl('credential-b', 'password-b', 'update_dvr_series_rule'), [
        'rule_id' => (string) $rule->id,
        'priority' => '90',
    ])->assertNotFound();

    $rule->refresh();
    expect($rule->priority)->not->toBe(90);
});

it('does not let one credential see another credential\'s recordings via get_dvr_recordings', function () {
    $recordingA = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authA, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authB, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'get_dvr_recordings'))->assertOk();

    $uuids = collect($response->json())->pluck('uuid');
    expect($response->json())->toHaveCount(1);
    expect($uuids->first())->toBe($recordingA->uuid);
});

it('404s get_dvr_recording for a recording owned by another credential', function () {
    $recordingB = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authB, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled]);

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'get_dvr_recording'), [
        'recording_id' => $recordingB->uuid,
    ])->assertNotFound();

    $this->postJson(dvrActionUrl('credential-b', 'password-b', 'get_dvr_recording'), [
        'recording_id' => $recordingB->uuid,
    ])->assertOk();
});

it('404s cancel_dvr_recording for a recording owned by another credential, and never touches it', function () {
    $recordingB = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authB, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled, 'proxy_network_id' => null]);

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'cancel_dvr_recording'), [
        'recording_id' => $recordingB->uuid,
    ])->assertNotFound();

    expect($recordingB->fresh()->status)->toBe(DvrRecordingStatus::Scheduled);
});

it('404s delete_dvr_recording for a recording owned by another credential, and never touches it', function () {
    $recordingB = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authB, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Completed]);

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'delete_dvr_recording'), [
        'recording_id' => $recordingB->uuid,
    ])->assertNotFound();

    expect(DvrRecording::find($recordingB->id))->not->toBeNull();
});

it('lets the playlist owner see and manage every credential\'s recordings', function () {
    $recordingA = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authA, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled, 'proxy_network_id' => null]);

    $recordingB = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($this->channel)
        ->for($this->authB, 'playlistAuth')
        ->create(['status' => DvrRecordingStatus::Scheduled, 'proxy_network_id' => null]);

    $response = $this->postJson(
        dvrActionUrl($this->user->name, $this->playlist->uuid, 'get_dvr_recordings')
    )->assertOk();

    expect($response->json())->toHaveCount(2);

    $this->postJson(dvrActionUrl($this->user->name, $this->playlist->uuid, 'cancel_dvr_recording'), [
        'recording_id' => $recordingA->uuid,
    ])->assertOk()->assertJson(['success' => true]);

    $this->postJson(dvrActionUrl($this->user->name, $this->playlist->uuid, 'cancel_dvr_recording'), [
        'recording_id' => $recordingB->uuid,
    ])->assertOk()->assertJson(['success' => true]);
});

it('rejects all DVR actions for a PlaylistAuth credential without dvr_enabled', function (string $action) {
    $this->authA->update(['dvr_enabled' => false]);

    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', $action), [
        'recording_id' => 'does-not-matter',
        'rule_id' => '1',
        'channel_id' => (string) $this->channel->id,
        'title' => 'Evening News',
        'q' => 'ne',
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ]);

    $response->assertStatus(403)->assertJson(['error' => 'DVR access denied']);
})->with([
    'get_dvr_recordings',
    'get_dvr_recording',
    'schedule_dvr',
    'create_dvr_series_rule',
    'update_dvr_series_rule',
    'cancel_dvr_recording',
    'delete_dvr_recording',
    'list_dvr_series_rules',
    'delete_dvr_series_rule',
    'search_epg_shows',
]);

it('does not let one credential see or delete another credential\'s series rule via list/delete_dvr_series_rule', function () {
    $ruleA = DvrRecordingRule::create([
        'user_id' => $this->user->id,
        'dvr_setting_id' => $this->setting->id,
        'playlist_auth_id' => $this->authA->id,
        'type' => \App\Enums\DvrRuleType::Series,
        'channel_id' => $this->channel->id,
        'series_title' => 'Credential A Show',
        'match_mode' => DvrMatchMode::Contains,
        'series_mode' => DvrSeriesMode::All,
        'enabled' => true,
    ]);

    DvrRecordingRule::create([
        'user_id' => $this->user->id,
        'dvr_setting_id' => $this->setting->id,
        'playlist_auth_id' => $this->authB->id,
        'type' => \App\Enums\DvrRuleType::Series,
        'channel_id' => $this->channel->id,
        'series_title' => 'Credential B Show',
        'match_mode' => DvrMatchMode::Contains,
        'series_mode' => DvrSeriesMode::All,
        'enabled' => true,
    ]);

    $response = $this->postJson(dvrActionUrl('credential-a', 'password-a', 'list_dvr_series_rules'))->assertOk();
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.series_title'))->toBe('Credential A Show');
    expect($response->json('0.recording_count'))->toBe(0);

    $this->postJson(dvrActionUrl('credential-a', 'password-a', 'delete_dvr_series_rule'), [
        'rule_id' => (string) $ruleA->id,
    ])->assertOk()->assertJson(['success' => true]);

    expect(DvrRecordingRule::find($ruleA->id))->toBeNull();
});
