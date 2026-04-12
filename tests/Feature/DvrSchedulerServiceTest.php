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
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
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

    $this->service = app(DvrSchedulerService::class);
});

// --- Series rules ---

it('creates a scheduled recording for a matching series programme', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['series_title' => 'Breaking Bad']);

    EpgProgramme::factory()->upcoming(10)->create(['title' => 'Breaking Bad']);

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
        'epg_channel_id' => 'channel.001',
    ]);

    // Pre-create the recording to simulate a previous tick
    DvrRecording::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'dvr_recording_rule_id' => $rule->id,
            'programme_start' => $programme->start_time,
            'epg_programme_data' => ['epg_channel_id' => 'channel.001'],
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

    EpgProgramme::factory()->upcoming(10)->isNew()->create(['title' => 'Some Show']);

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

it('skips a manual rule that is outside the lookahead window', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'manual_start' => now()->addHours(2),
            'manual_end' => now()->addHours(3),
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

    EpgProgramme::factory()->upcoming(10)->create(['title' => 'Capacity Show']);

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

    EpgProgramme::factory()->upcoming(10)->create(['title' => 'Hidden Show']);

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
