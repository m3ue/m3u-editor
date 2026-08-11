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

/**
 * Set up the "owner_auth" fallback session: username = the playlist owner's
 * m3u-editor User::$name, password = the playlist UUID. There is no
 * PlaylistAuth record for this login (PlaylistService::authenticate() Method 2).
 */
function setOwnerAuthRecordingContext(Playlist $playlist, User $user): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $user->name);
    session()->put("{$prefix}guest_auth_password", $playlist->uuid);
}

beforeEach(function () {
    Queue::fake();
    config()->set('dvr.dvr_enabled', true);
    config()->set('proxy.proxy_integration_enabled', true);
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

it('denies access when DVR_ENABLED config is false', function () {
    config()->set('dvr.dvr_enabled', false);

    expect(GuestDvrRecordingResource::canAccess())->toBeFalse();
});

it('denies access when proxy integration config is false', function () {
    config()->set('proxy.proxy_integration_enabled', false);

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

    DvrRecording::factory()->for($this->dvrSetting)->for($this->user)->create(['playlist_auth_id' => $this->guestA->id]);
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

it('excludes recordings owned by a different guest from the list query', function () {
    $ownRecording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id]);

    $ids = GuestDvrRecordingResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownRecording->id]);
});

it('excludes owner-created recordings (null playlist_auth_id) from the list query', function () {
    $ownRecording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    $ids = GuestDvrRecordingResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownRecording->id]);
});

it('returns no recordings when the guest session credentials do not resolve to a PlaylistAuth or the owner', function () {
    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    request()->attributes->set('playlist_uuid', $this->playlist->uuid);
    $prefix = base64_encode($this->playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", 'stale-username');
    session()->put("{$prefix}guest_auth_password", 'stale-password');

    $ids = GuestDvrRecordingResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([]);
});

// --- Navigation badge count ---

it('navigation badge only counts the current guest\'s own active recordings', function () {
    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id, 'status' => DvrRecordingStatus::Scheduled]);

    // Another guest's active recording, and an owner-created one — neither
    // should count toward guest A's badge.
    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id, 'status' => DvrRecordingStatus::Recording]);
    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null, 'status' => DvrRecordingStatus::Scheduled]);

    expect(GuestDvrRecordingResource::getNavigationBadge())->toBe('1');
});

it('navigation badge is null when the guest has no active recordings but others on the playlist do', function () {
    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id, 'status' => DvrRecordingStatus::Scheduled]);

    expect(GuestDvrRecordingResource::getNavigationBadge())->toBeNull();
});

// --- Cancel action authorization guard ---

/*
 * These assert GuestDvrRecordingResource::guestCanCancel() directly — the
 * single predicate both ->visible() and the in-action backend guard call
 * (mirrors guestCanPlay() below, #1397 follow-up). Asserting the resource's
 * actual method, rather than reimplementing its logic inline in the test,
 * is what makes these fail if the guard itself is ever weakened.
 */

it('cancel guard authorizes the owning guest', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'playlist_auth_id' => $this->guestA->id,
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeTrue();
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

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeFalse();
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

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeFalse();
});

it('cancel guard rejects when there is no authenticated guest', function () {
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'playlist_auth_id' => $this->guestA->id,
            'status' => DvrRecordingStatus::Scheduled,
        ]);

    expect(GuestDvrRecordingResource::guestCanCancel($recording, null))->toBeFalse();
});

it('cancel guard rejects completed recordings', function () {
    $recording = DvrRecording::factory()
        ->completed()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeFalse();
});

it('cancel guard rejects failed recordings', function () {
    $recording = DvrRecording::factory()
        ->failed()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeFalse();
});

it('cancel guard accepts recordings in Recording status', function () {
    $recording = DvrRecording::factory()
        ->recording()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $currentAuth = GuestDvrRecordingResource::getCurrentPlaylistAuth();

    expect(GuestDvrRecordingResource::guestCanCancel($recording, $currentAuth))->toBeTrue();
});

// --- Play action visibility (visible() closure predicates) ---

/*
 * Play authorization (#1366).
 *
 * These assert GuestDvrRecordingResource::guestCanPlay() directly, which is the
 * single predicate both ->visible() and the in-action backend guard call. The
 * guest panel resolves its auth from request attributes and Livewire::test()
 * cannot carry those across synthetic requests (see the note on
 * setupGuestReleaseDateContext), so the action closures are not reachable from a
 * test — asserting the shared predicate is what keeps the rule covered, and
 * stops the two layers drifting apart.
 */

