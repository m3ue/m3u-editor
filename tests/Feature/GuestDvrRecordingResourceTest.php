<?php

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Filament\GuestPanel\Resources\DvrRecordings\GuestDvrRecordingResource;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Set up request attributes and session so HasGuestDvr resolves the correct context.
 * Mirrors the helper in GuestBrowseShowsTest.
 */
function setGuestDvrRecordingContext(Playlist $playlist, PlaylistAuth $auth): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $auth->username);
    session()->put("{$prefix}guest_auth_password", $auth->password);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->dvrSetting = DvrSetting::factory()->enabled()->for($this->playlist)->for($this->user)->create();
    $this->guestA = PlaylistAuth::factory()
        ->for($this->user)
        ->create(['enabled' => true, 'dvr_enabled' => true]);
    $this->guestB = PlaylistAuth::factory()
        ->for($this->user)
        ->create(['enabled' => true, 'dvr_enabled' => true]);
    setGuestDvrRecordingContext($this->playlist, $this->guestA);
});

// --- canAccess / canCreate / canEdit / canDelete ---

it('grants access when dvr_enabled is true and DvrSetting exists', function () {
    expect(GuestDvrRecordingResource::canAccess())->toBeTrue();
});

it('denies access when dvr_enabled is false', function () {
    $this->guestA->update(['dvr_enabled' => false]);

    expect(GuestDvrRecordingResource::canAccess())->toBeFalse();
});

it('always returns false for canCreate', function () {
    expect(GuestDvrRecordingResource::canCreate())->toBeFalse();
});

it('always returns false for canEdit', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    expect(GuestDvrRecordingResource::canEdit($recording))->toBeFalse();
});

it('always returns false for canDelete', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    expect(GuestDvrRecordingResource::canDelete($recording))->toBeFalse();
});

// --- Eloquent query scope ---

it('scopes recordings to the current playlist DvrSetting', function () {
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherSetting = DvrSetting::factory()->enabled()->for($otherPlaylist)->for($otherUser)->create();

    DvrRecording::factory()->for($this->dvrSetting)->for($this->user)->create();
    DvrRecording::factory()->for($otherSetting)->for($otherUser)->create();

    $ids = GuestDvrRecordingResource::getEloquentQuery()->pluck('dvr_setting_id')->unique()->all();

    expect($ids)->toBe([$this->dvrSetting->id]);
});

it('returns no recordings when no DvrSetting exists', function () {
    $this->dvrSetting->delete();
    // Reset context so getDvrSetting() returns null
    setGuestDvrRecordingContext($this->playlist, $this->guestA);

    $count = GuestDvrRecordingResource::getEloquentQuery()->count();

    expect($count)->toBe(0);
});

// --- Cancel action authorization guard ---

it('cancel guard authorizes the owning guest', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'playlist_auth_id' => $this->guestA->id,
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    $isOwner = $currentAuth && $recording->playlist_auth_id === $currentAuth->id;
    $isCancellable = in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]);

    expect($isOwner)->toBeTrue()
        ->and($isCancellable)->toBeTrue();
});

it('cancel guard rejects a recording owned by a different guest', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'playlist_auth_id' => $this->guestB->id, // owned by guest B
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    // Context is guest A
    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    $isOwner = $currentAuth && $recording->playlist_auth_id === $currentAuth->id;

    expect($isOwner)->toBeFalse();
});

it('cancel guard rejects a recording owned by the playlist owner (null playlist_auth_id)', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'playlist_auth_id' => null, // owner-created recording
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    $isOwner = $currentAuth && $recording->playlist_auth_id === $currentAuth->id;

    expect($isOwner)->toBeFalse();
});

it('cancel guard rejects completed recordings', function () {
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $isCancellable = in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]);

    expect($isCancellable)->toBeFalse();
});

it('cancel guard rejects failed recordings', function () {
    $recording = DvrRecording::factory()
        ->failed()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $isCancellable = in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]);

    expect($isCancellable)->toBeFalse();
});

it('cancel guard accepts recordings in Recording status', function () {
    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    $isOwner = $currentAuth && $recording->playlist_auth_id === $currentAuth->id;
    $isCancellable = in_array($recording->status, [DvrRecordingStatus::Scheduled, DvrRecordingStatus::Recording]);

    expect($isOwner)->toBeTrue()
        ->and($isCancellable)->toBeTrue();
});
