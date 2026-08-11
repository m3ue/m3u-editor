<?php

use App\Jobs\RefreshMediaServerLibraryJob;
use App\Models\Channel;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'cache.default' => 'array',
    ]);
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
        ]);

    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);
});

function embyPublishingActionUrl(string $action, array $parameters = []): string
{
    return '/player_api.php?'.http_build_query(array_merge([
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => $action,
    ], $parameters));
}

it('advertises the versioned managed library publishing contract to authenticated clients', function () {
    $response = $this->getJson(embyPublishingActionUrl('get_server_info'));

    $response->assertOk()
        ->assertJsonPath('m3u_editor.library_publishing.api_version', 1)
        ->assertJsonPath('m3u_editor.library_publishing.actions.register_publisher', 'm3u_editor_register_publisher')
        ->assertJsonPath('m3u_editor.library_publishing.actions.catalog', 'm3u_editor_catalog')
        ->assertJsonPath('m3u_editor.library_publishing.actions.sync_result', 'm3u_editor_sync_result')
        ->assertJsonPath('m3u_editor.library_publishing.snapshot_mode', 'full')
        ->assertJsonPath('m3u_editor.library_publishing.features', [
            'library_mappings',
            'variants',
            'provider_failover',
            'local_nfo',
            'revision_metadata',
        ]);

    expect(json_encode($response->json('m3u_editor.library_publishing')))
        ->not->toContain('must-not-leak')
        ->not->toContain('/srv/emby');
});

it('advertises publisher registration before the first mapping exists', function () {
    $this->mapping->delete();

    $this->getJson(embyPublishingActionUrl('get_server_info'))
        ->assertOk()
        ->assertJsonPath(
            'm3u_editor.library_publishing.actions.register_publisher',
            'm3u_editor_register_publisher',
        );
});

it('rejects invalid companion writable path advertisements', function (mixed $writablePaths) {
    $response = $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_register_publisher',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'writable_paths' => $writablePaths,
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_request');
    expect($this->integration->refresh()->emby_publisher_writable_paths)->toBeNull()
        ->and($this->integration->emby_publisher_capabilities_updated_at)->toBeNull();
})->with([
    'missing list' => null,
    'relative path' => [['relative/path']],
    'traversal path' => [['/srv/emby/managed/../../etc']],
    'traversal path (windows-style)' => [['C:\\emby\\managed\\..\\..\\Windows']],
    'NUL-bearing path' => [["/srv/emby/managed\0/movies"]],
    'overlong path' => [['/'.str_repeat('a', 1024)]],
    'duplicate path' => [['/srv/emby/managed', '/srv/emby/managed']],
    'duplicate normalized path' => [['/srv/emby/managed', ' /srv/emby/managed ']],
    'associative path list' => [['movies' => '/srv/emby/managed']],
    'over-limit list' => [array_map(fn (int $index): string => "/srv/emby/managed/{$index}", range(1, 51))],
]);

it('rejects cross-owner publisher registration', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $foreignIntegration = MediaServerIntegration::factory()->for($otherUser)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => null,
    ]);

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_register_publisher',
        'api_version' => 1,
        'integration_id' => $foreignIntegration->id,
        'writable_paths' => ['/srv/emby/managed/movies'],
    ])->assertNotFound()
        ->assertJsonPath('error.code', 'integration_not_found');

    expect($foreignIntegration->refresh()->emby_publisher_writable_paths)->toBeNull()
        ->and($foreignIntegration->emby_publisher_capabilities_updated_at)->toBeNull();
});

it('denies managed library publishing to playlist credentials without explicit access', function () {
    $this->auth->update(['library_publishing_enabled' => false]);

    $serverInfo = $this->getJson(embyPublishingActionUrl('get_server_info'))->assertOk();
    expect($serverInfo->json('m3u_editor.library_publishing'))->toBeNull();

    $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertForbidden()
        ->assertJsonPath('error.code', 'library_publishing_unavailable');

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_register_publisher',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'writable_paths' => ['/srv/emby/managed/movies'],
    ])->assertForbidden()
        ->assertJsonPath('error.code', 'library_publishing_unavailable');

    expect($this->integration->refresh()->emby_publisher_writable_paths)->toBeNull();
});

it('publishes valid source-owner playback credentials to an opted-in companion', function () {
    config(['app.url' => 'https://m3u-editor.test', 'app.port' => null]);
    $sourcePlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $group = Group::factory()->for($this->user)->for($sourcePlaylist)->create([
        'name' => 'Movies',
        'type' => 'vod',
    ]);
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->for($group)->createQuietly([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Source Movie',
        'container_extension' => 'mkv',
    ]);

    $response = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertOk();
    $playbackUrl = $response->json('mappings.0.items.0.variants.0.preferred.playback_url');

    expect($playbackUrl)
        ->toContain('/movie/'.urlencode($this->user->name).'/'.urlencode($sourcePlaylist->uuid)."/{$channel->id}.mkv")
        ->not->toContain('companion-secret');
});

