<?php

use App\Filament\GuestPanel\Pages\GuestRequestContent;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Request;

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

it('hides the page when no session auth', function () {
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
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
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'guest_enabled' => false,
    ]);

    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page when no integration exists at all', function () {
    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('shows the page when an integration is guest-enabled', function () {
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
        'guest_enabled' => true,
    ]);

    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeTrue();
});

it('hides the page when integration is disabled', function () {
    ArrIntegration::factory()->create([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => false,
        'guest_enabled' => true,
    ]);

    setGuestAuthContext($this->playlist->uuid);

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('hides the page for unknown playlist UUID', function () {
    setGuestAuthContext('unknown-uuid');

    expect(GuestRequestContent::canAccess())->toBeFalse();
});

it('resolves the integration via the integration property', function () {
    $integration = ArrIntegration::factory()->sonarr()->create([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
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
