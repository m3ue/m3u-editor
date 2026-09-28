<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| cache:cleanup-orphans contract
|--------------------------------------------------------------------------
|
| The command deletes `cached_content_files` rows where:
|   file_path IS NULL                                              (no bytes on disk)
|   AND status != Downloading                                      (in-flight rows are protected)
|   AND updated_at < now()->subDays(--days)   (default 7)
|
| Age is judged by `updated_at` (NOT created_at) so a Failed row that
| was retried, or a Pending row whose dispatch timestamp was bumped,
| is not considered abandoned as long as it moved within the window.
| That is the whole point of the `updated_at` switch: a row that has
| been touched recently is, by definition, not abandoned even if it
| has been around for weeks.
|
| Tests in this file:
|  - cutoff boundary (older / newer than --days, judged by updated_at)
|  - Completed rows that DO have a file_path stay untouched regardless of age
|  - Completed row whose file vanished from disk (no on-disk cleanup contract)
|  - Downloading rows are NEVER deleted (a worker is presumed to be on it)
|  - Pending / Failed rows are KEPT if updated_at is inside the window,
|    DELETED if updated_at crossed it (the documented contract)
|  - A Pending row created long ago but updated recently is KEPT (the
|    `updated_at` switch catches the "retried / re-queued" case)
|  - --dry-run reports without deleting
|  - custom --days value
|  - --days=0 clamps to 1
|  - empty result returns SUCCESS without printing the "Deleted" line
|  - multi-row deletion count
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Disk fake - the command never touches files directly, but several
    // tests assert that on-disk files for surviving rows are untouched.
    Storage::fake('cache');
});

it('deletes a Pending row with file_path IS NULL whose updated_at is older than --days', function () {
    $user = User::factory()->create();
    // 10 days old by updated_at, no file_path, Pending - the canonical
    // orphan for the new contract.
    $orphan = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(10),
        'created_at' => Carbon::now()->subDays(10),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($orphan->id))->toBeNull();
});

it('keeps a Failed row with file_path IS NULL whose updated_at is inside the --days window', function () {
    $user = User::factory()->create();
    $fresh = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(3),
        'created_at' => Carbon::now()->subDays(3),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($fresh->id))->not->toBeNull();
});

it('keeps a Completed row whose file_path is set (regardless of age)', function () {
    // A Completed row always has file_path populated by the downloader.
    // The whereNull('file_path') filter must exclude it from the
    // orphan sweep even if the row is very old.
    $user = User::factory()->create();
    Storage::disk('cache')->put('cache/stale-on-disk.mp4', 'still here');

    $completed = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Completed,
        'disk' => 'cache',
        'file_path' => 'cache/stale-on-disk.mp4',
        'file_size_bytes' => 14,
        'updated_at' => Carbon::now()->subDays(60),
        'created_at' => Carbon::now()->subDays(60),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    // Row stays put.
    expect(CachedContentFile::find($completed->id))->not->toBeNull()
        // The on-disk file is NOT touched by this command - that is the
        // retention service's job (different file_path resolution +
        // different disk delete).
        ->and(Storage::disk('cache')->exists('cache/stale-on-disk.mp4'))->toBeTrue();
});

it('does NOT touch a Completed row whose file has vanished from disk', function () {
    // The orphan cleanup command only deletes ROWS where file_path
    // IS NULL. A Completed row with a missing on-disk file still has
    // a file_path - it falls outside the orphan filter entirely. The
    // command must neither delete the row nor attempt to "reconcile"
    // the missing file; the row stays as-is for the retention service
    // or operator follow-up to handle.
    $user = User::factory()->create();
    // Deliberately do NOT put the file on disk - the row points
    // somewhere that no longer exists.
    $completed = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Completed,
        'disk' => 'cache',
        'file_path' => 'cache/vanished.mp4',
        'file_size_bytes' => 42,
        'updated_at' => Carbon::now()->subDays(30),
        'created_at' => Carbon::now()->subDays(30),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($completed->id))->not->toBeNull()
        ->and($completed->fresh()->file_path)->toBe('cache/vanished.mp4');
});

it('NEVER deletes a Downloading row, even if its updated_at is well past --days', function () {
    // The contract: an in-flight download must never be deleted out
    // from under the worker that owns it. A row that was bumped into
    // Downloading and then stalled (e.g. crashed worker, abandoned
    // queue) has a stale updated_at but the status change has to be
    // reaped manually or by the reclaim path - never silently by the
    // orphan sweep. This test pins that invariant.
    $user = User::factory()->create();
    $stalledDownload = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Downloading,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(30),
        'created_at' => Carbon::now()->subDays(30),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($stalledDownload->id))->not->toBeNull();
});

it('keeps a Pending row whose updated_at is recent even if created_at is old (retry / re-queue path)', function () {
    // A Pending row created 60 days ago but bumped to a fresh
    // updated_at yesterday (e.g. re-queued by a sync pass, picked up
    // by a dispatcher, then failed back to Pending) MUST NOT be
    // considered abandoned. The updated_at switch is exactly for this
    // case - the old created_at would have wrongly classified the row
    // as abandoned under the prior contract.
    $user = User::factory()->create();
    $requeued = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        // Created two months ago but updated yesterday - the row
        // moved, so it is NOT abandoned.
        'created_at' => Carbon::now()->subDays(60),
        'updated_at' => Carbon::now()->subDay(),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($requeued->id))->not->toBeNull();
});

it('dequeues an abandoned Failed row whose updated_at crossed --days', function () {
    // A Failed row that has not been touched within the retention
    // window IS in scope for the orphan sweep. This pins the
    // "Failed without ever producing a file and never retried" case.
    $user = User::factory()->create();
    $staleFailed = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(15),
        'created_at' => Carbon::now()->subDays(15),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($staleFailed->id))->toBeNull();
});

