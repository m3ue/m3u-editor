<?php

use App\Filament\Resources\PluginInstallReviews\Pages\EditPluginInstallReview;
use App\Filament\Resources\Plugins\Pages\EditPlugin;
use App\Filament\Resources\Plugins\Pages\ListPlugins;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Plugin;
use App\Models\PluginInstallReview;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginValidator;
use App\Plugins\Support\PluginApiRequestContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    // Throttling runs on Redis, which persists between tests; the rate-limit test sets its own limit.
    config()->set('plugins.api.rate_limit_per_minute', 100000);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['xapi_enabled' => true]);
    $this->playlistAuth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true]);
    $this->playlistAuth->assignTo($this->playlist);
    $this->credentials = [
        'username' => $this->playlistAuth->username,
        'password' => $this->playlistAuth->password,
    ];
});

afterEach(function () {
    if (isset($this->apiPluginPath)) {
        File::deleteDirectory($this->apiPluginPath);
    }
});

/**
 * Copy the api-demo fixture into a scratch directory (under a unique class name, so
 * each test loads fresh code), validate it, then trust and enable it.
 *
 * @param  array<string, mixed>  $manifestChanges
 */
function trustedApiDemoPlugin(array $manifestChanges = [], ?Closure $editSource = null): Plugin
{
    $namespace = 'ApiDemoFixture'.Str::random(10);
    $path = storage_path('app/testing-plugin-api/'.$namespace);
    File::ensureDirectoryExists($path);
    test()->apiPluginPath = $path;

    $source = str_replace(
        'namespace AppLocalPlugins\\ApiDemo;',
        "namespace AppLocalPlugins\\{$namespace};",
        File::get(base_path('tests/Fixtures/plugins/api-demo/Plugin.php')),
    );
    File::put($path.'/Plugin.php', $editSource ? $editSource($source) : $source);

    $manifest = array_replace(
        json_decode(File::get(base_path('tests/Fixtures/plugins/api-demo/plugin.json')), true),
        ['class' => "AppLocalPlugins\\{$namespace}\\Plugin"],
        $manifestChanges,
    );
    File::put($path.'/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $plugin = app(PluginManager::class)->validate(Plugin::create([
        'plugin_id' => $manifest['id'],
        'path' => $path,
        'name' => $manifest['name'],
        'installation_status' => 'installed',
    ]));
    expect($plugin->validation_errors)->toBeEmpty();

    $plugin->update([
        'trusted_hashes' => $plugin->only(['manifest_hash', 'entrypoint_hash', 'plugin_hash']),
        'trust_state' => 'trusted',
        'integrity_status' => 'verified',
        'enabled' => true,
    ]);

    return $plugin->fresh();
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>|null  $credentials
 */
function callPluginApi(string $endpoint, array $payload = [], ?array $credentials = null, string $pluginId = 'api-demo')
{
    return test()->postJson(
        "/api/plugins/{$pluginId}/{$endpoint}",
        ($credentials ?? test()->credentials) + ['payload' => $payload],
    );
}

it('answers a declared endpoint with the validated payload and the response envelope', function () {
    trustedApiDemoPlugin();
    Bus::fake();

    callPluginApi('echo', ['message' => 'hi', 'shout' => true])
        ->assertOk()
        ->assertExactJson([
            'api_version' => 1,
            'plugin_id' => 'api-demo',
            'endpoint' => 'echo',
            'data' => ['message' => 'HI', 'greeting' => 'Hello'],
        ]);

    $this->assertDatabaseCount(PluginRun::class, 0);
    Bus::assertNothingDispatched();
});

it('accepts credentials in an HTTP Basic header', function () {
    trustedApiDemoPlugin();

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode($this->playlistAuth->username.':'.$this->playlistAuth->password),
    ])->postJson('/api/plugins/api-demo/echo', ['payload' => ['message' => 'hi']])
        ->assertOk()
        ->assertJsonPath('data.message', 'hi');
});

