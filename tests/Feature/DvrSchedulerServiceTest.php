<?php

/**
 * Tests for DvrSchedulerService
 *
 * Covers:
 * - Series rules: match upcoming programmes by title and create SCHEDULED recordings
 * - Series rules: skip already-scheduled recordings (dedup)
 * - Series rules: respect new_only flag
 * - Once rules: match a specific programme by programme_id
 * - Once rules: disable rule when programme_id is not found
 * - Manual rules: create a recording from manual_start/end window
 * - Capacity enforcement: skip scheduling when at max_concurrent_recordings
 * - triggerPendingRecordings: dispatch StartDvrRecording for due recordings
 * - stopExpiredRecordings: dispatch StopDvrRecording for overdue recordings
 * - Disabled rules are not processed
 */

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Jobs\StartDvrRecording;
use App\Jobs\StopDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\User;
use App\Services\DvrSchedulerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['dvr.scheduler_lookahead_minutes' => 30]);

    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'max_concurrent_recordings' => 2,
        'default_start_early_seconds' => 0,
        'default_end_late_seconds' => 0,
    ]);

    // Create an EPG channel + playlist channel so series rules can scope programmes
    // and resolve a stream URL without a pinned channel_id on the rule.
    $this->epgChannel = EpgChannel::factory()->create(['channel_id' => 'test.channel']);
    $this->channel = Channel::factory()
        ->for($this->setting->playlist)
        ->create(['epg_channel_id' => $this->epgChannel->id]);

    $this->service = app(DvrSchedulerService::class);
});

// --- Series rules ---

it('creates a scheduled recording for a matching series programme', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Breaking Bad']);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->first()->status)
        ->toBe(DvrRecordingStatus::Scheduled);
});

it('does not duplicate a recording for a programme already scheduled', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Breaking Bad']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
    ]);

    // Pre-create the recording to simulate a previous tick
    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'test.channel'],
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('skips non-new programmes when new_only is enabled', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Some Show', 'new_only' => true]);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Some Show',
        'epg_channel_id' => 'test.channel',
        'is_new' => false,
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

it('schedules a new-only programme when is_new is true', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Some Show', 'new_only' => true]);

    EpgProgramme::factory()->upcoming(10)->isNew()->create([
        'title' => 'Some Show',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

// --- Once rules ---

it('creates a scheduled recording for a once rule with a valid programme_id', function () {
    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Special Event',
    ]);

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => $programme->id,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('disables a once rule when the programme_id no longer exists', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => 99999,
        ]);

    $this->service->tick();

    expect($rule->fresh()->enabled)->toBeFalse();
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

// --- Once rules (dummy EPG fallback) ---

it('schedules a once rule with no programme_id using the current dummy epg slot', function () {
    // Override the DVR setting's playlist to have dummy EPG enabled
    $this->setting->playlist->update([
        'dummy_epg' => true,
        'dummy_epg_length' => 60,
    ]);

    $channel = Channel::factory()->for($this->setting->playlist)->create();

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => null,
            'channel_id' => $channel->id,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
    expect($rule->fresh()->enabled)->toBeFalse();
});

it('does not schedule a once rule with no programme_id when playlist has no dummy epg', function () {
    $this->setting->playlist->update(['dummy_epg' => false]);

    $channel = Channel::factory()->for($this->setting->playlist)->create();

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => null,
            'channel_id' => $channel->id,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
    expect($rule->fresh()->enabled)->toBeTrue();
});

it('does not duplicate a dummy epg once recording on subsequent ticks', function () {
    $this->setting->playlist->update([
        'dummy_epg' => true,
        'dummy_epg_length' => 60,
    ]);

    $channel = Channel::factory()->for($this->setting->playlist)->create();

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => null,
            'channel_id' => $channel->id,
        ]);

    $this->service->tick();
    // Re-enable the rule and tick again to verify dedup works (not just the disabled-rule guard)
    $rule->update(['enabled' => true]);
    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

// --- Manual rules ---

it('creates a scheduled recording for a manual rule within the lookahead window', function () {
    // Use a start time within the 30-minute lookahead window
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->addMinutes(10),
            'manual_end' => now()->addMinutes(70),
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->first()->title)
        ->toBe('Manual Recording');
});

it('creates a scheduled recording for a manual rule more than 30 minutes in the future', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->addHours(2),
            'manual_end' => now()->addHours(3),
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('skips a manual rule whose end time has already passed', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->subHours(2),
            'manual_end' => now()->subMinutes(5),
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

// --- Capacity ---

it('does not schedule when the dvr setting is at capacity', function () {
    $this->setting->update(['max_concurrent_recordings' => 1]);

    DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create();

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Capacity Show']);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Capacity Show',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

// --- Disabled rules ---

it('does not process disabled rules', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->disabled()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Hidden Show']);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Hidden Show',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

// --- Trigger pending recordings ---

it('dispatches StartDvrRecording for recordings whose scheduled_start has passed', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinutes(1),
            'scheduled_end' => now()->addHour(),
        ]);

    $this->service->tick();

    Queue::assertPushed(StartDvrRecording::class, fn ($job) => $job->recordingId === $recording->id);
});

it('does not dispatch StartDvrRecording for a future recording', function () {
    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

    $this->service->tick();

    Queue::assertNotPushed(StartDvrRecording::class);
});

// --- Stop expired recordings ---

it('dispatches StopDvrRecording for recordings whose scheduled_end has passed', function () {
    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->subMinutes(5),
        ]);

    $this->service->tick();

    Queue::assertPushed(StopDvrRecording::class, fn ($job) => $job->recordingId === $recording->id);
});

// --- Re-scheduling after failure ---

it('re-schedules the same programme after a failed recording', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Retry Show']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Retry Show',
        'epg_channel_id' => 'test.channel',
    ]);

    // Pre-existing Failed recording for this programme slot
    DvrRecording::factory()
        ->failed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'test.channel'],
        ]);

    $this->service->tick();

    // A new Scheduled recording should have been created alongside the Failed one
    expect(
        DvrRecording::where('dvr_recording_rule_id', $rule->id)
            ->where('status', DvrRecordingStatus::Scheduled)
            ->count()
    )->toBe(1);
});

