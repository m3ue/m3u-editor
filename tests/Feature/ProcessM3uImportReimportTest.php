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

describe('M3U group auto sort', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->tempM3uPath = sys_get_temp_dir().'/playlist_group_sort_'.uniqid().'.m3u';
        file_put_contents($this->tempM3uPath, implode("\n", [
            '#EXTM3U',
            '#EXTINF:-1 tvg-name="Ch One" group-title="Sports",Ch One',
            'http://example.test/stream/1',
            '#EXTINF:-1 tvg-name="Ch Two" group-title="News",Ch Two',
            'http://example.test/stream/2',
        ]));
    });

    $makePlaylist = fn (User $user, string $path, array $attributes) => Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $path,
        'xtream' => false,
        'import_prefs' => [],
        ...$attributes,
    ]));

    it('keeps manual group order when Group Auto Sort is off, even with Channel Auto Sort on', function () use ($makePlaylist) {
        $playlist = $makePlaylist($this->user, $this->tempM3uPath, ['auto_sort' => true, 'auto_sort_groups' => false]);
        $sports = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'Sports', 'name_internal' => 'Sports', 'type' => 'live', 'custom' => false, 'sort_order' => 5,
        ]);
        $news = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'News', 'name_internal' => 'News', 'type' => 'live', 'custom' => false, 'sort_order' => 1,
        ]);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect((float) $sports->fresh()->sort_order)->toBe(5.0)
            ->and((float) $news->fresh()->sort_order)->toBe(1.0);
    });

    it('numbers groups in playlist order when Group Auto Sort is on, even with Channel Auto Sort off', function () use ($makePlaylist) {
        $playlist = $makePlaylist($this->user, $this->tempM3uPath, ['auto_sort' => false, 'auto_sort_groups' => true]);
        $sports = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'Sports', 'name_internal' => 'Sports', 'type' => 'live', 'custom' => false, 'sort_order' => 5,
        ]);
        $news = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'News', 'name_internal' => 'News', 'type' => 'live', 'custom' => false, 'sort_order' => 1,
        ]);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect((float) $sports->fresh()->sort_order)->toBe(1.0)
            ->and((float) $news->fresh()->sort_order)->toBe(2.0);
    });

    it('backfills Group Auto Sort from Channel Auto Sort on M3U playlists only', function () use ($makePlaylist) {
        $m3uOn = $makePlaylist($this->user, $this->tempM3uPath, ['auto_sort' => true, 'auto_sort_groups' => false]);
        $m3uOff = $makePlaylist($this->user, $this->tempM3uPath, ['auto_sort' => false, 'auto_sort_groups' => true]);
        $xtream = $makePlaylist($this->user, $this->tempM3uPath, ['xtream' => true, 'auto_sort' => true, 'auto_sort_groups' => false]);

        $migration = require database_path('migrations/2026_11_08_073909_backfill_auto_sort_groups_for_m3u_playlists.php');
        $migration->up();

        expect((bool) $m3uOn->fresh()->auto_sort_groups)->toBeTrue()
            ->and((bool) $m3uOff->fresh()->auto_sort_groups)->toBeFalse()
            ->and((bool) $xtream->fresh()->auto_sort_groups)->toBeFalse();
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