it('never reads credentials from the query string', function () {
    trustedApiDemoPlugin();

    $this->postJson('/api/plugins/api-demo/echo?'.http_build_query($this->credentials), ['payload' => ['message' => 'hi']])
        ->assertUnauthorized()
        ->assertExactJson(['error' => 'unauthorized']);
});

it('requires POST with a JSON body', function () {
    trustedApiDemoPlugin();

    $this->getJson('/api/plugins/api-demo/echo')->assertStatus(405);
    $this->post('/api/plugins/api-demo/echo', $this->credentials + ['payload' => ['message' => 'hi']])
        ->assertStatus(415);
});

it('rejects logins that /player_api.php would reject', function (string $case) {
    trustedApiDemoPlugin();

    match ($case) {
        'wrong password' => $this->credentials['password'] = 'wrong',
        'expired' => $this->playlistAuth->update(['expires_at' => now()->subMinute()]),
        'disabled' => $this->playlistAuth->update(['enabled' => false]),
        'xtream output disabled' => $this->playlist->update(['xapi_enabled' => false]),
    };

    callPluginApi('echo', ['message' => 'hi'])->assertUnauthorized();
})->with(['wrong password', 'expired', 'disabled', 'xtream output disabled']);

it('scopes the plugin to the playlist the login unlocks', function () {
    trustedApiDemoPlugin();

    callPluginApi('playlist-summary')
        ->assertOk()
        ->assertJsonPath('data', ['name' => $this->playlist->name, 'type' => 'Playlist', 'via_playlist_auth' => true]);

    callPluginApi('playlist-summary', [], ['username' => $this->user->name, 'password' => $this->playlist->uuid])
        ->assertOk()
        ->assertJsonPath('data.via_playlist_auth', false);

    $custom = CustomPlaylist::factory()->for($this->user)->create(['xapi_enabled' => true]);
    $this->playlistAuth->assignTo($custom);
    callPluginApi('playlist-summary')->assertOk()->assertJsonPath('data.type', 'CustomPlaylist');

    $alias = PlaylistAlias::create([
        'name' => 'Synthetic alias',
        'uuid' => (string) Str::uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'username' => 'synthetic-alias',
        'password' => 'synthetic-alias-pass',
        'xapi_enabled' => true,
    ]);
    callPluginApi('playlist-summary', [], ['username' => $alias->username, 'password' => $alias->password])
        ->assertOk()
        ->assertJsonPath('data.type', 'PlaylistAlias');
});

it('passes identifiers and settings to the plugin, never credentials', function () {
    $plugin = trustedApiDemoPlugin();
    $playlist = $this->playlist;

    $context = PluginApiRequestContext::make($plugin, 'echo', $playlist, $this->playlistAuth, ['greeting' => 'Hi']);

    expect(json_decode(json_encode($context), true))->toBe([
        'pluginId' => 'api-demo',
        'endpoint' => 'echo',
        'playlist' => ['id' => $playlist->id, 'type' => Playlist::class, 'user_id' => $this->user->id],
        'playlistAuth' => ['id' => $this->playlistAuth->id, 'user_id' => $this->user->id],
        'settings' => ['greeting' => 'Hi'],
    ]);
});

it('only routes endpoint ids declared in the manifest', function (string $endpoint) {
    trustedApiDemoPlugin();

    callPluginApi($endpoint)->assertNotFound();
})->with(['runaction', 'handleapirequest', 'unknown']);

it('returns not found for a missing plugin or a disabled plugin system', function () {
    callPluginApi('echo', ['message' => 'hi'])->assertNotFound();

    trustedApiDemoPlugin();
    config()->set('plugins.enabled', false);
    callPluginApi('echo', ['message' => 'hi'])->assertNotFound();
});

