<?php

/**
 * DvrRecording's deleting hook cleans up the recording file on disk, but that
 * hook only fires on an Eloquent-level delete — a raw DB foreign-key cascade
 * (e.g. deleting the owning Playlist/CustomPlaylist/MergedPlaylist and letting
 * dvr_settings -> dvr_recordings cascade at the database) bypasses Eloquent
 * events entirely and would leave the file orphaned on disk.
 *
 * Playlist::deleting / MergedPlaylist::deleting / CustomPlaylist::deleting
 * (AppServiceProvider) must delete owned recordings through Eloquent first so
 * the file-cleanup hook actually runs.
 */

use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('dvr');
    $this->user = User::factory()->create();
});

it('deletes the recording file from disk when the owning playlist is deleted', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $setting = DvrSetting::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'storage_disk' => 'dvr',
    ]);

    $recording = DvrRecording::factory()->completed()->create([
        'dvr_setting_id' => $setting->id,
        'user_id' => $this->user->id,
    ]);

    Storage::disk('dvr')->put($recording->file_path, 'fake video bytes');
    expect(Storage::disk('dvr')->exists($recording->file_path))->toBeTrue();

    $playlist->delete();

    expect(Storage::disk('dvr')->exists($recording->file_path))->toBeFalse()
        ->and(DvrRecording::find($recording->id))->toBeNull()
        ->and(DvrSetting::find($setting->id))->toBeNull();
});

it('deletes the recording file from disk when the owning merged playlist is deleted', function () {
    $merged = MergedPlaylist::factory()->for($this->user)->create();

    $setting = DvrSetting::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'storage_disk' => 'dvr',
    ]);

    $recording = DvrRecording::factory()->completed()->create([
        'dvr_setting_id' => $setting->id,
        'user_id' => $this->user->id,
    ]);

    Storage::disk('dvr')->put($recording->file_path, 'fake video bytes');

    $merged->delete();

    expect(Storage::disk('dvr')->exists($recording->file_path))->toBeFalse()
        ->and(DvrRecording::find($recording->id))->toBeNull();
});

it('deletes the recording file from disk when the owning custom playlist is deleted', function () {
    $custom = CustomPlaylist::factory()->for($this->user)->create();

    $setting = DvrSetting::create([
        'custom_playlist_id' => $custom->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'storage_disk' => 'dvr',
    ]);

    $recording = DvrRecording::factory()->completed()->create([
        'dvr_setting_id' => $setting->id,
        'user_id' => $this->user->id,
    ]);

    Storage::disk('dvr')->put($recording->file_path, 'fake video bytes');

    $custom->delete();

    expect(Storage::disk('dvr')->exists($recording->file_path))->toBeFalse()
        ->and(DvrRecording::find($recording->id))->toBeNull();
});
