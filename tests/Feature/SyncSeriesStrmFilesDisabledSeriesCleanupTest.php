<?php

/**
 * Issue #1789: disabling a series and syncing its .strm files left them in place.
 * Disabling a series leaves its episodes enabled, and cleanup only looked at
 * episodes.enabled. The single-series sync also returned before tracking any
 * location for a disabled series (or one without enabled episodes), and the
 * batch sync returned without cleanup when no series were enabled.
 *
 * Dispatched jobs never run inline in this suite (the queue connection is real
 * Redis), so each hop is checked with Queue::fake() and then driven by calling
 * that job's handle() directly, the same way SyncVodStrmFilesLastEnabledCleanupTest
 * drives the VOD chain.
 */

use App\Jobs\CheckSeriesStrmProgress;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use App\Models\StrmFileMapping;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->settings = app(GeneralSettings::class);

    $this->dir = sys_get_temp_dir().'/strm-disabled-series-'.uniqid();
    mkdir($this->dir, 0777, true);

    $this->setting = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'series',
        'enabled' => true,
        'location' => $this->dir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => false,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/**
 * A series with one enabled episode whose .strm file has been synced.
 */
function syncedSeriesWithEpisode(object $test, string $name): Series
{
    $series = Series::factory()->for($test->playlist)->for($test->user)->create([
        'name' => $name,
        'enabled' => true,
        'stream_file_setting_id' => $test->setting->id,
    ]);
    $season = Season::factory()->create(['series_id' => $series->id, 'playlist_id' => $test->playlist->id, 'season_number' => 1]);
    Episode::factory()->for($test->playlist)->for($test->user)->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'title' => "{$name} Pilot",
        'url' => 'http://provider.test/'.$series->id.'/1.mp4',
        'container_extension' => 'mp4',
    ]);

    (new SyncSeriesStrmFiles(series: $series, notify: false))->handle($test->settings);

    expect(seriesStrmPaths($series))->toHaveCount(1)
        ->and(file_exists(seriesStrmPaths($series)[0]))->toBeTrue();

    return $series;
}

/**
 * @return array<int, string>
 */
function seriesStrmPaths(Series $series): array
{
    return StrmFileMapping::query()
        ->where('syncable_type', Episode::class)
        ->whereIn('syncable_id', $series->episodes()->select('episodes.id'))
        ->pluck('current_path')
        ->all();
}

it('removes the files when Sync Series .strm files runs on a disabled series', function () {
    $series = syncedSeriesWithEpisode($this, 'Alpha');
    $path = seriesStrmPaths($series)[0];

    $series->update(['enabled' => false]);
    (new SyncSeriesStrmFiles(series: $series->fresh(), notify: false))->handle($this->settings);

    expect(file_exists($path))->toBeFalse()
        ->and(seriesStrmPaths($series))->toBeEmpty();
});

it('removes the files when Sync Series .strm files runs on a series whose episodes are all disabled', function () {
    $series = syncedSeriesWithEpisode($this, 'Alpha');
    $path = seriesStrmPaths($series)[0];

    $series->episodes()->update(['enabled' => false]);
    // fresh(): a queued job unserializes the series without the relations the
    // first sync loaded, so its enabled_episodes is re-read from the database.
    (new SyncSeriesStrmFiles(series: $series->fresh(), notify: false))->handle($this->settings);

    expect(file_exists($path))->toBeFalse()
        ->and(seriesStrmPaths($series))->toBeEmpty();
});

it('runs cleanup from a full sync when no series are enabled any more', function () {
    $series = syncedSeriesWithEpisode($this, 'Alpha');
    $path = seriesStrmPaths($series)[0];
    $series->update(['enabled' => false]);

    Queue::fake();
    (new SyncSeriesStrmFiles(notify: false, playlist_id: $this->playlist->id, user_id: $this->user->id))
        ->handle($this->settings);

    Queue::assertPushed(CheckSeriesStrmProgress::class, fn ($job) => $job->totalSeries === 0
        && $job->needsCleanup === true
        && $job->playlist_id === $this->playlist->id);
    $progressJob = collect(Queue::pushedJobs()[CheckSeriesStrmProgress::class])->first()['job'];

    Queue::fake();
    $progressJob->handle();

    Queue::assertPushed(SyncSeriesStrmFiles::class, fn ($job) => $job->isCleanupJob === true);
    $cleanupJob = collect(Queue::pushedJobs()[SyncSeriesStrmFiles::class])->first()['job'];
    $cleanupJob->handle($this->settings);

    expect(file_exists($path))->toBeFalse()
        ->and(seriesStrmPaths($series))->toBeEmpty();
});

it('treats the enabled episodes of a disabled series as orphaned, and keeps enabled series', function () {
    $disabled = syncedSeriesWithEpisode($this, 'Alpha');
    $kept = syncedSeriesWithEpisode($this, 'Bravo');
    $disabledPath = seriesStrmPaths($disabled)[0];
    $keptPath = seriesStrmPaths($kept)[0];

    $disabled->update(['enabled' => false]);

    expect(StrmFileMapping::cleanupOrphaned(Episode::class, $this->dir))->toBe(1)
        ->and(file_exists($disabledPath))->toBeFalse()
        ->and(file_exists($keptPath))->toBeTrue()
        ->and(seriesStrmPaths($kept))->toHaveCount(1);
});