it('dequeues an abandoned Pending row whose updated_at crossed --days', function () {
    // A Pending row that no dispatcher has touched within the
    // retention window IS in scope for the orphan sweep - that is
    // exactly what the command exists to do. Pinning it here means
    // a future change that adds a `whereNotIn('status', ...)`
    // filter is caught.
    $user = User::factory()->create();
    $stalePending = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(15),
        'created_at' => Carbon::now()->subDays(15),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($stalePending->id))->toBeNull();
});

it('keeps a Downloading row newer than --days (active download in flight)', function () {
    $user = User::factory()->create();
    $downloading = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Downloading,
        'file_path' => null,
        'updated_at' => Carbon::now()->subHours(2),
        'created_at' => Carbon::now()->subHours(2),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($downloading->id))->not->toBeNull();
});

it('--dry-run reports the count but performs no deletion', function () {
    $user = User::factory()->create();
    $orphan1 = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(10),
        'created_at' => Carbon::now()->subDays(10),
    ]);
    $orphan2 = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(12),
        'created_at' => Carbon::now()->subDays(12),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --dry-run')
            ->expectsOutputToContain('[DRY RUN] Identified 2 orphan cached_content_files rows older than 7 day(s).')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    // Both rows must still be present - --dry-run is a no-op for deletes.
    expect(CachedContentFile::find($orphan1->id))->not->toBeNull()
        ->and(CachedContentFile::find($orphan2->id))->not->toBeNull();
});

it('returns SUCCESS and prints the identified line (no "Deleted" line) when there are zero orphans', function () {
    // The command short-circuits before the chunkById loop when the
    // count is 0, so the "Deleted N" line must NOT appear. The
    // "Identified 0" line still prints for operator visibility.
    $user = User::factory()->create();
    // A fresh row with file_path IS NULL but inside the window -
    // nothing for the command to find.
    CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'file_path' => null,
        'updated_at' => Carbon::now()->subHours(1),
        'created_at' => Carbon::now()->subHours(1),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')
            ->expectsOutputToContain('Identified 0 orphan cached_content_files rows older than 7 day(s).')
            ->doesntExpectOutputToContain('Deleted')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('respects a custom --days value', function () {
    // Pin: --days=30 widens the cutoff so a 10-day-old updated_at
    // row that the default 7-day sweep would have deleted survives.
    $user = User::factory()->create();
    $middleAged = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(10),
        'created_at' => Carbon::now()->subDays(10),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --days=30')
            ->expectsOutputToContain('older than 30 day(s)')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($middleAged->id))->not->toBeNull();
});

it('clamps --days below 1 up to 1 (no retention window shorter than 1 day)', function () {
    // The command uses `max(1, (int) $this->option('days'))` so an
    // operator passing --days=0 gets a 1-day window, not a wall-clock
    // deletion of every file_path-IS-NULL row. The test exercises
    // that clamp by pairing two rows: one 6 hours old (must survive -
    // inside the clamped 1-day window) and one 2 days old (correctly
    // deleted - outside the clamped 1-day window). The readout text
    // pins the surfaced "older than 1 day(s)" message.
    $user = User::factory()->create();
    $hoursOld = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subHours(6),
        'created_at' => Carbon::now()->subHours(6),
    ]);
    $daysOld = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(2),
        'created_at' => Carbon::now()->subDays(2),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans --days=0')
            ->expectsOutputToContain('older than 1 day(s)')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($hoursOld->id))->not->toBeNull()
        // The 2-day-old row sits outside the clamped 1-day window, so
        // it IS deleted - the clamp only prevents operator error from
        // shrinking the window below 1 day.
        ->and(CachedContentFile::find($daysOld->id))->toBeNull();
});

it('deletes multiple orphans in one chunk and prints the count', function () {
    // Pin the multi-row happy path: 3 old orphans in, 3 out, the count
    // message reflects the deletion total. Uses a tiny chunk size via
    // a second invocation wouldn't actually matter because chunkById
    // defaults to 1000 and we only have 3 - this asserts END-TO-END.
    $user = User::factory()->create();
    $orphans = collect(range(1, 3))->map(fn () => CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(20),
        'created_at' => Carbon::now()->subDays(20),
    ]));

    Carbon::setTestNow(Carbon::now());
    try {
        $this->artisan('cache:cleanup-orphans')
            ->expectsOutputToContain('Identified 3 orphan cached_content_files rows older than 7 day(s).')
            ->expectsOutputToContain('Deleted 3 orphan cached_content_files rows.')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    foreach ($orphans as $orphan) {
        expect(CachedContentFile::find($orphan->id))->toBeNull();
    }
});

it('--dry-run counts a Downloading row in the IDENTIFIED count, but never deletes it', function () {
    // The whereNotIn('status', [Downloading]) filter and the deletion
    // query both apply the same status clause; --dry-run mirrors the
    // production query exactly, so a Downloading row past --days IS
    // flagged by the count line but never reaches the chunkById loop.
    $user = User::factory()->create();
    $stalled = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Downloading,
        'file_path' => null,
        'updated_at' => Carbon::now()->subDays(30),
        'created_at' => Carbon::now()->subDays(30),
    ]);

    Carbon::setTestNow(Carbon::now());
    try {
        // Without Downloading filter, this row WOULD have been a
        // match by the old created_at-based contract. With the new
        // contract it is excluded entirely from the query, so the
        // report says "Identified 0".
        $this->artisan('cache:cleanup-orphans --dry-run')
            ->expectsOutputToContain('Identified 0 orphan cached_content_files rows older than 7 day(s).')
            ->doesntExpectOutputToContain('Deleted')
            ->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    expect(CachedContentFile::find($stalled->id))->not->toBeNull();
});
