<?php

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires PlaylistCreated → SyncPipelineService → Redis lock.
    // AGENTS.md says Bus::fake() is mandatory; Http::preventStrayRequests() guards
    // against any unexpected outbound request during the test.
    Bus::fake();
    Http::preventStrayRequests();

    // Job calls TmdbService::getMovieDetails / getTvSeriesDetails / getSeasonDetails
    // right after row creation to populate the title column. Http::preventStrayRequests()
    // would block those calls and fail every test, so wire a permissive default
    // fake for all TMDB endpoints. Tests that care about the title assert against
    // these responses explicitly.
    Http::fake([
        '*api.themoviedb.org/3/movie/*' => Http::response(['title' => 'Test Movie', 'original_title' => 'Test Movie'], 200),
        '*api.themoviedb.org/3/tv/*/season/*' => Http::response([
            'season_number' => 1,
            'name' => 'Season 1',
            'episodes' => [
                ['episode_number' => 1, 'id' => 1001, 'name' => 'Pilot'],
                ['episode_number' => 2, 'id' => 1002, 'name' => 'Cat\'s in the Bag'],
            ],
        ], 200),
        '*api.themoviedb.org/3/tv/*' => Http::response(['name' => 'Test Series', 'original_name' => 'Test Series'], 200),
    ]);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0, // disable connection pre-flight by default
    ]);
    $this->group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Test Group',
    ]);

    app(GeneralSettings::class)->refresh();
});

it('creates a Completed row on successful download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/movie.mp4' => Http::response('FAKE_CONTENT', 200),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    $files = CachedContentFile::all();
    expect($files)->toHaveCount(1);

    $file = $files->first();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->content_type)->toBe('movie')
        ->and($file->tmdb_id)->toBe('550')
        // 4 empty fields between tmdb_id and quality → 4 colons
        ->and($file->content_fingerprint)->toBe('movie:550::::1080p')
        ->and($file->file_path)->toStartWith('cache/')
        ->and($file->file_size_bytes)->toBeGreaterThan(0)
        ->and($file->last_verified_at)->not->toBeNull();

    expect($file->dynamicGroups)->toHaveCount(1)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('creates a Failed row and increments failure_count on HTTP 404', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/missing.mp4' => Http::response('Not Found', 404),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '999',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/missing.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    $file = CachedContentFile::first();
    expect($file->status)->toBe(CachedContentFileStatus::Failed)
        ->and((int) $file->failure_count)->toBe(1)
        ->and($file->last_failed_at)->not->toBeNull()
        ->and($file->file_path)->toBeNull()
        // last_error_message persists the exception onto the row itself so the
        // activity widget's "View error" action can show it without a log dive.
        ->and($file->last_error_message)
        ->not->toBeNull()
        ->and($file->last_error_message)->toContain('404');
});

it('truncates last_error_message to 8000 chars so Postgres row-size budgets stay sane', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Generate an error message longer than the 8000-char truncation cap:
    // 250 repetitions of ~50-char string = ~12,500 chars.
    $longMessage = str_repeat('A long error explaining nothing in particular. ', 250);
    expect(mb_strlen($longMessage))->toBeGreaterThan(8000);

    Http::fake([
        'provider.example/long.mp4' => Http::response($longMessage, 500),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '777',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/long.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    $file = CachedContentFile::first();
    expect($file->status)->toBe(CachedContentFileStatus::Failed)
        ->and($file->last_error_message)->not->toBeNull()
        ->and(mb_strlen($file->last_error_message))->toBeLessThanOrEqual(8000);
});

it('attaches to existing Completed row (cross-group dedup) and skips download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Pre-seeded with auto-derived fingerprint (don't hardcode — model derives from parts)
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);

    Http::fake(); // any request would fail the test — we expect NO HTTP call

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    expect(CachedContentFile::count())->toBe(1); // no new row
    expect($existing->fresh()->dynamicGroups)->toHaveCount(1)
        ->and($existing->dynamicGroups->first()->id)->toBe($this->group->id);

    Http::assertNothingSent();
});

