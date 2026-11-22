<?php

/**
 * Settings actions live in the section body (or on the field they act on) rather than in
 * section headings, so they line up with the fields they belong to.
 */

use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['permissions' => ['use_proxy']]));
});

it('tests the resolver connection from the Resolver URL suffix action', function () {
    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765, 'proxy.m3u_resolver_url' => null]);
    Http::preventStrayRequests();
    Http::fake(['*/test-connection' => Http::response(['success' => true, 'url_tested' => 'http://editor.test/up'])]);

    Livewire::test(ManageProxySettings::class)
        ->fillForm(['failover_resolver_url' => 'http://editor.test'])
        ->assertActionVisible(TestAction::make('test_failover_connection')->schemaComponent('failover_resolver_url'))
        ->callAction(TestAction::make('test_failover_connection')->schemaComponent('failover_resolver_url'))
        ->assertNotified(__('Connection Successful'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/test-connection')
        && $request['url'] === 'http://editor.test/up');
});

it('disables the resolver test until a Resolver URL is entered', function () {
    config(['proxy.m3u_resolver_url' => null]);

    Livewire::test(ManageProxySettings::class)
        ->fillForm(['failover_resolver_url' => ''])
        ->assertActionDisabled(TestAction::make('test_failover_connection')->schemaComponent('failover_resolver_url'));
});

it('renders moved section actions in the section body', function () {
    Livewire::test(ManageProxySettings::class)
        ->assertActionVisible(TestAction::make('testProxyWebhooks')->schemaComponent());

    Livewire::test(ManageIntegrationSettings::class)
        ->assertActionVisible(TestAction::make('test_mediaflow_connection')->schemaComponent());
});

it('tests the TMDB connection from the API key suffix action', function () {
    Http::preventStrayRequests();
    Http::fake(['api.themoviedb.org/*' => Http::response(['images' => []])]);

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['tmdb_api_key' => 'test-key'])
        ->callAction(TestAction::make('test_tmdb_connection')->schemaComponent('tmdb_api_key'))
        ->assertNotified(__('Connection Successful'));

    Http::assertSent(fn (Request $request): bool => $request['api_key'] === 'test-key');
});

it('links to the TMDB API key page from the API key field hint', function () {
    $getKey = TestAction::make('get_tmdb_api_key')->schemaComponent('tmdb_api_key');

    Livewire::test(ManageIntegrationSettings::class)
        ->assertActionVisible($getKey)
        ->assertActionHasUrl($getKey, 'https://www.themoviedb.org/settings/api');
});
