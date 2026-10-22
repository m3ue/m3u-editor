<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function boundOidcFixture(array $attributes = []): SocialiteUser
{
    $attributes = array_replace([
        'id' => 'subject-one',
        'name' => 'SSO Viewer',
        'email' => 'sso@example.test',
        'avatar' => null,
        'issuer' => 'https://issuer.example.test',
        'email_verified' => true,
    ], $attributes);

    return (new SocialiteUser)->setRaw([
        'iss' => $attributes['issuer'],
        'sub' => $attributes['id'],
        'aud' => 'fixture-client',
        'email_verified' => $attributes['email_verified'],
    ])->map($attributes);
}

beforeEach(function () {
    config()->set('services.oidc.enabled', true);
    config()->set('services.oidc.auto_create_users', true);
    config()->set('services.oidc.expected_issuer', 'https://issuer.example.test');
    config()->set('services.oidc.client_id', 'fixture-client');
});

it('does not match a local account by email or display name', function (string $field, string $value) {
    $local = User::factory()->create([$field => $value]);
    config()->set('services.oidc.auto_create_users', false);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error');
    $this->assertGuest();
    expect($local->fresh()->oidc_id)->toBeNull();
})->with(['email' => ['email', 'sso@example.test'], 'name' => ['name', 'SSO Viewer']]);

it('rejects invalid identity claims without changing users', function (array $attributes) {
    $count = User::count();
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture($attributes));

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error');
    $this->assertGuest();
    expect(User::count())->toBe($count);
})->with([
    'missing subject' => [['id' => null]],
    'empty subject' => [['id' => '']],
    'missing issuer' => [['issuer' => null]],
    'unexpected issuer' => [['issuer' => 'https://other.example.test']],
    'literal issuer mismatch' => [['issuer' => 'https://issuer.example.test/']],
]);

it('matches only the exact issuer and subject pair', function () {
    $other = User::factory()->create(['oidc_id' => 'subject-one', 'oidc_issuer' => 'https://other.example.test']);
    $local = User::factory()->create(['oidc_id' => 'subject-one', 'oidc_issuer' => 'https://issuer.example.test']);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertRedirect();
    $this->assertAuthenticatedAs($local);
    expect($other->fresh()->email)->not->toBe('sso@example.test');
});

it('does not automatically adopt legacy subject-only bindings', function () {
    $local = User::factory()->create(['oidc_id' => 'subject-one', 'email' => 'sso@example.test']);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error');
    $this->assertGuest();
    expect($local->fresh()->oidc_issuer)->toBeNull();
});

it('rejects email collisions before modifying an existing binding', function () {
    $local = User::factory()->create(['oidc_id' => 'subject-one', 'oidc_issuer' => 'https://issuer.example.test']);
    $original = $local->fresh()->getAttributes();
    User::factory()->create(['email' => 'sso@example.test']);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error');
    $this->assertGuest();
    expect($local->fresh()->getAttributes())->toBe($original);
});

it('links only the locally authenticated account after an explicit consent action', function () {
    $local = User::factory()->create(['name' => 'xtream-user']);
    $this->actingAs($local);
    Socialite::shouldReceive('driver->redirect')->once()->andReturn(redirect('https://issuer.example.test/authorize'));
    $this->post('/auth/oidc/link')->assertRedirect('https://issuer.example.test/authorize');
    Socialite::shouldReceive('driver->user')->once()->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertRedirect()->assertSessionMissing('oidc_link');
    $this->assertAuthenticatedAs($local);
    expect($local->fresh()->oidc_id)->toBe('subject-one')
        ->and($local->fresh()->oidc_issuer)->toBe('https://issuer.example.test')
        ->and($local->fresh()->name)->toBe('xtream-user');
});

it('requires authentication to start linking', function () {
    Socialite::shouldReceive('driver')->never();
    $this->post('/auth/oidc/link')->assertRedirect();
    $this->assertGuest();
});

it('does not switch accounts or replace an existing binding during linking', function () {
    $owner = User::factory()->create(['oidc_id' => 'subject-one', 'oidc_issuer' => 'https://issuer.example.test']);
    $local = User::factory()->create();
    $this->actingAs($local)->withSession(['oidc_link' => ['user_id' => $local->id, 'expires_at' => now()->addMinutes(5)->timestamp]]);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error')->assertSessionMissing('oidc_link');
    $this->assertAuthenticatedAs($local);
    expect($local->fresh()->oidc_id)->toBeNull()->and($owner->fresh()->oidc_id)->toBe('subject-one');
});

it('consumes expired or mismatched linking intents without mutation', function (string $mode) {
    $local = User::factory()->create();
    $this->actingAs($local)->withSession(['oidc_link' => [
        'user_id' => $mode === 'wrong user' ? $local->id + 1 : $local->id,
        'expires_at' => $mode === 'expired' ? now()->subMinute()->timestamp : now()->addMinutes(5)->timestamp,
    ]]);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture());

    $this->get('/auth/oidc/callback')->assertSessionHas('oidc_error')->assertSessionMissing('oidc_link');
    expect($local->fresh()->oidc_id)->toBeNull();
})->with(['expired', 'wrong user']);

it('consumes linking intent when the provider fails', function () {
    $local = User::factory()->create();
    $this->actingAs($local)->withSession(['oidc_link' => ['user_id' => $local->id, 'expires_at' => now()->addMinutes(5)->timestamp]]);
    Socialite::shouldReceive('driver->user')->andThrow(new RuntimeException('Fixture provider unavailable'));

    $this->get('/auth/oidc/callback')->assertSessionMissing('oidc_link')->assertSessionHas('oidc_error');
    expect($local->fresh()->oidc_id)->toBeNull();
});

it('does not mark unverified provider email as verified on creation', function () {
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture(['email_verified' => false]));
    $this->get('/auth/oidc/callback')->assertRedirect();
    expect(User::where('oidc_id', 'subject-one')->firstOrFail()->email_verified_at)->toBeNull();
});

it('does not retain local email verification when the provider changes to an unverified email', function () {
    $local = User::factory()->create(['oidc_id' => 'subject-one', 'oidc_issuer' => 'https://issuer.example.test']);
    Socialite::shouldReceive('driver->user')->andReturn(boundOidcFixture(['email_verified' => false]));
    $this->get('/auth/oidc/callback')->assertRedirect();
    $this->assertAuthenticatedAs($local);
    expect($local->fresh()->email_verified_at)->toBeNull();
});
