<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Jobs\ReleaseWatchedManagedCache;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\CachedContentActivityService;
use App\Services\CachedContentRetentionService;
use App\Services\ManagedCacheControlService;
use App\Settings\GeneralSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('cache');
    config(['cache.default' => 'array']);
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->shouldReceive('refresh')->zeroOrMoreTimes()->andReturnSelf();
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
});

function makeManagedFile(Playlist $playlist): CachedContentFile
{
    return CachedContentFile::factory()->for($playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => 'managed/movie.mp4',
        'file_size_bytes' => 11,
        'content_fingerprint' => 'fixture-fingerprint',
    ]);
}

it('reclaims never-watched managed orphans through the generic cleanup path', function (bool $withInterest): void {
    $file = makeManagedFile($this->playlist);
    $file->update(['cacheable_type' => Channel::class, 'cacheable_id' => 999999999]);
    Storage::disk('cache')->put($file->file_path, 'owned cache');
    if ($withInterest) {
        ManagedCacheInterest::factory()->create(['cached_content_file_id' => $file->id, 'integration_id' => $this->integration->id, 'mapping_uuid' => (string) Str::uuid(), 'canonical_id' => 'episode:orphan', 'variant_key' => '1080p', 'consumer_id' => 'profile-a']);
    }
    $retention = app(CachedContentRetentionService::class);
    $ids = $retention->evaluate();
    expect($ids->contains($file->id))->toBeTrue()
        ->and($retention->deleteIds($ids))->toBe(1)
        ->and($file->fresh())->toBeNull();
    Storage::disk('cache')->assertMissing($file->file_path);
})->with([true, false]);

it('does not use orphan cleanup to delete a still-referenced unwatched file', function (): void {
    $file = makeManagedFile($this->playlist);
    $channel = Channel::factory()->createQuietly(['playlist_id' => $this->playlist->id, 'user_id' => $this->user->id]);
    $file->update(['cacheable_type' => $channel->getMorphClass(), 'cacheable_id' => $channel->id]);
    Storage::disk('cache')->put($file->file_path, 'owned cache');
    expect(app(CachedContentRetentionService::class)->deleteIds(collect([$file->id])))->toBe(0);
    Storage::disk('cache')->assertExists($file->file_path);
});

it('rechecks ownership and sharing inside its storage deletion claim', function (string $protection) {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put($file->file_path, 'owned cache');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subMinute(),
    ]);
    $activity = Mockery::mock(CachedContentActivityService::class)->makePartial();
    $activity->shouldReceive('isSafeToDelete')->once()->andReturnUsing(function () use ($file, $protection): bool {
        if ($protection === 'keep') {
            expect($file->fresh()->keep())->toBeTrue();
        } else {
            $this->playlist->update(['share_cache_across_playlists' => true]);
        }

        return true;
    });
    app()->instance(CachedContentActivityService::class, $activity);

    expect(app(CachedContentRetentionService::class)->deleteManagedFileIfReleasable($file))->toBeFalse()
        ->and($file->fresh())->not->toBeNull();
    Storage::disk('cache')->assertExists($file->file_path);
})->with(['manual keep' => ['keep'], 'shared playlist' => ['share']]);

it('does not report a stale deleted file as successfully kept', function () {
    $file = makeManagedFile($this->playlist);
    CachedContentFile::query()->whereKey($file->id)->delete();

    expect($file->keep())->toBeFalse();
});

it('sets release_after when cohort is complete and watched', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    app(ManagedCacheControlService::class)->releaseWatched(
        $this->user,
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: true,
        deleteAfterHours: 0,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->not->toBeNull()
        ->and($interest->release_after)->not->toBeNull()
        ->and($interest->release_after->isFuture())->toBeTrue();
});

it('does not let another user undo a watched managed cache interest', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->addMinutes(15),
    ]);

    app(ManagedCacheControlService::class)->releaseWatched(
        User::factory()->create(),
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: false,
        deleteAfterHours: 24,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->not->toBeNull()
        ->and($interest->release_after)->not->toBeNull();
});

it('cancels release when watched is false', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(ManagedCacheControlService::class)->releaseWatched(
        $this->user,
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: false,
        deleteAfterHours: 24,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->toBeNull()
        ->and($interest->release_after)->toBeNull();
});

