<?php

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use App\Models\DvrRecording;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

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
