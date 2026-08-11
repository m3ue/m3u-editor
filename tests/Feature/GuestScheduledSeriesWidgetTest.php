<?php

declare(strict_types=1);

use App\Enums\DvrRuleType;
use App\Filament\GuestPanel\Widgets\GuestScheduledSeriesWidget;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Set up request attributes and session so HasGuestDvr resolves the correct context.
 */
function setGuestScheduledSeriesContext(Playlist $playlist, PlaylistAuth $auth): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $auth->username);
    session()->put("{$prefix}guest_auth_password", $auth->password);
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
function setStaleGuestSeriesContext(Playlist $playlist): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", 'nonexistent_guest');
    session()->put("{$prefix}guest_auth_password", 'irrelevant_password');
}

beforeEach(function () {
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
    setGuestScheduledSeriesContext($this->playlist, $this->guestA);
});

it('excludes series rules owned by a different guest', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Own Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestA->id,
        ]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Other Guest Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestB->id,
        ]);

    $widget = new GuestScheduledSeriesWidget;
    $ids = $widget->getSeriesRules()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});

it('excludes owner-created series rules (null playlist_auth_id)', function () {
    $ownRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Own Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestA->id,
        ]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Owner Show',
            'enabled' => true,
            'playlist_auth_id' => null,
        ]);

    $widget = new GuestScheduledSeriesWidget;
    $ids = $widget->getSeriesRules()->pluck('id')->all();

    expect($ids)->toBe([$ownRule->id]);
});

// --- Stale-guest null-auth fail-open (issue #1398 follow-up) ---
//
// When a guest's PlaylistAuth is revoked/disabled while they still hold a
// session with credentials in the expected keys, getCurrentAuth() returns
// non-null but getCurrentPlaylistAuth() returns null. The merged fix for
// #1398 (scoping to playlist_auth_id) is correct for live guests, but the
// `?->id` fallback to whereNull() was turning that null into "show every
// series rule with playlist_auth_id = null" — i.e. the playlist owner's.
// The fix in getSeriesRules() must fail closed in this state. isOwnerAuth()
// must be allowed through, otherwise the legitimate playlist-owner login
// (which has no PlaylistAuth row) regresses.

it('returns no series rules when getCurrentPlaylistAuth() resolves to null and the session is not owner-auth', function () {
    $ownerRule = DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Owner Show',
            'enabled' => true,
            'playlist_auth_id' => null,
        ]);

    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Other Guest Show',
            'enabled' => true,
            'playlist_auth_id' => $this->guestB->id,
        ]);

    setStaleGuestSeriesContext($this->playlist);

    // Sanity check on the precondition — if these stop holding the test no
    // longer exercises the bug it's meant to.
    expect(GuestScheduledSeriesWidget::getDvrSetting())->not->toBeNull()
        ->and(GuestScheduledSeriesWidget::getCurrentPlaylistAuth())->toBeNull();

    $widget = new GuestScheduledSeriesWidget;

    expect($widget->getSeriesRules())->toHaveCount(0);
});

it('returns no series rules when getCurrentPlaylistAuth() resolves to null even if the owner has series rules', function () {
    // Specifically guard against leaking the owner's series rules, which is
    // the exact privacy regression #1398 exists to prevent.
    DvrRecordingRule::factory()
        ->for($this->dvrSetting)
        ->for($this->user)
        ->create([
            'type' => DvrRuleType::Series,
            'series_title' => 'Owner Show',
            'enabled' => true,
            'playlist_auth_id' => null,
        ]);

    setStaleGuestSeriesContext($this->playlist);

    $widget = new GuestScheduledSeriesWidget;
    $ids = $widget->getSeriesRules()->pluck('id')->all();

    expect($ids)->toBe([]);
});