it('attaches to winner row on unique-fingerprint race', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Note: the dedup fast-path returns early before the create, so this race is hard
    // to exercise directly. We assert the dedup behavior is correct (attach, no duplicate).
    $existing = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);

    Http::fake(['*' => Http::response('FAKE', 200)]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    expect(CachedContentFile::count())->toBe(1)
        ->and($existing->fresh()->dynamicGroups)->toHaveCount(1);
});

it('does not gate concurrency when Downloading count is below max', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('FAKE', 200)]);

    // max is 2 by default per settings migration
    CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Downloading]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // At 1 Downloading + this new one = 2, exactly at max but not OVER — runs fine.
    expect(CachedContentFile::count())->toBe(2);
    expect(CachedContentFile::latest('id')->first()->file_path)->not->toBeNull();
});

it('gates concurrency and releases without creating a row when Downloading count meets max', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // max is 2 by default per settings migration
    CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Downloading]);
    CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Downloading]);

    Http::fake(); // any outbound request would fail the test — gate must return before Step 6

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // No row created for the throttled job — the 2 pre-seeded Downloading
    // rows are untouched.
    expect(CachedContentFile::count())->toBe(2);
    Http::assertNothingSent();
});

it('sets tries=0 and a future retryUntil() deadline so concurrency-throttle releases never permanently fail the job', function () {
    // Regression test: this job used to ship with $tries=1, which meant any
    // job that hit the Step 3 concurrency gate even once and called
    // $this->release() would be permanently failed with
    // MaxAttemptsExceededException on its very next redelivery — Laravel
    // counts every release() as an "attempt" against $tries. retryUntil()
    // replaces that cap with a wall-clock deadline instead (see
    // Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts), so this asserts
    // the fix's actual shape rather than re-deriving Laravel's queue
    // internals in a test.
    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    expect($job->tries)->toBe(0)
        ->and($job->retryUntil())->toBeInstanceOf(Carbon::class)
        ->and($job->retryUntil()->isFuture())->toBeTrue();
});

it('reclaims a Pending row (created at dispatch time) and re-attempts the download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('PENDING_RECLAIMED', 200)]);

    // DynamicGroupCacheDispatchService::dispatchJob() now creates this row
    // in Pending status immediately at dispatch time, before the job ever
    // runs — the activity widget reads it to show the item as queued.
    $pending = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'status' => CachedContentFileStatus::Pending,
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // Same row id reclaimed — no new row created, file written
    expect(CachedContentFile::count())->toBe(1);
    $file = $pending->fresh();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->file_path)->not->toBeNull()
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('skips connection pre-flight when available_streams is 0 (disabled)', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('OK', 200)]);

    $this->playlist->update(['available_streams' => 0]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    expect(CachedContentFile::first()->status)->toBe(CachedContentFileStatus::Completed);
});

it('reclaims a Failed row past cooldown and re-attempts the download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('RETRYED_CONTENT', 200)]);

    // Pre-seeded Failed row — dispatcher's shouldSkip() would not have queued
    // this job if cooldown hadn't expired. last_failed_at 1h ago is well past
    // the default 360-min retry cooldown anyway.
    $existing = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'failure_count' => 3,
        'last_failed_at' => now()->subHour(),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // Same row id, now Completed; file_path written; failure_count reset
    $file = $existing->fresh();
    expect(CachedContentFile::count())->toBe(1)
        ->and($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and((int) $file->failure_count)->toBe(0)
        ->and($file->last_failed_at)->toBeNull()
        ->and($file->file_path)->not->toBeNull()
        ->and($file->file_size_bytes)->toBeGreaterThan(0);

    // Pivot attach
    expect($file->dynamicGroups)->toHaveCount(1)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('reclaims a stale Downloading row and re-attempts the download', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('STALE_RETRYED', 200)]);

    // Default job timeout is 3600s (1h). Stale = updated_at older than
    // timeout + 300s safety margin = ~1h05m ago. Backdate updated_at to 2h
    // ago to be safely stale.
    $staleRow = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'status' => CachedContentFileStatus::Downloading,
        'updated_at' => now()->subHours(2),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // Same row reclaimed — no new row created, file written
    expect(CachedContentFile::count())->toBe(1);
    $file = $staleRow->fresh();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->file_path)->not->toBeNull()
        ->and($file->file_size_bytes)->toBeGreaterThan(0)
        ->and($file->dynamicGroups->first()->id)->toBe($this->group->id);
});