it('serializes a watched undo with deletion for its managed file', function () {
    $file = makeManagedFile($this->playlist);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);
    $activity = Mockery::mock(CachedContentActivityService::class);
    $activity->shouldReceive('withFileActivityLock')
        ->once()
        ->with(
            Mockery::on(fn (CachedContentFile $lockedFile): bool => $lockedFile->is($file)),
            Mockery::type('callable'),
        )
        ->andReturnUsing(fn (CachedContentFile $_, callable $callback): mixed => $callback());
    app()->instance(CachedContentActivityService::class, $activity);

    app(ManagedCacheControlService::class)->releaseWatched(
        $this->user,
        $this->integration,
        $interest->mapping_uuid,
        $interest->canonical_id,
        $interest->variant_key,
        $interest->consumer_id,
        watched: false,
        deleteAfterHours: 24,
        cohortComplete: true,
    );

    $interest->refresh();
    expect($interest->watched_at)->toBeNull()
        ->and($interest->release_after)->toBeNull();
});

it('does not delete a managed file while a reader lease is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(CachedContentActivityService::class)->acquireReaderForFile(
        $this->user,
        $this->integration,
        '33333333-3333-4333-8333-333333333333',
        $file,
    );

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('rechecks an active reader lease before a direct managed deletion', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(CachedContentActivityService::class)->acquireReaderForFile(
        $this->user,
        $this->integration,
        '44444444-4444-4444-8444-444444444444',
        $file,
    );

    expect(app(CachedContentRetentionService::class)->deleteManagedFileIfReleasable($file))->toBeFalse()
        ->and(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a managed file that its playlist offers as a shared cache', function () {
    $this->playlist->update(['share_cache_across_playlists' => true]);
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a managed file while a playback lease for its interest target is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);
    app(CachedContentActivityService::class)->acquirePlayback(
        $this->user,
        $this->integration,
        '55555555-5555-4555-8555-555555555555',
        1,
        [
            'mapping_uuid' => $interest->mapping_uuid,
            'revision' => str_repeat('a', 64),
            'canonical_id' => $interest->canonical_id,
            'variant_key' => $interest->variant_key,
        ],
    );

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('does not delete a releasable managed file while cache is disabled at execution', function () {
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->shouldReceive('refresh')->once()->andReturnSelf();
    $settings->enable_cache = false;
    app()->instance(GeneralSettings::class, $settings);

    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    app(ReleaseWatchedManagedCache::class)->handle(app(CachedContentRetentionService::class));

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('deletes a managed file when all interests are released and no lease is active', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeFalse();
});

it('keeps a managed file when not all interests are released', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-b',
        'watched_at' => null,
        'release_after' => null,
    ]);

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('keeps non-managed files untouched', function () {
    $file = CachedContentFile::factory()->for($this->playlist)->createQuietly([
        'status' => CachedContentFileStatus::Completed,
        'managed_by' => null,
        'source' => 'provider',
        'disk' => 'cache',
        'file_path' => 'manual/movie.mp4',
        'file_size_bytes' => 11,
        'content_fingerprint' => 'fixture-fingerprint',
    ]);
    Storage::disk('cache')->put('manual/movie.mp4', 'video-bytes');

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('manual/movie.mp4'))->toBeTrue();
});

it('does not let generic retention delete managed publishing files', function () {
    $file = makeManagedFile($this->playlist);
    Storage::disk('cache')->put('managed/movie.mp4', 'video-bytes');

    app(CachedContentRetentionService::class)->deleteIds(collect([$file->id]));

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(Storage::disk('cache')->exists('managed/movie.mp4'))->toBeTrue();
});

it('retains managed metadata when the storage delete reports failure', function () {
    $file = makeManagedFile($this->playlist);
    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $this->integration->id,
        'mapping_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'canonical_id' => 'episode:fixture:s01e01',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
        'watched_at' => now()->subHour(),
        'release_after' => now()->subHour(),
    ]);

    Storage::shouldReceive('disk')
        ->once()
        ->with('cache')
        ->andReturn(Mockery::mock(Filesystem::class, function ($disk): void {
            $disk->shouldReceive('delete')->once()->with('managed/movie.mp4')->andReturnFalse();
        }));

    dispatch_sync(new ReleaseWatchedManagedCache);

    expect(CachedContentFile::find($file->id))->not->toBeNull()
        ->and(ManagedCacheInterest::query()->where('cached_content_file_id', $file->id)->exists())->toBeTrue();
});

it('schedules watched managed cache release every minute without overlapping', function () {
    $schedule = new Schedule;

    app()->instance(Schedule::class, $schedule);
    Facade::clearResolvedInstance(Schedule::class);

    require base_path('routes/console.php');

    $event = collect($schedule->events())
        ->firstWhere('description', ReleaseWatchedManagedCache::class);

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
