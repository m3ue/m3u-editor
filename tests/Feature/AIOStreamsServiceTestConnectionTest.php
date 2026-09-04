<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\AIOStreamsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeAioIntegration(): MediaServerIntegration
{
    $user = User::factory()->create();

    return MediaServerIntegration::create([
        'user_id' => $user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
    ]);
}

it('skips malformed catalog entries instead of throwing', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'catalogs' => [
                ['id' => 'movie.popular', 'type' => 'movie', 'name' => 'Popular Movies'],
                ['type' => 'movie', 'name' => 'Missing Id'],
                ['id' => 'series.trending', 'name' => 'Missing Type'],
                ['id' => 'series.other', 'type' => 'series'],
                'not-an-array',
            ],
        ], 200),
    ]);

    $result = AIOStreamsService::make($integration)->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['catalogs'])->toBe(1)
        ->and($integration->aiostreams_catalogs)->toHaveCount(1)
        ->and($integration->aiostreams_catalogs[0]['id'])->toBe('movie.popular');
});

it('reports all valid catalogs when the manifest is well-formed', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'catalogs' => [
                ['id' => 'movie.popular', 'type' => 'movie', 'name' => 'Popular Movies'],
                ['id' => 'series.trending', 'type' => 'series', 'name' => 'Trending Series'],
            ],
        ], 200),
    ]);

    $result = AIOStreamsService::make($integration)->testConnection();

    expect($result['catalogs'])->toBe(2)
        ->and($integration->aiostreams_catalogs)->toHaveCount(2);
});

// ── aiostreams_meta_id_prefixes: computed from the manifest's `meta` resource
// declaration so fetchMeta() can skip a guaranteed-404 call instead of always
// trying AIOStreams first and falling back after the fact.

it('stores an empty meta id prefix list when the manifest declares no meta resource', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'resources' => ['catalog', 'stream'],
        ], 200),
    ]);

    AIOStreamsService::make($integration)->testConnection();

    expect($integration->aiostreams_meta_id_prefixes)->toBe([]);
});

it('stores the top-level idPrefixes for a string-form meta resource', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'resources' => ['catalog', 'meta', 'stream'],
            'idPrefixes' => ['tt'],
        ], 200),
    ]);

    AIOStreamsService::make($integration)->testConnection();

    expect($integration->aiostreams_meta_id_prefixes)->toBe(['tt']);
});

it('stores the per-resource idPrefixes for an object-form meta resource', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'resources' => [
                'catalog',
                ['name' => 'meta', 'types' => ['movie', 'series'], 'idPrefixes' => ['tt', 'kitsu:']],
            ],
        ], 200),
    ]);

    AIOStreamsService::make($integration)->testConnection();

    expect($integration->aiostreams_meta_id_prefixes)->toBe(['tt', 'kitsu:']);
});

it('stores a wildcard when the manifest declares meta without restricting idPrefixes', function () {
    $integration = makeAioIntegration();

    Http::fake([
        'aiostreams.test/*' => Http::response([
            'id' => 'aiostreams',
            'name' => 'AIOStreams',
            'version' => '1.0.0',
            'resources' => ['catalog', 'meta', 'stream'],
        ], 200),
    ]);

    AIOStreamsService::make($integration)->testConnection();

    expect($integration->aiostreams_meta_id_prefixes)->toBe(['*']);
});