it('refuses plugins that are not runnable before loading their code', function (array $state, int $status) {
    $plugin = trustedApiDemoPlugin();
    $plugin->update($state);

    callPluginApi('echo', ['message' => 'hi'])->assertStatus($status);
    expect(class_exists($plugin->class_name, false))->toBeFalse();
})->with([
    'uninstalled' => [['installation_status' => 'uninstalled'], 404],
    'unavailable' => [['available' => false], 404],
    'disabled' => [['enabled' => false], 403],
    'blocked' => [['trust_state' => 'blocked'], 403],
    'untrusted' => [['trust_state' => 'pending_review'], 403],
    'integrity reset' => [['trusted_hashes' => []], 403],
]);

it('re-hashes plugin files and refuses code changed after trust', function () {
    $plugin = trustedApiDemoPlugin();
    File::append($plugin->path.'/Plugin.php', "\n// changed after trust\n");

    callPluginApi('echo', ['message' => 'hi'])->assertForbidden();
    expect($plugin->fresh()->integrity_status)->toBe('changed')
        ->and(class_exists($plugin->class_name, false))->toBeFalse();
});

it('does not write the plugin row when nothing changed', function () {
    $plugin = trustedApiDemoPlugin();
    $plugin->forceFill(['last_validated_at' => now()->subDay()])->save();
    $before = $plugin->fresh()->last_validated_at;

    callPluginApi('echo', ['message' => 'hi'])->assertOk();

    expect($plugin->fresh()->last_validated_at->equalTo($before))->toBeTrue();
});

it('validates the payload against the declared fields', function (array $payload, array $fields) {
    trustedApiDemoPlugin();

    callPluginApi('echo', $payload)
        ->assertUnprocessable()
        ->assertExactJson(['error' => 'invalid_payload', 'fields' => $fields]);
})->with([
    'missing required' => [[], ['message']],
    'wrong type' => [['message' => ['nested']], ['message']],
    'too long' => [['message' => str_repeat('x', 2049)], ['message']],
]);

it('only hands the plugin declared fields', function () {
    trustedApiDemoPlugin(editSource: fn (string $source): string => str_replace(
        "'echo' => \$this->echo(\$payload, \$context),",
        "'echo' => ['keys' => array_keys(\$payload)],",
        $source,
    ));

    callPluginApi('echo', ['message' => 'hi', 'playlist_id' => 999, 'user_id' => 1])
        ->assertOk()
        ->assertJsonPath('data.keys', ['message']);
});

it('rejects a payload that is not a JSON object', function () {
    trustedApiDemoPlugin();

    $this->postJson('/api/plugins/api-demo/echo', $this->credentials + ['payload' => ['a', 'b']])
        ->assertUnprocessable()
        ->assertJsonPath('fields', ['payload']);
});

it('bounds the request body and the encoded response', function () {
    trustedApiDemoPlugin();

    $this->postJson('/api/plugins/api-demo/echo', $this->credentials + [
        'payload' => ['message' => 'hi'],
        'padding' => str_repeat('x', 131072),
    ])->assertStatus(413)->assertJsonPath('error', 'request_too_large');

    config()->set('plugins.api.max_response_bytes', 64);
    callPluginApi('echo', ['message' => str_repeat('x', 100)])
        ->assertStatus(413)
        ->assertJsonPath('error', 'response_too_large');
});

it('hides plugin exception details from the caller and logs them', function () {
    Log::spy();
    trustedApiDemoPlugin(editSource: fn (string $source): string => str_replace(
        "'echo' => \$this->echo(\$payload, \$context),",
        "'echo' => throw new RuntimeException('secret-internal-detail'),",
        $source,
    ));

    $response = callPluginApi('echo', ['message' => 'hi']);

    $response->assertStatus(500)->assertExactJson(['error' => 'plugin_request_failed']);
    expect($response->getContent())->not->toContain('secret-internal-detail');
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'Plugin API request failed'
            && $context['plugin_id'] === 'api-demo'
            && $context['endpoint'] === 'echo'
            && $context['exception']->getMessage() === 'secret-internal-detail',
    )->once();
});

