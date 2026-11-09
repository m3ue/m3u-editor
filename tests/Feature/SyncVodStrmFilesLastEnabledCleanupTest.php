<?php

/**
 * Issue #1745: after disabling the last enabled VOD at a sync location, rerunning the
 * playlist's explicit "Sync VOD .strm files" action left that VOD's tracked file and
 * mapping in place. dispatchBatches() returned immediately when the enabled-channel
 * query was empty, before ever scheduling the cleanup chain - which
 * StrmFileMapping::cleanupOrphaned() otherwise correctly treats disabled items as
 * eligible for.
 *
 * The queue connection is real Redis in this test suite (config/queue.php hardcodes
 * it), so a dispatched job is never actually executed inline here - each hop is
 * verified with Queue::fake() + assertPushed(), then that job's handle() is invoked
 * directly to drive the next hop, exactly as production's real queue worker would.
 */

use App\Jobs\CheckVodStrmProgress;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamFileSetting;
use App\Models\StrmFileMapping;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->settings = app(GeneralSettings::class);

    $this->dir = sys_get_temp_dir().'/strm-last-enabled-'.uniqid();
    mkdir($this->dir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->dir)) {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }
});

/**
 * Simulates one real batch job directly (the same convention
 * SyncVodStrmFilesLocationOverrideTest.php uses to set up initial state,
 * since a dispatched Bus::chain is never executed in this test suite).
 */
function runVodStrmSingleBatch(Playlist $playlist, GeneralSettings $settings): void
{
    (new SyncVodStrmFiles(
        notify: false,
        playlist_id: $playlist->id,
        batchOffset: 0,
        totalBatches: 1,
        currentBatch: 1,
    ))->handle($settings);
}

it('dispatches a cleanup-triggering progress check instead of silently returning when zero VOD are enabled', function () {
    $setting = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => $this->dir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => false,
    ]);

    $alpha = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => false,
        'is_vod' => true,
        'title' => 'Alpha',
        'name' => 'Alpha',
        'url' => 'http://provider.test/alpha.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
    ]);

    Queue::fake();

    // The real "Sync VOD .strm files" action's entry point: no batchOffset, so
    // handle() takes the initial-dispatch -> dispatchBatches() path - exactly
    // the code the #1745 fix changes.
    (new SyncVodStrmFiles(notify: false, playlist_id: $this->playlist->id))->handle($this->settings);

    Queue::assertPushed(CheckVodStrmProgress::class, fn ($job) => $job->totalChannels === 0
        && $job->currentOffset === 0
        && $job->needsCleanup === true
        && $job->playlist_id === $this->playlist->id);
});

it('removes the tracked file and mapping end-to-end when the sync action runs after its last enabled VOD is disabled', function () {
    $setting = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => $this->dir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => false,
    ]);

    $alpha = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Alpha',
        'name' => 'Alpha',
        'url' => 'http://provider.test/alpha.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
    ]);

    runVodStrmSingleBatch($this->playlist, $this->settings);

    expect(file_exists($this->dir.'/Alpha.strm'))->toBeTrue()
        ->and(StrmFileMapping::where('syncable_id', $alpha->id)->count())->toBe(1);

    $alpha->update(['enabled' => false]);

    Queue::fake();
    (new SyncVodStrmFiles(notify: false, playlist_id: $this->playlist->id))->handle($this->settings);

    Queue::assertPushed(CheckVodStrmProgress::class, 1);
    $progressJob = collect(Queue::pushedJobs()[CheckVodStrmProgress::class])->first()['job'];

    Queue::fake();
    $progressJob->handle();

    Queue::assertPushed(SyncVodStrmFiles::class, fn ($job) => $job->isCleanupJob === true);
    $cleanupJob = collect(Queue::pushedJobs()[SyncVodStrmFiles::class])->first()['job'];

    $cleanupJob->handle($this->settings);

    expect(file_exists($this->dir.'/Alpha.strm'))->toBeFalse()
        ->and(StrmFileMapping::where('syncable_id', $alpha->id)->count())->toBe(0);
});
