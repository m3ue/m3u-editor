<?php

use App\Models\User;

beforeEach(function () {
    // Migrations seed an "admin" user; start each test from a known-empty state.
    User::query()->delete();
});

it('disables MFA for the only user and prints the correct user name', function () {
    User::factory()->create(['name' => 'Jane Doe']);

    $this->artisan('app:disable-mfa')
        ->expectsOutputToContain('Jane Doe')
        ->assertExitCode(0);
});

it('shows a message when no users exist', function () {
    $this->artisan('app:disable-mfa')
        ->expectsOutputToContain('No users found.')
        ->assertExitCode(0);
});

it('prints the chosen user name (not the collection) when multiple users exist', function () {
    User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    // Regression: before the fix, the info() message interpolated $users->name
    // (the Collection) instead of $user->name, producing an empty name string.
    $this->artisan('app:disable-mfa')
        ->expectsChoice(
            'Select a user to disable MFA for:',
            'bob@example.com',
            ['alice@example.com', 'bob@example.com']
        )
        ->expectsOutputToContain('Bob')
        ->assertExitCode(0);
});
