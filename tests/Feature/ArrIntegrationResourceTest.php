<?php

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Filament\Resources\ArrIntegrations\Pages\CreateArrIntegration;
use App\Filament\Resources\ArrIntegrations\Pages\EditArrIntegration;
use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Models\ArrIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('hides navigation when user cannot use integrations', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(ArrIntegrationResource::canAccess())->toBeFalse();
});

it('shows navigation when user has permission', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);

    expect(ArrIntegrationResource::canAccess())->toBeTrue();
});

it('scopes table query to current user', function () {
    $other = User::factory()->create();

    ArrIntegration::factory()->create(['user_id' => $this->user->id, 'name' => 'Mine']);
    ArrIntegration::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

    $query = ArrIntegrationResource::getEloquentQuery();
    $names = $query->pluck('name')->all();

    expect($names)->toContain('Mine');
    expect($names)->not->toContain('Theirs');
});

it('can create an integration', function () {
    Livewire::test(CreateArrIntegration::class)
        ->fillForm([
            'name' => 'Sonarr 1080p',
            'type' => 'sonarr',
            'url' => 'http://192.168.1.42:8989',
            'api_key' => 'secret-key-123',
            'enabled' => true,
            'guest_enabled' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $integration = ArrIntegration::where('name', 'Sonarr 1080p')->first();
    expect($integration)->not->toBeNull();
    expect($integration->user_id)->toBe($this->user->id);
    expect($integration->isSonarr())->toBeTrue();
    expect($integration->api_key)->toBe('secret-key-123');
});

it('requires type on create', function () {
    Livewire::test(CreateArrIntegration::class)
        ->fillForm([
            'name' => 'No Type',
            'url' => 'http://192.168.1.42:8989',
            'api_key' => 'secret',
        ])
        ->call('create')
        ->assertHasFormErrors(['type' => 'required']);
});

it('can edit an existing integration', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old Name',
    ]);

    Livewire::test(EditArrIntegration::class, [
        'record' => $integration->id,
    ])
        ->fillForm(['name' => 'New Name', 'guest_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $integration->refresh();
    expect($integration->name)->toBe('New Name');
    expect($integration->guest_enabled)->toBeTrue();
});

it('preserves api_key on edit when left blank', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'api_key' => 'original-key',
    ]);

    Livewire::test(EditArrIntegration::class, [
        'record' => $integration->id,
    ])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $integration->refresh();
    expect($integration->api_key)->toBe('original-key');
});

it('can delete an integration', function () {
    $integration = ArrIntegration::factory()->create(['user_id' => $this->user->id]);

    $this->assertDatabaseHas('arr_integrations', ['id' => $integration->id]);

    $integration->delete();

    expect(ArrIntegration::find($integration->id))->toBeNull();
    $this->assertDatabaseMissing('arr_integrations', ['id' => $integration->id]);
});

it('lists the page without error', function () {
    ArrIntegration::factory()->create(['user_id' => $this->user->id, 'name' => 'My Sonarr']);

    Livewire::test(ListArrIntegrations::class)
        ->assertOk();
});
