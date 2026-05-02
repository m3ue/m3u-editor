<?php

/**
 * Tests for DvrSchedulerService
 *
 * Covers:
 * - Series rules: match upcoming programmes by title and create SCHEDULED recordings
 * - Series rules: skip already-scheduled recordings (dedup)
 * - Series rules: respect series_mode (all / new_flag / unique_se)
 * - Once rules: match a specific programme by programme_id
 * - Once rules: disable rule when programme_id is not found
 * - Manual rules: create a recording from manual_start/end window
 * - Capacity enforcement: skip scheduling when at max_concurrent_recordings
 * - triggerPendingRecordings: dispatch StartDvrRecording for due recordings
 * - stopExpiredRecordings: dispatch StopDvrRecording for overdue recordings
 * - Disabled rules are not processed
 */

use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
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
            'title' => 'Breaking Bad',
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

// --- Phase 0: Stale-window handling ---

it('marks scheduled recordings whose window has fully passed as Failed', function () {
    $recording = DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
        ]);

    $this->service->tick();

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::Failed)
        ->and($fresh->error_message)->toContain('Missed recording window');

    Queue::assertNotPushed(StartDvrRecording::class);
});

it('does not dispatch a start job for a recording whose end has already passed', function () {
    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subMinutes(1),
        ]);

    $this->service->tick();

    Queue::assertNotPushed(StartDvrRecording::class);
});

// --- Phase 0: Capacity race within a single tick ---

it('does not dispatch more starts in one tick than free capacity slots', function () {
    $this->setting->update(['max_concurrent_recordings' => 2]);

    // One slot is already occupied by an in-flight recording
    DvrRecording::factory()
        ->recording()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create();

    // Three rows are all due this tick — only one should start (1 active + 1 new = 2 max)
    DvrRecording::factory()
        ->count(3)
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->state(fn () => [
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHour(),
        ])
        ->create();

    $this->service->tick();

    Queue::assertPushed(StartDvrRecording::class, 1);
});

// --- Phase 0: use_proxy honours both DVR setting and playlist ---

it('uses proxy URL only when both DVR setting use_proxy and playlist proxy are enabled', function () {
    $this->setting->update(['use_proxy' => false]);

    $this->setting->playlist->update([
        'proxy_options' => ['enabled' => true],
    ]);

    $epgChannel = EpgChannel::factory()->create(['channel_id' => 'proxy.test.channel']);
    Channel::factory()
        ->for($this->setting->playlist)
        ->create([
            'epg_channel_id' => $epgChannel->id,
            'url' => 'http://example.com/direct.m3u8',
        ]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Proxy Test', 'channel_id' => null]);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Proxy Test',
        'epg_channel_id' => 'proxy.test.channel',
    ]);

    $this->service->tick();

    $recording = DvrRecording::where('dvr_recording_rule_id', $rule->id)->firstOrFail();

    expect($recording->stream_url)->toBe('http://example.com/direct.m3u8');
});

// --- Phase 2: series_mode unique_se ---

it('unique_se mode skips a programme when the same (season, episode) was already recorded', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'My Show',
            'series_mode' => DvrSeriesMode::UniqueSe,
        ]);

    // Pre-existing completed recording for S01E05
    DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'My Show',
            'season' => 1,
            'episode' => 5,
            'series_key' => "setting:{$this->setting->id}|title:my show",
        ]);

    // New programme for the same S01E05 (re-run at a different time)
    $rerun = EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'My Show',
        'epg_channel_id' => 'test.channel',
        'season' => 1,
        'episode' => 5,
    ]);

    $this->service->tick();

    // Should NOT create a new recording for the re-run
    $newRecordings = DvrRecording::where('dvr_recording_rule_id', $rule->id)
        ->where('programme_start', $rerun->start_time)
        ->count();

    expect($newRecordings)->toBe(0);
});

it('unique_se mode still records a different episode (different season/episode number)', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'My Show',
            'series_mode' => DvrSeriesMode::UniqueSe,
        ]);

    // Pre-existing S01E05
    DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'My Show',
            'season' => 1,
            'episode' => 5,
            'series_key' => "setting:{$this->setting->id}|title:my show",
        ]);

    // New programme for S01E06
    $newEp = EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'My Show',
        'epg_channel_id' => 'test.channel',
        'season' => 1,
        'episode' => 6,
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(2);
});

it('all mode records every programme regardless of prior S/E recordings', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'My Show',
            'series_mode' => DvrSeriesMode::All,
        ]);

    DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'My Show',
            'season' => 1,
            'episode' => 5,
            'series_key' => "setting:{$this->setting->id}|title:my show",
        ]);

    $rerun = EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'My Show',
        'epg_channel_id' => 'test.channel',
        'season' => 1,
        'episode' => 5,
    ]);

    $this->service->tick();

    // All mode ignores S/E dedup — re-run is recorded
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)
        ->where('programme_start', $rerun->start_time)
        ->exists())->toBeTrue();
});

