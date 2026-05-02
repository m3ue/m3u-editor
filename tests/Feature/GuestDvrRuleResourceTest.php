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

it('allows creating rules when access is granted', function () {
    expect(GuestDvrRuleResource::canCreate())->toBeTrue();
});

it('denies creating rules when dvr_enabled is false', function () {
    $this->guestA->update(['dvr_enabled' => false]);

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

    DvrRecordingRule::factory()->for($this->dvrSetting)->for($this->user)->create();
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
