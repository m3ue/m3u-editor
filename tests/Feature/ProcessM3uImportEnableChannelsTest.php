<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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

    Bus::fake();

    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    expect((bool) $playlist->fresh()->enable_channels)->toBeTrue();
    expect((bool) $job->playlist->enable_channels)->toBeTrue();

    $method = new ReflectionMethod($job, 'processM3uPlus');
    $method->invoke($job);

    $importJob = Job::query()->first();

    expect($importJob)->not->toBeNull();

    $payload = $importJob->payload;
    expect($payload)->toBeArray()
        ->and($payload)->not->toBeEmpty();

    $allEnabled = collect($payload)->every(fn (array $channel): bool => (bool) ($channel['enabled'] ?? false));
    $this->assertTrue($allEnabled, 'Unexpected payload: '.json_encode($payload));
});