it('blocks private and loopback destinations through the SSRF guard', function (string $url) {
    trustedApiDemoPlugin();

    callPluginApi('url-status', ['url' => $url])
        ->assertUnprocessable()
        ->assertJsonPath('fields', ['url']);

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/admin',
    'private range' => 'http://192.168.1.1/',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'non-http scheme' => 'file:///etc/passwd',
]);

it('fetches public destinations through the SSRF guard', function () {
    trustedApiDemoPlugin();
    Http::fake(['93.184.216.34/*' => Http::response('ok', 204)]);

    callPluginApi('url-status', ['url' => 'http://93.184.216.34/health'])
        ->assertOk()
        ->assertJsonPath('data.status', 204);
});

it('refuses outbound requests from plugins without network_egress', function () {
    $plugin = trustedApiDemoPlugin();
    $plugin->update(['permissions' => ['db_read', 'api_endpoints']]);

    $context = PluginApiRequestContext::make($plugin->fresh(), 'url-status', $this->playlist, null, []);

    expect(fn () => $context->safeGet('http://93.184.216.34/'))
        ->toThrow(RuntimeException::class, 'network_egress');
    Http::assertNothingSent();
});

it('rate limits per client IP, whatever plugin id is in the URL', function () {
    trustedApiDemoPlugin();
    config()->set('plugins.api.rate_limit_per_minute', 2);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.random_int(1, 254)]);

    callPluginApi('echo', ['message' => 'hi'])->assertOk();
    callPluginApi('echo', ['message' => 'hi'], ['username' => 'guess', 'password' => 'guess'], 'some-other-plugin')->assertUnauthorized();
    callPluginApi('echo', ['message' => 'hi'], ['username' => 'guess', 'password' => 'guess'], 'yet-another-plugin')->assertTooManyRequests();
});

it('no longer treats plugin_query as a special Xtream action', function () {
    trustedApiDemoPlugin();

    $response = $this->post('/player_api.php', $this->credentials + [
        'action' => 'plugin_query',
        'plugin_id' => 'api-demo',
        'query' => 'echo',
        'payload' => '{"message":"hi"}',
    ]);

    expect($response->getContent())->not->toContain('"plugin_id"');
});

