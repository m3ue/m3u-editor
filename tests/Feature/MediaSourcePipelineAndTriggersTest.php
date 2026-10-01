<?php

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Jobs\FetchTmdbIds;
use App\Jobs\MatchMediaServerSources;
use App\Jobs\SyncMediaServer;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use App\Services\SyncPipelineService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function mediaSourceMockPipelineSettings(bool $tmdb = false, string $lookupScope = 'enabled'): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdb;
    $mock->tmdb_auto_lookup_all_new = $lookupScope;

    app()->instance(GeneralSettings::class, $mock);
}

function mediaSourcePlaylistWithVod(User $user, array $attrs = []): Playlist
{
    $playlist = Playlist::factory()->for($user)->create($attrs);
    Channel::factory()->for($playlist)->for($user)->create(['enabled' => true, 'is_vod' => true]);

    return $playlist;
}

beforeEach(function () {
    // NOTE: no Event::fake() here — a full event fake swallows Eloquent model
    // events, and the toggle test asserts on the Playlist::updated listener.
    //
    // The array cache store matters too: MatchMediaServerSources is
    // ShouldBeUnique, and PendingDispatch acquires its unique lock from the
    // cache BEFORE Bus::fake() sees the job. Redis locks persist across test
    // runs (the DB ids reset, the locks don't), silently dropping the second
    // and every later dispatch.
    config()->set('cache.default', 'array');
    Cache::flush();
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->service = app(SyncPipelineService::class);
});

it('includes MediaSourceMatch in the pipeline only when the toggle is on and the playlist has vod or series', function () {
    mediaSourceMockPipelineSettings();

    $on = mediaSourcePlaylistWithVod($this->user, ['prefer_media_server_sources' => true]);
    $off = mediaSourcePlaylistWithVod($this->user, ['prefer_media_server_sources' => false]);
    $empty = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);

    $runOn = $this->service->buildPipeline($on, app(GeneralSettings::class));
    $runOff = $this->service->buildPipeline($off, app(GeneralSettings::class));
    $runEmpty = $this->service->buildPipeline($empty, app(GeneralSettings::class));

    expect($runOn->phases)->toContain(SyncRunPhase::MediaSourceMatch->value)
        ->and($runOff->phases)->not->toContain(SyncRunPhase::MediaSourceMatch->value)
        ->and($runEmpty->phases)->not->toContain(SyncRunPhase::MediaSourceMatch->value);
});

it('places MediaSourceMatch after the TMDB phases and before the STRM phases', function () {
    mediaSourceMockPipelineSettings(tmdb: true);

    $playlist = Playlist::factory()->for($this->user)->create([
        'prefer_media_server_sources' => true,
        'auto_sync_vod_stream_files' => true,
        'auto_fetch_vod_metadata' => true,
    ]);
    Channel::factory()->for($playlist)->for($this->user)->create(['enabled' => true, 'is_vod' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $phases = $run->phases;

    expect($phases)->toContain(SyncRunPhase::MediaSourceMatch->value)
        ->and(array_search(SyncRunPhase::MediaSourceMatch->value, $phases))
        ->toBeGreaterThan(array_search(SyncRunPhase::VodTmdb->value, $phases))
        ->toBeLessThan(array_search(SyncRunPhase::VodStrm->value, $phases));
});

it('completes the MediaSourceMatch phase even when the rebuild throws', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    $run = SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::MediaSourceMatch->value, SyncRunPhase::SyncCompleted->value],
        'context' => ['playlist_id' => $playlist->id],
    ]);

    $service = Mockery::mock(MediaSourceMatchService::class.'[rebuildForPlaylist]');
    $service->shouldReceive('rebuildForPlaylist')
        ->andThrow(new RuntimeException('boom'));
    app()->instance(MediaSourceMatchService::class, $service);

    (new MatchMediaServerSources($playlist->id, $run->id, SyncRunPhase::MediaSourceMatch))->handle();

    $run->refresh();

    expect($run->isPhaseComplete(SyncRunPhase::MediaSourceMatch))->toBeTrue();
});

