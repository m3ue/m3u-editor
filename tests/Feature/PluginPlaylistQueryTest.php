<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\Support\PluginPlaylistQueryContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['xapi_enabled' => true]);
    $this->playlistAuth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true]);
    $this->playlistAuth->assignTo($this->playlist);
    $this->queryBody = [
        'username' => $this->playlistAuth->username,
        'password' => $this->playlistAuth->password,
        'action' => 'plugin_query',
        'plugin_id' => 'query-demo',
        'query' => 'echo_readonly',
        'payload' => '{"value":"synthetic"}',
    ];
});

function playlistQueryPlugin(bool $withQueries = true, array $queryNames = ['echo_readonly']): Plugin
{
    $class = 'PlaylistQueryFixture'.Str::random(12);
    $path = storage_path('app/testing-playlist-queries/'.$class);
    File::ensureDirectoryExists($path);
    test()->queryPluginPath = $path;
    $source = <<<'PHP'
<?php
class FIXTURE_CLASS implements \App\Plugins\Contracts\PluginInterface
{
    public function runAction(string $action, array $payload, \App\Plugins\Support\PluginExecutionContext $context): \App\Plugins\Support\PluginActionResult
    {
        throw new \RuntimeException('Write action must never run');
    }

    public function playlistQueryNames(): array
    {
        return ['echo_readonly'];
    }

    public function queryForPlaylist(string $query, array $payload, object $context): array
    {
        if (isset($payload['validation_error'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['value' => 'internal-password-and-path']);
        }
        if (isset($payload['http_error'])) {
            abort(418, 'internal-password-and-path');
        }
        if (isset($payload['invalid_utf8'])) {
            return ['value' => "\xB1"];
        }
        if (isset($payload['throw'])) {
            throw new \RuntimeException('internal-password-and-path');
        }
        if (isset($payload['size'])) {
            return ['value' => str_repeat('x', $payload['size'])];
        }
        if (isset($payload['context'])) {
            return ['context' => $context];
        }

        return ['value' => $payload['value'] ?? null];
    }
}
PHP;
    if (! $withQueries) {
        $source = str_replace('function queryForPlaylist(', 'function unsupportedQuery(', $source);
    }
    $source = str_replace("return ['echo_readonly'];", 'return '.var_export($queryNames, true).';', $source);
    File::put($path.'/Plugin.php', str_replace('FIXTURE_CLASS', $class, $source));
    File::put($path.'/plugin.json', json_encode([
        'id' => 'query-demo', 'name' => 'Query demo', 'version' => '1.0.0',
        'api_version' => '1.0.0', 'entrypoint' => 'Plugin.php', 'class' => $class,
        'capabilities' => [], 'hooks' => [], 'permissions' => [], 'actions' => [],
        'settings' => [['id' => 'example', 'type' => 'text', 'label' => 'Example', 'default' => 'default-value']], 'schema' => ['tables' => []],
    ], JSON_THROW_ON_ERROR));
    $plugin = Plugin::create([
        'plugin_id' => 'query-demo', 'path' => $path, 'name' => 'Query demo',
        'installation_status' => 'installed',
    ]);
    $plugin = app(PluginManager::class)->validate($plugin);
    expect($plugin->validation_errors)->toBeEmpty();
    $plugin->update([
        'trusted_hashes' => $plugin->only(['manifest_hash', 'entrypoint_hash', 'plugin_hash']),
        'trust_state' => 'trusted', 'enabled' => true,
    ]);

    return $plugin->fresh();
}

afterEach(function () {
    if (isset($this->queryPluginPath)) {
        File::deleteDirectory($this->queryPluginPath);
    }
});

it('serves an allowlisted read query through native Xtream POST authentication', function () {
    playlistQueryPlugin();
    Bus::fake();
    $this->post('/player_api.php', $this->queryBody)->assertOk()->assertExactJson([
        'api_version' => 1, 'plugin_id' => 'query-demo', 'query' => 'echo_readonly',
        'data' => ['value' => 'synthetic'],
    ]);
    $this->assertDatabaseCount(PluginRun::class, 0);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('rejects GET queries instead of invoking a plugin', function () {
    playlistQueryPlugin();
    $this->getJson('/player_api.php?'.http_build_query($this->queryBody))->assertStatus(405);
});

it('rejects missing and invalid credentials without changing other Xtream actions', function () {
    $this->post('/player_api.php', array_replace($this->queryBody, ['password' => 'invalid']))->assertUnauthorized();
    $this->post('/player_api.php', array_diff_key($this->queryBody, ['password' => true]))->assertUnauthorized();
    $this->postJson('/player_api.php', ['action' => 'panel', 'username' => 'invalid', 'password' => 'invalid'])->assertUnauthorized();
});

it('preserves credential expiry and disabled output rejection', function (string $case) {
    if ($case === 'expired') {
        $this->playlistAuth->update(['expires_at' => now()->subMinute()]);
    } elseif ($case === 'disabled') {
        $this->playlistAuth->update(['enabled' => false]);
    } else {
        $this->playlist->update(['xapi_enabled' => false]);
    }
    $this->post('/player_api.php', $this->queryBody)->assertUnauthorized();
})->with(['expired', 'disabled', 'output_disabled']);

it('rejects unavailable plugin registry states before loading code', function (array $state, int $status) {
    $plugin = playlistQueryPlugin();
    $plugin->update($state);
    $this->post('/player_api.php', $this->queryBody)->assertStatus($status);
    expect(class_exists($plugin->class_name, false))->toBeFalse();
})->with([
    'uninstalled' => [['installation_status' => 'uninstalled'], 404],
    'unavailable' => [['available' => false], 404],
    'disabled' => [['enabled' => false], 403],
    'blocked' => [['trust_state' => 'blocked'], 403],
    'untrusted' => [['trust_state' => 'pending_review'], 403],
    'integrity' => [['trusted_hashes' => []], 403],
]);

it('rechecks actual file integrity before executing a query', function () {
    $plugin = playlistQueryPlugin();
    File::append($plugin->path.'/Plugin.php', "\n// changed after trust\n");
    $this->post('/player_api.php', $this->queryBody)->assertForbidden();
    expect($plugin->fresh()->integrity_status)->toBe('changed');
});

it('never dispatches arbitrary methods or write actions', function (string $query) {
    playlistQueryPlugin();
    $this->post('/player_api.php', array_replace($this->queryBody, ['query' => $query]))->assertNotFound();
    $this->assertDatabaseCount(PluginRun::class, 0);
})->with(['runAction', 'queryForPlaylist', 'playlistQueryNames', '__construct', 'unknown']);

it('returns unavailable for missing plugins and the disabled plugin system', function () {
    $this->post('/player_api.php', $this->queryBody)->assertNotFound();
    playlistQueryPlugin();
    config()->set('plugins.enabled', false);
    $this->post('/player_api.php', $this->queryBody)->assertNotFound();
});

it('validates generic form input without returning submitted data', function (array $changes) {
    playlistQueryPlugin();
    $response = $this->post('/player_api.php', array_replace($this->queryBody, $changes));
    $response->assertUnprocessable();
    expect($response->getContent())->not->toContain($this->playlistAuth->password);
})->with([
    'bad plugin' => [['plugin_id' => '../private']],
    'array plugin' => [['plugin_id' => ['query-demo']]],
    'missing plugin' => [['plugin_id' => '']],
    'long plugin' => [['plugin_id' => str_repeat('a', 101)]],
    'missing query' => [['query' => '']],
    'array query' => [['query' => ['echo_readonly']]],
    'invalid query characters' => [['query' => 'query/name']],
    'long query' => [['query' => str_repeat('a', 101)]],
    'missing payload' => [['payload' => '']],
    'invalid json' => [['payload' => '{broken']],
    'scalar json' => [['payload' => 'true']],
    'non json form field' => [['payload' => ['value' => 'synthetic']]],
]);

it('bounds the whole form body including unrelated fields', function () {
    playlistQueryPlugin();
    $this->post('/player_api.php', $this->queryBody + ['extra' => str_repeat('x', 131072)])->assertStatus(413);
});

it('bounds the encoded envelope and sanitizes plugin exceptions', function () {
    playlistQueryPlugin();
    $this->post('/player_api.php', array_replace($this->queryBody, ['payload' => '{"size":524288}']))->assertStatus(413);
    $response = $this->post('/player_api.php', array_replace($this->queryBody, ['payload' => '{"throw":true}']));
    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('internal-password-and-path');
});

it('passes only the authenticated playlist scope and restricted auth identity', function () {
    $plugin = playlistQueryPlugin();
    $plugin->update(['settings' => ['example' => 'resolved']]);
    $foreign = Playlist::factory()->create();
    $response = $this->post('/player_api.php', array_replace($this->queryBody, [
        'payload' => json_encode(['context' => true, 'playlist_id' => $foreign->id]),
    ]));
    $response->assertOk()->assertJsonPath('data.context.playlist', [
        'id' => $this->playlist->id, 'type' => Playlist::class, 'user_id' => $this->user->id,
    ])->assertJsonPath('data.context.playlistAuth', [
        'id' => $this->playlistAuth->id, 'user_id' => $this->user->id,
    ])->assertJsonPath('data.context.settings.example', 'resolved');
    expect(array_keys($response->json('data.context')))->toBe(['playlist', 'playlistAuth', 'settings']);
    expect($response->getContent())->not->toContain($this->playlistAuth->password, $this->playlist->uuid, 'request');
});

it('keeps query methods optional for existing plugins', function () {
    playlistQueryPlugin(withQueries: false);
    $this->post('/player_api.php', $this->queryBody)->assertNotFound();
});

it('passes the actual custom merged or alias scope instead of a client-selected type', function (string $type) {
    playlistQueryPlugin();
    $scope = match ($type) {
        'custom' => CustomPlaylist::factory()->for($this->user)->create(['xapi_enabled' => true]),
        'merged' => MergedPlaylist::factory()->for($this->user)->create(['xapi_enabled' => true]),
        'alias' => PlaylistAlias::create([
            'name' => 'Synthetic alias', 'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
            'username' => 'synthetic-alias', 'password' => 'synthetic-alias-pass', 'xapi_enabled' => true,
        ]),
    };
    $this->playlistAuth->assignTo($scope);
    $response = $this->post('/player_api.php', array_replace($this->queryBody, [
        'payload' => '{"context":true,"type":"App\\\\Models\\\\User","user_id":9999,"playlist_id":9999}',
    ]));
    $response->assertOk()->assertJsonPath('data.context.playlist', [
        'id' => $scope->id, 'type' => $scope::class, 'user_id' => $this->user->id,
    ]);
})->with(['custom', 'merged', 'alias']);

it('keeps owner and alias logins scoped without passing credentials', function () {
    playlistQueryPlugin();
    $body = array_replace($this->queryBody, [
        'username' => $this->user->name, 'password' => $this->playlist->uuid,
        'payload' => '{"context":true}',
    ]);
    $this->post('/player_api.php', $body)->assertOk()
        ->assertJsonPath('data.context.playlist.id', $this->playlist->id)
        ->assertJsonPath('data.context.playlistAuth', null);
    $alias = PlaylistAlias::create([
        'name' => 'Synthetic alias', 'uuid' => (string) Str::uuid(),
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'username' => 'synthetic-alias', 'password' => 'synthetic-alias-pass', 'xapi_enabled' => true,
    ]);
    $body['username'] = $alias->username;
    $body['password'] = $alias->password;
    $response = $this->post('/player_api.php', $body);
    $response->assertOk()->assertJsonPath('data.context.playlist.type', PlaylistAlias::class)
        ->assertJsonPath('data.context.playlist.id', $alias->id)
        ->assertJsonPath('data.context.playlistAuth', null);
    expect($response->getContent())->not->toContain($alias->password, $alias->uuid);
    $alias->update(['expires_at' => now()->subMinute()]);
    $this->post('/player_api.php', $body)->assertUnauthorized();
});

it('rejects credentials supplied only in a URL', function () {
    $body = array_diff_key($this->queryBody, ['username' => true, 'password' => true]);
    $url = '/player_api.php?'.http_build_query($this->playlistAuth->only(['username', 'password']));
    $this->post($url, $body)->assertUnauthorized();
});

it('enforces the actual raw form body boundary even with an understated length header', function (int $bytes, int $status) {
    playlistQueryPlugin();
    $body = $this->queryBody + ['padding' => ''];
    $body['padding'] = str_repeat('x', $bytes - strlen(http_build_query($body)));
    $raw = http_build_query($body);
    expect(strlen($raw))->toBe($bytes);
    $this->call('POST', '/player_api.php', $body, [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'CONTENT_LENGTH' => '1',
    ], $raw)->assertStatus($status);
})->with([[131072, 200], [131073, 413]]);

it('accepts only a response whose full encoded envelope fits the limit', function (int $size, int $status) {
    playlistQueryPlugin();
    $response = $this->post('/player_api.php', array_replace($this->queryBody, [
        'payload' => json_encode(['size' => $size]),
    ]));
    $response->assertStatus($status);
    if ($status === 200) {
        expect(strlen($response->getContent()))->toBe(524288);
    }
})->with([[524202, 200], [524203, 413]]);

it('sanitizes validation HTTP and serialization errors from plugin code', function (string $mode, int $status) {
    playlistQueryPlugin();
    $response = $this->post('/player_api.php', array_replace($this->queryBody, [
        'payload' => json_encode([$mode => true]),
    ]));
    $response->assertStatus($status);
    expect($response->getContent())->not->toContain('internal-password-and-path', 'exception', 'trace');
})->with([['validation_error', 422], ['http_error', 500], ['invalid_utf8', 500]]);

it('reports genuinely missing files as unavailable before loading code', function () {
    $plugin = playlistQueryPlugin();
    File::deleteDirectory($plugin->path);
    $this->post('/player_api.php', $this->queryBody)->assertNotFound();
    expect(class_exists($plugin->class_name, false))->toBeFalse();
});

it('does not coerce values in the plugin query allowlist', function () {
    playlistQueryPlugin(queryNames: [0]);
    $this->post('/player_api.php', array_replace($this->queryBody, ['query' => '0']))->assertNotFound();
});

it('accepts only native playlist model types when constructing server context', function () {
    expect(fn () => new PluginPlaylistQueryContext($this->user, null, []))
        ->toThrow(TypeError::class);
});