it('does not reclaim a fresh Downloading row (other worker plausibly active)', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // catch-all: if anything got re-downloaded this would record it
    Http::fake(); // any outbound request would fail the test

    // updated_at is now (fresh) — the staleness check at timeout + 300s won't trigger
    $freshRow = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'status' => CachedContentFileStatus::Downloading,
        // updated_at defaults to now
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // No re-download attempted — fresh Downloading row stays as-is, group attached
    expect(CachedContentFile::count())->toBe(1);
    expect($freshRow->fresh()->status)->toBe(CachedContentFileStatus::Downloading)
        ->and($freshRow->fresh()->file_path)->toBeNull();
    expect($freshRow->fresh()->dynamicGroups->first()->id)->toBe($this->group->id);

    Http::assertNothingSent();
});

it('resets failure_count to 0 when reclaiming a Failed row', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake(['*' => Http::response('OK', 200)]);

    $existing = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
        'failure_count' => 5, // past the 3-threshold for tier-2 cooldown
        'last_failed_at' => now()->subHours(2),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    // failure_count reset to 0 so the dispatcher's tier logic starts fresh
    expect((int) $existing->fresh()->failure_count)->toBe(0);
});

it('streams the downloaded temp file into storage instead of loading it into memory', function () {
    // Regression test for the 2GB OOM at Step 7 (DownloadCachedContentFile.php:200).
    // Old code: file_get_contents($tempPath) loaded the whole file into RAM — multi-GB
    // downloads blew the worker's memory_limit. Fix: open as a stream and pass the
    // resource to Storage::put(), which Flysystem writes via writeStream in chunks.
    // Asserting the second arg to Storage::put() is a resource catches any future
    // regression back to file_get_contents() / Storage::putFileAs() with the wrong
    // argument shape.
    config()->set('filesystems.default', 'local');

    $capturedContents = null;
    $diskMock = Mockery::mock();
    $diskMock->shouldReceive('put')
        ->once()
        ->andReturnUsing(function ($path, $contents) use (&$capturedContents) {
            $capturedContents = $contents;

            return true;
        });
    $diskMock->shouldReceive('size')->andReturn(50);

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturn($diskMock);

    Http::fake(['*' => Http::response('FAKE_CONTENT', 200)]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    expect(CachedContentFile::first()->status)->toBe(CachedContentFileStatus::Completed);
    expect($capturedContents)->toBeResource();
});

it('reportDownloadProgress flags the job cancelled instead of throwing when the row is flagged cancelled', function () {
    // "Cancel download" (DynamicGroupCacheActivityWidget::deleteCachedFile())
    // sets CachedContentFile::cancellationCacheKey($id) before deleting the
    // row. checkCancellation() — called from reportDownloadProgress(), which
    // is wired to Guzzle's PROGRESS option — sets a cooperative $cancelled
    // flag rather than throwing: throwing from inside a curl progress
    // callback crashes the worker process instead of unwinding as a normal
    // PHP exception, which orphans the Redis queue reservation and leaves
    // the queue_monitor row stuck (confirmed via a real Horizon run). The
    // flag is checked by handle() after the HTTP GET returns instead.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '3',
        'content_fingerprint' => 'movie:3::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);
    Cache::put(CachedContentFile::cancellationCacheKey($file->id), true);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '3',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);

    $job->reportDownloadProgress(10_485_760, 1_048_576);

    expect($reflection->getProperty('cancelled')->getValue($job))->toBeTrue();
});

