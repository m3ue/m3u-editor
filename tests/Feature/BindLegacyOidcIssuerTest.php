<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    config()->set('services.oidc.expected_issuer', 'https://issuer.example.test');
});

it('requires an explicit exact issuer for legacy migration', function (?string $issuer) {
    $user = User::factory()->create(['oidc_id' => 'legacy-subject']);
    $options = $issuer === null ? [] : ['--issuer' => $issuer];
    $this->artisan('oidc:bind-legacy-issuer', $options)->assertFailed();
    expect($user->fresh()->oidc_issuer)->toBeNull();
})->with([null, 'https://issuer.example.test/']);

it('previews legacy binding without changing any user', function () {
    $user = User::factory()->create(['oidc_id' => 'legacy-subject']);
    $this->artisan('oidc:bind-legacy-issuer', ['--issuer' => 'https://issuer.example.test', '--dry-run' => true])->assertSuccessful();
    expect($user->fresh()->oidc_issuer)->toBeNull();
});

it('binds only legacy rows and preserves usernames and existing bindings', function () {
    $legacy = User::factory()->create(['name' => 'xtream-name', 'oidc_id' => 'legacy-subject']);
    $local = User::factory()->create();
    $bound = User::factory()->create(['oidc_id' => 'already-bound', 'oidc_issuer' => 'https://other.example.test']);
    $this->artisan('oidc:bind-legacy-issuer', ['--issuer' => 'https://issuer.example.test'])->assertSuccessful();
    expect($legacy->fresh()->oidc_issuer)->toBe('https://issuer.example.test')
        ->and($legacy->fresh()->name)->toBe('xtream-name')
        ->and($local->fresh()->oidc_issuer)->toBeNull()
        ->and($bound->fresh()->oidc_issuer)->toBe('https://other.example.test');
});

it('aborts legacy migration atomically on duplicate or already claimed identities', function (bool $alreadyBound) {
    $first = User::factory()->create(['oidc_id' => 'duplicate-subject']);
    $second = User::factory()->create(['oidc_id' => 'duplicate-subject', 'oidc_issuer' => $alreadyBound ? 'https://issuer.example.test' : null]);
    $other = User::factory()->create(['oidc_id' => 'other-subject']);
    $this->artisan('oidc:bind-legacy-issuer', ['--issuer' => 'https://issuer.example.test'])->assertFailed();
    expect($first->fresh()->oidc_issuer)->toBeNull()->and($other->fresh()->oidc_issuer)->toBeNull();
})->with([false, true]);

it('enforces one local owner per issuer and subject in the database', function () {
    User::factory()->create(['oidc_id' => 'same-subject', 'oidc_issuer' => 'https://issuer.example.test']);
    expect(fn () => User::factory()->create(['oidc_id' => 'same-subject', 'oidc_issuer' => 'https://issuer.example.test']))
        ->toThrow(UniqueConstraintViolationException::class);
});
