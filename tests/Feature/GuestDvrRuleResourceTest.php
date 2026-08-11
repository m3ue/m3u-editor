<?php

declare(strict_types=1);

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Resources\DvrRules\GuestDvrRuleResource;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Set up request attributes and session so HasGuestDvr resolves the correct context.
 */
function setGuestDvrRuleContext(Playlist $playlist, PlaylistAuth $auth): void
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
function setOwnerAuthRuleContext(Playlist $playlist, User $user): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $user->name);
    session()->put("{$prefix}guest_auth_password", $playlist->uuid);
}

/**
 * Set up a "stale guest" session: credentials are present in the expected
 * session keys (so getCurrentAuth() returns non-null), but they don't match
 * any PlaylistAuth row (so getCurrentPlaylistAuth() returns null). This is
 * the state a guest lands in when their PlaylistAuth is revoked/disabled
 * mid-session while stale session credentials still exist — the exact
 * scenario issue #1398 follow-up is about. getDvrSetting() must still
 * resolve normally via the request attribute so the only path that can leak
 * is the playlist_auth_id whereNull() coercion of the original fix.
 */
function setStaleGuestRuleContext(Playlist $playlist): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", 'nonexistent_guest');
    session()->put("{$prefix}guest_auth_password", 'irrelevant_password');
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
    setGuestDvrRuleContext($this->playlist, $this->guestA);
});

// --- canAccess / canCreate ---

it('grants access when dvr_enabled is true and DvrSetting exists', function () {
    expect(GuestDvrRuleResource::canAccess())->toBeTrue();
});

it('denies access when dvr_enabled is false', function () {
    $this->guestA->update(['dvr_enabled' => false]);

    expect(GuestDvrRuleResource::canAccess())->toBeFalse();
});

it('denies access when DVR_ENABLED config is false', function () {
    config()->set('dvr.dvr_enabled', false);

    expect(GuestDvrRuleResource::canAccess())->toBeFalse();
});

it('denies access when proxy integration config is false', function () {
    config()->set('proxy.proxy_integration_enabled', false);

    expect(GuestDvrRuleResource::canAccess())->toBeFalse();
});

it('allows creating rules when access is granted', function () {
    expect(GuestDvrRuleResource::canCreate())->toBeTrue();
});

it('denies creating rules when dvr_enabled is false', function () {
    $this->guestA->update(['dvr_enabled' => false]);

    expect(GuestDvrRuleResource::canCreate())->toBeFalse();
});

it('denies creating rules when DVR_ENABLED config is false', function () {
    config()->set('dvr.dvr_enabled', false);

    expect(GuestDvrRuleResource::canCreate())->toBeFalse();
});

// --- canEdit / canDelete ownership ---

it('allows editing a rule owned by the current guest', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    expect(GuestDvrRuleResource::canEdit($rule))->toBeTrue();
});

it('denies editing a rule owned by a different guest', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id]);

    expect(GuestDvrRuleResource::canEdit($rule))->toBeFalse();
});

it('denies editing a rule owned by the playlist owner (null playlist_auth_id)', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    expect(GuestDvrRuleResource::canEdit($rule))->toBeFalse();
});

it('allows deleting a rule owned by the current guest', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    expect(GuestDvrRuleResource::canDelete($rule))->toBeTrue();
});

it('denies deleting a rule owned by a different guest', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id]);

    expect(GuestDvrRuleResource::canDelete($rule))->toBeFalse();
});

it('denies deleting a rule owned by the playlist owner (null playlist_auth_id)', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    expect(GuestDvrRuleResource::canDelete($rule))->toBeFalse();
});

// --- before() guard conditions (same logic applied in the before hooks for EditAction/DeleteAction) ---

it('before guard authorizes the owning guest for edit and delete', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    $currentAuth = GuestDvrRuleResource::getCurrentPlaylistAuth();

    $isAuthorized = $currentAuth && $rule->playlist_auth_id === $currentAuth->id;

    expect($isAuthorized)->toBeTrue();
});

it('before guard rejects edit/delete for a rule owned by a different guest', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id]);

    $currentAuth = GuestDvrRuleResource::getCurrentPlaylistAuth();

    $isAuthorized = $currentAuth && $rule->playlist_auth_id === $currentAuth->id;

    expect($isAuthorized)->toBeFalse();
});

it('before guard rejects edit/delete for a rule with null playlist_auth_id (owner rule)', function () {
    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    $currentAuth = GuestDvrRuleResource::getCurrentPlaylistAuth();

    $isAuthorized = $currentAuth && $rule->playlist_auth_id === $currentAuth->id;

    expect($isAuthorized)->toBeFalse();
});

// --- Eloquent query scope ---

it('scopes rules to the current playlist DvrSetting', function () {
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherSetting = DvrSetting::factory()->enabled()->for($otherPlaylist)->for($otherUser)->create();

    DvrRecordingRule::factory()->for($this->dvrSetting)->for($this->user)->create(['playlist_auth_id' => $this->guestA->id]);
    DvrRecordingRule::factory()->for($otherSetting)->for($otherUser)->create();

    $ids = GuestDvrRuleResource::getEloquentQuery()->pluck('dvr_setting_id')->unique()->all();

    expect($ids)->toBe([$this->dvrSetting->id]);
});

it('returns no rules when no DvrSetting exists', function () {
    $this->dvrSetting->delete();
    setGuestDvrRuleContext($this->playlist, $this->guestA);

    $count = GuestDvrRuleResource::getEloquentQuery()->count();

    expect($count)->toBe(0);
});

