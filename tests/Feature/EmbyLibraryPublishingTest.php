<?php

use App\Models\EmbyLibraryMapping;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('persists a user owned Emby library mapping with normalized lifecycle state', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);

    $mapping = EmbyLibraryMapping::create([
        'media_server_integration_id' => $integration->id,
        'user_id' => $user->id,
        'source_kind' => 'vod_group',
        'source_identifier' => '42',
        'source_label' => 'Action',
        'target_library_id' => 'emby-library-1',
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/media/m3u-editor/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
        'last_planned_revision' => 'revision-1',
        'status_summary' => 'Planned 3 items',
    ]);

    expect($mapping->uuid)->toBeString()->not->toBeEmpty()
        ->and($mapping->enabled)->toBeTrue()
        ->and($mapping->is_managed)->toBeTrue()
        ->and($mapping->options)->toBeArray()
        ->and($mapping->last_success_at)->toBeNull()
        ->and($mapping->integration->is($integration))->toBeTrue()
        ->and($mapping->user->is($user))->toBeTrue();
});

it('rejects a mapping owned by a different user than its integration', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($owner)->createQuietly(['type' => 'emby']);

    expect(fn () => EmbyLibraryMapping::create([
        'media_server_integration_id' => $integration->id,
        'user_id' => $otherUser->id,
        'source_kind' => 'vod_group',
        'source_identifier' => '42',
        'source_label' => 'Action',
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/media/m3u-editor/movies',
        'options' => [],
    ]))->toThrow(ValidationException::class);
});

it('creates a mapping factory with matching integration ownership', function () {
    $mapping = EmbyLibraryMapping::factory()->create();

    expect($mapping->user_id)->toBe($mapping->integration->user_id)
        ->and($mapping->collection_type)->toBeIn(['movies', 'tvshows'])
        ->and($mapping->options)->toBeArray();
});

it('exposes mappings through their integration and owner', function () {
    $mapping = EmbyLibraryMapping::factory()->create();

    expect($mapping->integration->embyLibraryMappings->modelKeys())->toContain($mapping->id)
        ->and($mapping->user->embyLibraryMappings->modelKeys())->toContain($mapping->id);
});

it('authorizes integration users for only their own mappings', function () {
    $mapping = EmbyLibraryMapping::factory()->create();
    $owner = $mapping->user;
    $owner->update(['permissions' => ['use_integrations']]);
    $otherUser = User::factory()->create(['permissions' => ['use_integrations']]);
    $admin = User::factory()->admin()->create();

    expect($owner->can('view', $mapping))->toBeTrue()
        ->and($owner->can('update', $mapping))->toBeTrue()
        ->and($owner->can('delete', $mapping))->toBeTrue()
        ->and($otherUser->can('view', $mapping))->toBeFalse()
        ->and($otherUser->can('update', $mapping))->toBeFalse()
        ->and($otherUser->can('delete', $mapping))->toBeFalse()
        ->and($admin->can('view', $mapping))->toBeTrue();
});

it('allows only one mapping for a source and collection in an integration', function () {
    $mapping = EmbyLibraryMapping::factory()->create();
    $duplicate = $mapping->replicate(['uuid', 'target_library_id', 'target_library_name', 'output_path']);
    $duplicate->target_library_id = fake()->uuid();
    $duplicate->target_library_name = 'Another Library';
    $duplicate->output_path = '/media/m3u-editor/another-library';

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});

it('rejects unsupported collection types', function () {
    $mapping = EmbyLibraryMapping::factory()->make(['collection_type' => 'music']);

    expect(fn () => $mapping->save())->toThrow(ValidationException::class);
});

it('rejects unsupported source kinds', function () {
    $mapping = EmbyLibraryMapping::factory()->make(['source_kind' => 'live_group']);

    expect(fn () => $mapping->save())->toThrow(ValidationException::class);
});

it('rejects unrecognized publishing options', function () {
    $mapping = EmbyLibraryMapping::factory()->make([
        'options' => ['provider_url' => 'https://user:secret@example.com/stream'],
    ]);

    expect(fn () => $mapping->save())->toThrow(ValidationException::class);
});

it('exposes only validated companion writable paths', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $integration->update([
        'emby_publisher_writable_paths' => [
            '/srv/emby/managed',
            '/srv/emby/managed',
            'relative/path',
            'https://user:secret@example.com/path',
            'C:\\Emby\\Managed',
        ],
    ]);

    expect($integration->getEmbyPublisherWritablePaths())->toBe([
        '/srv/emby/managed',
        'C:\\Emby\\Managed',
    ]);
});
