<?php

/**
 * Regression tests for the "all channels marked new on every reimport" bug.
 *
 * Root cause: processChannelCollection() (M3U+ path) was missing the
 * `new = false` reset that processXtreamChannelCollections() always had.
 * Because `new` is intentionally excluded from the upsert update list,
 * channels inserted on the first import kept new=true forever, so every
 * reimport's ProcessM3uImportComplete counted the full channel set as newly
 * added with zero removals.
 */

use App\Jobs\ProcessM3uImport;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// ── Shared DB setup ──────────────────────────────────────────────────────────

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

// ── M3U+ path ────────────────────────────────────────────────────────────────

describe('M3U reimport', function () {
    it('resets the new flag on existing channels before processing', function () {
        $user = User::factory()->create();

        $this->tempM3uPath = sys_get_temp_dir().'/playlist_reimport_'.uniqid().'.m3u';
        file_put_contents($this->tempM3uPath, implode("\n", [
            '#EXTM3U',
            '#EXTINF:-1 tvg-id="ch-1" tvg-name="Channel One" group-title="News",Channel One',
            'http://example.test/stream/1',
            '#EXTINF:-1 tvg-id="ch-2" tvg-name="Channel Two" group-title="News",Channel Two',
            'http://example.test/stream/2',
        ]));

        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'import_prefs' => [],
            'auto_sort' => false,
        ]));

        // Simulate channels left over from a previous import — all still marked new.
        $group = Group::factory()->for($playlist)->for($user)->create(['new' => false]);
        Channel::factory()->count(2)->for($playlist)->for($user)->for($group)->create([
            'new' => true,
            'import_batch_no' => 'previous-batch',
        ]);

        expect($playlist->channels()->where('new', true)->count())->toBe(2);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect($playlist->channels()->where('new', true)->count())->toBe(0);
    });

    it('only resets the new flag for channels belonging to the reimported playlist', function () {
        $user = User::factory()->create();

        $this->tempM3uPath = sys_get_temp_dir().'/playlist_reimport_'.uniqid().'.m3u';
        file_put_contents($this->tempM3uPath, implode("\n", [
            '#EXTM3U',
            '#EXTINF:-1 tvg-name="Ch One" group-title="Sports",Ch One',
            'http://example.test/stream/1',
        ]));

        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'import_prefs' => [],
            'auto_sort' => false,
        ]));

        $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'import_prefs' => [],
        ]));

        $group = Group::factory()->for($playlist)->for($user)->create(['new' => false]);
        $otherGroup = Group::factory()->for($otherPlaylist)->for($user)->create(['new' => false]);

        Channel::factory()->for($playlist)->for($user)->for($group)->create(['new' => true]);
        Channel::factory()->for($otherPlaylist)->for($user)->for($otherGroup)->create(['new' => true]);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect($playlist->channels()->where('new', true)->count())->toBe(0)
            ->and($otherPlaylist->channels()->where('new', true)->count())->toBe(1);
    });
});

// ── Xtream API path ──────────────────────────────────────────────────────────

describe('Xtream reimport', function () {
    it('resets the new flag on existing channels before processing', function () {
        $user = User::factory()->create();

        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
            'xtream' => true,
            'xtream_config' => [
                'url' => 'http://xtream.example.test:8080',
                'username' => 'user',
                'password' => 'pass',
                'output' => 'ts',
                // No live/vod/series — avoids needing to fake sink-based stream downloads.
                'import_options' => [],
            ],
            'import_prefs' => [],
        ]));

        Http::fake([
            'http://xtream.example.test:8080/player_api.php*' => Http::response(
                json_encode(['user_info' => ['status' => 'Active']]),
                200
            ),
        ]);

        $group = Group::factory()->for($playlist)->for($user)->create(['new' => false]);
        Channel::factory()->count(3)->for($playlist)->for($user)->for($group)->create([
            'new' => true,
            'import_batch_no' => 'previous-batch',
        ]);

        expect($playlist->channels()->where('new', true)->count())->toBe(3);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect($playlist->channels()->where('new', true)->count())->toBe(0);
    });
});