it('reportDownloadProgress does not throw when the row is not flagged cancelled', function () {
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '4',
        'content_fingerprint' => 'movie:4::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '4',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);

    $job->reportDownloadProgress(10_485_760, 1_048_576);
    expect($file->fresh()->bytes_downloaded)->toBe(1_048_576);
});

it('streamResponseToFile stops reading further chunks once cancelled mid-transfer (real mid-stream abort)', function () {
    // Regression test for the crash-based cancellation this replaced:
    // throwing from inside curl's progress callback stopped the transfer but
    // crashed the worker process, orphaning the Redis queue reservation and
    // leaving the queue_monitor row stuck (confirmed via a real Horizon
    // run). This drives streamResponseToFile() directly against a fake
    // multi-chunk PSR-7 stream so we can prove the read loop actually stops
    // pulling further chunks — not just that handle() discards the result
    // afterward (that's the next test) — once checkCancellation() flags it.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '5',
        'content_fingerprint' => 'movie:5::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);
    Cache::put(CachedContentFile::cancellationCacheKey($file->id), true);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '5',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);

    // Guzzle's FnStream lets us hand back one fixed "network chunk" per
    // read() call regardless of the requested length, mimicking a real
    // streaming socket instead of a buffer that returns everything at once.
    $chunks = ['AAAA', 'BBBB', 'CCCC'];
    $reads = 0;
    $stream = FnStream::decorate(
        Utils::streamFor(implode('', $chunks)),
        [
            'read' => function (int $length) use (&$reads, $chunks): string {
                $chunk = $chunks[$reads] ?? '';
                $reads++;

                return $chunk;
            },
            'eof' => fn (): bool => $reads >= count($chunks),
        ]
    );
    $response = new Illuminate\Http\Client\Response(new Response(200, [], $stream));

    $tempPath = tempnam(sys_get_temp_dir(), 'dgc_test_');
    $reflection->getMethod('streamResponseToFile')->invoke($job, $response, $tempPath);

    // Only the first chunk was written — checkCancellation() (called from
    // reportDownloadProgress() after every chunk) saw the flag on the very
    // first check and the loop broke immediately instead of draining the
    // rest of the body.
    expect(file_get_contents($tempPath))->toBe('AAAA')
        ->and($reads)->toBe(1);

    @unlink($tempPath);
});

it('handle() discards the download and skips Storage/Completed when cancelled mid-transfer', function () {
    // Http::fake() returns the whole faked body as a single in-memory
    // stream rather than delivering it in real network chunks, so we can't
    // drive a mid-loop cancellation through it the way the test above does
    // — instead this simulates cancellation having already been flagged
    // before Step 6 even starts, and asserts handle()'s post-request check
    // honors it: no Storage write, no flip to Completed.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/movie.mp4' => Http::response('FAKE_CONTENT', 200),
    ]);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    );
    (new ReflectionObject($job))->getProperty('cancelled')->setValue($job, true);

    $job->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    $file = CachedContentFile::first();
    expect($file->status)->toBe(CachedContentFileStatus::Downloading)
        ->and($file->file_path)->toBeNull();

    Storage::disk('local')->assertDirectoryEmpty('cache');
});

it('skips creating a row when the dispatch-time Pending row was cancelled before any worker reclaimed it', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Mirrors DynamicGroupCacheActivityWidget::deleteCachedFile()'s Pending
    // branch: the row is gone (deleted) and the fingerprint-keyed flag is
    // set in its place.
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);
    Cache::put(CachedContentFile::pendingCancellationCacheKey($fingerprint), true);

    Http::fake(); // any outbound request would fail the test — must skip before Step 6

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '550',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: '1080p',
        sourceUrl: 'https://provider.example/movie.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    expect(CachedContentFile::count())->toBe(0);
    Http::assertNothingSent();

    // Consumed (Cache::pull), so it can't suppress a later legitimate dispatch.
    expect(Cache::has(CachedContentFile::pendingCancellationCacheKey($fingerprint)))->toBeFalse();
});

