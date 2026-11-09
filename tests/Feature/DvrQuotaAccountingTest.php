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
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\DvrRetentionService;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
    // Its space wasn't freed, and the newer recording isn't evicted to make up for it.
    expect($this->service->libraryUsageBytes($this->setting))->toBe(1_300_000_000)
        ->and($newer->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

// --- third review ---------------------------------------------------------------------

/** Like refuseDeletes(), but only the given path can't be deleted. */
function refuseDeleteOf(string $refusedPath): void
{
    $root = Storage::disk('dvr')->path('');
    $adapter = new class($root) extends LocalFilesystemAdapter
    {
        public string $refusedPath = '';

        public function delete(string $path): void
        {
            if ($path === $this->refusedPath) {
                throw UnableToDeleteFile::atLocation($path, 'Permission denied');
            }
            parent::delete($path);
        }
    };
    $adapter->refusedPath = $refusedPath;
    Storage::set('dvr', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false]));
}

it('does not evict other recordings to make up for a file it cannot delete', function (): void {
    $stuck = quotaRecording($this->setting, $this->user, 'library/2026/S/S.mkv', 1_200_000_000, 50);
    $a = quotaRecording($this->setting, $this->user, 'library/2026/A/A.mkv', 100_000_000, 40);
    $b = quotaRecording($this->setting, $this->user, 'library/2026/B/B.mkv', 100_000_000, 1);
    refuseDeleteOf('library/2026/S/S.mkv');

    $this->service->runForSetting($this->setting);

    expect($stuck->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($a->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($b->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertExists($a->file_path);
    Storage::disk('dvr')->assertExists($b->file_path);
});

it('keeps every row on a shared file that could not be deleted', function (): void {
    $shared = 'library/2026/News/News - 2026-11-02.mkv';
    $rows = collect([30, 29, 28])->map(fn (int $hours) => quotaRecording($this->setting, $this->user, $shared, 1_200_000_000, $hours));
    refuseDeletes();

    $this->service->runForSetting($this->setting);

    $rows->each(fn (DvrRecording $row) => expect($row->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($row->fresh()->file_path)->toBe($shared));
});

it('evicts a file whose other row is failed, instead of counting it as shared', function (): void {
    $path = 'library/2026/F/F.mkv';
    $completed = quotaRecording($this->setting, $this->user, $path, 1_200_000_000, 30);
    $failed = quotaRecording($this->setting, $this->user, $path, 1_200_000_000, 29);
    $failed->update(['status' => DvrRecordingStatus::Failed->value]);

    $this->service->runForSetting($this->setting);

    expect($completed->fresh()->status)->toBe(DvrRecordingStatus::Purged);
    Storage::disk('dvr')->assertMissing($path);
});

it('keeps a file another setting on the same disk still uses', function (): void {
    $path = 'library/2026/N/N.mkv';
    $mine = quotaRecording($this->setting, $this->user, $path, 1_200_000_000, 30);
    $otherSetting = DvrSetting::factory()->enabled()->for($this->user)->create(['storage_disk' => 'dvr', 'global_disk_quota_gb' => null]);
    $theirs = quotaRecording($otherSetting, $this->user, $path, 1_200_000_000, 30);

    $this->service->runForSetting($this->setting);

    expect($mine->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($theirs->fresh()->status)->toBe(DvrRecordingStatus::Completed);
    Storage::disk('dvr')->assertExists($path);
});

it('does not treat the same path on another disk as shared', function (): void {
    $path = 'library/2026/N/N.mkv';
    $mine = quotaRecording($this->setting, $this->user, $path, 1_200_000_000, 30);
    $otherSetting = DvrSetting::factory()->enabled()->for($this->user)->create(['storage_disk' => 'elsewhere', 'global_disk_quota_gb' => null]);
    DvrRecording::factory()->completed()->for($otherSetting, 'dvrSetting')->for($this->user)->create(['file_path' => $path]);

    $this->service->runForSetting($this->setting);

    expect($mine->fresh()->status)->toBe(DvrRecordingStatus::Purged);
    Storage::disk('dvr')->assertMissing($path);
});

it('enforces the quota again once missing files have stayed missing past the grace period', function (): void {
    $present = quotaRecording($this->setting, $this->user, 'library/2026/P/P.mkv', 5_000_000_000, 10);
    $gone = quotaRecording($this->setting, $this->user, 'library/2026/G/G.mkv', 100_000_000, 20, onDisk: false);

    // Within the grace period the guard holds: this could still be a partial mount.
    $this->service->runForSetting($this->setting);
    $this->travel(6)->days();
    $this->service->runForSetting($this->setting);

    expect($present->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($gone->fresh()->status)->toBe(DvrRecordingStatus::Completed);

    $this->travel(2)->days();
    $this->service->runForSetting($this->setting);

    expect($gone->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($gone->fresh()->file_path)->toBeNull()
        ->and($present->fresh()->status)->toBe(DvrRecordingStatus::Purged);
});

it('never purges rows while no library file is present, however long that lasts', function (): void {
    $a = quotaRecording($this->setting, $this->user, 'library/2026/A/A.mkv', 800_000_000, 20, onDisk: false);
    $b = quotaRecording($this->setting, $this->user, 'library/2026/B/B.mkv', 800_000_000, 10, onDisk: false);

    $this->service->runForSetting($this->setting);
    $this->travel(30)->days();
    $this->service->runForSetting($this->setting);

    expect($a->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($b->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('restarts the grace period when a missing file comes back', function (): void {
    $present = quotaRecording($this->setting, $this->user, 'library/2026/P/P.mkv', 100_000_000, 10);
    $flaky = quotaRecording($this->setting, $this->user, 'library/2026/F/F.mkv', 100_000_000, 20, onDisk: false);

    $this->service->runForSetting($this->setting);
    expect($flaky->fresh()->file_missing_since)->not->toBeNull();

    Storage::disk('dvr')->put($flaky->file_path, 'back');
    $this->travel(8)->days();
    $this->service->runForSetting($this->setting);

    expect($flaky->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($flaky->fresh()->file_missing_since)->toBeNull()
        ->and($present->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('tells the owner once when files go missing', function (): void {
    Notification::fake();
    quotaRecording($this->setting, $this->user, 'library/2026/P/P.mkv', 100_000_000, 10);
    quotaRecording($this->setting, $this->user, 'library/2026/G/G.mkv', 100_000_000, 20, onDisk: false);

    $this->service->runForSetting($this->setting);
    $this->service->runForSetting($this->setting);

    Notification::assertSentToTimes($this->user, DatabaseNotification::class, 1);
});

it('stops counting a missing file in the widget, matching the quota', function (): void {
    quotaRecording($this->setting, $this->user, 'library/2026/P/P.mkv', 100_000_000, 10);
    quotaRecording($this->setting, $this->user, 'library/2026/Q/Q.mkv', 100_000_000, 15);
    quotaRecording($this->setting, $this->user, 'library/2026/G/G.mkv', 800_000_000, 20, onDisk: false);

    expect(widgetBytes($this->user))->toBe(1_000_000_000);

    $this->service->runForSetting($this->setting);

    expect(widgetBytes($this->user))->toBe(200_000_000)
        ->and($this->service->libraryUsageBytes($this->setting))->toBe(200_000_000);
});

it('evicts oldest by instant across a DST change, and a recording with no end time last', function (): void {
    config(['app.timezone' => 'America/Denver']);
    $this->setting->update(['global_disk_quota_gb' => 1]);

    // 2026-11-01 fall-back: 07:50Z is 01:50-06:00 and 08:10Z is 01:10-07:00.
    $older = quotaRecording($this->setting, $this->user, 'library/2026/O/O.mkv', 700_000_000, 1);
    $older->update(['actual_end' => '2026-11-01 07:50:00+00:00']);
    $newer = quotaRecording($this->setting, $this->user, 'library/2026/N/N.mkv', 700_000_000, 1);
    $newer->update(['actual_end' => '2026-11-01 08:10:00+00:00']);
    $noEnd = quotaRecording($this->setting, $this->user, 'library/2026/X/X.mkv', 100_000_000, 1);
    DvrRecording::whereKey($noEnd->id)->update(['actual_end' => null]);

    $this->service->runForSetting($this->setting);

    expect($older->fresh()->status)->toBe(DvrRecordingStatus::Purged)
        ->and($newer->fresh()->status)->toBe(DvrRecordingStatus::Completed)
        ->and($noEnd->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

it('excludes purged rows from guest and setting storage usage', function (): void {
    $auth = PlaylistAuth::factory()->for($this->user)->create(['dvr_enabled' => true, 'dvr_storage_quota_gb' => 1]);
    $purged = quotaRecording($this->setting, $this->user, 'library/2026/P/P.mkv', 2_000_000_000, 10);
    $purged->update(['playlist_auth_id' => $auth->id, 'status' => DvrRecordingStatus::Purged->value, 'file_path' => null]);

    expect($auth->fresh()->hasReachedStorageQuota())->toBeFalse()
        ->and($auth->fresh()->storage_used_bytes)->toBe(0)
        ->and($this->setting->fresh()->storage_used_bytes)->toBe(0);
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
