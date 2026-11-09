<?php

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Models\ArrIntegration;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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
    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'Sonarr 1080p',
            'type' => 'sonarr',
            'url' => 'http://192.168.1.42:8989',
            'api_key' => 'secret-key-123',
            'enabled' => true,
            'guest_enabled' => false,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $integration = ArrIntegration::where('name', 'Sonarr 1080p')->first();
    expect($integration)->not->toBeNull();
    expect($integration->user_id)->toBe($this->user->id);
    expect($integration->isSonarr())->toBeTrue();
    expect($integration->api_key)->toBe('secret-key-123');
});

it('requires type on create', function () {
    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'name' => 'No Type',
            'url' => 'http://192.168.1.42:8989',
            'api_key' => 'secret',
        ])
        ->assertHasFormErrors(['type' => 'required']);
});

it('can edit an existing integration', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Old Name',
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('edit')->table($integration), data: ['name' => 'New Name', 'guest_enabled' => true])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $integration->refresh();
    expect($integration->name)->toBe('New Name');
    expect($integration->guest_enabled)->toBeTrue();
});

it('saves the Use for caching toggle', function () {
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id]);

    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('edit')->table($integration), data: ['cache_enabled' => true])
        ->assertHasNoFormErrors();

    expect($integration->refresh()->cache_enabled)->toBeTrue();
});

it('flips Use for caching from the integrations table', function () {
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id]);

    Livewire::test(ListArrIntegrations::class)
        ->assertSee('instead of downloading them from the provider')
        ->call('updateTableColumnState', 'cache_enabled', (string) $integration->id, true);

    expect($integration->refresh()->cache_enabled)->toBeTrue();
});

it('preserves api_key on edit when left blank', function () {
    $integration = ArrIntegration::factory()->create([
        'user_id' => $this->user->id,
        'api_key' => 'original-key',
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('edit')->table($integration), data: ['name' => 'New Name'])
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

it('shows the webhook URL when editing', function () {
    $integration = ArrIntegration::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->assertSchemaStateSet(['webhook_url' => url('/api/webhooks/arr/'.$integration->webhook_secret)]);
});

/**
 * Radarr's Webhook connection template from /notification/schema (trimmed).
 *
 * @return array<string, mixed>
 */
function arrWebhookTemplate(): array
{
    return [
        'implementation' => 'Webhook',
        'configContract' => 'WebhookSettings',
        'fields' => [['name' => 'url', 'value' => null], ['name' => 'method', 'value' => 1]],
        'onGrab' => false,
        'onDownload' => false,
        'onUpgrade' => false,
        'onMovieAdded' => false,
        'onManualInteractionRequired' => false,
        'onHealthIssue' => false,
    ];
}

/**
 * The url field of a Webhook connection sent to the arr.
 */
function arrWebhookUrl(Request $request): ?string
{
    return collect($request['fields'])->firstWhere('name', 'url')['value'] ?? null;
}

it('registers the webhook in Radarr with the events the app handles', function () {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'url' => 'http://radarr.test']);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/notification/schema' => Http::response([arrWebhookTemplate()]),
        'radarr.test/api/v3/notification' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([])
            : Http::response(['id' => 9], 201),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('registerWebhook')->schemaComponent('webhook'))
        ->assertNotified('Webhook registered');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['name'] === 'm3u editor ('.substr($integration->webhook_secret, 0, 8).')'
        && arrWebhookUrl($request) === $integration->webhook_url
        && $request['onGrab'] && $request['onDownload'] && $request['onUpgrade']
        && $request['onMovieAdded'] && $request['onManualInteractionRequired']
        && ! $request['onHealthIssue']);
});

it('updates an existing webhook connection instead of adding a second one', function () {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'url' => 'http://radarr.test']);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/notification' => Http::response([[
            ...arrWebhookTemplate(),
            'id' => 5,
            'name' => 'My webhook',
            'fields' => [['name' => 'url', 'value' => 'http://old-host:36400/api/webhooks/arr/'.$integration->webhook_secret]],
        ]]),
        'radarr.test/api/v3/notification/5' => Http::response(['id' => 5], 202),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('registerWebhook')->schemaComponent('webhook'))
        ->assertNotified('Webhook registered');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request['name'] === 'My webhook'
        && arrWebhookUrl($request) === $integration->webhook_url
        && $request['onGrab']);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('shows the arr error when the webhook cannot be registered', function () {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'url' => 'http://radarr.test']);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/notification/schema' => Http::response([arrWebhookTemplate()]),
        'radarr.test/api/v3/notification' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([])
            : Http::response([['propertyName' => '', 'errorMessage' => 'Unable to post to webhook: Connection refused']], 400),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('registerWebhook')->schemaComponent('webhook'))
        ->assertNotified('Could not register the webhook');
});

