<?php

/**
 * Covers per-sync-window dedup guards added in Step 1 of the sync pipeline
 * refactor:
 *   - F/R skip marker consumed by SyncListener (1a)
 *   - TMDB fetch lock in ProcessVodChannelsComplete + CheckSeriesImportProgress
 *     + ProcessM3uImportSeriesComplete (1b)
 *   - ProbeVodStreams lock shared between CheckVodStrmProgress and
 *     CheckSeriesStrmProgress (1d)
 */

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\CheckSeriesStrmProgress;
use App\Jobs\CheckVodStrmProgress;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProbeVodStreams;
use App\Jobs\ProcessM3uImportSeriesComplete;
use App\Jobs\ProcessVodChannelsComplete;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Listeners\SyncListener;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
    ]);
});

// -----------------------------------------------------------------------------
// 1a: Find & Replace skip marker
// -----------------------------------------------------------------------------

it('marks find & replace as run after RunPlaylistFindReplaceRules executes', function () {
    $this->playlist->update([
        'find_replace_rules' => [
            ['enabled' => true, 'target' => 'channels', 'find_replace' => 'foo', 'replace_with' => 'bar', 'column' => 'title', 'use_regex' => false],
        ],
    ]);

    Cache::forget(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist));

    (new RunPlaylistFindReplaceRules($this->playlist))->handle();

    expect(Cache::has(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist)))->toBeTrue();
});

it('SyncListener skips F/R when the ran marker is present and consumes it', function () {
    Bus::fake();

    $this->playlist->update([
        'find_replace_rules' => [
            ['enabled' => true, 'target' => 'channels', 'find_replace' => 'foo', 'replace_with' => 'bar', 'column' => 'title', 'use_regex' => false],
        ],
        'sort_alpha_config' => [
            ['enabled' => true],
        ],
    ]);

    Cache::put(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist), true, 1800);

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh(), 'playlist'));

    // F/R must NOT be re-dispatched (it ran in the upstream chain). Sort alpha
    // still runs since it was enabled.
    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertDispatched(RunPlaylistSortAlpha::class);

    // Marker consumed atomically.
    expect(Cache::has(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist)))->toBeFalse();
});

it('SyncListener still runs F/R when no marker is present', function () {
    Bus::fake();

    $this->playlist->update([
        'find_replace_rules' => [
            ['enabled' => true, 'target' => 'channels', 'find_replace' => 'foo', 'replace_with' => 'bar', 'column' => 'title', 'use_regex' => false],
        ],
    ]);

    Cache::forget(RunPlaylistFindReplaceRules::ranMarkerKey($this->playlist));

    (new SyncListener)->handle(new SyncCompleted($this->playlist->fresh(), 'playlist'));

    Bus::assertDispatched(RunPlaylistFindReplaceRules::class);
});

// -----------------------------------------------------------------------------
// 1b: TMDB fetch lock
// -----------------------------------------------------------------------------

it('ProcessVodChannelsComplete dispatches TMDB fetch only once per sync window', function () {
    Bus::fake();

    $this->playlist->update([
        'auto_sync_vod_stream_files' => false,
    ]);

    $settings = app(GeneralSettings::class);
    $settings->tmdb_auto_lookup_on_import = true;

    Cache::forget("playlist:{$this->playlist->id}:tmdb_fetch_vod");

    (new ProcessVodChannelsComplete($this->playlist->fresh()))->handle($settings);
    (new ProcessVodChannelsComplete($this->playlist->fresh()))->handle($settings);

    Bus::assertDispatchedTimes(FetchTmdbIds::class, 1);
});

it('ProcessM3uImportSeriesComplete dispatches TMDB fetch only once per sync window', function () {
    Bus::fake();

    $settings = app(GeneralSettings::class);
    $settings->tmdb_auto_lookup_on_import = true;

    Cache::forget("playlist:{$this->playlist->id}:tmdb_fetch_series");

    (new ProcessM3uImportSeriesComplete($this->playlist->fresh(), 'batch-1'))->handle($settings);
    (new ProcessM3uImportSeriesComplete($this->playlist->fresh(), 'batch-2'))->handle($settings);

    Bus::assertDispatchedTimes(FetchTmdbIds::class, 1);
});

// -----------------------------------------------------------------------------
// 1d: ProbeVodStreams lock
// -----------------------------------------------------------------------------

it('VOD probe is dispatched only once even when both VOD and series checkers complete', function () {
    Bus::fake();

    $this->playlist->update(['auto_probe_vod_streams' => true]);
    Cache::forget("playlist:{$this->playlist->id}:probe_vod");

    $vodChecker = new CheckVodStrmProgress(
        currentOffset: 10,
        totalChannels: 10,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
    );
    $vodChecker->handle();

    $seriesChecker = new CheckSeriesStrmProgress(
        currentOffset: 5,
        totalSeries: 5,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
    );
    $seriesChecker->handle();

    Bus::assertDispatchedTimes(ProbeVodStreams::class, 1);
});