// --- Phase 1: series_key dedup across rules ---

it('does not create a duplicate recording when two rules match the same programme', function () {
    $ruleA = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Breaking Bad', 'channel_id' => $this->channel->id]);

    $ruleB = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Breaking Bad', 'channel_id' => $this->channel->id]);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::count())->toBe(1);
    expect(DvrRecording::first()->dvr_recording_rule_id)->toBe($ruleA->id);
});

it('dedup is scoped per DVR setting — a recording in setting A does not block in setting B', function () {
    $settingB = DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => $this->setting->playlist_id,
    ]);

    $ruleA = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'The Office', 'channel_id' => null]);

    $ruleB = DvrRecordingRule::factory()
        ->series()
        ->for($settingB, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'The Office', 'channel_id' => null]);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'The Office',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::count())->toBe(2);
});

it('recordings store the correct series_key and normalized_title', function () {
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

    $recording = DvrRecording::firstOrFail();

    expect($recording->series_key)->toBe("setting:{$this->setting->id}|title:breaking bad")
        ->and($recording->normalized_title)->toBe('breaking bad');
});

it('manual rules derive series_key from the channel title when available', function () {
    $channel = Channel::factory()
        ->for($this->setting->playlist)
        ->create(['title_custom' => 'My Channel']);

    $rule = DvrRecordingRule::factory()
        ->manual()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'channel_id' => $channel->id,
            'manual_start' => now()->addMinutes(5),
            'manual_end' => now()->addMinutes(65),
        ]);

    $this->service->tick();

    $recording = DvrRecording::firstOrFail();

    expect($recording->series_key)
        ->toBe("setting:{$this->setting->id}|title:my channel")
        ->and($recording->title)->toBe('My Channel');
});

// --- Phase 3: user_cancelled blocks re-scheduling, retry cap on Failed ---

it('does not re-schedule a programme that was user-cancelled', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Cancelled Show']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Cancelled Show',
        'epg_channel_id' => 'test.channel',
    ]);

    // Pre-existing user-cancelled recording for this programme
    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Cancelled Show',
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'test.channel'],
            'status' => DvrRecordingStatus::Cancelled,
            'user_cancelled' => true,
            'scheduled_start' => $programme->start_time->copy()->subMinutes(5),
            'scheduled_end' => $programme->end_time->copy()->addMinutes(5),
        ]);

    $this->service->tick();

    // User cancelled — scheduler should not create a new recording
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('retries a failed recording within the airing window when attempt_count is below max', function () {
    config(['dvr.max_attempts_per_airing' => 3]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Retry Show']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Retry Show',
        'epg_channel_id' => 'test.channel',
    ]);

    // Pre-existing failed recording (attempt 1)
    $failedRecording = DvrRecording::factory()
        ->failed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Retry Show',
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'test.channel'],
            'scheduled_start' => $programme->start_time->copy()->subMinutes(5),
            'scheduled_end' => $programme->end_time->copy()->addMinutes(5),
            'user_cancelled' => false,
            'attempt_count' => 1,
        ]);

    Queue::fake();

    $this->service->tick();

    $failedRecording->refresh();

    // The existing Failed row should be resurrected to Scheduled (not a new row created)
    expect($failedRecording->status)->toBe(DvrRecordingStatus::Scheduled)
        ->and($failedRecording->error_message)->toBeNull()
        ->and($failedRecording->attempt_count)->toBe(1);

    // No new row should have been created
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
});

it('does not retry a failed recording when attempt_count has reached max', function () {
    config(['dvr.max_attempts_per_airing' => 3]);

    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Exhausted Show']);

    $programme = EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Exhausted Show',
        'epg_channel_id' => 'test.channel',
    ]);

    // Failed at max attempts (3 of 3)
    DvrRecording::factory()
        ->failed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Exhausted Show',
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'test.channel'],
            'scheduled_start' => $programme->start_time->copy()->subMinutes(5),
            'scheduled_end' => $programme->end_time->copy()->addMinutes(5),
            'user_cancelled' => false,
            'attempt_count' => 3,
        ]);

    $this->service->tick();

    // Should not retry — no new row, existing row still Failed
    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1)
        ->and(DvrRecording::first()->status)->toBe(DvrRecordingStatus::Failed);
});

it('attempt_count starts at 1 for newly created recordings', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Fresh Show']);

    EpgProgramme::factory()->upcoming(10)->create([
        'title' => 'Fresh Show',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    $recording = DvrRecording::firstOrFail();

    expect($recording->attempt_count)->toBe(1);
});

// --- Match modes ---

it('match_mode exact only records exact title match', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'The Office',
            'match_mode' => DvrMatchMode::Exact,
        ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'The Office',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'The Office Tour',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Welcome to The Office',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'the office',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(6),
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(2);
});

