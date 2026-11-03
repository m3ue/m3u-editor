<?php

/**
 * DVR disk quota accounting.
 *
 * The quota summed file_size_bytes over every completed row. Recordings that share
 * one file (same title on the same day, before output paths were unique) were
 * counted once per row, so the quota evicted recordings it didn't need to. The
 * storage widget used a different sum again (every row with a size, including
 * recordings retention had already purged), so it disagreed with the quota.
 *
 * The quota now measures each library file once, on disk; files are sparse here so
 * they have their real size without using the space.
 */

declare(strict_types=1);

use App\Enums\DvrRecordingStatus;
use App\Filament\Widgets\DvrStorageOverviewWidget;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\User;
use App\Services\DvrRetentionService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;

beforeEach(function (): void {
    Storage::fake('dvr');
    Queue::fake();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->setting = DvrSetting::factory()->enabled()->for($this->user)->create([
        'storage_disk' => 'dvr',
        'global_disk_quota_gb' => 1,
        'retention_days' => null,
    ]);
    $this->service = app(DvrRetentionService::class);
});

/** A completed recording whose file is $bytes long on disk (unless $onDisk is false). */
function quotaRecording(DvrSetting $setting, User $user, string $path, int $bytes, int $hoursAgo, bool $onDisk = true, ?int $recordedBytes = -1): DvrRecording
{
    $recording = DvrRecording::factory()->completed()->for($setting, 'dvrSetting')->for($user)->create([
        'file_path' => $path,
        'file_size_bytes' => $recordedBytes === -1 ? $bytes : $recordedBytes,
        'actual_end' => now()->subHours($hoursAgo),
    ]);
    if ($onDisk && ! Storage::disk('dvr')->exists($path)) {
        Storage::disk('dvr')->put($path, '');
        truncate(Storage::disk('dvr')->path($path), $bytes);
    }

    return $recording;
}

function truncate(string $file, int $bytes): void
{
    $handle = fopen($file, 'r+');
    ftruncate($handle, $bytes); // sparse: the right size without using the space
    fclose($handle);
}

function widgetBytes(User $user): int
{
    $widget = new DvrStorageOverviewWidget;
    $data = (new ReflectionMethod($widget, 'getViewData'))->invoke($widget);

    return collect($data['rows'])->first(fn (array $row): bool => $row['user']?->is($user))['used_bytes'];
}

it('counts a file shared by two recordings once and evicts nothing when under quota', function (): void {
    // Real usage: 600 MB + 300 MB = 0.9 GB (< 1 GB). Per-row sum: 1.5 GB.
    $shared = 'library/2026/News/News - 2026-11-03.mkv';
    $a = quotaRecording($this->setting, $this->user, $shared, 600_000_000, 5);
    $b = quotaRecording($this->setting, $this->user, $shared, 600_000_000, 4);
    $c = quotaRecording($this->setting, $this->user, 'library/2026/Film/Film - 2026-11-03.mkv', 300_000_000, 3);

    expect($this->service->libraryUsageBytes($this->setting))->toBe(900_000_000);

    $this->service->runForSetting($this->setting);

    foreach ([$a, $b, $c] as $recording) {
        expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    }
});

