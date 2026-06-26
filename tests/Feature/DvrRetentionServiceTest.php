<?php

/**
 * Tests for DvrRetentionService
 *
 * Covers:
 * - keepLast: hard-deletes excess completed recordings beyond the keep_last limit
 * - keepLast: keeps the most recent recordings (not the oldest)
 * - retention_days: hard-deletes recordings older than the configured days
 * - retention_days: retains recordings within the cutoff
 * - disk_quota: deletes oldest recordings when quota is exceeded
 * - disk_quota: no deletions when under quota
 * - Ghost rows (Completed + null file_path) are purged at the start of each run
 * - Disabled settings are skipped
 */

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\User;
use App\Services\DvrRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('dvr');

    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'storage_disk' => 'dvr',
        'retention_days' => 0,
        'global_disk_quota_gb' => 0,
    ]);

    $this->service = app(DvrRetentionService::class);
});

// --- keepLast ---

it('deletes excess completed recordings beyond keep_last', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['keep_last' => 2, 'series_title' => 'Test Show']);

    // Create 4 completed recordings ordered newest → oldest (subHours(1) … subHours(4)).
    // Title must match rule's series_title so afterCreating derives the same series_key
    // for all recordings, correctly grouping them under keep_last enforcement.
    // Real EPG programmes use title="Show Name" without episode suffixes.
    $recordings = collect(range(1, 4))->map(fn ($i) => DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Test Show',
            'dvr_recording_rule_id' => $rule->id,
            'scheduled_start' => now()->subHours($i),
            'actual_end' => now()->subHours($i),
            'file_path' => "recordings/ep{$i}.ts",
            'file_size_bytes' => 100_000_000,
        ])
    );

    // Place files on the fake disk so the service can delete them
    foreach ($recordings as $recording) {
        Storage::disk('dvr')->put($recording->file_path, 'fake-content');
    }

    $this->service->runAll();

    // 2 oldest rows are hard-deleted; 2 newest survive
    expect(
        DvrRecording::where('dvr_recording_rule_id', $rule->id)
            ->where('status', DvrRecordingStatus::Completed)
            ->count()
    )->toBe(2);

    // 2 newest (subHours(1), subHours(2)) still exist with their files intact
    expect(DvrRecording::find($recordings->get(0)->id))->not->toBeNull();
    expect(DvrRecording::find($recordings->get(1)->id))->not->toBeNull();

    // 2 oldest (subHours(3), subHours(4)) are hard-deleted
    expect(DvrRecording::find($recordings->get(2)->id))->toBeNull();
    expect(DvrRecording::find($recordings->get(3)->id))->toBeNull();
    expect(Storage::disk('dvr')->exists('recordings/ep3.ts'))->toBeFalse();
    expect(Storage::disk('dvr')->exists('recordings/ep4.ts'))->toBeFalse();
});

it('keeps only the most recent N recordings for a rule with keep_last', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['keep_last' => 2, 'series_title' => 'Test Show']);

    $recordings = collect(range(1, 4))->map(fn ($i) => DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Test Show',
            'dvr_recording_rule_id' => $rule->id,
            'scheduled_start' => now()->subHours($i),
            'actual_end' => now()->subHours($i),
            'file_path' => "recordings/ep{$i}.ts",
            'file_size_bytes' => 100_000_000,
        ])
    );

    foreach ($recordings as $recording) {
        Storage::disk('dvr')->put($recording->file_path, 'fake-content');
    }

    $this->service->runAll();

    // 2 newest survive, 2 oldest are hard-deleted
    expect(DvrRecording::find($recordings->get(0)->id))->not->toBeNull(); // newest
    expect(DvrRecording::find($recordings->get(1)->id))->not->toBeNull(); // 2nd newest
    expect(DvrRecording::find($recordings->get(2)->id))->toBeNull();      // 3rd — deleted
    expect(DvrRecording::find($recordings->get(3)->id))->toBeNull();      // oldest — deleted
});

// --- Retention days ---

it('deletes completed recordings older than retention_days', function () {
    $this->setting->update(['retention_days' => 7]);

    $old = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subDays(10),
            'file_path' => 'recordings/old.ts',
        ]);

    Storage::disk('dvr')->put($old->file_path, 'data');

    $this->service->runAll();

    expect(DvrRecording::find($old->id))->toBeNull();
    expect(Storage::disk('dvr')->exists('recordings/old.ts'))->toBeFalse();
});

