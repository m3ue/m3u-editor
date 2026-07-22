<?php

/**
 * Regression coverage for Xtream `schedule_dvr` → DvrRecordingRule → DvrRecording.
 *
 * The endpoint must produce a `Manual` rule (not `Once`) so the scheduler
 * routes it to `matchManualRule`, which reads `manual_start`/`manual_end`
 * and creates the `Scheduled` DvrRecording row.
 *
 * Locks in:
 *   1. Schedule DvrRecordingRule with `type = Manual`.
 *   2. Boot event fires scheduleRuleImmediately for Manual rules,
 *      producing a Scheduled DvrRecording row within the same request.
 *   3. Scheduled row has the requested manual_start/manual_end window.
 */

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The playlist listener dispatches ProcessM3uImport on playlist create;
    // fake the queue so the sync driver does not try to reach Redis.
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

    DvrSetting::factory()
        ->enabled()
        ->for($this->user)
        ->for($this->playlist)
        ->create();
});

function scheduleDvrUrl(string $username, string $password): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'schedule_dvr',
    ]);
}

it('creates a Manual DvrRecordingRule from the Xtream schedule_dvr action', function () {
    $start = now()->addHour()->startOfMinute();
    $end = $start->copy()->addMinutes(45);

    $response = $this->postJson(scheduleDvrUrl($this->username, $this->password), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Evening News',
        'start_time' => $start->toIso8601String(),
        'end_time' => $end->toIso8601String(),
    ]);

    $response->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonStructure(['success', 'rule_id', 'message']);

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule)->not->toBeNull();
    expect($rule->type)->toBe(DvrRuleType::Manual);
    expect($rule->channel_id)->toBe($this->channel->id);
    expect($rule->series_title)->toBe('Evening News');
    expect($rule->enabled)->toBeTrue();
});

it('materialises a Scheduled DvrRecording in the same request via the boot event', function () {
    $start = now()->addMinutes(5)->startOfMinute();
    $end = $start->copy()->addMinutes(30);

    $response = $this->postJson(scheduleDvrUrl($this->username, $this->password), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Late Show',
        'start_time' => $start->toIso8601String(),
        'end_time' => $end->toIso8601String(),
    ]);

    $response->assertOk();
    $rule = DvrRecordingRule::find($response->json('rule_id'));

    $recording = DvrRecording::where('dvr_recording_rule_id', $rule->id)->first();
    expect($recording)->not->toBeNull('Manual rule should immediately produce a DvrRecording row');
    expect($recording->status)->toBe(DvrRecordingStatus::Scheduled);
    expect($recording->channel_id)->toBe($this->channel->id);
    expect($recording->scheduled_start->equalTo($rule->manual_start->copy()->subSeconds($rule->start_early_seconds)))->toBeTrue();
    expect($recording->scheduled_end->equalTo($rule->manual_end->copy()->addSeconds($rule->end_late_seconds)))->toBeTrue();
});

it('stores the correct absolute instant when app.timezone is not UTC', function () {
    // manual_start/manual_end are cast as `datetime`, which Eloquent re-hydrates by
    // reinterpreting the stored wall-clock in `app.timezone`. The TV client always
    // sends UTC ISO 8601 timestamps, so a non-UTC app.timezone (a supported,
    // user-configurable setting — see GeneralSettings::app_timezone) must not shift
    // the recorded instant. Regression for the schedule appearing hours off in the
    // admin UI when the server's timezone isn't UTC.
    config(['app.timezone' => 'America/Denver']);
    date_default_timezone_set('America/Denver');

    // Mirror the TV client exactly: it always sends `.toUtc().toIso8601String()`,
    // i.e. a 'Z'-suffixed UTC timestamp, regardless of the server's timezone.
    $start = now()->utc()->addMinutes(5)->startOfMinute();
    $end = $start->copy()->addMinutes(30);

    $response = $this->postJson(scheduleDvrUrl($this->username, $this->password), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Late Show',
        'start_time' => $start->toIso8601String(),
        'end_time' => $end->toIso8601String(),
    ]);

    $response->assertOk();
    $rule = DvrRecordingRule::find($response->json('rule_id'));

    expect($rule->manual_start->equalTo($start))->toBeTrue();
    expect($rule->manual_end->equalTo($end))->toBeTrue();

    // Re-fetch from a fresh model instance to force the read-side cast round-trip,
    // not just the in-memory value set during this request.
    $rule->refresh();
    expect($rule->manual_start->equalTo($start))->toBeTrue();
    expect($rule->manual_end->equalTo($end))->toBeTrue();
});

it('rejects scheduling when DVR is not enabled for the playlist', function () {
    DvrSetting::where('playlist_id', $this->playlist->id)->update(['enabled' => false]);

    $response = $this->postJson(scheduleDvrUrl($this->username, $this->password), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Evening News',
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ]);

    $response->assertStatus(422)
        ->assertJson(['error' => 'DVR is not enabled for this playlist']);

    expect(DvrRecordingRule::count())->toBe(0);
});

it('rejects scheduling a channel that belongs to a different playlist', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherChannel = Channel::factory()
        ->for($otherPlaylist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'Other Playlist Channel']);

    $response = $this->postJson(scheduleDvrUrl($this->username, $this->password), [
        'channel_id' => (string) $otherChannel->id,
        'title' => 'Evening News',
        'start_time' => now()->addHour()->toIso8601String(),
        'end_time' => now()->addHours(2)->toIso8601String(),
    ]);

    $response->assertStatus(404)
        ->assertJson(['error' => 'Channel not found']);

    expect(DvrRecordingRule::count())->toBe(0);
});

function createDvrSeriesRuleUrl(string $username, string $password): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'create_dvr_series_rule',
    ]);
}

it('creates a Series DvrRecordingRule from the Xtream create_dvr_series_rule action', function () {
    $response = $this->postJson(createDvrSeriesRuleUrl($this->username, $this->password), [
        'channel_id' => (string) $this->channel->id,
        'title' => 'Breaking News',
    ]);

    $response->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonStructure(['success', 'rule_id']);

    $rule = DvrRecordingRule::find($response->json('rule_id'));
    expect($rule)->not->toBeNull();
    expect($rule->type)->toBe(DvrRuleType::Series);
    expect($rule->channel_id)->toBe($this->channel->id);
});

it('rejects a series rule for a channel that belongs to a different playlist', function () {
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $otherChannel = Channel::factory()
        ->for($otherPlaylist)
        ->for($this->group)
        ->create(['enabled' => true, 'title_custom' => 'Other Playlist Channel']);

    $response = $this->postJson(createDvrSeriesRuleUrl($this->username, $this->password), [
        'channel_id' => (string) $otherChannel->id,
        'title' => 'Breaking News',
    ]);

    $response->assertStatus(404)
        ->assertJson(['error' => 'Channel not found']);

    expect(DvrRecordingRule::count())->toBe(0);
});