it('match_mode starts_with records titles beginning with the pattern', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'The Office',
            'match_mode' => DvrMatchMode::StartsWith,
        ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'The Office',
        'epg_channel_id' => 'test.channel',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'The Office Tour',
        'epg_channel_id' => 'test.channel',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Welcome to The Office',
        'epg_channel_id' => 'test.channel',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Behind the Office',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(2);
});

it('match_mode contains records titles containing the pattern', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'Office',
            'match_mode' => DvrMatchMode::Contains,
        ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'The Office',
        'epg_channel_id' => 'test.channel',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Office Space',
        'epg_channel_id' => 'test.channel',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Post Office',
        'epg_channel_id' => 'test.channel',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(3);
});

it('match_mode tmdb records programmes by tmdb_id', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'Breaking Bad',
            'match_mode' => DvrMatchMode::Tmdb,
            'tmdb_id' => '12345',
        ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
        'tmdb_id' => '12345',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
        'tmdb_id' => '67890',
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Better Call Saul',
        'epg_channel_id' => 'test.channel',
        'tmdb_id' => '12345',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(2);
});

it('match_mode tmdb with no tmdb_id on rule matches nothing', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'series_title' => 'Breaking Bad',
            'match_mode' => DvrMatchMode::Tmdb,
            'tmdb_id' => null,
        ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Breaking Bad',
        'epg_channel_id' => 'test.channel',
        'tmdb_id' => '12345',
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(0);
});

// --- Priority ---

it('higher priority rules are processed first during scheduling', function () {
    $lowPriority = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show A', 'priority' => 10]);

    $highPriority = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show B', 'priority' => 100]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Show A',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
    ]);
    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Show B',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $highPriority->id)->count())->toBe(1);
    expect(DvrRecording::where('dvr_recording_rule_id', $lowPriority->id)->count())->toBe(1);
});

it('triggerPendingRecordings dispatches higher priority recordings before lower priority when both are due', function () {
    $lowPriority = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show A', 'priority' => 10]);

    $highPriority = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show B', 'priority' => 100]);

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($lowPriority, 'recordingRule')
        ->create([
            'title' => 'Show A',
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinutes(1),
            'scheduled_end' => now()->addHour(),
        ]);

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($highPriority, 'recordingRule')
        ->create([
            'title' => 'Show B',
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinutes(1),
            'scheduled_end' => now()->addHour(),
        ]);

    Queue::fake();

    $this->service->tick();

    $highId = DvrRecording::where('dvr_recording_rule_id', $highPriority->id)->first()->id;

    Queue::assertPushed(StartDvrRecording::class, fn ($job) => $job->recordingId === $highId);
});

it('triggerPendingRecordings dispatches at most max_concurrent_recordings per setting', function () {
    $this->setting->update(['max_concurrent_recordings' => 1]);

    $ruleA = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show A', 'priority' => 10]);

    $ruleB = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Show B', 'priority' => 100]);

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($ruleA, 'recordingRule')
        ->create([
            'title' => 'Show A',
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinutes(1),
            'scheduled_end' => now()->addHour(),
        ]);

    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($ruleB, 'recordingRule')
        ->create([
            'title' => 'Show B',
            'status' => DvrRecordingStatus::Scheduled,
            'scheduled_start' => now()->subMinutes(1),
            'scheduled_end' => now()->addHour(),
        ]);

    Queue::fake();

    $this->service->tick();

    Queue::assertPushed(StartDvrRecording::class, 1);
});

// --- Stable EPG dedup ---

it('does not create a duplicate recording when the same programme drifts to a new start_time', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Late Night Show']);

    $originalProgramme = EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Late Night Show',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
        'end_time' => now()->addMinutes(65),
        'season' => 5,
        'episode' => 12,
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);

    $originalRecording = DvrRecording::first();

    $driftedProgramme = EpgProgramme::factory()->create([
        'title' => 'Late Night Show',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(10),
        'end_time' => now()->addMinutes(70),
        'season' => 5,
        'episode' => 12,
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(1);
    expect(DvrRecording::first()->id)->toBe($originalRecording->id);
});

it('dedup by programme_uid is case-sensitive for title', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Late Night Show']);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Late Night Show',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(5),
        'season' => 5,
        'episode' => 13,
    ]);

    EpgProgramme::factory()->upcoming(5)->create([
        'title' => 'Late Night show',
        'epg_channel_id' => 'test.channel',
        'start_time' => now()->addMinutes(10),
        'season' => 5,
        'episode' => 13,
    ]);

    $this->service->tick();

    expect(DvrRecording::where('dvr_recording_rule_id', $rule->id)->count())->toBe(2);
});