it('dispatches MatchMediaServerSources when an emby sync finishes', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $mediaPlaylist = Playlist::factory()->for($this->user)->create();
    $integration->update(['playlist_id' => $mediaPlaylist->id]);

    $toggledProvider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);
    Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => false]);

    // Other user's toggled-on playlist must NOT be matched
    $otherUser = User::factory()->create();
    Playlist::factory()->for($otherUser)->create(['prefer_media_server_sources' => true]);

    $job = new SyncMediaServer($integration->id);

    (new ReflectionMethod($job, 'dispatchSourceMatching'))->invoke($job, $integration);

    Bus::assertDispatched(MatchMediaServerSources::class, fn (MatchMediaServerSources $j) => $j->playlistId === $toggledProvider->id);
    Bus::assertDispatchedTimes(MatchMediaServerSources::class, 1);
});

it('passes the matching job as a postCompletionJob for local integrations', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'enabled' => true,
        'auto_fetch_metadata' => true,
    ]);
    $mediaPlaylist = Playlist::factory()->for($this->user)->create();
    $integration->update(['playlist_id' => $mediaPlaylist->id]);

    $toggledProvider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);

    Bus::fake();
    $job = new SyncMediaServer($integration->id);
    (new ReflectionProperty($job, 'stats'))->setValue($job, ['movies_synced' => 3, 'series_synced' => 0]);

    $method = new ReflectionMethod($job, 'dispatchMetadataLookup');
    $method->invoke($job, $integration, $mediaPlaylist);

    Bus::assertDispatched(MatchMediaServerSources::class, fn (MatchMediaServerSources $j) => $j->playlistId === $toggledProvider->id);
});

it('dispatches the job when the playlist toggle changes', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => false]);

    $playlist->update(['prefer_media_server_sources' => true]);

    Bus::assertDispatched(MatchMediaServerSources::class, fn (MatchMediaServerSources $j) => $j->playlistId === $playlist->id);

    Bus::fake();
    $playlist->update(['name' => 'unrelated change']);
    Bus::assertNotDispatched(MatchMediaServerSources::class);
});

it('chains post-completion matching jobs for the provider playlists, not the media playlist', function () {
    config()->set('cache.default', 'array');
    Cache::flush();

    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'local',
        'enabled' => true,
        'auto_fetch_metadata' => true,
    ]);
    $mediaPlaylist = Playlist::factory()->for($this->user)->create([
        // The integration's own playlist can never carry the toggle (the form
        // hides it there) — but set it anyway to prove the target selection
        // doesn't depend on it.
        'prefer_media_server_sources' => true,
    ]);
    $integration->update(['playlist_id' => $mediaPlaylist->id]);

    $toggledProvider = Playlist::factory()->for($this->user)->create(['prefer_media_server_sources' => true]);

    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    app()->instance(TmdbService::class, $tmdb);

    Bus::fake();
    $job = new SyncMediaServer($integration->id);
    (new ReflectionProperty($job, 'stats'))->setValue($job, ['movies_synced' => 3, 'series_synced' => 0]);

    $method = new ReflectionMethod($job, 'dispatchMetadataLookup');
    $method->invoke($job, $integration, $mediaPlaylist);

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $job) use ($toggledProvider): bool {
        return collect($job->postCompletionJobs)->contains(
            fn ($postJob) => $postJob instanceof MatchMediaServerSources && $postJob->playlistId === $toggledProvider->id,
        );
    });
});

it('does not dedupe pipeline runs against ad-hoc rebuilds', function () {
    $pipelineJob = new MatchMediaServerSources(7, 42, SyncRunPhase::MediaSourceMatch);
    $otherPipelineJob = new MatchMediaServerSources(7, 99, SyncRunPhase::MediaSourceMatch);
    $adhocJob = new MatchMediaServerSources(7);

    expect($pipelineJob->uniqueId())->toBe('match-media-server-sources-7:42')
        ->and($otherPipelineJob->uniqueId())->toBe('match-media-server-sources-7:99')
        ->and($adhocJob->uniqueId())->toBe('match-media-server-sources-7:adhoc')
        ->and($pipelineJob->uniqueId())->not->toBe($adhocJob->uniqueId());
});

it('serializes rebuilds per playlist via WithoutOverlapping with bounded retries', function () {
    $job = new MatchMediaServerSources(7);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($job->retryUntil())->toBeInstanceOf(DateTime::class);
});
