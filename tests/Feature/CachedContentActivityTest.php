<?php

use App\Models\CachedContentFile;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\CachedContentActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    config(['cache.default' => 'array']);
});

it('keeps independent playback sessions active and stops only the named lease', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $first = $service->acquirePlayback($user, $integration, '11111111-1111-4111-8111-111111111111', 1, $target);
    $second = $service->acquirePlayback($user, $integration, '22222222-2222-4222-8222-222222222222', 1, $target);

    expect($service->hasActivePlayback($target))->toBeTrue();

    $service->stopPlayback($integration, $first->lease_id, 2);

    expect($service->hasActivePlayback($target))->toBeTrue();

    $service->stopPlayback($integration, $second->lease_id, 2);

    expect($service->hasActivePlayback($target))->toBeFalse();
});

it('does not revive a terminal playback session from a late heartbeat', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $service->acquirePlayback($user, $integration, '11111111-1111-4111-8111-111111111111', 1, $target);
    $service->stopPlayback($integration, '11111111-1111-4111-8111-111111111111', 2);
    $service->acquirePlayback($user, $integration, '11111111-1111-4111-8111-111111111111', 3, $target);

    expect($service->hasActivePlayback($target))->toBeFalse();
});

it('does not renew an active playback lease for a duplicate heartbeat sequence', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $lease = $service->acquirePlayback($user, $integration, '11111111-1111-4111-8111-111111111111', 1, $target);
    $lease->update(['expires_at' => now()->addSecond()]);
    $expiryBeforeRetry = $lease->fresh()->expires_at;

    $service->acquirePlayback($user, $integration, '11111111-1111-4111-8111-111111111111', 1, $target);

    expect($lease->fresh()->expires_at->equalTo($expiryBeforeRetry))->toBeTrue();
});

it('acquires a reader lease and considers it active for the target', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $lease = $service->acquireReader($user, $integration, '33333333-3333-4333-8333-333333333333', $target);

    expect($lease->kind)->toBe('reader')
        ->and($lease->expires_at->isFuture())->toBeTrue()
        ->and($service->hasActiveReader($target))->toBeTrue();
});

it('releases a reader lease on finish', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $lease = $service->acquireReader($user, $integration, '33333333-3333-4333-8333-333333333333', $target);
    $service->releaseReader($lease);

    expect($service->hasActiveReader($target))->toBeFalse();
});

it('serialises reader acquire and delete check under the same file lock', function () {
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $file = CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => 'completed',
        'content_fingerprint' => 'fixture-fingerprint',
    ]);

    $service = app(CachedContentActivityService::class);
    $target = [
        'mapping_uuid' => (string) Str::uuid(),
        'revision' => str_repeat('a', 64),
        'canonical_id' => 'episode:tmdb:550',
        'variant_key' => '1080p',
    ];

    $service->acquireReader($user, $integration, '33333333-3333-4333-8333-333333333333', $target, $file);

    expect($service->hasActiveReaderForFile($file))->toBeTrue()
        ->and($service->isSafeToDelete($file))->toBeFalse();

    $lease = $service->findActiveReaderLeaseForFile($file);
    $service->releaseReader($lease);

    expect($service->isSafeToDelete($file))->toBeTrue();
});

it('runs a deletion recheck under the same file activity lock', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $file = CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => 'completed',
        'content_fingerprint' => 'fixture-fingerprint',
    ]);

    $result = app(CachedContentActivityService::class)->withFileActivityLock($file, function () use ($file): int {
        return $file->id;
    });

    expect($result)->toBe($file->id);
});
