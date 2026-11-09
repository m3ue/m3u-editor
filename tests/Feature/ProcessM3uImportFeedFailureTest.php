<?php

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Filament\Resources\Playlists\Resources\SyncRuns\SyncRunResource;
use App\Jobs\CompleteSyncPhase;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\DateFormatService;
use App\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

beforeEach(function () {
    Bus::fake();
    Notification::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
    $this->withoutVite();
    app('router')->aliasMiddleware('throttle', ThrottleRequests::class);
    config(['cache.limiter' => 'array', 'dev.max_channels' => 100, 'dev.invalidate_import' => false, 'dev.disable_sync_logs' => false]);
    app()->forgetInstance(RateLimiter::class);
    $settings = app(GeneralSettings::class);
    $settings->tmdb_auto_lookup_on_import = false;
    $settings->alerts_on_sync_invalidated = false;
    app()->instance(GeneralSettings::class, $settings);
    $this->travelTo(now()->startOfSecond());
    $this->actingAs(User::factory()->admin()->create());
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->syncEvents = [];
    Event::listen(SyncCompleted::class, function (SyncCompleted $event): void {
        $this->syncEvents[] = $event;
    });
});

afterEach(function () {
    $this->travelBack();
});

function feedFailurePlaylist(bool $findReplace = false): Playlist
{
    return Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for(auth()->user())->create([
        'name' => 'Feed failure regression',
        'url' => 'https://feed.invalid/native.m3u',
        'xtream' => false,
        'source_type' => PlaylistSourceType::M3u,
        'is_network_playlist' => false,
        'status' => Status::Pending,
        'synced' => null,
        'errors' => null,
        'enable_channels' => true,
        'auto_sort' => false,
        'backup_before_sync' => false,
        'sync_logs_enabled' => true,
        'profiles_enabled' => false,
        'import_prefs' => ['preprocess' => false],
        'auto_probe_streams' => false,
        'auto_probe_vod_streams' => false,
        'auto_fetch_vod_metadata' => false,
        'auto_fetch_series_metadata' => false,
        'auto_sync_vod_stream_files' => false,
        'auto_sync_series_stream_files' => false,
        'auto_merge_channels_enabled' => false,
        'prefer_media_server_sources' => false,
        'find_replace_rules' => $findReplace ? [[
            'enabled' => true, 'target' => 'channels', 'column' => 'title',
            'use_regex' => false, 'find_replace' => 'Raw', 'replace_with' => 'Clean',
        ]] : [],
        'sort_alpha_config' => [],
        'channel_enable_rules' => [],
        'auto_sync_to_custom_config' => [],
        'dynamic_groups_config' => [],
    ]));
}

function feedFailureTransport(bool $fail = false): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://feed.invalid/native.m3u' => $fail
        ? Http::failedConnection('Synthetic feed connection failure')
        : Http::response("#EXTM3U\n#EXTINF:-1 tvg-id=\"one\" tvg-name=\"Raw One\" group-title=\"News\",Raw One\nhttps://media.invalid/one.ts\n#EXTINF:-1 tvg-id=\"two\" tvg-name=\"Raw Two\" group-title=\"News\",Raw Two\nhttps://media.invalid/two.ts\n", 200),
    ]);
}

function feedFailureStart(TestCase $test, Playlist $playlist): SyncRun
{
    Bus::fake();
    $test->getJson(route('api.playlist.sync', ['uuid' => $playlist->uuid]))->assertOk();
    $job = Bus::dispatched(ProcessM3uImport::class)->sole();
    expect($job->playlist->id)->toBe($playlist->id)->and($job->force)->toBeTrue();
    $run = SyncRun::findOrFail($job->syncRunId);
    expect($run->trigger)->toBe('api_refresh')->and($run->status)->toBe('running');

    return $run;
}

/** Execute only the queue envelope locally; all business handlers and chain dispatch remain original. */
function feedFailureDrain(TestCase $test): array
{
    $classes = [ProcessM3uImport::class, ProcessM3uImportChunk::class, ProcessM3uImportComplete::class, RunPlaylistFindReplaceRules::class, CompleteSyncPhase::class];
    $seen = [];
    $trace = [];
    for ($guard = 0; $guard < 20; $guard++) {
        $pending = [];
        foreach ($classes as $class) {
            foreach (Bus::dispatched($class) as $job) {
                if (! isset($seen[spl_object_id($job)])) {
                    $pending[] = $job;
                }
            }
        }
        if ($pending === []) {
            return $trace;
        }
        foreach ($pending as $job) {
            $seen[spl_object_id($job)] = true;
            $test->travel(7)->seconds();
            app()->call([$job, 'handle']);
            $trace[] = $job::class;
            $job->dispatchNextJobInChain();
        }
    }
    throw new RuntimeException('Import queue did not drain');
}

function feedFailureTimeline(TestCase $test, SyncRun $run): string
{
    $response = $test->get(SyncRunResource::getUrl('view', [
        'playlist' => $run->playlist_id, 'record' => $run->id,
    ]))->assertOk();

    $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($response->getContent())));
    $run->refresh();
    expect($text)->toContain(app(DateFormatService::class)->format($run->started_at));
    if ($run->finished_at) {
        expect($text)->toContain(app(DateFormatService::class)->format($run->finished_at));
    }

    return $text;
}