// --- Offset application ---

it('applies default_start_early_seconds and default_end_late_seconds offsets', function () {
    $this->setting->update([
        'default_start_early_seconds' => 60,
        'default_end_late_seconds' => 120,
    ]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Offset Show']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Offset Show',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    $recording = DvrRecording::where('dvr_recording_rule_id', $rule->id)->firstOrFail();

    // scheduled_start should be 60 s before programme.start_time
    expect($recording->scheduled_start->timestamp)
        ->toBe($programme->fresh()->start_time->subSeconds(60)->timestamp);

    // scheduled_end should be 120 s after programme.end_time
    expect($recording->scheduled_end->timestamp)
        ->toBe($programme->fresh()->end_time->addSeconds(120)->timestamp);
});

// --- Manual rule dedup ---

it('does not create a duplicate manual recording on a second tick', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->addMinutes(10),
            'manual_end' => now()->addMinutes(70),
        ]);

    $this->service->tick();
    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

// --- Once rule with currently-airing programme ---

it('schedules a once rule for a programme starting more than 30 minutes in the future', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Future Show',
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(4),
    ]);

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => $programme->id,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('schedules a once rule for a programme that is currently airing', function () {
    $programme = EpgProgramme::factory()->create([
        'title' => 'Airing Now',
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Once,
            'programme_id' => $programme->id,
        ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

// --- Stream URL resolution via EPG fallback ---

it('resolves stream_url via programme epg_channel_id when rule has no channel_id', function () {
    $epgChannel = EpgChannel::factory()->create(['channel_id' => 'channel.test.epg.fallback']);

    $channel = Channel::factory()
        ->for($this->setting->playlist)
        ->create([
            'epg_channel_id' => $epgChannel->id,
            'url' => 'http://example.com/stream.m3u8',
        ]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'EPG Fallback Show', 'channel_id' => null]);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'EPG Fallback Show',
        'epg_channel_id' => 'channel.test.epg.fallback',
    ]);

    $this->service->tick();

    $recording = DvrRecording::where('dvr_recording_rule_id', $rule->id)->firstOrFail();

    expect($recording->stream_url)->toBe('http://example.com/stream.m3u8')
        ->and($recording->channel_id)->toBe($channel->id);
});

it('does not schedule a programme whose EPG channel has no playlist mapping', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Unmapped Show', 'channel_id' => null]);

    // Programme's epg_channel_id has no corresponding Channel in this playlist
    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Unmapped Show',
        'epg_channel_id' => 'channel.no.match',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});
