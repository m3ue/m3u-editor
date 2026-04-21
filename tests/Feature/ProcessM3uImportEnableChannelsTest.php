<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

// Isolate the jobs connection to a temp database so parallel test processes
// don't interfere with each other via the shared jobs.sqlite file.
beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);

    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }

    if (isset($this->tempM3uPath) && file_exists($this->tempM3uPath)) {
        @unlink($this->tempM3uPath);
    }
});

it('marks imported m3u channels as enabled when playlist auto-enable is on', function () {
    $user = User::factory()->create();

    $this->tempM3uPath = sys_get_temp_dir().'/playlist_import_'.uniqid('', true).'.m3u';
    file_put_contents($this->tempM3uPath, implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="demo-1" tvg-name="Demo One" group-title="News",Demo One',
        'http://example.test/stream/1',
        '#EXTINF:-1 tvg-id="demo-2" tvg-name="Demo Two" group-title="News",Demo Two',
        'http://example.test/stream/2',
    ]));

    $playlist = Playlist::withoutEvents(function () use ($user) {
        return Playlist::factory()->for($user)->create([
            'name' => 'M3U Auto Enable Regression Test',
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'enable_channels' => true,
            'enable_vod_channels' => false,
            'import_prefs' => [],
            'auto_sort' => false,
        ]);
    });

    // Prevent ProcessM3uImportChunk / ProcessM3uImportComplete from being dispatched
    // while still allowing processChannelCollection() to write Job staging records.
    Bus::fake();

    (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

    $importJobs = Job::all();
    expect($importJobs)->not->toBeEmpty();

    $allEnabled = $importJobs
        ->flatMap(fn (Job $job): array => $job->payload)
        ->every(fn (array $channel): bool => (bool) ($channel['enabled'] ?? false));

    expect($allEnabled)->toBeTrue();
});