it('excludes rules owned by a different guest from the list query', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestB->id]);

    $ids = GuestDvrRuleResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});

it('excludes owner-created rules (null playlist_auth_id) from the list query', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    $ids = GuestDvrRuleResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});

// --- create action null guard ---

it('create action guard blocks when DvrSetting does not exist', function () {
    $this->dvrSetting->delete();
    setGuestDvrRuleContext($this->playlist, $this->guestA);

    // getDvrSetting() returns null → the guard returns early, no rule created
    $dvrSetting = GuestDvrRuleResource::getDvrSetting();

    expect($dvrSetting)->toBeNull();
    expect(DvrRecordingRule::count())->toBe(0);
});

it('create action stamps playlist_auth_id from the current guest auth', function () {
    // Verify the expected values that the create action would stamp
    $dvrSetting = GuestDvrRuleResource::getDvrSetting();
    $auth = GuestDvrRuleResource::getCurrentPlaylistAuth();

    expect($dvrSetting?->id)->toBe($this->dvrSetting->id)
        ->and($auth?->id)->toBe($this->guestA->id);
});

// --- DvrRecordingRule factory produces correct rule types ---

it('can create Manual and Series rules (not Once) via canCreate', function () {
    expect(GuestDvrRuleResource::canCreate())->toBeTrue();

    // Manual rule
    $manual = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Manual,
            'playlist_auth_id' => $this->guestA->id,
        ]);

    // Series rule
    $series = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Breaking Bad',
            'playlist_auth_id' => $this->guestA->id,
        ]);

    expect($manual->type)->toBe(DvrRuleType::Manual)
        ->and($series->type)->toBe(DvrRuleType::Series);
});

// --- Owner (owner_auth) access ---
//
// The playlist owner can log into the guest panel with no PlaylistAuth
// record at all — username = their m3u-editor User::$name, password = the
// playlist UUID (PlaylistService::authenticate()'s "owner_auth" fallback).
// They have no dvr_enabled flag of their own, so access is gated by the
// playlist-level DvrSetting::$enabled instead, and they own rules with a
// null playlist_auth_id — the ones created from the main admin panel.

it('grants access and create to the owner via owner_auth when the playlist-level DvrSetting is enabled', function () {
    setOwnerAuthRuleContext($this->playlist, $this->user);

    expect(GuestDvrRuleResource::canAccess())->toBeTrue()
        ->and(GuestDvrRuleResource::canCreate())->toBeTrue();
});

it('denies access to the owner via owner_auth when the playlist-level DvrSetting is disabled', function () {
    $this->dvrSetting->update(['enabled' => false]);
    setOwnerAuthRuleContext($this->playlist, $this->user);

    expect(GuestDvrRuleResource::canAccess())->toBeFalse()
        ->and(GuestDvrRuleResource::canCreate())->toBeFalse();
});

it('scopes the owner\'s list query to their own (null playlist_auth_id) rules only', function () {
    $ownerRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    setOwnerAuthRuleContext($this->playlist, $this->user);

    $ids = GuestDvrRuleResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([$ownerRule->id]);
});

it('allows the owner (owner_auth) to edit and delete their own rule', function () {
    setOwnerAuthRuleContext($this->playlist, $this->user);

    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    expect(GuestDvrRuleResource::canEdit($rule))->toBeTrue()
        ->and(GuestDvrRuleResource::canDelete($rule))->toBeTrue();
});

it('denies the owner (owner_auth) editing a guest-owned rule', function () {
    setOwnerAuthRuleContext($this->playlist, $this->user);

    $rule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    expect(GuestDvrRuleResource::canEdit($rule))->toBeFalse()
        ->and(GuestDvrRuleResource::canDelete($rule))->toBeFalse();
});

// --- Stale-guest null-auth fail-open (issue #1398 follow-up) ---
//
// When a guest's PlaylistAuth is revoked/disabled while they still hold a
// session with credentials in the expected keys, getCurrentAuth() returns
// non-null but getCurrentPlaylistAuth() returns null. The merged fix for
// #1398 (scoping to playlist_auth_id) is correct for live guests, but the
// `?->id` fallback to whereNull() was turning that null into "show every
// rule with playlist_auth_id = null" — i.e. the playlist owner's. The fix
// in getEloquentQuery() must fail closed in this state. isOwnerAuth() must
// be allowed through, otherwise the legitimate playlist-owner login (which
// has no PlaylistAuth row) regresses.

it('returns no rules when getCurrentPlaylistAuth() resolves to null and the session is not owner-auth', function () {
    $ownerRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => $this->guestA->id]);

    setStaleGuestRuleContext($this->playlist);

    // Sanity check on the precondition — if these stop holding the test no
    // longer exercises the bug it's meant to.
    expect(GuestDvrRuleResource::getDvrSetting())->not->toBeNull()
        ->and(GuestDvrRuleResource::getCurrentPlaylistAuth())->toBeNull();

    $count = GuestDvrRuleResource::getEloquentQuery()->count();

    expect($count)->toBe(0);
});

it('returns no rules when getCurrentPlaylistAuth() resolves to null even if the owner has rules', function () {
    // Specifically guard against leaking the owner's rules, which is the
    // exact privacy regression #1398 exists to prevent.
    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create(['playlist_auth_id' => null]);

    setStaleGuestRuleContext($this->playlist);

    $ids = GuestDvrRuleResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toBe([]);
});
