<?php

use App\Filament\Resources\StreamProfiles\Pages\ListStreamProfiles;
use App\Models\StreamProfile;
use App\Models\User;
use App\Policies\StreamProfilePolicy;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->proxyUser = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->plainUser = User::factory()->create();
});

// ── canAccess / viewAny ───────────────────────────────────────────────────────

it('allows proxy users to access the stream profiles list', function (): void {
    $this->actingAs($this->proxyUser);

    Livewire::test(ListStreamProfiles::class)
        ->assertSuccessful();
});

it('blocks users without proxy permission from accessing stream profiles', function (): void {
    $this->actingAs($this->plainUser);

    expect((new StreamProfilePolicy)->viewAny($this->plainUser))->toBeFalse();
});

// ── create ────────────────────────────────────────────────────────────────────

it('allows proxy users to see the create action', function (): void {
    $this->actingAs($this->proxyUser);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionVisible('create');
});

it('allows admins to see the create action', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionVisible('create');
});

it('policy grants create to proxy users', function (): void {
    expect((new StreamProfilePolicy)->create($this->proxyUser))->toBeTrue();
});

it('policy denies create to users without proxy permission', function (): void {
    expect((new StreamProfilePolicy)->create($this->plainUser))->toBeFalse();
});

// ── update ────────────────────────────────────────────────────────────────────

it('allows proxy users to edit their own stream profiles', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    $this->actingAs($this->proxyUser);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionVisible(TestAction::make('edit')->table($profile));
});

it('policy grants update to a proxy user for their own profile', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    expect((new StreamProfilePolicy)->update($this->proxyUser, $profile))->toBeTrue();
});

it('policy denies update to a proxy user for another user\'s profile', function (): void {
    $other = User::factory()->create(['permissions' => ['use_proxy']]);
    $profile = StreamProfile::factory()->for($other)->create();

    expect((new StreamProfilePolicy)->update($this->proxyUser, $profile))->toBeFalse();
});

it('policy grants update to admins for any profile', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    expect((new StreamProfilePolicy)->update($this->admin, $profile))->toBeTrue();
});

// ── delete ────────────────────────────────────────────────────────────────────

it('allows proxy users to delete their own stream profiles', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    $this->actingAs($this->proxyUser);

    Livewire::test(ListStreamProfiles::class)
        ->assertActionVisible(TestAction::make('delete')->table($profile));
});

it('policy grants delete to a proxy user for their own profile', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    expect((new StreamProfilePolicy)->delete($this->proxyUser, $profile))->toBeTrue();
});

it('policy denies delete to a proxy user for another user\'s profile', function (): void {
    $other = User::factory()->create(['permissions' => ['use_proxy']]);
    $profile = StreamProfile::factory()->for($other)->create();

    expect((new StreamProfilePolicy)->delete($this->proxyUser, $profile))->toBeFalse();
});

it('policy grants delete to admins for any profile', function (): void {
    $profile = StreamProfile::factory()->for($this->proxyUser)->create();

    expect((new StreamProfilePolicy)->delete($this->admin, $profile))->toBeTrue();
});
