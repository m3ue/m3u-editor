<?php

/**
 * Tests for the DvrRecording deleting cascade additions:
 *  - Once / Manual rules: removed when their recording is deleted.
 *  - Series rules: removed only when the deleted recording is the LAST sibling.
 *  - Empty parent directories under library/ are pruned after the file is gone.
 */

declare(strict_types=1);

use App\Enums\DvrRuleType;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Bus::fake();
    Storage::fake('dvr');
});

it('cascades a Once rule when its recording is deleted', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);
    $rule = DvrRecordingRule::factory()->create([
        'dvr_setting_id' => $setting->id,
        'type' => DvrRuleType::Once,
    ]);

    $recording = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'dvr_recording_rule_id' => $rule->id,
    ]);

    $recording->delete();

    expect(DvrRecordingRule::find($rule->id))->toBeNull();
});

it('cascades a Manual rule when its recording is deleted', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);
    $rule = DvrRecordingRule::factory()->manual()->create([
        'dvr_setting_id' => $setting->id,
    ]);

    $recording = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'dvr_recording_rule_id' => $rule->id,
    ]);

    $recording->delete();

    expect(DvrRecordingRule::find($rule->id))->toBeNull();
});

it('keeps a Series rule alive while sibling recordings still exist', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);
    $rule = DvrRecordingRule::factory()->series()->create([
        'dvr_setting_id' => $setting->id,
    ]);

    $first = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'dvr_recording_rule_id' => $rule->id,
    ]);
    $second = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'dvr_recording_rule_id' => $rule->id,
    ]);

    $first->delete();

    expect(DvrRecordingRule::find($rule->id))->not->toBeNull()
        ->and(DvrRecording::find($second->id))->not->toBeNull();
});

it('removes a Series rule when its last sibling recording is deleted', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);
    $rule = DvrRecordingRule::factory()->series()->create([
        'dvr_setting_id' => $setting->id,
    ]);

    $only = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'dvr_recording_rule_id' => $rule->id,
    ]);

    $only->delete();

    expect(DvrRecordingRule::find($rule->id))->toBeNull();
});

it('prunes empty parent directories up to the library root', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);

    $relative = 'library/2026/Some Show/Some Show - S01E01.mp4';
    Storage::disk('dvr')->put($relative, 'x');

    $recording = DvrRecording::factory()->completed()->create([
        'dvr_setting_id' => $setting->id,
        'file_path' => $relative,
    ]);

    $recording->delete();

    Storage::disk('dvr')
        ->assertMissing($relative)
        ->assertMissing('library/2026/Some Show')
        ->assertMissing('library/2026');

    // The library root itself must remain (stop anchor).
    expect(Storage::disk('dvr')->exists('library'))->toBeTrue();
});

it('does not prune a parent directory that still contains other recordings', function (): void {
    $setting = DvrSetting::factory()->create(['storage_disk' => 'dvr']);

    $deleted = 'library/2026/Some Show/Some Show - S01E01.mp4';
    $kept = 'library/2026/Some Show/Some Show - S01E02.mp4';

    Storage::disk('dvr')->put($deleted, 'x');
    Storage::disk('dvr')->put($kept, 'y');

    $recording = DvrRecording::factory()->completed()->create([
        'dvr_setting_id' => $setting->id,
        'file_path' => $deleted,
    ]);

    $recording->delete();

    Storage::disk('dvr')
        ->assertMissing($deleted)
        ->assertExists($kept)
        ->assertExists('library/2026/Some Show');
});