it('finishes a failed feed attempt and keeps a healthy HTTP retry in its own run', function () {
    $playlist = feedFailurePlaylist();
    $other = feedFailurePlaylist();
    $otherRun = SyncRun::factory()->for($other)->create();
    $otherBefore = $other->refresh()->getAttributes();
    $otherRunBefore = $otherRun->refresh()->getAttributes();

    feedFailureTransport();
    $first = feedFailureStart($this, $playlist);
    expect(feedFailureDrain($this))->toBe([ProcessM3uImport::class, ProcessM3uImportChunk::class, ProcessM3uImportComplete::class]);
    expect($first->fresh()->status)->toBe('completed')->and($playlist->channels()->count())->toBe(2);
    $firstBefore = $first->fresh()->getAttributes();
    $channelsBefore = $playlist->channels()->orderBy('id')->get()->map->getAttributes()->all();
    $logsBefore = $playlist->syncStatuses()->count();
    $eventsBefore = count($this->syncEvents);
    $notificationsBefore = Notification::sent($playlist->user, DatabaseNotification::class)->count();

    feedFailureTransport(true);
    $failed = feedFailureStart($this, $playlist->fresh());
    expect($failed->id)->not->toBe($first->id);
    expect(feedFailureDrain($this))->toBe([ProcessM3uImport::class]);
    $playlist->refresh();
    expect($playlist->status)->toBe(Status::Failed)
        ->and($playlist->progress)->toBe(100.0)
        ->and($playlist->processing['live_processing'])->toBeFalse()
        ->and($playlist->processing['vod_processing'])->toBeFalse()
        ->and($playlist->isProcessing())->toBeFalse()
        ->and($playlist->channels()->orderBy('id')->get()->map->getAttributes()->all())->toBe($channelsBefore)
        ->and($playlist->syncStatuses()->count())->toBe($logsBefore)
        ->and(Notification::sent($playlist->user, DatabaseNotification::class)->count())->toBe($notificationsBefore + 1);
    expect(count($this->syncEvents))->toBe($eventsBefore + 1)
        ->and(end($this->syncEvents)->syncRunId)->toBeNull();
    expect($failed->refresh()->status)->toBe('failed')
        ->and($failed->finished_at)->not->toBeNull()
        ->and($failed->phase_statuses['import']['status'])->toBe('failed')
        ->and($failed->progress_message)->toContain('Synthetic feed connection failure')
        ->and($failed->duration)->toBe(7.0);
    $failedBefore = $failed->getAttributes();
    $timelineBefore = $failed->phase_timeline;
    expect(feedFailureTimeline($this, $failed))->toContain('Phase Timeline', 'Failed', '7s')
        ->not->toContain('Running…', 'Resolving phases…');

    $this->travel(120)->seconds();
    expect($failed->fresh()->duration)->toBe(7.0)
        ->and($failed->getAttributes())->toBe($failedBefore)
        ->and($failed->phase_timeline)->toBe($timelineBefore);
    expect(feedFailureTimeline($this, $failed))->toContain('Failed', '7s')
        ->not->toContain('Running…', 'Resolving phases…');

    feedFailureTransport();
    $retry = feedFailureStart($this, $playlist->fresh());
    expect($retry->id)->not->toBeIn([$first->id, $failed->id]);
    expect(feedFailureDrain($this))->toBe([ProcessM3uImport::class, ProcessM3uImportChunk::class, ProcessM3uImportComplete::class]);
    expect($retry->refresh()->status)->toBe('completed')->and($retry->duration)->toBe(21.0);
    expect(feedFailureTimeline($this, $retry))->toContain('Sync Completed', '21s')
        ->not->toContain('Running…', 'Resolving phases…');
    expect($playlist->syncRuns()->count())->toBe(3)
        ->and($first->fresh()->getAttributes())->toBe($firstBefore)
        ->and($failed->fresh()->getAttributes())->toBe($failedBefore)
        ->and($other->fresh()->getAttributes())->toBe($otherBefore)
        ->and($otherRun->fresh()->getAttributes())->toBe($otherRunBefore);
});

it('preserves the original import and local FindReplace pipeline', function (bool $findReplace, float $duration) {
    $playlist = feedFailurePlaylist($findReplace);
    feedFailureTransport();
    $run = feedFailureStart($this, $playlist);
    $expected = [ProcessM3uImport::class, ProcessM3uImportChunk::class, ProcessM3uImportComplete::class];
    if ($findReplace) {
        $expected = [...$expected, RunPlaylistFindReplaceRules::class, CompleteSyncPhase::class];
    }
    expect(feedFailureDrain($this))->toBe($expected)
        ->and($run->refresh()->status)->toBe('completed')
        ->and($run->duration)->toBe($duration)
        ->and($playlist->channels()->count())->toBe(2);
    if ($findReplace) {
        expect($playlist->channels()->orderBy('title')->pluck('title_custom')->all())->toBe(['Clean One', 'Clean Two']);
    }
    expect(feedFailureTimeline($this, $run))->toContain('Phase Timeline', 'Sync Completed', $duration.'s')
        ->not->toContain('Running…', 'Resolving phases…');
})->with([[false, 21.0], [true, 35.0]]);