it('throttles progress updates to the 1 MiB boundary', function () {
    // Direct unit-style test of reportDownloadProgress(). A multi-GB download can
    // fire the Guzzle PROGRESS callback thousands of times — we update the DB only
    // on 1 MiB boundaries to avoid hammering Postgres with UPDATEs on every chunk.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'content_fingerprint' => 'movie:1::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '1',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    // Reflectively set transient progress state (private properties).
    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);
    $reflection->getProperty('progressThreshold')->setValue($job, 1_048_576); // 1 MiB

    // First call: downloadSize > 0, downloaded = 1 MiB → should update + capture bytes_expected.
    $job->reportDownloadProgress(10_485_760, 1_048_576);
    $file->refresh();
    expect($file->bytes_downloaded)->toBe(1_048_576)
        ->and($file->bytes_expected)->toBe(10_485_760)
        ->and($file->last_progress_at)->not->toBeNull();

    // Sub-threshold call: downloaded = 1 MiB + 64 KB (less than 1 MiB boundary) → no update.
    $file->update(['bytes_downloaded' => 999]); // sentinel — proves no overwrite happened
    $job->reportDownloadProgress(10_485_760, 1_048_576 + 65_536);
    expect($file->fresh()->bytes_downloaded)->toBe(999);

    // Crossing the 1 MiB boundary again: 2 MiB total → updates.
    $job->reportDownloadProgress(10_485_760, 2_097_152);
    expect($file->fresh()->bytes_downloaded)->toBe(2_097_152);
});

it('captures bytes_expected from the first non-zero Content-Length and ignores -1 (chunked)', function () {
    // Guzzle sends downloadSize = -1 for chunked transfer / no Content-Length.
    // bytes_expected must stay null in that case — never default to 0 or -1.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '2',
        'content_fingerprint' => 'movie:2::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '2',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);
    $reflection->getProperty('progressThreshold')->setValue($job, 1);

    // First event: downloadSize = -1 (unknown) → bytes_expected stays null.
    $job->reportDownloadProgress(-1, 1_048_576);
    expect($file->fresh()->bytes_expected)->toBeNull();

    // Then a real Content-Length shows up later → bytes_expected is captured.
    $job->reportDownloadProgress(50_000_000, 2_097_152);
    expect($file->fresh()->bytes_expected)->toBe(50_000_000);
});

it('swallows progress-update failures without aborting the download', function () {
    // A failed UPDATE on the progress row (e.g. row was deleted by retention mid-download)
    // must not bubble up and crash the HTTP transfer. Call reportDownloadProgress with
    // a progressFile that no longer exists in the DB → the catch (Throwable) absorbs it.
    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '3',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '3',
        'content_fingerprint' => 'movie:3::::',
        'status' => CachedContentFileStatus::Downloading,
    ]);
    $file->delete();

    $reflection = new ReflectionObject($job);
    $reflection->getProperty('progressFile')->setValue($job, $file);
    $reflection->getProperty('progressThreshold')->setValue($job, 1);

    // Must NOT throw — even though $file->update() will fail with ModelNotFoundException.
    expect(fn () => $job->reportDownloadProgress(100, 100))->not->toThrow(Throwable::class);
});