function makeGuestPlayRecording(object $ctx, DvrRecordingStatus $status, ?int $authId): DvrRecording
{
    return DvrRecording::factory()
        ->for($ctx->dvrSetting)
        ->for($ctx->user)
        ->create(['playlist_auth_id' => $authId, 'status' => $status]);
}

it('guestCanPlay allows an owned Completed recording', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Completed, $this->guestA->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeTrue();
});

it('guestCanPlay allows an owned in-progress recording', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Recording, $this->guestA->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeTrue();
});

it('guestCanPlay REFUSES a recording owned by a different guest', function () {
    // The security case: guest A must never play guest B's recording.
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Completed, $this->guestB->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeFalse();
});

it('guestCanPlay REFUSES an owner-created recording (null playlist_auth_id)', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Completed, null);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeFalse();
});

it('guestCanPlay REFUSES when there is no authenticated guest', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Completed, $this->guestA->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, null))->toBeFalse();
});

it('guestCanPlay REFUSES a Scheduled recording even when owned', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Scheduled, $this->guestA->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeFalse();
});

it('guestCanPlay REFUSES a Failed recording even when owned', function () {
    $record = makeGuestPlayRecording($this, DvrRecordingStatus::Failed, $this->guestA->id);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeFalse();
});

it('guestCanPlay REFUSES when the DvrSetting has no resolvable owner', function () {
    $orphanSetting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => null,
        'merged_playlist_id' => null,
    ]);
    $record = DvrRecording::factory()
        ->for($orphanSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id, 'status' => DvrRecordingStatus::Completed]);

    expect(GuestDvrRecordingResource::guestCanPlay($record, $this->guestA))->toBeFalse();
});

// --- Owner (owner_auth) access ---
//
// The playlist owner can log into the guest panel with no PlaylistAuth
// record at all — username = their m3u-editor User::$name, password = the
// playlist UUID (PlaylistService::authenticate()'s "owner_auth" fallback).
// They have no dvr_enabled flag of their own, so access is gated by the
// playlist-level DvrSetting::$enabled instead, and they own recordings with
// a null playlist_auth_id — the ones scheduled from the main admin panel.

it('grants access to the owner via owner_auth when the playlist-level DvrSetting is enabled', function () {
    setOwnerAuthRecordingContext($this->playlist, $this->user);

    expect(GuestDvrRecordingResource::canAccess())->toBeTrue();
});

it('denies access to the owner via owner_auth when the playlist-level DvrSetting is disabled', function () {
    $this->dvrSetting->update(['enabled' => false]);
    setOwnerAuthRecordingContext($this->playlist, $this->user);

    expect(GuestDvrRecordingResource::canAccess())->toBeFalse();
});

it('scopes the owner\'s list query to their own (null playlist_auth_id) recordings only', function () {
    $ownerRecording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    setOwnerAuthRecordingContext($this->playlist, $this->user);

    $ids = GuestDvrRecordingResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownerRecording->id]);
});

it('guestCanCancel allows the owner (owner_auth) to cancel their own recording', function () {
    setOwnerAuthRecordingContext($this->playlist, $this->user);

    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null, 'status' => DvrRecordingStatus::Scheduled]);

    expect(GuestDvrRecordingResource::guestCanCancel($recording, null))->toBeTrue();
});

it('guestCanCancel REFUSES the owner (owner_auth) for a guest-owned recording', function () {
    setOwnerAuthRecordingContext($this->playlist, $this->user);

    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id, 'status' => DvrRecordingStatus::Scheduled]);

    expect(GuestDvrRecordingResource::guestCanCancel($recording, null))->toBeFalse();
});

it('guestCanPlay allows the owner (owner_auth) to play their own Completed recording', function () {
    setOwnerAuthRecordingContext($this->playlist, $this->user);

    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null, 'status' => DvrRecordingStatus::Completed]);

    expect(GuestDvrRecordingResource::guestCanPlay($recording, null))->toBeTrue();
});

it('guestCanCancel REFUSES anyone (including a resolvable owner_auth match) when the session is still guest A\'s', function () {
    // Session is guest A's (set in beforeEach) — a null $auth passed explicitly
    // must not fall back to owner recognition just because it's null.
    $recording = DvrRecording::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null, 'status' => DvrRecordingStatus::Scheduled]);

    expect(GuestDvrRecordingResource::guestCanCancel($recording, null))->toBeFalse();
});
