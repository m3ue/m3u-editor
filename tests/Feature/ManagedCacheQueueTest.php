<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ManagedCacheCapacityService;
use App\Services\ManagedCacheCapacitySnapshot;
use App\Services\ManagedCacheCapacitySnapshotSource;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('cache');
    config(['cache.default' => 'array']);
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $persisted = new GeneralSettings;
    $persisted->enable_cache = true;
    $persisted->save();
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly(['available_streams' => 0]);
    $channel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'is_vod' => true, 'url' => 'http://198.51.100.1/movie/synthetic/account/1.mp4',
    ]);
    $this->file = CachedContentFile::factory()->forItem($channel)->createQuietly([
        'status' => CachedContentFileStatus::Pending,
        'managed_by' => CachedContentManagedBy::ManagedPublishing,
        'source' => 'provider',
    ]);
    $this->snapshots = new class implements ManagedCacheCapacitySnapshotSource
    {
        public ?ManagedCacheCapacitySnapshot $snapshot;

        public int $calls = 0;

        public function __construct()
        {
            $this->snapshot = new ManagedCacheCapacitySnapshot('queue-account', 3, 1, 0);
        }

        public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
        {
            $this->calls++;

            return $this->snapshot;
        }
    };
    app()->instance(ManagedCacheCapacitySnapshotSource::class, $this->snapshots);
});

it('refreshes a resolved worker settings instance before opening a managed source', function () {
    $resolved = new GeneralSettings;
    $resolved->enable_cache = true;
    $resolved->save();
    app()->instance(GeneralSettings::class, $resolved);
    $updated = new GeneralSettings;
    $updated->enable_cache = false;
    $updated->save();
    Http::fake(['http://198.51.100.1/*' => Http::response('synthetic media bytes', 200)]);

    (new DownloadCachedContentFile($this->file->id))->handle();

    expect($this->file->fresh()->status)->toBe(CachedContentFileStatus::Failed)
        ->and($this->snapshots->calls)->toBe(0);
    Http::assertNothingSent();
});

it('reacquires expired prefetch capacity from a fresh snapshot before downloading', function () {
    $capacity = app(ManagedCacheCapacityService::class);
    $reservation = $capacity->reserve($this->snapshots->snapshot);
    $capacity->attachToFile($this->file->id, $reservation);
    $this->travel(121)->seconds();
    Http::fake(['http://198.51.100.1/*' => Http::response('synthetic media bytes', 200)]);

    (new DownloadCachedContentFile($this->file->id))->handle();

    $file = $this->file->fresh();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($this->snapshots->calls)->toBe(1);
    Storage::disk('cache')->assertExists($file->file_path);
    Http::assertSentCount(1);
    expect($capacity->reserve($this->snapshots->snapshot))->not->toBeNull();
});

it('keeps managed downloads pending without opening media until fresh capacity is available', function (string $reason) {
    $capacity = app(ManagedCacheCapacityService::class);
    $busyReservation = null;
    if ($reason === 'unknown') {
        $this->snapshots->snapshot = null;
    } elseif ($reason === 'live-reserve') {
        $this->snapshots->snapshot = new ManagedCacheCapacitySnapshot('queue-account', 3, 2, 0);
    } else {
        $busyReservation = $capacity->reserve($this->snapshots->snapshot);
    }
    $job = (new DownloadCachedContentFile($this->file->id))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(120);
    expect($this->file->fresh()->status)->toBe(CachedContentFileStatus::Pending);
    Http::assertNothingSent();
    Storage::disk('cache')->assertDirectoryEmpty('/');

    // Simulate the next execution of the same queued row after capacity returns.
    $capacity->release($busyReservation);
    $this->snapshots->snapshot = new ManagedCacheCapacitySnapshot('queue-account', 3, 1, 0);
    Http::fake(['http://198.51.100.1/*' => Http::response('synthetic media bytes', 200)]);
    (new DownloadCachedContentFile($this->file->id))->handle();
    expect($this->file->fresh()->status)->toBe(CachedContentFileStatus::Completed);
    Http::assertSentCount(1);
})->with(['unknown', 'live-reserve', 'another-prefetch']);