it('rollbackStorageWrite() deletes the Storage file and marks the row Failed', function () {
    // Step 8 can throw (DB connection lost, constraint violation). Without
    // rollbackStorageWrite() the multi-GB file would be orphaned because the
    // failed update never set file_path on the row, so hasFilePath() returns
    // false and the retention service can't find or clean it up.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Storage::disk('local')->put('cache/movie:42::::.mp4', 'fake-bytes');
    $file = CachedContentFile::factory()->downloading()->create([
        'content_type' => 'movie', 'tmdb_id' => '42',
        'content_fingerprint' => 'movie:42::::',
    ]);
    expect(Storage::disk('local')->exists('cache/movie:42::::.mp4'))->toBeTrue()
        ->and($file->status)->toBe(CachedContentFileStatus::Downloading);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '42',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    // Reflectively call the private rollbackStorageWrite() with a simulated cause.
    $reflection = new ReflectionObject($job);
    $method = $reflection->getMethod('rollbackStorageWrite');
    $method->setAccessible(true);
    $method->invoke($job, 'local', 'cache/movie:42::::.mp4', $file, new RuntimeException('Simulated DB failure'));

    // Storage file is gone — no orphan.
    expect(Storage::disk('local')->exists('cache/movie:42::::.mp4'))->toBeFalse();
    // Row is marked Failed so the dispatcher's failure-cooldown governs the retry.
    expect($file->fresh()->status)->toBe(CachedContentFileStatus::Failed)
        ->and($file->fresh()->failure_count)->toBeGreaterThan(0);
});

it('rollbackStorageWrite() does NOT throw if the Storage delete itself fails', function () {
    // Worst case: Step 7 wrote to Storage, Step 8 update threw, AND the
    // rollback Storage::delete() also fails (e.g. S3 500). The row must still
    // be marked Failed — better to have an unrecoverable orphan in logs than
    // a row stuck in Downloading that retries forever. We log the orphan so
    // an operator can clean it up manually.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Use a path that Storage::fake's delete() would still work on — to force
    // a delete failure we wrap Storage in a Mockery double that throws.
    Storage::disk('local')->put('cache/orphan.mp4', 'data');
    $file = CachedContentFile::factory()->downloading()->create([
        'content_type' => 'movie', 'tmdb_id' => '99',
        'content_fingerprint' => 'movie:99::::',
    ]);

    $diskMock = Mockery::mock();
    $diskMock->shouldReceive('delete')->andThrow(new RuntimeException('S3 500'));
    Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);

    $job = new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '99',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/movie.mp4',
    );

    $reflection = new ReflectionObject($job);
    $method = $reflection->getMethod('rollbackStorageWrite');
    $method->setAccessible(true);

    // Must not throw — the row gets marked Failed either way, and we log the
    // Storage failure so an operator can clean up manually.
    expect(fn () => $method->invoke($job, 'local', 'cache/orphan.mp4', $file, new RuntimeException('Step 8 update failed')))
        ->not->toThrow(Throwable::class);
    expect($file->fresh()->status)->toBe(CachedContentFileStatus::Failed);
});

it('stamps final bytes_downloaded from Storage::size() in Step 8 even if the last progress window was under the threshold', function () {
    // Edge case: a small clip (say 200 KB) never crosses the 1 MiB throttle boundary,
    // so the only progress update is the final one from Step 8 — which uses
    // Storage::size() instead of the progress callback. Verify the final row state
    // has bytes_downloaded == file_size_bytes regardless.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    Http::fake([
        'provider.example/small.mp4' => Http::response(str_repeat('x', 200), 200, ['Content-Length' => '200']),
    ]);

    (new DownloadCachedContentFile(
        dynamicGroup: $this->group,
        contentType: 'movie',
        tmdbId: '4',
        tvdbId: null,
        seasonNumber: null,
        episodeNumber: null,
        quality: null,
        sourceUrl: 'https://provider.example/small.mp4',
    ))->handle(
        app(GeneralSettings::class),
        app(M3uProxyService::class),
        app(TmdbService::class),
    );

    $file = CachedContentFile::first();
    expect($file->status)->toBe(CachedContentFileStatus::Completed)
        ->and($file->bytes_downloaded)->toBe($file->file_size_bytes)
        ->and($file->file_size_bytes)->toBe(200)
        ->and($file->last_progress_at)->not->toBeNull();
});