describe('manifest validation', function () {
    /**
     * @param  array<string, mixed>  $changes
     * @return array<int, string>
     */
    function apiManifestErrors(array $changes, ?Closure $editSource = null): array
    {
        $path = storage_path('app/testing-plugin-api/validator-'.Str::random(10));
        File::ensureDirectoryExists($path);
        test()->apiPluginPath = $path;

        $source = File::get(base_path('tests/Fixtures/plugins/api-demo/Plugin.php'));
        File::put($path.'/Plugin.php', $editSource ? $editSource($source) : $source);
        File::put($path.'/plugin.json', json_encode(array_replace(
            json_decode(File::get(base_path('tests/Fixtures/plugins/api-demo/plugin.json')), true),
            $changes,
        ), JSON_THROW_ON_ERROR));

        return app(PluginValidator::class)->validatePath($path)->errors;
    }

    it('accepts the demo plugin as shipped', function () {
        expect(apiManifestErrors([]))->toBe([]);
    });

    it('requires the capability, permission and endpoint list together', function (array $changes, string $error) {
        expect(apiManifestErrors($changes))->toContain($error);
    })->with([
        'endpoints without capability' => [['capabilities' => []], 'Plugins declaring [api_endpoints] must use the [api_endpoints] capability.'],
        'endpoints without permission' => [['permissions' => ['db_read', 'network_egress']], 'Plugins declaring [api_endpoints] must declare the [api_endpoints] permission.'],
        'capability without endpoints' => [['api_endpoints' => []], 'Plugins using the [api_endpoints] capability must declare at least one entry in [api_endpoints].'],
    ]);

    it('requires the plugin class to implement the endpoint interface', function () {
        $errors = apiManifestErrors([], fn (string $source): string => str_replace(
            'implements ApiEndpointPluginInterface, PluginInterface',
            'implements PluginInterface',
            $source,
        ));

        expect($errors)->toContain('Plugin class [AppLocalPlugins\\ApiDemo\\Plugin] must implement [App\\Plugins\\Contracts\\ApiEndpointPluginInterface]');
    });

    it('rejects unsafe endpoint declarations', function (array $endpoint, string $error) {
        expect(apiManifestErrors(['api_endpoints' => [$endpoint]]))->toContain($error);
    })->with([
        'path-like id' => [['id' => '../admin'], 'API endpoint [id] must be 1-64 lowercase letters, digits, hyphens or underscores, starting with a letter or digit.'],
        'model select field' => [
            ['id' => 'lookup', 'fields' => [['id' => 'playlist', 'type' => 'model_select', 'label' => 'Playlist', 'model' => Playlist::class]]],
            'api_endpoints.lookup.playlist uses type [model_select], which API endpoints do not accept',
        ],
        'dynamic options' => [
            ['id' => 'lookup', 'fields' => [['id' => 'mode', 'type' => 'select', 'label' => 'Mode', 'options_provider' => 'modes']]],
            'api_endpoints.lookup.mode cannot use [options_provider] on an API endpoint',
        ],
    ]);

    it('rejects duplicate endpoint ids', function () {
        expect(apiManifestErrors(['api_endpoints' => [['id' => 'echo'], ['id' => 'echo']]]))
            ->toContain('Duplicate API endpoint id [echo]');
    });
});

describe('plugin manager UI', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create([
            'email' => 'plugin-api-admin-'.Str::lower(Str::random(8)).'@example.com',
        ]));
    });

    it('lists each endpoint URL on the plugin page and warns about outside access and egress', function () {
        $plugin = trustedApiDemoPlugin();

        Livewire::test(EditPlugin::class, ['record' => $plugin->getKey()])
            ->assertOk()
            ->assertSee('Reachable from outside M3U Editor')
            ->assertSee('Can fetch remote URLs for outside callers')
            ->assertSet('data.api_endpoint_playlist_summary', route('api.plugins.endpoint', ['pluginId' => 'api-demo', 'endpoint' => 'playlist-summary']))
            ->assertSet('data.api_endpoint_url_status', route('api.plugins.endpoint', ['pluginId' => 'api-demo', 'endpoint' => 'url-status']))
            ->assertSee('Accepts: message, shout');
    });

    it('flags plugins with endpoints in the plugin list', function () {
        $plugin = trustedApiDemoPlugin();

        Livewire::test(ListPlugins::class)
            ->assertCanSeeTableRecords([$plugin])
            ->assertTableColumnStateSet('api_endpoint_count', 3, $plugin);
    });

    it('shows declared endpoints and the high-risk permission in the install review', function () {
        $manifest = json_decode(File::get(base_path('tests/Fixtures/plugins/api-demo/plugin.json')), true);
        $review = PluginInstallReview::create([
            'plugin_id' => 'api-demo',
            'plugin_name' => 'API Demo',
            'source_type' => 'local_directory',
            'status' => 'review_ready',
            'validation_status' => 'valid',
            'scan_status' => 'clean',
            'permissions' => $manifest['permissions'],
            'capabilities' => $manifest['capabilities'],
            'manifest_snapshot' => $manifest,
        ]);

        Livewire::test(EditPluginInstallReview::class, ['record' => $review->getKey()])
            ->assertOk()
            ->assertSee('Exposes API endpoints')
            ->assertSee('/api/plugins/api-demo/playlist-summary')
            ->assertSee('Can fetch remote URLs for outside callers')
            ->assertSee('network_egress, api_endpoints');
    });
});