it('returns the canonical catalog through the authenticated versioned action', function () {
    $response = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]));

    $response->assertOk()
        ->assertJsonPath('api_version', 1)
        ->assertJsonPath('full_snapshot', true)
        ->assertJsonPath('mappings.0.mapping_uuid', $this->mapping->uuid)
        ->assertJsonPath('mappings.0.integration_id', $this->integration->id);

    expect($this->mapping->refresh()->last_planned_revision)
        ->toBe($response->json('mappings.0.revision'))
        ->and(json_encode($response->json()))
        ->not->toContain('must-not-leak');
});

it('rejects unsupported catalog API versions without planning a revision', function () {
    $response = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 2,
    ]));

    $response->assertBadRequest()
        ->assertJsonPath('error.code', 'unsupported_api_version');
    expect($this->mapping->refresh()->last_planned_revision)->toBeNull();
});

it('rejects unauthenticated catalog requests using existing Xtream conventions', function () {
    $response = $this->getJson('/player_api.php?'.http_build_query([
        'username' => 'emby-companion',
        'password' => 'wrong-password',
        'action' => 'm3u_editor_catalog',
        'api_version' => 1,
    ]));

    $response->assertUnauthorized()
        ->assertJsonPath('error', 'Unauthorized');
    expect($this->mapping->refresh()->last_planned_revision)->toBeNull();
});

it('applies an exact successful revision once and requests one Emby refresh', function () {
    Bus::fake();
    $catalog = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertOk()->json();
    $revision = $catalog['mappings'][0]['revision'];
    $payload = [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_sync_result',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => $revision,
        'status' => 'success',
        'summary' => 'Applied 0 items',
    ];

    $this->postJson('/player_api.php', $payload)
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.duplicate', false);

    $mapping = $this->mapping->refresh();
    expect($mapping->last_applied_revision)->toBe($revision)
        ->and($mapping->last_success_at)->not->toBeNull()
        ->and($mapping->status)->toBe('synced')
        ->and($mapping->status_summary)->toBe('Applied 0 items');
    Bus::assertDispatchedTimes(RefreshMediaServerLibraryJob::class, 1);

    $this->postJson('/player_api.php', $payload)
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.duplicate', true);
    Bus::assertDispatchedTimes(RefreshMediaServerLibraryJob::class, 1);
});

it('rejects a stale successful revision without changing applied state', function () {
    Bus::fake();
    $catalog = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertOk()->json();
    $reportedRevision = $catalog['mappings'][0]['revision'];
    $this->mapping->updateQuietly(['last_planned_revision' => str_repeat('b', 64)]);

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_sync_result',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => $reportedRevision,
        'status' => 'success',
    ])->assertConflict()
        ->assertJsonPath('error.code', 'stale_revision');

    expect($this->mapping->refresh()->last_applied_revision)->toBeNull();
    Bus::assertNotDispatched(RefreshMediaServerLibraryJob::class);
});

it('records redacted failed results without applying or refreshing', function () {
    Bus::fake();
    $catalog = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertOk()->json();
    $revision = $catalog['mappings'][0]['revision'];

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_sync_result',
        'api_version' => 1,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => $revision,
        'status' => 'failed',
        'summary' => 'Companion failed',
        'error' => 'POST https://user:secret@provider.invalid token=abc api_key=xyz',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'sync_failed');

    $mapping = $this->mapping->refresh();
    expect($mapping->last_applied_revision)->toBeNull()
        ->and($mapping->status)->toBe('failed')
        ->and($mapping->error_summary)->not->toContain('provider.invalid')
        ->and($mapping->error_summary)->not->toContain('secret')
        ->and($mapping->error_summary)->not->toContain('abc')
        ->and($mapping->error_summary)->not->toContain('xyz');
    Bus::assertNotDispatched(RefreshMediaServerLibraryJob::class);
});

it('rejects cross-integration sync results without changing mapping state', function () {
    Bus::fake();
    $catalog = $this->getJson(embyPublishingActionUrl('m3u_editor_catalog', [
        'api_version' => 1,
    ]))->assertOk()->json();
    $otherIntegration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_sync_result',
        'api_version' => 1,
        'integration_id' => $otherIntegration->id,
        'mapping_uuid' => $this->mapping->uuid,
        'revision' => $catalog['mappings'][0]['revision'],
        'status' => 'success',
    ])->assertNotFound()
        ->assertJsonPath('error.code', 'mapping_not_found');

    expect($this->mapping->refresh()->last_applied_revision)->toBeNull();
    Bus::assertNotDispatched(RefreshMediaServerLibraryJob::class);
});
