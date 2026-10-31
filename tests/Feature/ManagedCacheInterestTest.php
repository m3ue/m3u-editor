<?php

use App\Models\CachedContentFile;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Services\ManagedCacheControlService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

it('persists a managed cache interest with foreign keys', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);

    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect($interest->exists)->toBeTrue()
        ->and($interest->cached_content_file_id)->toBe($file->id)
        ->and($interest->integration_id)->toBe($integration->id);
});

it('enforces unique interest per file/integration/mapping/consumer tuple', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $uuid = (string) Str::uuid();

    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => $uuid,
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect(fn () => ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => $uuid,
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows duplicate consumers across different files', function () {
    $integration = MediaServerIntegration::factory()->create();
    $fileA = CachedContentFile::factory()->completed()->create();
    $fileB = CachedContentFile::factory()->completed()->create();

    ManagedCacheInterest::create([
        'cached_content_file_id' => $fileA->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $second = ManagedCacheInterest::create([
        'cached_content_file_id' => $fileB->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect($second->exists)->toBeTrue();
});

it('deletes interest metadata without deleting the cached file', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $interest->delete();

    expect(ManagedCacheInterest::find($interest->id))->toBeNull()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();
});

it('cascades interest cleanup when the integration is deleted', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $integration->delete();

    expect(ManagedCacheInterest::find($interest->id))->toBeNull()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();
});

it('records an interest on the cache file without changing its owner', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);

    $interest = app(ManagedCacheControlService::class)->recordInterest(
        $file,
        $integration,
        (string) Str::uuid(),
        'movie:tmdb:550',
        '1080p',
        'profile-a',
    );

    expect($interest->cached_content_file_id)->toBe($file->id)
        ->and($interest->integration_id)->toBe($integration->id)
        ->and($file->fresh()->managed_by)->toBeNull();
});
