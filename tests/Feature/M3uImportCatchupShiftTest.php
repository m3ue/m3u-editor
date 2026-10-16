<?php

/**
 * Regression tests for issue #1317 ("Imported M3U doesn't support catch-up").
 *
 * Root cause: the M3U attribute map in ProcessM3uImport built the 'shift' field from a
 * PHP array literal with a duplicate 'shift' key — the second entry ('timeshift') silently
 * overwrote the first ('tvg-shift'), so the 'tvg-shift' fallback never actually ran despite
 * the comment claiming it did. The map also never recognized 'catchup-days'/'tvg-rec', which
 * many catch-up providers (e.g. EPGenius) use to express the window in days instead of hours.
 *
 * Follow-up (#1627): 'timeshift' is the SIPTV attribute and is also days (Kodi IPTV Simple,
 * TiviMate, IPTVnator), and 'tvg-shift' is the EPG time offset, so it is no longer read as
 * the catch-up window.
 */

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

/**
 * Runs ProcessM3uImport against a one-channel M3U file and returns the parsed channel
 * payload row that was queued for ProcessM3uImportChunk (the chain itself is faked —
 * it's forced onto the redis connection in production code, which isn't available here —
 * but the M3U parsing under test happens synchronously before the chain is dispatched).
 *
 * @return array<string, mixed>
 */
function importedChannelPayloadRow(User $user, string $extinfAttributes, string $title = 'Channel One'): array
{
    $tempM3uPath = sys_get_temp_dir().'/playlist_catchup_'.uniqid().'.m3u';
    file_put_contents($tempM3uPath, implode("\n", [
        '#EXTM3U',
        "#EXTINF:-1 tvg-id=\"ch-1\" group-title=\"News\" {$extinfAttributes},{$title}",
        'http://example.test/stream/1',
    ]));
    test()->tempM3uPath = $tempM3uPath;

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
        'enable_channels' => true,
    ]));

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    return Job::firstOrFail()->payload[0];
}

it('reads the SIPTV timeshift attribute (days) into shift as hours', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRow($user, 'catchup="append" timeshift="4"');

    expect((int) $row['shift'])->toBe(4 * 24);
});

it('does not treat tvg-shift (the EPG offset) as a catch-up window', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRow($user, 'catchup="append" tvg-shift="3"');

    expect((int) $row['shift'])->toBe(0)
        ->and((int) $row['tvg_shift'])->toBe(3);
});

it('converts catchup-days (days) to hours', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRow($user, 'catchup="append" catchup-days="7"');

    expect((int) $row['shift'])->toBe(7 * 24);
});

it('converts tvg-rec (days) to hours when catchup-days is absent', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRow($user, 'catchup="append" tvg-rec="2"');

    expect((int) $row['shift'])->toBe(2 * 24);
});

it('prefers catchup-days over tvg-rec and timeshift when multiple are present', function () {
    $user = User::factory()->create();

    $row = importedChannelPayloadRow($user, 'catchup="append" timeshift="5" tvg-rec="9" catchup-days="3"');

    expect((int) $row['shift'])->toBe(3 * 24);
});
