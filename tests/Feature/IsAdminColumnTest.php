<?php

use App\Models\User;

// --- isAdmin() uses column ---

it('returns true for users with is_admin column set', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    expect($admin->isAdmin())->toBeTrue();
});

it('returns false for users without is_admin column set', function () {
    $user = User::factory()->create(['is_admin' => false]);

    expect($user->isAdmin())->toBeFalse();
});

it('defaults is_admin to false for new users', function () {
    $user = User::factory()->create();

    expect($user->is_admin)->toBeFalse();
});

// --- Admin factory state ---

it('creates admin via factory state', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue();
});

// --- Admin status independent of email ---

it('remains admin after email change', function () {
    $admin = User::factory()->admin()->create(['email' => 'original@example.com']);

    $admin->update(['email' => 'newemail@example.com']);

    expect($admin->fresh()->isAdmin())->toBeTrue();
});

it('remains non-admin regardless of email', function () {
    // Even if the email matches the seeded admin email, is_admin column is what matters
    $user = User::factory()->create(['email' => 'looks-like-admin@example.com']);

    expect($user->isAdmin())->toBeFalse();
});

// --- Permissions inherit from is_admin ---

it('grants all permissions to admin users via is_admin', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->hasPermission('use_scrubber'))->toBeTrue()
        ->and($admin->hasPermission('view_release_logs'))->toBeTrue()
        ->and($admin->hasPermission('any_permission'))->toBeTrue();
});

it('does not grant permissions to non-admin by default', function () {
    $user = User::factory()->create();

    expect($user->hasPermission('use_scrubber'))->toBeFalse()
        ->and($user->hasPermission('view_release_logs'))->toBeFalse();
});

// --- OIDC + is_admin ---

it('preserves is_admin when oidc_id is set', function () {
    $admin = User::factory()->admin()->create();
    $admin->update(['oidc_id' => 'oidc-abc']);

    expect($admin->fresh()->isAdmin())->toBeTrue()
        ->and($admin->fresh()->isOidcUser())->toBeTrue();
});
