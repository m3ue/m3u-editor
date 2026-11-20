<?php

use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\CachedContentActivityService;
use App\Services\CachedContentDispatchService;
use App\Services\CachedContentRetentionService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('cache');
    config(['cache.default' => 'array']);
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->createQuietly(['type' => 'emby']);
    $this->file = CachedContentFile::factory()->for($this->playlist)->createQuietly([
        'status' => 'completed', 'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider', 'disk' => 'cache', 'file_path' => 'review/movie.mp4',
        'content_fingerprint' => 'review-fingerprint',
    ]);
    Storage::disk('cache')->put($this->file->file_path, 'review-bytes');
    $this->interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $this->file->id, 'integration_id' => $this->integration->id,
        'mapping_uuid' => (string) Str::uuid(), 'canonical_id' => 'episode:review:s01e01',
        'variant_key' => '1080p', 'consumer_id' => 'review-cohort',
        'watched_at' => now()->subHour(), 'release_after' => now()->subMinute(),
    ]);
    $this->target = ['mapping_uuid' => $this->interest->mapping_uuid, 'revision' => str_repeat('a', 64),
        'canonical_id' => $this->interest->canonical_id, 'variant_key' => $this->interest->variant_key];
});

it('keeps an unreleased reader protected after 31 seconds', function () {
    $activity = app(CachedContentActivityService::class);
    $lease = $activity->acquireReaderForFile($this->user, $this->integration, (string) Str::uuid(), $this->file);
    $this->travel(31)->seconds();
    expect(app(CachedContentRetentionService::class)->deleteManagedFileIfReleasable($this->file))->toBeFalse();
});

it('restarts the 15 minute grace after playback stop', function () {
    $activity = app(CachedContentActivityService::class);
    $session = (string) Str::uuid();
    $activity->acquirePlayback($this->user, $this->integration, $session, 1, $this->target);
    expect(app(CachedContentRetentionService::class)->deleteManagedFileIfReleasable($this->file))->toBeFalse();
    $activity->stopPlayback($this->integration, $session, 2);
    expect(app(CachedContentRetentionService::class)->deleteManagedFileIfReleasable($this->file))->toBeFalse();
});

it('renews an equal playback sequence without changing its identity', function () {
    $activity = app(CachedContentActivityService::class);
    $session = (string) Str::uuid();
    $lease = $activity->acquirePlayback($this->user, $this->integration, $session, 1, $this->target);
    $before = $lease->expires_at->timestamp;
    $this->travel(60)->seconds();
    $activity->acquirePlayback($this->user, $this->integration, $session, 1, $this->target);
    expect($lease->fresh()->expires_at->timestamp)->toBeGreaterThan($before);
});

it('preserves managed ownership when pending file receives another managed dispatch', function () {
    $persisted = new GeneralSettings;
    $persisted->enable_cache = true;
    $persisted->save();
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->createQuietly([
        'is_vod' => true, 'url' => 'https://provider.test/movie/u/p/1.mp4',
    ]);
    $file = CachedContentFile::factory()->forItem($channel)->createQuietly([
        'status' => 'pending', 'managed_by' => CachedContentManagedBy::ManagedPublishing,
    ]);
    expect(app(CachedContentDispatchService::class)->dispatchManaged($channel))->toBe(CacheDispatchResult::AlreadyQueued);
    expect($file->fresh()->managed_by)->toBe(CachedContentManagedBy::ManagedPublishing);
});

it('preserves unsaved legacy settings without database queries', function (): void {
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
    DB::enableQueryLog();
    DB::flushQueryLog();
    for ($item = 0; $item < 10; $item++) {
        expect(app(CachedContentDispatchService::class)->isEnabled())->toBeTrue();
    }
    config(['cached_content.enabled' => false]);
    expect(app(CachedContentDispatchService::class)->isEnabled())->toBeFalse()
        ->and(app(CachedContentDispatchService::class)->isManagedEnabled())->toBeFalse()
        ->and(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();
});

it('sees current feature off despite an already resolved settings instance', function () {
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    $settings->save();
    app()->instance(GeneralSettings::class, $settings);
    $updated = new GeneralSettings;
    $updated->enable_cache = false;
    $updated->save();
    expect(app(CachedContentDispatchService::class)->isManagedEnabled())->toBeFalse()
        ->and(app(CachedContentDispatchService::class)->isEnabled())->toBeTrue();
});