it('tests the webhook through Radarr with one attempt', function (int $status, string $title) {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'url' => 'http://radarr.test']);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/notification/schema' => Http::response([arrWebhookTemplate()]),
        'radarr.test/api/v3/notification/test' => Http::response($status === 200 ? [] : [['errorMessage' => 'Unable to send test message']], $status),
        'radarr.test/api/v3/notification' => Http::response([]),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('testWebhook')->schemaComponent('webhook'))
        ->assertNotified($title);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/notification/test')
        && arrWebhookUrl($request) === $integration->webhook_url);
})->with([
    'reachable' => [200, 'Webhook test succeeded'],
    'unreachable' => [400, 'Webhook test failed'],
]);

it('tests the registered webhook connection itself, so its name does not clash', function () {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'url' => 'http://radarr.test']);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/notification/test' => Http::response([]),
        'radarr.test/api/v3/notification' => Http::response([[
            ...arrWebhookTemplate(),
            'id' => 5,
            'name' => 'm3u editor',
            'fields' => [['name' => 'url', 'value' => $integration->webhook_url]],
        ]]),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('testWebhook')->schemaComponent('webhook'))
        ->assertNotified('Webhook test succeeded');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/notification/test')
        && $request['id'] === 5);
});

it('discovers profiles in the edit slide-over with the saved API key', function () {
    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'url' => 'http://radarr.test',
        'api_key' => 'saved-key',
        'quality_profile_id' => null,
        'root_folder_path' => null,
        'last_test_at' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/system/status' => Http::response(['version' => '6.0.4']),
        'radarr.test/api/v3/qualityprofile' => Http::response([['id' => 7, 'name' => 'HD-1080p']]),
        'radarr.test/api/v3/rootfolder' => Http::response([['path' => '/media/Movies']]),
    ]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('testAndDiscover')->schemaComponent())
        ->assertNotified('Connection Successful')
        ->assertSchemaStateSet(['quality_profile_id' => 7, 'root_folder_path' => '/media/Movies']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'saved-key'));
    expect($integration->refresh()->last_test_at)->not->toBeNull();
});

it('shows the saved profile and folder locked until Discover loads the server choices', function () {
    $integration = ArrIntegration::factory()->radarr()->create([
        'user_id' => $this->user->id,
        'url' => 'http://radarr.test',
        'quality_profile_id' => 4,
        'quality_profile_name' => 'Ultra-HD',
        'root_folder_path' => '/media/4K',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'radarr.test/api/v3/system/status' => Http::response(['version' => '6.0.4']),
        'radarr.test/api/v3/qualityprofile' => Http::response([['id' => 7, 'name' => 'HD-1080p'], ['id' => 4, 'name' => 'Ultra-HD']]),
        'radarr.test/api/v3/rootfolder' => Http::response([['path' => '/media/Movies'], ['path' => '/media/4K']]),
    ]);

    // Saving without Discover keeps the saved choices.
    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->assertSchemaComponentVisible('quality_profile_id')
        ->assertSchemaComponentVisible('root_folder_path')
        ->assertFormFieldDisabled('quality_profile_id')
        ->assertFormFieldDisabled('root_folder_path')
        ->assertFormFieldExists('quality_profile_id', fn (Select $field): bool => $field->getOptions() === [4 => 'Ultra-HD'])
        ->assertFormFieldExists('root_folder_path', fn (Select $field): bool => $field->getOptions() === ['/media/4K' => '/media/4K'])
        ->assertMountedActionModalSee('Click "Test Connection & Discover" above to load the options from the server.')
        ->fillForm(['name' => 'Radarr 4K'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($integration->refresh())
        ->name->toBe('Radarr 4K')
        ->quality_profile_id->toBe(4)
        ->root_folder_path->toBe('/media/4K');

    // Discover unlocks every choice and leaves the saved ones selected.
    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($integration))
        ->callAction(TestAction::make('testAndDiscover')->schemaComponent())
        ->assertFormFieldEnabled('quality_profile_id')
        ->assertFormFieldEnabled('root_folder_path')
        ->assertFormFieldExists('quality_profile_id', fn (Select $field): bool => $field->getOptions() === [7 => 'HD-1080p', 4 => 'Ultra-HD'])
        ->assertMountedActionModalDontSee('Click "Test Connection & Discover" above to load the options from the server.')
        ->assertSchemaStateSet(['quality_profile_id' => 4, 'root_folder_path' => '/media/4K']);
});

it('shows the profile and folder fields locked on create until Discover runs', function () {
    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('create')->table())
        ->assertSchemaComponentVisible('quality_profile_id')
        ->assertFormFieldDisabled('quality_profile_id')
        ->assertSchemaComponentVisible('root_folder_path')
        ->assertFormFieldDisabled('root_folder_path');
});
