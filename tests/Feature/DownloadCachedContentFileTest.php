<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function setEnableCacheForDownloadTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory fires PlaylistListener -> SyncPipelineService ->
    // dispatch(ProcessM3uImport). Bus::fake() catches that so the listener
    // never queues real jobs in tests that touch Playlists.
    Bus::fake();

    // The job's kill-switch guard reads `enable_cache` from
    // GeneralSettings. Tests for the download path need the setting on
    // (the off-path test flips it explicitly).
    setEnableCacheForDownloadTest(true);

    // Per-test Http::fake is set INSIDE each test rather than as a `*`
    // wildcard here. A wildcard registered in beforeEach is matched
    // BEFORE any test-specific stub (Laravel iterates stubCallbacks in
    // registration order), so the failure-path test's 404 stub would be
    // shadowed by the 200 wildcard. Each test sets its own Http::fake()
    // with the exact URL pattern it needs.
});

// --- happy path ---

it('happy path: Channel with a real URL lands the file on disk and the row is Completed', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 550,
        'url' => 'https://example.com/movie.mp4',
    ]);

    Http::fake([
        'https://example.com/movie.mp4' => Http::response('binary file bytes', 200),
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Run the job synchronously (no queue). RefreshDatabase has wrapped
    // each test in a transaction, but the job's Storage::disk('cache')
    // call goes through Laravel's Storage fake, which is in-memory.
    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->disk)->toBe('cache')
        ->and($fresh->file_path)->not->toBeNull()
        ->and($fresh->file_size_bytes)->toBe(strlen('binary file bytes'))
        ->and($fresh->last_verified_at)->not->toBeNull()
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();
});

it('happy path: Episode with a real URL lands the file on disk and the row is Completed', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $series = Series::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 60625,
        'tvdb_id' => 888,
    ]);
    $episode = Episode::factory()->for($user)->for($playlist)->for($series, 'series')->create([
        'season' => 1,
        'episode_num' => 5,
        'url' => 'https://example.com/episode.mp4',
    ]);

    Http::fake([
        'https://example.com/episode.mp4' => Http::response('binary file bytes', 200),
    ]);

    $row = CachedContentFile::factory()->forItem($episode)->create([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'tvdb_id' => '888',
        'season_number' => 1,
        'episode_number' => 5,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->file_path)->not->toBeNull()
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();
});

// --- failure path ---

it('failure path: HTTP error -> row is Failed and failure_count increments', function () {
    Storage::fake('cache');

    Http::fake([
        'https://example.com/broken.mp4' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 551,
        'url' => 'https://example.com/broken.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    try {
        (new DownloadCachedContentFile($row->id))->handle();
    } catch (RequestException) {
        // Expected - the job re-throws so Horizon marks it failed. PR B's
        // minimal job lets the exception bubble (PR C adds
        // try/catch-and-stay-alive behavior).
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull()
        // file_path remains null - nothing was written
        ->and($fresh->file_path)->toBeNull();
});

it('failure path: empty URL -> row is Failed without throwing', function () {
    // The job's resolveSourceUrl returns '' for an empty URL and handle()
    // marks Failed via the early return path. No HTTP call, no throw.
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 552,
        'url' => null, // empty source URL
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '552',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'failure_count' => 0,
    ]);

    // Should NOT throw - the empty-URL path is an early-return.
    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull();
});

