<?php

use App\Models\CachedContentActivityLease;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Episode;
use App\Models\Group;
use App\Models\ManagedCacheEvent;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use App\Services\ManagedCacheCapacitySnapshot;
use App\Services\ManagedCacheCapacitySnapshotSource;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'cache.default' => 'array',
    ]);
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake(CachedContentFile::DISK);

    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    cache()->put("p:{$this->playlist->id}:xtream_status", []);
    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'library_publishing_enabled' => true,
    ]);
    $this->auth->assignTo($this->playlist);
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly([
        'type' => 'emby',
        'api_key' => 'must-not-leak',
    ]);
    $this->mapping = EmbyLibraryMapping::factory()
        ->for($this->user)
        ->for($this->integration, 'integration')
        ->create([
            'source_kind' => 'all',
            'source_identifier' => '*',
            'source_label' => 'All Movies',
            'target_library_name' => 'Managed Movies',
            'output_path' => '/srv/emby/managed/movies',
            'is_managed' => true,
            'last_planned_revision' => str_repeat('a', 64),
            'last_applied_revision' => str_repeat('a', 64),
        ]);

    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->app_output_enabled = true;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
});

function managedCacheActionUrl(array $parameters = []): string
{
    return '/player_api.php?'.http_build_query(array_merge([
        'username' => 'emby-companion',
        'password' => 'companion-secret',
    ], $parameters));
}

it('builds the mapping catalog only once for a batched cache request', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod']);
    Channel::factory()->count(3)->for($this->user)->for($this->playlist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    expect($catalog['items'])->toHaveCount(3);
    $catalogService = Mockery::mock(EmbyPublicationCatalogService::class);
    $catalogService->shouldReceive('buildMapping')->once()->andReturn($catalog);
    app()->instance(EmbyPublicationCatalogService::class, $catalogService);

    $this->postJson(managedCacheActionUrl(), [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => array_map(fn (array $item): array => [
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ], $catalog['items']),
    ])->assertOk()->assertJsonCount(3, 'data.results');
});

it('rejects prefetch without required target fields', function () {
    $this->postJson(managedCacheActionUrl([
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
    ]))->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_request');
});

it('returns deferred when managed cache capacity is unknown', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $response = $this->postJson(managedCacheActionUrl([
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ]));

    $response->assertOk()
        ->assertJsonPath('data.request_id', $response->json('data.request_id'))
        ->assertJsonPath('data.results.0.canonical_id', $item['canonical_id'])
        ->assertJsonPath('data.results.0.variant_key', $item['variants'][0]['key'])
        ->assertJsonPath('data.results.0.status', 'deferred');
});

it('queues a managed prefetch only with an injected authoritative capacity snapshot', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    app()->instance(ManagedCacheCapacitySnapshotSource::class, new class implements ManagedCacheCapacitySnapshotSource
    {
        public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
        {
            return new ManagedCacheCapacitySnapshot('synthetic-account', limit: 3, live: 1, background: 0);
        }
    });

    $item = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'][0];

    $this->postJson(managedCacheActionUrl([
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ]))->assertOk()
        ->assertJsonPath('data.results.0.status', 'queued');

    expect(CachedContentFile::query()->where('managed_by', 'managed_publishing')->exists())->toBeTrue()
        ->and(ManagedCacheInterest::query()->where('consumer_id', 'profile-a')->exists())->toBeTrue();
});

it('rechecks a deferred prefetch with a new request id without replaying an old decision', function (?int $initialLive) {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $source = new class($initialLive) implements ManagedCacheCapacitySnapshotSource
    {
        public function __construct(public ?int $live) {}

        public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
        {
            return $this->live === null ? null : new ManagedCacheCapacitySnapshot('synthetic-retry-account', limit: 3, live: $this->live, background: 0);
        }
    };
    app()->instance(ManagedCacheCapacitySnapshotSource::class, $source);
    $item = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'][0];
    $parameters = [
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];
    $oldRequest = (string) Str::uuid();
    $this->postJson(managedCacheActionUrl([...$parameters, 'request_id' => $oldRequest]))
        ->assertOk()->assertJsonPath('data.results.0.status', 'deferred');
    expect(CachedContentFile::count())->toBe(0)->and(ManagedCacheInterest::count())->toBe(0);

    $source->live = 1;
    $this->postJson(managedCacheActionUrl([...$parameters, 'request_id' => $oldRequest]))
        ->assertOk()->assertJsonPath('data.results.0.status', 'deferred');
    expect(CachedContentFile::count())->toBe(0);
    $this->postJson(managedCacheActionUrl([...$parameters, 'request_id' => (string) Str::uuid()]))
        ->assertOk()->assertJsonPath('data.results.0.status', 'queued');
    expect(CachedContentFile::where('managed_by', 'managed_publishing')->count())->toBe(1)
        ->and(ManagedCacheInterest::count())->toBe(1)
        ->and(ManagedCacheEvent::count())->toBe(2);
    Http::assertNothingSent();
})->with(['unknown capacity' => [null], 'live reserve occupied' => [2]]);