it('does not evict other recordings because of a file that is missing from disk', function (): void {
    // Usage by the records: 800 MB + 500 MB = 1.3 GB > 1 GB, but the 800 MB file is gone.
    $missing = quotaRecording($this->setting, $this->user, 'library/2026/Gone/Gone - 2026-11-01.mkv', 800_000_000, 10, onDisk: false);
    $kept = quotaRecording($this->setting, $this->user, 'library/2026/Here/Here - 2026-11-02.mkv', 500_000_000, 5);

    $this->service->runForSetting($this->setting);

    expect($kept->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($missing->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('evicts and purges nothing when the storage is unavailable', function (): void {
    // e.g. the network share isn't mounted: every file looks missing.
    $a = quotaRecording($this->setting, $this->user, 'library/2026/A/A - 2026-11-01.mkv', 800_000_000, 10, onDisk: false);
    $b = quotaRecording($this->setting, $this->user, 'library/2026/B/B - 2026-11-02.mkv', 700_000_000, 5, onDisk: false);

    $this->service->runForSetting($this->setting);

    expect($a->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($b->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('evicts a shared file as one unit: every recording on it is purged and the space freed once', function (): void {
    // Real usage: 700 MB (shared, oldest) + 600 MB = 1.3 GB > 1 GB. Freeing the
    // shared file alone brings it to 0.6 GB, so the newer recording must survive.
    $shared = 'library/2026/News/News - 2026-11-02.mkv';
    $a = quotaRecording($this->setting, $this->user, $shared, 700_000_000, 30);
    $b = quotaRecording($this->setting, $this->user, $shared, 700_000_000, 29);
    $newer = quotaRecording($this->setting, $this->user, 'library/2026/Film/Film - 2026-11-03.mkv', 600_000_000, 1);

    $this->service->runForSetting($this->setting);

    expect($a->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($b->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($newer->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertMissing($shared);
    Storage::disk('dvr')->assertExists($newer->file_path);
});

it('excludes purged recordings from the usage figure', function (): void {
    quotaRecording($this->setting, $this->user, 'library/2026/A/A - 2026-11-03.mkv', 100_000_000, 2);
    DvrRecording::factory()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'status' => DvrRecordingStatus::Purged,
        'file_path' => null,
        'file_size_bytes' => 900_000_000,
    ]);

    expect($this->service->libraryUsageBytes($this->setting))->toBe(100_000_000);
});

it('shows the storage widget the same figure the quota measures', function (): void {
    $shared = 'library/2026/News/News - 2026-11-03.mkv';
    quotaRecording($this->setting, $this->user, $shared, 400_000_000, 3);
    quotaRecording($this->setting, $this->user, $shared, 400_000_000, 2);
    DvrRecording::factory()->for($this->setting, 'dvrSetting')->for($this->user)->create([
        'status' => DvrRecordingStatus::Purged,
        'file_path' => null,
        'file_size_bytes' => 900_000_000,
    ]);

    expect(widgetBytes($this->user))->toBe(400_000_000)
        ->and($this->service->libraryUsageBytes($this->setting))->toBe(400_000_000);
});

// --- review follow-up: sizes that are null or stale, and partially available storage ---

it('measures the file on disk when its newest recording has a null or smaller size', function (): void {
    // On disk: 700 MB (shared, oldest) + 500 MB = 1.2 GB > 1 GB. The newer row on the
    // shared file recorded no size; taking the newest row's figure saw only 0.5 GB.
    $shared = 'library/2026/News/News - 2026-11-02.mkv';
    $a = quotaRecording($this->setting, $this->user, $shared, 700_000_000, 30);
    $b = quotaRecording($this->setting, $this->user, $shared, 700_000_000, 29, recordedBytes: null);
    $newer = quotaRecording($this->setting, $this->user, 'library/2026/Film/Film - 2026-11-03.mkv', 500_000_000, 1, recordedBytes: 1_000);

    expect($this->service->libraryUsageBytes($this->setting))->toBe(1_200_000_000);

    $this->service->runForSetting($this->setting);

    expect($a->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($b->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($newer->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('shows the largest recorded size of a file in the widget when a newer row has none', function (): void {
    $shared = 'library/2026/News/News - 2026-11-02.mkv';
    quotaRecording($this->setting, $this->user, $shared, 700_000_000, 30);
    quotaRecording($this->setting, $this->user, $shared, 700_000_000, 29, recordedBytes: null);

    expect(widgetBytes($this->user))->toBe(700_000_000);
});

it('leaves missing files out before evicting, so present ones are not over-evicted', function (): void {
    // On disk: 400 MB + 400 MB = 0.8 GB, under quota. The newest file (400 MB recorded)
    // is missing; counting it until the loop reached it made the total 1.2 GB and evicted
    // the oldest present recording for nothing.
    $oldest = quotaRecording($this->setting, $this->user, 'library/2026/A/A - 2026-11-01.mkv', 400_000_000, 30);
    $middle = quotaRecording($this->setting, $this->user, 'library/2026/B/B - 2026-11-02.mkv', 400_000_000, 20);
    $missing = quotaRecording($this->setting, $this->user, 'library/2026/C/C - 2026-11-03.mkv', 400_000_000, 10, onDisk: false);

    $this->service->runForSetting($this->setting);

    foreach ([$oldest, $middle, $missing] as $recording) {
        expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    }
    Storage::disk('dvr')->assertExists($oldest->file_path);
});

it('evicts nothing when most of the library is missing but some files still appear (partial mount)', function (): void {
    // Only the oldest file is visible; on its own it is over quota. With two of three
    // files missing the storage is not trustworthy, so nothing is deleted.
    $visible = quotaRecording($this->setting, $this->user, 'library/2026/A/A - 2026-11-01.mkv', 1_200_000_000, 30);
    quotaRecording($this->setting, $this->user, 'library/2026/B/B - 2026-11-02.mkv', 400_000_000, 20, onDisk: false);
    quotaRecording($this->setting, $this->user, 'library/2026/C/C - 2026-11-03.mkv', 400_000_000, 10, onDisk: false);

    $this->service->runForSetting($this->setting);

    expect($visible->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertExists($visible->file_path);
});

// --- second review: a delete that fails must not count as freed space ---------------

/** Swap the dvr disk for one whose file deletes fail silently ('throw' => false), files kept. */
function refuseDeletes(): void
{
    $root = Storage::disk('dvr')->path('');
    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public function delete(string $path): void
        {
            throw UnableToDeleteFile::atLocation($path, 'Permission denied');
        }
    };
    Storage::set('dvr', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false]));
}

it('keeps a recording whose file could not be deleted, and does not count its space as freed', function (): void {
    // 800 MB + 500 MB = 1.3 GB > 1 GB. The oldest file can't be deleted.
    $stuck = quotaRecording($this->setting, $this->user, 'library/2026/A/A - 2026-11-01.mkv', 800_000_000, 30);
    $newer = quotaRecording($this->setting, $this->user, 'library/2026/B/B - 2026-11-02.mkv', 500_000_000, 5);
    refuseDeletes();

    $this->service->runForSetting($this->setting);

    expect($stuck->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($stuck->fresh()->file_path)->toBe('library/2026/A/A - 2026-11-01.mkv');
    Storage::disk('dvr')->assertExists($stuck->file_path);
    // Its space wasn't freed, so the quota went on to the next file (which also can't go).
    expect($this->service->libraryUsageBytes($this->setting))->toBe(1_300_000_000)
        ->and($newer->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('keeps a usable reference to a shared file that could not be deleted', function (): void {
    $shared = 'library/2026/News/News - 2026-11-02.mkv';
    $a = quotaRecording($this->setting, $this->user, $shared, 1_200_000_000, 30);
    $b = quotaRecording($this->setting, $this->user, $shared, 1_200_000_000, 29);
    refuseDeletes();

    $this->service->runForSetting($this->setting);

    Storage::disk('dvr')->assertExists($shared);
    $usable = DvrRecording::whereIn('id', [$a->id, $b->id])->where('status', DvrRecordingStatus::Completed)->where('file_path', $shared);
    expect($usable->count())->toBeGreaterThanOrEqual(1)
        ->and($this->service->libraryUsageBytes($this->setting))->toBe(1_200_000_000);
});