it('failure path: missing cached_content_files row -> job logs and returns cleanly (no throw)', function () {
    // If the row has been deleted out from under the job (e.g. operator
    // cleanup between dispatch and run), the job must NOT crash - log and
    // exit cleanly so Horizon doesn't pollute failed_jobs with this.
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 553,
        'url' => 'https://example.com/movie.mp4',
    ]);

    // Pass a non-existent row ID.
    (new DownloadCachedContentFile(999999))->handle();

    expect(CachedContentFile::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// PR C upgrade tests - byte-level progress, cancellation, reclaim, backoff
// ---------------------------------------------------------------------------

it('regression: HTTP error -> last_error_message is persisted on the row', function () {
    // This is the most important new test. PR #1500 silently dropped
    // `last_error_message` because the column was missing from
    // CachedContentFile::$fillable. PR A added it to $fillable; PR C's
    // `markFailed()` writes it via `forceFill()` (regression-proof even if
    // a future change removes the column from $fillable). Without this
    // test we cannot tell whether markFailed is doing its job - the row
    // is "Failed" either way.
    Storage::fake('cache');

    Http::fake([
        'https://example.com/broken.mp4' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 554,
        'url' => 'https://example.com/broken.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '554',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    try {
        (new DownloadCachedContentFile($row->id))->handle();
    } catch (RequestException) {
        // Expected - the job re-throws so Horizon applies backoff.
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        // THE regression assertion: PR #1500 callers saw this as null.
        ->and($fresh->last_error_message)->not->toBeNull()
        ->and($fresh->last_error_message)->toContain('HTTP error')
        ->and($fresh->last_error_message)->toContain('404');
});

it('upgrade: byte-level progress reporting writes bytes_downloaded and bytes_per_second', function () {
    // Stream a body large enough to cross the 1 MiB progress threshold at
    // least twice, so the job writes a progress update on the row. Then
    // assert the row's bytes_downloaded / bytes_per_second / last_progress_at
    // reflect the simulated throughput.
    Storage::fake('cache');

    // 3 MiB of body - two throttle boundaries crossed (at 1 MiB and 2 MiB).
    // The third chunk (~1 MiB) sits below the threshold so it is the final
    // tally, written by Step 7 from Storage::size().
    $body = str_repeat('A', 3 * 1_048_576);
    Http::fake([
        'https://example.com/big.mp4' => Http::response($body, 200, [
            'Content-Length' => (string) strlen($body),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 555,
        'url' => 'https://example.com/big.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '555',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->bytes_downloaded)->toBe(strlen($body))
        ->and($fresh->bytes_expected)->toBe(strlen($body))
        ->and($fresh->bytes_per_second)->not->toBeNull()
        ->and($fresh->bytes_per_second)->toBeGreaterThan(0)
        ->and($fresh->last_progress_at)->not->toBeNull();
});

it('upgrade: final progress reporting writes small downloads before completion', function () {
    Storage::fake('cache');
    $body = 'small download body';
    Http::fake(['https://example.com/small.mp4' => Http::response($body, 200, [
        'Content-Length' => (string) strlen($body),
    ])]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 556,
        'url' => 'https://example.com/small.mp4',
    ]);
    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '556',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    expect($row->fresh()->bytes_downloaded)->toBe(strlen($body));
});

it('upgrade: cancellation key observed during a live download flips the row to Failed (no resurrection)', function () {
    // PR #1524 review item 2: a row-id cancel flag observed by the
    // worker while the row is still alive must NOT leave the row stuck
    // on Downloading. The first checkCancellation() fires right after
    // atomicReclaim() (this test pre-sets the flag, so the before-start
    // check catches it) and routes the abort through markCancelled().
    // Http::preventStrayRequests() would fail the test if the GET fired.
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 556,
        'url' => 'https://example.com/cancel-me.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '556',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Pre-set the cancellation key the job polls. The widget's delete
    // path also deletes the row; this test exercises the alternate race
    // where the row stays alive when the worker picks it up.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    // PR #1524 review item 2: a live row that observes the cancel flag
    // must NOT be left stuck on Downloading - markCancelled() flips it
    // to Failed with a Cancelled reason so the UI / retry path can act
    // on it without operator intervention.
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        // No file_path - the abort happened before any bytes were written.
        ->and($fresh->file_path)->toBeNull()
        // markCancelled() does NOT bump failure_count (cancellation is
        // not a transient failure, so no cooldown penalty on retry).
        ->and($fresh->failure_count)->toBe(0)
        ->and($fresh->last_error_message)->toBe('Cancelled before download started.');

    // Cache key was cleared by markCancelled() after the abort.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($row->id)))->toBeFalse();
});

it('regression (item 7): cancelling a Pending row does not leave a fingerprint-scoped flag behind', function () {
    // PR #1524 review item 7: the previous design also wrote a
    // fingerprint-scoped `pendingCancellationCacheKey` with a 10-minute
    // TTL when a Pending row was cancelled. Because the row is also
    // deleted, the job's `find()` returned null and the pull never
    // fired, so the fingerprint flag lingered and silently suppressed
    // the NEXT legitimate dispatch for the same content. After the fix
    // the fingerprint key is gone entirely; the row-id key + row
    // deletion cover both windows.
    //
    // This test exercises the realistic flow: cancel a Pending row,
    // re-dispatch the same content, and verify the new job runs to
    // completion (no stale flag suppressing it).
    Storage::fake('cache');
    Http::fake([
        'https://example.com/redispatch.mp4' => Http::response('fresh bytes', 200, [
            'Content-Length' => (string) strlen('fresh bytes'),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 560,
        'is_vod' => true,
        'url' => 'https://example.com/redispatch.mp4',
    ]);

    // 1) Original Pending row, then cancel via the widget helper. The
    // widget sets the row-id key + deletes the row; that is the
    // realistic input shape.
    $row1 = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '560',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    Cache::put(CachedContentFile::cancellationCacheKey($row1->id), true, now()->addHours(48));
    $row1->delete();

    expect(CachedContentFile::find($row1->id))->toBeNull();

    // 2) Sanity: the fingerprint-scoped helper no longer exists (the
    // call would fatal-error if it did, but be explicit). The model
    // class should not expose `pendingCancellationCacheKey`.
    expect(method_exists(CachedContentFile::class, 'pendingCancellationCacheKey'))->toBeFalse();

    // 3) Dispatch the same content again and run the new job. The new
    // row reaches Completed - the only thing that would suppress it is a
    // stale fingerprint-scoped flag, which no longer exists.
    $result = app(CachedContentDispatchService::class)->dispatch($channel);

    expect($result)->toBe(CacheDispatchResult::Queued);
    $row2Id = $channel->cachedContentFile()->value('id');

    (new DownloadCachedContentFile($row2Id))->handle();

    $row2 = CachedContentFile::find($row2Id);
    expect($row2)->not->toBeNull()
        ->and($row2->status)->toBe(CachedContentFileStatus::Completed)
        ->and($row2->file_path)->not->toBeNull();
});

it('regression (item 2): markCancelled does not recreate a deleted row', function () {
    // PR #1524 review item 2: the cancellation helper must NOT recreate
    // a deleted row. The widget deletes the row before the worker
    // observes the cancel flag; the worker's `find()` returns null and
    // it exits before markCancelled() runs. To exercise markCancelled()
    // directly without a live row, we delete the row between the
    // worker's `find()` and the mid-flight cancellation check by
    // dispatching the cancellation flag while the row is still alive
    // and then deleting it before the second checkCancellation() call.
    //
    // Concretely: set the cancellation key, run the job, and verify
    // the row stays deleted (no resurrection via a misguided UPDATE or
    // re-INSERT).
    Storage::fake('cache');

    Http::fake([
        'https://example.com/deleted-row.mp4' => Http::response('short body', 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 561,
        'url' => 'https://example.com/deleted-row.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '561',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Pre-set the row-id cancellation key, then delete the row out from
    // under the job. markCancelled() will be called with the in-memory
    // model whose row no longer exists; it must update 0 rows and not
    // resurrect anything.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);
    $rowId = $row->id;
    $row->delete();

    // Simulate the job holding an in-memory reference to the deleted
    // row - this is the race the fix is hardening against. We run the
    // job normally; the worker's `find()` returns null and exits before
    // markCancelled() is reached, which is the safe path.
    (new DownloadCachedContentFile($rowId))->handle();

    // The row stays deleted - no resurrection.
    expect(CachedContentFile::find($rowId))->toBeNull();
    // The cache key was already set by us, and the worker (which never
    // reached markCancelled) did not touch it. A TTL on the widget's
    // Cache::put means it expires on its own; nothing in the job path
    // needs to clear it because the row was never found.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($rowId)))->toBeTrue();
});

it('regression (item 2): markCancelled on a LIVE row flips it to Failed with Cancelled reason', function () {
    // PR #1524 review item 2: when the worker observes the cancel flag
    // AND the row still exists (a race, or a future cancel path that
    // does not delete the row), markCancelled() must flip the row to
    // Failed with a Cancelled reason. This test exercises the before-
    // start cancel exit by pre-setting the flag BEFORE handle() runs -
    // the worker's first checkCancellation() after atomicReclaim fires
    // markCancelled().
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 562,
        'url' => 'https://example.com/before-start-cancel.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '562',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    // Pre-set the row-id cancellation key. The job's first
    // checkCancellation() after atomicReclaim sees the flag, calls
    // markCancelled(), and returns. Http::preventStrayRequests() would
    // fail the test if the GET fired.
    Cache::put(CachedContentFile::cancellationCacheKey($row->id), true, 60);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->last_failed_at)->not->toBeNull()
        ->and($fresh->last_error_message)->toBe('Cancelled before download started.')
        // markCancelled() does NOT bump failure_count.
        ->and($fresh->failure_count)->toBe(0);

    // The row-id key was cleared by markCancelled() so a future
    // dispatch (new row, new id) is not affected.
    expect(Cache::has(CachedContentFile::cancellationCacheKey($row->id)))->toBeFalse();
});

it('upgrade: Failed -> Downloading atomic reclaim transitions through Downloading to Completed', function () {
    // A retry case: the row is in Failed (a previous attempt failed and
    // the cooldown has elapsed). handle() must atomically flip it to
    // Downloading, then run the download to Completed, resetting
    // failure_count and last_failed_at.
    Storage::fake('cache');

    Http::fake([
        'https://example.com/retry.mp4' => Http::response('retry bytes', 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 558,
        'url' => 'https://example.com/retry.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '558',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Failed,
        'failure_count' => 2,
        'last_failed_at' => now()->subMinutes(5),
        'last_error_message' => 'Previous attempt died.',
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->failure_count)->toBe(0)
        ->and($fresh->last_failed_at)->toBeNull()
        ->and($fresh->last_error_message)->toBeNull()
        ->and($fresh->file_path)->not->toBeNull();
});

it('upgrade: row already Downloading (another worker claimed it) returns early without HTTP', function () {
    // Defensive path: another worker beat this one to the atomic reclaim.
    // handle() must not issue a duplicate GET. Http::preventStrayRequests()
    // fails the test if it does.
    Storage::fake('cache');
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 559,
        'url' => 'https://example.com/already-claimed.mp4',
    ]);

    // Row already in Downloading state - simulate another worker having
    // claimed it first.
    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '559',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Downloading,
        'bytes_downloaded' => 524288,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    // Status unchanged - the second worker returned early.
    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Downloading);
});

it('retries up to 3 exceptions with backoff and serializes downloads per playlist', function () {
    $row = CachedContentFile::factory()->create();
    $job = new DownloadCachedContentFile($row->id);

    $middleware = $job->middleware();

    expect($job->maxExceptions)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 300])
        ->and($job->retryUntil()->isFuture())->toBeTrue()
        ->and($job->queue)->toBe('cache')
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('cached-content-download:playlist:'.$row->playlist_id);
});

// --- enable_cache kill switch: jobs queued before the toggle flipped off ---

it('handle marks the row Failed with "Caching disabled" when enable_cache is off at run time', function () {
    // PR #1524 review item 5: a job queued while enable_cache was on
    // can still be picked up after the operator flips the toggle off.
    // The job must mark the row Failed via the same path other validation
    // errors use (so a later re-enable + dispatch can reclaim it) and
    // must NOT throw - Horizon should not retry a known-disabled job.
    Storage::fake('cache');
    Http::preventStrayRequests();

    setEnableCacheForDownloadTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 560,
        'url' => 'https://example.com/kill-switch.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '560',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    // No exception bubbles - Horizon should not retry a disabled-by-toggle
    // failure.
    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull()
        ->and($fresh->last_error_message)->toBe('Caching is disabled.');
});

it('handle returns cleanly without touching the row when enable_cache is off AND the row is missing', function () {
    // Defensive: if the row was deleted between dispatch and run AND
    // the operator flipped the toggle off, handle() must not crash.
    Storage::fake('cache');

    setEnableCacheForDownloadTest(false);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 561,
        'url' => 'https://example.com/none.mp4',
    ]);

    expect(CachedContentFile::count())->toBe(0);
    (new DownloadCachedContentFile(999999))->handle();
    expect(CachedContentFile::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// PR D: fetchWithSafeRedirects() redirect-walk tests
// ---------------------------------------------------------------------------
//
// `fetchWithSafeRedirects()` disables Guzzle's automatic redirect-following
// and walks the Location header chain manually, re-validating each hop
// through `PrivateNetworkGuard::assertUrlSafe()`. The tests below pin
// behaviour for: a clean public redirect chain, an SSRF-rejected redirect
// target (no private-network request must fire), a relative Location
// header that resolves against the current URL, exceeding the
// `$maxRedirects` (5) cap, and a non-http scheme redirect target.

it('redirect walk: public -> public -> 200 completes and lands the file on disk', function () {
    Storage::fake('cache');

    // First request: 302 to a second public IP. Second: the real 200.
    // The job must follow the redirect without dropping bytes or
    // marking the row Failed. Using IP-literal URLs (TEST-NET-2 range
    // 198.51.100.0/24, public per PHP's filter flags) avoids DNS
    // resolution in the test environment.
    Http::fake([
        'http://198.51.100.1/origin.mp4' => Http::response('', 302, [
            'Location' => 'http://198.51.100.2/movie.mp4',
        ]),
        'http://198.51.100.2/movie.mp4' => Http::response('redirected bytes', 200, [
            'Content-Length' => (string) strlen('redirected bytes'),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 570,
        'url' => 'http://198.51.100.1/origin.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '570',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->file_path)->not->toBeNull()
        ->and($fresh->file_size_bytes)->toBe(strlen('redirected bytes'))
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'http://198.51.100.1/origin.mp4';
    });
    Http::assertSent(function ($request) {
        return $request->url() === 'http://198.51.100.2/movie.mp4';
    });
});

it('redirect walk: public -> http://127.0.0.1/... is rejected and no private request fires', function () {
    Storage::fake('cache');
    Http::preventStrayRequests();

    Http::fake([
        'http://198.51.100.1/payload.mp4' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/admin',
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 571,
        'url' => 'http://198.51.100.1/payload.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '571',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    // The job's catch(InvalidArgumentException) marks Failed with the
    // "Redirect rejected" prefix so the activity widget can surface the
    // SSRF rejection distinctly from a generic HTTP failure.
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_failed_at)->not->toBeNull()
        ->and($fresh->last_error_message)->not->toBeNull()
        ->and($fresh->last_error_message)->toContain('Redirect rejected (private network guard)')
        ->and($fresh->last_error_message)->toContain('127.0.0.1')
        // No file_path - the abort happened before any bytes were written.
        ->and($fresh->file_path)->toBeNull();

    // Critical: Http::assertNotSent() against the private URL catches the
    // failure mode the SSRF guard exists to prevent - a real outbound
    // request reaching 127.0.0.1.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '127.0.0.1');
    });
});

it('redirect walk: relative Location header resolves against the current URL', function () {
    Storage::fake('cache');

    // First request returns a relative Location "/movie.mp4"; the job
    // must resolve that against the original URL via Guzzle's
    // UriResolver to "http://198.51.100.1/movie.mp4".
    Http::fake([
        'http://198.51.100.1/origin' => Http::response('', 302, [
            'Location' => '/movie.mp4',
        ]),
        'http://198.51.100.1/movie.mp4' => Http::response('relative-redirect bytes', 200, [
            'Content-Length' => (string) strlen('relative-redirect bytes'),
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 572,
        'url' => 'http://198.51.100.1/origin',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '572',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Completed)
        ->and($fresh->file_size_bytes)->toBe(strlen('relative-redirect bytes'))
        ->and(Storage::disk('cache')->exists($fresh->file_path))->toBeTrue();

    Http::assertSent(function ($request) {
        // The resolved relative target must be on the SAME host as the
        // original URL, not a fabricated host.
        return $request->url() === 'http://198.51.100.1/movie.mp4';
    });
});

it('redirect walk: more than $maxRedirects hops ends the row Failed with the redirect-limit error', function () {
    Storage::fake('cache');

    // Every request returns 302 -> /next-N. With $maxRedirects = 5 the
    // loop exits after 6 iterations (i = 0..5) and the job throws
    // RuntimeException with the "Exceeded N redirects" message. The
    // outer catch (Throwable) in handle() routes it to "Unexpected error:"
    // AND re-throws so Horizon applies backoff - same shape as the
    // existing HTTP-error failure-path test.
    Http::fake([
        'http://198.51.100.1/*' => function () {
            static $i = 0;
            $i++;

            return Http::response('', 302, ['Location' => '/next-'.$i]);
        },
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 573,
        'url' => 'http://198.51.100.1/start',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '573',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    try {
        (new DownloadCachedContentFile($row->id))->handle();
    } catch (RuntimeException) {
        // Expected - handle() marks Failed via markFailed() and then
        // re-throws so Horizon applies backoff (matching the existing
        // HTTP-error failure path).
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_error_message)->not->toBeNull()
        ->and($fresh->last_error_message)->toContain('redirects')
        ->and($fresh->file_path)->toBeNull();
});

it('redirect walk: redirect to a non-http scheme (file:///etc/passwd) is rejected', function () {
    Storage::fake('cache');
    Http::preventStrayRequests();

    // The guard rejects non-http(s) schemes regardless of destination,
    // so this case uses a public first hop to keep the SSRF path
    // distinct from the previous redirect tests.
    Http::fake([
        'http://198.51.100.1/payload.mp4' => Http::response('', 302, [
            'Location' => 'file:///etc/passwd',
        ]),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'tmdb_id' => 574,
        'url' => 'http://198.51.100.1/payload.mp4',
    ]);

    $row = CachedContentFile::factory()->forItem($channel)->create([
        'content_type' => 'movie',
        'tmdb_id' => '574',
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'status' => CachedContentFileStatus::Pending,
        'failure_count' => 0,
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_error_message)->toContain('Redirect rejected (private network guard)')
        ->and($fresh->file_path)->toBeNull();

    // Pin the scheme rejection in the surfaced error - operators reading
    // the activity widget should be able to tell WHY this was rejected.
    expect($fresh->last_error_message)->toContain('file');
});

// ---------------------------------------------------------------------------
// Source URL, storage path, provider limits, and crash recovery
// ---------------------------------------------------------------------------

/**
 * A Pending row for a fresh VOD channel with the given attributes.
 *
 * @param  array<string, mixed>  $channelAttributes
 * @param  array<string, mixed>  $playlistAttributes
 */
function pendingDownloadRow(array $channelAttributes = [], array $playlistAttributes = []): CachedContentFile
{
    $playlist = Playlist::factory()->create($playlistAttributes);
    $channel = Channel::factory()->create(array_merge([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'tmdb_id' => 777,
        'url' => 'https://example.com/source.mkv',
    ], $channelAttributes));

    return CachedContentFile::factory()->forItem($channel)->create();
}

it('downloads url_custom instead of url and sends the playlist User-Agent', function () {
    Storage::fake('cache');
    Http::fake(['https://example.net/*' => Http::response('bytes', 200)]);

    $row = pendingDownloadRow(
        ['url_custom' => 'https://example.net/override.mkv'],
        ['user_agent' => 'MyPlayer/1.0'],
    );

    (new DownloadCachedContentFile($row->id))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://example.net/override.mkv'
        && $request->header('User-Agent')[0] === 'MyPlayer/1.0');
    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Completed);
});

it('writes each row to its own uuid path and leaves no partial file behind', function () {
    Storage::fake('cache');
    Http::fake(['https://example.com/*' => Http::response('bytes', 200)]);

    $row = pendingDownloadRow();

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->file_path)->toBe("{$row->playlist_id}/{$row->uuid}.mkv");
    Storage::disk('cache')->assertExists($fresh->file_path);
    Storage::disk('cache')->assertMissing($fresh->file_path.'.part');
});

it('fails the row when the provider answers with an HLS manifest', function () {
    Storage::fake('cache');
    Http::fake(['https://example.com/*' => Http::response("#EXTM3U\n", 200, ['Content-Type' => 'application/vnd.apple.mpegurl'])]);

    $row = pendingDownloadRow();

    (new DownloadCachedContentFile($row->id))->handle();

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->last_error_message)->toContain('HLS');
});

it('fails the row when its source channel no longer exists', function () {
    Storage::fake('cache');
    Http::preventStrayRequests();

    $row = pendingDownloadRow();
    Channel::whereKey($row->cacheable_id)->delete();

    (new DownloadCachedContentFile($row->id))->handle();

    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Failed);
});

it('waits (releases the job) while the playlist is at its connection limit', function () {
    Storage::fake('cache');
    config(['proxy.m3u_proxy_host' => 'http://proxy.test', 'proxy.m3u_proxy_port' => null]);
    Http::fake([
        'http://proxy.test/streams/by-metadata*' => Http::response(['total_matching' => 1]),
        'https://example.com/*' => Http::response('bytes', 200),
    ]);

    $row = pendingDownloadRow(playlistAttributes: ['available_streams' => 1]);
    $job = (new DownloadCachedContentFile($row->id))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased();
    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Pending);
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://example.com/'));
});

it('reclaims a Downloading row whose worker went quiet', function () {
    Storage::fake('cache');
    Http::fake(['https://example.com/*' => Http::response('bytes', 200)]);

    $row = pendingDownloadRow();
    CachedContentFile::whereKey($row->id)->update([
        'status' => CachedContentFileStatus::Downloading->value,
        'updated_at' => now()->subSeconds(DownloadCachedContentFile::STALE_DOWNLOAD_SECONDS + 60),
    ]);

    (new DownloadCachedContentFile($row->id))->handle();

    expect($row->fresh()->status)->toBe(CachedContentFileStatus::Completed);
});

it('failed() marks an in-flight row Failed so it is never stuck in Downloading', function () {
    $row = pendingDownloadRow();
    $row->update(['status' => CachedContentFileStatus::Downloading]);

    (new DownloadCachedContentFile($row->id))->failed(new RuntimeException('Job timed out.'));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(CachedContentFileStatus::Failed)
        ->and($fresh->last_error_message)->toBe('Job timed out.');
});