it('accepts the companion form contract with one global consumer id', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $this->post('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk()
        ->assertJsonPath('data.results.0.status', 'deferred');
});

it('records an interest for an existing catalog cache without adopting it', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $file = CachedContentFile::factory()->forItem($channel)->completed()->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'cached');

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk()
        ->assertJsonPath('data.results.0.status', 'already_cached');

    expect(ManagedCacheInterest::query()
        ->where('cached_content_file_id', $file->id)
        ->where('consumer_id', 'profile-a')
        ->exists())->toBeTrue()
        ->and($file->fresh()->managed_by)->toBeNull();
});

it('creates a playback lease from the validated companion session', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $sessionId = (string) Str::uuid();

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_playback',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'session_id' => $sessionId,
        'sequence' => 1,
        'state' => 'start',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ])->assertOk();

    expect(CachedContentActivityLease::query()
        ->where('integration_id', $this->integration->id)
        ->where('lease_id', $sessionId)
        ->where('kind', 'playback')
        ->exists())->toBeTrue();
});

it('renews an active playback lease when an idempotent heartbeat is retried', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $item = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'][0];
    $sessionId = (string) Str::uuid();
    $heartbeat = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_playback',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => (string) Str::uuid(),
        'consumer_id' => 'profile-a',
        'session_id' => $sessionId,
        'sequence' => 1,
        'state' => 'heartbeat',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];

    $this->postJson('/player_api.php', $heartbeat)->assertOk();
    $lease = CachedContentActivityLease::query()->where('lease_id', $sessionId)->sole();
    $lease->update(['expires_at' => now()->addSecond()]);
    $expiryBeforeRetry = $lease->fresh()->expires_at;

    $this->postJson('/player_api.php', $heartbeat)->assertOk();

    expect($lease->fresh()->expires_at->equalTo($expiryBeforeRetry))->toBeTrue();
});

it('releases and undoes a watched managed cache interest through the companion form contract', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);
    $file = CachedContentFile::factory()->forItem($channel)->completed()->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'cached');
    $item = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping)['items'][0];
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'canonical_id' => $item['canonical_id'],
        'variant_key' => $item['variants'][0]['key'],
        'consumer_id' => 'profile-a',
    ]);
    $payload = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_release',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
        'watched' => true,
        'cohort_complete' => true,
        'delete_after_hours' => 0,
    ];

    $this->postJson('/player_api.php', [...$payload, 'request_id' => (string) Str::uuid()])
        ->assertOk();

    expect($interest->fresh()->watched_at)->not->toBeNull()
        ->and($interest->fresh()->release_after)->not->toBeNull();

    $this->postJson('/player_api.php', [...$payload, 'request_id' => (string) Str::uuid(), 'watched' => false])
        ->assertOk();

    expect($interest->fresh()->watched_at)->toBeNull()
        ->and($interest->fresh()->release_after)->toBeNull();
});

it('returns idempotent success for identical prefetch request id and payload', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $requestId = (string) Str::uuid();
    $payload = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => $requestId,
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];

    $first = $this->postJson('/player_api.php', $payload);
    $first->assertOk();

    $second = $this->postJson('/player_api.php', $payload);
    $second->assertOk()
        ->assertJsonPath('data.status', $first->json('data.status'));

    expect(ManagedCacheEvent::count())->toBe(1);
});

it('conflicts when the same request id carries a different payload', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'container_extension' => 'mkv',
    ]);

    $catalog = app(EmbyPublicationCatalogService::class)->buildMapping($this->mapping);
    $item = $catalog['items'][0];
    $requestId = (string) Str::uuid();
    $base = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_cache_prefetch',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => str_repeat('a', 64),
        'request_id' => $requestId,
        'consumer_id' => 'profile-a',
        'targets' => [[
            'canonical_id' => $item['canonical_id'],
            'variant_key' => $item['variants'][0]['key'],
        ]],
    ];

    $this->postJson('/player_api.php', $base)->assertOk();

    $conflict = $base;
    $conflict['consumer_id'] = 'profile-b';

    $this->postJson('/player_api.php', $conflict)
        ->assertConflict()
        ->assertJsonPath('error.code', 'idempotency_conflict');
});
