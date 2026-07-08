<?php

use App\Filament\GuestPanel\Pages\GuestRequestContent;
use App\Livewire\GuestQueueStatus;
use App\Models\ArrIntegration;
use App\Models\MediaRequest;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistRequestSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->owner = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->owner->id]);
});

function setGuestAuthContext(string $uuid, ?string $username = 'guest', ?string $password = 'secret'): void
{
    $request = Request::create('/');
    $request->attributes->set('playlist_uuid', $uuid);
    app()->instance('request', $request);

    if ($username) {
        session([
            base64_encode($uuid).'_guest_auth_username' => $username,
            base64_encode($uuid).'_guest_auth_password' => $password,
        ]);
    } else {
        session()->forget([
            base64_encode($uuid).'_guest_auth_username',
            base64_encode($uuid).'_guest_auth_password',
        ]);
    }
}

function createGuestAuthForPlaylist(Playlist $playlist, User $owner, bool $requestEnabled = true): PlaylistAuth
{
    $auth = PlaylistAuth::factory()->create([
        'user_id' => $owner->id,
        'enabled' => true,
        'username' => 'guest',
        'password' => 'secret',
        'request_enabled' => $requestEnabled,
    ]);
    $auth->assignTo($playlist);

    return $auth;
}

it('hides the page when no session auth', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    $request = Request::create('/');
    $request->attributes->set('playlist_uuid', $this->playlist->uuid);
    app()->instance('request', $request);
    session()->forget([
        base64_encode($this->playlist->uuid).'_guest_auth_username',
        base64_encode($this->playlist->uuid).'_guest_auth_password',
    ]);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when no integration is guest-enabled', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => false,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when no integration exists at all', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when request setting is disabled', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => false,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when no request setting exists', function () {
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when PlaylistAuth request access is disabled', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner, requestEnabled: false);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('shows the page when request setting is enabled and an integration is guest-enabled', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeTrue();
});

it('hides the page when integration is disabled', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'enabled' => false,
        'guest_enabled' => true,
    ]);

    createGuestAuthForPlaylist($this->playlist, $this->owner);
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page for unknown playlist UUID', function () {
    setGuestAuthContext('unknown-uuid');

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('resolves the integration via the integration property', function () {
    PlaylistRequestSetting::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->owner->id,
        'enabled' => true,
    ]);
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->owner->id,
        'enabled' => true,
        'guest_enabled' => true,
        'name' => 'My Sonarr',
    ]);

    setGuestAuthContext($this->playlist->uuid);

    $page = new GuestRequestContent;
    $resolved = $page->getIntegrationProperty();

    expect($resolved)->toBeInstanceOf(ArrIntegration::class);
    expect($resolved->id)->toBe($integration->id);
});

// ── My Requests queue ─────────────────────────────────────────────────────────

it('returns an empty collection when the guest has no requests', function () {
    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);
    setGuestAuthContext($this->playlist->uuid, 'guest');

    $page = new GuestRequestContent;
    expect($page->getMyRequestsProperty())->toHaveCount(0);
});

it('returns only requests belonging to the authenticated guest', function () {
    $integration = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->owner->id]);

    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);
    $otherAuth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'other']);

    MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'My Show',
        'external_id' => '1',
        'request_type' => 'series',
        'payload' => [],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    MediaRequest::create([
        'playlist_auth_id' => $otherAuth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'Other Show',
        'external_id' => '2',
        'request_type' => 'movie',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);

    setGuestAuthContext($this->playlist->uuid, 'guest');

    $page = new GuestRequestContent;
    $requests = $page->getMyRequestsProperty();

    expect($requests)->toHaveCount(1);
    expect($requests->first()->title)->toBe('My Show');
});

it('returns requests with all statuses for the guest', function () {
    $integration = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->owner->id]);
    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);

    foreach (['pending', 'approved', 'rejected'] as $status) {
        MediaRequest::create([
            'playlist_auth_id' => $auth->id,
            'arr_integration_id' => $integration->id,
            'title' => ucfirst($status).' Show',
            'external_id' => $status,
            'request_type' => 'series',
            'payload' => [],
            'status' => $status,
            'requested_at' => now(),
        ]);
    }

    setGuestAuthContext($this->playlist->uuid, 'guest');

    $page = new GuestRequestContent;
    $requests = $page->getMyRequestsProperty();

    expect($requests)->toHaveCount(3);
    expect($requests->pluck('status')->sort()->values()->all())
        ->toBe(['approved', 'pending', 'rejected']);
});

// ── GuestQueueStatus dismiss ───────────────────────────────────────────────────

it('guest can dismiss an approved or rejected request', function () {
    Http::preventStrayRequests();
    $integration = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->owner->id, 'guest_enabled' => true]);
    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);

    $approved = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'Done Show',
        'external_id' => '1',
        'request_type' => 'series',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);

    setGuestAuthContext($this->playlist->uuid, 'guest');
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    Livewire::test(GuestQueueStatus::class, ['uuid' => $this->playlist->uuid])
        ->call('dismissRequest', $approved->id);

    expect(MediaRequest::find($approved->id))->toBeNull();
});

it('guest cannot dismiss a pending request', function () {
    Http::preventStrayRequests();
    $integration = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->owner->id, 'guest_enabled' => true]);
    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);

    $pending = MediaRequest::create([
        'playlist_auth_id' => $auth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'Waiting Show',
        'external_id' => '2',
        'request_type' => 'series',
        'payload' => [],
        'status' => 'pending',
        'requested_at' => now(),
    ]);

    setGuestAuthContext($this->playlist->uuid, 'guest');
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    Livewire::test(GuestQueueStatus::class, ['uuid' => $this->playlist->uuid])
        ->call('dismissRequest', $pending->id);

    // Pending requests are protected — record must still exist.
    expect(MediaRequest::find($pending->id))->not->toBeNull();
});

it('guest cannot dismiss another guests request', function () {
    Http::preventStrayRequests();
    $integration = ArrIntegration::factory()->sonarr()->create(['user_id' => $this->owner->id, 'guest_enabled' => true]);

    $auth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'guest']);
    $otherAuth = PlaylistAuth::factory()->create(['user_id' => $this->owner->id, 'username' => 'other']);

    $otherRequest = MediaRequest::create([
        'playlist_auth_id' => $otherAuth->id,
        'arr_integration_id' => $integration->id,
        'title' => 'Other Show',
        'external_id' => '3',
        'request_type' => 'series',
        'payload' => [],
        'status' => 'approved',
        'requested_at' => now(),
    ]);

    setGuestAuthContext($this->playlist->uuid, 'guest');
    Http::fake(['*/api/v3/queue*' => Http::response(['records' => []], 200)]);

    Livewire::test(GuestQueueStatus::class, ['uuid' => $this->playlist->uuid])
        ->call('dismissRequest', $otherRequest->id);

    // Record belongs to a different auth — must not be deleted.
    expect(MediaRequest::find($otherRequest->id))->not->toBeNull();
});