it('retains completed recordings within the retention_days cutoff', function () {
    $this->setting->update(['retention_days' => 7]);

    $recent = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subDays(3),
            'file_path' => 'recordings/recent.ts',
        ]);

    Storage::disk('dvr')->put($recent->file_path, 'data');

    $this->service->runAll();

    $recent->refresh();
    expect($recent->file_path)->not->toBeNull();
    expect(Storage::disk('dvr')->exists('recordings/recent.ts'))->toBeTrue();
});

// --- Disk quota ---

it('deletes oldest recordings when disk quota is exceeded', function () {
    $this->setting->update(['global_disk_quota_gb' => 1]); // 1 GB = ~1,073,741,824 bytes

    $old = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subHours(2),
            'file_path' => 'recordings/old.ts',
            'file_size_bytes' => 800_000_000,
        ]);

    $newer = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subHour(),
            'file_path' => 'recordings/newer.ts',
            'file_size_bytes' => 800_000_000,
        ]);

    Storage::disk('dvr')->put($old->file_path, 'data');
    Storage::disk('dvr')->put($newer->file_path, 'data');

    $this->service->runAll();

    // Total = 1.6 GB > 1 GB quota. The oldest should be evicted first and hard-deleted.
    expect(DvrRecording::find($old->id))->toBeNull();
    expect(Storage::disk('dvr')->exists('recordings/old.ts'))->toBeFalse();
});

it('does not delete any recordings when under the disk quota', function () {
    $this->setting->update(['global_disk_quota_gb' => 10]);

    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subHour(),
            'file_path' => 'recordings/small.ts',
            'file_size_bytes' => 100_000_000, // 100 MB << 10 GB
        ]);

    Storage::disk('dvr')->put($recording->file_path, 'data');

    $this->service->runAll();

    $recording->refresh();
    expect($recording->file_path)->not->toBeNull();
});

// --- Ghost row purge ---

it('hard-deletes ghost rows (completed with null file_path) before enforcing other policies', function () {
    $ghost = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'file_path' => null,
            'actual_end' => now()->subDays(1),
        ]);

    $this->service->runAll();

    expect(DvrRecording::find($ghost->id))->toBeNull();
});

it('does not count ghost rows toward keep_last so real recordings are not over-pruned', function () {
    $rule = DvrRecordingRule::factory()
        ->series()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create(['keep_last' => 2, 'series_title' => 'Test Show']);

    // 2 real recordings (have files)
    $real = collect(range(1, 2))->map(fn ($i) => DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Test Show',
            'scheduled_start' => now()->subHours($i),
            'file_path' => "recordings/real{$i}.ts",
            'file_size_bytes' => 100_000_000,
        ])
    );

    foreach ($real as $recording) {
        Storage::disk('dvr')->put($recording->file_path, 'fake-content');
    }

    // 3 ghost rows from previous retention runs (no file)
    collect(range(1, 3))->each(fn ($i) => DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->for($rule, 'recordingRule')
        ->create([
            'title' => 'Test Show',
            'scheduled_start' => now()->subDays($i),
            'file_path' => null,
        ])
    );

    $this->service->runAll();

    // Both real recordings survive — ghost rows were purged first and don't inflate the count
    expect(DvrRecording::find($real->get(0)->id))->not->toBeNull();
    expect(DvrRecording::find($real->get(1)->id))->not->toBeNull();
    expect(Storage::disk('dvr')->exists('recordings/real1.ts'))->toBeTrue();
    expect(Storage::disk('dvr')->exists('recordings/real2.ts'))->toBeTrue();
});

// --- Disabled settings skipped ---

it('skips retention for disabled dvr settings', function () {
    $this->setting->update(['enabled' => false, 'retention_days' => 1]);

    $old = DvrRecording::factory()
        ->completed()
        ->for($this->setting, 'dvrSetting')
        ->for($this->user)
        ->create([
            'actual_end' => now()->subDays(5),
            'file_path' => 'recordings/shouldstay.ts',
        ]);

    Storage::disk('dvr')->put($old->file_path, 'data');

    $this->service->runAll();

    $old->refresh();
    expect($old->file_path)->not->toBeNull();
});
