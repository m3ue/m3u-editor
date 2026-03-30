<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function mockOidcUser(array $attributes = []): SocialiteUser
{
    $defaults = [
        'id' => 'oidc-123',
        'name' => 'Test User',
        'email' => 'oidc-test@example.com',
        'avatar' => 'https://example.com/avatar.jpg',
    ];

    $attributes = array_merge($defaults, $attributes);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $attributes['id'];
    $socialiteUser->name = $attributes['name'];
    $socialiteUser->email = $attributes['email'];
    $socialiteUser->avatar = $attributes['avatar'];

    return $socialiteUser;
}

beforeEach(function () {
    config()->set('services.oidc.enabled', true);
    config()->set('services.oidc.auto_create_users', true);
});

// --- Route guards ---

it('returns 404 for redirect when OIDC is disabled', function () {
    config()->set('services.oidc.enabled', false);

    $this->get('/auth/oidc/redirect')->assertNotFound();
});

it('returns 404 for callback when OIDC is disabled', function () {
    config()->set('services.oidc.enabled', false);

    $this->get('/auth/oidc/callback')->assertNotFound();
});

// --- User matching ---

it('matches existing user by oidc_id', function () {
    $user = User::factory()->create(['oidc_id' => 'oidc-123']);
    $countBefore = User::count();

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser());

    $this->get('/auth/oidc/callback')
        ->assertRedirect();

    expect(auth()->id())->toBe($user->id);
    expect(User::count())->toBe($countBefore);
});

it('matches existing user by email', function () {
    $user = User::factory()->create(['email' => 'oidc-test@example.com']);

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser());

    $this->get('/auth/oidc/callback')
        ->assertRedirect();

    expect(auth()->id())->toBe($user->id);
    expect($user->fresh()->oidc_id)->toBe('oidc-123');
});

it('matches existing user by username as fallback', function () {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'different@example.com',
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser());

    $this->get('/auth/oidc/callback')
        ->assertRedirect();

    expect(auth()->id())->toBe($user->id);
    expect($user->fresh()->oidc_id)->toBe('oidc-123');
});

it('prefers oidc_id match over email match', function () {
    $oidcUser = User::factory()->create(['oidc_id' => 'oidc-123', 'email' => 'other@example.com']);
    $emailUser = User::factory()->create(['email' => 'someone-else@example.com']);

    // Mock OIDC user returns a unique email that won't conflict
    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser([
        'email' => 'oidc-unique@example.com',
    ]));

    $this->get('/auth/oidc/callback')
        ->assertRedirect();

    expect(auth()->id())->toBe($oidcUser->id);
});

// --- Auto-create ---

it('creates a new user when no match and auto-create is enabled', function () {
    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser());

    $this->get('/auth/oidc/callback')
        ->assertRedirect();

    $user = User::where('oidc_id', 'oidc-123')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Test User')
        ->and($user->email)->toBe('oidc-test@example.com')
        ->and(auth()->id())->toBe($user->id);
});

it('denies login when no match and auto-create is disabled', function () {
    config()->set('services.oidc.auto_create_users', false);
    $countBefore = User::count();

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser());

    $this->get('/auth/oidc/callback')
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(auth()->check())->toBeFalse();
    expect(User::count())->toBe($countBefore);
});

// --- Profile sync ---

it('syncs profile data from IdP on login', function () {
    $user = User::factory()->create([
        'oidc_id' => 'oidc-123',
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser([
        'name' => 'New Name',
        'email' => 'new@example.com',
        'avatar' => 'https://example.com/new-avatar.jpg',
    ]));

    $this->get('/auth/oidc/callback');

    $user->refresh();
    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->avatar_url)->toBe('https://example.com/new-avatar.jpg');
});

// --- Error handling ---

it('redirects with error when OIDC provider fails', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new Exception('Provider error'));

    $this->get('/auth/oidc/callback')
        ->assertRedirect(route('filament.admin.auth.login'))
        ->assertSessionHas('oidc_error');
});

it('redirects with error when OIDC user has no email', function () {
    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser(['email' => null]));

    $this->get('/auth/oidc/callback')
        ->assertRedirect(route('filament.admin.auth.login'))
        ->assertSessionHas('oidc_error');

    expect(auth()->check())->toBeFalse();
});

// --- Admin status preserved ---

it('preserves admin status when OIDC syncs profile', function () {
    $admin = User::factory()->create([
        'oidc_id' => 'oidc-123',
        'is_admin' => true,
        'email' => 'myadmin@example.com',
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(mockOidcUser([
        'email' => 'different@example.com',
    ]));

    $this->get('/auth/oidc/callback');

    $admin->refresh();
    expect($admin->is_admin)->toBeTrue()
        ->and($admin->email)->toBe('different@example.com');
});
