<?php

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->playlist = Playlist::factory()->for($this->admin)->create();
});

// --- Model gate: hasFilePath() ---

it('hasFilePath returns true for completed recordings with file_path', function () {
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->create(['file_path' => 'recordings/test.ts']);

    expect($recording->hasFilePath())->toBeTrue();
});

it('hasFilePath returns false for completed recordings without file_path', function () {
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->create(['file_path' => null]);

    expect($recording->hasFilePath())->toBeFalse();
});

it('hasFilePath returns false for non-completed recordings', function () {
    $recording = DvrRecording::factory()
        ->for($this->admin)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'file_path' => 'recordings/test.ts',
        ]);

    expect($recording->hasFilePath())->toBeFalse();
});

it('hasFilePath returns false for failed recordings', function () {
    $recording = DvrRecording::factory()
        ->failed()
        ->for($this->admin)
        ->create(['file_path' => 'recordings/test.ts']);

    expect($recording->hasFilePath())->toBeFalse();
});

// --- Resource access ---

it('admin can access DvrRecordingResource', function () {
    expect(DvrRecordingResource::canAccess())->toBeTrue();
});

// --- Factory states ---

it('completed factory state produces a completed recording', function () {
    $recording = DvrRecording::factory()->completed()->for($this->admin)->create();

    expect($recording->status)->toBe(DvrRecordingStatus::Completed)
        ->and($recording->file_path)->not->toBeEmpty()
        ->and($recording->hasFilePath())->toBeTrue();
});

// --- downloadResponse() ---

it('downloadResponse returns null when the file is missing from disk', function () {
    Storage::fake('dvr');
    $setting = DvrSetting::factory()->for($this->admin)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/missing.ts']);

    expect($recording->downloadResponse())->toBeNull();
});

it('downloadResponse streams the file when it exists on disk', function () {
    Storage::fake('dvr');
    Storage::disk('dvr')->put('recordings/test.mp4', 'video-bytes');

    $setting = DvrSetting::factory()->for($this->admin)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/test.mp4']);

    $response = $recording->downloadResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('video/mp4');

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toBe('video-bytes');
});

// --- dvr-recordings.download route ---
// The download action links here instead of returning a response from a
// Filament Action closure, because Livewire buffers any StreamedResponse
// returned from an action into memory and ships it as one base64 JSON
// payload (Livewire\Features\SupportFileDownloads) - fine for small
// exports, but it means the whole recording file sits in PHP memory and
// then browser JS memory before being saved. This route is a plain HTTP
// GET, outside the Livewire request cycle, so the response streams
// straight through.

it('download route streams the file for the owning user', function () {
    Storage::fake('dvr');
    Storage::disk('dvr')->put('recordings/test.mp4', 'video-bytes');

    $setting = DvrSetting::factory()->for($this->admin)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/test.mp4']);

    $response = $this->get(route('dvr-recordings.download', $recording));

    $response->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertStreamedContent('video-bytes');
});

it('download route returns 404 when the file is missing from disk', function () {
    Storage::fake('dvr');
    $setting = DvrSetting::factory()->for($this->admin)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/missing.ts']);

    $this->get(route('dvr-recordings.download', $recording))->assertNotFound();
});

it('download route returns 404 for a recording without a completed file', function () {
    $recording = DvrRecording::factory()
        ->for($this->admin)
        ->create(['status' => DvrRecordingStatus::Scheduled, 'file_path' => null]);

    $this->get(route('dvr-recordings.download', $recording))->assertNotFound();
});

it('download route forbids downloading another user\'s recording', function () {
    Storage::fake('dvr');
    Storage::disk('dvr')->put('recordings/other.mp4', 'video-bytes');

    $otherUser = User::factory()->admin()->create();
    $setting = DvrSetting::factory()->for($otherUser)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($otherUser)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/other.mp4']);

    $this->get(route('dvr-recordings.download', $recording))->assertForbidden();
});

it('download route requires authentication', function () {
    Storage::fake('dvr');
    Storage::disk('dvr')->put('recordings/test.mp4', 'video-bytes');

    $setting = DvrSetting::factory()->for($this->admin)->create(['storage_disk' => 'dvr']);
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->admin)
        ->for($setting, 'dvrSetting')
        ->create(['file_path' => 'recordings/test.mp4']);

    // Simulate a request with no authenticated user at all, rather than
    // relying on the beforeEach actingAs() call.
    auth()->logout();
    $this->app['session']->flush();

    $this->get(route('dvr-recordings.download', $recording))->assertRedirect();
});
